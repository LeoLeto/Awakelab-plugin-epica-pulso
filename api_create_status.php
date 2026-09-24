<?php
/**
 * AJAX endpoint: estado de UN encargo de creación (Epica), o los últimos
 * encargos del usuario en el curso (galería). Paso 4 de la integración: el
 * panel sondea este endpoint — nunca a Epica directamente, eso lo hace solo
 * la tarea adhoc (classes/epica_client.php).
 *
 * GET params:
 *   courseid  (obligatorio)
 *   encargoid (opcional): con él, detalle de UN encargo; sin él, los últimos
 *             encargos de ESTE usuario en el curso.
 *
 * Nunca se devuelve el base64 de la imagen: solo la URL de pluginfile.php,
 * que ya valida el acceso ella misma (block_pulso_pluginfile() en lib.php).
 * Mismo control de acceso aquí: dueño del encargo o quien tenga
 * 'block/pulso:viewanalytics' en el curso.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');

use block_pulso\chat_pipeline;

$courseid = required_param('courseid', PARAM_INT);
$encargoid = optional_param('encargoid', 0, PARAM_INT);

header('Content-Type: application/json; charset=utf-8');

/**
 * Payload de UN encargo para el cliente. "sobre" (el envelope del modo de
 * ensayo) solo viaja a quien tiene viewanalytics: es la señal de
 * verificación contra el contrato de Epica, no algo que un alumno necesite
 * ver. El resto de campos son seguros para cualquiera con acceso al encargo.
 */
function pulso_status_encargo_payload(stdClass $encargo, context_course $context, bool $canviewanalytics): array {
    $terminal = in_array($encargo->status, ['listo', 'fallado', 'desconocido', 'ensayo'], true);

    $imageurl = null;
    $downloadurl = null;
    if ($encargo->status === 'listo' && !empty($encargo->filename)) {
        $imageurl = (string)moodle_url::make_pluginfile_url(
            $context->id, 'block_pulso', 'encargo', $encargo->id, '/', $encargo->filename, false
        );
        $downloadurl = (string)moodle_url::make_pluginfile_url(
            $context->id, 'block_pulso', 'encargo', $encargo->id, '/', $encargo->filename, true
        );
    }

    $avisos = null;
    if (!empty($encargo->avisos)) {
        $decoded = json_decode($encargo->avisos, true);
        $avisos = is_array($decoded) ? $decoded : null;
    }

    $sobre = null;
    if ($canviewanalytics && $encargo->status === 'ensayo' && !empty($encargo->sobre_json)) {
        $decoded = json_decode($encargo->sobre_json, true);
        $sobre = is_array($decoded) ? $decoded : null;
    }

    // Mientras el encargo NO es terminal, "motivo" puede llevar el último
    // error de red del reintento (epica_client::manejar_excepcion_transporte()),
    // un detalle técnico que solo interesa a quien tiene viewanalytics — no
    // al alumno dueño del encargo, que vería un mensaje de excepción sin
    // sentido para él. Una vez terminal, motivo es la razón real del fallo y
    // se enseña igual que siempre (a dueño o viewanalytics).
    $motivo = null;
    if ($encargo->motivo !== null && $encargo->motivo !== '') {
        $motivo = ($terminal || $canviewanalytics) ? $encargo->motivo : null;
    }

    return [
        'id' => (int)$encargo->id,
        'status' => $encargo->status,
        'terminal' => $terminal,
        'posicion' => isset($encargo->epica_posicion) && $encargo->epica_posicion !== null ? (int)$encargo->epica_posicion : null,
        'format' => $encargo->format,
        'titulo' => $encargo->titulo !== null && $encargo->titulo !== '' ? $encargo->titulo : null,
        'tema' => $encargo->tema !== null && $encargo->tema !== '' ? $encargo->tema : null,
        'mock' => !empty($encargo->mock),
        'verificado' => isset($encargo->verificado) && $encargo->verificado !== null ? (bool)$encargo->verificado : null,
        'avisos' => $avisos,
        'motivo' => $motivo,
        'imageurl' => $imageurl,
        'downloadurl' => $downloadurl,
        'sobre' => $sobre,
        'timecreated' => (int)$encargo->timecreated,
        'timemodified' => (int)$encargo->timemodified,
    ];
}

try {
    $course = get_course($courseid);
    $context = context_course::instance($courseid);

    // Mismo orden que los demás endpoints del plugin: autenticar -> sesskey
    // -> permisos -> estado del plugin.
    require_login($course);
    require_sesskey();
    require_capability('block/pulso:createactivity', $context);
    chat_pipeline::check_enabled($courseid);

    global $DB, $USER;
    $userid = (int)$USER->id;
    $canviewanalytics = chat_pipeline::user_can_view_analytics($courseid);

    if ($encargoid > 0) {
        $encargo = $DB->get_record('block_pulso_encargos', ['id' => $encargoid]);
        if (!$encargo || (int)$encargo->courseid !== $courseid) {
            throw new \Exception('Ese encargo no existe.');
        }

        $isowner = (int)$encargo->userid === $userid;
        if (!$isowner && !$canviewanalytics) {
            throw new \Exception('No tienes acceso a ese encargo.');
        }

        echo json_encode([
            'success' => true,
            'encargo' => pulso_status_encargo_payload($encargo, $context, $canviewanalytics),
        ], JSON_UNESCAPED_UNICODE);
    } else {
        // Galería: solo LOS PROPIOS encargos del usuario en este curso, más
        // recientes primero. No es un listado de todo el curso (eso seguiría
        // exigiendo viewanalytics por fila, no por vista completa).
        $rows = $DB->get_records_select(
            'block_pulso_encargos',
            'courseid = :courseid AND userid = :userid',
            ['courseid' => $courseid, 'userid' => $userid],
            'timecreated DESC',
            '*',
            0,
            8
        );

        $encargos = [];
        foreach ($rows as $row) {
            $encargos[] = pulso_status_encargo_payload($row, $context, $canviewanalytics);
        }

        echo json_encode(['success' => true, 'encargos' => $encargos], JSON_UNESCAPED_UNICODE);
    }

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
