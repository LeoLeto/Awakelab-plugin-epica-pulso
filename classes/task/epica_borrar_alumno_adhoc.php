<?php
/**
 * Adhoc task: pedir a Épica que borre los datos de UNA persona (carta 14, v2.4.0).
 *
 * Épica guarda, por `sub` (id de Moodle) y por centro, el historial del alumno, las copias de
 * sus láminas y juegos, sus sesiones y lo que contestó en los retos. Cuando en Moodle se borran
 * los datos o la cuenta de una persona, se encola esta tarea (nunca se llama a Épica dentro del
 * provider de privacidad ni de un evento: una caída de Épica no puede hacer fallar la solicitud
 * de Moodle).
 *
 * - Épica no disponible: termina sin hacer nada.
 * - 200 (haya datos o no): hecho.
 * - Fallo transitorio (red, http 0, 5xx, excepción al firmar o pedir): LANZA, y Moodle reintenta
 *   la tarea con su espera creciente. Cada reintento firma un token nuevo. Duda sin verificar: hasta
 *   Moodle 4.3 se cree que no hay tope de reintentos; en versiones nuevas puede haber un tope
 *   (`attemptsavailable`, 12 por defecto), tras el cual la tarea se descarta y queda en el log de
 *   tareas fallidas (el borrado se relanzaría repitiendo la solicitud de privacidad).
 * - 4xx: terminal. error_log con el motivo y sin reintentar (un 403 sería un error de
 *   configuración nuestro).
 *
 * El `userid` NUNCA se guarda en ninguna tabla ni ajuste para «recordar que se borró»: guardarlo
 * sería no haberlo borrado. En los logs solo va el id, nunca el correo ni el nombre.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../epica_client.php');

class epica_borrar_alumno_adhoc extends \core\task\adhoc_task {

    /** Ruta de Épica (se cuelga de epica_client::endpoint()). */
    const RUTA = '/api/moodle/alumno/borrar';

    public function get_name(): string {
        return get_string('task_epica_borrar_alumno_adhoc', 'block_pulso');
    }

    /**
     * Encola el borrado de una persona. Si ya hay una tarea igual esperando no encola otra (el
     * provider y el evento user_deleted pueden coincidir en una misma solicitud); borrar dos veces
     * es seguro, esto solo evita la llamada de más.
     */
    public static function encolar(int $userid): void {
        if ($userid <= 0) {
            return;
        }
        $tarea = new self();
        $tarea->set_component('block_pulso');
        $tarea->set_custom_data(['userid' => $userid]);
        \core\task\manager::queue_adhoc_task($tarea, true);
    }

    public function execute(): void {
        $data = $this->get_custom_data();
        $userid = (int)($data->userid ?? 0);
        if ($userid <= 0) {
            mtrace('Pulso borrado Épica: sin userid en custom data, nada que hacer.');
            return;
        }

        if (!\block_pulso\epica_client::disponible()) {
            mtrace("Pulso borrado Épica: Épica no está disponible en este sitio; no se pide el borrado del usuario {$userid}.");
            return;
        }

        $admin = get_admin();
        if (empty($admin) || empty($admin->email)) {
            // Sin correo el token sale sin claim email y Épica lo rechaza. Se lanza (en vez de
            // darlo por perdido): es un borrado debido y se arregla poniendo el correo al administrador.
            error_log('Pulso borrado Épica: el administrador del sitio no tiene correo; no se puede firmar.');
            throw new \RuntimeException('El administrador del sitio no tiene correo; no se puede firmar la petición de borrado.');
        }

        try {
            // Token nuevo en cada ejecución (el jti se gasta). Como tema_service: administrador,
            // contexto de sistema, viewanalytics (NO createactivity) y sin curso. Espera corta.
            $token = \local_awkepica\epica::firmar_por(
                $admin,
                \context_system::instance(),
                \block_pulso\epica_client::CAPABILITY_ROL
            );
            $respuesta = \local_awkepica\epica::pedir(
                \block_pulso\epica_client::endpoint(self::RUTA),
                ['token' => $token, 'sub_alumno' => (string)$userid]
            );
        } catch (\Throwable $e) {
            // Solo la clase: el mensaje podría arrastrar datos del token o de la petición.
            error_log("Pulso borrado Épica (usuario {$userid}): " . get_class($e));
            throw new \RuntimeException("Fallo al pedir a Épica el borrado del usuario {$userid} ("
                . get_class($e) . '); se reintentará.');
        }

        if (\block_pulso\epica_client::es_transitorio($respuesta)) {
            $motivo = \block_pulso\epica_client::describir_transitorio($respuesta);
            error_log("Pulso borrado Épica (usuario {$userid}): {$motivo}");
            throw new \RuntimeException("Épica no respondió al borrado del usuario {$userid} ({$motivo}); se reintentará.");
        }

        [$http, $datos] = \block_pulso\epica_client::normalize_response($respuesta);
        if ($http === 200) {
            $borrado = is_array($datos['borrado'] ?? null) ? $datos['borrado'] : [];
            $habia = !empty($datos['habia_datos']) ? 'sí' : 'no';
            mtrace("Pulso borrado Épica: usuario {$userid} hecho; había datos: {$habia}; historial="
                . (int)($borrado['historial'] ?? 0) . ' copias=' . (int)($borrado['copias'] ?? 0)
                . ' sesiones=' . (int)($borrado['sesiones'] ?? 0)
                . ' retos_contestados=' . (int)($borrado['retos_contestados'] ?? 0));
            return;
        }

        // 4xx (o cualquier otro no-200 no transitorio): terminal, sin reintentar.
        $motivo = mb_substr(preg_replace('/[^\w .:-]/u', '', (string)($datos['motivo'] ?? '')), 0, 60, 'UTF-8');
        $traza = mb_substr(preg_replace('/[^\w .:\/-]/u', '', (string)($datos['traza'] ?? '')), 0, 200, 'UTF-8');
        error_log("Pulso borrado Épica (usuario {$userid}): Épica respondió HTTP {$http}"
            . ($motivo !== '' ? " ({$motivo})" : '') . ($traza !== '' ? " traza: {$traza}" : '')
            . '; no se reintenta.');
        mtrace("Pulso borrado Épica: usuario {$userid} NO borrado, HTTP {$http}; ver error_log.");
    }
}
