<?php
// T2.3.1: Setup OpenAI PHP client.
namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

/**
 * Clase encargada de la comunicación con la API de OpenAI.
 */
class openai_connector {

    private $apikey;
    private $model;
    private $apiurl = 'https://api.openai.com/v1/chat/completions';

    /**
     * Constructor: Inicializa el cliente usando la API Key de los ajustes.
     * Criterio de Aceptación: "Initialize OpenAI client using API key from settings"
     */
    public function __construct() {
        // Recuperamos la configuración que guardamos en la administración (T2.1.4)
        $config = get_config('block_pulso');
        
        // Verificamos que exista la clave
        if (empty($config->openai_key)) {
            // Lanzamos una excepción de Moodle si no hay clave configurada
            throw new \moodle_exception('error_no_apikey', 'block_pulso');
        }

        $this->apikey = $config->openai_key;
        // Si no se configuró modelo, usamos gpt-4o por defecto
        $this->model = $config->model ?: 'gpt-4o'; 
    }

    /**
     * Envía una consulta básica a la IA para verificar la conexión.
     * Criterio de Aceptación: "Prepare basic chat completion request payload"
     *
     * @param string $user_prompt El texto que escribe el usuario.
     * @return stdClass La respuesta cruda de OpenAI desglosada.
     */
    public function send_basic_test_query(string $user_prompt) {
        global $CFG;

        // 1. Preparar el PAYLOAD básico según documentación de OpenAI
        $payload = [
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system', 
                    'content' => 'Eres Pulso AI, un asistente de analítica para Moodle. Responde de forma concisa y educada.'
                ],
                [
                    'role' => 'user', 
                    'content' => $user_prompt
                ]
            ],
            'temperature' => 0.7, // Controla la "creatividad" de la respuesta
            'max_tokens' => 150   // Limitamos para pruebas
        ];

        // 2. Usar el cliente nativo cURL de Moodle para hacer la petición POST
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        
        // Configuramos las cabeceras HTTP necesarias (Authorization)
        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey
        ]);

        // Convertimos el payload a JSON y hacemos el envío
        $json_payload = json_encode($payload);
        $response_raw = $curl->post($this->apiurl, $json_payload);

        // 3. Procesar la respuesta
        if ($curl->errno) {
            // Manejo básico de errores de red/conexión
            throw new \moodle_exception('error_api_connection', 'block_pulso', '', $curl->error);
        }

        $response = json_decode($response_raw);

        // Verificamos si OpenAI devolvió un error (ej: clave inválida)
        if (isset($response->error)) {
            throw new \moodle_exception('error_api_response', 'block_pulso', '', $response->error->message);
        }

        return $response;
    }

    /**
     * Envía una consulta con contexto del curso para análisis inteligente.
     * Este método es usado por el chat UI (T2.3.2) para procesar queries de usuarios.
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

        // 1. Construir el arreglo de mensajes
        $messages = [
            [
                'role' => 'system',
                'content' => $system_prompt
            ]
        ];

        // 2. Agregar el historial de conversación si existe
        if (!empty($conversation_history)) {
            foreach ($conversation_history as $msg) {
                if (isset($msg['role']) && isset($msg['content'])) {
                    $messages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content']
                    ];
                }
            }
        }

        // 3. Agregar el mensaje actual del usuario
        $messages[] = [
            'role' => 'user',
            'content' => $user_message
        ];

        // 4. Preparar el payload para OpenAI
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.5,      // Más preciso que creativo
            'max_tokens' => $max_tokens,
            'top_p' => 0.9,            // Nucleus sampling
            'presence_penalty' => 0.1   // Reducir repeticiones
        ];

        // 5. Hacer la solicitud a OpenAI
        require_once($CFG->libdir . '/filelib.php');
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 55]);

        $curl->setHeader([
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->apikey
        ]);

        $json_payload = json_encode($payload);
        $response_raw = $curl->post($this->apiurl, $json_payload);

        // 6. Validar respuesta
        if ($curl->errno) {
            throw new \moodle_exception(
                'error_api_connection',
                'block_pulso',
                '',
                'cURL error: ' . $curl->error
            );
        }

        $response = json_decode($response_raw, true);

        // Verificar si hay error en la respuesta de OpenAI
        if (isset($response['error'])) {
            throw new \moodle_exception(
                'error_api_response',
                'block_pulso',
                '',
                $response['error']['message'] ?? 'Unknown API error'
            );
        }

        // 7. Extraer la respuesta y contar tokens
        if (empty($response['choices'][0]['message']['content'])) {
            throw new \moodle_exception(
                'error_empty_response',
                'block_pulso',
                '',
                'No content in OpenAI response'
            );
        }

        $answer = trim($response['choices'][0]['message']['content']);
        $tokens_used = $response['usage']['total_tokens'] ?? 0;

        return [
            'answer' => $answer,
            'tokens_used' => (int)$tokens_used,
            'model' => $this->model,
            'finish_reason' => $response['choices'][0]['finish_reason'] ?? 'unknown'
        ];
    }

    /**
     * Enviar query analítica con system prompt completo y schema JSON
     * Task T2.3.3: Design system prompt with schema and examples
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
        // Cargar system_prompt_designer
        require_once(__DIR__ . '/system_prompt_designer.php');

        // Generar system prompt enriquecido con contexto del curso
        $system_prompt = $custom_system_prompt ?: system_prompt_designer::generate_prompt_with_context($course_context);

        // Enviar query a OpenAI
        $response = $this->send_query_with_context(
            $user_query,
            $system_prompt,
            $conversation_history,
            $max_tokens
        );

        // Validar que la respuesta sigue el schema JSON
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
        int $max_tokens = 220
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

        $response = $this->send_query_with_context($user_prompt, $system_prompt, [], $max_tokens);
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
        int $max_tokens = 500
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

        $response = $this->send_query_with_context($user_prompt, $system_prompt, [], $max_tokens);
        return trim((string)($response['answer'] ?? ''));
    }

    /**
     * Generar preguntas de seguimiento basadas en contexto y respuesta anterior
     * Task T2.4.12: Generate follow-up questions
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
        global $CFG;

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

            // Llamar a OpenAI para generar las preguntas
            $payload = [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => $system_prompt
                    ],
                    [
                        'role' => 'user',
                        'content' => $followup_prompt
                    ]
                ],
                'temperature' => 0.7,
                'max_tokens' => 300
            ];

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $this->apiurl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apikey
                ],
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
            if (!isset($response_data['choices'][0]['message']['content'])) {
                error_log('Follow-up questions: Missing content in response');
                return [];
            }

            // Parsear respuesta
            $content = $response_data['choices'][0]['message']['content'];
            
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
        } catch (Exception $e) {
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