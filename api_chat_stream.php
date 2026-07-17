<?php
/**
 * API Endpoint: Chat Query Processing — STREAMING (Server-Sent Events)
 *
 * Igual que api_chat.php pero transmite la respuesta de Claude (Anthropic)
 * token a token, para que el usuario vea la respuesta mientras se genera.
 *
 * Protocolo (eventos SSE):
 *   status    → {stage: 'retrieving'|'generating'}   progreso previo a la IA
 *   delta     → {text: '...'}                        fragmento de respuesta
 *   final     → {...}  mismo shape JSON que api_chat.php (respuesta completa)
 *   followups → {questions: [...]}                   sugerencias (diferidas)
 *   error     → {message: '...'}
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);
define('NO_OUTPUT_BUFFERING', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/data_retriever.php');
require_once(__DIR__ . '/classes/anthropic_connector.php');
require_once(__DIR__ . '/classes/system_prompt_designer.php');
require_once(__DIR__ . '/classes/rag_retriever.php');
require_once(__DIR__ . '/classes/chat_pipeline.php');

use block_pulso\chat_pipeline;
use block_pulso\anthropic_connector;
use block_pulso\system_prompt_designer;

$courseid = optional_param('courseid', 0, PARAM_INT);
$user_query = optional_param('user_query', '', PARAM_RAW);
$conversation_history = optional_param('conversation_history', '[]', PARAM_RAW);

// ============================================================
// FASE 1: Validaciones ANTES de abrir el stream SSE.
// Si algo falla aquí, respondemos JSON normal — el cliente detecta
// que no es text/event-stream y hace fallback al endpoint clásico.
// ============================================================
try {
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

    chat_pipeline::check_enabled($courseid);

    require_login($course);
    $context = context_course::instance($courseid);
    if (!has_capability('block/pulso:viewanalytics', $context)) {
        throw new Exception('You do not have permission to use analytics');
    }
} catch (\Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage(),
        'answer' => null
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// FASE 2: Abrir el stream SSE.
// ============================================================
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no'); // nginx: no bufferizar esta respuesta.
@ini_set('zlib.output_compression', '0');
while (ob_get_level() > 0) {
    @ob_end_flush();
}

/**
 * Emitir un evento SSE y forzar el envío inmediato al navegador.
 *
 * @param string $event
 * @param mixed $data
 */
function pulso_sse(string $event, $data): void {
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) {
        @ob_flush();
    }
    flush();
}

// Relleno inicial: fuerza a proxies/FastCGI con buffer a empezar a
// entregar el stream inmediatamente (las líneas ':' son comentarios SSE).
echo ':' . str_repeat(' ', 2048) . "\n\n";
if (ob_get_level() > 0) {
    @ob_flush();
}
flush();

try {
    // Primer byte inmediato: el cliente sabe que el stream está vivo.
    pulso_sse('status', ['stage' => 'retrieving']);

    // 1. Contexto de analítica del curso (con cache corta).
    $course_context = chat_pipeline::get_course_context($courseid);

    // 2. RAG: fragmentos relevantes del contenido del curso.
    $rag = chat_pipeline::get_rag($courseid, $user_query);
    $rag_context = $rag['context'];
    $rag_diagnostics = $rag['diagnostics'];

    // 3. Historial (lee $SESSION, por eso va antes de cerrar la sesión).
    $history = chat_pipeline::prepare_history($courseid, $conversation_history, $rag_context);

    // 4. Liberar el lock de sesión de Moodle ANTES de llamar a OpenAI.
    //    Sin esto, todas las demás páginas/pestañas del usuario quedan
    //    congeladas mientras la IA genera la respuesta.
    \core\session\manager::write_close();

    $ondelta = function(string $text): void {
        pulso_sse('delta', ['text' => $text]);
    };

    // 5. Ruta directa (respuestas estructurales/recurso resueltas desde Moodle).
    $qinfo = chat_pipeline::build_direct_query($user_query, $history);
    $direct = chat_pipeline::resolve_direct_answer($courseid, $user_query, $qinfo, $ondelta);

    if ($direct !== null) {
        $history = chat_pipeline::save_history($courseid, $history, $user_query, $direct['answer']);

        pulso_sse('final', [
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
        ]);
        exit;
    }

    // 6. Ruta LLM: streaming de la respuesta principal.
    pulso_sse('status', ['stage' => 'generating']);

    $system_prompt = system_prompt_designer::generate_prompt_with_context_and_rag(
        $course_context,
        $rag_context
    );

    $connector = new anthropic_connector();
    $ai_response = $connector->stream_query_with_context(
        $user_query,
        $system_prompt,
        $history,
        800,
        $ondelta,
        true // prefill_json: fuerza que la respuesta empiece en '{' (sin preámbulo).
    );

    $answer = chat_pipeline::clean_answer($ai_response['answer']);
    $schema_data = system_prompt_designer::validate_response($answer);

    $history = chat_pipeline::save_history($courseid, $history, $user_query, $answer);

    pulso_sse('final', [
        'success' => true,
        'message' => 'Query procesado exitosamente',
        'answer' => $answer,
        'tokens_used' => $ai_response['tokens_used'] ?? 0,
        'model' => $ai_response['model'] ?? 'claude-sonnet-5',
        'schema_valid' => $schema_data ? true : false,
        'schema_data' => $schema_data,
        'rag_diagnostics' => $rag_diagnostics,
        'followup_questions' => [],
        'history_length' => count($history),
        'course_id' => $courseid,
        'course_name' => $course_context['course_context']['course_name'] ?? $course->fullname,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

    // 7. Preguntas de seguimiento FUERA de la ruta crítica: la respuesta ya
    //    se mostró; las sugerencias llegan unos segundos después como evento.
    try {
        $followup_questions = $connector->generate_followup_questions(
            $user_query,
            $answer,
            $course_context
        );
        if (!empty($followup_questions)) {
            pulso_sse('followups', ['questions' => $followup_questions]);
        }
    } catch (\Throwable $e) {
        error_log('Follow-up questions generation failed: ' . $e->getMessage());
    }

} catch (\Throwable $e) {
    pulso_sse('error', [
        'message' => 'Error: ' . $e->getMessage(),
        'error_code' => get_class($e)
    ]);
}

exit;
