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
require_once(__DIR__ . '/classes/epica_client.php');

use block_pulso\chat_pipeline;
use block_pulso\creation_quota;
use block_pulso\pulso_error;

$courseid = required_param('courseid', PARAM_INT);
// 'ampliacion' y 'retos' no pasan por los cupos de encargos de Epica (cada una tiene
// sus propios topes en su endpoint); cualquier otro valor = flujo de siempre.
$skipquota = in_array(optional_param('tool', '', PARAM_ALPHA), ['ampliacion', 'retos'], true);

header('Content-Type: application/json; charset=utf-8');

try {
    // Mismo orden que los demás endpoints del plugin: autenticar -> sesskey ->
    // permisos -> estado del plugin.
    pulso_error::require_session();
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    pulso_error::require_sesskey();
    require_capability('block/pulso:createactivity', $context);
    chat_pipeline::check_enabled($courseid);

    // Todo menos «Ampliar recurso» necesita Épica (v1.31.0): sin ella, el formulario no se abre.
    if (optional_param('tool', '', PARAM_ALPHA) !== 'ampliacion' && !\block_pulso\epica_client::disponible()) {
        throw new pulso_error('unavailable', \block_pulso\epica_client::MENSAJE_NO_DISPONIBLE, 503);
    }

    global $USER;
    $userid = (int)$USER->id;
    $isteacher = chat_pipeline::user_can_view_analytics($courseid);

    // Ampliacion y Retos tienen sus propios topes (api_ampliacion.php / api_retos.php): no se comprueba ni bloquea el cupo de encargos.
    $quota = $skipquota
        ? ['allowed' => true, 'reason' => '']
        : creation_quota::check_general_quota($courseid, $userid, $isteacher);

    $response = [
        'success' => true,
        'quota_ok' => $quota['allowed'],
        'quota_message' => $quota['reason'],
        'resources' => [],
        'noresourcesreason' => null,
    ];

    if ($quota['allowed']) {
        $ctx = creation_quota::get_resources_context($courseid, $userid, !$skipquota);
        $response['resources'] = $ctx['resources'];
        $response['noresourcesreason'] = $ctx['reason'];
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    // Texto para persona + error_code; el detalle tecnico va solo a error_log.
    pulso_error::send_json($e, 'api_create_form', $courseid);
}
