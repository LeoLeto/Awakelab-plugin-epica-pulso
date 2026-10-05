<?php
/**
 * «Mi historial» (carta 10): lleva al ALUMNO a su historial en Épica.
 *
 * Firma un token NUEVO de alumno y devuelve una página mínima que autoenvía un
 * <form method="post"> a /api/auth/alumno con ese único campo. El token viaja en
 * el CUERPO de un POST del navegador: nunca en una URL, nunca en un log, nunca
 * por el JS de Pulse. Épica contesta con un 303 a /mis-recursos.
 *
 * Solo alumnado: un token de docente en esa puerta es 403 en Épica, así que al
 * profesorado no se le firma nada (ver CLAUDE.md → «Historial del alumno»).
 *
 * Orden: POST → autenticar → sesskey → createactivity → check_enabled →
 * profesorado → precondiciones → firmar.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/epica_client.php');

use block_pulso\chat_pipeline;
use block_pulso\epica_client;

/**
 * Página mínima, sin el tema de Moodle.
 *
 * @param string $title Titulo de la pagina (texto plano).
 * @param string $bodyhtml HTML ya escapado del cuerpo.
 * @param int $status Codigo HTTP.
 */
function pulso_historial_page(string $title, string $bodyhtml, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    header('Referrer-Policy: no-referrer');
    echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="referrer" content="no-referrer">'
        . '<title>' . s($title) . '</title>'
        . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#F0F3FC;color:#27334F;'
        . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px;text-align:center}'
        . 'p{max-width:420px;line-height:1.5}button{background:#003670;color:#fff;border:0;border-radius:8px;'
        . 'padding:10px 18px;font-size:1rem;cursor:pointer}</style></head><body><div>' . $bodyhtml . '</div></body></html>';
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pulso_historial_page('Mi historial', '<p>Método no permitido.</p>', 405);
}

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_sesskey();
require_capability('block/pulso:createactivity', $context);
try {
    chat_pipeline::check_enabled($courseid);
} catch (\Exception $e) {
    pulso_historial_page('Mi historial', '<p>Pulso está desactivado en este curso.</p>', 403);
}

// Profesorado: la puerta de alumno de Épica contesta 403 a un token de docente.
if (chat_pipeline::user_can_view_analytics($courseid)) {
    pulso_historial_page('Mi historial',
        '<p>El historial de Épica es para el alumnado. El profesorado entra en Épica por su acceso habitual.</p>');
}

if (!class_exists('\local_awkepica\epica')) {
    pulso_historial_page('Mi historial', '<p>El servicio de Épica no está disponible en este sitio.</p>', 503);
}

global $USER;
$user = \core_user::get_user((int)$USER->id, '*', MUST_EXIST);

$motivo = epica_client::precondiciones_error($user);
if ($motivo !== null) {
    pulso_historial_page('Mi historial', '<p>' . s($motivo) . '</p>', 503);
}

try {
    // Red de seguridad: si el rol no sale «estudiante», no se firma (la puerta daria 403).
    if (\local_awkepica\epica::rol_de(epica_client::CAPABILITY_ROL, $context, $user) !== 'estudiante') {
        pulso_historial_page('Mi historial',
            '<p>El historial de Épica es para el alumnado. El profesorado entra en Épica por su acceso habitual.</p>');
    }
    $destino = epica_client::endpoint('/api/auth/alumno');
    // Token nuevo justo ahora: vale 120 s y un solo uso.
    $token = \local_awkepica\epica::firmar_por($user, $context, epica_client::CAPABILITY_ROL, (string)$course->id);
} catch (\Throwable $e) {
    // Nunca el token ni el mensaje técnico hacia el navegador; clase y mensaje al log.
    error_log('Pulso historial: fallo al firmar: ' . get_class($e) . ': ' . $e->getMessage());
    pulso_historial_page('Mi historial',
        '<p>No hemos podido abrir tu historial en Épica ahora mismo. Cierra esta pestaña e inténtalo de nuevo en unos minutos.</p>', 503);
}

pulso_historial_page('Abriendo tu historial en Épica…',
    '<form id="historial" method="post" action="' . s($destino) . '">'
    . '<input type="hidden" name="token" value="' . s($token) . '">'
    . '<p>Abriendo tu historial en Épica…</p>'
    . '<noscript><button type="submit">Continuar a Épica</button></noscript>'
    . '</form>'
    . '<script>document.getElementById("historial").submit();</script>');
