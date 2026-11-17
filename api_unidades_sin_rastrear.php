<?php
/**
 * AJAX endpoint: unidades del curso que Pulse no puede leer (v2.17.0), para el aviso del profesorado.
 *
 * Una unidad está «sin rastrear» cuando, tras indexar el curso, ninguno de sus módulos de contenido salió
 * con texto (unit_map::untracked(): un SCORM que no se deja, vídeos, enlaces) y tampoco tiene material
 * extra subido para ella en Épica (fuentes_service::unidades_con_material(), el `unidad_curso` del índice).
 * Cada una lleva su botón, que entra en Fuentes de Épica para esa unidad (fuentes_puente.php con `unidad`).
 *
 * Solo profesorado (viewanalytics): al alumnado se le contesta una lista vacía, sin error. Y solo si hay
 * adónde mandar a nadie: sin Épica configurada o con Fuentes sin contratar, lista vacía también.
 *
 * El chat lo pide una vez al abrirse, aparte del catálogo de prompts: es lo único de la home que pregunta a
 * Épica (el índice, con su caché de 5 minutos y 1 s de espera), y ninguna página espera por él. Si Épica no
 * contesta, se avisa de lo que dice Moodle: mejor un aviso de más que esconder una unidad sin material.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/unit_map.php');
require_once(__DIR__ . '/classes/epica_client.php');
require_once(__DIR__ . '/classes/fuentes_service.php');

use block_pulso\chat_pipeline;
use block_pulso\epica_client;
use block_pulso\fuentes_service;
use block_pulso\pulso_error;
use block_pulso\unit_map;

$courseid = required_param('courseid', PARAM_INT);

header('Content-Type: application/json; charset=utf-8');

try {
    // Mismo orden que los demás endpoints del plugin: autenticar -> sesskey -> permisos -> estado.
    pulso_error::require_session();
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    pulso_error::require_sesskey();
    if (!has_capability('block/pulso:usechat', $context)) {
        throw new pulso_error('access', '', 403);
    }
    chat_pipeline::check_enabled($courseid);

    // Solo lecturas: se suelta el lock de sesión antes de esperar a Épica.
    \core\session\manager::write_close();

    $units = [];
    if (chat_pipeline::user_can_view_analytics($courseid) && epica_client::disponible() && !fuentes_service::no_contratada()) {
        $units = unit_map::untracked($courseid);
        if (!empty($units)) {
            $withmaterial = fuentes_service::unidades_con_material($courseid);
            if ($withmaterial !== null) {
                $units = array_values(array_filter($units, function (array $u) use ($withmaterial): bool {
                    return !in_array($u['key'], $withmaterial, true);
                }));
            }
            // El índice puede haber descubierto ahora que Fuentes no está contratada.
            if (fuentes_service::no_contratada()) {
                $units = [];
            }
        }
    }

    echo json_encode(['success' => true, 'units' => $units], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    // Texto para persona + error_code; el detalle técnico va solo a error_log.
    pulso_error::send_json($e, 'api_unidades_sin_rastrear', $courseid);
}
