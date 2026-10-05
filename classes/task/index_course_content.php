<?php
/**
 * Scheduled task: Index course content for RAG
 *
 * Runs daily (configurable in Site administration → Server → Scheduled tasks).
 * For every course that has Pulso enabled, extracts all supported module
 * content, chunks it and generates/caches OpenAI embeddings.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../rag_retriever.php');

class index_course_content extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_index_course_content', 'block_pulso');
    }

    public function execute(): void {
        global $DB;

        // El texto completo lo necesitan las herramientas de Crear aunque el RAG esté
        // apagado (v1.31.0): se salta solo si NADA lo consume (RAG apagado y sin Épica
        // ni Ampliación). Los embeddings dependen además de rag_enabled y de la clave
        // de OpenAI (rag_retriever::embeddings_wanted()).
        if (!\block_pulso\rag_retriever::text_extraction_wanted()) {
            mtrace('Pulso RAG: indexing skipped — RAG off and no Crear tool available.');
            return;
        }
        if (!\block_pulso\rag_retriever::embeddings_wanted()) {
            mtrace('Pulso: solo texto completo (sin embeddings: RAG apagado o falta la clave de OpenAI).');
        }

        $default_enabled = get_config('block_pulso', 'enabled_by_default');

        // Find all courses that have a Pulso block instance.
        $courses = $DB->get_records_sql(
            "SELECT DISTINCT bi.parentcontextid, ctx.instanceid AS courseid
               FROM {block_instances} bi
               JOIN {context} ctx ON ctx.id = bi.parentcontextid
              WHERE bi.blockname = 'pulso'
                AND ctx.contextlevel = :course_level",
            ['course_level' => CONTEXT_COURSE]
        );

        if (empty($courses)) {
            mtrace('Pulso RAG: no courses with block_pulso found.');
            return;
        }

        $total_indexed  = 0;
        $total_skipped  = 0;
        $total_deleted  = 0;
        $total_fulltext_stored = 0;
        $courses_done   = 0;
        $courses_skipped = 0;

        foreach ($courses as $row) {
            $courseid = (int)$row->courseid;

            // Respect per-course enabled toggle.
            $course_enabled = get_config('block_pulso', 'enabled_course_' . $courseid);
            if ($course_enabled !== false) {
                $is_enabled = (bool)$course_enabled;
            } else {
                $is_enabled = ($default_enabled === false) ? true : (bool)$default_enabled;
            }

            if (!$is_enabled) {
                mtrace("  Course {$courseid}: skipped (Pulso disabled for course).");
                $courses_skipped++;
                continue;
            }

            mtrace("  Indexing course {$courseid}…");

            try {
                $stats = \block_pulso\rag_retriever::index_course($courseid);
                $total_indexed += $stats['indexed'];
                $total_skipped += $stats['skipped'];
                $total_deleted += $stats['deleted'];
                $total_fulltext_stored += $stats['fulltext_stored'] ?? 0;
                $courses_done++;
                if (!empty($stats['rag_error']) || !empty($stats['fulltext_error'])) {
                    mtrace('    AVISO: fallo parcial (ver error_log) — rag_error=' . (int)($stats['rag_error'] ?? 0)
                        . ' fulltext_error=' . (int)($stats['fulltext_error'] ?? 0));
                }
                mtrace("    indexed={$stats['indexed']} skipped={$stats['skipped']} deleted={$stats['deleted']} " .
                    "| texto completo: nuevo/actualizado={$stats['fulltext_stored']} " .
                    "sin cambios={$stats['fulltext_skipped']} borrados={$stats['fulltext_deleted']}");
            } catch (\Throwable $e) {
                // Log error but do not halt other courses.
                mtrace("    ERROR: " . $e->getMessage());
            }
        }

        mtrace(sprintf(
            'Pulso RAG indexing complete: %d courses processed, %d skipped. ' .
            'Chunks — indexed: %d, skipped (unchanged): %d, deleted: %d. ' .
            'Texto completo — nuevo/actualizado: %d.',
            $courses_done, $courses_skipped,
            $total_indexed, $total_skipped, $total_deleted,
            $total_fulltext_stored
        ));
    }
}
