<?php
/**
 * Observers de block_pulso.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/epica_client.php');

class observer {

    /**
     * Se ha borrado la cuenta de una persona: se encola el borrado de lo que Épica guarda de ella
     * (carta 14). delete_user() de Moodle no llama a los providers de privacidad (solo lanza este
     * evento), así que sin este observer un borrado de cuenta no llegaría a Épica.
     *
     * Nunca llama a Épica ni lanza: solo encola, y un fallo aquí no puede tumbar el borrado.
     */
    public static function user_deleted(\core\event\user_deleted $event): void {
        try {
            if (!epica_client::disponible()) {
                return;
            }
            task\epica_borrar_alumno_adhoc::encolar((int)$event->objectid);
        } catch (\Throwable $e) {
            error_log('Pulso borrado Épica: no se pudo encolar tras user_deleted: ' . get_class($e));
        }
    }
}
