<?php
/**
 * API Endpoint: Chat Query Processing
 *
 * Recibe query del usuario y retorna respuesta de Claude (Anthropic)
 * con contexto del curso inyectado dinámicamente.
 *
 * Endpoint clásico (no streaming). El cliente usa api_chat_stream.php por
 * defecto y cae en este endpoint si el streaming no está disponible.
 * La lógica compartida vive en classes/chat_pipeline.php.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Permitir AJAX
define('AJAX_SCRIPT', true);

// Incluir Moodle config
require_once(__DIR__ . '/../../config.php');

// Incluir clases necesarias
require_once(__DIR__ . '/classes/data_retriever.php');
require_once(__DIR__ . '/classes/anthropic_connector.php');
require_once(__DIR__ . '/classes/system_prompt_designer.php');
require_once(__DIR__ . '/classes/rag_retriever.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');

use block_pulso\chat_pipeline;
use block_pulso\anthropic_connector;
use block_pulso\system_prompt_designer;

$courseid = optional_param('courseid', 0, PARAM_INT);
// PARAM_RAW no acota longitud: el tope real se aplica en servidor.
$user_query = chat_pipeline::sanitize_query(optional_param('user_query', '', PARAM_RAW));
$conversation_history = optional_param('conversation_history', '[]', PARAM_RAW);

// Headers JSON
header('Content-Type: application/json; charset=utf-8');

// Respuesta por defecto
$response = [
    'success' => false,
    'message' => 'Error desconocido',
    'answer' => null,
    'tokens_used' => 0
];

try {
    // ============================================================
    // VALIDACIONES BÁSICAS
    // ============================================================

    if (empty($courseid) || $courseid <= 0) {
        throw new Exception('Invalid courseid provided');
    }
    if (empty($user_query)) {
        throw new Exception('Empty query provided');
    }

    $course = get_course($courseid);
    if (!$course) {
        throw new Exception('Course not found');
    }

    // Orden obligatorio: autenticar -> validar sesskey -> permisos -> estado del
    // plugin (mismo criterio que api_chat_stream.php). require_sesskey() cierra
    // el CSRF: sin el, una web externa podia disparar consultas —y gasto de
    // tokens— con la sesion del profesor.
    require_login($course);
    require_sesskey();

    // T2.6.2: Verificar permisos del usuario.
    $context = context_course::instance($courseid);
    if (!has_capability('block/pulso:viewanalytics', $context)) {
        throw new Exception('You do not have permission to use analytics');
    }

    // T2.6.1: Verificar si Pulso está habilitado para este curso.
    chat_pipeline::check_enabled($courseid);

    // ============================================================
    // 1. CONTEXTO DEL CURSO (con cache corta) + RAG + HISTORIAL
    // ============================================================

    $course_context = chat_pipeline::get_course_context($courseid);

    $rag = chat_pipeline::get_rag($courseid, $user_query);
    $rag_context = $rag['context'];
    $rag_diagnostics = $rag['diagnostics'];

    $history = chat_pipeline::prepare_history($courseid, $conversation_history, $rag_context);

    // Liberar el lock de sesión de Moodle ANTES de las llamadas a OpenAI:
    // sin esto, el resto de páginas/pestañas del usuario quedan bloqueadas
    // mientras se genera la respuesta.
    \core\session\manager::write_close();

    // ============================================================
    // 2. RUTA DIRECTA (respuestas resueltas desde Moodle)
    // ============================================================

    $qinfo = chat_pipeline::build_direct_query($user_query, $history);
    $direct = chat_pipeline::resolve_direct_answer($courseid, $user_query, $qinfo);

    if ($direct !== null) {
        $history = chat_pipeline::save_history($courseid, $history, $user_query, $direct['answer']);

        $response = [
            'success' => true,
            'message' => 'Query estructural/recurso resuelto directamente desde Moodle',
            'answer' => $direct['answer'],
            'tokens_used' => 0,
            'model' => 'direct-moodle-query',
            'schema_valid' => true,
            'schema_data' => $direct['schema_data'],
            'rag_diagnostics' => $rag_diagnostics,
            'followup_questions' => $direct['followup_questions'],
            'history_length' => count($history),
            'course_id' => $courseid,
            'course_name' => $course_context['course_context']['course_name'] ?? $course->fullname,
            'timestamp' => date('Y-m-d H:i:s')
        ];

        echo json_encode($response, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    // 3. LLAMAR A OPENAI CON CONTEXTO DINÁMICO
    // ============================================================

    // Bloques (no string): el prompt base va marcado con cache_control — ver
    // system_prompt_designer::generate_system_blocks().
    $system_prompt = system_prompt_designer::generate_system_blocks(
        $course_context,
        $rag_context
    );

    $connector = new anthropic_connector();
    $ai_response = $connector->send_analytics_query_with_schema(
        $user_query,
        $course_context,
        $history,
        3000, // max_tokens para respuesta JSON (con 800/2000 se truncaban rankings/listas largas)
        $system_prompt
    );

    if (!$ai_response) {
        throw new Exception('No response from OpenAI API');
    }

    // ============================================================
    // 4. PARSEAR Y VALIDAR RESPUESTA JSON
    // ============================================================

    $answer = chat_pipeline::clean_answer($ai_response['answer']);
    $schema_data = system_prompt_designer::validate_response($answer);

    // ============================================================
    // 5. GENERAR PREGUNTAS DE SEGUIMIENTO (T2.4.12)
    // ============================================================

    $followup_questions = [];
    try {
        $followup_questions = $connector->generate_followup_questions(
            $user_query,
            $answer,
            $course_context
        );
    } catch (Exception $e) {
        // Si falla la generación de preguntas, continuar sin ellas.
        error_log('Follow-up questions generation failed: ' . $e->getMessage());
    }

    // ============================================================
    // 6. GUARDAR HISTORIAL Y RETORNAR RESPUESTA
    // ============================================================

    $history = chat_pipeline::save_history($courseid, $history, $user_query, $answer);

    $response = [
        'success' => true,
        'message' => 'Query procesado exitosamente',
        'answer' => $answer,
        'tokens_used' => $ai_response['tokens_used'] ?? 0,
        // Métricas de prompt caching (ver api_chat_stream.php).
        'cache_read_input_tokens' => $ai_response['cache_read_input_tokens'] ?? 0,
        'cache_creation_input_tokens' => $ai_response['cache_creation_input_tokens'] ?? 0,
        'model' => $ai_response['model'] ?? 'claude-sonnet-5',
        'schema_valid' => $schema_data ? true : false,
        'schema_data' => $schema_data,
        'rag_diagnostics' => $rag_diagnostics,
        'followup_questions' => $followup_questions,
        'history_length' => count($history),
        'course_id' => $courseid,
        'course_name' => $course_context['course_context']['course_name'] ?? $course->fullname,
        'timestamp' => date('Y-m-d H:i:s')
    ];

} catch (\Throwable $e) {
    // Manejo de errores. El detalle técnico (debuginfo) va solo al log del
    // servidor, no al cliente.
    http_response_code(500);
    if ($e instanceof \moodle_exception && !empty($e->debuginfo)) {
        error_log('block_pulso api_chat error: ' . $e->debuginfo);
    }
    $response = [
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'answer' => null,
        'tokens_used' => 0,
        'error_code' => get_class($e)
    ];
}

// Enviar respuesta JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
