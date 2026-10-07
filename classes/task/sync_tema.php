<?php
/**
 * Scheduled task: sincronizar los colores del centro con Épica (carta 11).
 *
 * Cada hora pregunta a Épica por el tema y guarda la respuesta; al pintar, Pulse lee solo lo
 * guardado (ninguna página espera a Épica). Un fallo deja lo último guardado.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../tema_service.php');

class sync_tema extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_sync_tema', 'block_pulso');
    }

    public function execute(): void {
        $res = \block_pulso\tema_service::sincronizar();
        mtrace('Pulso tema: ' . $res['estado'] . ' — ' . $res['mensaje']);
    }
}
