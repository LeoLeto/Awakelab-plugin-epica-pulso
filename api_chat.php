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
require_once(__DIR__ . '/classes/chat_rate_limiter.php');

use block_pulso\chat_pipeline;
use block_pulso\chat_rate_limiter;
use block_pulso\anthropic_connector;
use block_pulso\system_prompt_designer;
use block_pulso\pulso_error;

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

    // Una sesion caducada es lo primero que hay que decir, antes de cualquier
    // otro fallo (curso, pregunta vacia...): el cliente enseña «Tu sesion ha
    // caducado» y no reintenta.
    pulso_error::require_session();

    if (empty($courseid) || $courseid <= 0) {
        throw new pulso_error('bad_request');
    }
    if (empty($user_query)) {
        throw new pulso_error('bad_request');
    }

    $course = get_course($courseid);

    // Orden obligatorio: autenticar -> validar sesskey -> permisos -> estado del
    // plugin (mismo criterio que api_chat_stream.php). require_sesskey() cierra
    // el CSRF: sin el, una web externa podia disparar consultas —y gasto de
    // tokens— con la sesion del profesor.
    require_login($course);
    pulso_error::require_sesskey();

    // T2.6.2: Verificar permisos del usuario. El permiso mínimo es 'usechat'
    // (preguntas de contenido, lo tienen los alumnos); la analítica exige
    // 'viewanalytics'.
    $context = context_course::instance($courseid);
    if (!has_capability('block/pulso:usechat', $context)) {
        throw new pulso_error('access', '', 403);
    }
    $isteacher = chat_pipeline::user_can_view_analytics($courseid);

    // T2.6.1: Verificar si Pulso está habilitado para este curso.
    chat_pipeline::check_enabled($courseid);

    // Tope por persona (minuto y día), ANTES de contexto, RAG y Anthropic.
    chat_rate_limiter::check((int)$USER->id);

    // Respuesta + RAG + Anthropic pueden pasar de max_execution_time (30 s por defecto).
    core_php_time_limit::raise(180);

    // Modo alumno: las preguntas de analítica se cortan AQUÍ, antes de leer
    // contexto, RAG o llamar a Anthropic. Ni un dato ni un token.
    if (!$isteacher && chat_pipeline::is_teacher_only_query($user_query)) {
        $refusal = chat_pipeline::teacher_only_refusal($user_query);
        echo json_encode([
            'success' => true,
            'message' => 'Consulta de analítica bloqueada por rol (modo alumno)',
            'answer' => $refusal['answer'],
            'tokens_used' => 0,
            'model' => 'role-restricted',
            'schema_valid' => true,
            'schema_data' => $refusal['schema_data'],
            'rag_diagnostics' => [],
            'followup_questions' => $refusal['followup_questions'],
            'history_length' => 0,
            'course_id' => $courseid,
            'course_name' => $course->fullname,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ============================================================
    // 1. CONTEXTO DEL CURSO (con cache corta) + RAG + HISTORIAL
    // ============================================================

    // Historial primero: es lo único que lee $SESSION (el filtro de contradicciones RAG
    // se aplica después, con el contexto ya calculado).
    $history = chat_pipeline::prepare_history($courseid, $conversation_history, '');

    // Liberar el lock de sesión de Moodle ANTES de contexto, RAG y Anthropic: el RAG
    // llama a OpenAI y la IA tarda segundos; sin esto el resto de páginas/pestañas del
    // usuario quedan bloqueadas mientras tanto.
    \core\session\manager::write_close();

    $course_context = chat_pipeline::get_course_context($courseid);

    $rag = chat_pipeline::get_rag($courseid, $user_query);
    $rag_context = $rag['context'];
    // Solo viaja al cliente con viewanalytics (lleva nombres de fragmentos y el
    // estado interno del indice).
    $rag_diagnostics = chat_pipeline::client_rag_diagnostics($rag['diagnostics'], $isteacher);
    $history = chat_pipeline::drop_no_access_replies($history, $rag_context);

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
    // 3. LLAMAR A ANTHROPIC CON CONTEXTO DINÁMICO
    // ============================================================

    // Bloques (no string): el prompt base va marcado con cache_control — ver
    // system_prompt_designer::generate_system_blocks().
    $system_prompt = system_prompt_designer::generate_system_blocks(
        $course_context,
        $rag_context,
        $isteacher,
        $user_query
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
        throw new \moodle_exception('error_empty_response', 'block_pulso', '', '', 'Empty response from Anthropic connector');
    }

    // ============================================================
    // 4. PARSEAR Y VALIDAR RESPUESTA JSON
    // ============================================================

    // Diagnóstico opcional: volcar la respuesta CRUDA del modelo antes de limpiarla.
    // clean_answer() extrae el objeto JSON balanceado, así que cualquier frase que el
    // modelo escriba FUERA del objeto desaparece aquí sin dejar rastro — es la única
    // forma de distinguir "el modelo no ofreció nada" de "ofreció algo y el pipeline
    // se lo comió". Se activa con `$CFG->block_pulso_log_raw_answer = true;` en
    // config.php. Va a error_log y NO a debugging(): en api_chat_stream.php cualquier
    // salida en el cuerpo rompería el SSE, y este log tiene que ser simétrico.
    if (!empty($CFG->block_pulso_log_raw_answer)) {
        error_log('[pulso raw][xhr] ' . $ai_response['answer']);
    }

    $answer = chat_pipeline::clean_answer($ai_response['answer']);
    $schema_data = system_prompt_designer::validate_response($answer);

    // ============================================================
    // 5. GENERAR PREGUNTAS DE SEGUIMIENTO (T2.4.12)
    // ============================================================

    // max_tokens: respuesta cortada. Se avisa al cliente, no se guarda en el historial
    // y no se piden sugerencias (sugerirían sobre algo a medias).
    $truncated = !empty($ai_response['truncated']);

    $followup_questions = [];
    try {
        if ($truncated) {
            throw new \RuntimeException('truncated');
        }
        $followup_questions = $connector->generate_followup_questions(
            $user_query,
            $answer,
            $course_context,
            $isteacher
        );
        if (!$isteacher) {
            $followup_questions = chat_pipeline::filter_student_followups($followup_questions);
        }
    } catch (Exception $e) {
        // Si falla la generación de preguntas, continuar sin ellas.
        if ($e->getMessage() !== 'truncated') {
            error_log('Follow-up questions generation failed: ' . $e->getMessage());
        }
    }

    // ============================================================
    // 6. GUARDAR HISTORIAL Y RETORNAR RESPUESTA
    // ============================================================

    if (!$truncated) {
        $history = chat_pipeline::save_history($courseid, $history, $user_query, $answer);
    }

    $response = [
        'success' => true,
        'truncated' => $truncated,
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
    // Texto para persona + error_code estable (el cliente elige el texto por el
    // codigo, nunca por el mensaje). El detalle tecnico va solo a error_log.
    [$status, $payload] = pulso_error::to_response($e, 'api_chat', (int)$courseid);
    http_response_code($status);
    $response = $payload + ['answer' => null, 'tokens_used' => 0];
}

// Enviar respuesta JSON
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;
