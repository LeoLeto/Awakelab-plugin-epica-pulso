<?php
/**
 * AJAX endpoint: catálogo de prompts preestablecidos del chat y lo que se puede elegir en este curso
 * (v2.14.0).
 *
 * Devuelve, para el rol de quien pregunta, la lista de prompts (con las palabras por las que se
 * sugieren mientras se escribe), los desplegables de valores fijos y las unidades, recursos con texto,
 * cuestionarios y actividades que ESTE usuario ve. No llama al LLM ni a Épica: solo lee Moodle.
 *
 * El chat lo pide una vez, al abrirse; ninguna página depende de que conteste (sin él, el chat sigue
 * siendo de texto libre).
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');
require_once(__DIR__ . '/classes/chat_presets.php');

use block_pulso\chat_pipeline;
use block_pulso\chat_presets;
use block_pulso\pulso_error;

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

    $isteacher = chat_pipeline::user_can_view_analytics($courseid);

    // Solo lecturas: se suelta el lock de sesión para no frenar las demás pestañas.
    \core\session\manager::write_close();

    echo json_encode([
        'success' => true,
        'groups' => chat_presets::GROUPS,
        'presets' => chat_presets::catalog($isteacher),
        'choices' => chat_presets::choices(),
        'options' => chat_presets::course_options($courseid),
        'topicmax' => chat_presets::MAX_TOPIC_CHARS,
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    // Texto para persona + error_code; el detalle técnico va solo a error_log.
    pulso_error::send_json($e, 'api_presets', $courseid);
}
