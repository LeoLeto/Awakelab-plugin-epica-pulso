<?php
/**
 * AJAX endpoint: datos para abrir el formulario de "Crear infografía" (Epica).
 *
 * Comprueba el cupo ANTES de dar la lista de recursos (si no queda cupo, no
 * merece la pena ni consultar el desplegable) y, si hay cupo, devuelve los
 * recursos que este usuario puede elegir de verdad -con texto aprovechable y
 * visibles para él-, junto con el consumo de hoy del cupo por sección de cada
 * uno, para que el frontend pueda avisar al elegir el recurso, antes de que el
 * usuario escriba su petición.
 *
 * No envía nada a Epica: eso es el paso 4 de la integración.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/creation_quota.php');

use block_pulso\chat_pipeline;
use block_pulso\creation_quota;

$courseid = required_param('courseid', PARAM_INT);

header('Content-Type: application/json; charset=utf-8');

try {
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    // Mismo orden que los demás endpoints del plugin: autenticar -> sesskey ->
    // permisos -> estado del plugin.
    require_login($course);
    require_sesskey();
    require_capability('block/pulso:createactivity', $context);
    chat_pipeline::check_enabled($courseid);

    global $USER;
    $userid = (int)$USER->id;
    $isteacher = chat_pipeline::user_can_view_analytics($courseid);

    $quota = creation_quota::check_general_quota($courseid, $userid, $isteacher);

    $response = [
        'success' => true,
        'quota_ok' => $quota['allowed'],
        'quota_message' => $quota['reason'],
        'resources' => [],
        'noresourcesreason' => null,
    ];

    if ($quota['allowed']) {
        $ctx = creation_quota::get_resources_context($courseid, $userid);
        $response['resources'] = $ctx['resources'];
        $response['noresourcesreason'] = $ctx['reason'];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
