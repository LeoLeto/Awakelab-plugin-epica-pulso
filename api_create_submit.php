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
require_once(__DIR__ . '/classes/epica_client.php');

use block_pulso\chat_pipeline;
use block_pulso\creation_quota;

$courseid = required_param('courseid', PARAM_INT);
$cmid = required_param('cmid', PARAM_INT);
// creation_quota::TOOL_INFOGRAFIA por defecto (sin romper al cliente actual,
// que no manda "tool" todavía -no hay botón de gamificación hasta el paso 2).
// Con TOOL_GAMIFICACION, "format" no se pide ni se valida: esa herramienta no
// tiene formato, se guarda vacío (columna ya lo admite).
$tool = optional_param('tool', creation_quota::TOOL_INFOGRAFIA, PARAM_ALPHA);
$isjuego = $tool === creation_quota::TOOL_GAMIFICACION;
$format = $isjuego ? '' : required_param('format', PARAM_ALPHANUMEXT);
// PARAM_RAW: el recorte a MAX_PROMPT_LENGTH se hace con mb_substr más abajo,
// nunca con substr/strlen -el texto es UTF-8 en español (ver CLAUDE.md).
$prompt = trim((string)required_param('prompt', PARAM_RAW));

header('Content-Type: application/json; charset=utf-8');

try {
    \block_pulso\pulso_error::require_session();
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    \block_pulso\pulso_error::require_sesskey();
    require_capability('block/pulso:createactivity', $context);
    chat_pipeline::check_enabled($courseid);

    if (!in_array($tool, [creation_quota::TOOL_INFOGRAFIA, creation_quota::TOOL_GAMIFICACION], true)) {
        throw new \block_pulso\pulso_error('bad_request', 'Herramienta de creación no reconocida.');
    }
    // Sin Épica el encargo se guardaba, gastaba cupo y fallaba en el cron: ahora no se inserta nada.
    if (!\block_pulso\epica_client::disponible()) {
        throw new \block_pulso\pulso_error('unavailable', \block_pulso\epica_client::MENSAJE_NO_DISPONIBLE, 503);
    }
    if (!$isjuego && !in_array($format, creation_quota::FORMATS, true)) {
        throw new \block_pulso\pulso_error('bad_request', 'Formato de infografía no reconocido.');
    }
    if ($prompt === '') {
        throw new \block_pulso\pulso_error('bad_request', $isjuego
            ? 'Describe qué juego quieres antes de enviarlo.'
            : 'Describe qué infografía quieres antes de enviarla.');
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
        throw new \block_pulso\pulso_error('bad_request', 'Ese recurso ya no está disponible para generar contenido con Pulse.');
    }

    // Candado por usuario alrededor de "comprobar cupo + insertar": sin el, dos
    // envios simultaneos (doble clic, dos pestañas) pasan los dos la comprobacion
    // y el cupo se sobrepasa en 1. Mismo patron que Retos (retos_service::con_candado).
    $lock = null;
    try {
        $factory = \core\lock\lock_config::get_lock_factory('block_pulso_encargos');
        $lock = $factory->get_lock('encargo_' . $userid, 5, 60);
    } catch (\Throwable $e) {
        $lock = null; // Sin factoria de candados: se sigue sin proteccion extra.
    }
    if ($lock === false) {
        throw new \block_pulso\pulso_error('busy', 'Ya tienes un encargo en marcha. Espera un momento antes de enviar otro.', 409);
    }
    try {
        $quota = creation_quota::check_general_quota($courseid, $userid, $isteacher);
        if ($quota['allowed']) {
            $quota = creation_quota::check_section_quota($courseid, $userid, (int)$resource['sectionnum']);
        }
        if (!$quota['allowed']) {
            // $quota['reason'] es texto escrito por nosotros (creation_quota), apto para la persona.
            throw new \block_pulso\pulso_error('quota', (string)$quota['reason'], 400);
        }

        $encargoid = creation_quota::record_encargo(
            $courseid,
            $cmid,
            (int)$resource['sectionnum'],
            $userid,
            $tool,
            $prompt,
            $format
        );
    } finally {
        if ($lock) {
            $lock->release();
        }
    }

    echo json_encode([
        'success' => true,
        'encargoid' => $encargoid,
        'message' => 'Encargo guardado. En cuanto Pulse pueda enviarlo a generación, te avisaremos aquí.',
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    // Texto para persona + error_code; el detalle tecnico va solo a error_log.
    \block_pulso\pulso_error::send_json($e, 'api_create_submit', $courseid);
}
