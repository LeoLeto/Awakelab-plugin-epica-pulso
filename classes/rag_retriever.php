<?php
/**
 * RAG Retriever — high-level facade for Retrieval-Augmented Generation
 *
 * Combines content_extractor + embedding_manager into a single API
 * that api_chat.php uses:
 *
 *   1. rag_retriever::get_context_for_query($courseid, $query)
 *      → returns a formatted string ready to inject into the system prompt.
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

    // ----------------------------------------------------------------
    // Query-time retrieval
    // ----------------------------------------------------------------

    /**
     * Retrieve the most relevant content chunks for a user query and
     * return a formatted context block ready to append to the system prompt.
     *
     * Returns an empty string when RAG is disabled, not indexed, or the
     * embeddings API fails (so the chat still works without RAG context).
     *
     * @param int    $courseid
     * @param string $query    User's natural-language question.
     * @return string
     */
    public static function get_context_for_query(int $courseid, string $query): string {
        $result = self::get_context_and_diagnostics_for_query($courseid, $query);
        return $result['context'];
    }

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
        $isSectionQuery = self::is_explicit_section_query($q);
        $isResourceIntent = self::is_resource_query($q);
        $isCourseNameQuery = (bool)preg_match('/c[oó]mo\s+se\s+llama\s+este\s+curso|nombre\s+del\s+curso|nombre\s+de\s+este\s+curso/u', $q);
        $isSectionCountQuery = (bool)preg_match('/cu[aá]ntas?\s+secciones|n[uú]mero\s+de\s+secciones|total\s+de\s+secciones/u', $q);
        $isCourseContentQuery = self::is_course_content_query($q);

        // Siempre intentar buscar actividades por nombre antes de sección.
        // Solo omitir para consultas de nivel de curso (contenido, nombre, secciones).
        if (!$isCourseContentQuery && !$isCourseNameQuery && !$isSectionCountQuery) {
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

            $title = trim((string)$matched->name);
            if ($title === '') {
                $title = 'Seccion ' . (int)$matched->section;
            }

            $activities = self::list_section_activities($courseid, $matched->id, (int)$matched->section, $modinfo);
            $summary = trim(strip_tags((string)$matched->summary));

            $lines = [];
            $lines[] = 'Seccion: ' . $title;
            $lines[] = 'Numero de seccion: ' . (int)$matched->section;
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

        // Primero intentar emparejar un recurso (PDF/archivo).
        $matched = null;
        $matchedType = 'resource'; // resource | quiz | assign | forum | page | url | book | folder | ...
        if (!empty($resources)) {
            $matched = self::match_activity_by_name($resources, $query);
        }

        // Si no se encontró recurso, intentar quiz.
        if ($matched === null && !empty($quizzes)) {
            $matched = self::match_activity_by_name($quizzes, $query);
            if ($matched !== null) {
                $matchedType = 'quiz';
            }
        }

        // Si no se encontró quiz, intentar assign.
        if ($matched === null && !empty($assigns)) {
            $matched = self::match_activity_by_name($assigns, $query);
            if ($matched !== null) {
                $matchedType = 'assign';
            }
        }

        // Buscar en otros tipos de actividad.
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
        if ($matched === null) {
            foreach ($otherTypes as $typeName => $records) {
                if (!empty($records)) {
                    $matched = self::match_activity_by_name($records, $query);
                    if ($matched !== null) {
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

        return $result;
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
        // Exact whole-word match first.
        foreach ($records as $record) {
            $name = mb_strtolower(trim((string)$record->name), 'UTF-8');
            if ($name !== '' && preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/ui', $query)) {
                return $record;
            }
        }

        // Fuzzy: score all records by token hits and return the best match.
        // Words that appear in virtually every Moodle activity name or teacher query
        // are useless as discriminators and must not contribute to the score.
        $stopwords = ['curso', 'foro', 'tarea', 'tema', 'quiz', 'link', 'book', 'page'];
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
            foreach ($tokens as $token) {
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
            if ($hits > 0 && $hits > $bestScore) {
                $bestScore = $hits;
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

        return $result;
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
            $gradeStats = $DB->get_record_sql(
                "SELECT COUNT(*) AS graded, AVG(ag.grade) AS avg_grade
                   FROM {assign_grades} ag
                  WHERE ag.assignment = :assignid
                    AND ag.grade >= 0",
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
        if ($completedUsers > 0) {
            $contentLines[] = 'Estudiantes que han completado la actividad: ' . $completedUsers;
        }

        return [
            'type' => 'text',
            'title' => 'Tarea: ' . $assignName,
            'summary' => 'He localizado la tarea dentro del curso.',
            'content' => implode("\n", $contentLines)
        ];
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
                $answerCount = (int)$DB->count_records('choice_answers', ['choiceid' => $activity->id]);
                $contentLines[] = 'Opciones: ' . $optionCount;
                $contentLines[] = 'Respuestas recibidas: ' . $answerCount;
            } catch (\Throwable $e) {}
        }

        if ($intro !== '') {
            $contentLines[] = 'Descripcion: ' . preg_replace('/\s+/u', ' ', $intro);
        }
        if ($completedUsers > 0) {
            $contentLines[] = 'Estudiantes que han completado la actividad: ' . $completedUsers;
        }

        return [
            'type' => 'text',
            'title' => $typeLabel . ': ' . $activityName,
            'summary' => 'He localizado la actividad dentro del curso.',
            'content' => implode("\n", $contentLines)
        ];
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
        return (bool)preg_match('/enunciado|primer\s+problem[ao]|\bproblema\s+\d+|primer\s+ejercicio|ejercicio\s*\d+|mu[eé]strame\s+(el|la|los|las|un)\b|soluci[oó]n\s+del\b|dame\s+(un|el|la|los)\s+\w+|qu[eé]\s+preguntas?|cu[aá]ntas?\s+preguntas?|pregunta\s+\d+|\bpregunta\s+del|cu[aá]ntos?\s+(alumnos|estudiantes)|qui[eé]n(es)?\s+(ha|han)\s+(completado|hecho|realizado)|nota\s+media|calificaci[oó]n\s+(media|promedio)|cu[aá]ntos?\s+intentos/u', $query);
    }

    /**
     * @param string $query
     * @return bool
     */
    private static function is_explicit_section_query(string $query): bool {
        return (bool)preg_match('/secci[oó]n|secciones|apartado|tema\s+\d+|contenido\s+de\s+la\s+secci[oó]n|actividades\s+de\s+la\s+secci[oó]n/u', $query);
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
            'on_demand_index_ran' => false,
            'on_demand_index_stats' => null,
            'forced_reindex_ran' => false,
            'forced_reindex_stats' => null,
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

            // If this course was never indexed, perform on-demand indexing.
            if (!$diagnostics['initially_indexed']) {
                $diagnostics['on_demand_index_ran'] = true;
                $diagnostics['on_demand_index_stats'] = self::index_course($courseid);
            } else {
                // Backward compatibility: older indexes may not contain course metadata/sections.
                // Reindex once to include course_meta and course_section chunks.
                $hasStructure = $DB->record_exists_select(
                    'block_pulso_content_chunks',
                    'courseid = :courseid AND (module_type = :meta OR module_type = :section)',
                    ['courseid' => $courseid, 'meta' => 'course_meta', 'section' => 'course_section']
                );
                if (!$hasStructure) {
                    $diagnostics['on_demand_index_ran'] = true;
                    $diagnostics['on_demand_index_stats'] = self::index_course($courseid);
                }
            }

            $manager = new embedding_manager();
            $chunks  = $manager->find_relevant_chunks($courseid, $query, self::TOP_K);

            // If retrieval is empty, try one forced re-index in case content changed.
            if (empty($chunks)) {
                $diagnostics['forced_reindex_ran'] = true;
                $diagnostics['forced_reindex_stats'] = self::index_course($courseid);
                $chunks = $manager->find_relevant_chunks($courseid, $query, self::TOP_K);
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
            $diagnostics['message'] = 'RAG activo, pero no se encontraron fragmentos relevantes. Verifica indexación/contenido del recurso.';
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
        if (preg_match('/secci[oó]n\s+(\d{1,2})/u', $query, $m)) {
            $target = (int)$m[1];
            foreach ($sections as $section) {
                if ((int)$section->section === $target) {
                    return $section;
                }
            }
        }

        foreach ($sections as $section) {
            $name = mb_strtolower(trim((string)$section->name), 'UTF-8');
            if ($name === '') {
                continue;
            }
            if (preg_match('/(?<![\pL\pN])' . preg_quote($name, '/') . '(?![\pL\pN])/ui', $query)) {
                return $section;
            }
        }

        foreach ($sections as $section) {
            $name = mb_strtolower(trim((string)$section->name), 'UTF-8');
            if ($name === '') {
                continue;
            }
            $tokens = preg_split('/\s+/u', $name);
            $matched = 0;
            foreach ($tokens as $token) {
                if (mb_strlen($token, 'UTF-8') < 4) {
                    continue;
                }
                if (strpos($query, $token) !== false) {
                    $matched++;
                }
            }
            if ($matched > 0) {
                return $section;
            }
        }

        return null;
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
        $is_problem_query = preg_match('/problema|problemas|ejercicio|ejercicios|enunciado|pdf/u', $q);
        if (!$is_problem_query) {
            return '';
        }

        // Join resource chunks in order to reconstruct a wider window.
        $rows = $DB->get_records_select(
            'block_pulso_content_chunks',
            'courseid = :courseid AND module_type = :mod',
            ['courseid' => $courseid, 'mod' => 'resource'],
            'chunk_index ASC, id ASC',
            'chunk_text'
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
        try {
            $tables = $DB->get_tables(false);
            return in_array('block_pulso_content_chunks', $tables, true);
        } catch (\Throwable $e) {
            return false;
        }
    }
}
