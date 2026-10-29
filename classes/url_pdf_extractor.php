<?php
/**
 * URL PDF Extractor — texto de los PDF enlazados desde módulos URL de dominios de confianza (v2.10.0)
 *
 * Un mod_url cuyo `externalurl` apunta a un PDF de un host de la lista `url_dominios_confianza`
 * (por defecto media.awakelab.world) se descarga y se indexa como cualquier otro recurso. Solo
 * corre desde las tareas de cron (vía content_extractor::extract_course_content()), NUNCA en una
 * petición de chat.
 *
 * Seguridad, en este orden:
 *  - Solo https, puerto 443 o ninguno, sin userinfo, y el host EXACTAMENTE en la lista (nada de
 *    sufijos, ni IP, ni localhost). Se comprueba antes de cada petición, también en cada redirección.
 *  - Sin FOLLOWLOCATION: las redirecciones se siguen a mano (máx. MAX_REDIRECTS) y solo hacia otro
 *    host de la lista. Además se vuelve a validar la URL efectiva que informa cURL.
 *  - Tope de bytes (se aborta al pasarlo, no se descarga entero), timeout y firma %PDF.
 *  - El texto descargado es MATERIAL: nunca instrucciones, y nada de él va al log (solo host,
 *    tamaño, HTTP y motivo).
 *
 * Sin db/: los validadores HTTP (ETag/Last-Modified) y el hash del binario viven en un ajuste de
 * plugin por curso (`urlpdfcache_<courseid>`, JSON), que se limpia al borrar el curso.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

class url_pdf_extractor {

    /** Ajuste de plugin con la lista (uno por línea). Vacío = desactivado. */
    const SETTING = 'url_dominios_confianza';

    /** Valor por defecto del ajuste (mientras no se haya guardado nunca). */
    const DEFAULT_DOMAINS = 'media.awakelab.world';

    /** Prefijo del ajuste por curso con validadores, hash y estado de cada módulo. */
    const CACHE_PREFIX = 'urlpdfcache_';

    /** Máx. bytes de un PDF; al pasarlo se aborta la descarga. */
    const MAX_BYTES = 40 * 1024 * 1024;

    /** Timeout total de una petición, en segundos. */
    const TIMEOUT_S = 60;

    /** Máx. redirecciones que se siguen a mano. */
    const MAX_REDIRECTS = 3;

    /** Segundos de descarga por pasada de curso; pasado el presupuesto se reutiliza lo ya guardado. */
    const PASS_BUDGET_S = 300;

    /** Máx. caracteres de texto que se guardan (corte con mb_*). */
    const MAX_CHARS = 300000;

    /** Prefijo de `extraido_por` de estas filas (también lo usa la reutilización de texto). */
    const EXTRACTED_PREFIX = 'url: pdf';

    /** Motivos que cuentan como fallo a revisar en el diagnóstico (el resto es normal o informativo). */
    const REASON_LABELS = [
        'host' => 'host fuera de la lista',
        'esquema' => 'URL no https/443 o con formato raro',
        'sin_url' => 'sin dirección',
        'descarga' => 'fallo de descarga',
        'redireccion' => 'redirección no permitida',
        'no_pdf' => 'no es un PDF',
        'demasiado_grande' => 'PDF demasiado grande',
        'sin_texto' => 'PDF sin texto legible',
        'presupuesto' => 'sin tiempo en esta pasada',
    ];

    /** @var content_extractor */
    private content_extractor $ce;

    /** @var int */
    private int $courseid;

    /** @var callable|null function(string $url, array $headers): array — ver http_get() */
    private $fetcher;

    /** @var string[] lista ya normalizada */
    private array $domains;

    /** @var array cmid => entrada de caché (carga) */
    private array $oldmods = [];

    /** @var array cmid => entrada de caché (esta pasada) */
    private array $newmods = [];

    /** @var array motivo => nº de módulos URL descartados sin intento de descarga */
    private array $skipped = [];

    /** @var float segundos gastados descargando en esta pasada */
    private float $spent = 0.0;

    /** @var string JSON guardado al empezar, para no reescribir el ajuste si no cambia */
    private string $loadedjson = '';

    /**
     * @param callable|null $fetcher Sustituye a la descarga real (pruebas).
     * @param string[]|null $domains Sustituye a la lista del ajuste (pruebas).
     */
    public function __construct(content_extractor $ce, int $courseid, ?callable $fetcher = null, ?array $domains = null) {
        $this->ce = $ce;
        $this->courseid = $courseid;
        $this->fetcher = $fetcher;
        $this->domains = $domains ?? self::allowed_domains();

        $raw = (string)get_config('block_pulso', self::CACHE_PREFIX . $courseid);
        $this->loadedjson = $raw;
        $data = $raw !== '' ? json_decode($raw, true) : null;
        if (is_array($data) && isset($data['m']) && is_array($data['m'])) {
            foreach ($data['m'] as $cmid => $entry) {
                if (is_array($entry)) {
                    $this->oldmods[(int)$cmid] = $entry;
                }
            }
        }
    }

    // ----------------------------------------------------------------
    // Lista de dominios
    // ----------------------------------------------------------------

    /** ¿Es un nombre de host válido y NO una IP ni localhost? (minúsculas, sin punto final). */
    public static function is_valid_hostname(string $host): bool {
        if ($host === '' || strlen($host) > 253 || $host === 'localhost') {
            return false;
        }
        if (!preg_match('/\A([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\z/', $host)) {
            return false;
        }
        // Una IPv4 casa con lo de arriba: el último segmento de un host real no es solo dígitos.
        return !preg_match('/(\A|\.)[0-9]+\z/', $host);
    }

    /**
     * Valor del ajuste → lista de hosts (minúsculas, sin duplicados). Las líneas inválidas se ignoran
     * (el ajuste las rechaza al guardar; esto es la segunda red por si el valor se tocó a mano).
     *
     * @return string[]
     */
    public static function parse_domains(string $raw): array {
        $out = [];
        foreach (preg_split('/\R/u', $raw) as $line) {
            $host = strtolower(trim($line));
            if ($host !== '' && self::is_valid_hostname($host)) {
                $out[$host] = $host;
            }
        }
        return array_values($out);
    }

    /** Validación al guardar el ajuste: true o el texto del error (cadena de lang). */
    public static function validate_domains_setting(string $raw) {
        foreach (preg_split('/\R/u', $raw) as $line) {
            $host = trim($line);
            if ($host === '') {
                continue;
            }
            if (!self::is_valid_hostname($host)) {
                return get_string('err_url_dominios', 'block_pulso', s($host));
            }
        }
        return true;
    }

    /** @return string[] Lista efectiva (vacía = función desactivada). */
    public static function allowed_domains(): array {
        $raw = get_config('block_pulso', self::SETTING);
        if ($raw === false) {
            $raw = self::DEFAULT_DOMAINS;
        }
        return self::parse_domains((string)$raw);
    }

    /**
     * ¿Se puede pedir esta URL? Https, sin userinfo, puerto 443 o ninguno y host EXACTAMENTE en la lista.
     *
     * @param string $url
     * @param string[] $domains
     * @return array{0: string|null, 1: string|null} [url normalizada, motivo] — uno de los dos es null
     */
    public static function check_url(string $url, array $domains): array {
        $url = trim($url);
        if ($url === '') {
            return [null, 'sin_url'];
        }
        // Caracteres de control y barra invertida: cURL y parse_url los leen distinto (host engañoso).
        if (preg_match('/[\x00-\x1f\x7f\\\\]/', $url)) {
            return [null, 'esquema'];
        }
        // Espacios y UTF-8 (nombres de fichero con tildes) se codifican; el host no los admite
        // y, codificado, nunca casará con la lista.
        $url = preg_replace_callback('/[\x20\x80-\xff]/', function ($m) {
            return sprintf('%%%02X', ord($m[0]));
        }, $url);

        $p = parse_url($url);
        if ($p === false || empty($p['scheme']) || empty($p['host'])) {
            return [null, 'esquema'];
        }
        if (strtolower($p['scheme']) !== 'https') {
            return [null, 'esquema'];
        }
        if (isset($p['user']) || isset($p['pass'])) {
            return [null, 'esquema'];
        }
        if (isset($p['port']) && (int)$p['port'] !== 443) {
            return [null, 'esquema'];
        }
        $host = strtolower($p['host']);
        if (!in_array($host, $domains, true) || !self::is_valid_hostname($host)) {
            return [null, 'host'];
        }
        return [$url, null];
    }

    // ----------------------------------------------------------------
    // Una pasada de curso
    // ----------------------------------------------------------------

    /** ¿Hay algún dominio de confianza configurado? */
    public function enabled(): bool {
        return !empty($this->domains);
    }

    /**
     * Procesa un módulo URL.
     *
     * @param int $cmid
     * @param string $externalurl
     * @return array{status: string, text: string, method: string}|null
     *         null = saltar el módulo (sin fila ni fragmentos); status 'indexado' con texto, o
     *         'sin_texto' (PDF sin texto legible: fila usable=0 y aviso).
     */
    public function process(int $cmid, string $externalurl): ?array {
        if (!$this->enabled()) {
            return null;
        }

        [$url, $why] = self::check_url($externalurl, $this->domains);
        if ($url === null) {
            // Un enlace a otro dominio es lo normal en un curso: solo se cuenta.
            $this->skipped[$why] = ($this->skipped[$why] ?? 0) + 1;
            return null;
        }

        $urlkey = sha1($url);
        $old = $this->oldmods[$cmid] ?? [];
        $sameurl = isset($old['u']) && $old['u'] === $urlkey;
        $stored = $sameurl ? $this->stored_text($cmid) : null;
        $knownnotext = $sameurl && ($old['s'] ?? '') === 'sin_texto';

        // Sin tiempo en esta pasada: lo guardado vale; sin nada guardado se salta (la próxima pasada lo coge).
        if ($this->spent >= self::PASS_BUDGET_S) {
            return $this->fail($cmid, $urlkey, 'presupuesto', $stored, $old);
        }

        $headers = [];
        if (($stored !== null || $knownnotext) && !empty($old['e'])) {
            $headers[] = 'If-None-Match: ' . $old['e'];
        }
        if (($stored !== null || $knownnotext) && !empty($old['l'])) {
            $headers[] = 'If-Modified-Since: ' . $old['l'];
        }

        $res = $this->download($url, $headers);
        $host = (string)parse_url($url, PHP_URL_HOST);

        if ($res['reason'] !== null) {
            error_log("Pulso URL-PDF cmid={$cmid} host={$host}: {$res['reason']} http={$res['http']} bytes={$res['bytes']}");
            return $this->fail($cmid, $urlkey, $res['reason'], $stored, $old, $res['transient']);
        }

        $entry = ['u' => $urlkey, 't' => time()];
        foreach (['e' => 'etag', 'l' => 'lastmod'] as $k => $hk) {
            if ($res[$hk] !== '') {
                $entry[$k] = $res[$hk];
            }
        }

        // 304: nada cambió. Se reutiliza el texto guardado (o el «sin texto» ya conocido).
        if ($res['http'] === 304) {
            $entry['h'] = $old['h'] ?? '';
            foreach (['e', 'l'] as $k) {
                if (!isset($entry[$k]) && isset($old[$k])) {
                    $entry[$k] = $old[$k];
                }
            }
            if ($stored !== null) {
                $entry['s'] = 'indexado';
                $this->newmods[$cmid] = $entry;
                return ['status' => 'indexado', 'text' => $stored['texto'], 'method' => $stored['method']];
            }
            if ($knownnotext) {
                $entry['s'] = 'sin_texto';
                $this->newmods[$cmid] = $entry;
                return ['status' => 'sin_texto', 'text' => '', 'method' => ''];
            }
            // 304 sin nada que reutilizar (no debería ocurrir: no se mandan validadores sin ello).
            return $this->fail($cmid, $urlkey, 'descarga', null, $old);
        }

        $body = $res['body'];
        $entry['h'] = hash('sha256', $body);

        // Mismo binario que la vez anterior (el servidor no soporta condicionales): sin reparsear.
        if ($stored !== null && ($old['h'] ?? '') === $entry['h']) {
            $entry['s'] = 'indexado';
            $this->newmods[$cmid] = $entry;
            return ['status' => 'indexado', 'text' => $stored['texto'], 'method' => $stored['method']];
        }

        $parsed = $this->parse_pdf($body);
        unset($body, $res['body']);
        if ($parsed['text'] === '') {
            error_log("Pulso URL-PDF cmid={$cmid} host={$host}: pdf sin texto legible bytes={$res['bytes']}");
            $entry['s'] = 'sin_texto';
            $this->newmods[$cmid] = $entry;
            return ['status' => 'sin_texto', 'text' => '', 'method' => ''];
        }

        $text = $parsed['text'];
        if (mb_strlen($text, 'UTF-8') > self::MAX_CHARS) {
            $text = mb_substr($text, 0, self::MAX_CHARS, 'UTF-8');
            error_log("Pulso URL-PDF cmid={$cmid} host={$host}: texto recortado a " . self::MAX_CHARS . ' caracteres');
        }
        $entry['s'] = 'indexado';
        $this->newmods[$cmid] = $entry;
        return ['status' => 'indexado', 'text' => $text, 'method' => $parsed['method']];
    }

    /**
     * Cierra la pasada: guarda la caché del curso (solo los módulos vistos) o la borra si la
     * función está desactivada. Solo escribe si cambió.
     */
    public function finish(): void {
        $name = self::CACHE_PREFIX . $this->courseid;
        if (!$this->enabled()) {
            if ($this->loadedjson !== '') {
                unset_config($name, 'block_pulso');
            }
            return;
        }
        $json = json_encode(['m' => $this->newmods, 'x' => $this->skipped]);
        if ($json !== false && $json !== $this->loadedjson) {
            set_config($name, $json, 'block_pulso');
        }
    }

    /** Estado de la pasada (para pruebas y log). */
    public function get_state(): array {
        return ['m' => $this->newmods, 'x' => $this->skipped];
    }

    /**
     * Fallo en un módulo con dominio permitido. Un fallo TRANSITORIO (red, 5xx, 429, presupuesto) con
     * texto ya guardado lo conserva (no se borra material bueno por un corte); un fallo definitivo
     * (404, no es PDF, demasiado grande…) quita el módulo del índice.
     */
    private function fail(int $cmid, string $urlkey, string $reason, ?array $stored, array $old, bool $transient = true): ?array {
        if ($stored !== null && $transient) {
            $entry = $old;
            $entry['u'] = $urlkey;
            $entry['s'] = 'indexado';
            $entry['w'] = $reason;
            $this->newmods[$cmid] = $entry;
            return ['status' => 'indexado', 'text' => $stored['texto'], 'method' => $stored['method']];
        }
        $this->newmods[$cmid] = ['u' => $urlkey, 't' => time(), 's' => $reason];
        return null;
    }

    /**
     * Texto guardado de ESTE módulo si lo produjo este extractor (url: pdf, usable).
     *
     * @return array{texto: string, method: string}|null
     */
    private function stored_text(int $cmid): ?array {
        try {
            $row = full_text_store::get_resource_text($this->courseid, $cmid);
        } catch (\Throwable $e) {
            return null;
        }
        if ($row === null || strpos((string)$row['extraido_por'], self::EXTRACTED_PREFIX) !== 0 || $row['texto'] === '') {
            return null;
        }
        // 'url: pdf (<método>)' → <método>
        $method = (string)$row['extraido_por'];
        if (preg_match('/\Aurl: pdf \((.*)\)\z/s', $method, $m)) {
            $method = $m[1];
        }
        return ['texto' => (string)$row['texto'], 'method' => $method];
    }

    // ----------------------------------------------------------------
    // Descarga
    // ----------------------------------------------------------------

    /**
     * Descarga con redirecciones manuales validadas.
     *
     * @return array{reason: string|null, transient: bool, http: int, bytes: int, body: string,
     *               etag: string, lastmod: string}
     *         reason null = hay respuesta usable (200 con PDF, o 304).
     */
    private function download(string $url, array $headers): array {
        $out = ['reason' => 'descarga', 'transient' => true, 'http' => 0, 'bytes' => 0, 'body' => '', 'etag' => '', 'lastmod' => ''];
        $start = microtime(true);

        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                [$checked, $why] = self::check_url($url, $this->domains);
                if ($checked === null) {
                    // Primera petición ya validada: solo puede ser una redirección a otro sitio.
                    $out['reason'] = 'redireccion';
                    $out['transient'] = false;
                    return $out;
                }

                $r = $this->http_get($checked, $headers);
                $out['http'] = (int)$r['http'];
                $out['bytes'] = strlen((string)$r['body']);

                if ($r['too_big']) {
                    $out['reason'] = 'demasiado_grande';
                    $out['transient'] = false;
                    $out['body'] = '';
                    return $out;
                }
                if ($r['errno'] || $r['http'] === 0) {
                    return $out; // 'descarga', transitorio
                }
                // URL efectiva que informa cURL: si un FOLLOWLOCATION se hubiera colado, no vale.
                if ($r['effective'] !== '' && $r['effective'] !== $checked) {
                    [$eff, ] = self::check_url($r['effective'], $this->domains);
                    if ($eff === null) {
                        $out['reason'] = 'redireccion';
                        $out['transient'] = false;
                        return $out;
                    }
                }

                if (in_array($r['http'], [301, 302, 303, 307, 308], true)) {
                    $loc = trim((string)$r['location']);
                    if ($loc === '') {
                        $out['transient'] = false;
                        return $out;
                    }
                    $url = $this->resolve_location($checked, $loc);
                    if ($url === '') {
                        $out['reason'] = 'redireccion';
                        $out['transient'] = false;
                        return $out;
                    }
                    continue;
                }

                if ($r['http'] === 304) {
                    $out['reason'] = null;
                    $out['etag'] = $r['etag'];
                    $out['lastmod'] = $r['lastmod'];
                    return $out;
                }

                if ($r['http'] !== 200) {
                    // 5xx, 408 y 429 son transitorios; el resto (404, 403, 410…) no.
                    $out['transient'] = $r['http'] >= 500 || in_array($r['http'], [408, 429], true);
                    return $out;
                }

                $out['transient'] = false;
                $ct = strtolower(trim(explode(';', (string)$r['ctype'])[0]));
                $head = substr((string)$r['body'], 0, 1024);
                if (($ct !== '' && strpos($ct, 'text/') === 0) || strpos($head, '%PDF-') === false) {
                    $out['reason'] = 'no_pdf';
                    $out['body'] = '';
                    return $out;
                }
                $out['reason'] = null;
                $out['body'] = (string)$r['body'];
                $out['etag'] = $r['etag'];
                $out['lastmod'] = $r['lastmod'];
                return $out;
            }
            // Demasiadas redirecciones.
            $out['reason'] = 'redireccion';
            $out['transient'] = false;
            return $out;
        } catch (\Throwable $e) {
            // Solo la clase: el mensaje puede llevar la URL o restos de la respuesta.
            error_log('Pulso URL-PDF: excepción en la descarga: ' . get_class($e));
            return $out;
        } finally {
            $this->spent += microtime(true) - $start;
        }
    }

    /** Location (absoluta https o con ruta absoluta) → URL; '' si no se puede resolver con seguridad. */
    private function resolve_location(string $current, string $loc): string {
        if (preg_match('#\Ahttps://#i', $loc)) {
            return $loc;
        }
        if (isset($loc[0]) && $loc[0] === '/' && substr($loc, 0, 2) !== '//') {
            $p = parse_url($current);
            return 'https://' . $p['host'] . $loc;
        }
        return '';
    }

    /**
     * Una petición GET sin seguir redirecciones. Con $fetcher (pruebas) delega en él.
     *
     * @return array{errno: int, http: int, body: string, too_big: bool, location: string, ctype: string,
     *               etag: string, lastmod: string, effective: string}
     */
    private function http_get(string $url, array $headers): array {
        if ($this->fetcher !== null) {
            $r = ($this->fetcher)($url, $headers);
            return $r + [
                'errno' => 0, 'http' => 200, 'body' => '', 'too_big' => false, 'location' => '',
                'ctype' => 'application/pdf', 'etag' => '', 'lastmod' => '', 'effective' => '',
            ];
        }

        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $max = self::MAX_BYTES;
        $toobig = false;
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_TIMEOUT' => self::TIMEOUT_S,
            'CURLOPT_CONNECTTIMEOUT' => 15,
            'CURLOPT_MAXFILESIZE' => $max,
            // Aborta en cuanto lo recibido pasa del tope (Content-Length lo anuncia antes de bajar nada).
            'CURLOPT_NOPROGRESS' => false,
            'CURLOPT_PROGRESSFUNCTION' => function () use (&$toobig, $max) {
                $args = func_get_args();
                // PHP < 8.2: ($ch, $dltotal, $dlnow, …); PHP >= 8.2 también admite sin el handle.
                $nums = (is_object($args[0] ?? null) || is_resource($args[0] ?? null)) ? array_slice($args, 1) : $args;
                $dltotal = (float)($nums[0] ?? 0);
                $dlnow = (float)($nums[1] ?? 0);
                if ($dltotal > $max || $dlnow > $max) {
                    $toobig = true;
                    return 1;
                }
                return 0;
            },
        ]);
        $curl->setHeader(array_merge(['Accept: application/pdf,*/*;q=0.5'], $headers));
        $body = $curl->get($url);

        $info = (array)$curl->info;
        $res = [
            'errno' => (int)$curl->errno,
            'http' => (int)($info['http_code'] ?? 0),
            'body' => is_string($body) ? $body : '',
            // 63 = CURLE_FILESIZE_EXCEEDED (MAXFILESIZE con Content-Length); la callback marca $toobig.
            'too_big' => $toobig || (int)$curl->errno === 63,
            'location' => '', 'ctype' => '', 'etag' => '', 'lastmod' => '',
            'effective' => (string)($info['url'] ?? ''),
        ];
        // Cabeceras de la respuesta final (get_raw_response() las devuelve línea a línea).
        foreach ((array)$curl->get_raw_response() as $line) {
            if (!is_string($line) || strpos($line, ':') === false) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $k = strtolower(trim($k));
            $v = trim($v);
            if ($k === 'location') {
                $res['location'] = $v;
            } else if ($k === 'content-type') {
                $res['ctype'] = $v;
            } else if ($k === 'etag') {
                $res['etag'] = $v;
            } else if ($k === 'last-modified') {
                $res['lastmod'] = $v;
            }
        }
        return $res;
    }

    // ----------------------------------------------------------------
    // PDF
    // ----------------------------------------------------------------

    /** Texto de un PDF en memoria con el MISMO extractor de los recursos (pdfparser primero). */
    private function parse_pdf(string $body): array {
        if (function_exists('raise_memory_limit') && defined('MEMORY_EXTRA')) {
            raise_memory_limit(MEMORY_EXTRA);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'pulso_urlpdf_');
        if (!$tmp) {
            return ['text' => '', 'method' => ''];
        }
        try {
            if (file_put_contents($tmp, $body) === false) {
                return ['text' => '', 'method' => ''];
            }
            $res = $this->ce->extract_pdf_file($tmp);
            return ['text' => trim((string)$res['text']), 'method' => (string)$res['method']];
        } catch (\Throwable $e) {
            error_log('Pulso URL-PDF: fallo al extraer el texto: ' . get_class($e));
            return ['text' => '', 'method' => ''];
        } finally {
            @unlink($tmp);
        }
    }

    // ----------------------------------------------------------------
    // Diagnóstico
    // ----------------------------------------------------------------

    /**
     * Suma el estado guardado de TODOS los cursos.
     *
     * @return array{courses: int, indexed: int, warned: int, reasons: array<string,int>}
     */
    public static function summarize(): array {
        global $DB;

        $out = ['courses' => 0, 'indexed' => 0, 'warned' => 0, 'reasons' => []];
        $like = $DB->sql_like('name', ':n');
        $rows = $DB->get_records_select(
            'config_plugins',
            "plugin = :p AND $like",
            ['p' => 'block_pulso', 'n' => $DB->sql_like_escape(self::CACHE_PREFIX) . '%'],
            '',
            'id, value'
        );
        foreach ($rows as $row) {
            $data = json_decode((string)$row->value, true);
            if (!is_array($data)) {
                continue;
            }
            $out['courses']++;
            foreach ((array)($data['m'] ?? []) as $entry) {
                $s = (string)($entry['s'] ?? '');
                if ($s === 'indexado') {
                    $out['indexed']++;
                    if (!empty($entry['w'])) {
                        $out['warned']++;
                    }
                } else if ($s !== '') {
                    $out['reasons'][$s] = ($out['reasons'][$s] ?? 0) + 1;
                }
            }
            foreach ((array)($data['x'] ?? []) as $reason => $n) {
                $out['reasons'][(string)$reason] = ($out['reasons'][(string)$reason] ?? 0) + (int)$n;
            }
        }
        return $out;
    }
}
