<?php
// Cliente de la API de Anthropic (Claude) para las respuestas de chat.
// Los embeddings del RAG siguen usando OpenAI (ver classes/embedding_manager.php).
namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

/**
 * Clase encargada de la comunicación con la API de Anthropic (Claude).
 */
class anthropic_connector {

    /**
     * Modelo rápido/barato para tareas auxiliares (preguntas de seguimiento).
     * No se usa para la respuesta principal.
     */
    const FAST_MODEL = 'claude-haiku-4-5';

    /** Versión de la API de Anthropic requerida por cabecera. */
    const API_VERSION = '2023-06-01';

    private $apikey;
    private $model;
    private $apiurl = 'https://api.anthropic.com/v1/messages';

    /**
     * Constructor: Inicializa el cliente usando la API Key de los ajustes.
     */
    public function __construct() {
        $config = get_config('block_pulso');

        if (empty($config->anthropic_key)) {
            throw new \moodle_exception('error_no_apikey_anthropic', 'block_pulso');
        }

        $this->apikey = $config->anthropic_key;
        // Si no se configuró modelo, usamos claude-sonnet-5 por defecto.
        $this->model = $config->model ?: 'claude-sonnet-5';
    }

    /**
     * Cabeceras HTTP comunes para cualquier llamada a la API de Anthropic.
     *
     * @return string[]
     */
    private function headers(): array {
        return [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apikey,
            'anthropic-version: ' . self::API_VERSION,
        ];
    }

    /**
     * Filtra el historial de conversación a solo mensajes user/assistant,
     * tal y como exige la Messages API (el system prompt va aparte).
     *
     * @param array $conversation_history
     * @return array
     */
    private function build_messages(array $conversation_history, string $user_message): array {
        $messages = [];
        foreach ($conversation_history as $msg) {
            if (isset($msg['role'], $msg['content']) && in_array($msg['role'], ['user', 'assistant'], true)) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }
        $messages[] = ['role' => 'user', 'content' => $user_message];
        return $messages;
    }

    /**
     * Extrae el primer bloque de texto de la respuesta de Anthropic.
     *
     * @param array $content Array "content" de la respuesta.
     * @return string
     */
    private function extract_text(array $content): string {
        foreach ($content as $block) {
            if (($block['type'] ?? '') === 'text') {
                return (string)($block['text'] ?? '');
            }
        }
        return '';
    }

    /**
     * Envía una consulta con contexto del curso para análisis inteligente.
     *
     * @param string $user_message El mensaje del usuario con contexto del curso
     * @param string $system_prompt El prompt del sistema que define el rol de la IA
     * @param array $conversation_history Historial de conversación anterior (opcional)
     * @param int $max_tokens Máximo de tokens en la respuesta (defecto 500)
     * @return array Array con 'answer' y 'tokens_used'
     * @throws \moodle_exception Si falla la API
     */
    public function send_query_with_context(
        string $user_message,
        string $system_prompt,
        array $conversation_history = [],
        int $max_tokens = 500
    ): array {
        global $CFG;

        // NOTA: NO añadir un turno "assistant" de prefill aquí — claude-sonnet-5
        // (y otros modelos Claude 4.x/5) lo rechazan con 400 invalid_request_error:
        // "This model does not support assistant message prefill. The conversation
        // must end with a user message." La conversación SIEMPRE debe terminar en
        // "user" (ver build_messages()).
        $payload = [
            'model' => $this->model,
            'system' => $system_prompt,
            'messages' => $this->build_messages($conversation_history, $user_message),
            'max_tokens' => $max_tokens,
            // Sin temperature/top_p: Claude Sonnet 5 / Opus 4.8 rechazan (400) valores
            // no-default de estos parámetros; se omiten para funcionar con cualquier
            // modelo del selector.
        ];

        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 55]);

        $curl->setHeader($this->headers());

        $json_payload = json_encode($payload);
        $response_raw = $curl->post($this->apiurl, $json_payload);

        if ($curl->errno) {
            throw new \moodle_exception(
                'error_api_connection',
                'block_pulso',
                '',
                'cURL error: ' . $curl->error
            );
        }

        $response = json_decode($response_raw, true);

        if (isset($response['error'])) {
            // NOTA: el 4º argumento de moodle_exception es $a (interpolación de
            // get_string), NO debuginfo — el detalle real va en el 5º argumento.
            $http_status = $curl->info['http_code'] ?? '?';
            $err_message = $response['error']['message'] ?? 'Unknown API error';
            $err_type = $response['error']['type'] ?? null;
            throw new \moodle_exception(
                'error_api_response',
                'block_pulso',
                '',
                $err_message,
                'HTTP ' . $http_status . ($err_type ? " ({$err_type})" : '') . ': ' . $err_message
            );
        }

        $stop_reason = $response['stop_reason'] ?? 'unknown';
        $text = trim($this->extract_text($response['content'] ?? []));

        if ($text === '') {
            if ($stop_reason === 'refusal') {
                throw new \moodle_exception('error_refusal', 'block_pulso');
            }
            throw new \moodle_exception(
                'error_empty_response',
                'block_pulso',
                '',
                'No content in Anthropic response'
            );
        }

        $tokens_used = ($response['usage']['input_tokens'] ?? 0) + ($response['usage']['output_tokens'] ?? 0);

        return [
            'answer' => $text,
            'tokens_used' => (int)$tokens_used,
            'model' => $this->model,
            'finish_reason' => $stop_reason,
        ];
    }

    /**
     * Igual que send_query_with_context() pero en modo STREAMING (SSE).
     *
     * Abre la petición a Anthropic con stream=true y va invocando $ondelta con
     * cada fragmento de texto a medida que llega, para que el endpoint pueda
     * reenviarlo al navegador sin esperar la respuesta completa.
     *
     * @param string $user_message
     * @param string $system_prompt
     * @param array $conversation_history
     * @param int $max_tokens
     * @param callable $ondelta fn(string $textdelta): void
     * @return array ['answer', 'tokens_used', 'model', 'finish_reason']
     * @throws \moodle_exception
     */
    public function stream_query_with_context(
        string $user_message,
        string $system_prompt,
        array $conversation_history,
        int $max_tokens,
        callable $ondelta
    ): array {
        // NOTA: sin prefill de "assistant" — ver comentario en send_query_with_context().
        $payload = [
            'model' => $this->model,
            'system' => $system_prompt,
            'messages' => $this->build_messages($conversation_history, $user_message),
            'max_tokens' => $max_tokens,
            'stream' => true,
        ];

        $model_text = '';
        $tokens_in = 0;
        $tokens_out = 0;
        $finish_reason = 'unknown';
        $sse_buffer = '';
        $error_body = '';
        $error_http_status = null;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiurl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => array_merge($this->headers(), ['Accept: text/event-stream']),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (
                &$sse_buffer, &$model_text, &$tokens_in, &$tokens_out, &$finish_reason, &$error_body,
                &$error_http_status, $ondelta
            ) {
                // Con error HTTP, Anthropic devuelve un body JSON normal: acumularlo.
                $httpcode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($httpcode >= 400) {
                    $error_http_status = $httpcode;
                    $error_body .= $data;
                    return strlen($data);
                }

                $sse_buffer .= $data;
                while (($pos = strpos($sse_buffer, "\n")) !== false) {
                    $line = trim(substr($sse_buffer, 0, $pos));
                    $sse_buffer = substr($sse_buffer, $pos + 1);
                    if ($line === '' || strpos($line, 'data:') !== 0) {
                        continue;
                    }
                    $json = trim(substr($line, 5));
                    $event = json_decode($json, true);
                    if (!is_array($event) || !isset($event['type'])) {
                        continue;
                    }

                    switch ($event['type']) {
                        case 'message_start':
                            $tokens_in = (int)($event['message']['usage']['input_tokens'] ?? 0);
                            break;
                        case 'content_block_delta':
                            if (($event['delta']['type'] ?? '') === 'text_delta') {
                                $delta = (string)($event['delta']['text'] ?? '');
                                if ($delta !== '') {
                                    $model_text .= $delta;
                                    $ondelta($delta);
                                }
                            }
                            break;
                        case 'message_delta':
                            if (isset($event['usage']['output_tokens'])) {
                                $tokens_out = (int)$event['usage']['output_tokens'];
                            }
                            if (!empty($event['delta']['stop_reason'])) {
                                $finish_reason = (string)$event['delta']['stop_reason'];
                            }
                            break;
                    }
                }
                return strlen($data);
            }
        ]);

        curl_exec($curl);
        $curl_errno = curl_errno($curl);
        $curl_error = curl_error($curl);
        curl_close($curl);

        if ($curl_errno) {
            // Si ya llegó parte de la respuesta, devolver lo acumulado en vez de fallar.
            if ($model_text === '') {
                throw new \moodle_exception('error_api_connection', 'block_pulso', '', 'cURL error: ' . $curl_error);
            }
        }

        if ($error_body !== '') {
            $err = json_decode($error_body, true);
            $err_message = $err['error']['message'] ?? 'Unknown API error';
            $err_type = $err['error']['type'] ?? null;
            throw new \moodle_exception(
                'error_api_response',
                'block_pulso',
                '',
                $err_message,
                'HTTP ' . ($error_http_status ?? '?') . ($err_type ? " ({$err_type})" : '') . ': ' . $err_message
            );
        }

        if ($model_text === '') {
            if ($finish_reason === 'refusal') {
                throw new \moodle_exception('error_refusal', 'block_pulso');
            }
            throw new \moodle_exception('error_empty_response', 'block_pulso', '', 'No content in Anthropic stream');
        }

        $model_text = trim($model_text);

        return [
            'answer' => $model_text,
            'tokens_used' => $tokens_in + $tokens_out,
            'model' => $this->model,
            'finish_reason' => $finish_reason,
        ];
    }

    /**
     * Enviar query analítica con system prompt completo y schema JSON
     *
     * @param string $user_query Pregunta del usuario
     * @param array $course_context Contexto del curso desde data_retriever
     * @param array $conversation_history Historial anterior (opcional)
     * @param int $max_tokens Máximo de tokens (defecto 800 para respuestas JSON)
     * @return array Respuesta con structure [answer, tokens_used, schema_valid]
     * @throws \moodle_exception
     */
    public function send_analytics_query_with_schema(
        string $user_query,
        array $course_context = [],
        array $conversation_history = [],
        int $max_tokens = 800,
        ?string $custom_system_prompt = null
    ): array {
        require_once(__DIR__ . '/system_prompt_designer.php');

        $system_prompt = $custom_system_prompt ?: system_prompt_designer::generate_prompt_with_context($course_context);

        $response = $this->send_query_with_context(
            $user_query,
            $system_prompt,
            $conversation_history,
            $max_tokens
        );

        $is_valid = system_prompt_designer::validate_response($response['answer']);

        return [
            'answer' => $response['answer'],
            'tokens_used' => $response['tokens_used'],
            'model' => $response['model'],
            'schema_valid' => $is_valid ? true : false,
            'schema_data' => $is_valid,
            'finish_reason' => $response['finish_reason']
        ];
    }

    /**
     * Generate a concise summary from extracted document text.
     *
     * @param string $document_text Extracted/cleaned text from the document.
     * @param string $user_query Original user request for context.
     * @param int $max_tokens Maximum tokens for the summary.
     * @return string
     * @throws \moodle_exception
     */
    public function summarize_document_text(
        string $document_text,
        string $user_query = '',
        int $max_tokens = 220,
        ?callable $ondelta = null
    ): string {
        $document_text = trim($document_text);
        if ($document_text === '') {
            return '';
        }

        $system_prompt = 'Eres un asistente que resume documentos extraídos de Moodle. '
            . 'Recibirás texto potencialmente ruidoso por OCR. '
            . 'Tu tarea es reconstruir el sentido general y redactar un resumen claro en español. '
            . 'No copies el texto tal cual si viene roto. '
            . 'No menciones JSON ni formato técnico. '
            . 'Responde solo con un resumen breve de 2 a 4 frases.';

        $user_prompt = "Consulta del usuario: " . trim($user_query) . "\n\n"
            . "Texto extraído del documento:\n"
            . mb_substr($document_text, 0, 7000);

        $response = $ondelta !== null
            ? $this->stream_query_with_context($user_prompt, $system_prompt, [], $max_tokens, $ondelta)
            : $this->send_query_with_context($user_prompt, $system_prompt, [], $max_tokens);
        return trim((string)($response['answer'] ?? ''));
    }

    /**
     * Responder una pregunta concreta sobre el contenido de un documento.
     *
     * @param string $document_text Texto extraído del documento (puede tener ruido OCR)
     * @param string $question      Pregunta original del usuario
     * @param int    $max_tokens
     * @return string
     */
    public function answer_document_question(
        string $document_text,
        string $question,
        int $max_tokens = 500,
        ?callable $ondelta = null
    ): string {
        $document_text = trim($document_text);
        if ($document_text === '') {
            return '';
        }

        $system_prompt = 'Eres un asistente educativo que responde preguntas concretas sobre documentos de un curso en Moodle. '
            . 'El texto puede tener imperfecciones de OCR; ignóralas y extrae la información real. '
            . 'Responde de forma clara, directa y concisa en español basándote ÚNICAMENTE en el contenido del documento. '
            . 'Ve directo al grano: no uses pasos numerados, no escribas "Paso 1", "Primero", "Segundo", etc. '
            . 'Responde en un solo párrafo o con viñetas si es necesario, sin rodeos. '
            . 'Si la información no aparece en el documento, indícalo en una frase corta. '
            . 'No menciones JSON, RAG ni terminología técnica.';

        $user_prompt = "Pregunta: " . trim($question) . "\n\n"
            . "Contenido del documento:\n"
            . mb_substr($document_text, 0, 7000);

        $response = $ondelta !== null
            ? $this->stream_query_with_context($user_prompt, $system_prompt, [], $max_tokens, $ondelta)
            : $this->send_query_with_context($user_prompt, $system_prompt, [], $max_tokens);
        return trim((string)($response['answer'] ?? ''));
    }

    /**
     * Generar preguntas de seguimiento basadas en contexto y respuesta anterior
     *
     * @param string $user_query Pregunta original del usuario
     * @param string $ai_response Respuesta anterior de la IA
     * @param array $course_context Contexto del curso
     * @return array Array con 2-3 preguntas sugeridas
     */
    public function generate_followup_questions(
        string $user_query,
        string $ai_response,
        array $course_context = []
    ): array {
        try {
            // System prompt para generar preguntas de seguimiento
            $system_prompt = <<<'PROMPT'
Eres un asistente educativo inteligente. Basándote en la pregunta del usuario y la respuesta anterior,
genera exactamente 2-3 preguntas de seguimiento naturales y relevantes que el profesor podría hacer a continuación.

Las preguntas deben:
- Ser breves (máx 80 caracteres)
- Explorar diferentes aspectos relacionados
- Ser fáciles de entender
- Estar en el mismo idioma que la pregunta original

Retorna SOLO un JSON válido sin texto adicional:
{
    "followup_questions": [
        "¿Pregunta 1?",
        "¿Pregunta 2?",
        "¿Pregunta 3?"
    ]
}
PROMPT;

            // Preparar el mensaje del usuario para generar preguntas (truncar respuesta si es muy larga)
            $truncated_response = substr($ai_response, 0, 500);
            $followup_prompt = "Pregunta original: \"$user_query\"\n\nRespuesta anterior: \"$truncated_response\"\n\nGenera 2-3 preguntas de seguimiento relevantes.";

            // Llamar a Claude para generar las preguntas.
            // Tarea auxiliar y corta → modelo rápido y menos tokens: reduce
            // varios segundos de latencia frente a usar el modelo principal.
            $payload = [
                'model' => self::FAST_MODEL,
                'system' => $system_prompt,
                'messages' => [
                    ['role' => 'user', 'content' => $followup_prompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 150,
            ];

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->apiurl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => $this->headers(),
            ]);

            $response = curl_exec($curl);
            $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($curl);
            curl_close($curl);

            // Validar respuesta
            if ($http_code !== 200) {
                error_log('Follow-up questions HTTP error: ' . $http_code . ' - ' . $curl_error);
                return [];
            }

            $response_data = json_decode($response, true);
            $content = $this->extract_text($response_data['content'] ?? []);
            if ($content === '') {
                error_log('Follow-up questions: Missing content in response');
                return [];
            }

            // Intenta parsear como JSON
            $questions_json = json_decode($content, true);

            // Si falla el JSON, intentar extraer las preguntas manualmente
            if (!$questions_json) {
                error_log('Follow-up questions: Invalid JSON - ' . substr($content, 0, 200));
                // Intenta extraer preguntas con regex
                preg_match_all('/["\']([^"\']*\?[^"\']*)["\']/', $content, $matches);
                if (!empty($matches[1])) {
                    $clean = $this->sanitize_followup_questions($matches[1]);
                    if (!empty($clean)) {
                        return array_slice($clean, 0, 3);
                    }
                }
                return $this->fallback_followup_questions($user_query);
            }

            if (isset($questions_json['followup_questions']) && is_array($questions_json['followup_questions'])) {
                $questions = $this->sanitize_followup_questions($questions_json['followup_questions']);
                if (!empty($questions)) {
                    return array_slice($questions, 0, 3);
                }
                return $this->fallback_followup_questions($user_query);
            }

            error_log('Follow-up questions: No followup_questions field in JSON');
            return $this->fallback_followup_questions($user_query);
        } catch (\Throwable $e) {
            error_log('Follow-up questions exception: ' . $e->getMessage());
            return $this->fallback_followup_questions($user_query);
        }
    }

    /**
     * Clean and validate follow-up questions.
     *
     * @param array $questions
     * @return array
     */
    private function sanitize_followup_questions(array $questions): array {
        $clean = [];
        foreach ($questions as $q) {
            $q = trim((string)$q);
            if ($q === '') {
                continue;
            }

            // Remove bullet markers and repeated punctuation.
            $q = preg_replace('/^[\-\*\d\.\)\s]+/u', '', $q);
            $q = preg_replace('/\?{2,}/u', '?', $q);
            $q = preg_replace('/\s{2,}/u', ' ', $q);
            $q = trim($q);

            // Discard trivial/invalid items like '?', '¿?', very short strings.
            if (mb_strlen($q, 'UTF-8') < 12) {
                continue;
            }
            if (preg_match('/^[¿?\s]+$/u', $q)) {
                continue;
            }

            // Ensure it ends as a question.
            if (!preg_match('/[\?؟]$/u', $q)) {
                $q .= '?';
            }

            // Keep max 100 chars for UI readability.
            if (mb_strlen($q, 'UTF-8') > 100) {
                $q = mb_substr($q, 0, 100, 'UTF-8');
                $q = rtrim($q, " .,;:") . '?';
            }

            $clean[] = $q;
        }

        // Remove duplicates preserving order.
        $unique = [];
        foreach ($clean as $q) {
            if (!in_array($q, $unique, true)) {
                $unique[] = $q;
            }
        }

        return $unique;
    }

    /**
     * Deterministic fallback questions when generation/parsing fails.
     *
     * @param string $user_query
     * @return array
     */
    private function fallback_followup_questions(string $user_query): array {
        $q = mb_strtolower($user_query, 'UTF-8');
        $isanalytics = preg_match('/anal[ií]tica|notas?|completitud|completion|engagement|riesgo|promedio|grade|calificaci[oó]n/u', $q);

        if ($isanalytics) {
            return [
                '¿Quieres el desglose por estudiante?',
                '¿Comparo este resultado con la última semana?',
                '¿Te muestro los casos en mayor riesgo primero?'
            ];
        }

        return [
            '¿Quieres que te cite el fragmento exacto del contenido?',
            '¿Te explico el procedimiento paso a paso?',
            '¿Prefieres una versión resumida o detallada de la solución?'
        ];
    }
}
