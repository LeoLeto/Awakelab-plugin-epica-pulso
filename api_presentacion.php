<?php
/**
 * AJAX endpoint (POST): «Presentación» de la Factoría de Épica empezada en Pulse (carta 18). Deja el
 * borrador (contenido o tema) en Épica y devuelve su código; el navegador entra después por
 * fuentes_puente.php (herramienta=factoria). Ver classes/presentacion_service.php.
 *
 * Orden: autenticar -> sesskey -> viewanalytics (solo profesorado) -> estado del plugin -> Épica
 * disponible -> campos -> el cmid se recalcula SIEMPRE contra creation_quota::get_resources_context()
 * (visible de verdad para este usuario y con texto aprovechable), nunca se acepta tal cual.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/creation_quota.php');
require_once(__DIR__ . '/classes/epica_client.php');
require_once(__DIR__ . '/classes/presentacion_service.php');

use block_pulso\chat_pipeline;
use block_pulso\creation_quota;
use block_pulso\epica_client;
use block_pulso\presentacion_service;
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
    // Solo profesorado, y no solo «con la UI»: la fila del bloque no es control de acceso. Es la misma
    // capability con la que se firma el token (nunca createactivity: ver CLAUDE.md, paso 3 de Épica).
    require_capability('block/pulso:viewanalytics', $context);
    chat_pipeline::check_enabled($courseid);

    if (!epica_client::disponible()) {
        throw new pulso_error('unavailable', epica_client::MENSAJE_NO_DISPONIBLE, 503);
    }

    global $USER;
    $userid = (int)$USER->id;

    // Cadenas libres: se leen sin limpiar y las valida el servicio (mb_*, UTF-8 sano, longitudes).
    $cmid = optional_param('cmid', 0, PARAM_INT);
    $tema = optional_param('tema', '', PARAM_RAW);
    $titulo = optional_param('titulo', '', PARAM_RAW);
    $diapparam = optional_param('diapositivas', '', PARAM_RAW);
    $diapositivas = null;
    if (trim($diapparam) !== '') {
        if (!preg_match('/^\d{1,3}$/', trim($diapparam))) {
            throw new pulso_error('bad_request', get_string('pres_err_diapositivas', 'block_pulso',
                ['min' => presentacion_service::DIAP_MIN, 'max' => presentacion_service::DIAP_MAX]));
        }
        $diapositivas = (int)trim($diapparam);
    }

    // Validar antes de gastar nada.
    presentacion_service::validar_campos($cmid, $tema, $titulo, $diapositivas);

    if ($cmid > 0) {
        $ctx = creation_quota::get_resources_context($courseid, $userid, false);
        $found = false;
        foreach ($ctx['resources'] as $candidate) {
            if ((int)$candidate['cmid'] === $cmid) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            throw new pulso_error('bad_request', get_string('pres_err_sin_texto', 'block_pulso'));
        }
    }

    // Como el chat: soltar el candado de sesion antes de las llamadas externas (Haiku puede tardar
    // decenas de segundos) para no congelar las demas pestañas del usuario.
    \core\session\manager::write_close();
    core_php_time_limit::raise(120);

    $result = presentacion_service::preparar($courseid, $userid, $cmid, $tema, $titulo, $diapositivas);

    echo json_encode([
        'success' => true,
        'borrador' => $result['borrador'],
        'caduca' => $result['caduca'],
        'forma' => $result['forma'],
        'titulo' => $result['titulo'],
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

} catch (\Throwable $e) {
    // Texto para persona + error_code; el detalle tecnico va solo a error_log.
    pulso_error::send_json($e, 'api_presentacion', $courseid);
}
