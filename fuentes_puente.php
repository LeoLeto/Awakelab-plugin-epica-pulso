<?php
/**
 * «Material para Pulse» (carta 17): lleva al PROFESORADO a la pantalla /fuentes de Épica, donde sube
 * apuntes, documentos y vídeos que Pulse usa al responder.
 *
 * Firma un token NUEVO de docente y devuelve una página mínima que autoenvía un <form method="post"> a
 * epica_client::endpoint('/api/auth/moodle') (el puente de docentes del Épica de Pulse) con token,
 * herramienta=fuentes y curso_nombre. El token viaja en el CUERPO de un POST del navegador: nunca en una URL, nunca en un log,
 * nunca por el JS de Pulse. Mismo patrón y mismas cabeceras que epica_historial.php.
 *
 * Solo profesorado: abre la pantalla donde se sube y se archiva, y Épica exige `docente` en ese puente.
 * El rol sale de `block/pulso:viewanalytics` en contexto de CURSO (nunca `createactivity`: con ella todo
 * el alumnado saldría firmado como docente).
 *
 * Orden: POST → autenticar → sesskey → viewanalytics → Épica disponible → precondiciones → firmar.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/epica_client.php');

use block_pulso\epica_client;

/**
 * Página mínima, sin el tema de Moodle.
 *
 * @param string $title Titulo de la pagina (texto plano).
 * @param string $bodyhtml HTML ya escapado del cuerpo.
 * @param int $status Codigo HTTP.
 */
function pulso_fuentes_page(string $title, string $bodyhtml, int $status = 200): void {
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
    pulso_fuentes_page('Material para Pulse', '<p>Método no permitido.</p>', 405);
}

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);

require_login($course);
require_sesskey();
// Solo profesorado, y no solo «con la UI»: la fila del bloque no es control de acceso.
require_capability('block/pulso:viewanalytics', $context);

if (!epica_client::disponible()) {
    pulso_fuentes_page('Material para Pulse', '<p>' . s(epica_client::MENSAJE_NO_DISPONIBLE) . '</p>', 503);
}

global $USER;
$user = \core_user::get_user((int)$USER->id, '*', MUST_EXIST);

$motivo = epica_client::precondiciones_error($user);
if ($motivo !== null) {
    pulso_fuentes_page('Material para Pulse', '<p>' . s($motivo) . '</p>', 503);
}

try {
    // El puente de docentes, sobre el epica_base_url de Pulse (endpoint() exige https://: el token
    // no puede viajar sin cifrar). Nunca epica::auth_url(), que apunta al Épica de local_awkepica.
    $urlpuente = epica_client::endpoint('/api/auth/moodle');
    // Token nuevo justo ahora: vale 120 s y un solo uso.
    $token = \local_awkepica\epica::firmar_por($user, $context, epica_client::CAPABILITY_ROL, (string)$course->id);
} catch (\Throwable $e) {
    // Nunca el token ni el mensaje técnico hacia el navegador; solo la clase al log.
    error_log('Pulso fuentes: fallo al firmar: ' . get_class($e));
    pulso_fuentes_page('Material para Pulse',
        '<p>No hemos podido abrir Épica ahora mismo. Cierra esta pestaña e inténtalo de nuevo en unos minutos.</p>', 503);
}

$texto = 'Abriendo el material para Pulse en Épica…';

pulso_fuentes_page($texto,
    '<form id="fuentes" method="post" action="' . s($urlpuente) . '">'
    . '<input type="hidden" name="token" value="' . s($token) . '">'
    . '<input type="hidden" name="herramienta" value="fuentes">'
    . '<input type="hidden" name="curso_nombre" value="' . s(format_string($course->fullname)) . '">'
    . '<p>' . s($texto) . '</p>'
    . '<noscript><button type="submit">Continuar a Épica</button></noscript>'
    . '</form>'
    . '<script>document.getElementById("fuentes").submit();</script>');
