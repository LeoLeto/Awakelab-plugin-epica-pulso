<?php
/**
 * AJAX endpoint: registra un encargo de creación (Epica) con estado
 * "pendiente".
 *
 * Revalida en servidor -nunca confiando en lo que ya se comprobó al abrir el
 * formulario, que puede haberse quedado desfasado- que: el usuario sigue
 * teniendo cupo (general y de la sección del recurso elegido) y que el
 * recurso sigue siendo uno que este usuario puede ver y que tiene texto
 * aprovechable.
 *
 * No envía nada a Epica todavía: eso es el paso 4 de la integración
 * (creation_quota::record_encargo() ya deja marcado el punto de entrada).
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
$cmid = required_param('cmid', PARAM_INT);
$format = required_param('format', PARAM_ALPHANUMEXT);
// PARAM_RAW: el recorte a MAX_PROMPT_LENGTH se hace con mb_substr más abajo,
// nunca con substr/strlen -el texto es UTF-8 en español (ver CLAUDE.md).
$prompt = trim((string)required_param('prompt', PARAM_RAW));

header('Content-Type: application/json; charset=utf-8');

try {
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    require_sesskey();
    require_capability('block/pulso:createactivity', $context);
    chat_pipeline::check_enabled($courseid);

    if (!in_array($format, creation_quota::FORMATS, true)) {
        throw new \Exception('Formato de infografía no reconocido.');
    }
    if ($prompt === '') {
        throw new \Exception('Describe qué infografía quieres antes de enviarla.');
    }
    $prompt = mb_substr($prompt, 0, creation_quota::MAX_PROMPT_LENGTH, 'UTF-8');

    global $USER;
    $userid = (int)$USER->id;
    $isteacher = chat_pipeline::user_can_view_analytics($courseid);

    // El cmid tiene que seguir siendo uno realmente ofrecible para ESTE
    // usuario -con texto y visible-, calculado de nuevo en servidor: nunca
    // confiar en el cmid tal cual llega del cliente.
    $ctx = creation_quota::get_resources_context($courseid, $userid);
    $resource = null;
    foreach ($ctx['resources'] as $candidate) {
        if ((int)$candidate['cmid'] === $cmid) {
            $resource = $candidate;
            break;
        }
    }
    if ($resource === null) {
        throw new \Exception('Ese recurso ya no está disponible para generar una infografía.');
    }

    $quota = creation_quota::check_general_quota($courseid, $userid, $isteacher);
    if ($quota['allowed']) {
        $quota = creation_quota::check_section_quota($courseid, $userid, (int)$resource['sectionnum']);
    }
    if (!$quota['allowed']) {
        throw new \Exception($quota['reason']);
    }

    $encargoid = creation_quota::record_encargo(
        $courseid,
        $cmid,
        (int)$resource['sectionnum'],
        $userid,
        creation_quota::TOOL_INFOGRAFIA,
        $prompt,
        $format
    );

    echo json_encode([
        'success' => true,
        'encargoid' => $encargoid,
        'message' => 'Encargo guardado. En cuanto Pulse pueda enviarlo a generación, te avisaremos aquí.',
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
