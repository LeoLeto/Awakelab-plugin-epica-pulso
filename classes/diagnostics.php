<?php
/**
 * Comprobaciones de diagnóstico para quien administra el sitio (v1.31.0): claves y
 * servicios externos, Épica, cron, tareas adhoc del plugin y cursos sin texto indexado.
 *
 * Lo usan diagnostico.php y los botones «Check API key» de los ajustes
 * (check_api_key.php / check_anthropic_key.php). TODAS las llamadas de red van por el
 * wrapper \curl de Moodle, que respeta el proxy del sitio ($CFG->proxyhost…): con
 * curl_init() a pelo, en un Moodle detrás de proxy las comprobaciones fallaban aunque el
 * chat funcionara.
 *
 * Nunca se devuelve una clave: los mensajes pasan por scrub() y solo llevan texto nuestro
 * o, como mucho, el código de motivo genérico del proveedor.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/anthropic_connector.php');
require_once(__DIR__ . '/epica_client.php');
require_once(__DIR__ . '/ampliacion_service.php');

class diagnostics {

    const OK = 'ok';
    const WARN = 'warn';
    const FAIL = 'fail';

    /** Timeout (s) de cada comprobación de red. */
    const TIMEOUT = 10;

    /** @var string[] Ajustes que contienen claves, para borrarlas de cualquier mensaje. */
    const SECRET_SETTINGS = ['anthropic_key', 'openai_key', 'youtube_api_key', 'openalex_api_key'];

    /** Resultado de una comprobación. */
    private static function result(string $status, string $message): array {
        return ['status' => $status, 'message' => self::scrub($message)];
    }

    /** Quita las claves configuradas de un texto y lo acota (mb_*, nunca substr). */
    public static function scrub(string $message): string {
        foreach (self::SECRET_SETTINGS as $name) {
            $key = trim((string)get_config('block_pulso', $name));
            if ($key !== '') {
                $message = str_replace($key, '***', $message);
            }
        }
        return mb_substr($message, 0, 300, 'UTF-8');
    }

    /**
     * GET con el \curl de Moodle (proxy incluido).
     *
     * @return array{0:int,1:string,2:int,3:string} [errno, error, http, body]
     */
    private static function get(string $url, array $headers): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => self::TIMEOUT, 'CURLOPT_CONNECTTIMEOUT' => self::TIMEOUT]);
        $curl->setHeader($headers);
        $body = $curl->get($url);
        return [(int)$curl->errno, (string)$curl->error, (int)($curl->info['http_code'] ?? 0), (string)$body];
    }

    /** Resultado común de «¿responde el servicio y acepta la clave?». */
    private static function from_http(string $service, int $errno, string $error, int $http, string $body, string $badkey): array {
        if ($errno) {
            return self::result(self::FAIL, "{$service}: no se puede conectar (cURL {$errno}: {$error}). "
                . 'Revisa la salida a internet y el proxy de Moodle.');
        }
        if ($http === 200) {
            return self::result(self::OK, "{$service}: responde y acepta la clave.");
        }
        if (in_array($http, [401, 403], true)) {
            return self::result(self::FAIL, "{$service}: {$badkey} (HTTP {$http}).");
        }
        if ($http === 429 || $http >= 500) {
            return self::result(self::WARN, "{$service}: no responde bien ahora mismo (HTTP {$http}); puede ser pasajero.");
        }
        $data = json_decode($body, true);
        $reason = is_array($data) ? (string)($data['error']['errors'][0]['reason'] ?? $data['error']['type'] ?? '') : '';
        return self::result(self::FAIL, "{$service}: respuesta inesperada (HTTP {$http}"
            . ($reason !== '' ? ", {$reason}" : '') . ').');
    }

    public static function check_anthropic(): array {
        $key = trim((string)get_config('block_pulso', 'anthropic_key'));
        if ($key === '') {
            return self::result(self::FAIL, 'Anthropic: falta configurar la clave. Sin ella el chat no funciona.');
        }
        [$errno, $error, $http, $body] = self::get('https://api.anthropic.com/v1/models', [
            'x-api-key: ' . $key,
            'anthropic-version: ' . anthropic_connector::API_VERSION,
        ]);
        return self::from_http('Anthropic', $errno, $error, $http, $body, 'la clave no es válida o no tiene permisos');
    }

    public static function check_openai(): array {
        $key = trim((string)get_config('block_pulso', 'openai_key'));
        if ($key === '') {
            $ragon = (bool)get_config('block_pulso', 'rag_enabled');
            return self::result($ragon ? self::FAIL : self::WARN, $ragon
                ? 'OpenAI: falta la clave y el RAG está activado: no hay embeddings.'
                : 'OpenAI: sin clave. Opcional: solo hace falta para el RAG semántico (el texto de Crear no depende de ella).');
        }
        [$errno, $error, $http, $body] = self::get('https://api.openai.com/v1/models', ['Authorization: Bearer ' . $key]);
        return self::from_http('OpenAI', $errno, $error, $http, $body, 'la clave no es válida o no tiene permisos');
    }

    public static function check_youtube(): array {
        $key = trim((string)get_config('block_pulso', 'youtube_api_key'));
        if ($key === '') {
            return self::result(self::WARN, 'YouTube: sin clave. «Ampliar recurso» ofrecerá solo artículos.');
        }
        // videos.list cuesta 1 unidad de cuota (search.list, 100): lo justo para validar la clave.
        [$errno, $error, $http, $body] = self::get(
            'https://www.googleapis.com/youtube/v3/videos?part=id&id=dQw4w9WgXcQ',
            ['X-Goog-Api-Key: ' . $key]
        );
        return self::from_http('YouTube', $errno, $error, $http, $body, 'la clave no es válida o no tiene permisos');
    }

    public static function check_openalex(): array {
        $key = trim((string)get_config('block_pulso', 'openalex_api_key'));
        $headers = $key !== '' ? ['Authorization: Bearer ' . $key] : [];
        [$errno, $error, $http, $body] = self::get('https://api.openalex.org/works?per_page=1&select=id', $headers);
        $res = self::from_http('OpenAlex', $errno, $error, $http, $body, 'la clave no es válida');
        if ($res['status'] === self::OK && $key === '') {
            $res = self::result(self::OK, 'OpenAlex: responde (sin clave: cupo gratuito más bajo).');
        }
        return $res;
    }

    /** ¿Está Épica disponible (plugin + configuración + URL https)? */
    public static function check_epica(): array {
        if (epica_client::disponible()) {
            return self::result(self::OK, 'Épica disponible: local_awkepica instalado y configurado, URL ' . trim((string)get_config('block_pulso', 'epica_base_url')) . '.');
        }
        $why = [];
        if (!class_exists('\local_awkepica\epica')) {
            $why[] = 'local_awkepica no está instalado';
        } else if (!\local_awkepica\epica::configurado()) {
            $why[] = 'local_awkepica no tiene plataforma/secreto configurados';
        }
        $urlerror = epica_client::base_url_error();
        if ($urlerror !== null) {
            $why[] = $urlerror;
        }
        return self::result(self::WARN, 'Épica NO disponible (' . implode('; ', $why) . '). No se ofrecen infografías, juegos, retos ni historial; «Ampliar recurso» sigue.');
    }

    /** Última ejecución del cron de Moodle (la más reciente de las tareas programadas). */
    public static function check_cron(): array {
        global $DB;
        $last = (int)$DB->get_field_sql('SELECT MAX(lastruntime) FROM {task_scheduled}');
        if ($last <= 0) {
            return self::result(self::FAIL, 'Cron: no consta ninguna ejecución. Sin cron no se indexa el texto ni se procesan los encargos.');
        }
        $age = time() - $last;
        $when = userdate($last) . ' (hace ' . format_time($age) . ')';
        if ($age > 30 * MINSECS) {
            return self::result(self::FAIL, 'Cron: la última ejecución fue ' . $when . '. Parece parado.');
        }
        if ($age > 5 * MINSECS) {
            return self::result(self::WARN, 'Cron: la última ejecución fue ' . $when . '. Va con retraso.');
        }
        return self::result(self::OK, 'Cron: última ejecución ' . $when . '.');
    }

    /** Tareas adhoc de block_pulso pendientes o fallidas (encargos de Épica, indexación bajo demanda). */
    public static function check_adhoc(): array {
        global $DB;
        $rows = $DB->get_records_select('task_adhoc', 'component = :c', ['c' => 'block_pulso'], '', 'id, classname, nextruntime, faildelay');
        $queued = count($rows);
        if ($queued === 0) {
            return self::result(self::OK, 'Tareas adhoc de Pulse: ninguna en cola.');
        }
        $failed = 0;
        $overdue = 0;
        $now = time();
        foreach ($rows as $row) {
            if ((int)$row->faildelay > 0) {
                $failed++;
            }
            if ((int)$row->nextruntime < $now - 10 * MINSECS) {
                $overdue++;
            }
        }
        $msg = "Tareas adhoc de Pulse: {$queued} en cola, {$failed} con fallos, {$overdue} con más de 10 minutos de retraso.";
        return self::result(($failed > 0 || $overdue > 0) ? self::WARN : self::OK, $msg);
    }

    /** Tarea programada de indexación: última ejecución y si está desactivada. */
    public static function check_index_task(): array {
        global $DB;
        $task = $DB->get_record('task_scheduled', ['classname' => '\block_pulso\task\index_course_content']);
        if (!$task) {
            return self::result(self::WARN, 'Tarea de indexación: no registrada (¿falta pasar por Notificaciones?).');
        }
        if (!empty($task->disabled)) {
            return self::result(self::WARN, 'Tarea de indexación: DESACTIVADA. El texto de Crear no se actualiza solo.');
        }
        $when = (int)$task->lastruntime > 0 ? userdate((int)$task->lastruntime) : 'nunca';
        return self::result(self::OK, 'Tarea de indexación: activa, última ejecución: ' . $when . '.');
    }

    /**
     * Cursos con el bloque Pulse y SIN ninguna fila de texto completo.
     *
     * @return array{total:int, courses: array<int,string>} máx. 50 nombres.
     */
    public static function courses_without_text(): array {
        global $DB;
        $from = "FROM {block_instances} bi
                 JOIN {context} ctx ON ctx.id = bi.parentcontextid AND ctx.contextlevel = :lvl
                 JOIN {course} c ON c.id = ctx.instanceid
                WHERE bi.blockname = 'pulso'
                  AND NOT EXISTS (SELECT 1 FROM {block_pulso_full_text} ft WHERE ft.courseid = c.id)";
        $params = ['lvl' => CONTEXT_COURSE];

        $total = (int)$DB->count_records_sql("SELECT COUNT(DISTINCT c.id) $from", $params);
        $rows = $DB->get_records_sql("SELECT DISTINCT c.id, c.fullname $from ORDER BY c.id", $params, 0, 50);
        $names = [];
        foreach ($rows as $row) {
            $names[(int)$row->id] = format_string($row->fullname);
        }
        return ['total' => $total, 'courses' => $names];
    }
}
