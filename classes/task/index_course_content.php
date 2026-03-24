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

        // Skip entirely if RAG is disabled in settings.
        if (!get_config('block_pulso', 'rag_enabled')) {
            mtrace('Pulso RAG: indexing skipped — rag_enabled is off.');
            return;
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
                $courses_done++;
                mtrace("    indexed={$stats['indexed']} skipped={$stats['skipped']} deleted={$stats['deleted']}");
            } catch (\Throwable $e) {
                // Log error but do not halt other courses.
                mtrace("    ERROR: " . $e->getMessage());
            }
        }

        mtrace(sprintf(
            'Pulso RAG indexing complete: %d courses processed, %d skipped. ' .
            'Chunks — indexed: %d, skipped (unchanged): %d, deleted: %d.',
            $courses_done, $courses_skipped,
            $total_indexed, $total_skipped, $total_deleted
        ));
    }
}
