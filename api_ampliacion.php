<?php
/**
 * AJAX endpoint (POST): ampliacion de un recurso del curso — tema + 2 videos de
 * YouTube + 2 articulos de OpenAlex, con cache compartida por recurso y
 * version de su texto (ver classes/ampliacion_service.php).
 *
 * Orden: autenticar -> sesskey -> permisos -> estado del plugin. El cmid se
 * recalcula SIEMPRE contra creation_quota::get_resources_context() (visible de
 * verdad para este usuario y con texto aprovechable), nunca se acepta tal cual.
 *
 * Los topes de esta herramienta son independientes de los cupos de
 * infografias/juegos: no escribe en block_pulso_encargos.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/creation_quota.php');
require_once(__DIR__ . '/classes/ampliacion_service.php');

use block_pulso\ampliacion_service;
use block_pulso\chat_pipeline;
use block_pulso\creation_quota;

$courseid = required_param('courseid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);

header('Content-Type: application/json; charset=utf-8');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        throw new \Exception('Método no permitido.');
    }

    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    require_sesskey();
    require_capability('block/pulso:createactivity', $context);
    chat_pipeline::check_enabled($courseid);

    global $USER;
    $userid = (int)$USER->id;

    $ctx = creation_quota::get_resources_context($courseid, $userid);
    $found = false;
    foreach ($ctx['resources'] as $candidate) {
        if ((int)$candidate['cmid'] === $cmid) {
            $found = true;
            break;
        }
    }
    if (!$found) {
        throw new \Exception('Ese recurso ya no está disponible para ampliar con Pulse.');
    }

    // Como el chat: soltar el candado de sesion antes de las llamadas externas
    // (Haiku + YouTube + OpenAlex pueden tardar decenas de segundos) para no
    // congelar las demas pestañas del usuario.
    \core\session\manager::write_close();

    $result = ampliacion_service::obtener($courseid, $cmid, $userid);

    echo json_encode([
        'success' => true,
        'cached' => $result['cached'],
        'tema' => $result['tema'],
        'videos' => $result['videos'],
        'articulos' => $result['articulos'],
        'avisos' => $result['avisos'],
        'idioma_curso' => $result['idioma_curso'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}
