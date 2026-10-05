<?php
/**
 * Errores de los endpoints AJAX, siempre con la misma forma para el navegador:
 *
 *   {success:false, error_code:'busy'|'config'|..., message:'texto para persona',
 *    detail:'causa generica, solo para profesorado/admin'}
 *
 * REGLAS (ver CLAUDE.md, «Errores al usuario»):
 *  - El cliente decide el texto por `error_code`, nunca por `message`.
 *  - `message` y `detail` son SIEMPRE texto escrito por nosotros. Nunca el
 *    getMessage() de un Throwable cualquiera, ni el cuerpo de error de
 *    Anthropic/OpenAI/Epica, ni codigos HTTP.
 *  - El detalle tecnico va a error_log (aqui) o a debuginfo, nunca al cliente.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

class pulso_error extends \Exception {

    /** @var string Codigo estable (busy, config, network, session, access, disabled...). */
    public $errorcode;

    /** @var int Codigo HTTP con el que contesta el endpoint. */
    public $status;

    /** @var string Causa generica para quien administra (nunca texto de una API). */
    public $detail;

    /**
     * @param string $errorcode Codigo estable.
     * @param string $message Texto para la persona, ya en castellano. Vacio = el
     *        texto por defecto del codigo (lang string `err_<codigo>`).
     * @param int $status HTTP.
     * @param string $detail Causa generica para profesorado/admin (opcional).
     */
    public function __construct(string $errorcode, string $message = '', int $status = 400, string $detail = '') {
        parent::__construct($message !== '' ? $message : self::text($errorcode));
        $this->errorcode = $errorcode;
        $this->status = $status;
        $this->detail = $detail;
    }

    /** HTTP por defecto de cada codigo. */
    const STATUS = [
        'session' => 401,
        'access' => 403,
        'disabled' => 403,
        'bad_request' => 400,
        'busy' => 503,
        'config' => 503,
        'network' => 503,
        'empty' => 502,
        'refusal' => 422,
        'encoding' => 400,
        'unknown' => 500,
    ];

    /**
     * Texto para persona de un codigo (lang/es o lang/en segun el usuario).
     *
     * @param string $errorcode
     * @return string
     */
    public static function text(string $errorcode): string {
        $key = 'err_' . $errorcode;
        if (!get_string_manager()->string_exists($key, 'block_pulso')) {
            $key = 'err_unknown';
        }
        return get_string($key, 'block_pulso');
    }

    /**
     * Exige una sesion iniciada. Va ANTES de cualquier otra comprobacion: una
     * sesion caducada tiene que dar «Tu sesion ha caducado», no un fallo de curso.
     *
     * @throws pulso_error code=session
     */
    public static function require_session(): void {
        if (!isloggedin() || isguestuser()) {
            throw new self('session', '', 401);
        }
    }

    /**
     * Valida el sesskey. Equivale a require_sesskey(), pero con codigo estable:
     * un sesskey invalido casi siempre es una pagina abierta desde hace horas.
     *
     * @throws pulso_error code=session
     */
    public static function require_sesskey(): void {
        if (!confirm_sesskey()) {
            throw new self('session', '', 401);
        }
    }

    /**
     * ¿Puede esta persona ver la causa generica del error? Solo administracion
     * del sitio o quien tiene `viewanalytics` en el curso.
     *
     * @param int $courseid
     * @return bool
     */
    public static function can_see_detail(int $courseid = 0): bool {
        try {
            if (!isloggedin() || isguestuser()) {
                return false;
            }
            if (has_capability('moodle/site:config', \context_system::instance())) {
                return true;
            }
            return $courseid > 0
                && has_capability('block/pulso:viewanalytics', \context_course::instance($courseid));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Convierte cualquier Throwable en la respuesta que ve el navegador.
     *
     * @param \Throwable $e
     * @param string $where Para el log (p. ej. 'api_chat').
     * @param int $courseid Para decidir si se enseña `detail`.
     * @return array [int $httpstatus, array $payload]
     */
    public static function to_response(\Throwable $e, string $where, int $courseid = 0): array {
        $err = self::classify($e, $where);

        $payload = [
            'success' => false,
            'error_code' => $err->errorcode,
            'message' => $err->getMessage(),
        ];
        if ($err->detail !== '' && self::can_see_detail($courseid)) {
            $payload['detail'] = $err->detail;
        }
        return [$err->status, $payload];
    }

    /**
     * Emite la respuesta de error JSON del endpoint (cabecera + HTTP + cuerpo).
     *
     * @param \Throwable $e
     * @param string $where
     * @param int $courseid
     */
    public static function send_json(\Throwable $e, string $where, int $courseid = 0): void {
        [$status, $payload] = self::to_response($e, $where, $courseid);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($status);
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Clasifica un Throwable en un pulso_error. El detalle tecnico se registra
     * aqui en error_log; el pulso_error devuelto solo lleva texto nuestro.
     *
     * @param \Throwable $e
     * @param string $where
     * @return pulso_error
     */
    public static function classify(\Throwable $e, string $where): pulso_error {
        if ($e instanceof self) {
            return $e;
        }

        $debug = $e instanceof \moodle_exception ? (string)($e->debuginfo ?? '') : '';
        error_log('Pulso ' . $where . ': ' . get_class($e) . ': ' . $e->getMessage()
            . ($debug !== '' ? ' [' . $debug . ']' : ''));

        // Sesion y acceso (Moodle).
        if ($e instanceof \require_login_session_timeout_exception) {
            return new self('session', '', 401);
        }
        if ($e instanceof \require_login_exception || $e instanceof \required_capability_exception) {
            return new self('access', '', 403);
        }
        if ($e instanceof \dml_missing_record_exception) {
            return new self('bad_request', '', 400);
        }

        if ($e instanceof \moodle_exception) {
            switch ($e->errorcode) {
                case 'invalidsesskey':
                case 'requireloginerror':
                case 'sessiontimedout':
                    return new self('session', '', 401);
                case 'nopermissions':
                case 'accessdenied':
                    return new self('access', '', 403);
                case 'error_no_apikey_anthropic':
                    return new self('config', '', 503, get_string('err_detail_nokey_anthropic', 'block_pulso'));
                case 'error_no_apikey':
                    return new self('config', '', 503, get_string('err_detail_nokey_openai', 'block_pulso'));
                case 'error_api_connection':
                    return new self('network', '', 503);
                case 'error_refusal':
                    return new self('refusal', '', 422);
                case 'error_empty_response':
                    return new self('empty', '', 502);
                case 'error_payload_encoding':
                    return new self('encoding', '', 400);
                case 'error_api_response':
                    return self::classify_api_error($debug);
            }
        }

        return new self('unknown', '', 500);
    }

    /**
     * Error devuelto por la API de Anthropic: el debuginfo empieza por
     * "HTTP <codigo> (<tipo>): <mensaje>" (ver anthropic_connector).
     *
     * @param string $debug
     * @return pulso_error
     */
    private static function classify_api_error(string $debug): pulso_error {
        $http = 0;
        $type = '';
        if (preg_match('/^HTTP (\d{3})(?: \(([a-z_]+)\))?/', $debug, $m)) {
            $http = (int)$m[1];
            $type = $m[2] ?? '';
        }

        // Sobrecarga o limite de ritmo: pasajero, vale reintentar en un minuto.
        if (in_array($http, [429, 529], true) || in_array($type, ['overloaded_error', 'rate_limit_error'], true)
                || ($http >= 500 && !in_array($http, [504, 408], true))) {
            return new self('busy', '', 503);
        }
        if (in_array($http, [504, 408], true)) {
            return new self('network', '', 503);
        }

        // El resto es configuracion: el alumno solo ve «no disponible»; quien
        // administra ve la causa generica (sin el texto de la API).
        if (in_array($http, [401, 403], true) || $type === 'authentication_error' || $type === 'permission_error') {
            $detail = get_string('err_detail_badkey', 'block_pulso');
        } else if ($http === 402 || preg_match('/credit balance|billing/i', $debug)) {
            $detail = get_string('err_detail_nocredit', 'block_pulso');
        } else if ($http === 404 || $type === 'not_found_error') {
            $detail = get_string('err_detail_badmodel', 'block_pulso');
        } else {
            $detail = get_string('err_detail_rejected', 'block_pulso');
        }
        return new self('config', '', 503, $detail);
    }
}
