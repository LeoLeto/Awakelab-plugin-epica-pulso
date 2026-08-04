<?php
/**
 * Adhoc task: index a single course's content for RAG, in background.
 *
 * Existe para que la indexación NUNCA corra dentro de la petición de chat del
 * usuario: extraer el contenido de un curso implica parsear todos sus PDFs y
 * llamar a la API de embeddings de OpenAI por cada lote de fragmentos, lo que
 * puede tardar minutos y costar dinero. Antes se hacía en línea (y hasta dos
 * veces por mensaje), así que un curso cuyo contenido no genera fragmentos
 * embebibles reindexaba el curso completo en cada pregunta.
 *
 * Se encola desde rag_retriever::request_background_index(), que además limita
 * la frecuencia para que un curso sin contenido indexable no se reencole sin
 * parar. Requiere que el cron de Moodle esté funcionando (igual que la tarea
 * programada diaria).
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../rag_retriever.php');

class index_course_adhoc extends \core\task\adhoc_task {

    /**
     * @return string
     */
    public function get_name(): string {
        return get_string('task_index_course_adhoc', 'block_pulso');
    }

    /**
     * Index the course carried in the task's custom data.
     */
    public function execute(): void {
        $data = $this->get_custom_data();
        $courseid = (int)($data->courseid ?? 0);

        if ($courseid <= 0) {
            mtrace('Pulso RAG (adhoc): no courseid in custom data, nothing to do.');
            return;
        }

        if (!get_config('block_pulso', 'rag_enabled')) {
            mtrace('Pulso RAG (adhoc): skipped — rag_enabled is off.');
            return;
        }

        mtrace("Pulso RAG (adhoc): indexing course {$courseid}…");
        $stats = \block_pulso\rag_retriever::index_course($courseid);
        mtrace(sprintf(
            '  indexed=%d skipped=%d deleted=%d embedded=%d embed_errors=%d',
            $stats['indexed'] ?? 0,
            $stats['skipped'] ?? 0,
            $stats['deleted'] ?? 0,
            $stats['embedded'] ?? 0,
            $stats['embed_errors'] ?? 0
        ));
    }
}
