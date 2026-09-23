<?php
/**
 * Full Text Store — texto completo por modulo para Epica (material.texto)
 *
 * Guarda el texto ENTERO extraido de cada modulo (sin trocear), capturado por
 * content_extractor::chunk_text() antes de partirlo en fragmentos. No se
 * reconstruye concatenando chunk_text de block_pulso_content_chunks porque los
 * fragmentos SE SOLAPAN (content_extractor::CHUNK_OVERLAP) y esa concatenacion
 * duplicaria texto.
 *
 * Como la extraccion en si, este upsert corre SOLO desde las tareas de cron
 * (via rag_retriever::index_course()), nunca dentro de una peticion de chat.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

class full_text_store {

    /**
     * Upsert del texto completo de un curso.
     *
     * El courseid es OBLIGATORIO en cada filtro/lectura: los cmid sinteticos de
     * course_meta/course_section (content_extractor::course_meta_cmid() /
     * course_section_cmid()) estan pensados para no colisionar entre cursos,
     * pero un filtro que solo mirase cmid ya sobrescribio una vez filas de otro
     * curso en block_pulso_content_chunks (ver CLAUDE.md) — no se repite aqui.
     *
     * @param int   $courseid
     * @param array $fulltexts Salida de content_extractor::get_extracted_full_texts()
     * @return array ['stored'=>int, 'skipped'=>int, 'deleted'=>int]
     */
    public static function store_course_texts(int $courseid, array $fulltexts): array {
        global $DB;

        $stats = ['stored' => 0, 'skipped' => 0, 'deleted' => 0];

        if (!self::table_exists()) {
            return $stats;
        }

        $now = time();
        $current_cmids = array_unique(array_column($fulltexts, 'cmid'));

        // Borrar filas de modulos que ya no existen en el curso (mismo patron que
        // embedding_manager::index_course_chunks()).
        if (!empty($current_cmids)) {
            [$not_in_sql, $in_params] = $DB->get_in_or_equal($current_cmids, SQL_PARAMS_NAMED, 'cmid', false);
            $stale = $DB->get_records_select(
                'block_pulso_full_text',
                "courseid = :courseid AND cmid $not_in_sql",
                array_merge(['courseid' => $courseid], $in_params),
                '',
                'id'
            );
            if ($stale) {
                $DB->delete_records_list('block_pulso_full_text', 'id', array_keys($stale));
                $stats['deleted'] = count($stale);
            }
        }

        foreach ($fulltexts as $entry) {
            $texto = (string)$entry['texto'];
            $hash  = hash('sha256', $texto);

            $existing = $DB->get_record('block_pulso_full_text', [
                'courseid' => $courseid,
                'cmid'     => $entry['cmid'],
            ]);

            if ($existing && $existing->content_hash === $hash) {
                $stats['skipped']++;
                continue;
            }

            $record = (object)[
                'courseid'     => $courseid,
                'cmid'         => $entry['cmid'],
                'module_type'  => $entry['module_type'],
                'module_name'  => $entry['module_name'],
                'texto'        => $texto,
                'caracteres'   => mb_strlen($texto, 'UTF-8'),
                'extraido_por' => $entry['extraido_por'],
                'usable'       => !empty($entry['usable']) ? 1 : 0,
                'content_hash' => $hash,
                'timecreated'  => $existing ? $existing->timecreated : $now,
                'timemodified' => $now,
            ];

            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('block_pulso_full_text', $record);
            } else {
                $DB->insert_record('block_pulso_full_text', $record);
            }
            $stats['stored']++;
        }

        return $stats;
    }

    /**
     * Cmid de un curso con texto completo APROVECHABLE (no solo "tiene fila":
     * un PDF escaneado puede dejar fila con el aviso de fallo). Es lo que
     * alimenta el desplegable de "enviar a Epica" — solo se puede ofrecer un
     * recurso del que de verdad tengamos contenido.
     *
     * @param int $courseid
     * @return array cmid => ['module_type'=>string, 'module_name'=>string, 'caracteres'=>int]
     */
    public static function get_available_resources(int $courseid): array {
        global $DB;

        if (!self::table_exists()) {
            return [];
        }

        // cmid > 0: excluye los chunks sinteticos de metadatos/seccion, que no
        // son un recurso que se pueda ofrecer en el desplegable.
        $rows = $DB->get_records_select(
            'block_pulso_full_text',
            'courseid = :courseid AND usable = 1 AND cmid > 0',
            ['courseid' => $courseid],
            'module_name ASC',
            'cmid, module_type, module_name, caracteres'
        );

        $out = [];
        foreach ($rows as $row) {
            $out[(int)$row->cmid] = [
                'module_type' => $row->module_type,
                'module_name' => $row->module_name,
                'caracteres'  => (int)$row->caracteres,
            ];
        }
        return $out;
    }

    /**
     * Borra el texto completo de un curso (p. ej. al desactivar Pulso en el curso).
     *
     * @param int $courseid
     */
    public static function delete_course_texts(int $courseid): void {
        global $DB;
        if (!self::table_exists()) {
            return;
        }
        $DB->delete_records('block_pulso_full_text', ['courseid' => $courseid]);
    }

    /**
     * Verify the full-text storage table exists in current DB schema.
     *
     * @return bool
     */
    private static function table_exists(): bool {
        global $DB;

        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }

        try {
            $tables = $DB->get_tables();
            $exists = in_array('block_pulso_full_text', $tables, true);
        } catch (\Throwable $e) {
            $exists = false;
        }

        return $exists;
    }
}
