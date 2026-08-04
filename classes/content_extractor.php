<?php
/**
 * Content Extractor — RAG pipeline step 1
 *
 * Extracts clean text from Moodle course modules so it can be chunked
 * and embedded for Retrieval-Augmented Generation.
 *
 * Supported module types:
 *   page, assign, quiz (questions), label, book (chapters), wiki (pages), resource (incl. PDF)
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

class content_extractor {

    /** Maximum characters per chunk (≈500 tokens for text-embedding-3-small). */
    const CHUNK_SIZE = 2000;

    /** Overlap between consecutive chunks in characters. */
    const CHUNK_OVERLAP = 200;

    /** Max SCORM package files processed (rendimiento en paquetes muy grandes). */
    const SCORM_MAX_FILES = 60;

    /** Max characters extraidos de un SCORM antes de parar. */
    const SCORM_MAX_CHARS = 80000;

    /**
     * Espacio reservado por curso para los cmid SINTETICOS de los chunks que no
     * pertenecen a ningun modulo real (metadatos del curso y secciones). Los cmid
     * reales son positivos, asi que estos van en negativo:
     *
     *   metadatos del curso C  ->  -(C * SYNTHETIC_CMID_STRIDE)
     *   seccion S del curso C  ->  -(C * SYNTHETIC_CMID_STRIDE + S + 1)
     *
     * Con S+1 acotado a [1, STRIDE-1] ningun par (curso, seccion) puede colisionar
     * con los metadatos de otro curso. El esquema anterior usaba -C para los
     * metadatos, que chocaba con la seccion 4 del curso C/1000 (p. ej. el curso
     * 1005 contra la seccion 4 del curso 1).
     */
    const SYNTHETIC_CMID_STRIDE = 1000;

    /**
     * Extract all content from a course and return as an array of chunks.
     *
     * Each chunk is an associative array:
     *   cmid, module_type, module_name, chunk_index, chunk_text, token_count
     *
     * @param int $courseid
     * @return array
     */
    public function extract_course_content(int $courseid): array {
        global $DB;

        $chunks = [];

        // Include course-level metadata and section structure so RAG can answer
        // questions beyond PDFs (course name, section count, activity distribution).
        $chunks = array_merge($chunks, $this->extract_course_structure($courseid));

        // Get all course modules for the course.
        $cms = $DB->get_records_sql(
            "SELECT cm.id AS cmid, m.name AS modname, cm.instance
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid
                AND cm.deletioninprogress = 0",
            ['courseid' => $courseid]
        );

        foreach ($cms as $cm) {
            try {
                $extracted = $this->extract_module($cm->cmid, $cm->modname, $cm->instance);
                if (!empty($extracted)) {
                    $chunks = array_merge($chunks, $extracted);
                }
            } catch (\Throwable $e) {
                // Never abort full indexing because one module type/table fails.
                // Example: optional module tables not installed on this Moodle site.
                error_log('Pulso RAG extractor skipped module cmid=' . $cm->cmid .
                    ' mod=' . $cm->modname . ' due to error: ' . $e->getMessage());
            }
        }

        return $chunks;
    }

    /**
     * Extract course metadata and section/activity structure as additional chunks.
     *
     * @param int $courseid
     * @return array
     */
    private function extract_course_structure(int $courseid): array {
        global $DB;

        $chunks = [];

        $course = $DB->get_record('course', ['id' => $courseid], 'id, fullname, shortname, summary, format');
        if (!$course) {
            return [];
        }

        $sections = $DB->get_records('course_sections', ['course' => $courseid], 'section ASC',
            'id, section, name, summary, visible');

        $sectioncount = 0;
        $visiblecount = 0;
        foreach ($sections as $sec) {
            if ((int)$sec->section === 0) {
                continue;
            }
            $sectioncount++;
            if ((int)$sec->visible === 1) {
                $visiblecount++;
            }
        }

        $overview = [];
        $overview[] = 'Curso: ' . trim((string)$course->fullname);
        $overview[] = 'Nombre corto: ' . trim((string)$course->shortname);
        $overview[] = 'Formato del curso: ' . trim((string)$course->format);
        $overview[] = 'Total de secciones: ' . $sectioncount;
        $overview[] = 'Secciones visibles: ' . $visiblecount;
        if (!empty($course->summary)) {
            $overview[] = 'Resumen del curso:';
            $overview[] = $this->html_to_text((string)$course->summary);
        }

        $overviewtext = trim(implode("\n", array_filter($overview)));
        if ($overviewtext !== '') {
            $chunks = array_merge($chunks, $this->chunk_text(
                $overviewtext,
                $this->course_meta_cmid($courseid),
                'course_meta',
                mb_substr((string)$course->fullname, 0, 255)
            ));
        }

        // Build per-section content with section names/summaries and activity names.
        $modinfo = null;
        if (function_exists('\get_fast_modinfo')) {
            try {
                $modinfo = \get_fast_modinfo($courseid);
            } catch (\Throwable $e) {
                $modinfo = null;
            }
        }

        foreach ($sections as $sec) {
            if ((int)$sec->section === 0) {
                continue;
            }

            $title = trim((string)$sec->name);
            if ($title === '') {
                $title = 'Seccion ' . (int)$sec->section;
            }

            $lines = [];
            $lines[] = 'Seccion ' . (int)$sec->section . ': ' . $title;

            $summary = trim($this->html_to_text((string)$sec->summary));
            if ($summary !== '') {
                $lines[] = 'Descripcion de la seccion:';
                $lines[] = $summary;
            }

            $activities = [];
            if ($modinfo && isset($modinfo->sections[(int)$sec->section])) {
                $cmids = $modinfo->sections[(int)$sec->section];
                foreach ($cmids as $cmid) {
                    if (!isset($modinfo->cms[$cmid])) {
                        continue;
                    }
                    $cm = $modinfo->cms[$cmid];
                    $actname = trim((string)$cm->name);
                    if ($actname === '') {
                        $actname = 'actividad sin nombre';
                    }
                    $activities[] = '- [' . $cm->modname . '] ' . $actname;
                }
            } else {
                $cms = $DB->get_records_sql(
                    "SELECT cm.id, m.name AS modname
                       FROM {course_modules} cm
                       JOIN {modules} m ON m.id = cm.module
                      WHERE cm.course = :courseid
                        AND cm.section = :sectionid
                        AND cm.deletioninprogress = 0
                   ORDER BY cm.id ASC",
                    ['courseid' => $courseid, 'sectionid' => $sec->id]
                );
                foreach ($cms as $cm) {
                    $activities[] = '- [' . $cm->modname . '] cmid=' . $cm->id;
                }
            }

            $lines[] = 'Actividades en esta seccion: ' . count($activities);
            if (!empty($activities)) {
                $lines[] = implode("\n", $activities);
            }

            $sectiontext = trim(implode("\n", array_filter($lines)));
            if ($sectiontext === '') {
                continue;
            }

            $chunks = array_merge($chunks, $this->chunk_text(
                $sectiontext,
                $this->course_section_cmid($courseid, (int)$sec->section),
                'course_section',
                mb_substr($title, 0, 255)
            ));
        }

        return $chunks;
    }

    /**
     * cmid sintetico para el chunk de metadatos de un curso.
     *
     * @param int $courseid
     * @return int negativo, nunca colisiona con un cmid real
     */
    private function course_meta_cmid(int $courseid): int {
        return -1 * ($courseid * self::SYNTHETIC_CMID_STRIDE);
    }

    /**
     * cmid sintetico para el chunk de una seccion. El offset dentro del curso se
     * acota a [1, STRIDE-1] para que no pueda desbordar al espacio del curso
     * siguiente ni chocar con sus metadatos.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @return int negativo, nunca colisiona con un cmid real
     */
    private function course_section_cmid(int $courseid, int $sectionnum): int {
        $offset = min(max($sectionnum, 0), self::SYNTHETIC_CMID_STRIDE - 2) + 1;
        return -1 * (($courseid * self::SYNTHETIC_CMID_STRIDE) + $offset);
    }

    /**
     * Extract and chunk content from a single course module.
     *
     * @param int    $cmid       course_modules.id
     * @param string $modname    Module type name (page, assign, quiz, …)
     * @param int    $instance   Module instance id (row id in the module table)
     * @return array
     */
    public function extract_module(int $cmid, string $modname, int $instance): array {
        switch ($modname) {
            case 'page':
                return $this->extract_page($cmid, $instance);
            case 'assign':
                return $this->extract_assign($cmid, $instance);
            case 'quiz':
                return $this->extract_quiz($cmid, $instance);
            case 'label':
                return $this->extract_label($cmid, $instance);
            case 'book':
                return $this->extract_book($cmid, $instance);
            case 'wiki':
                return $this->extract_wiki($cmid, $instance);
            case 'resource':
                return $this->extract_resource($cmid, $instance);
            case 'scorm':
                return $this->extract_scorm($cmid, $instance);
            default:
                return [];
        }
    }

    // ----------------------------------------------------------------
    // Per-module extractors
    // ----------------------------------------------------------------

    private function extract_page(int $cmid, int $instance): array {
        global $DB;
        $record = $DB->get_record('page', ['id' => $instance], 'name, content');
        if (!$record) {
            return [];
        }
        $text = $record->name . "\n\n" . $this->html_to_text($record->content);
        return $this->chunk_text($text, $cmid, 'page', $record->name);
    }

    private function extract_assign(int $cmid, int $instance): array {
        global $DB;
        $record = $DB->get_record('assign', ['id' => $instance], 'name, intro');
        if (!$record) {
            return [];
        }
        $text = $record->name . "\n\n" . $this->html_to_text($record->intro);
        return $this->chunk_text($text, $cmid, 'assign', $record->name);
    }

    private function extract_quiz(int $cmid, int $instance): array {
        global $DB;

        $quiz = $DB->get_record('quiz', ['id' => $instance], 'name, intro');
        if (!$quiz) {
            return [];
        }

        // Collect question text from all slots.
        $questions = $DB->get_records_sql(
            "SELECT q.questiontext, q.generalfeedback, q.name AS qname
               FROM {quiz_slots} qs
               JOIN {question_references} qr
                 ON qr.component = 'mod_quiz'
                AND qr.questionarea = 'slot'
                AND qr.itemid = qs.id
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE qs.quizid = :quizid",
            ['quizid' => $instance]
        );

        $full_text = $quiz->name . "\n\n" . $this->html_to_text($quiz->intro) . "\n\n";
        foreach ($questions as $q) {
            $full_text .= "Pregunta: " . $this->html_to_text($q->questiontext) . "\n";
            if (!empty($q->generalfeedback)) {
                $full_text .= "Feedback: " . $this->html_to_text($q->generalfeedback) . "\n";
            }
            $full_text .= "\n";
        }

        return $this->chunk_text(trim($full_text), $cmid, 'quiz', $quiz->name);
    }

    private function extract_label(int $cmid, int $instance): array {
        global $DB;
        $record = $DB->get_record('label', ['id' => $instance], 'name, intro');
        if (!$record) {
            return [];
        }
        $text = $this->html_to_text($record->intro);
        if (strlen(trim($text)) < 20) {
            return []; // Skip trivially short labels.
        }
        return $this->chunk_text($text, $cmid, 'label', $record->name ?: 'Label');
    }

    private function extract_book(int $cmid, int $instance): array {
        global $DB;
        $book = $DB->get_record('book', ['id' => $instance], 'name');
        if (!$book) {
            return [];
        }
        $chapters = $DB->get_records('book_chapters', ['bookid' => $instance], 'pagenum ASC', 'title, content');
        $full_text = $book->name . "\n\n";
        foreach ($chapters as $ch) {
            $full_text .= "## " . $ch->title . "\n" . $this->html_to_text($ch->content) . "\n\n";
        }
        return $this->chunk_text(trim($full_text), $cmid, 'book', $book->name);
    }

    private function extract_wiki(int $cmid, int $instance): array {
        global $DB;
        $wiki = $DB->get_record('wiki', ['id' => $instance], 'name');
        if (!$wiki) {
            return [];
        }
        $subwikis = $DB->get_records('wiki_subwikis', ['wikiid' => $instance], '', 'id');
        $full_text = $wiki->name . "\n\n";
        foreach ($subwikis as $sw) {
            $pages = $DB->get_records('wiki_pages', ['subwikiid' => $sw->id], 'title ASC', 'title, cachedcontent');
            foreach ($pages as $page) {
                $full_text .= "## " . $page->title . "\n" . $this->html_to_text($page->cachedcontent) . "\n\n";
            }
        }
        return $this->chunk_text(trim($full_text), $cmid, 'wiki', $wiki->name);
    }

    private function extract_resource(int $cmid, int $instance): array {
        global $DB;

        $resource = $DB->get_record('resource', ['id' => $instance], 'name, intro');
        if (!$resource) {
            return [];
        }

        $parts = [];
        $parts[] = $resource->name;
        if (!empty($resource->intro)) {
            $parts[] = $this->html_to_text($resource->intro);
        }

        try {
            $context = \context_module::instance($cmid);
            $fs = get_file_storage();
            $files = $fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'sortorder, id', false);

            foreach ($files as $file) {
                $filename = $file->get_filename();
                $mimetype = $file->get_mimetype();
                $parts[] = 'Archivo: ' . $filename . ' (' . $mimetype . ')';

                // Text-like files can be read directly.
                if (strpos($mimetype, 'text/') === 0) {
                    $plain = trim((string)$file->get_content());
                    if (!empty($plain)) {
                        $parts[] = $plain;
                    }
                    continue;
                }

                // Jupyter Notebooks (.ipynb) — extract markdown and code cells.
                if ($mimetype === 'application/json' || preg_match('/\.ipynb$/i', $filename)) {
                    $raw = trim((string)$file->get_content());
                    if (!empty($raw)) {
                        $notebook = @json_decode($raw, true);
                        if (is_array($notebook) && !empty($notebook['cells'])) {
                            $cellParts = [];
                            foreach ($notebook['cells'] as $cell) {
                                $cellType = $cell['cell_type'] ?? '';
                                $source = $cell['source'] ?? [];
                                if (is_array($source)) {
                                    $source = implode('', $source);
                                }
                                $source = trim((string)$source);
                                if ($source === '') {
                                    continue;
                                }
                                if ($cellType === 'markdown') {
                                    $cellParts[] = $source;
                                } else if ($cellType === 'code') {
                                    $cellParts[] = '```' . "\n" . $source . "\n" . '```';
                                }
                            }
                            if (!empty($cellParts)) {
                                $parts[] = implode("\n\n", $cellParts);
                            }
                        }
                    }
                    continue;
                }

                // Documentos Office (Word/PowerPoint) — ZIP con XML dentro.
                $isDocx = $mimetype === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                    || preg_match('/\.docx$/i', $filename);
                $isPptx = $mimetype === 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
                    || preg_match('/\.pptx$/i', $filename);
                if ($isDocx || $isPptx) {
                    $officetext = $isDocx ? $this->extract_docx_text($file) : $this->extract_pptx_text($file);
                    if ($this->is_extracted_text_useful($officetext)) {
                        $parts[] = 'Contenido del documento extraido:';
                        $parts[] = $officetext;
                    } else {
                        $parts[] = 'No se pudo leer el contenido de este documento.';
                    }
                    continue;
                }

                // PDFs require extraction step.
                if ($mimetype === 'application/pdf' || preg_match('/\.pdf$/i', $filename)) {
                    $pdftext = $this->extract_pdf_text($file);
                    if (!empty($pdftext)) {
                        $parts[] = 'Contenido PDF extraido:';
                        $parts[] = $pdftext;
                    } else {
                        // Ninguna estrategia de extraccion dio texto legible — mensaje
                        // claro en vez de dejar el recurso vacio o basura sin avisar.
                        $parts[] = 'No se pudo leer el texto de este PDF; puede estar escaneado o protegido.';
                    }
                }
            }
        } catch (\Throwable $e) {
            // Non-fatal: keep at least the resource title/intro.
            $parts[] = 'Nota: no se pudo extraer el archivo del recurso (' . $e->getMessage() . ').';
        }

        $full = trim(implode("\n\n", array_filter($parts)));
        if (strlen($full) < 20) {
            return [];
        }

        return $this->chunk_text($full, $cmid, 'resource', $resource->name);
    }

    private function extract_scorm(int $cmid, int $instance): array {
        global $DB;

        $scorm = $DB->get_record('scorm', ['id' => $instance], 'name');
        if (!$scorm) {
            return [];
        }

        $parts = [$scorm->name];

        try {
            $context = \context_module::instance($cmid);
            $scormtext = $this->extract_scorm_content_text($context);
            if ($scormtext !== '') {
                $parts[] = $scormtext;
            } else {
                $parts[] = 'No se pudo extraer el contenido de texto de este SCORM (puede usar un ' .
                    'formato interactivo, p. ej. Articulate/Storyline, que no expone texto en el HTML, ' .
                    'o estar alojado externamente).';
            }
        } catch (\Throwable $e) {
            $parts[] = 'Nota: no se pudo procesar el paquete SCORM (' . $e->getMessage() . ').';
        }

        $full = trim(implode("\n\n", array_filter($parts)));
        if (strlen($full) < 20) {
            return [];
        }

        return $this->chunk_text($full, $cmid, 'scorm', $scorm->name);
    }

    /**
     * Moodle descomprime el paquete SCORM al subirlo, en component 'mod_scorm',
     * filearea 'content', itemid 0 (incluido el imsmanifest.xml) — no hace falta
     * ZipArchive, los ficheros ya estan accesibles individualmente via file API.
     *
     * @param \context $context
     * @return string
     */
    private function extract_scorm_content_text(\context $context): string {
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, 'mod_scorm', 'content', 0, 'sortorder, filepath, filename', false);
        if (empty($files)) {
            return '';
        }

        // Mapa "ruta relativa" -> stored_file, para resolver los href del manifest.
        $byPath = [];
        $manifestFile = null;
        foreach ($files as $file) {
            $relpath = ltrim($file->get_filepath(), '/') . $file->get_filename();
            $byPath[$relpath] = $file;
            if (mb_strtolower($file->get_filename()) === 'imsmanifest.xml') {
                $manifestFile = $file;
            }
        }

        $orderedPaths = [];
        if ($manifestFile !== null) {
            $orderedPaths = $this->scorm_resolve_launch_order((string)$manifestFile->get_content(), $byPath);
        }
        if (empty($orderedPaths)) {
            // Fallback: todos los HTML del paquete, en orden alfabetico de ruta.
            foreach ($byPath as $relpath => $file) {
                if (preg_match('/\.(html?|xhtml)$/i', $relpath)) {
                    $orderedPaths[] = $relpath;
                }
            }
            sort($orderedPaths, SORT_STRING);
        }

        $orderedPaths = array_slice($orderedPaths, 0, self::SCORM_MAX_FILES);

        $pageTexts = [];
        $totalChars = 0;
        foreach ($orderedPaths as $relpath) {
            if ($totalChars >= self::SCORM_MAX_CHARS) {
                break;
            }
            if (!isset($byPath[$relpath])) {
                continue;
            }
            $html = (string)$byPath[$relpath]->get_content();
            $clean = $this->strip_script_and_style($html);
            $text = $this->html_to_text($clean);
            if (!$this->is_extracted_text_useful($text)) {
                continue;
            }
            $pageTexts[] = $text;
            $totalChars += strlen($text);
        }

        return trim(implode("\n\n", $pageTexts));
    }

    /**
     * Resolver el orden de lanzamiento a partir de <organizations><item identifierref="...">
     * y su correspondiente <resources><resource identifier="..." href="...">.
     *
     * @param string $manifestXml
     * @param array  $byPath ruta relativa -> stored_file, para validar que el href existe en el paquete
     * @return array lista de rutas relativas, en orden de lanzamiento
     */
    private function scorm_resolve_launch_order(string $manifestXml, array $byPath): array {
        if (trim($manifestXml) === '') {
            return [];
        }

        $manifest = @simplexml_load_string($manifestXml);
        if ($manifest === false) {
            return [];
        }

        $resourceHrefs = [];
        foreach ($manifest->resources->resource ?? [] as $resource) {
            $attrs = $resource->attributes();
            $identifier = (string)($attrs['identifier'] ?? '');
            $href = (string)($attrs['href'] ?? '');
            if ($identifier === '' || $href === '') {
                continue;
            }
            $normalized = ltrim(str_replace('\\', '/', $href), './');
            if (isset($byPath[$normalized])) {
                $resourceHrefs[$identifier] = $normalized;
            }
        }

        if (empty($resourceHrefs)) {
            return [];
        }

        $ordered = [];
        $seen = [];
        foreach ($manifest->organizations->organization ?? [] as $organization) {
            $this->scorm_collect_item_order($organization, $resourceHrefs, $ordered, $seen);
        }

        return $ordered;
    }

    /**
     * Recorre recursivamente <item> (pueden anidarse) en orden de documento,
     * acumulando las rutas de recurso referenciadas por identifierref.
     *
     * @param \SimpleXMLElement $node
     * @param array $resourceHrefs
     * @param array $ordered
     * @param array $seen
     */
    private function scorm_collect_item_order(\SimpleXMLElement $node, array $resourceHrefs, array &$ordered, array &$seen): void {
        foreach ($node->item ?? [] as $item) {
            $attrs = $item->attributes();
            $ref = (string)($attrs['identifierref'] ?? '');
            if ($ref !== '' && isset($resourceHrefs[$ref]) && !isset($seen[$ref])) {
                $seen[$ref] = true;
                $ordered[] = $resourceHrefs[$ref];
            }
            $this->scorm_collect_item_order($item, $resourceHrefs, $ordered, $seen);
        }
    }

    /**
     * Quita bloques <script> y <style> COMPLETOS (etiqueta + contenido) antes
     * de pasar por html_to_text(), para no colar JS/CSS como si fuera texto.
     *
     * @param string $html
     * @return string
     */
    private function strip_script_and_style(string $html): string {
        $html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $html);
        $html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $html);
        return (string)$html;
    }

    // ----------------------------------------------------------------
    // Chunking helpers
    // ----------------------------------------------------------------

    /**
     * Split text into overlapping chunks.
     *
     * @param string $text        Clean plain text.
     * @param int    $cmid        course_modules id.
     * @param string $module_type Module type name.
     * @param string $module_name Human-readable module name.
     * @return array
     */
    public function chunk_text(string $text, int $cmid, string $module_type, string $module_name): array {
        $text = $this->sanitize_utf8($text);
        $text = trim($text);
        if (empty($text)) {
            return [];
        }

        $chunks   = [];
        $length   = mb_strlen($text);
        $start    = 0;
        $index    = 0;
        $step     = self::CHUNK_SIZE - self::CHUNK_OVERLAP;

        while ($start < $length) {
            $chunk_text = mb_substr($text, $start, self::CHUNK_SIZE);

            // Try to break at a sentence boundary (period/newline) near the end.
            if ($start + self::CHUNK_SIZE < $length) {
                $break = mb_strrpos($chunk_text, "\n");
                if ($break === false || $break < self::CHUNK_SIZE * 0.5) {
                    $break = mb_strrpos($chunk_text, '. ');
                }
                if ($break !== false && $break > self::CHUNK_SIZE * 0.5) {
                    $chunk_text = mb_substr($chunk_text, 0, $break + 1);
                }
            }

            $chunk_text = trim($chunk_text);
            if (strlen($chunk_text) > 0) {
                // Prepend context header so the model knows the source.
                $prefixed = "[{$module_type}: {$module_name}]\n{$chunk_text}";
                $prefixed = $this->sanitize_utf8($prefixed);
                $chunks[] = [
                    'cmid'        => $cmid,
                    'module_type' => $module_type,
                    'module_name' => mb_substr($module_name, 0, 255),
                    'chunk_index' => $index,
                    'chunk_text'  => $prefixed,
                    'token_count' => $this->estimate_tokens($prefixed),
                ];
                $index++;
            }

            $advance = mb_strlen($chunk_text) - self::CHUNK_OVERLAP;
            if ($advance <= 0) {
                $advance = $step;
            }
            $start += $advance;
            if ($start <= 0) {
                break; // Prevent infinite loop on very short chunks.
            }
        }

        return $chunks;
    }

    // ----------------------------------------------------------------
    // Utility helpers
    // ----------------------------------------------------------------

    /**
     * Convert HTML to clean plain text.
     */
    public function html_to_text(string $html): string {
        if (empty($html)) {
            return '';
        }
        // Replace block-level tags with newlines before stripping.
        $text = preg_replace(
            ['/<br\s*\/?>/i', '/<\/p>/i', '/<\/li>/i', '/<\/h[1-6]>/i', '/<\/div>/i', '/<\/tr>/i'],
            "\n",
            $html
        );
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Collapse excessive whitespace while preserving single blank lines.
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        return trim($text);
    }

    /**
     * Rough token count estimate: ~4 chars per token (OpenAI rule of thumb).
     */
    public function estimate_tokens(string $text): int {
        return (int)ceil(mb_strlen($text) / 4);
    }

    /**
     * Best-effort PDF text extraction.
     *
     * Strategy (cada resultado se valida con is_extracted_text_useful() antes de
     * aceptarse; si no es texto legible, se prueba la siguiente):
     *  (a) Libreria PHP pura smalot/pdfparser, vendorizada con el plugin
     *      (lib/pdfparser/) — unica que decodifica fuentes CID/Identity-H
     *      con tabla ToUnicode.
     *  (b) Binario pdftotext via shell_exec, solo si el servidor lo tuviera
     *      instalado (fallback inofensivo, no es un requisito).
     *  (c) Parser naive de streams PDF (ultimo recurso).
     *
     * @param \stored_file $file
     * @return string
     */
    private function extract_pdf_text(\stored_file $file): string {
        $tmpin = tempnam(sys_get_temp_dir(), 'pulso_pdf_in_');
        $tmpout = tempnam(sys_get_temp_dir(), 'pulso_pdf_out_');
        if (!$tmpin || !$tmpout) {
            return '';
        }

        // tempnam creates the file; for pdftotext we want a .txt output path.
        $txtout = $tmpout . '.txt';

        try {
            $file->copy_content_to($tmpin);

            // (a) Libreria PHP pura (sin dependencia de shell).
            $librarytext = $this->try_parse_pdf_with_php_library($tmpin);
            if ($this->is_extracted_text_useful($librarytext)) {
                return trim($librarytext);
            }

            // (b) Fallback a pdftotext si el servidor lo tiene instalado.
            if (function_exists('shell_exec')) {
                $commands = [
                    'pdftotext -layout ' . escapeshellarg($tmpin) . ' ' . escapeshellarg($txtout),
                    'pdftotext ' . escapeshellarg($tmpin) . ' ' . escapeshellarg($txtout),
                ];

                foreach ($commands as $cmd) {
                    @shell_exec($cmd . ' 2>&1');
                    if (is_file($txtout) && filesize($txtout) > 0) {
                        $content = trim((string)file_get_contents($txtout));
                        if ($this->is_extracted_text_useful($content)) {
                            return $content;
                        }
                    }
                }
            }

            // (c) Parser naive de streams PDF, ultimo recurso.
            $naivetext = $this->try_parse_pdf_naive($tmpin);
            if ($this->is_extracted_text_useful($naivetext)) {
                return $naivetext;
            }
        } catch (\Throwable $e) {
            return '';
        } finally {
            @unlink($tmpin);
            @unlink($tmpout);
            @unlink($txtout);
        }

        return '';
    }

    /**
     * Heuristica de "texto legible": descarta basura/bytes mal decodificados
     * que pasarian un simple chequeo de longitud (p. ej. fuentes CID leidas
     * con el encoding equivocado) pero no son texto real.
     *
     * @param string $text
     * @return bool
     */
    private function is_extracted_text_useful(string $text): bool {
        $trimmed = trim($text);
        $totalLen = mb_strlen($trimmed, 'UTF-8');
        if ($totalLen < 20) {
            return false;
        }

        $letters = preg_match_all('/\p{L}/u', $trimmed);
        if ($letters === false || ($letters / $totalLen) < 0.4) {
            return false;
        }

        $words = preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY);
        $realWords = 0;
        foreach ($words as $word) {
            if (mb_strlen($word, 'UTF-8') >= 3 && preg_match('/\p{L}/u', $word)) {
                $realWords++;
            }
        }

        return $realWords >= 3;
    }

    /**
     * Extraer texto de un .docx (Word) — es un ZIP con XML dentro.
     * Usa ZipArchive (extension nativa de PHP), sin librerias externas.
     *
     * @param \stored_file $file
     * @return string
     */
    private function extract_docx_text(\stored_file $file): string {
        if (!class_exists('ZipArchive')) {
            return '';
        }

        $tmpin = tempnam(sys_get_temp_dir(), 'pulso_docx_');
        if (!$tmpin) {
            return '';
        }

        try {
            $file->copy_content_to($tmpin);

            $zip = new \ZipArchive();
            if ($zip->open($tmpin) !== true) {
                return '';
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false || $xml === '') {
                return '';
            }

            return $this->docx_xml_to_text($xml);
        } catch (\Throwable $e) {
            return '';
        } finally {
            @unlink($tmpin);
        }
    }

    /**
     * Convertir el XML de word/document.xml a texto plano.
     *
     * @param string $xml
     * @return string
     */
    private function docx_xml_to_text(string $xml): string {
        // Saltos de parrafo y de linea explicitos ANTES de quitar etiquetas.
        $xml = str_replace('</w:p>', "</w:p>\n", $xml);
        $xml = preg_replace('/<w:tab\s*\/>/', "\t", $xml);
        $xml = preg_replace('/<w:br\s*\/>/', "\n", $xml);

        $text = strip_tags($xml);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim((string)$text);
    }

    /**
     * Extraer texto de un .pptx (PowerPoint) — lee las diapositivas EN ORDEN
     * y concatena el texto de los nodos <a:t> de cada una.
     *
     * @param \stored_file $file
     * @return string
     */
    private function extract_pptx_text(\stored_file $file): string {
        if (!class_exists('ZipArchive')) {
            return '';
        }

        $tmpin = tempnam(sys_get_temp_dir(), 'pulso_pptx_');
        if (!$tmpin) {
            return '';
        }

        try {
            $file->copy_content_to($tmpin);

            $zip = new \ZipArchive();
            if ($zip->open($tmpin) !== true) {
                return '';
            }

            // Localizar diapositivas y ordenarlas numericamente (slide2 antes que slide10).
            $slideNumbers = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && preg_match('#^ppt/slides/slide(\d+)\.xml$#', $name, $m)) {
                    $slideNumbers[(int)$m[1]] = $name;
                }
            }
            ksort($slideNumbers, SORT_NUMERIC);

            $slideTexts = [];
            foreach ($slideNumbers as $num => $entryName) {
                $xml = $zip->getFromName($entryName);
                if ($xml === false || $xml === '') {
                    continue;
                }
                $text = $this->pptx_slide_xml_to_text($xml);
                if ($text !== '') {
                    $slideTexts[] = 'Diapositiva ' . $num . ":\n" . $text;
                }
            }
            $zip->close();

            return trim(implode("\n\n", $slideTexts));
        } catch (\Throwable $e) {
            return '';
        } finally {
            @unlink($tmpin);
        }
    }

    /**
     * Extraer el texto (nodos <a:t>) de una diapositiva pptx.
     *
     * @param string $xml
     * @return string
     */
    private function pptx_slide_xml_to_text(string $xml): string {
        $texts = [];
        if (preg_match_all('/<a:t>(.*?)<\/a:t>/s', $xml, $matches)) {
            foreach ($matches[1] as $chunk) {
                $decoded = trim(html_entity_decode($chunk, ENT_QUOTES | ENT_XML1, 'UTF-8'));
                if ($decoded !== '') {
                    $texts[] = $decoded;
                }
            }
        }
        return implode("\n", $texts);
    }

    /**
     * Try extracting text using a PHP PDF parser library.
     *
     * Current supported library: smalot/pdfparser (class Smalot\PdfParser\Parser)
     *
     * @param string $pdfpath
     * @return string
     */
    private function try_parse_pdf_with_php_library(string $pdfpath): string {
        if (!$this->try_load_pdf_parser_library()) {
            return '';
        }

        if (!class_exists('\\Smalot\\PdfParser\\Parser')) {
            return '';
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfpath);
            $text = trim((string)$pdf->getText());
            return $text;
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Load composer autoloaders from common locations if available.
     *
     * @return bool true when the parser class can be resolved.
     */
    private function try_load_pdf_parser_library(): bool {
        global $CFG;

        if (class_exists('\\Smalot\\PdfParser\\Parser')) {
            return true;
        }

        $autoloaders = [
            __DIR__ . '/../lib/pdfparser/autoload.php',      // vendorizado con el plugin (sin Composer)
            __DIR__ . '/../vendor/autoload.php',            // plugin-local vendor
            __DIR__ . '/../../vendor/autoload.php',          // blocks/pulso/vendor
            $CFG->dirroot . '/vendor/autoload.php',          // moodle root vendor
        ];

        foreach ($autoloaders as $autoload) {
            if (is_file($autoload)) {
                require_once($autoload);
                if (class_exists('\\Smalot\\PdfParser\\Parser')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Naive text extraction for text-based PDFs without external dependencies.
     *
     * This is not a full PDF parser, but it often recovers text from many
     * generated PDFs by reading stream objects and extracting text operators.
     *
     * @param string $pdfpath
     * @return string
     */
    private function try_parse_pdf_naive(string $pdfpath): string {
        $bin = @file_get_contents($pdfpath);
        if ($bin === false || strlen($bin) < 32) {
            return '';
        }

        $out = [];

        // 1) Try to decode stream objects (especially FlateDecode streams).
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bin, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = $stream;
                $inflated = @gzuncompress($stream);
                if ($inflated !== false && is_string($inflated)) {
                    $decoded = $inflated;
                } else {
                    $inflated = @gzinflate($stream);
                    if ($inflated !== false && is_string($inflated)) {
                        $decoded = $inflated;
                    }
                }

                $text = $this->extract_pdf_text_operators($decoded);
                if (!empty($text)) {
                    $out[] = $text;
                }
            }
        }

        // 2) Fallback over raw binary if stream parsing found nothing.
        if (empty($out)) {
            $raw = $this->extract_pdf_text_operators($bin);
            if (!empty($raw)) {
                $out[] = $raw;
            }
        }

        $joined = trim(implode("\n", $out));
        if (strlen($joined) < 20) {
            return '';
        }

        // Normalize whitespace.
        $joined = preg_replace('/[ \t]+/', ' ', $joined);
        $joined = preg_replace('/\n{3,}/', "\n\n", $joined);

        return trim($joined);
    }

    /**
     * Extract string operands used by PDF text-show operators (Tj/TJ).
     *
     * @param string $content
     * @return string
     */
    private function extract_pdf_text_operators(string $content): string {
        $parts = [];

        // Literal strings before Tj operator: (text) Tj
        if (preg_match_all('/\(([^\\)]*(?:\\.[^\\)]*)*)\)\s*Tj/s', $content, $m1)) {
            foreach ($m1[1] as $s) {
                $parts[] = $this->decode_pdf_literal_string($s);
            }
        }

        // Arrays before TJ operator: [(a) 10 (b)] TJ
        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $m2)) {
            foreach ($m2[1] as $arr) {
                if (preg_match_all('/\(([^\\)]*(?:\\.[^\\)]*)*)\)/s', $arr, $marr)) {
                    foreach ($marr[1] as $s) {
                        $parts[] = $this->decode_pdf_literal_string($s);
                    }
                }

                // Hex strings inside TJ arrays: [<00480065...> 120 <006C...>] TJ
                if (preg_match_all('/<([0-9A-Fa-f\s]+)>/s', $arr, $mhexarr)) {
                    foreach ($mhexarr[1] as $hex) {
                        $parts[] = $this->decode_pdf_hex_string($hex);
                    }
                }
            }
        }

        // Standalone hex strings before Tj: <00480065...> Tj
        if (preg_match_all('/<([0-9A-Fa-f\s]+)>\s*Tj/s', $content, $m3)) {
            foreach ($m3[1] as $hex) {
                $parts[] = $this->decode_pdf_hex_string($hex);
            }
        }

        $text = trim(implode("\n", array_filter($parts, function($v) {
            return strlen(trim($v)) > 0;
        })));

        return $text;
    }

    /**
     * Decode escaped PDF literal string content.
     *
     * @param string $s
     * @return string
     */
    private function decode_pdf_literal_string(string $s): string {
        // Common escaped sequences in PDF strings.
        $map = [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\b' => "\b",
            '\\f' => "\f",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ];

        $decoded = strtr($s, $map);

        // Decode octal escapes like \053.
        $decoded = preg_replace_callback('/\\\\([0-7]{1,3})/', function($m) {
            return chr(octdec($m[1]));
        }, $decoded);

        // Remove non-printable control chars except line breaks.
        $decoded = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $decoded);

        return trim($decoded);
    }

    /**
     * Decode hex-encoded PDF string, including UTF-16BE BOM forms.
     *
     * @param string $hex
     * @return string
     */
    private function decode_pdf_hex_string(string $hex): string {
        $hex = preg_replace('/\s+/', '', $hex);
        if ($hex === '' || (strlen($hex) % 2) !== 0) {
            return '';
        }

        $bin = @hex2bin($hex);
        if ($bin === false || $bin === '') {
            return '';
        }

        // UTF-16BE with BOM FEFF
        if (strlen($bin) >= 2 && substr($bin, 0, 2) === "\xFE\xFF") {
            $u16 = substr($bin, 2);
            $utf8 = @iconv('UTF-16BE', 'UTF-8//IGNORE', $u16);
            return trim((string)$utf8);
        }

        // UTF-16LE with BOM FFFE (rare in PDFs)
        if (strlen($bin) >= 2 && substr($bin, 0, 2) === "\xFF\xFE") {
            $u16 = substr($bin, 2);
            $utf8 = @iconv('UTF-16LE', 'UTF-8//IGNORE', $u16);
            return trim((string)$utf8);
        }

        // Fallback: treat as Latin-1 bytes.
        $txt = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $bin);
        return trim((string)$txt);
    }

    /**
     * Ensure a string is valid UTF-8 and strip invalid byte sequences.
     *
     * @param string $text
     * @return string
     */
    private function sanitize_utf8(string $text): string {
        if ($text === '') {
            return '';
        }

        // If text is not valid UTF-8, try Latin-1 -> UTF-8 conversion first.
        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $text);
            if ($converted !== false && $converted !== '') {
                $text = $converted;
            }
        }

        // Remove any remaining invalid sequences.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
        if ($clean !== false) {
            $text = $clean;
        }

        return $text;
    }
}
