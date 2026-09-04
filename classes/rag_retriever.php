<?php
/**
 * RAG Retriever — high-level facade for Retrieval-Augmented Generation
 *
 * Combines content_extractor + embedding_manager into a single API
 * that api_chat.php uses:
 *
 *   1. rag_retriever::get_context_and_diagnostics_for_query($courseid, $query)
 *      → returns ['context' => string ready to inject into the system prompt,
 *                 'diagnostics' => array for the client].
 *
 *   2. rag_retriever::index_course($courseid)
 *      → called by the scheduled task to (re-)index a course.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/content_extractor.php');
require_once(__DIR__ . '/embedding_manager.php');

class rag_retriever {

    /** Number of chunks to inject into the prompt. */
    const TOP_K = 5;

    /** Max characters of chunk text to include per chunk in the prompt. */
    const MAX_CHUNK_CHARS = 800;

    /**
     * Segundos mínimos entre dos peticiones de indexación en background para el
     * mismo curso. Protege de reindexar en bucle un curso cuyo contenido no
     * genera fragmentos recuperables (cada pasada reparsea PDFs y paga embeddings).
     */
    const INDEX_REQUEST_THROTTLE = 6 * HOURSECS;

    /**
     * Tope de fragmentos que reconstruye el catálogo de problemas. Sin tope, una
     * pregunta con la palabra "problema" concatenaba en memoria el texto extraído de
     * TODOS los recursos del curso para luego quedarse con 8 problemas.
     */
    const PROBLEM_CATALOG_MAX_CHUNKS = 120;

    // ----------------------------------------------------------------
    // Query-time retrieval
    // ----------------------------------------------------------------

    /**
     * Resolve course structure queries directly from Moodle data.
     *
     * This bypasses semantic retrieval/model ambiguity for questions about:
     * - course name
     * - section count
     * - contents/activities inside a section
     *
     * Returns null when the query is not a direct structure query.
     *
     * @param int $courseid
     * @param string $query
     * @return array|null
     */
    public static function resolve_direct_course_query(int $courseid, string $query): ?array {
        global $DB;

        $q = mb_strtolower(trim($query), 'UTF-8');

        // Preguntas semánticas de nivel de curso ("¿de qué trata el curso?",
        // "describe el curso") NO se resuelven con datos estructurales: deben
        // ir a la IA con contexto RAG + resumen del curso para dar una
        // respuesta real sobre la temática.
        if (self::is_course_about_query($q)) {
            return null;
        }

        $isSectionQuery = self::is_explicit_section_query($q);
        $isResourceIntent = self::is_resource_query($q);
        $isCourseNameQuery = (bool)preg_match('/c[oó]mo\s+se\s+llama\s+este\s+curso|nombre\s+del\s+curso|nombre\s+de\s+este\s+curso/u', $q);
        $isSectionCountQuery = (bool)preg_match('/cu[aá]ntas?\s+secciones|n[uú]mero\s+de\s+secciones|total\s+de\s+secciones/u', $q);
        $isCourseContentQuery = self::is_course_content_query($q);

        // "¿Qué hay en la sección X?" / "¿qué actividades tiene el tema 2?" es un
        // LISTADO de sección: gana siempre la sección, nunca un recurso suelto con
        // nombre parecido. Ver is_section_listing_query().
        $isSectionListing = self::is_section_listing_query($q);

        // Siempre intentar buscar actividades por nombre antes de sección.
        // Solo omitir para consultas de nivel de curso (contenido, nombre, secciones)
        // y para los listados de sección.
        if (!$isCourseContentQuery && !$isCourseNameQuery && !$isSectionCountQuery && !$isSectionListing) {
            $directResource = self::resolve_direct_resource_query($courseid, $q);
            if ($directResource !== null) {
                return $directResource;
            }
        }

        // Load course and sections up-front so we can try implicit section name
        // detection even when the user didn't use keywords like "sección" or "apartado".
        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, summary, format');
        if (!$course) {
            return null;
        }

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id, section, name, summary, visible');
        if ($sections === false) {
            return null;
        }

        $realSections = [];
        foreach ($sections as $section) {
            if ((int)$section->section === 0) {
                continue;
            }
            $realSections[] = $section;
        }

        // Always try to find a matching section by name — handles implicit queries
        // like "que contenidos hay en Inteligencia Artificial" where the section
        // name appears without an explicit "sección" keyword.
        $matched = self::find_matching_section_for_query($realSections, $q);

        // Bail out only when there is no recognisable query type AND no implicit
        // section name was found in the query text.
        if (!$isSectionQuery && !$isCourseNameQuery && !$isSectionCountQuery && !$isCourseContentQuery && $matched === null) {
            return null;
        }

        $modinfo = null;
        if (function_exists('\get_fast_modinfo')) {
            try {
                $modinfo = \get_fast_modinfo($courseid);
            } catch (\Throwable $e) {
                $modinfo = null;
            }
        }

        if ($matched && $isSectionListing) {
            // Listado explícito de la sección: NO se pasa por los resolutores de
            // label/quiz/tarea/genérica/recurso, que devuelven UN elemento. Ese
            // atajo era el fallo real de "¿qué actividades hay en la sección
            // RECURSOS?": la sección se identificaba bien y luego
            // resolve_direct_resource_in_section_query() devolvía un solo PDF.
            return self::build_section_listing_answer($courseid, $matched, $modinfo);
        }

        if ($matched) {
            // Determinar si el usuario pide explícitamente un PDF/archivo.
            $explicitPdfIntent = (bool)preg_match('/\bpdf\b|\barchivo\b|\bdocumento\b/u', $q);
            $explicitQuizIntent = (bool)preg_match('/\bquiz\b|\bcuestionario\b|\bexamen\b|\btest\b/u', $q);
            $explicitAssignIntent = (bool)preg_match('/\btarea\b|\bassignment\b|\bentrega\b/u', $q);
            $explicitGenericIntent = (bool)preg_match('/\bforo\b|\bforum\b|\bp[aá]gina\b|\bpage\b|\burl\b|\benlace\b|\blibro\b|\bbook\b|\bcarpeta\b|\bfolder\b|\bglosario\b|\bglossary\b|\bwiki\b|\bencuesta\b|\bchoice\b|\bfeedback\b|\blecci[oó]n\b|\blesson\b/u', $q);

            // Quiz: si pide explícitamente un cuestionario o la consulta lo sugiere.
            if ($explicitQuizIntent) {
                $quizResult = self::resolve_direct_quiz_in_section_query($courseid, $matched, $q);
                if ($quizResult !== null) {
                    return $quizResult;
                }
            }

            // Assign: si pide explícitamente una tarea.
            if ($explicitAssignIntent) {
                $assignResult = self::resolve_direct_assign_in_section_query($courseid, $matched, $q);
                if ($assignResult !== null) {
                    return $assignResult;
                }
            }

            // Si NO pide explícitamente un PDF, siempre comprobar labels primero.
            // Los labels contienen texto inline (preguntas, problemas, instrucciones) que
            // el usuario espera que el chat lea sin tener que especificar "label".
            if (!$explicitPdfIntent) {
                $labelResult = self::resolve_direct_label_in_section_query($courseid, $matched, $q);
                if ($labelResult !== null) {
                    return $labelResult;
                }
            }

            // Si no pidió nada específico pero hay quiz/assign, intentarlos antes del PDF.
            if (!$explicitPdfIntent && !$explicitQuizIntent && !$explicitAssignIntent && !$explicitGenericIntent) {
                $quizResult = self::resolve_direct_quiz_in_section_query($courseid, $matched, $q);
                if ($quizResult !== null) {
                    return $quizResult;
                }
                $assignResult = self::resolve_direct_assign_in_section_query($courseid, $matched, $q);
                if ($assignResult !== null) {
                    return $assignResult;
                }
            }

            // Buscar actividades genéricas (foro, página, url, libro, etc.) en la sección.
            if ($explicitGenericIntent || (!$explicitPdfIntent && !$explicitQuizIntent && !$explicitAssignIntent)) {
                $genericResult = self::resolve_direct_generic_in_section_query($courseid, $matched, $q);
                if ($genericResult !== null) {
                    return $genericResult;
                }
            }

            if ($isResourceIntent) {
                $resourceInSection = self::resolve_direct_resource_in_section_query($courseid, $matched, $q);
                if ($resourceInSection !== null) {
                    return $resourceInSection;
                }
            }

            return self::build_section_listing_answer($courseid, $matched, $modinfo);
        }

        if ($isSectionCountQuery) {
            $lines = [];
            $lines[] = 'Curso: ' . trim((string)$course->fullname);
            $lines[] = 'Total de secciones: ' . count($realSections);
            $lines[] = 'Secciones detectadas:';
            foreach ($realSections as $section) {
                $title = trim((string)$section->name);
                if ($title === '') {
                    $title = 'Seccion ' . (int)$section->section;
                }
                $lines[] = '- Seccion ' . (int)$section->section . ': ' . $title;
            }

            return [
                'type' => 'text',
                'title' => 'Secciones del curso',
                'summary' => 'He obtenido la estructura general del curso directamente desde Moodle.',
                'content' => implode("\n", $lines)
            ];
        }

        if ($isCourseNameQuery) {
            $lines = [];
            $lines[] = 'Nombre del curso: ' . trim((string)$course->fullname);
            $lines[] = 'Nombre corto: ' . trim((string)$course->shortname);
            $lines[] = 'Formato: ' . trim((string)$course->format);
            if (!empty($course->summary)) {
                $lines[] = 'Resumen del curso: ' . preg_replace('/\s+/u', ' ', trim(strip_tags((string)$course->summary)));
            }

            return [
                'type' => 'text',
                'title' => 'Datos del curso',
                'summary' => 'He recuperado los datos principales del curso directamente desde Moodle.',
                'content' => implode("\n", $lines)
            ];
        }

        // Consulta general de contenido del curso: listar todas las secciones con sus actividades.
        if ($isCourseContentQuery) {
            $lines = [];
            $lines[] = 'Curso: ' . trim((string)$course->fullname);
            $lines[] = 'Total de secciones: ' . count($realSections);

            $totalActivities = 0;
            foreach ($realSections as $section) {
                $secTitle = trim((string)$section->name);
                if ($secTitle === '') {
                    $secTitle = 'Seccion ' . (int)$section->section;
                }
                $activities = self::list_section_activities($courseid, $section->id, (int)$section->section, $modinfo);
                $totalActivities += count($activities);

                $lines[] = '';
                $lines[] = 'Seccion ' . (int)$section->section . ': ' . $secTitle;
                if (!empty($activities)) {
                    foreach ($activities as $activity) {
                        $lines[] = '  - ' . $activity;
                    }
                } else {
                    $lines[] = '  (sin actividades visibles)';
                }
            }

            return [
                'type' => 'text',
                'title' => 'Contenido del curso ' . trim((string)$course->fullname),
                'summary' => 'He listado todas las secciones y actividades del curso.',
                'content' => implode("\n", $lines)
            ];
        }

        return null;
    }

    /**
     * ¿La pregunta pide el LISTADO del contenido de una sección?
     *
     * Regla de desempate cuando una sección y un recurso tienen nombres
     * parecidos (curso real: sección "RECURSOS" y recurso "MATERIAL 1"; sección 2
     * y recurso "MIC Tema 2"):
     *  - gana la SECCIÓN si la pregunta lleva un cuantificador de listado
     *    ("qué hay en", "qué actividades", "qué contiene", "lista");
     *  - gana el RECURSO si pide contenido o una acción sobre él ("resume",
     *    "ábreme", "el enunciado de", "qué dice"), porque entonces no hay
     *    cuantificador de listado y esta función devuelve false.
     *
     * @param string $query Consulta ya en minúsculas
     * @return bool
     */
    private static function is_section_listing_query(string $query): bool {
        $mentionsSection = (bool)preg_match(
            '/\b(secci[oó]n|seccion|secciones|apartado|tema|temas|unidad|bloque|m[oó]dulo|modulo)\b/u',
            $query
        );
        if (!$mentionsSection) {
            return false;
        }

        return (bool)preg_match(
            '/qu[eé]\s+(hay|actividades|contenidos?|recursos?|materiales?|elementos|documentos?|archivos?)' .
            '|qu[eé]\s+\w+\s+(hay|tiene|contiene|incluye)' .
            '|(contiene|incluye|tiene)\s+(la|el)\s+(secci[oó]n|seccion|tema|unidad|bloque|m[oó]dulo)' .
            '|list(a|ar|ame|ado)\b' .
            '|ens[eé][nñ]ame\s+(el\s+|las\s+|los\s+)?(contenido|actividades|recursos|materiales)' .
            '|contenido\s+(de|del)\s+(la\s+)?(secci[oó]n|seccion|tema|unidad|bloque|m[oó]dulo)' .
            '|dime\s+qu[eé]\s+(hay|actividades|contenidos?)' .
            '|todo\s+lo\s+que\s+hay/u',
            $query
        );
    }

    /**
     * Listado completo del contenido de una sección (con el tipo de cada
     * elemento). Es la respuesta canónica para un listado de sección: se usa
     * tanto cuando la pregunta lo pide explícitamente como cuando ningún
     * resolutor más específico ha respondido.
     *
     * @param int $courseid
     * @param object $section Registro de course_sections
     * @param mixed $modinfo
     * @return array
     */
    private static function build_section_listing_answer(int $courseid, $section, $modinfo = null): array {
        $title = trim((string)$section->name);
        if ($title === '') {
            $title = 'Seccion ' . (int)$section->section;
        }

        $activities = self::list_section_activities($courseid, $section->id, (int)$section->section, $modinfo);
        $summary = trim(strip_tags((string)$section->summary));

        $lines = [];
        $lines[] = 'Seccion: ' . $title;
        $lines[] = 'Numero de seccion: ' . (int)$section->section;
        if ($summary !== '') {
            $lines[] = 'Resumen: ' . preg_replace('/\s+/u', ' ', $summary);
        }
        $lines[] = 'Numero de actividades: ' . count($activities);
        if (!empty($activities)) {
            $lines[] = 'Contenidos dentro de esta seccion:';
            foreach ($activities as $activity) {
                $lines[] = '- ' . $activity;
            }
        } else {
            $lines[] = 'No se detectaron actividades visibles en esta seccion.';
        }

        return [
            'type' => 'text',
            'title' => 'Contenido de la sección ' . $title,
            'summary' => 'He encontrado la sección consultada dentro del curso.',
            'content' => implode("\n", $lines)
        ];
    }

    /**
     * Resolve direct queries about a resource/document/PDF by name.
     *
     * @param int $courseid
     * @param string $query Lowercased query
     * @return array|null
     */
    private static function resolve_direct_resource_query(int $courseid, string $query): ?array {
        global $DB;

        $isSummaryIntent = (bool)preg_match('/resumen|resumir|s[ií]ntesis|sintesis|resum[eé]n|de\s+qu[eé]\s+va|de\s+qu[eé]\s+trata/u', $query);
        $isContentIntent = !$isSummaryIntent && self::is_pdf_content_query($query);
        $isAnalyticsIntent = self::is_course_analytics_query($query);

        // Atajo: en una pregunta de analitica de curso solo puede ganar un match
        // EXACTO de nombre (el difuso se descarta unas lineas mas abajo). Comprobar
        // primero si la pregunta contiene algun nombre de actividad evita barrer las
        // 13 tablas de tipos de modulo en cada mensaje para nada.
        if ($isAnalyticsIntent && !self::query_mentions_any_activity_name($courseid, $query)) {
            return null;
        }

        $resources = $DB->get_records('resource', ['course' => $courseid], 'name ASC', 'id, name, intro');

        // También buscar quizzes y tareas (assign).
        $quizzes = $DB->get_records('quiz', ['course' => $courseid], 'name ASC', 'id, name, intro');
        $assigns = $DB->get_records('assign', ['course' => $courseid], 'name ASC', 'id, name, intro');

        // Buscar otros tipos de actividad comunes en Moodle.
        $forums = $DB->get_records('forum', ['course' => $courseid], 'name ASC', 'id, name, intro');
        $pages = [];
        $urls = [];
        $books = [];
        $folders = [];
        $glossaries = [];
        $wikis = [];
        $choices = [];
        $feedbacks = [];
        $lessons = [];
        try { $pages = $DB->get_records('page', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $urls = $DB->get_records('url', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $books = $DB->get_records('book', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $folders = $DB->get_records('folder', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $glossaries = $DB->get_records('glossary', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $wikis = $DB->get_records('wiki', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $choices = $DB->get_records('choice', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $feedbacks = $DB->get_records('feedback', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}
        try { $lessons = $DB->get_records('lesson', ['course' => $courseid], 'name ASC', 'id, name, intro'); } catch (\Throwable $e) {}

        // Otros tipos de actividad comunes.
        $otherTypes = [
            'forum' => $forums,
            'page' => $pages,
            'url' => $urls,
            'book' => $books,
            'folder' => $folders,
            'glossary' => $glossaries,
            'wiki' => $wikis,
            'choice' => $choices,
            'feedback' => $feedbacks,
            'lesson' => $lessons,
        ];

        // Orden de matching: actividades "accionables" (quiz/assign/otras) ANTES
        // que los recursos, para que un PDF con una palabra generica en el nombre
        // no eclipse al quiz/tarea/foro real (bug: "nota media" -> "NOTA INFORMATIVA").
        $collections = ['quiz' => $quizzes, 'assign' => $assigns] + $otherTypes + ['resource' => $resources];

        // Fase 1: match EXACTO (nombre completo e inequivoco) en todos los tipos.
        $matched = null;
        $matchedType = null;
        foreach ($collections as $typeName => $records) {
            if (!empty($records)) {
                $exact = self::match_activity_by_name_exact($records, $query);
                if ($exact !== null) {
                    $matched = $exact;
                    $matchedType = $typeName;
                    break;
                }
            }
        }

        // Sin nombre de actividad inequivoco: si es una pregunta de analitica de
        // curso (nota media, matriculados, en riesgo, ranking...), no forzar un
        // match difuso sobre un recurso — que la responda la ruta de analitica/LLM.
        if ($matched === null && $isAnalyticsIntent) {
            return null;
        }

        // Fase 2: sin match exacto, probar difuso (umbral alto), mismo orden.
        if ($matched === null) {
            foreach ($collections as $typeName => $records) {
                if (!empty($records)) {
                    $fuzzy = self::match_activity_by_name_fuzzy($records, $query);
                    if ($fuzzy !== null) {
                        $matched = $fuzzy;
                        $matchedType = $typeName;
                        break;
                    }
                }
            }
        }

        if ($matched === null) {
            return null;
        }

        // ----- Quiz / Assign path -----
        if ($matchedType === 'quiz') {
            return self::build_quiz_answer($courseid, $matched, $query, $isSummaryIntent, $isContentIntent);
        }
        if ($matchedType === 'assign') {
            return self::build_assign_answer($courseid, $matched, $query);
        }

        // ----- Other activity types -----
        if (in_array($matchedType, ['forum', 'page', 'url', 'book', 'folder', 'glossary', 'wiki', 'choice', 'feedback', 'lesson'], true)) {
            return self::build_generic_activity_answer($courseid, $matched, $matchedType, $query);
        }

        $cm = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND m.name = :modname
                AND cm.instance = :instance
                AND cm.deletioninprogress = 0",
            ['courseid' => $courseid, 'modname' => 'resource', 'instance' => $matched->id]
        );

        $mimetype = '';
        $filename = '';
        if ($cm) {
            try {
                $context = \context_module::instance($cm->id);
                $fs = get_file_storage();
                $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder, id', false);
                foreach ($files as $file) {
                    $filename = $file->get_filename();
                    $mimetype = $file->get_mimetype();
                    break;
                }
            } catch (\Throwable $e) {
                // Non-fatal.
            }
        }

        $joined = '';
        if ($cm) {
            $joined = self::get_resource_joined_chunks_by_cmid($courseid, (int)$cm->id);
        }
        if ($joined === '' && $cm) {
            $joined = self::get_live_resource_text((int)$cm->id, (int)$matched->id, (string)$matched->name);
        }
        if ($joined === '') {
            $joined = self::get_resource_joined_chunks($courseid, (string)$matched->name);
        }
        $joinedLower = mb_strtolower($joined, 'UTF-8');

        $summaryParts = [];
        if ($filename !== '' || $mimetype !== '') {
            $isPdf = ($mimetype === 'application/pdf') || preg_match('/\.pdf$/i', $filename);
            if ($isPdf) {
                $summaryParts[] = 'Es un PDF del curso.';
            } else if ($filename !== '') {
                $summaryParts[] = 'Es un recurso del curso (' . $filename . ').';
            } else {
                $summaryParts[] = 'Es un recurso del curso.';
            }
        }

        if ($summaryParts === []) {
            $summaryParts[] = 'He localizado el recurso dentro del curso.';
        }

        $contentLines = [];
        $resourceName = trim((string)$matched->name);
        $intro = trim(strip_tags((string)$matched->intro));
        if ($isSummaryIntent) {
            $summaryText = self::build_resource_summary($joined, $intro, $filename);
            $contentLines[] = 'Resumen: ' . ($summaryText !== '' ? $summaryText : 'No se pudo extraer suficiente texto para resumir el PDF con precisión.');
        } else if ($isContentIntent) {
            // Placeholder — api_chat.php lo reemplaza con la respuesta real del modelo.
            $contentLines[] = 'Buscando la respuesta en el documento...';
        } else {
            $contentLines[] = 'Recurso: ' . $resourceName;
            if ($filename !== '') {
                $contentLines[] = 'Archivo: ' . $filename;
            }
            if ($mimetype !== '') {
                $contentLines[] = 'Tipo: ' . $mimetype;
            }
            if ($intro !== '') {
                $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
            }
        }

        $result = [
            'type' => 'text',
            'title' => $isSummaryIntent ? ('Resumen del recurso ' . $resourceName) : ($isContentIntent ? $resourceName : ('Recurso ' . $resourceName)),
            'summary' => implode(' ', $summaryParts),
            'content' => implode("\n", $contentLines)
        ];

        if ($isSummaryIntent) {
            $result['summary_mode'] = true;
            $result['raw_summary_source'] = self::build_summary_source_text($joined, $intro, $filename);
        }
        if ($isContentIntent && $cm) {
            $extText = self::get_resource_chunks_extended($courseid, (int)$cm->id, (int)$matched->id, (string)$matched->name);
            if ($extText !== '') {
                $result['content_mode'] = true;
                $result['raw_content_source'] = $extText;
            } else {
                // Sin texto disponible — mostrar metadatos en lugar del placeholder.
                $fallbackLines = ['Recurso: ' . $resourceName];
                if ($filename !== '') { $fallbackLines[] = 'Archivo: ' . $filename; }
                if ($mimetype !== '') { $fallbackLines[] = 'Tipo: ' . $mimetype; }
                $result['content'] = implode("\n", $fallbackLines);
            }
        }

        return self::attach_activity_link(
            $result,
            $courseid,
            'resource',
            $cm ? (int)$cm->id : null,
            $resourceName,
            'Recurso',
            $query
        );
    }

    /**
     * ¿Menciona la pregunta el nombre de ALGUNA actividad del curso, como palabra
     * completa?
     *
     * Usa get_fast_modinfo(), que Moodle ya mantiene en caché y expone el nombre de
     * cada modulo (OJO: {course_modules} NO tiene columna `name`; el nombre vive en
     * la tabla de cada tipo de modulo, que es justo lo que queremos evitar barrer).
     *
     * Deliberadamente permisiva: si dice que si, el flujo normal decide de verdad.
     *
     * @param int $courseid
     * @param string $query Pregunta en minusculas.
     * @return bool
     */
    private static function query_mentions_any_activity_name(int $courseid, string $query): bool {
        if (!function_exists('\get_fast_modinfo')) {
            // Sin modinfo no se descarta nada: sigue el camino largo de siempre.
            return true;
        }

        try {
            $modinfo = \get_fast_modinfo($courseid);
        } catch (\Throwable $e) {
            return true;
        }

        foreach ($modinfo->get_cms() as $cm) {
            $name = mb_strtolower(trim((string)$cm->name), 'UTF-8');
            if ($name === '') {
                continue;
            }
            if (preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/ui', $query)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match an activity (resource/quiz/assign) by name in the query.
     * First tries exact whole-word match, then fuzzy token match.
     *
     * @param array $records DB records with 'name' field
     * @param string $query  Lowercased query
     * @return object|null
     */
    private static function match_activity_by_name(array $records, string $query) {
        $exact = self::match_activity_by_name_exact($records, $query);
        if ($exact !== null) {
            return $exact;
        }
        return self::match_activity_by_name_fuzzy($records, $query);
    }

    /**
     * Exact whole-word match: the record's full name appears verbatim in the query.
     *
     * @param array $records
     * @param string $query
     * @return object|null
     */
    private static function match_activity_by_name_exact(array $records, string $query) {
        // Se elige el nombre MÁS LARGO de los que coinciden, no el primero de la
        // lista: con nombres que se solapan ("Tarea 1" y "Tarea 1 corregida") devolver
        // el primero hacía ganar siempre al más corto aunque la pregunta citara el
        // largo. Mismo criterio que find_matching_section_for_query().
        $best = null;
        $bestLength = 0;
        foreach ($records as $record) {
            $name = mb_strtolower(trim((string)$record->name), 'UTF-8');
            if ($name === '') {
                continue;
            }
            $length = mb_strlen($name, 'UTF-8');
            if ($length <= $bestLength) {
                continue;
            }
            if (preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/ui', $query)) {
                $best = $record;
                $bestLength = $length;
            }
        }
        return $best;
    }

    /**
     * Fuzzy match: scores records by how much of their significant name tokens are
     * covered by the query. A single generic word is not enough on its own — either
     * the name is fully covered, or at least 2 independent significant words hit.
     * A number in the name ("Tema 3", "Problema 2") acts as a hard discriminator.
     *
     * @param array $records
     * @param string $query
     * @return object|null
     */
    private static function match_activity_by_name_fuzzy(array $records, string $query) {
        // Words that appear in virtually every Moodle activity name or teacher query
        // are useless as discriminators and must not contribute to the score.
        $stopwords = [
            'curso', 'foro', 'tarea', 'tema', 'temas', 'quiz', 'link', 'book', 'page',
            'tipo', 'test', 'nota', 'notas', 'alumno', 'alumnos', 'estudiante', 'estudiantes',
            'resumen', 'participante', 'participantes', 'guia', 'manual', 'cuestionario',
            'actividad', 'actividades', 'pregunta', 'preguntas',
            // Genéricas de nombre de recurso/sección: un PDF llamado "MATERIAL 1"
            // no puede engancharse por la palabra "material" de la pregunta.
            'material', 'materiales', 'recurso', 'recursos', 'documento', 'documentos',
            'archivo', 'archivos', 'apartado', 'seccion', 'sección', 'unidad',
            'bloque', 'modulo', 'módulo', 'contenido', 'contenidos',
        ];
        $ordinalMap = [
            'primer' => '1', 'primero' => '1', 'primera' => '1',
            'segundo' => '2', 'segunda' => '2', 'tercer' => '3', 'tercero' => '3',
            'tercera' => '3', 'cuarto' => '4', 'cuarta' => '4', 'quinto' => '5', 'quinta' => '5',
        ];
        $queryNorm = strtr($query, $ordinalMap);
        preg_match_all('/\d+/u', $queryNorm, $qm);
        $queryNumbers = $qm[0];

        $bestRecord = null;
        $bestScore = 0;
        foreach ($records as $record) {
            $name = mb_strtolower(trim((string)$record->name), 'UTF-8');
            if ($name === '') {
                continue;
            }
            $tokens = preg_split('/\s+/u', $name);
            $significantTokens = 0;
            $hits = 0;
            $nameNumbers = [];
            foreach ($tokens as $token) {
                if (preg_match('/^\d+$/u', $token)) {
                    $nameNumbers[] = $token;
                    continue;
                }
                if (mb_strlen($token, 'UTF-8') < 4) {
                    continue;
                }
                if (in_array($token, $stopwords, true)) {
                    continue;
                }
                $significantTokens++;
                if (strpos($query, $token) !== false) {
                    $hits++;
                }
            }

            // Un numero en el nombre es un discriminador fuerte: si la pregunta
            // cita otro numero distinto, este candidato queda descartado.
            if (!empty($nameNumbers) && !empty($queryNumbers) && !array_intersect($nameNumbers, $queryNumbers)) {
                continue;
            }

            if ($significantTokens === 0 || $hits === 0) {
                continue;
            }

            // Hacen falta 2 palabras significativas SIEMPRE. Antes valia "o cubre el
            // nombre entero", y eso era una puerta trasera: un nombre con UNA sola
            // palabra significativa la cubria con un solo hit, que es justo el bug #2
            // que este umbral debia cerrar ("Introduccion a la investigacion"
            // enganchaba cualquier pregunta que dijera "investigacion"). Los nombres
            // de una sola palabra ya los resuelve match_activity_by_name_exact(),
            // que compara el nombre completo con limites de palabra.
            if ($hits < 2) {
                continue;
            }

            $score = $hits + ($hits >= $significantTokens ? 1 : 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestRecord = $record;
            }
        }

        return $bestRecord;
    }

    /**
     * Build a direct answer for a quiz activity.
     */
    private static function build_quiz_answer(int $courseid, object $quiz, string $query, bool $isSummaryIntent, bool $isContentIntent): array {
        global $DB;

        $quizName = trim((string)$quiz->name);
        $intro = trim(strip_tags((string)$quiz->intro));

        $cm = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND m.name = :modname
                AND cm.instance = :instance
                AND cm.deletioninprogress = 0",
            ['courseid' => $courseid, 'modname' => 'quiz', 'instance' => $quiz->id]
        );

        // Obtener número de preguntas y configuración básica.
        $questionCount = 0;
        $timeLimit = '';
        $attempts = '';
        if ($cm) {
            $questionCount = (int)$DB->count_records_sql(
                "SELECT COUNT(*) FROM {quiz_slots} WHERE quizid = :quizid",
                ['quizid' => $quiz->id]
            );
        }
        $quizRecord = $DB->get_record('quiz', ['id' => $quiz->id], 'id, timelimit, attempts, grade');
        if ($quizRecord) {
            if (!empty($quizRecord->timelimit)) {
                $mins = (int)($quizRecord->timelimit / 60);
                $timeLimit = $mins . ' minutos';
            }
            if (!empty($quizRecord->attempts)) {
                $attempts = (string)$quizRecord->attempts;
            }
        }

        // Datos de intentos/completitud de alumnos.
        $totalAttempts = 0;
        $uniqueUsers = 0;
        $avgGrade = null;
        $completedUsers = 0;
        try {
            $attemptStats = $DB->get_record_sql(
                "SELECT COUNT(*) AS total_attempts,
                        COUNT(DISTINCT qa.userid) AS unique_users,
                        AVG(qa.sumgrades) AS avg_sumgrades
                   FROM {quiz_attempts} qa
                  WHERE qa.quiz = :quizid
                    AND qa.state = :state",
                ['quizid' => $quiz->id, 'state' => 'finished']
            );
            if ($attemptStats) {
                $totalAttempts = (int)$attemptStats->total_attempts;
                $uniqueUsers = (int)$attemptStats->unique_users;
                if ($attemptStats->avg_sumgrades !== null && $quizRecord && (float)$quizRecord->grade > 0) {
                    // Convertir sumgrades a escala de calificación del quiz.
                    $maxSumgrades = $DB->get_field('quiz', 'sumgrades', ['id' => $quiz->id]);
                    if ($maxSumgrades > 0) {
                        $avgGrade = round(((float)$attemptStats->avg_sumgrades / (float)$maxSumgrades) * (float)$quizRecord->grade, 2);
                    }
                }
            }
            // Completitud via course_modules_completion.
            if ($cm) {
                $completedUsers = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cmc.userid)
                       FROM {course_modules_completion} cmc
                      WHERE cmc.coursemoduleid = :cmid
                        AND cmc.completionstate > 0",
                    ['cmid' => $cm->id]
                );
            }
        } catch (\Throwable $e) {
            // Non-fatal — some tables may not exist.
        }

        $contentLines = [];

        // Detectar la intención específica del usuario sobre el quiz.
        $q = mb_strtolower(trim($query), 'UTF-8');
        $asksQuestionCount = (bool)preg_match('/cu[aá]ntas?\s+preguntas/u', $q);
        $asksCompletionData = (bool)preg_match('/cu[aá]ntos?\s+(alumnos|estudiantes).*(complet|hecho|realizado)|qui[eé]n.*(complet|hecho|realizado)|han\s+(complet|hecho|realizado)/u', $q);
        $asksAttemptData = (bool)preg_match('/cu[aá]ntos?\s+(alumnos|estudiantes).*(intent|participad)|cu[aá]ntos?\s+intentos|participaci[oó]n/u', $q);
        $asksGradeData = (bool)preg_match('/nota\s+media|calificaci[oó]n\s+(media|promedio)|media\s+de\s+(nota|calificaci)/u', $q);
        $asksAboutContent = $isSummaryIntent || (bool)preg_match('/de\s+qu[eé]|qu[eé]\s+tipo|sobre\s+qu[eé]|qu[eé]\s+temas?|de\s+qu[eé]\s+va/u', $q);
        $asksSpecific = $asksQuestionCount || $asksCompletionData || $asksAttemptData || $asksGradeData;

        // Modo alumno: esta rama responde SIN pasar por el LLM y con datos leídos
        // de la BD, así que el permiso hay que comprobarlo aquí, donde se
        // construye el dato, no solo en el gate del pipeline.
        if (!chat_pipeline::user_can_view_analytics($courseid)) {
            if ($asksCompletionData || $asksGradeData) {
                return chat_pipeline::teacher_only_payload($query);
            }
            if ($asksAttemptData) {
                // "¿cuántos intentos permite?" sí es configuración pública; el
                // recuento de alumnos que lo han intentado, no.
                $contentLines[] = 'Cuestionario: ' . $quizName;
                if ($attempts !== '') {
                    $contentLines[] = 'Intentos permitidos: ' . ($attempts === '0' ? 'ilimitados' : $attempts);
                } else {
                    $contentLines[] = 'Intentos permitidos: sin limite configurado.';
                }
                return [
                    'type' => 'text',
                    'title' => 'Cuestionario: ' . $quizName,
                    'summary' => 'He localizado el cuestionario dentro del curso.',
                    'content' => implode("\n", $contentLines)
                ];
            }
            // Y en la vista general, ni una cifra del grupo: a 0/null para que
            // las líneas correspondientes no se impriman.
            $totalAttempts = 0;
            $uniqueUsers = 0;
            $completedUsers = 0;
            $avgGrade = null;
        }

        if ($asksQuestionCount) {
            // Respuesta directa: número de preguntas.
            $contentLines[] = 'Cuestionario: ' . $quizName;
            if ($questionCount > 0) {
                $contentLines[] = 'El cuestionario "' . $quizName . '" tiene ' . $questionCount . ' preguntas.';
            } else {
                $contentLines[] = 'No se han detectado preguntas configuradas en este cuestionario.';
            }
        } else if ($asksCompletionData) {
            // Respuesta directa: datos de completitud.
            $contentLines[] = 'Cuestionario: ' . $quizName;
            if ($completedUsers > 0) {
                $contentLines[] = $completedUsers . ' estudiantes han completado este cuestionario.';
            } else if ($uniqueUsers > 0) {
                $contentLines[] = $uniqueUsers . ' estudiantes han intentado este cuestionario, pero aún ninguno ha sido marcado como completado según los criterios de completitud de Moodle.';
            } else {
                $contentLines[] = 'Ningun estudiante ha completado este cuestionario aun.';
            }
            if ($totalAttempts > 0) {
                $contentLines[] = 'Total de intentos finalizados: ' . $totalAttempts;
            }
        } else if ($asksAttemptData) {
            // Respuesta directa: datos de intentos/participación.
            $contentLines[] = 'Cuestionario: ' . $quizName;
            if ($uniqueUsers > 0) {
                $contentLines[] = $uniqueUsers . ' estudiantes han intentado este cuestionario.';
                $contentLines[] = 'Total de intentos finalizados: ' . $totalAttempts;
            } else {
                $contentLines[] = 'Ningun estudiante ha realizado intentos en este cuestionario aun.';
            }
            if ($attempts !== '') {
                $contentLines[] = 'Intentos permitidos: ' . ($attempts === '0' ? 'ilimitados' : $attempts);
            }
        } else if ($asksGradeData) {
            // Respuesta directa: nota media.
            $contentLines[] = 'Cuestionario: ' . $quizName;
            if ($avgGrade !== null) {
                $contentLines[] = 'La calificacion media es ' . $avgGrade . (($quizRecord && (float)($quizRecord->grade ?? 0) > 0) ? (' sobre ' . (float)$quizRecord->grade) : '') . '.';
            } else if ($uniqueUsers === 0) {
                $contentLines[] = 'No hay calificaciones aun porque ningun estudiante ha realizado el cuestionario.';
            } else {
                $contentLines[] = 'No se pudo calcular la calificacion media.';
            }
        } else if ($asksAboutContent) {
            // "De qué trata" / "qué tipo de preguntas" → usar IA con el texto de las preguntas.
            $contentLines[] = 'Cuestionario: ' . $quizName;
            if ($cm) {
                $questionsText = self::get_quiz_questions_text($quiz->id);
                if ($questionsText !== '') {
                    $result = [
                        'type' => 'text',
                        'title' => 'Cuestionario: ' . $quizName,
                        'summary' => 'He localizado el cuestionario dentro del curso.',
                        'content' => 'Analizando el contenido del cuestionario...',
                        'content_mode' => true,
                        'raw_content_source' => $questionsText
                    ];
                    return $result;
                }
            }
            // Fallback si no hay texto de preguntas.
            if ($intro !== '') {
                $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
            }
            if ($questionCount > 0) {
                $contentLines[] = 'Numero de preguntas: ' . $questionCount;
            }
        } else {
            // Vista general: mostrar toda la información disponible.
            $contentLines[] = 'Cuestionario: ' . $quizName;
            if ($questionCount > 0) {
                $contentLines[] = 'Numero de preguntas: ' . $questionCount;
            }
            if ($timeLimit !== '') {
                $contentLines[] = 'Tiempo limite: ' . $timeLimit;
            }
            if ($attempts !== '') {
                $contentLines[] = 'Intentos permitidos: ' . ($attempts === '0' ? 'ilimitados' : $attempts);
            }
            if ($quizRecord && (float)($quizRecord->grade ?? 0) > 0) {
                $contentLines[] = 'Calificacion maxima: ' . (float)$quizRecord->grade;
            }
            if ($intro !== '') {
                $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
            }
            if ($uniqueUsers > 0) {
                $contentLines[] = 'Estudiantes que han intentado el cuestionario: ' . $uniqueUsers;
            }
            if ($totalAttempts > 0) {
                $contentLines[] = 'Total de intentos finalizados: ' . $totalAttempts;
            }
            if ($avgGrade !== null) {
                $contentLines[] = 'Calificacion media: ' . $avgGrade . (($quizRecord && (float)($quizRecord->grade ?? 0) > 0) ? (' / ' . (float)$quizRecord->grade) : '');
            }
            if ($completedUsers > 0) {
                $contentLines[] = 'Estudiantes que han completado la actividad: ' . $completedUsers;
            }
        }

        $result = [
            'type' => 'text',
            'title' => 'Cuestionario: ' . $quizName,
            'summary' => 'He localizado el cuestionario dentro del curso.',
            'content' => implode("\n", $contentLines)
        ];

        // Si pide contenido específico (preguntas, enunciados) y no es una pregunta de stats, pasar a IA.
        if ($isContentIntent && !$asksSpecific && $cm) {
            $questionsText = self::get_quiz_questions_text($quiz->id);
            if ($questionsText !== '') {
                $result['content_mode'] = true;
                $result['raw_content_source'] = $questionsText;
                $result['content'] = 'Buscando la respuesta en el cuestionario...';
            }
        }

        return self::attach_activity_link(
            $result,
            $courseid,
            'quiz',
            $cm ? (int)$cm->id : null,
            $quizName,
            'Cuestionario',
            $query
        );
    }

    /**
     * Obtener el texto de las preguntas de un quiz para IA.
     */
    private static function get_quiz_questions_text(int $quizid): string {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT qs.slot, q.id, q.questiontext, q.name AS qname
               FROM {quiz_slots} qs
               JOIN {question_references} qr ON qr.component = 'mod_quiz'
                    AND qr.questionarea = 'slot'
                    AND qr.itemid = qs.id
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid
           ORDER BY qs.slot ASC",
            ['quizid' => $quizid]
        );

        if (empty($rows)) {
            // Fallback for older Moodle versions without question_references.
            try {
                $rows = $DB->get_records_sql(
                    "SELECT qs.slot, q.id, q.questiontext, q.name AS qname
                       FROM {quiz_slots} qs
                       JOIN {question} q ON q.id = qs.questionid
                      WHERE qs.quizid = :quizid
                   ORDER BY qs.slot ASC",
                    ['quizid' => $quizid]
                );
            } catch (\Throwable $e) {
                return '';
            }
        }

        if (empty($rows)) {
            return '';
        }

        $parts = [];
        foreach ($rows as $row) {
            $text = trim(strip_tags((string)$row->questiontext));
            $qname = trim((string)($row->qname ?? ''));
            $label = 'Pregunta ' . (int)$row->slot;
            if ($qname !== '') {
                $label .= ' (' . $qname . ')';
            }
            $parts[] = $label . ': ' . ($text !== '' ? $text : '(sin texto)');
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build a direct answer for an assign (task) activity.
     */
    private static function build_assign_answer(int $courseid, object $assign, string $query): array {
        global $DB;

        $assignName = trim((string)$assign->name);
        $intro = trim(strip_tags((string)$assign->intro));

        $fullAssign = $DB->get_record('assign', ['id' => $assign->id], 'id, duedate, allowsubmissionsfromdate, grade');

        // Obtener el course_module para completion data.
        $cm = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND m.name = :modname
                AND cm.instance = :instance
                AND cm.deletioninprogress = 0",
            ['courseid' => $courseid, 'modname' => 'assign', 'instance' => $assign->id]
        );

        // Datos de entregas y completitud.
        $submittedCount = 0;
        $gradedCount = 0;
        $completedUsers = 0;
        $avgGrade = null;
        try {
            $submittedCount = (int)$DB->count_records_sql(
                "SELECT COUNT(DISTINCT asub.userid)
                   FROM {assign_submission} asub
                  WHERE asub.assignment = :assignid
                    AND asub.status = :status
                    AND asub.latest = 1",
                ['assignid' => $assign->id, 'status' => 'submitted']
            );
            // Solo el ULTIMO intento calificado de cada alumno: {assign_grades}
            // guarda una fila por intento, asi que promediar todas las filas mezcla
            // las notas de intentos ya superados y baja la media artificialmente.
            $gradeStats = $DB->get_record_sql(
                "SELECT COUNT(*) AS graded, AVG(ag.grade) AS avg_grade
                   FROM {assign_grades} ag
                  WHERE ag.assignment = :assignid
                    AND ag.grade >= 0
                    AND ag.attemptnumber = (
                        SELECT MAX(ag2.attemptnumber)
                          FROM {assign_grades} ag2
                         WHERE ag2.assignment = ag.assignment
                           AND ag2.userid = ag.userid
                           AND ag2.grade >= 0
                    )",
                ['assignid' => $assign->id]
            );
            if ($gradeStats) {
                $gradedCount = (int)$gradeStats->graded;
                if ($gradeStats->avg_grade !== null) {
                    $avgGrade = round((float)$gradeStats->avg_grade, 2);
                }
            }
            if ($cm) {
                $completedUsers = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cmc.userid)
                       FROM {course_modules_completion} cmc
                      WHERE cmc.coursemoduleid = :cmid
                        AND cmc.completionstate > 0",
                    ['cmid' => $cm->id]
                );
            }
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        // Detectar la intención específica sobre la tarea.
        $q = mb_strtolower(trim($query), 'UTF-8');
        $asksCompletionData = (bool)preg_match('/cu[aá]ntos?\s+(alumnos|estudiantes).*(complet|hecho|realizado|entregad)|qui[eé]n.*(complet|hecho|realizado|entregad)|han\s+(complet|hecho|realizado|entregad)/u', $q);
        $asksSubmissionData = (bool)preg_match('/cu[aá]ntos?\s+(alumnos|estudiantes).*(entregad|enviado)|cu[aá]ntas?\s+entregas|entregas\s+recibidas/u', $q);
        $asksGradeData = (bool)preg_match('/nota\s+media|calificaci[oó]n\s+(media|promedio)|media\s+de\s+(nota|calificaci)/u', $q);
        $asksAboutContent = (bool)preg_match('/de\s+qu[eé]|qu[eé]\s+tipo|sobre\s+qu[eé]|de\s+qu[eé]\s+va|de\s+qu[eé]\s+trata/u', $q);

        $contentLines = [];

        // Modo alumno: entregas, calificadas y completitud son dato del
        // profesorado (misma regla que en build_quiz_answer).
        if (!chat_pipeline::user_can_view_analytics($courseid)) {
            if ($asksCompletionData || $asksSubmissionData || $asksGradeData) {
                return chat_pipeline::teacher_only_payload($query);
            }
            $submittedCount = 0;
            $gradedCount = 0;
            $completedUsers = 0;
            $avgGrade = null;
        }

        if ($asksCompletionData || $asksSubmissionData) {
            $contentLines[] = 'Tarea: ' . $assignName;
            if ($submittedCount > 0) {
                $contentLines[] = $submittedCount . ' estudiantes han entregado esta tarea.';
            } else {
                $contentLines[] = 'Ningun estudiante ha entregado esta tarea aun.';
            }
            if ($completedUsers > 0) {
                $contentLines[] = $completedUsers . ' estudiantes han completado la actividad.';
            }
            if ($gradedCount > 0) {
                $contentLines[] = $gradedCount . ' entregas han sido calificadas.';
            }
        } else if ($asksGradeData) {
            $contentLines[] = 'Tarea: ' . $assignName;
            if ($avgGrade !== null) {
                $contentLines[] = 'La calificacion media es ' . $avgGrade . (($fullAssign && (float)($fullAssign->grade ?? 0) > 0) ? (' sobre ' . (float)$fullAssign->grade) : '') . '.';
                $contentLines[] = 'Entregas calificadas: ' . $gradedCount;
            } else if ($submittedCount === 0) {
                $contentLines[] = 'No hay calificaciones aun porque ningun estudiante ha entregado la tarea.';
            } else {
                $contentLines[] = 'No se pudo calcular la calificacion media.';
            }
        } else if ($asksAboutContent) {
            $contentLines[] = 'Tarea: ' . $assignName;
            if ($intro !== '') {
                $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
            } else {
                $contentLines[] = 'Esta tarea no tiene una descripcion configurada.';
            }
            if ($fullAssign && !empty($fullAssign->duedate)) {
                $contentLines[] = 'Fecha limite: ' . userdate($fullAssign->duedate);
            }
            if ($fullAssign && !empty($fullAssign->grade) && (float)$fullAssign->grade > 0) {
                $contentLines[] = 'Puntuacion maxima: ' . (float)$fullAssign->grade;
            }
        } else {
            // Vista general con toda la información.
            $contentLines[] = 'Tarea: ' . $assignName;
            if ($intro !== '') {
                $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
            }
            if ($fullAssign && !empty($fullAssign->duedate)) {
                $contentLines[] = 'Fecha limite: ' . userdate($fullAssign->duedate);
            }
            if ($fullAssign && !empty($fullAssign->allowsubmissionsfromdate)) {
                $contentLines[] = 'Abierta desde: ' . userdate($fullAssign->allowsubmissionsfromdate);
            }
            if ($fullAssign && !empty($fullAssign->grade) && (float)$fullAssign->grade > 0) {
                $contentLines[] = 'Puntuacion maxima: ' . (float)$fullAssign->grade;
            }
            if ($submittedCount > 0) {
                $contentLines[] = 'Entregas recibidas: ' . $submittedCount;
            }
            if ($gradedCount > 0) {
                $contentLines[] = 'Entregas calificadas: ' . $gradedCount;
            }
            if ($avgGrade !== null) {
                $contentLines[] = 'Calificacion media: ' . $avgGrade . (($fullAssign && (float)($fullAssign->grade ?? 0) > 0) ? (' / ' . (float)$fullAssign->grade) : '');
            }
            if ($completedUsers > 0) {
                $contentLines[] = 'Estudiantes que han completado la actividad: ' . $completedUsers;
            }
        }

        $result = [
            'type' => 'text',
            'title' => 'Tarea: ' . $assignName,
            'summary' => 'He localizado la tarea dentro del curso.',
            'content' => implode("\n", $contentLines)
        ];

        return self::attach_activity_link(
            $result,
            $courseid,
            'assign',
            $cm ? (int)$cm->id : null,
            $assignName,
            'Tarea',
            $query
        );
    }

    /**
     * Build a generic answer for any Moodle activity (forum, page, url, book, folder, glossary, wiki, choice, feedback, lesson).
     */
    private static function build_generic_activity_answer(int $courseid, object $activity, string $modType, string $query): array {
        global $DB;

        $typeLabels = [
            'forum' => 'Foro',
            'page' => 'Página',
            'url' => 'URL',
            'book' => 'Libro',
            'folder' => 'Carpeta',
            'glossary' => 'Glosario',
            'wiki' => 'Wiki',
            'choice' => 'Encuesta',
            'feedback' => 'Feedback',
            'lesson' => 'Lección',
        ];
        $typeLabel = $typeLabels[$modType] ?? ucfirst($modType);
        $activityName = trim((string)$activity->name);
        $intro = trim(strip_tags((string)($activity->intro ?? '')));

        $cm = $DB->get_record_sql(
            "SELECT cm.id
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND m.name = :modname
                AND cm.instance = :instance
                AND cm.deletioninprogress = 0",
            ['courseid' => $courseid, 'modname' => $modType, 'instance' => $activity->id]
        );

        $completedUsers = 0;
        try {
            if ($cm) {
                $completedUsers = (int)$DB->count_records_sql(
                    "SELECT COUNT(DISTINCT cmc.userid)
                       FROM {course_modules_completion} cmc
                      WHERE cmc.coursemoduleid = :cmid
                        AND cmc.completionstate > 0",
                    ['cmid' => $cm->id]
                );
            }
        } catch (\Throwable $e) {}

        // Modo alumno: nada de recuentos de participación del grupo.
        $canviewanalytics = chat_pipeline::user_can_view_analytics($courseid);
        if (!$canviewanalytics) {
            $completedUsers = 0;
        }

        $contentLines = [];
        $contentLines[] = $typeLabel . ': ' . $activityName;

        // Datos específicos por tipo.
        if ($modType === 'forum') {
            try {
                $discussionCount = (int)$DB->count_records('forum_discussions', ['forum' => $activity->id]);
                $postCount = (int)$DB->count_records_sql(
                    "SELECT COUNT(*) FROM {forum_posts} fp
                       JOIN {forum_discussions} fd ON fd.id = fp.discussion
                      WHERE fd.forum = :forumid",
                    ['forumid' => $activity->id]
                );
                $contentLines[] = 'Discusiones: ' . $discussionCount;
                $contentLines[] = 'Mensajes totales: ' . $postCount;
            } catch (\Throwable $e) {}
        } else if ($modType === 'url') {
            try {
                $urlRecord = $DB->get_record('url', ['id' => $activity->id], 'externalurl');
                if ($urlRecord && !empty($urlRecord->externalurl)) {
                    $contentLines[] = 'Enlace: ' . $urlRecord->externalurl;
                }
            } catch (\Throwable $e) {}
        } else if ($modType === 'page') {
            try {
                $pageRecord = $DB->get_record('page', ['id' => $activity->id], 'content');
                if ($pageRecord && !empty($pageRecord->content)) {
                    $pageText = trim(strip_tags((string)$pageRecord->content));
                    if (mb_strlen($pageText, 'UTF-8') > 500) {
                        $pageText = mb_substr($pageText, 0, 500, 'UTF-8') . '...';
                    }
                    $contentLines[] = 'Contenido: ' . preg_replace('/\s+/u', ' ', $pageText);
                }
            } catch (\Throwable $e) {}
        } else if ($modType === 'book') {
            try {
                $chapterCount = (int)$DB->count_records('book_chapters', ['bookid' => $activity->id, 'hidden' => 0]);
                $contentLines[] = 'Capitulos: ' . $chapterCount;
            } catch (\Throwable $e) {}
        } else if ($modType === 'folder') {
            try {
                if ($cm) {
                    $context = \context_module::instance($cm->id);
                    $fs = get_file_storage();
                    $files = $fs->get_area_files($context->id, 'mod_folder', 'content', 0, 'sortorder, id', false);
                    $fileNames = [];
                    foreach ($files as $file) {
                        $fileNames[] = $file->get_filename();
                    }
                    $contentLines[] = 'Archivos en la carpeta: ' . count($fileNames);
                    if (!empty($fileNames)) {
                        foreach (array_slice($fileNames, 0, 10) as $fn) {
                            $contentLines[] = '  - ' . $fn;
                        }
                        if (count($fileNames) > 10) {
                            $contentLines[] = '  ... y ' . (count($fileNames) - 10) . ' más';
                        }
                    }
                }
            } catch (\Throwable $e) {}
        } else if ($modType === 'glossary') {
            try {
                $entryCount = (int)$DB->count_records('glossary_entries', ['glossaryid' => $activity->id]);
                $contentLines[] = 'Entradas: ' . $entryCount;
            } catch (\Throwable $e) {}
        } else if ($modType === 'choice') {
            try {
                $optionCount = (int)$DB->count_records('choice_options', ['choiceid' => $activity->id]);
                $contentLines[] = 'Opciones: ' . $optionCount;
                if ($canviewanalytics) {
                    // Cuántos han respondido la encuesta es dato del profesorado.
                    $answerCount = (int)$DB->count_records('choice_answers', ['choiceid' => $activity->id]);
                    $contentLines[] = 'Respuestas recibidas: ' . $answerCount;
                }
            } catch (\Throwable $e) {}
        }

        if ($intro !== '') {
            $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
        }
        if ($completedUsers > 0) {
            $contentLines[] = 'Estudiantes que han completado la actividad: ' . $completedUsers;
        }

        $result = [
            'type' => 'text',
            'title' => $typeLabel . ': ' . $activityName,
            'summary' => 'He localizado la actividad dentro del curso.',
            'content' => implode("\n", $contentLines)
        ];

        return self::attach_activity_link(
            $result,
            $courseid,
            $modType,
            $cm ? (int)$cm->id : null,
            $activityName,
            $typeLabel,
            $query
        );
    }

    /**
     * Resolve a label query within a matched section.
     * Labels in Moodle store their content in the 'intro' field of the 'label' table.
     */
    private static function resolve_direct_label_in_section_query(int $courseid, $section, string $query): ?array {
        global $DB;

        $labels = $DB->get_records_sql(
            "SELECT l.id, l.name, l.intro, l.introformat, cm.id AS cmid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {label} l ON l.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND cm.deletioninprogress = 0
                AND m.name = :modname
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $section->id, 'modname' => 'label']
        );

        if (empty($labels)) {
            return null;
        }

        // Si hay varios labels, usar el primero (o emparejar por nombre si es posible).
        $picked = null;
        foreach ($labels as $label) {
            $name = mb_strtolower(trim((string)$label->name), 'UTF-8');
            if ($name !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/ui', $query)) {
                $picked = $label;
                break;
            }
        }
        if ($picked === null) {
            $picked = array_values($labels)[0];
        }

        $sectionTitle = trim((string)$section->name);
        if ($sectionTitle === '') {
            $sectionTitle = 'Sección ' . (int)$section->section;
        }

        $labelText = trim(strip_tags((string)$picked->intro));
        $labelName = trim((string)$picked->name);
        if ($labelName === '') {
            $labelName = 'Etiqueta';
        }

        $isContentQuestion = self::is_pdf_content_query($query)
            || (bool)preg_match('/resultado|respuesta|resuelve|soluc|lee|leer|texto|dice|dime|cu[aá]nto|cu[aá]l|dame|pregunta|problema/u', $query);

        if ($isContentQuestion && $labelText !== '') {
            // Pasar el contenido del label a la IA para que responda la pregunta.
            return [
                'type' => 'text',
                'title' => $labelName . ' — ' . $sectionTitle,
                'summary' => 'Contenido de la etiqueta en la sección ' . $sectionTitle . '.',
                'content' => 'Buscando la respuesta en la etiqueta...',
                'content_mode' => true,
                'raw_content_source' => $labelText
            ];
        }

        // Respuesta informativa: mostrar el contenido del label.
        $contentLines = [];
        $contentLines[] = 'Etiqueta: ' . $labelName;
        $contentLines[] = 'Seccion: ' . $sectionTitle;
        if ($labelText !== '') {
            $contentLines[] = 'Contenido: ' . preg_replace('/\s+/u', ' ', $labelText);
        } else {
            $contentLines[] = 'La etiqueta no tiene texto visible.';
        }

        return [
            'type' => 'text',
            'title' => 'Etiqueta en sección ' . $sectionTitle,
            'summary' => 'He localizado la etiqueta en la sección ' . $sectionTitle . '.',
            'content' => implode("\n", $contentLines)
        ];
    }

    /**
     * Resolve quiz query constrained to a specific matched section.
     */
    private static function resolve_direct_quiz_in_section_query(int $courseid, $section, string $query): ?array {
        global $DB;

        $quizzes = $DB->get_records_sql(
            "SELECT q.id, q.name, q.intro
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {quiz} q ON q.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND cm.deletioninprogress = 0
                AND m.name = :modname
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $section->id, 'modname' => 'quiz']
        );

        if (empty($quizzes)) {
            return null;
        }

        // Match by name, or pick first.
        $picked = self::match_activity_by_name($quizzes, $query);
        if ($picked === null) {
            $picked = array_values($quizzes)[0];
        }

        $isSummaryIntent = (bool)preg_match('/resumen|resumir|de\s+qu[eé]\s+va|de\s+qu[eé]\s+(es|trata)/u', $query);
        $isContentIntent = !$isSummaryIntent && self::is_pdf_content_query($query);

        return self::build_quiz_answer($courseid, $picked, $query, $isSummaryIntent, $isContentIntent);
    }

    /**
     * Resolve assign query constrained to a specific matched section.
     */
    private static function resolve_direct_assign_in_section_query(int $courseid, $section, string $query): ?array {
        global $DB;

        $assigns = $DB->get_records_sql(
            "SELECT a.id, a.name, a.intro
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {assign} a ON a.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND cm.deletioninprogress = 0
                AND m.name = :modname
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $section->id, 'modname' => 'assign']
        );

        if (empty($assigns)) {
            return null;
        }

        $picked = self::match_activity_by_name($assigns, $query);
        if ($picked === null) {
            $picked = array_values($assigns)[0];
        }

        return self::build_assign_answer($courseid, $picked, $query);
    }

    /**
     * Resolve generic activity query within a matched section.
     * Searches forum, page, url, book, folder, glossary, wiki, choice, feedback, lesson.
     */
    private static function resolve_direct_generic_in_section_query(int $courseid, $section, string $query): ?array {
        global $DB;

        $modTypes = ['forum', 'page', 'url', 'book', 'folder', 'glossary', 'wiki', 'choice', 'feedback', 'lesson'];
        foreach ($modTypes as $modType) {
            try {
                $records = $DB->get_records_sql(
                    "SELECT t.id, t.name, t.intro
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                       JOIN {{$modType}} t ON t.id = cm.instance
                      WHERE cm.course = :courseid
                        AND cm.section = :sectionid
                        AND cm.deletioninprogress = 0
                        AND m.name = :modname
                   ORDER BY cm.id ASC",
                    ['courseid' => $courseid, 'sectionid' => $section->id, 'modname' => $modType]
                );
            } catch (\Throwable $e) {
                continue;
            }
            if (empty($records)) {
                continue;
            }
            $picked = self::match_activity_by_name($records, $query);
            if ($picked !== null) {
                return self::build_generic_activity_answer($courseid, $picked, $modType, $query);
            }
        }

        return null;
    }

    /**
     * Resolve resource/PDF query constrained to a specific matched section.
     *
     * @param int $courseid
     * @param object $section
     * @param string $query
     * @return array|null
     */
    private static function resolve_direct_resource_in_section_query(int $courseid, $section, string $query): ?array {
        global $DB;

        $isSummaryIntent = (bool)preg_match('/resumen|resumir|s[ií]ntesis|sintesis|resum[eé]n|de\s+qu[eé]\s+va|de\s+qu[eé]\s+trata/u', $query);

        $resources = $DB->get_records_sql(
            "SELECT r.id, r.name, r.intro, cm.id AS cmid
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
               JOIN {resource} r ON r.id = cm.instance
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND cm.deletioninprogress = 0
                AND m.name = :modname
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $section->id, 'modname' => 'resource']
        );

        if (empty($resources)) {
            return null;
        }

        $picked = null;
        foreach ($resources as $res) {
            $name = mb_strtolower(trim((string)$res->name), 'UTF-8');
            if ($name !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/ui', $query)) {
                $picked = $res;
                break;
            }
        }

        if ($picked === null) {
            // If user explicitly asks for PDF in this section, prefer PDF mime/file.
            if (strpos($query, 'pdf') !== false) {
                foreach ($resources as $res) {
                    [$filename, $mimetype] = self::get_resource_file_info((int)$res->cmid);
                    $isPdf = ($mimetype === 'application/pdf') || preg_match('/\.pdf$/i', (string)$filename);
                    if ($isPdf) {
                        $picked = $res;
                        break;
                    }
                }
            }
        }

        if ($picked === null) {
            $picked = array_values($resources)[0];
        }

        [$filename, $mimetype] = self::get_resource_file_info((int)$picked->cmid);
        $joined = self::get_resource_joined_chunks_by_cmid($courseid, (int)$picked->cmid);
        if ($joined === '') {
            $joined = self::get_live_resource_text((int)$picked->cmid, (int)$picked->id, (string)$picked->name);
        }
        if ($joined === '') {
            $joined = self::get_resource_joined_chunks($courseid, (string)$picked->name);
        }
        $joinedLower = mb_strtolower($joined, 'UTF-8');

        $sectionTitle = trim((string)$section->name);
        if ($sectionTitle === '') {
            $sectionTitle = 'Seccion ' . (int)$section->section;
        }

        $summaryParts = [];
        $isPdf = ($mimetype === 'application/pdf') || preg_match('/\.pdf$/i', (string)$filename);
        if ($isPdf) {
            $summaryParts[] = 'Es un PDF de la sección ' . $sectionTitle . '.';
        } else {
            $summaryParts[] = 'Es un recurso de la sección ' . $sectionTitle . '.';
        }

        $contentLines = [];
        $resourceName = trim((string)$picked->name);
        $intro = trim(strip_tags((string)$picked->intro));
        if ($isSummaryIntent) {
            $summaryText = self::build_resource_summary($joined, $intro, $filename);
            $contentLines[] = 'Resumen: ' . ($summaryText !== '' ? $summaryText : 'No se pudo extraer suficiente texto para resumir el PDF con precisión.');
        } else {
            $contentLines[] = 'Seccion: ' . $sectionTitle;
            $contentLines[] = 'Recurso: ' . $resourceName;
            if ($filename !== '') {
                $contentLines[] = 'Archivo: ' . $filename;
            }
            if ($mimetype !== '') {
                $contentLines[] = 'Tipo: ' . $mimetype;
            }
            if ($intro !== '') {
                $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
            }
        }

        $result = [
            'type' => 'text',
            'title' => $isSummaryIntent ? ('Resumen del PDF/recurso de la sección ' . $sectionTitle) : ('PDF/recurso de la sección ' . $sectionTitle),
            'summary' => implode(' ', $summaryParts),
            'content' => implode("\n", $contentLines)
        ];

        if ($isSummaryIntent) {
            $result['summary_mode'] = true;
            $result['raw_summary_source'] = self::build_summary_source_text($joined, $intro, $filename);
        }

        return $result;
    }

    /**
     * @param int $cmid
     * @return array [filename, mimetype]
     */
    private static function get_resource_file_info(int $cmid): array {
        $filename = '';
        $mimetype = '';

        try {
            $context = \context_module::instance($cmid);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder, id', false);
            foreach ($files as $file) {
                $filename = (string)$file->get_filename();
                $mimetype = (string)$file->get_mimetype();
                break;
            }
        } catch (\Throwable $e) {
            // Non-fatal.
        }

        return [$filename, $mimetype];
    }

    /**
     * @param int $courseid
     * @param string $modulename
     * @return string
     */
    private static function get_resource_joined_chunks(int $courseid, string $modulename): string {
        global $DB;

        $rows = $DB->get_records_select(
            'block_pulso_content_chunks',
            'courseid = :courseid AND module_type = :modtype AND module_name = :name',
            ['courseid' => $courseid, 'modtype' => 'resource', 'name' => $modulename],
            'chunk_index ASC, id ASC',
            'chunk_text'
        );

        $joined = '';
        foreach ($rows as $row) {
            $joined .= "\n" . $row->chunk_text;
        }

        return mb_substr(trim($joined), 0, 2000);
    }

    /**
     * @param int $courseid
     * @param int $cmid
     * @return string
     */
    private static function get_resource_joined_chunks_by_cmid(int $courseid, int $cmid): string {
        global $DB;

        $rows = $DB->get_records_select(
            'block_pulso_content_chunks',
            'courseid = :courseid AND module_type = :modtype AND cmid = :cmid',
            ['courseid' => $courseid, 'modtype' => 'resource', 'cmid' => $cmid],
            'chunk_index ASC, id ASC',
            'chunk_text'
        );

        $joined = '';
        foreach ($rows as $row) {
            $joined .= "\n" . $row->chunk_text;
        }

        return mb_substr(trim($joined), 0, 2000);
    }

    /**
     * Obtiene un bloque ampliado del texto de un recurso para respuestas de contenido por IA.
     * No recorta a 2000 chars — devuelve hasta $charlimit para que el modelo tenga más contexto.
     */
    private static function get_resource_chunks_extended(int $courseid, int $cmid, int $resourceid, string $resourcename, int $charlimit = 7000): string {
        global $DB;

        $rows = $DB->get_records_select(
            'block_pulso_content_chunks',
            'courseid = :courseid AND module_type = :modtype AND cmid = :cmid',
            ['courseid' => $courseid, 'modtype' => 'resource', 'cmid' => $cmid],
            'chunk_index ASC, id ASC',
            'chunk_text'
        );

        $joined = '';
        foreach ($rows as $row) {
            $joined .= "\n" . $row->chunk_text;
        }
        $joined = trim($joined);

        if ($joined === '') {
            $joined = self::get_live_resource_text($cmid, $resourceid, $resourcename);
        }
        if ($joined === '') {
            $joined = self::get_resource_joined_chunks($courseid, $resourcename);
        }

        // Eliminar marcadores de chunk; dejar el ruido OCR para que el modelo lo interprete.
        $clean = preg_replace('/\[[^\]]+\]/u', ' ', $joined);
        $clean = preg_replace('/Archivo:\s+\S+/u', ' ', $clean);
        $clean = preg_replace('/Contenido PDF extraido:\s*/u', ' ', $clean);
        $clean = preg_replace('/\s+/u', ' ', trim($clean));

        return mb_substr($clean, 0, $charlimit);
    }

    /**
     * Try extracting a specific resource file on demand (live), bypassing stale index.
     *
     * @param int $cmid
     * @param int $resourceid
     * @param string $resourcename
     * @return string
     */
    private static function get_live_resource_text(int $cmid, int $resourceid, string $resourcename = ''): string {
        try {
            $extractor = new content_extractor();
            $chunks = $extractor->extract_module($cmid, 'resource', $resourceid);
            if (empty($chunks)) {
                return '';
            }

            $parts = [];
            foreach ($chunks as $chunk) {
                $txt = (string)($chunk['chunk_text'] ?? '');
                if ($txt === '') {
                    continue;
                }
                // Remove synthetic chunk prefix to keep plain content.
                $txt = preg_replace('/^\[[^\]]+\]\s*/u', '', $txt);
                $parts[] = trim((string)$txt);
            }

            $joined = trim(implode("\n", array_filter($parts)));
            if ($joined === '') {
                return '';
            }

            return mb_substr($joined, 0, 2500);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * @param string $query
     * @return bool
     */
    private static function is_resource_query(string $query): bool {
        return (bool)preg_match('/pdf|archivo|documento|recurso|\bresource\b|material|qu[eé]\s+es|de\s+qu[eé]\s+trata|qu[eé]\s+contiene|contenido\s+de|quiz|cuestionario|examen|tarea|assignment|\blabel\b|\betiqueta\b|\bforo\b|\bforum\b|\bp[aá]gina\b|\bpage\b|\burl\b|\benlace\b|\blibro\b|\bbook\b|\bcarpeta\b|\bfolder\b|\bglosario\b|\bglossary\b|\bwiki\b|\bencuesta\b|\bchoice\b|\bfeedback\b|\blecci[oó]n\b|\blesson\b|\bactividad\b/u', $query);
    }

    /**
     * Detecta preguntas semánticas sobre la temática del curso ("¿de qué trata
     * el curso?", "describe este curso"). Estas consultas deben responderse
     * con la IA (RAG + resumen del curso), nunca con la ruta directa.
     *
     * @param string $query Lowercased query
     * @return bool
     */
    private static function is_course_about_query(string $query): bool {
        return (bool)preg_match(
            '/de\s+qu[eé]\s+(se\s+)?(trata|va)\s+(este\s+|el\s+)?curso' .
            '|sobre\s+qu[eé]\s+(trata|va|es)\s+(este\s+|el\s+)?curso' .
            '|qu[eé]\s+es\s+(este|el)\s+curso' .
            '|cu[eé]ntame\s+(algo\s+)?(sobre\s+|de\s+)?(este\s+|el\s+)?curso' .
            '|descr[ií]be(me)?\s+(este\s+|el\s+)?curso' .
            '|tem[aá]tica\s+(del|de\s+este)\s+curso' .
            '|objetivos?\s+(del|de\s+este)\s+curso' .
            '|resumen\s+(del|de\s+este)\s+curso' .
            '|res[uú]me(me)?\s+(este\s+|el\s+)?curso/u',
            $query
        );
    }

    /**
     * Detecta si el usuario pregunta por el contenido general del curso.
     */
    private static function is_course_content_query(string $query): bool {
        return (bool)preg_match(
            '/qu[eé]\s+(contenidos?|actividades|materiales?)\s+(hay|tiene|existen?|incluye|hay\s+en)\b' .
            '|contenidos?\s+(del|de\s+este|de\s+el)\s+curso' .
            '|actividades\s+(del|de\s+este)\s+curso' .
            '|qu[eé]\s+hay\s+en\s+(este|el)\s+curso' .
            '|todo\s+(el\s+)?contenido\s+del' .
            '|estructura\s+(del|de\s+este)\s+curso' .
            '|mu[eé]strame\s+(el|todo|los?)\s+(contenido|curso)' .
            // General "what is this course about" questions that should go to OpenAI.
            '|de\s+qu[eé]\s+(se\s+)?trata\s+(este\s+|el\s+)?curso' .
            '|de\s+qu[eé]\s+va\s+(este\s+|el\s+)?curso' .
            '|sobre\s+qu[eé]\s+(trata|va|es)\s+(este\s+|el\s+)?curso' .
            '|qu[eé]\s+es\s+(este|el)\s+curso' .
            '|cu[eé]ntame\s+(algo\s+)?(sobre\s+)?(este\s+|el\s+)?curso' .
            '|resumen\s+(del\s+|de\s+este\s+)curso' .
            '|dame\s+un\s+resumen\s+del\s+curso' .
            '|describe\s+(este\s+|el\s+)?curso/u',
            $query
        );
    }

    /**
     * Detecta si el usuario pide contenido específico de un documento (enunciados, problemas, ejercicios...).
     *
     * @param string $query
     * @return bool
     */
    private static function is_pdf_content_query(string $query): bool {
        return (bool)preg_match('/enunciado|primer\s+problem[ao]|\bproblema\s+\d+|primer\s+ejercicio|ejercicio\s*\d+|mu[eé]strame\s+(el|la|los|las|un)\b|soluci[oó]n\s+del\b|dame\s+(un|el|la|los)\s+\w+|qu[eé]\s+preguntas?|cu[aá]ntas?\s+preguntas?|pregunta\s+\d+|\bpregunta\s+del/u', $query);
    }

    /**
     * Detecta preguntas de analitica de CURSO (nota media, matriculados, en riesgo,
     * ranking, completitud...) que deben resolverse via analitica/LLM y no deben
     * dejar que un match difuso de recurso/PDF las secuestre.
     *
     * @param string $query
     * @return bool
     */
    public static function is_course_analytics_query(string $query): bool {
        return (bool)preg_match(
            '/nota\s+media|calificaci[oó]n\s+(media|promedio)|media\s+de\s+(nota|calificaci)' .
            '|matricul|en\s+riesgo|ranking|mejor(es)?\s+nota|peor(es)?\s+nota' .
            '|porcentaje\s+de\s+aprobad' .
            '|cu[aá]ntos?\s+(alumnos|estudiantes)\b(?!.*\b(pdf|documento|archivo)\b)' .
            '|qui[eé]n(es)?\s+(es|tiene|ha|han)\s+.*(nota|complet|aprobad|suspend)/u',
            $query
        );
    }

    /**
     * @param string $query
     * @return bool
     */
    private static function is_explicit_section_query(string $query): bool {
        return (bool)preg_match('/secci[oó]n|secciones|apartado|tema\s+\d+|contenido\s+de\s+la\s+secci[oó]n|actividades\s+de\s+la\s+secci[oó]n/u', $query);
    }

    /**
     * ¿El usuario pregunta por la UBICACIÓN o el ACCESO a algo?
     * ("¿dónde está X?", "¿cómo accedo al foro?", "¿cómo entrego la tarea?")
     *
     * @param string $query
     * @return bool
     */
    private static function is_location_query(string $query): bool {
        return (bool)preg_match(
            '/d[oó]nde\s+(est[aá]|est[aá]n|encuentro|puedo\s+(ver|encontrar|acceder))' .
            '|c[oó]mo\s+(accedo|acceder|llego|llegar|entro|entrar|abro|abrir|participo|participar' .
            '|entrego|entregar|env[ií]o|enviar|hago|hacer|puedo\s+(acceder|entrar|participar|entregar|ver|abrir|hablar|escribir))' .
            '|(enlace|link|url)\s+(de|a|del|para|hacia)' .
            '|ll[eé]vame\s+a|c[oó]mo\s+se\s+(accede|entra|participa)/u',
            $query
        );
    }

    /**
     * Texto breve y determinista de "cómo usarlo" según el tipo de módulo.
     * No usa IA: son los pasos reales de la interfaz de Moodle.
     *
     * @param string $modname
     * @return string
     */
    private static function activity_usage_hint(string $modname): string {
        $hints = [
            'forum'    => 'Para participar, abre el foro y pulsa "Añadir un nuevo tema de debate", o responde a un tema ya existente.',
            'assign'   => 'Para entregar, abre la tarea y pulsa "Agregar entrega"; adjunta tu trabajo y guarda los cambios antes de la fecha límite.',
            'quiz'     => 'Para realizarlo, ábrelo y pulsa "Intentar el cuestionario ahora".',
            'scorm'    => 'Ábrelo y pulsa "Entrar" para lanzar el contenido.',
            'resource' => 'Ábrelo para consultar o descargar el archivo.',
            'page'     => 'Ábrela para leer el contenido.',
            'url'      => 'Ábrelo para ir al enlace externo.',
            'book'     => 'Ábrelo y navega por los capítulos desde el índice.',
            'folder'   => 'Ábrela para ver y descargar los archivos.',
            'glossary' => 'Ábrelo para consultar las entradas del glosario.',
            'wiki'     => 'Ábrela para leer o editar sus páginas.',
            'choice'   => 'Ábrela para elegir tu opción y enviarla.',
            'feedback' => 'Ábrelo para responder al cuestionario de feedback.',
            'lesson'   => 'Ábrela y sigue los pasos que te vaya presentando.',
        ];
        return $hints[$modname] ?? '';
    }

    /**
     * Construir el enlace directo a un módulo del curso.
     *
     * IMPORTANTE: la URL se construye SIEMPRE aquí con moodle_url (nunca la
     * genera el LLM, que se las inventaría) y se comprueba la visibilidad real
     * para el usuario actual con get_fast_modinfo()->uservisible, para no
     * enlazar actividades ocultas o restringidas (clave en modo alumno).
     *
     * @param int $courseid
     * @param string $modname Tipo de módulo Moodle ('forum', 'quiz', 'assign'...)
     * @param int $cmid
     * @param string $activityname
     * @param string $typelabel Etiqueta legible del tipo ('Foro', 'Cuestionario'...)
     * @return array|null ['url', 'label', 'section'] o null si no es visible
     */
    private static function build_activity_link(
        int $courseid,
        string $modname,
        int $cmid,
        string $activityname = '',
        string $typelabel = ''
    ): ?array {
        if ($cmid <= 0 || $modname === '') {
            return null;
        }
        if (!function_exists('\get_fast_modinfo')) {
            return null;
        }

        try {
            $modinfo = \get_fast_modinfo($courseid);
            $cminfo = $modinfo->get_cm($cmid);
            if (empty($cminfo) || empty($cminfo->uservisible)) {
                // Oculta o restringida para este usuario: no dar enlace.
                return null;
            }

            $url = new \moodle_url('/mod/' . $modname . '/view.php', ['id' => $cmid]);

            $label = 'Ir a ' . ($typelabel !== '' ? \core_text::strtolower($typelabel) : 'la actividad');
            if ($activityname !== '') {
                $label .= ' "' . $activityname . '"';
            }

            // Ubicación legible: nombre de la sección o "Seccion N".
            $sectiondesc = '';
            $sectionnum = (int)($cminfo->sectionnum ?? 0);
            try {
                $secinfo = $modinfo->get_section_info($sectionnum);
                $secname = $secinfo ? trim((string)($secinfo->name ?? '')) : '';
                $sectiondesc = ($secname !== '')
                    ? ('Seccion ' . $sectionnum . ': ' . $secname)
                    : ('Seccion ' . $sectionnum);
            } catch (\Throwable $e) {
                $sectiondesc = 'Seccion ' . $sectionnum;
            }

            return [
                'url' => $url->out(false),
                'label' => $label,
                'section' => $sectiondesc,
            ];
        } catch (\Throwable $e) {
            // cmid inexistente o cualquier problema de modinfo: sin enlace, sin romper.
            return null;
        }
    }

    /**
     * Añadir a un payload de respuesta directa el enlace a la actividad y,
     * si la pregunta era de ubicación/acceso, dónde está y cómo usarla.
     *
     * @param array $result Payload de respuesta directa (se devuelve decorado)
     * @param int $courseid
     * @param string $modname
     * @param int|null $cmid
     * @param string $activityname
     * @param string $typelabel
     * @param string $query Pregunta original del usuario
     * @return array
     */
    private static function attach_activity_link(
        array $result,
        int $courseid,
        string $modname,
        ?int $cmid,
        string $activityname = '',
        string $typelabel = '',
        string $query = ''
    ): array {
        if (empty($cmid)) {
            return $result;
        }

        $link = self::build_activity_link($courseid, $modname, (int)$cmid, $activityname, $typelabel);
        if ($link === null) {
            return $result;
        }

        $result['link'] = [
            'url' => $link['url'],
            'label' => $link['label'],
        ];

        // Si preguntaba por ubicación/acceso, explicar dónde está y cómo usarlo.
        if ($query !== '' && self::is_location_query($query)) {
            $extra = [];
            if (!empty($link['section'])) {
                $extra[] = 'Ubicacion: ' . $link['section'];
            }
            $hint = self::activity_usage_hint($modname);
            if ($hint !== '') {
                $extra[] = $hint;
            }
            if (!empty($extra) && isset($result['content']) && is_string($result['content'])) {
                $result['content'] = rtrim($result['content']) . "\n" . implode("\n", $extra);
            }
        }

        return $result;
    }

    /**
     * @param string $joined
     * @return string
     */
    private static function extract_resource_snippet(string $joined): string {
        $text = preg_replace('/\[[^\]]+\]/u', '', $joined);
        $text = preg_replace('/Archivo:\s+.+/u', '', $text);
        $text = preg_replace('/Contenido PDF extraido:\s*/u', '', $text);
        $text = self::repair_ocr_text((string)$text);
        $text = preg_replace('/\s+/u', ' ', trim((string)$text));
        if ($text === '') {
            return '';
        }

        if (preg_match('/(.{0,80}(problema|problemas|ejercicio|ejercicios|ecuaci[oó]n|ecuaciones).{0,140})/iu', $text, $m)) {
            return trim($m[1]);
        }

        return mb_substr($text, 0, 180) . (mb_strlen($text) > 180 ? '...' : '');
    }

    /**
     * Build a short human-readable summary from extracted resource text.
     *
     * @param string $joined
     * @param string $intro
     * @return string
     */
    private static function build_resource_summary(string $joined, string $intro = '', string $filename = ''): string {
        $base = trim($joined);
        if ($base === '') {
            $base = trim($intro);
        }

        if ($base === '') {
            $fromName = self::build_filename_summary_hint($filename);
            if ($fromName !== '') {
                return $fromName;
            }
            return '';
        }

        $clean = preg_replace('/\[[^\]]+\]/u', ' ', $base);
        $clean = preg_replace('/Archivo:\s+.+/u', ' ', $clean);
        $clean = preg_replace('/Contenido PDF extraido:\s*/u', ' ', $clean);
        $clean = self::repair_ocr_text((string)$clean);
        $clean = preg_replace('/\s+/u', ' ', trim((string)$clean));
        if ($clean === '') {
            $fromName = self::build_filename_summary_hint($filename);
            return $fromName !== '' ? $fromName : '';
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $clean);
        $picked = [];
        foreach ($sentences as $s) {
            $s = trim((string)$s);
            if (mb_strlen($s, 'UTF-8') < 25) {
                continue;
            }
            $picked[] = $s;
            if (count($picked) >= 2) {
                break;
            }
        }

        if (!empty($picked)) {
            return implode(' ', $picked);
        }

        if (mb_strlen($clean, 'UTF-8') >= 35) {
            return mb_substr($clean, 0, 260) . (mb_strlen($clean, 'UTF-8') > 260 ? '...' : '');
        }

        $fromName = self::build_filename_summary_hint($filename);
        if ($fromName !== '') {
            return $fromName;
        }

        return '';
    }

    /**
     * Build a minimal semantic hint from filename when PDF text extraction fails.
     *
     * @param string $filename
     * @return string
     */
    private static function build_filename_summary_hint(string $filename): string {
        $name = trim((string)$filename);
        if ($name === '') {
            return '';
        }

        $name = preg_replace('/\.pdf$/i', '', $name);
        $name = preg_replace('/[_\-]+/', ' ', (string)$name);
        $name = preg_replace('/\s+/u', ' ', trim((string)$name));
        if ($name === '') {
            return '';
        }

        return 'No pude extraer texto completo del PDF, pero por el nombre del archivo parece corresponder al material ' . $name . '.';
    }

    /**
     * Prepare cleaned source text to be summarized by the language model.
     *
     * @param string $joined
     * @param string $intro
     * @param string $filename
     * @return string
     */
    private static function build_summary_source_text(string $joined, string $intro = '', string $filename = ''): string {
        $base = trim($joined);
        if ($base === '') {
            $base = trim($intro);
        }
        if ($base === '') {
            return self::build_filename_summary_hint($filename);
        }

        $clean = preg_replace('/\[[^\]]+\]/u', ' ', $base);
        $clean = preg_replace('/Archivo:\s+.+/u', ' ', $clean);
        $clean = preg_replace('/Contenido PDF extraido:\s*/u', ' ', $clean);
        $clean = self::repair_ocr_text((string)$clean);
        $clean = preg_replace('/\s+/u', ' ', trim((string)$clean));

        if ($clean === '') {
            return self::build_filename_summary_hint($filename);
        }

        return mb_substr($clean, 0, 6000);
    }

    /**
     * Best-effort cleanup for OCR/PDF text with words split into letters/fragments.
     *
     * @param string $text
     * @return string
     */
    private static function repair_ocr_text(string $text): string {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if ($text === '') {
            return '';
        }

        $stopwords = [
            'a','al','con','de','del','el','ella','en','es','esta','este','hay','la','las','lo','los',
            'mi','o','para','por','que','se','sin','su','sus','un','una','uno','y'
        ];

        // Join long runs of one-letter tokens: M A T E M A T I C A S -> MATEMATICAS.
        $text = preg_replace_callback('/(?:\b\pL\b(?:\s+\b\pL\b){2,})/u', function($m) {
            return str_replace(' ', '', $m[0]);
        }, $text);

        // Join split suffixes and prefixes created by OCR spacing.
        for ($i = 0; $i < 4; $i++) {
            $text = preg_replace_callback('/\b(\pL{2,4})\s+(\pL{3,})\b/u', function($m) use ($stopwords) {
                $left = mb_strtolower($m[1], 'UTF-8');
                if (in_array($left, $stopwords, true)) {
                    return $m[0];
                }
                return $m[1] . $m[2];
            }, $text);

            $text = preg_replace_callback('/\b(\pL{3,})\s+(\pL{1,4})\b/u', function($m) use ($stopwords) {
                $right = mb_strtolower($m[2], 'UTF-8');
                if (in_array($right, $stopwords, true)) {
                    return $m[0];
                }
                return $m[1] . $m[2];
            }, $text);

            $text = preg_replace_callback('/\b(\pL{2,4})\s+(\pL{2,4})\s+(\pL{1,4})\b/u', function($m) use ($stopwords) {
                $a = mb_strtolower($m[1], 'UTF-8');
                $b = mb_strtolower($m[2], 'UTF-8');
                $c = mb_strtolower($m[3], 'UTF-8');
                if (in_array($a, $stopwords, true) || in_array($b, $stopwords, true) || in_array($c, $stopwords, true)) {
                    return $m[0];
                }
                return $m[1] . $m[2] . $m[3];
            }, $text);
        }

        // Avoid merging obvious label/value separators.
        $text = preg_replace('/\b(Resumen|Descripcion|Descripción|Contenido|Archivo|Tipo|Seccion|Sección|Recurso)(?=\S)/u', '$1 ', $text);

        // Normalize punctuation spacing after repairs.
        $text = preg_replace('/\s+([,.;:!?])/u', '$1', $text);
        $text = preg_replace('/([,.;:!?])(\pL)/u', '$1 $2', $text);
        $text = preg_replace('/\s+/u', ' ', $text);

        return trim((string)$text);
    }

    /**
     * Retrieve RAG context and return diagnostics for UI/debug.
     *
     * @param int    $courseid
     * @param string $query
     * @return array ['context' => string, 'diagnostics' => array]
     */
    public static function get_context_and_diagnostics_for_query(int $courseid, string $query): array {
        global $DB;

        $diagnostics = [
            'rag_enabled' => (bool)get_config('block_pulso', 'rag_enabled'),
            'rag_table_exists' => false,
            'initially_indexed' => false,
            'index_queued' => false,
            'chunks_found' => 0,
            'chunk_sources' => [],
            'direct_structure_context' => false,
            'status' => 'unknown',
            'message' => ''
        ];

        $directStructureContext = self::build_direct_course_structure_context($courseid, $query);
        if ($directStructureContext !== '') {
            $diagnostics['direct_structure_context'] = true;
        }

        // Guard: RAG must be explicitly enabled in admin settings.
        if (!$diagnostics['rag_enabled']) {
            $diagnostics['status'] = 'disabled';
            $diagnostics['message'] = 'RAG desactivado en la configuración del plugin.';
            return ['context' => '', 'diagnostics' => $diagnostics];
        }

        // Guard: make sure DB upgrade created the RAG table.
        $diagnostics['rag_table_exists'] = self::rag_table_exists();
        if (!$diagnostics['rag_table_exists']) {
            $diagnostics['status'] = 'not_installed';
            $diagnostics['message'] = 'Falta tabla RAG en BD. Ejecuta la actualización del plugin (Administración del sitio > Notificaciones).';
            return ['context' => '', 'diagnostics' => $diagnostics];
        }

        try {
            $diagnostics['initially_indexed'] = self::is_indexed($courseid);

            // Si el curso no está indexado, encolar la indexación en BACKGROUND.
            // Nunca en línea: extraer los PDFs + generar embeddings tarda minutos
            // y cuesta dinero, y antes ocurría en la propia petición del chat.
            if (!$diagnostics['initially_indexed']) {
                $diagnostics['index_queued'] = self::request_background_index($courseid);
            } else {
                // Backward compatibility: older indexes may not contain course metadata/sections.
                // Reindex once to include course_meta and course_section chunks.
                $hasStructure = $DB->record_exists_select(
                    'block_pulso_content_chunks',
                    'courseid = :courseid AND (module_type = :meta OR module_type = :section)',
                    ['courseid' => $courseid, 'meta' => 'course_meta', 'section' => 'course_section']
                );
                if (!$hasStructure) {
                    $diagnostics['index_queued'] = self::request_background_index($courseid);
                }
            }

            $manager = new embedding_manager();
            $chunks  = $manager->find_relevant_chunks($courseid, $query, self::TOP_K);

            // Recuperación vacía: puede que el contenido haya cambiado, así que
            // se pide una reindexación — pero en background y con límite de
            // frecuencia. Reindexar aquí en línea hacía que un curso sin
            // fragmentos recuperables reindexase el curso COMPLETO en cada
            // mensaje, dos veces, indefinidamente.
            if (empty($chunks)) {
                $diagnostics['index_queued'] = self::request_background_index($courseid);
            }
        } catch (\Throwable $e) {
            // Never break the chat if RAG fails.
            error_log('Pulso RAG retrieval failed: ' . $e->getMessage());
            $diagnostics['status'] = 'error';
            $diagnostics['message'] = 'Error en recuperación RAG: ' . $e->getMessage();
            return ['context' => '', 'diagnostics' => $diagnostics];
        }

        $diagnostics['chunks_found'] = count($chunks);
        $diagnostics['chunk_sources'] = array_values(array_unique(array_map(function($chunk) {
            $modtype = $chunk->module_type ?? 'unknown';
            $modname = $chunk->module_name ?? 'unknown';
            return $modtype . ': ' . $modname;
        }, $chunks)));

        if (empty($chunks)) {
            if ($directStructureContext !== '') {
                $diagnostics['status'] = 'ok';
                $diagnostics['message'] = 'Contexto estructural directo del curso recuperado.';
                return [
                    'context' => $directStructureContext,
                    'diagnostics' => $diagnostics
                ];
            }
            $diagnostics['status'] = 'no_context';
            $diagnostics['message'] = $diagnostics['index_queued']
                ? 'RAG activo. El contenido del curso se está indexando en segundo plano (tarea del cron); '
                    . 'vuelve a preguntar en unos minutos.'
                : 'RAG activo, pero no se encontraron fragmentos relevantes. Verifica indexación/contenido del recurso.';
            return ['context' => '', 'diagnostics' => $diagnostics];
        }

        $lines = [];
        $lines[] = "\n\n## CONTENIDO RELEVANTE DEL CURSO (RAG)";
        $lines[] = "Los siguientes fragmentos han sido extraídos del contenido del curso (material didáctico + estructura del curso) y son relevantes para la consulta actual. Úsalos para responder preguntas sobre nombre del curso, número de secciones, actividades por sección, temario, enunciados, explicaciones y ejercicios.\n";

        if ($directStructureContext !== '') {
            $lines[] = $directStructureContext;
            $lines[] = '';
        }
        $lines[] = "Importante: algunos PDFs pueden extraerse con texto fragmentado/OCR parcial. Responde SOLO con el texto literal disponible y marca claramente cuando falte parte del enunciado.\n";

        // Índice dinámico de problemas detectados para consultas de contenido.
        $problemCatalog = self::build_problem_catalog_context($courseid, $query);
        if (!empty($problemCatalog)) {
            $lines[] = "## ÍNDICE DE PROBLEMAS DETECTADOS";
            $lines[] = $problemCatalog;
            $lines[] = "";
            $diagnostics['problem_catalog_context'] = true;
        } else {
            $diagnostics['problem_catalog_context'] = false;
        }

        foreach ($chunks as $i => $chunk) {
            $text = mb_substr($chunk->chunk_text, 0, self::MAX_CHUNK_CHARS);
            if (mb_strlen($chunk->chunk_text) > self::MAX_CHUNK_CHARS) {
                $text .= '…';
            }
            $lines[] = "### Fragmento " . ($i + 1) . " [{$chunk->module_type}: {$chunk->module_name}]";
            $lines[] = $text;
            $lines[] = '';
        }

        $lines[] = "---\n";
        $diagnostics['status'] = 'ok';
        $diagnostics['message'] = 'RAG recuperó ' . count($chunks) . ' fragmentos relevantes.';

        return [
            'context' => implode("\n", $lines),
            'diagnostics' => $diagnostics
        ];
    }

    /**
     * Build exact course-structure context for queries about course title,
     * sections, and activities inside a section.
     *
     * @param int $courseid
     * @param string $query
     * @return string
     */
    private static function build_direct_course_structure_context(int $courseid, string $query): string {
        global $DB;

        $q = mb_strtolower(trim($query), 'UTF-8');
        $isStructureQuery = (bool)preg_match('/curso|secci[oó]n|secciones|apartado|tema|contenido|contenidos|actividad|actividades/u', $q);
        if (!$isStructureQuery) {
            return '';
        }

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, summary, format');
        if (!$course) {
            return '';
        }

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC', 'id, section, name, summary, visible');
        if ($sections === false) {
            return '';
        }

        $lines = [];
        $lines[] = '## ESTRUCTURA DIRECTA DEL CURSO';
        $lines[] = 'Curso: ' . trim((string)$course->fullname);
        $lines[] = 'Nombre corto: ' . trim((string)$course->shortname);
        $coursesummary = trim(strip_tags((string)$course->summary));
        if ($coursesummary !== '') {
            $lines[] = 'Resumen del curso: ' . preg_replace('/\s+/u', ' ', $coursesummary);
        }

        $realSections = [];
        foreach ($sections as $section) {
            if ((int)$section->section === 0) {
                continue;
            }
            $realSections[] = $section;
        }
        $lines[] = 'Total de secciones: ' . count($realSections);

        $matched = self::find_matching_section_for_query($realSections, $q);
        $modinfo = null;
        if (function_exists('\get_fast_modinfo')) {
            try {
                $modinfo = \get_fast_modinfo($courseid);
            } catch (\Throwable $e) {
                $modinfo = null;
            }
        }

        if ($matched) {
            $title = trim((string)$matched->name);
            if ($title === '') {
                $title = 'Seccion ' . (int)$matched->section;
            }

            $lines[] = 'Seccion consultada: ' . (int)$matched->section . ' - ' . $title;

            $summary = trim(strip_tags((string)$matched->summary));
            if ($summary !== '') {
                $lines[] = 'Resumen de la seccion: ' . preg_replace('/\s+/u', ' ', $summary);
            }

            $activities = self::list_section_activities($courseid, $matched->id, (int)$matched->section, $modinfo);
            $lines[] = 'Numero de actividades en esta seccion: ' . count($activities);
            if (!empty($activities)) {
                $lines[] = 'Contenidos/actividades dentro de la seccion:';
                foreach ($activities as $activity) {
                    $lines[] = '- ' . $activity;
                }
            } else {
                $lines[] = 'No se detectaron actividades visibles en esta seccion.';
            }
        } else {
            $lines[] = 'Secciones detectadas en el curso:';
            foreach (array_slice($realSections, 0, 15) as $section) {
                $title = trim((string)$section->name);
                if ($title === '') {
                    $title = 'Seccion ' . (int)$section->section;
                }
                $lines[] = '- Seccion ' . (int)$section->section . ': ' . $title;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array $sections
     * @param string $query
     * @return object|null
     */
    private static function find_matching_section_for_query(array $sections, string $query) {
        // Ignorar referencias genéricas al curso ("este curso", "del curso",
        // "the course") al comparar nombres: una sección llamada "Curso" no
        // debe capturar preguntas de nivel de curso.
        $query = preg_replace('/\b(este|ese|el|del|al|de\s+este|de\s+ese|un)\s+curso\b/u', ' ', $query);
        $query = preg_replace('/\b(this|the)\s+course\b/u', ' ', $query);

        if (preg_match('/secci[oó]n\s+(\d{1,2})/u', $query, $m)) {
            $target = (int)$m[1];
            foreach ($sections as $section) {
                if ((int)$section->section === $target) {
                    return $section;
                }
            }
        }

        // Fase 2: el nombre completo de la sección aparece literalmente en la
        // pregunta. Se elige el nombre MÁS LARGO de los que coinciden, no el
        // primero de la lista: con nombres que se solapan ("Nominas" y "Nominas y
        // seguros sociales", "Evaluacion" y "Evaluacion final") devolver el primero
        // hacía ganar siempre al más corto, aunque la pregunta citara el largo.
        $exactMatch = null;
        $exactLength = 0;
        foreach ($sections as $section) {
            $name = mb_strtolower(trim((string)$section->name), 'UTF-8');
            if ($name === '') {
                continue;
            }
            $length = mb_strlen($name, 'UTF-8');
            if ($length <= $exactLength) {
                continue;
            }
            if (preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/ui', $query)) {
                $exactMatch = $section;
                $exactLength = $length;
            }
        }
        if ($exactMatch !== null) {
            return $exactMatch;
        }

        // Fase 2b: "tema 2", "unidad 3", "bloque 1"... como referencia al NÚMERO de
        // sección. Va después del match por nombre a propósito: si existe una
        // sección llamada literalmente "Tema 2", gana ella (la fase 2 ya la habría
        // devuelto). Sin esto, "¿qué hay en el tema 2?" no encontraba sección y
        // acababa enganchando un recurso llamado "MIC Tema 2".
        if (preg_match('/\b(tema|unidad|bloque|m[oó]dulo|modulo|apartado)\s+(\d{1,2})\b/u', $query, $mnum)) {
            $target = (int)$mnum[2];
            foreach ($sections as $section) {
                if ((int)$section->section === $target) {
                    return $section;
                }
            }
        }

        // Fase 3: match difuso por tokens del nombre. MISMO criterio que
        // match_activity_by_name_fuzzy() — este era el bug #2 del backlog todavía
        // vivo para secciones: bastaba UNA palabra de ≥4 letras del nombre de la
        // sección en cualquier parte de la pregunta para devolverla, así que
        // "cuántos alumnos han hecho la investigación" enganchaba la sección
        // "Introducción a la investigación". Ahora hay que cubrir el nombre entero
        // o acertar ≥2 palabras significativas, y se elige la MEJOR candidata en
        // vez de la primera de la lista.
        $stopwords = [
            'curso', 'tema', 'unidad', 'modulo', 'módulo', 'bloque', 'seccion', 'sección',
            'parte', 'apartado', 'introduccion', 'introducción', 'general', 'basico',
            'básico', 'contenido', 'contenidos', 'actividad', 'actividades', 'material',
            'materiales', 'alumno', 'alumnos', 'estudiante', 'estudiantes',
        ];

        preg_match_all('/\d+/u', $query, $qm);
        $queryNumbers = $qm[0];

        $bestSection = null;
        $bestScore = 0;
        foreach ($sections as $section) {
            $name = mb_strtolower(trim((string)$section->name), 'UTF-8');
            if ($name === '') {
                continue;
            }

            $tokens = preg_split('/\s+/u', $name);
            $significantTokens = 0;
            $hits = 0;      // palabras significativas del nombre presentes en la pregunta
            $anyHits = 0;   // idem contando también las genéricas ("tema", "unidad"...)
            $nameNumbers = [];
            foreach ($tokens as $token) {
                if (preg_match('/^\d+$/u', $token)) {
                    $nameNumbers[] = $token;
                    continue;
                }
                if (mb_strlen($token, 'UTF-8') < 4) {
                    continue;
                }
                $present = strpos($query, $token) !== false;
                if ($present) {
                    $anyHits++;
                }
                if (in_array($token, $stopwords, true)) {
                    continue;
                }
                $significantTokens++;
                if ($present) {
                    $hits++;
                }
            }

            // Un número en el nombre discrimina fuerte: si la pregunta cita otro
            // número distinto, esta sección queda descartada ("Tema 3" vs "tema 5").
            $numberMatch = !empty($nameNumbers) && !empty($queryNumbers)
                && !empty(array_intersect($nameNumbers, $queryNumbers));
            if (!empty($nameNumbers) && !empty($queryNumbers) && !$numberMatch) {
                continue;
            }

            if ($numberMatch) {
                // El número coincide, pero exigimos además alguna palabra compartida
                // del nombre (aunque sea genérica, tipo "tema") para no enganchar por
                // un número que la pregunta menciona por otro motivo — "cuántos de
                // los 3 alumnos aprobaron" no debe apuntar a "Tema 3".
                if ($anyHits === 0) {
                    continue;
                }
                $score = 3 + $hits;
            } else {
                // Sin número: hacen falta 2 palabras significativas SIEMPRE. Los
                // nombres de una sola palabra ya los resuelve el match exacto de la
                // fase 2 (nombre completo con límites de palabra); aceptarlos aquí
                // por una coincidencia de substring es justo el bug #2.
                if ($significantTokens === 0 || $hits < 2) {
                    continue;
                }
                $score = $hits + ($hits >= $significantTokens ? 1 : 0);
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestSection = $section;
            }
        }

        return $bestSection;
    }

    /**
     * @param int $courseid
     * @param int $sectionid
     * @param int $sectionnum
     * @param mixed $modinfo
     * @return array
     */
    private static function list_section_activities(int $courseid, int $sectionid, int $sectionnum, $modinfo = null): array {
        global $DB;

        $activities = [];

        if ($modinfo && isset($modinfo->sections[$sectionnum])) {
            foreach ($modinfo->sections[$sectionnum] as $cmid) {
                if (!isset($modinfo->cms[$cmid])) {
                    continue;
                }
                $cm = $modinfo->cms[$cmid];
                $name = trim((string)$cm->name);
                if ($name === '') {
                    $name = 'actividad sin nombre';
                }
                $activities[] = '[' . $cm->modname . '] ' . $name;
            }
            return $activities;
        }

        $cms = $DB->get_records_sql(
            "SELECT cm.id, m.name AS modname
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.section = :sectionid
                AND cm.deletioninprogress = 0
           ORDER BY cm.id ASC",
            ['courseid' => $courseid, 'sectionid' => $sectionid]
        );

        foreach ($cms as $cm) {
            $activities[] = '[' . $cm->modname . '] cmid=' . $cm->id;
        }

        return $activities;
    }

    /**
     * Build a dynamic catalog of detected problems from resource chunks.
     *
     * This is heuristic, but avoids hardcoding specific statements and lets
     * the AI reason about "primer/segundo/tercer problema" dynamically.
     *
     * @param int $courseid
     * @param string $query
     * @return string
     */
    private static function build_problem_catalog_context(int $courseid, string $query): string {
        global $DB;

        $q = mb_strtolower($query, 'UTF-8');
        // "pdf" a secas ya NO dispara este catálogo: es la palabra más común en las
        // preguntas de contenido y hacía que cualquiera de ellas reconstruyera el
        // texto completo de todos los recursos del curso. Hace falta hablar de
        // problemas/ejercicios/enunciados de verdad.
        $is_problem_query = preg_match('/problema|problemas|ejercicio|ejercicios|enunciado/u', $q);
        if (!$is_problem_query) {
            return '';
        }

        // Join resource chunks in order to reconstruct a wider window. El tope de
        // filas evita reconstruir en memoria el texto íntegro de un curso entero.
        $rows = $DB->get_records_select(
            'block_pulso_content_chunks',
            'courseid = :courseid AND module_type = :mod',
            ['courseid' => $courseid, 'mod' => 'resource'],
            'chunk_index ASC, id ASC',
            'chunk_text',
            0,
            self::PROBLEM_CATALOG_MAX_CHUNKS
        );

        if (empty($rows)) {
            return '';
        }

        $full = '';
        foreach ($rows as $r) {
            $full .= "\n" . $r->chunk_text;
        }

        // Normalize and attempt to repair OCR-like letter spacing.
        $norm = self::normalize_problem_text($full);

        // Extract question-like sentences.
        preg_match_all('/¿[^?]{10,}\?/u', $norm, $matches);
        $questions = $matches[0] ?? [];
        if (empty($questions)) {
            preg_match_all('/[^.!?]{12,}\?/u', $norm, $matches2);
            $questions = $matches2[0] ?? [];
        }

        if (empty($questions)) {
            return '';
        }

        // Build problem groups. Heuristic: each problem usually has 1-2 questions.
        $groups = [];
        $current = [];
        foreach ($questions as $question) {
            $qline = trim((string)$question);
            $qline = self::denoise_question_line($qline);
            if (mb_strlen($qline, 'UTF-8') < 12) {
                continue;
            }

            $current[] = $qline;
            if (count($current) >= 2) {
                $groups[] = $current;
                $current = [];
            }

            if (count($groups) >= 15) {
                break;
            }
        }
        if (!empty($current)) {
            $groups[] = $current;
        }

        if (empty($groups)) {
            return '';
        }

        // If query requests specific ordinal problem, bring that one first.
        $targetIndex = self::detect_requested_problem_index($q);
        if ($targetIndex !== null && isset($groups[$targetIndex])) {
            $target = $groups[$targetIndex];
            unset($groups[$targetIndex]);
            array_unshift($groups, $target);
        }

        // Render top problems for prompt context.
        $out = [];
        $maxProblems = min(8, count($groups));
        for ($i = 0; $i < $maxProblems; $i++) {
            $out[] = 'Problema ' . ($i + 1) . ':';
            foreach ($groups[$i] as $qline) {
                $out[] = '- ' . $qline;
            }
        }

        return implode("\n", $out);
    }

    /**
     * Normalize extracted OCR text for problem segmentation.
     *
     * @param string $text
     * @return string
     */
    private static function normalize_problem_text(string $text): string {
        // Collapse whitespace.
        $norm = preg_replace('/\s+/u', ' ', $text);
        $norm = trim((string)$norm);

        // Join long runs of single-letter tokens: d e s d e -> desde
        $norm = preg_replace_callback('/(?:\b\pL\b(?:\s+\b\pL\b){2,})/u', function($m) {
            return str_replace(' ', '', $m[0]);
        }, $norm);

        // Remove common OCR numbering artifacts inserted in middle of words.
        $norm = preg_replace('/(?<=\pL)\s+\d+\.\s+(?=\pL)/u', '', $norm);

        // Final cleanup.
        $norm = preg_replace('/\s+/u', ' ', $norm);
        return trim((string)$norm);
    }

    /**
     * Denoise OCR-like broken spacing in a question sentence.
     *
     * @param string $line
     * @return string
     */
    private static function denoise_question_line(string $line): string {
        $line = preg_replace('/\s+/u', ' ', trim($line));
        $line = preg_replace('/\b\d+\.\s*/u', '', $line); // stray numbering

        // Join runs of single-letter tokens: d e s d e -> desde
        $line = preg_replace_callback('/(?:\b\pL\b(?:\s+\b\pL\b){2,})/u', function($m) {
            return str_replace(' ', '', $m[0]);
        }, $line);

        // Join split endings: recorr e -> recorre, hectóm etr os -> hectómetros (best effort)
        $line = preg_replace('/\b(\pL{3,})\s+(\pL{1,2})\b/u', '$1$2', $line);

        // Join short prefix fragments when prefix is not a stopword: ha sta -> hasta.
        $stop = ['de','la','el','los','las','y','o','u','a','en','un','una','del','al'];
        $line = preg_replace_callback('/\b(\pL{1,2})\s+(\pL{3,})\b/u', function($m) use ($stop) {
            $a = mb_strtolower($m[1], 'UTF-8');
            if (in_array($a, $stop, true)) {
                return $m[0];
            }
            return $m[1] . $m[2];
        }, $line);

        // Cleanup spacing before punctuation.
        $line = preg_replace('/\s+([,.;:?\!])/u', '$1', $line);
        $line = preg_replace('/\s+/u', ' ', $line);

        return trim((string)$line);
    }

    /**
     * Detect requested problem index from user query.
     *
     * Returns zero-based index or null.
     *
     * @param string $q Lowercased query text
     * @return int|null
     */
    private static function detect_requested_problem_index(string $q): ?int {
        if (preg_match('/primer|primero|1er|first/u', $q)) return 0;
        if (preg_match('/segundo|segunda|2do|2da|second/u', $q)) return 1;
        if (preg_match('/tercer|tercero|tercera|3er|third/u', $q)) return 2;

        if (preg_match('/\b(\d{1,2})\s*(?:o|º|a)?\s*problema\b/u', $q, $m)) {
            $n = (int)$m[1];
            if ($n > 0) {
                return $n - 1;
            }
        }

        return null;
    }

    // ----------------------------------------------------------------
    // Indexing (called by scheduled task)
    // ----------------------------------------------------------------

    /**
     * Encola la indexación del curso como tarea adhoc, para que corra en el cron
     * y NO dentro de la petición de chat del usuario.
     *
     * Limitado por frecuencia (INDEX_REQUEST_THROTTLE): sin este límite, un curso
     * cuyo contenido no produce fragmentos recuperables volvería a encolarse en
     * cada mensaje, y cada ejecución reparsea todos los PDFs y paga embeddings.
     * queue_adhoc_task() con $checkforexisting evita además duplicados mientras
     * la tarea siga pendiente.
     *
     * @param int $courseid
     * @return bool true si se ha encolado ahora, false si se omitió por el límite.
     */
    private static function request_background_index(int $courseid): bool {
        $throttlekey = 'lastindexqueue_' . $courseid;
        $last = (int)get_config('block_pulso', $throttlekey);
        $now = time();

        if ($last > 0 && ($now - $last) < self::INDEX_REQUEST_THROTTLE) {
            return false;
        }

        try {
            $task = new \block_pulso\task\index_course_adhoc();
            $task->set_component('block_pulso');
            $task->set_custom_data(['courseid' => $courseid]);
            \core\task\manager::queue_adhoc_task($task, true);
            set_config($throttlekey, $now, 'block_pulso');
            return true;
        } catch (\Throwable $e) {
            // Nunca romper el chat porque no se pueda encolar la indexación.
            error_log('Pulso RAG: could not queue adhoc index for course ' . $courseid . ': ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Extract and embed all content for a course.
     *
     * @param int $courseid
     * @return array Stats: ['indexed'=>int, 'skipped'=>int, 'deleted'=>int]
     */
    public static function index_course(int $courseid): array {
        $extractor = new content_extractor();
        $chunks    = $extractor->extract_course_content($courseid);

        $manager = new embedding_manager();
        return $manager->index_course_chunks($courseid, $chunks);
    }

    /**
     * Returns whether a course has any indexed chunks.
     *
     * @param int $courseid
     * @return bool
     */
    public static function is_indexed(int $courseid): bool {
        global $DB;
        if (!self::rag_table_exists()) {
            return false;
        }
        return $DB->record_exists('block_pulso_content_chunks', ['courseid' => $courseid]);
    }

    /**
     * Delete all indexed chunks for a course (e.g. when Pulso is disabled for it).
     *
     * @param int $courseid
     */
    public static function delete_course_index(int $courseid): void {
        global $DB;
        // Limpiar tambien el limitador de frecuencia: si el indice se borra a
        // proposito, la siguiente consulta debe poder reencolar la indexacion
        // sin esperar la ventana de INDEX_REQUEST_THROTTLE.
        unset_config('lastindexqueue_' . $courseid, 'block_pulso');
        if (!self::rag_table_exists()) {
            return;
        }
        $DB->delete_records('block_pulso_content_chunks', ['courseid' => $courseid]);
    }

    /**
     * Verify the RAG storage table exists in current DB schema.
     *
     * @return bool
     */
    private static function rag_table_exists(): bool {
        global $DB;

        // Memorizado por petición: `get_tables(false)` fuerza un listado COMPLETO del
        // esquema sin usar la caché de Moodle, y esto se llamaba varias veces en cada
        // mensaje del chat. La respuesta no puede cambiar dentro de una petición.
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        try {
            // Sin `false`: se usa la caché de esquema de Moodle, que es justo para esto.
            $tables = $DB->get_tables();
            $exists = in_array('block_pulso_content_chunks', $tables, true);
        } catch (\Throwable $e) {
            $exists = false;
        }

        return $exists;
    }
}
