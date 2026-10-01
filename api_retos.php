<?php
/**
 * AJAX endpoint (POST): Retos de Epica — proponer seis retos, elegir uno y
 * recibir el enlace. Una `accion` por peticion:
 *   proponer   courseid + (cmid y/o tema) | propuesta («proponer otros»)
 *   propuesta  courseid + propuesta            (sondeo)
 *   elegir     courseid + propuesta + (reto | propio)
 *   refrescar  courseid + codigo
 *   curso      courseid
 *   mis_retos  courseid                        (sin llamar a Epica)
 *
 * A diferencia de las laminas y los juegos, Retos se llama desde la peticion
 * web (carta 8 §1): el navegador sondea ESTE endpoint y nunca habla con Epica.
 * Orden: autenticar -> sesskey -> permisos -> estado del plugin -> liberar la
 * sesion -> Epica. Nunca se devuelve el token ni la respuesta cruda de Epica.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/retos_service.php');

use block_pulso\chat_pipeline;
use block_pulso\reto_error;
use block_pulso\retos_service;

$courseid = required_param('courseid', PARAM_INT);
$accion = required_param('accion', PARAM_ALPHANUMEXT);

header('Content-Type: application/json; charset=utf-8');

const PULSO_RETOS_JSON = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new reto_error('metodo-no-permitido', 'Método no permitido.', 405);
    }

    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    require_sesskey();
    require_capability('block/pulso:createactivity', $context);
    try {
        chat_pipeline::check_enabled($courseid);
    } catch (\Exception $e) {
        throw new reto_error('pulso-desactivado', 'Pulso está desactivado en este curso.', 403);
    }

    // Registro completo (con email) para firmar el token, como hace la tarea adhoc.
    global $USER;
    $user = \core_user::get_user((int)$USER->id, '*', MUST_EXIST);

    // Como el chat: soltar el candado de sesion antes de llamar a Epica
    // (proponer con material puede tardar decenas de segundos).
    \core\session\manager::write_close();

    switch ($accion) {
        case 'proponer':
            $resultado = retos_service::proponer(
                $user,
                $courseid,
                optional_param('cmid', 0, PARAM_INT),
                optional_param('tema', '', PARAM_TEXT),
                optional_param('propuesta', 0, PARAM_INT)
            );
            break;

        case 'propuesta':
            $resultado = retos_service::consultar_propuesta($user, $courseid, required_param('propuesta', PARAM_INT));
            break;

        case 'elegir':
            $resultado = retos_service::elegir(
                $user,
                $courseid,
                required_param('propuesta', PARAM_INT),
                // El id del reto se compara tal cual con los GUARDADOS: sin limpiar nada que lo altere.
                optional_param('reto', '', PARAM_RAW_TRIMMED),
                optional_param('propio', '', PARAM_TEXT)
            );
            break;

        case 'refrescar':
            $resultado = retos_service::refrescar($user, $courseid, required_param('codigo', PARAM_ALPHANUMEXT));
            break;

        case 'curso':
            $resultado = retos_service::curso($user, $courseid);
            break;

        case 'mis_retos':
            $resultado = retos_service::mis_retos($user, $courseid);
            break;

        default:
            throw new reto_error('accion-desconocida', 'Acción desconocida.');
    }

    echo json_encode(['ok' => true] + $resultado, PULSO_RETOS_JSON);

} catch (reto_error $e) {
    http_response_code($e->status);
    echo json_encode(['ok' => false, 'error' => $e->motivo, 'mensaje' => $e->getMessage()] + $e->extra, PULSO_RETOS_JSON);
} catch (\moodle_exception $e) {
    // Sesion caducada, sesskey o capability: mensajes propios de Moodle, aptos para la persona.
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'acceso-denegado', 'mensaje' => $e->getMessage()], PULSO_RETOS_JSON);
} catch (\Throwable $e) {
    error_log('Pulso Retos: error inesperado en api_retos.php (' . $accion . '): ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'error-interno',
        'mensaje' => 'No hemos podido completar la operación. Inténtalo de nuevo en unos segundos.',
        'reintentable' => true,
    ], PULSO_RETOS_JSON);
}
