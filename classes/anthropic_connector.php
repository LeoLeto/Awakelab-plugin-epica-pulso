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

    /** Reintentos máximos ante sobrecarga transitoria de la API. */
    const MAX_RETRIES = 2;

    /**
     * Códigos HTTP que merecen reintento: 429 (rate limit), 529 (overloaded) y los
     * 5xx transitorios. Un 400/401/403 NO se reintenta — es un fallo de la petición
     * y repetirla da exactamente el mismo error.
     */
    const RETRYABLE_STATUSES = [429, 500, 502, 503, 504, 529];

    /** Tipos de `event: error` del stream que merecen reintento (si aún no se emitió ningún token). */
    const RETRYABLE_ERROR_TYPES = ['overloaded_error', 'api_error', 'rate_limit_error'];

    /**
     * Errores de cURL de CONEXIÓN que se reintentan UNA vez si no se emitió nada:
     * 6 no resuelve host, 7 no conecta, 35 SSL, 52 respuesta vacía, 55 envío, 56 recepción.
     * NO el 28 (timeout): repetir una petición que ya agotó el tiempo duplica la espera.
     */
    const CONNECTION_ERRNOS = [6, 7, 35, 52, 55, 56];

    /** Tope (s) de la espera por la cabecera retry-after de Anthropic: el usuario está esperando. */
    const RETRY_AFTER_CAP = 10;

    /** Timeout (s) de la llamada no-stream de la respuesta principal (antes 55). */
    const MAIN_TIMEOUT = 110;

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
     * ¿Merece la pena reintentar esta respuesta?
     *
     * @param int $httpstatus
     * @param int $attempt Reintentos ya consumidos.
     * @return bool
     */
    private function should_retry(int $httpstatus, int $attempt): bool {
        return $attempt < self::MAX_RETRIES
            && in_array($httpstatus, self::RETRYABLE_STATUSES, true);
    }

    /**
     * Espera antes del siguiente intento, creciente (1s, 2s). Se mantiene corta a
     * propósito: el usuario está esperando la respuesta del chat.
     *
     * @param int $attempt Número de reintento (1-based).
     * @param int|null $retryafter Cabecera retry-after de Anthropic (s), si llegó: se
     *                 respeta, con tope RETRY_AFTER_CAP.
     * @return int segundos
     */
    private function retry_delay(int $attempt, ?int $retryafter = null): int {
        if ($retryafter !== null && $retryafter > 0) {
            return min(self::RETRY_AFTER_CAP, $retryafter);
        }
        return min(4, (int)pow(2, $attempt - 1));
    }

    /** ¿Es un error de cURL de conexión que se puede reintentar una vez? */
    private function is_connection_errno(int $errno): bool {
        return in_array($errno, self::CONNECTION_ERRNOS, true);
    }

    /**
     * Espera entre reintentos. Con $onping (stream SSE) emite un comentario `: ping`
     * cada ~5 s para que un proxy no corte la conexión por inactividad.
     */
    private function wait(int $seconds, ?callable $onping = null): void {
        if ($onping !== null) {
            $onping();
        }
        for ($i = 1; $i <= $seconds; $i++) {
            sleep(1);
            if ($onping !== null && $i % 5 === 0) {
                $onping();
            }
        }
    }

    /**
     * Valor de la cabecera retry-after de una respuesta del wrapper \curl de Moodle.
     *
     * @param \curl $curl
     * @return int|null
     */
    private function retry_after_from_curl(\curl $curl): ?int {
        foreach ((array)$curl->get_raw_response() as $line) {
            if (is_string($line) && stripos($line, 'retry-after:') === 0) {
                return (int)trim(substr($line, 12));
            }
        }
        return null;
    }

    /**
     * Aplica la configuración de proxy de Moodle a un handle de cURL crudo.
     *
     * El streaming y las preguntas de seguimiento no pueden usar el wrapper `\curl`
     * de Moodle (necesitan CURLOPT_WRITEFUNCTION para ir emitiendo los tokens), pero
     * al usar curl_init() directamente se saltaban `$CFG->proxyhost`: en un Moodle
     * detrás de proxy el endpoint clásico funcionaba y el streaming no, con un fallo
     * de conexión difícil de atribuir.
     *
     * @param \CurlHandle|resource $handle
     * @param string $url URL destino, para respetar la lista de excepciones del proxy.
     */
    private function apply_proxy_settings($handle, string $url): void {
        global $CFG;

        if (empty($CFG->proxyhost)) {
            return;
        }

        // Respetar $CFG->proxybypass (is_proxybypass() vive en moodlelib).
        if (function_exists('is_proxybypass') && is_proxybypass($url)) {
            return;
        }

        if (empty($CFG->proxyport)) {
            curl_setopt($handle, CURLOPT_PROXY, $CFG->proxyhost);
        } else {
            curl_setopt($handle, CURLOPT_PROXY, $CFG->proxyhost . ':' . $CFG->proxyport);
        }

        if (!empty($CFG->proxytype) && $CFG->proxytype === 'SOCKS5') {
            curl_setopt($handle, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5);
        }

        if (!empty($CFG->proxyuser) && !empty($CFG->proxypassword)) {
            curl_setopt($handle, CURLOPT_PROXYUSERPWD, $CFG->proxyuser . ':' . $CFG->proxypassword);
            curl_setopt($handle, CURLOPT_PROXYAUTH, CURLAUTH_BASIC | CURLAUTH_NTLM);
        }
    }

    /**
     * Serializa el payload de la petición a JSON de forma segura.
     *
     * json_encode() devuelve false ante CUALQUIER byte UTF-8 inválido (texto
     * extraído de PDFs, historial truncado a mitad de un carácter...). Sin este
     * control el cuerpo enviado quedaba vacío y Anthropic respondía 400 con un
     * mensaje que no apuntaba a la causa real. JSON_INVALID_UTF8_SUBSTITUTE
     * sustituye los bytes rotos en vez de tirar la petición entera.
     *
     * @param array $payload
     * @return string
     * @throws \moodle_exception si ni con sustitución se puede serializar.
     */
    private function encode_payload(array $payload): string {
        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        if ($json === false || $json === '') {
            throw new \moodle_exception(
                'error_payload_encoding',
                'block_pulso',
                '',
                null,
                'json_encode failed: ' . json_last_error_msg()
            );
        }

        return $json;
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
     * Extrae las métricas de uso de tokens, incluyendo las de prompt caching.
     *
     * OJO con `input_tokens`: cuando hay caché, cuenta SOLO el resto no cacheado.
     * El tamaño real del prompt es input + cache_creation + cache_read, así que
     * sumar solo input+output infravaloraría el consumo al activar el caché.
     *
     * @param array $usage Objeto `usage` de la respuesta de Anthropic.
     * @return array
     */
    private function extract_usage(array $usage): array {
        $input = (int)($usage['input_tokens'] ?? 0);
        $output = (int)($usage['output_tokens'] ?? 0);
        $cachewrite = (int)($usage['cache_creation_input_tokens'] ?? 0);
        $cacheread = (int)($usage['cache_read_input_tokens'] ?? 0);

        return [
            'tokens_used' => $input + $output + $cachewrite + $cacheread,
            'cache_creation_input_tokens' => $cachewrite,
            'cache_read_input_tokens' => $cacheread,
            'uncached_input_tokens' => $input,
        ];
    }

    /**
     * Envía una consulta con contexto del curso para análisis inteligente.
     *
     * @param string $user_message El mensaje del usuario con contexto del curso
     * @param array|string $system_prompt System prompt: string, o array de bloques
     *                     de contenido cuando se usa prompt caching (ver
     *                     system_prompt_designer::generate_system_blocks()).
     * @param array $conversation_history Historial de conversación anterior (opcional)
     * @param int $max_tokens Máximo de tokens en la respuesta (defecto 500)
     * @return array Array con 'answer' y 'tokens_used'
     * @throws \moodle_exception Si falla la API
     */
    public function send_query_with_context(
        string $user_message,
        $system_prompt,
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
        $json_payload = $this->encode_payload($payload);

        // Reintento con espera creciente ante sobrecarga transitoria de la API
        // (429 rate limit, 529 overloaded, 5xx). Sin esto, un pico de carga en
        // Anthropic llegaba al profesor como un error seco.
        $attempt = 0;
        $connretries = 0;
        while (true) {
            $curl = new \curl();
            $curl->setopt(['CURLOPT_TIMEOUT' => self::MAIN_TIMEOUT]);
            $curl->setHeader($this->headers());

            $response_raw = $curl->post($this->apiurl, $json_payload);
            $http_status = (int)($curl->info['http_code'] ?? 0);

            if ($curl->errno) {
                // Error de conexión: un único reintento (no se ha recibido nada).
                if ($connretries < 1 && $this->is_connection_errno((int)$curl->errno)) {
                    $connretries++;
                    error_log("Pulso: Anthropic cURL {$curl->errno}, reintento de conexión.");
                    sleep(1);
                    continue;
                }
                throw new \moodle_exception(
                    'error_api_connection',
                    'block_pulso',
                    '',
                    'cURL error: ' . $curl->error
                );
            }

            if (!$this->should_retry($http_status, $attempt)) {
                break;
            }

            $attempt++;
            error_log("Pulso: Anthropic HTTP {$http_status}, reintento {$attempt} de " . self::MAX_RETRIES);
            sleep($this->retry_delay($attempt, $this->retry_after_from_curl($curl)));
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

        $usage = $this->extract_usage($response['usage'] ?? []);

        return array_merge([
            'answer' => $text,
            'model' => $this->model,
            'finish_reason' => $stop_reason,
            // max_tokens: respuesta cortada (el endpoint avisa y no la guarda en el historial).
            'truncated' => $stop_reason === 'max_tokens',
        ], $usage);
    }

    /**
     * Llamada corta de una sola vuelta a FAST_MODEL (Haiku), con su propio system
     * prompt (string): para tareas auxiliares que NO son el chat — hoy el tema de
     * la ampliacion de recursos. No toca el prompt base cacheado del chat.
     *
     * El payload va por encode_payload() (invariante: nunca json_encode a pelo).
     * Haiku si admite temperature; sin prefill de assistant (la conversacion
     * termina en "user").
     *
     * @param string $system_prompt
     * @param string $user_message
     * @param int $max_tokens
     * @return string Texto de la respuesta.
     * @throws \moodle_exception
     */
    public function send_fast_query(
        string $system_prompt,
        string $user_message,
        int $max_tokens = 300,
        ?float $deadline = null
    ): string {
        global $CFG;

        $payload = [
            'model' => self::FAST_MODEL,
            'system' => $system_prompt,
            'messages' => [['role' => 'user', 'content' => $user_message]],
            'max_tokens' => $max_tokens,
            'temperature' => 0.2,
        ];

        require_once($CFG->libdir . '/filelib.php');
        $json_payload = $this->encode_payload($payload);

        $attempt = 0;
        $connretries = 0;
        while (true) {
            // Con $deadline (presupuesto de tiempo de la ampliación) cada intento se acota
            // a lo que queda, y sin margen para una llamada útil no se empieza otra.
            $timeout = 25;
            if ($deadline !== null) {
                $left = $deadline - microtime(true);
                if ($left < 3) {
                    throw new \moodle_exception('error_api_connection', 'block_pulso', '', 'presupuesto de tiempo agotado');
                }
                $timeout = (int)max(3, min(25, floor($left)));
            }

            $curl = new \curl();
            $curl->setopt(['CURLOPT_TIMEOUT' => $timeout]);
            $curl->setHeader($this->headers());

            $response_raw = $curl->post($this->apiurl, $json_payload);
            $http_status = (int)($curl->info['http_code'] ?? 0);

            if ($curl->errno) {
                if ($connretries < 1 && $this->is_connection_errno((int)$curl->errno)) {
                    $connretries++;
                    sleep(1);
                    continue;
                }
                throw new \moodle_exception('error_api_connection', 'block_pulso', '', 'cURL error: ' . $curl->error);
            }
            if (!$this->should_retry($http_status, $attempt)) {
                break;
            }
            $attempt++;
            $delay = $this->retry_delay($attempt, $this->retry_after_from_curl($curl));
            if ($deadline !== null && ($deadline - microtime(true)) < $delay + 3) {
                break; // Sin tiempo para otro intento: se devuelve el error de este.
            }
            sleep($delay);
        }

        $response = json_decode($response_raw, true);
        if (isset($response['error'])) {
            $err_message = $response['error']['message'] ?? 'Unknown API error';
            throw new \moodle_exception(
                'error_api_response',
                'block_pulso',
                '',
                $err_message,
                'HTTP ' . $http_status . ': ' . $err_message
            );
        }

        $text = trim($this->extract_text($response['content'] ?? []));
        if ($text === '') {
            throw new \moodle_exception('error_empty_response', 'block_pulso', '', 'No content in Anthropic response');
        }
        return $text;
    }

    /**
     * Igual que send_query_with_context() pero en modo STREAMING (SSE).
     *
     * Abre la petición a Anthropic con stream=true y va invocando $ondelta con
     * cada fragmento de texto a medida que llega, para que el endpoint pueda
     * reenviarlo al navegador sin esperar la respuesta completa.
     *
     * @param string $user_message
     * @param array|string $system_prompt String, o array de bloques con cache_control.
     * @param array $conversation_history
     * @param int $max_tokens
     * @param callable $ondelta fn(string $textdelta): void
     * @param callable|null $onping fn(): void — se llama en los `ping` de Anthropic y durante las
     *                      esperas de reintento, para emitir un comentario SSE `: ping` al navegador.
     * @return array ['answer', 'tokens_used', 'model', 'finish_reason', 'truncated']
     * @throws \moodle_exception
     */
    public function stream_query_with_context(
        string $user_message,
        $system_prompt,
        array $conversation_history,
        int $max_tokens,
        callable $ondelta,
        ?callable $onping = null
    ): array {
        // NOTA: sin prefill de "assistant" — ver comentario en send_query_with_context().
        $payload = [
            'model' => $this->model,
            'system' => $system_prompt,
            'messages' => $this->build_messages($conversation_history, $user_message),
            'max_tokens' => $max_tokens,
            'stream' => true,
        ];

        // Serializar ANTES de abrir el handle: si el payload no es serializable
        // se lanza la excepción sin dejar un handle de cURL sin cerrar.
        $json_payload = $this->encode_payload($payload);

        // Bucle de reintento ante sobrecarga transitoria. SOLO se reintenta si no se
        // ha emitido ni un token todavía: una vez el usuario ha visto texto en
        // pantalla, repetir la petición duplicaría la respuesta. Los acumuladores se
        // reinician en cada intento para no mezclar estado entre ellos.
        $attempt = 0;
        $connretries = 0;
        while (true) {
        $model_text = '';
        $tokens_in = 0;
        $tokens_out = 0;
        $cache_write = 0;
        $cache_read = 0;
        $finish_reason = 'unknown';
        $stopped = false;       // ¿llegó message_stop? Sin él la respuesta no terminó.
        $stream_error = null;   // `event: error` recibido en mitad del stream.
        $retry_after = null;
        $sse_buffer = '';
        $error_body = '';
        $error_http_status = null;

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiurl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json_payload,
            CURLOPT_HTTPHEADER => array_merge($this->headers(), ['Accept: text/event-stream']),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 120,
            // Cabecera retry-after (429/529): se respeta, con tope, en la espera del reintento.
            CURLOPT_HEADERFUNCTION => function($ch, $header) use (&$retry_after) {
                if (stripos($header, 'retry-after:') === 0) {
                    $retry_after = (int)trim(substr($header, 12));
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION => function($ch, $data) use (
                &$sse_buffer, &$model_text, &$tokens_in, &$tokens_out, &$cache_write, &$cache_read,
                &$finish_reason, &$stopped, &$stream_error, &$error_body, &$error_http_status,
                $ondelta, $onping
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
                            // Las metricas de caché solo llegan aquí, en el usage
                            // inicial — message_delta no las repite.
                            $startusage = $event['message']['usage'] ?? [];
                            $tokens_in = (int)($startusage['input_tokens'] ?? 0);
                            $cache_write = (int)($startusage['cache_creation_input_tokens'] ?? 0);
                            $cache_read = (int)($startusage['cache_read_input_tokens'] ?? 0);
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
                        case 'message_stop':
                            $stopped = true;
                            break;
                        case 'ping':
                            // Se reenvía al navegador: mantiene viva la conexión tras proxies.
                            if ($onping !== null) {
                                $onping();
                            }
                            break;
                        case 'error':
                            // Anthropic puede abrir el stream con HTTP 200 y fallar después
                            // (p. ej. overloaded_error): llega como evento, no como código HTTP.
                            $stream_error = [
                                'type' => (string)($event['error']['type'] ?? ''),
                                'message' => (string)($event['error']['message'] ?? ''),
                            ];
                            break;
                    }
                }
                return strlen($data);
            }
        ]);

        $this->apply_proxy_settings($curl, $this->apiurl);

        curl_exec($curl);
        $curl_errno = curl_errno($curl);
        $curl_error = curl_error($curl);
        curl_close($curl);

            // Reintentos: SOLO si no se ha emitido ni un token.
            if ($model_text === '') {
                if ($error_http_status !== null && $this->should_retry((int)$error_http_status, $attempt)) {
                    $attempt++;
                    error_log("Pulso: Anthropic stream HTTP {$error_http_status}, reintento {$attempt} de "
                        . self::MAX_RETRIES);
                    $this->wait($this->retry_delay($attempt, $retry_after), $onping);
                    continue;
                }
                if ($stream_error !== null && in_array($stream_error['type'], self::RETRYABLE_ERROR_TYPES, true)
                        && $attempt < self::MAX_RETRIES) {
                    $attempt++;
                    error_log("Pulso: Anthropic stream error '{$stream_error['type']}', reintento {$attempt} de "
                        . self::MAX_RETRIES);
                    $this->wait($this->retry_delay($attempt, $retry_after), $onping);
                    continue;
                }
                if ($curl_errno && $connretries < 1 && $this->is_connection_errno((int)$curl_errno)) {
                    $connretries++;
                    error_log("Pulso: Anthropic stream cURL {$curl_errno}, reintento de conexión.");
                    $this->wait(1, $onping);
                    continue;
                }
            }
            break;
        }

        // Error a mitad de stream (o sin tokens y sin reintentos): se trata como un 529 de la
        // API — recuperable para el cliente (pulso_error lo clasifica «busy»). Si ya se emitió
        // texto no se reintenta (duplicaría la respuesta) ni se da por buena la parte recibida.
        if ($stream_error !== null) {
            $err_type = $stream_error['type'] !== '' ? $stream_error['type'] : 'api_error';
            throw new \moodle_exception(
                'error_api_response',
                'block_pulso',
                '',
                $stream_error['message'],
                'HTTP 529 (' . $err_type . '): ' . $stream_error['message']
            );
        }

        // Corte de red a mitad de respuesta: antes se devolvía lo acumulado como si fuera
        // completo (un JSON a medias reparado a la fuerza). Sin message_stop la respuesta no
        // terminó: error recuperable de red.
        if ($curl_errno && !$stopped) {
            throw new \moodle_exception('error_api_connection', 'block_pulso', '', 'cURL error: ' . $curl_error);
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
            'tokens_used' => $tokens_in + $tokens_out + $cache_write + $cache_read,
            'cache_creation_input_tokens' => $cache_write,
            'cache_read_input_tokens' => $cache_read,
            'uncached_input_tokens' => $tokens_in,
            'model' => $this->model,
            'finish_reason' => $finish_reason,
            // max_tokens: la respuesta se cortó. El endpoint avisa al cliente y NO la guarda en el historial.
            'truncated' => $finish_reason === 'max_tokens',
        ];
    }

    /**
     * Enviar query analítica con system prompt completo y schema JSON
     *
     * @param string $user_query Pregunta del usuario
     * @param array $course_context Contexto del curso desde data_retriever
     * @param array $conversation_history Historial anterior (opcional)
     * @param int $max_tokens Máximo de tokens (defecto 800 para respuestas JSON)
     * @param array|string|null $custom_system_prompt String, o array de bloques con
     *                          cache_control (system_prompt_designer::generate_system_blocks()).
     * @return array Respuesta con structure [answer, tokens_used, schema_valid]
     * @throws \moodle_exception
     */
    public function send_analytics_query_with_schema(
        string $user_query,
        array $course_context = [],
        array $conversation_history = [],
        int $max_tokens = 800,
        $custom_system_prompt = null
    ): array {
        require_once(__DIR__ . '/system_prompt_designer.php');

        // Cuidado: `?:` trataría un array vacío como falsy. Se comprueba null/''
        // explícitamente para no descartar unos bloques válidos.
        $system_prompt = ($custom_system_prompt === null || $custom_system_prompt === '')
            ? system_prompt_designer::generate_prompt_with_context($course_context)
            : $custom_system_prompt;

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
            'cache_creation_input_tokens' => $response['cache_creation_input_tokens'] ?? 0,
            'cache_read_input_tokens' => $response['cache_read_input_tokens'] ?? 0,
            'model' => $response['model'],
            'schema_valid' => $is_valid ? true : false,
            'schema_data' => $is_valid,
            'finish_reason' => $response['finish_reason'],
            'truncated' => !empty($response['truncated']),
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
     * @param bool $isteacher false = modo alumno: solo sugerencias de contenido.
     *                        El llamador filtra además el resultado con
     *                        chat_pipeline::filter_student_followups(), porque el
     *                        modelo no siempre respeta la restricción.
     * @return array Array con 2-3 preguntas sugeridas
     */
    public function generate_followup_questions(
        string $user_query,
        string $ai_response,
        array $course_context = [],
        bool $isteacher = true
    ): array {
        try {
            // System prompt para generar preguntas de seguimiento
            if ($isteacher) {
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
            } else {
                $system_prompt = <<<'PROMPT'
Eres un asistente educativo inteligente. Hablas con un ALUMNO del curso.
Basándote en la pregunta del usuario y la respuesta anterior, genera exactamente 2-3 preguntas de
seguimiento naturales que el alumno podría hacer a continuación SOBRE EL CONTENIDO del curso
(resúmenes, explicaciones, materiales, secciones, actividades).

Las preguntas deben:
- Ser breves (máx 80 caracteres)
- Explorar diferentes aspectos del contenido
- Ser fáciles de entender
- Estar en el mismo idioma que la pregunta original

PROHIBIDO sugerir preguntas sobre notas, calificaciones, medias, entregas, intentos, accesos,
alumnos en riesgo, rankings, número de alumnos o cualquier dato de otros estudiantes: el alumno
no tiene permiso para verlos y esas sugerencias se le rechazarían.

Retorna SOLO un JSON válido sin texto adicional:
{
    "followup_questions": [
        "¿Pregunta 1?",
        "¿Pregunta 2?",
        "¿Pregunta 3?"
    ]
}
PROMPT;
            }

            // Preparar el mensaje del usuario para generar preguntas (truncar
            // respuesta si es muy larga). mb_substr, no substr: cortar bytes
            // parte un acento en dos y json_encode() devolvía false, con lo que
            // esta llamada fallaba en silencio y nunca había sugerencias.
            $truncated_response = mb_substr($ai_response, 0, 500, 'UTF-8');
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
                CURLOPT_POSTFIELDS => $this->encode_payload($payload),
                CURLOPT_HTTPHEADER => $this->headers(),
            ]);

            $this->apply_proxy_settings($curl, $this->apiurl);

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
