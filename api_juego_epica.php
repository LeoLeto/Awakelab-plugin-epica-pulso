<?php
/**
 * AJAX endpoint (POST): «Crear juego» del profesorado, terminado en Épica (v2.18.0). Deja el borrador (la
 * petición y el texto del recurso) en Épica y devuelve su código; el navegador entra después por
 * fuentes_puente.php (herramienta=gamificacion). Ver classes/juego_epica_service.php.
 *
 * Orden: POST -> autenticar -> sesskey -> viewanalytics (solo profesorado) -> estado del plugin -> Épica
 * disponible -> el cmid se recalcula SIEMPRE contra creation_quota::get_resources_context() (visible de verdad
 * para este usuario y con texto aprovechable), nunca se acepta tal cual.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/creation_quota.php');
require_once(__DIR__ . '/classes/epica_client.php');
require_once(__DIR__ . '/classes/juego_epica_service.php');

use block_pulso\chat_pipeline;
use block_pulso\creation_quota;
use block_pulso\epica_client;
use block_pulso\juego_epica_service;
use block_pulso\pulso_error;

$courseid = required_param('courseid', PARAM_INT);

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new pulso_error('bad_request', 'Método no permitido.', 405);
    }

    pulso_error::require_session();
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    pulso_error::require_sesskey();
    // Solo profesorado: es la capability con la que se firma el token y la que abre el puente.
    require_capability('block/pulso:viewanalytics', $context);
    chat_pipeline::check_enabled($courseid);

    if (!epica_client::disponible()) {
        throw new pulso_error('unavailable', epica_client::MENSAJE_NO_DISPONIBLE, 503);
    }

    global $USER;
    $cmid = required_param('cmid', PARAM_INT);
    // PARAM_RAW: la valida y la recorta el servicio con mb_* (UTF-8).
    $peticion = required_param('prompt', PARAM_RAW);

    $ctx = creation_quota::get_resources_context($courseid, (int)$USER->id, false);
    $found = false;
    foreach ($ctx['resources'] as $candidate) {
        if ((int)$candidate['cmid'] === $cmid) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new pulso_error('bad_request', get_string('jep_err_sin_texto', 'block_pulso'));
    }

    \core\session\manager::write_close();

    $result = juego_epica_service::preparar($courseid, $cmid, $peticion);

    echo json_encode([
        'success' => true,
        'borrador' => $result['borrador'],
        'caduca' => $result['caduca'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (\Throwable $e) {
    // Texto para persona + error_code; el detalle tecnico va solo a error_log.
    pulso_error::send_json($e, 'api_juego_epica', $courseid);
}
