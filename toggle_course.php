<?php
/**
 * AJAX endpoint to toggle Pulso enable/disable per course
 * Task T2.6.1: Implement per-course enable/disable toggle
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/pulso_error.php');

$courseid = required_param('courseid', PARAM_INT);
$enabled = required_param('enabled', PARAM_INT);

header('Content-Type: application/json; charset=utf-8');

try {
    \block_pulso\pulso_error::require_session();
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    require_login($course);
    // Endpoint de ESCRITURA (set_config): sin sesskey, un CSRF podia activar o
    // desactivar Pulso en cualquier curso donde la victima pudiera editar.
    \block_pulso\pulso_error::require_sesskey();
    require_capability('moodle/course:update', $context);

    // Sanitize: only 0 or 1
    $enabled = $enabled ? 1 : 0;

    // Store in mdl_config_plugins per course context
    set_config('enabled_course_' . $courseid, $enabled, 'block_pulso');

    echo json_encode([
        'success' => true,
        'courseid' => $courseid,
        'enabled' => $enabled,
        'message' => $enabled ? 'Pulso enabled for this course' : 'Pulso disabled for this course',
    ], JSON_UNESCAPED_UNICODE);

} catch (\Throwable $e) {
    \block_pulso\pulso_error::send_json($e, 'toggle_course', $courseid);
}
