<?php
/**
 * Sirve el HTML de un juego de Gamificación (Épica) de forma SEGURA — la
 * ÚNICA vía para hacerlo (paso 2, v1.22.0). block_pulso_pluginfile() (lib.php)
 * sigue bloqueando la filearea "juego" a propósito: el HTML lo escribió un
 * modelo a partir de material del curso, y `send_stored_file()` tal cual lo
 * ejecutaría con el origen de Moodle y la sesión de quien lo abra.
 *
 * Este endpoint NUNCA sirve el fichero sin tocarlo: inyecta la CSP en un
 * <meta> (para el caso en que algo renderice el body sin llegar a leer las
 * cabeceras) y manda además la CSP real en la cabecera HTTP con "sandbox
 * allow-scripts" — eso es lo que aísla el juego incluso si alguien abre esta
 * URL directamente en una pestaña, fuera del iframe de juego.php: el
 * navegador le da un origen opaco igual (docs/epica_gamificacion_carta6.md
 * §A4, §B2).
 *
 * Sin sesskey: es la `src` de un <iframe>, una petición GET idempotente y sin
 * efectos secundarios, igual que cualquier otro recurso enlazado.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/creation_quota.php');

use block_pulso\creation_quota;

/**
 * Inserta la meta CSP como PRIMER hijo de <head>, antes de cualquier
 * <script> del juego. Si no hay <head>, se crea justo después de <html>; si
 * tampoco hay <html>, se antepone a todo el documento. Búsqueda case
 * insensitive (mb_stripos) y cortes por CARACTERES (mb_substr), nunca por
 * bytes -el HTML puede traer acentos/UTF-8 real.
 */
function pulso_juego_inject_csp_meta(string $html): string {
    $meta = '<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; '
        . 'script-src \'unsafe-inline\'; style-src \'unsafe-inline\'; img-src data: blob:; '
        . 'font-src data:; media-src data: blob:">' . "\n";

    $headpos = mb_stripos($html, '<head');
    if ($headpos !== false) {
        $tagend = mb_strpos($html, '>', $headpos);
        if ($tagend !== false) {
            $insertat = $tagend + 1;
            return mb_substr($html, 0, $insertat) . $meta . mb_substr($html, $insertat);
        }
    }

    $htmlpos = mb_stripos($html, '<html');
    if ($htmlpos !== false) {
        $tagend = mb_strpos($html, '>', $htmlpos);
        if ($tagend !== false) {
            $insertat = $tagend + 1;
            return mb_substr($html, 0, $insertat) . "<head>\n" . $meta . "</head>\n" . mb_substr($html, $insertat);
        }
    }

    return $meta . $html;
}

/**
 * Quita un bloque PULSO_PLATFORM_BRIDGE_START…END si el HTML ya trajera uno
 * -no debería, Épica lo entrega limpio (docs/epica_gamificacion_carta6.md
 * §A1)-, para no dejar dos puentes duplicados en la página servida.
 */
function pulso_juego_strip_existing_bridge(string $html): string {
    $startmarker = '<!-- PULSO_PLATFORM_BRIDGE_START -->';
    $endmarker = '<!-- PULSO_PLATFORM_BRIDGE_END -->';

    $start = mb_stripos($html, $startmarker);
    if ($start === false) {
        return $html;
    }
    $end = mb_stripos($html, $endmarker, $start);
    if ($end === false) {
        return $html;
    }
    $endpos = $end + mb_strlen($endmarker);
    return mb_substr($html, 0, $start) . mb_substr($html, $endpos);
}

/**
 * Inyecta el puente de puntuación justo antes de </body> (al final si no
 * hay). El nombre de la función global (window.reportAwakegameScore) es el
 * contrato con el ARTEFACTO y no se toca (docs/epica_gamificacion_carta6.md
 * §B2); el envío a '*' y la validación por event.source en juego.php son la
 * pareja de este puente -dentro del sandbox el origen es "null", así que
 * validar por origen no serviría de nada.
 */
function pulso_juego_inject_bridge(string $html): string {
    $html = pulso_juego_strip_existing_bridge($html);

    $bridge = "<!-- PULSO_PLATFORM_BRIDGE_START -->\n"
        . "<script>\n"
        . "window.reportAwakegameScore = function (score, maxscore) {\n"
        . "  try {\n"
        . "    window.parent.postMessage({ type: 'pulso-score', score: Number(score), maxscore: Number(maxscore) }, '*');\n"
        . "  } catch (e) {}\n"
        . "};\n"
        . "</script>\n"
        . "<!-- PULSO_PLATFORM_BRIDGE_END -->\n";

    $bodyendpos = mb_strripos($html, '</body>');
    if ($bodyendpos !== false) {
        return mb_substr($html, 0, $bodyendpos) . $bridge . mb_substr($html, $bodyendpos);
    }

    return $html . $bridge;
}

$id = required_param('id', PARAM_INT);

global $DB, $USER;

// Orden: existe -> require_login (necesita el curso, que solo conocemos tras
// leer la fila) -> acceso (dueño o viewanalytics) -> tool/status. Los dos
// últimos dan el MISMO 404 para no distinguir "no es tuyo" de "no es un
// juego listo" a quien no tiene acceso de todas formas.
$encargo = $DB->get_record('block_pulso_encargos', ['id' => $id]);
if (!$encargo) {
    send_file_not_found();
}

$course = get_course((int)$encargo->courseid);
require_login($course);

$context = context_course::instance((int)$course->id);
$isowner = (int)$encargo->userid === (int)$USER->id;
$isteacher = has_capability('block/pulso:viewanalytics', $context);
if (!$isowner && !$isteacher) {
    send_file_not_found();
}

if ($encargo->tool !== creation_quota::TOOL_GAMIFICACION || $encargo->status !== 'listo' || empty($encargo->filename)) {
    send_file_not_found();
}

$fs = get_file_storage();
$file = $fs->get_file($context->id, 'block_pulso', 'juego', $encargo->id, '/', $encargo->filename);
if (!$file || $file->is_directory()) {
    send_file_not_found();
}

$html = $file->get_content();
$html = pulso_juego_inject_csp_meta($html);
$html = pulso_juego_inject_bridge($html);

// El "sandbox" en esta cabecera (no disponible en la meta http-equiv) es lo
// que aísla el juego con origen opaco aunque alguien abra esta URL directa,
// fuera del iframe de juego.php.
header("Content-Security-Policy: sandbox allow-scripts; default-src 'none'; "
    . "script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; "
    . "font-src data:; media-src data: blob:");
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

echo $html;
