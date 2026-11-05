<?php
/**
 * Colores de cada centro (carta 11 de Épica, v2.1.0).
 *
 * Épica guarda, centro a centro, el color principal y el de acento; Pulse los pide a
 * POST /api/moodle/tema, los guarda en la configuración del plugin y los lee al pintar.
 * Reglas (CLAUDE.md → «Colores de cada centro»):
 *  - NUNCA se llama a Épica al pintar una página: sincronizar() solo lo ejecutan la tarea
 *    programada y el botón de los ajustes.
 *  - Lo de Épica se valida DOS veces: antes de guardar y otra vez al pintar. Nada entra en
 *    el HTML sin pasar por validar_colores().
 *  - El contraste lo calculamos nosotros (WCAG). El `texto` de Épica se acepta solo si da >= 4,5:1
 *    sobre `valor`. Las superficies grandes que Épica no manda se derivan del principal oscurecido
 *    (v2.5.0): nunca un color claro con texto negro salvo que el centro lo pida explícitamente.
 *  - Sin Épica, o con tema null, no se pinta nada: Pulse se ve exactamente como siempre.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/epica_client.php');

class tema_service {

    /** Ruta de Épica (se cuelga de epica_client::endpoint()). */
    const RUTA = '/api/moodle/tema';

    /** Las únicas claves que Pulse usa (todas opcionales); cualquier otra que mande Épica se ignora. */
    const CLAVES = ['principal', 'acento', 'cabecera', 'boton', 'burbuja', 'fondo', 'creaciones'];

    const RE_CLAVE = '/^[a-z][a-z0-9-]{0,31}\z/';
    const RE_COLOR = '/^#[0-9a-f]{6}\z/';
    const RE_VERSION = '/^[0-9A-Za-z_-]{1,32}\z/';

    /** Color de cabecera de Pulse (el que hay cuando Épica no manda `principal`). */
    const PRINCIPAL_DEFECTO = '#003670';
    /** Acento de Pulse (cian de marca; solo iconos, bordes y foco, nunca texto). */
    const ACENTO_DEFECTO = '#0b93aa';
    const BLANCO = '#ffffff';
    const NEGRO = '#000000';

    /** WCAG AA para texto normal, y para elementos gráficos (foco, bordes, iconos). */
    const CONTRASTE_TEXTO = 4.5;
    const CONTRASTE_GRAFICO = 3.0;

    /** Resultados de sincronizar(). */
    const ESTADO_GUARDADO = 'guardado';
    const ESTADO_SIN_CAMBIOS = 'sin_cambios';
    const ESTADO_BORRADO = 'borrado';
    const ESTADO_SIN_TEMA = 'sin_tema';
    const ESTADO_OMITIDO = 'omitido';
    const ESTADO_ERROR = 'error';

    /**
     * El `fondo` del centro (v2.13.0) se ACLARA hacia blanco hasta que TODOS los textos fijos pequeños que Pulse
     * pinta en el panel den >= 4,5:1 sobre él: TEXTOS_FIJOS = muted, éxito, aviso, error (los que más cuestan) y
     * slate, ink y deep (siempre sobrados, se incluyen por si cambia la paleta). Las píldoras con el texto en su
     * propio color sobre un tinte rgba() de ese color (éxito/aviso/error al 12–14 %, slate al 10–12 %) se fijan
     * OPACAS sobre blanco con el token «fondo» (styles.css): así dan lo mismo que hoy (el éxito, 4,37:1 ya hoy)
     * y no empeoran con el fondo del centro. TINTES solo sirve para medirlo en las pruebas.
     * Los textos blanco/#D9FBFF van sobre fondos propios oscuros y no dependen del panel.
     */
    const TEXTOS_FIJOS = ['#3b6996', '#0f7a57', '#8a6100', '#b3261e', '#34547a', '#27334f', '#003670'];
    const TINTES = [['#0f7a57', 0.14], ['#8a6100', 0.14], ['#b3261e', 0.14], ['#34547a', 0.12]];

    /** Con fondo del centro, las superficies son MÁS CLARAS que él: esta fracción del camino hacia blanco (hoy ~3 % y ~7 % bajo blanco). */
    const K_SURFACE = 0.3;
    const K_SURFACE2 = 0.6;

    /** Logo del centro (v2.13.0): filearea propia en el contexto de sistema. */
    const LOGO_AREA = 'tema';
    const LOGO_MAX_BYTES = 204800; // 200 KB
    const LOGO_MIN_PX = 96;
    const LOGO_MAX_PX = 512;
    const LOGO_ASPECTO_MAX = 1.1; // lado mayor / lado menor: «casi cuadrado»
    const LOGO_MAX_REDIRECTS = 3;
    const LOGO_TIMEOUT_S = 20;
    const RE_LOGO_VERSION = '/^[A-Za-z0-9._-]{1,64}\z/';
    const LOGO_EXTENSIONES = ['png', 'jpg', 'webp'];

    /** Descargador de pruebas (null = el curl de Moodle). Firma: fn(string $url): array con errno, http, body, too_big, location, effective. */
    private static $descargador = null;

    /** Modo demostración (carta 16): `hasta` como mucho a 7 días vista. */
    const DEMOSTRACION_MAX_S = 7 * 86400;

    // ----------------------------------------------------------------
    // Sincronización (tarea programada y botón de ajustes; nunca al pintar).
    // ----------------------------------------------------------------

    /**
     * Pregunta a Épica por el tema del centro y lo guarda.
     *
     * 200 con tema y `version` distinta → guarda; 200 con `tema: null` → borra lo guardado;
     * fallo, timeout o http 0 → se queda con lo último guardado. El detalle técnico va a
     * error_log, nunca a la interfaz.
     *
     * @return array{estado: string, ok: bool, mensaje: string} mensaje: texto para persona.
     */
    public static function sincronizar(): array {
        if (!epica_client::disponible()) {
            return self::resultado(self::ESTADO_OMITIDO, 'tema_msg_sin_epica');
        }

        $admin = get_admin();
        if (empty($admin) || empty($admin->email)) {
            // Sin correo el token sale sin claim email y Épica lo rechaza (paso 9 de su puerta).
            error_log('Pulso tema: el administrador del sitio no tiene correo; no se puede firmar.');
            return self::fallo('sin_correo', 'tema_msg_sin_correo');
        }

        try {
            // Token nuevo en cada llamada (el jti se gasta). Rol por viewanalytics, no por
            // createactivity; sin curso: el tema es del centro. Espera corta (la de pedir()).
            $token = \local_awkepica\epica::firmar_por(
                $admin,
                \context_system::instance(),
                epica_client::CAPABILITY_ROL
            );
            $respuesta = \local_awkepica\epica::pedir(epica_client::endpoint(self::RUTA), ['token' => $token]);
        } catch (\Throwable $e) {
            error_log('Pulso tema: ' . get_class($e) . ': ' . $e->getMessage());
            return self::fallo('red', 'tema_msg_error_red');
        }

        if (epica_client::es_transitorio($respuesta)) {
            error_log('Pulso tema: ' . epica_client::describir_transitorio($respuesta));
            return self::fallo('red', 'tema_msg_error_red');
        }

        [$http, $datos] = epica_client::normalize_response($respuesta);
        if ($http !== 200) {
            $motivo = mb_substr(preg_replace('/[^\w .:-]/u', '', (string)($datos['motivo'] ?? '')), 0, 60, 'UTF-8');
            error_log("Pulso tema: Épica respondió HTTP {$http}" . ($motivo !== '' ? " ({$motivo})" : '') . '.');
            return self::fallo('rechazo', 'tema_msg_error_rechazo');
        }

        // Modo demostración (carta 16): se lee SIEMPRE con un 200, antes de cualquier salida por
        // «misma versión» o «sin tema»: es independiente del tema. Un fallo o http 0 no llega aquí.
        set_config('demostracion_hasta', self::parsear_demostracion($datos['demostracion'] ?? null, time()), 'block_pulso');

        if (!array_key_exists('tema', $datos)) {
            error_log('Pulso tema: la respuesta 200 no trae el campo «tema».');
            return self::fallo('respuesta', 'tema_msg_error_respuesta');
        }

        $tema = $datos['tema'];
        $hayguardado = self::hay_guardado();

        if ($tema === null) {
            // El centro ha vuelto al tema por defecto de Pulse (también sin logo).
            self::borrar();
            self::borrar_logo();
            self::marcar_ok();
            return self::resultado($hayguardado ? self::ESTADO_BORRADO : self::ESTADO_SIN_TEMA,
                $hayguardado ? 'tema_msg_borrado' : 'tema_msg_sin_tema');
        }

        $version = is_array($tema) ? ($tema['version'] ?? null) : null;
        $colores = is_array($tema) ? self::validar_colores($tema['colores'] ?? null) : [];
        if (!is_string($version) || !preg_match(self::RE_VERSION, $version)) {
            error_log('Pulso tema: la respuesta no trae una versión válida.');
            return self::fallo('respuesta', 'tema_msg_error_respuesta');
        }
        // Logo (v2.13.0): clave aparte de `colores` (no es un color). Ausente o null = sin logo.
        $logo = is_array($tema) ? ($tema['logo'] ?? null) : null;

        if (!$colores) {
            // Nada utilizable (p. ej. solo claves que Pulse no usa): equivale a no tener tema.
            error_log('Pulso tema: el tema no trae colores que Pulse use o que tengan forma #rrggbb.');
            self::borrar();
            if ($logo === null) {
                self::borrar_logo();
                self::marcar_ok();
                return self::resultado($hayguardado ? self::ESTADO_BORRADO : self::ESTADO_SIN_TEMA,
                    $hayguardado ? 'tema_msg_borrado' : 'tema_msg_sin_tema');
            }
            // Solo logo: los colores vuelven a ser los de Pulse y el logo se trata como siempre.
            $logoerror = !self::sincronizar_logo($logo);
            self::marcar_ok();
            return self::con_aviso_logo(self::resultado(self::ESTADO_GUARDADO, 'tema_msg_guardado', $version), $logoerror);
        }

        // El logo se mira SIEMPRE, aunque no cambie la versión del tema: tiene su propia `version`.
        $logoerror = !self::sincronizar_logo($logo);

        if ($hayguardado && (string)get_config('block_pulso', 'tema_version') === $version) {
            self::marcar_ok();
            return self::con_aviso_logo(self::resultado(self::ESTADO_SIN_CAMBIOS, 'tema_msg_sin_cambios', $version), $logoerror);
        }

        set_config('tema_json', json_encode($colores), 'block_pulso');
        set_config('tema_version', $version, 'block_pulso');
        self::marcar_ok();
        return self::con_aviso_logo(self::resultado(self::ESTADO_GUARDADO, 'tema_msg_guardado', $version), $logoerror);
    }

    /**
     * Si el logo falló: conserva el anterior (nada se tocó), deja un código GENÉRICO en tema_sync_error
     * (tras marcar_ok(), que lo borra) y avisa al administrador. Los colores ya se guardaron.
     */
    private static function con_aviso_logo(array $res, bool $logoerror): array {
        if ($logoerror) {
            set_config('tema_sync_error', 'logo', 'block_pulso');
            set_config('tema_sync_error_time', time(), 'block_pulso');
            $res['ok'] = false;
            $res['mensaje'] .= ' ' . get_string('tema_msg_logo_error', 'block_pulso');
        }
        return $res;
    }

    /**
     * `demostracion` de Épica (null o {hasta: ISO 8601 UTC}) → instante UNIX, o 0 si no hay
     * demostración o no es utilizable (no es fecha, ya pasó, o está a más de 7 días: lo último
     * dejaría al centro sin topes de Pulse por mucho tiempo ante un error de Épica).
     *
     * @param mixed $valor
     */
    public static function parsear_demostracion($valor, int $ahora): int {
        if ($valor === null) {
            return 0;
        }
        $hasta = is_array($valor) && isset($valor['hasta']) && is_string($valor['hasta'])
            ? strtotime(trim($valor['hasta'])) : false;
        if ($hasta === false || $hasta <= $ahora || $hasta > $ahora + self::DEMOSTRACION_MAX_S) {
            error_log('Pulso tema: «demostracion» no válida (no es una fecha, ya pasó o está a más de 7 días); se ignora.');
            return 0;
        }
        return (int)$hasta;
    }

    /**
     * ¿Está el centro en modo demostración de Épica (carta 16)? La hora se mira EN CADA
     * comprobación: al pasar `hasta` se vuelve al tope sin esperar a la tarea. Sin Épica, nunca.
     */
    public static function en_demostracion(): bool {
        return epica_client::disponible() && (int)get_config('block_pulso', 'demostracion_hasta') > time();
    }

    private static function resultado(string $estado, string $clave, ?string $a = null): array {
        return [
            'estado' => $estado,
            'ok' => $estado !== self::ESTADO_ERROR && $estado !== self::ESTADO_OMITIDO,
            'mensaje' => get_string($clave, 'block_pulso', $a),
        ];
    }

    /** Fallo: se conserva lo último guardado y se deja un código GENÉRICO para el diagnóstico. */
    private static function fallo(string $codigo, string $clave): array {
        set_config('tema_sync_error', $codigo, 'block_pulso');
        set_config('tema_sync_error_time', time(), 'block_pulso');
        return self::resultado(self::ESTADO_ERROR, $clave);
    }

    private static function marcar_ok(): void {
        set_config('tema_sync_time', time(), 'block_pulso');
        unset_config('tema_sync_error', 'block_pulso');
        unset_config('tema_sync_error_time', 'block_pulso');
    }

    private static function borrar(): void {
        unset_config('tema_json', 'block_pulso');
        unset_config('tema_version', 'block_pulso');
    }

    private static function hay_guardado(): bool {
        return self::leer() !== [];
    }

    // ----------------------------------------------------------------
    // Logo del centro (v2.13.0). Solo lo descarga sincronizar() (tarea/botón), NUNCA al pintar y
    // jamás se enlaza a Épica desde la página: se guarda con la File API y se sirve por pluginfile.
    // ----------------------------------------------------------------

    /** Solo para pruebas: sustituye al \curl de Moodle. Pasar null lo quita. */
    public static function poner_descargador(?callable $descargador): void {
        self::$descargador = $descargador;
    }

    /**
     * La URL del logo solo vale si es https y del host (y puerto) EXACTO de epica_base_url, sin
     * userinfo: el mismo criterio que retos_service::enlace_epica(), más rechazo de caracteres de
     * control, espacios y barra invertida (cURL y parse_url los leen distinto).
     *
     * @return string La URL si vale, '' si no.
     */
    public static function url_logo_valida($url): string {
        if (!is_string($url) || $url === '' || mb_strlen($url, 'UTF-8') > 512 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
            return '';
        }
        $partes = parse_url($url);
        $base = parse_url((string)get_config('block_pulso', 'epica_base_url'));
        if (!is_array($partes) || !is_array($base) || empty($partes['host']) || empty($base['host'])) {
            return '';
        }
        if (strtolower($partes['scheme'] ?? '') !== 'https' || isset($partes['user']) || isset($partes['pass'])) {
            return '';
        }
        if (strtolower($partes['host']) !== strtolower($base['host'])) {
            return '';
        }
        $puerto = (int)($partes['port'] ?? 443);
        $puertobase = (int)($base['port'] ?? 443);
        return $puerto === $puertobase ? $url : '';
    }

    /** `version` del logo: forma cerrada y sin segmentos «.» / «..» (va en la ruta de la URL). */
    public static function version_logo_valida($version): bool {
        return is_string($version) && preg_match(self::RE_LOGO_VERSION, $version) === 1
            && preg_match('/^\.+\z/', $version) !== 1;
    }

    /**
     * Pone al día el logo guardado con lo que manda Épica. true = bien (o sin cambios); false = fallo,
     * y entonces NO se toca el logo anterior. Al log solo host, bytes, HTTP y motivo.
     *
     * @param mixed $logo null (borrar) o {url, version}.
     */
    private static function sincronizar_logo($logo): bool {
        if ($logo === null) {
            self::borrar_logo();
            return true;
        }
        $url = is_array($logo) ? ($logo['url'] ?? null) : null;
        $version = is_array($logo) ? ($logo['version'] ?? null) : null;
        if (!self::version_logo_valida($version) || self::url_logo_valida($url) === '') {
            self::log_logo(is_string($url) ? $url : '', 0, 0, 'forma_url_o_host');
            return false;
        }
        if ((string)get_config('block_pulso', 'tema_logo_version') === $version && self::fichero_logo() !== null) {
            return true; // misma versión y el fichero está: no se redescarga
        }

        $r = self::descargar_logo($url);
        if ($r['motivo'] !== '') {
            self::log_logo($url, $r['bytes'], $r['http'], $r['motivo']);
            return false;
        }
        $v = self::validar_imagen_logo($r['body']);
        if ($v['motivo'] !== '') {
            self::log_logo($url, $r['bytes'], $r['http'], $v['motivo']);
            return false;
        }
        if (!self::guardar_logo($r['body'], $v['ext'], $version)) {
            self::log_logo($url, $r['bytes'], $r['http'], 'guardado');
            return false;
        }
        return true;
    }

    private static function log_logo(string $url, int $bytes, int $http, string $motivo): void {
        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
        $host = is_string($host) ? preg_replace('/[^A-Za-z0-9.-]/', '', $host) : '';
        error_log('Pulso tema: logo no actualizado (host ' . ($host !== '' ? $host : '?') . ', ' . $bytes . ' bytes, HTTP ' . $http
            . ', motivo ' . $motivo . '); se conserva el anterior.');
    }

    /**
     * Descarga a mano, sin FOLLOWLOCATION: cada salto (y la primera URL) se revalida con url_logo_valida().
     *
     * @return array{motivo: string, http: int, bytes: int, body: string} motivo '' = hay cuerpo 200.
     */
    private static function descargar_logo(string $url): array {
        $out = ['motivo' => 'descarga', 'http' => 0, 'bytes' => 0, 'body' => ''];
        try {
            for ($salto = 0; $salto <= self::LOGO_MAX_REDIRECTS; $salto++) {
                if (self::url_logo_valida($url) === '') {
                    $out['motivo'] = $salto > 0 ? 'redireccion' : 'url';
                    return $out;
                }
                $r = self::http_logo($url);
                $out['http'] = (int)$r['http'];
                $out['bytes'] = strlen((string)$r['body']);
                if ($r['too_big']) {
                    $out['motivo'] = 'demasiado_grande';
                    return $out;
                }
                if ($r['errno'] || $r['http'] === 0) {
                    return $out;
                }
                // Defensa en profundidad: si un FOLLOWLOCATION se hubiera colado, la URL efectiva debe valer.
                if ($r['effective'] !== '' && $r['effective'] !== $url && self::url_logo_valida($r['effective']) === '') {
                    $out['motivo'] = 'redireccion';
                    return $out;
                }
                if (in_array($r['http'], [301, 302, 303, 307, 308], true)) {
                    $url = self::resolver_location($url, (string)$r['location']);
                    if ($url === '') {
                        $out['motivo'] = 'redireccion';
                        return $out;
                    }
                    continue;
                }
                if ($r['http'] !== 200) {
                    $out['motivo'] = 'http';
                    return $out;
                }
                if ($out['bytes'] > self::LOGO_MAX_BYTES) {
                    $out['motivo'] = 'demasiado_grande';
                    return $out;
                }
                $out['motivo'] = '';
                $out['body'] = (string)$r['body'];
                return $out;
            }
            $out['motivo'] = 'redireccion';
            return $out;
        } catch (\Throwable $e) {
            error_log('Pulso tema: excepción al descargar el logo: ' . get_class($e));
            return $out;
        }
    }

    /** Location absoluta https o con ruta absoluta → URL; '' si no se puede resolver con seguridad. */
    private static function resolver_location(string $actual, string $loc): string {
        $loc = trim($loc);
        if (preg_match('#\Ahttps://#i', $loc)) {
            return $loc;
        }
        if (isset($loc[0]) && $loc[0] === '/' && substr($loc, 0, 2) !== '//') {
            $p = parse_url($actual);
            return 'https://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . $loc;
        }
        return '';
    }

    /**
     * Una petición GET sin seguir redirecciones, con tope de 200 KB (aborta al pasar, no baja entero).
     *
     * @return array{errno: int, http: int, body: string, too_big: bool, location: string, effective: string}
     */
    private static function http_logo(string $url): array {
        if (self::$descargador !== null) {
            return (self::$descargador)($url) + [
                'errno' => 0, 'http' => 200, 'body' => '', 'too_big' => false, 'location' => '', 'effective' => '',
            ];
        }
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $max = self::LOGO_MAX_BYTES;
        $toobig = false;
        $curl = new \curl();
        $curl->setopt([
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_MAXREDIRS' => 0,
            'CURLOPT_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_REDIR_PROTOCOLS' => CURLPROTO_HTTPS,
            'CURLOPT_TIMEOUT' => self::LOGO_TIMEOUT_S,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_MAXFILESIZE' => $max,
            'CURLOPT_NOPROGRESS' => false,
            'CURLOPT_PROGRESSFUNCTION' => function () use (&$toobig, $max) {
                $args = func_get_args();
                $nums = (is_object($args[0] ?? null) || is_resource($args[0] ?? null)) ? array_slice($args, 1) : $args;
                if ((float)($nums[0] ?? 0) > $max || (float)($nums[1] ?? 0) > $max) {
                    $toobig = true;
                    return 1;
                }
                return 0;
            },
        ]);
        $curl->setHeader(['Accept: image/png,image/jpeg,image/webp']);
        $body = $curl->get($url);
        $info = (array)$curl->info;
        $res = [
            'errno' => (int)$curl->errno,
            'http' => (int)($info['http_code'] ?? 0),
            'body' => is_string($body) ? $body : '',
            'too_big' => $toobig || (int)$curl->errno === 63,
            'location' => '',
            'effective' => (string)($info['url'] ?? ''),
        ];
        foreach ((array)$curl->get_raw_response() as $linea) {
            if (is_string($linea) && stripos($linea, 'location:') === 0) {
                $res['location'] = trim(substr($linea, 9));
            }
        }
        return $res;
    }

    /**
     * PNG, JPEG o WebP (nada de SVG): firma mágica Y getimagesizefromstring() de acuerdo entre sí,
     * 96–512 px por lado y casi cuadrado.
     *
     * @return array{motivo: string, ext: string} motivo '' = vale.
     */
    public static function validar_imagen_logo(string $body): array {
        $len = strlen($body);
        if ($len === 0 || $len > self::LOGO_MAX_BYTES) {
            return ['motivo' => 'tamano', 'ext' => ''];
        }
        if (strncmp($body, "\x89PNG\r\n\x1a\n", 8) === 0) {
            $firma = 'png';
        } else if (strncmp($body, "\xFF\xD8\xFF", 3) === 0) {
            $firma = 'jpg';
        } else if ($len >= 12 && substr($body, 0, 4) === 'RIFF' && substr($body, 8, 4) === 'WEBP') {
            $firma = 'webp';
        } else {
            return ['motivo' => 'firma', 'ext' => ''];
        }
        $info = @getimagesizefromstring($body);
        if (!is_array($info) || empty($info[0]) || empty($info[1])) {
            return ['motivo' => 'imagen', 'ext' => ''];
        }
        $tipos = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];
        if (($tipos[(int)($info[2] ?? 0)] ?? '') !== $firma) {
            return ['motivo' => 'firma_no_coincide', 'ext' => ''];
        }
        $w = (int)$info[0];
        $h = (int)$info[1];
        if ($w < self::LOGO_MIN_PX || $h < self::LOGO_MIN_PX || $w > self::LOGO_MAX_PX || $h > self::LOGO_MAX_PX) {
            return ['motivo' => 'dimensiones', 'ext' => ''];
        }
        if (max($w, $h) / min($w, $h) > self::LOGO_ASPECTO_MAX) {
            return ['motivo' => 'aspecto', 'ext' => ''];
        }
        return ['motivo' => '', 'ext' => $firma];
    }

    /**
     * Guarda el logo en la filearea propia (contexto de sistema, itemid 0). Primero se escribe el nuevo
     * y después se quitan los demás, para no quedarse sin logo si falla la escritura.
     */
    private static function guardar_logo(string $body, string $ext, string $version): bool {
        if (!in_array($ext, self::LOGO_EXTENSIONES, true)) {
            return false;
        }
        try {
            $fs = get_file_storage();
            $ctxid = \context_system::instance()->id;
            $nombre = 'logo.' . $ext;
            foreach ($fs->get_area_files($ctxid, 'block_pulso', self::LOGO_AREA, 0, 'id', false) as $f) {
                if ($f->get_filename() === $nombre) {
                    $f->delete(); // mismo nombre: no pueden convivir
                }
            }
            $fs->create_file_from_string([
                'contextid' => $ctxid, 'component' => 'block_pulso', 'filearea' => self::LOGO_AREA,
                'itemid' => 0, 'filepath' => '/', 'filename' => $nombre,
            ], $body);
            foreach ($fs->get_area_files($ctxid, 'block_pulso', self::LOGO_AREA, 0, 'id', false) as $f) {
                if ($f->get_filename() !== $nombre) {
                    $f->delete();
                }
            }
            set_config('tema_logo_ext', $ext, 'block_pulso');
            set_config('tema_logo_version', $version, 'block_pulso');
            return true;
        } catch (\Throwable $e) {
            error_log('Pulso tema: no se pudo guardar el logo: ' . get_class($e));
            return false;
        }
    }

    /** `logo: null`, o tema borrado: fuera el fichero y los ajustes; el chat vuelve al isotipo. */
    private static function borrar_logo(): void {
        try {
            get_file_storage()->delete_area_files(\context_system::instance()->id, 'block_pulso', self::LOGO_AREA, 0);
        } catch (\Throwable $e) {
            error_log('Pulso tema: no se pudo borrar el logo: ' . get_class($e));
        }
        unset_config('tema_logo_version', 'block_pulso');
        unset_config('tema_logo_ext', 'block_pulso');
    }

    /** El fichero guardado, o null. */
    private static function fichero_logo(): ?\stored_file {
        $ext = (string)get_config('block_pulso', 'tema_logo_ext');
        if (!in_array($ext, self::LOGO_EXTENSIONES, true)) {
            return null;
        }
        $f = get_file_storage()->get_file(\context_system::instance()->id, 'block_pulso', self::LOGO_AREA, 0, '/', 'logo.' . $ext);
        return ($f && !$f->is_directory()) ? $f : null;
    }

    /**
     * URL de pluginfile del logo guardado, con la versión en la ruta (cache larga), o '' si no hay. Lee solo
     * ajustes (sin BD de ficheros) y los REVALIDA. block_pulso_pluginfile() ignora ese segmento de versión.
     */
    public static function logo_para_pintar(): string {
        $version = (string)get_config('block_pulso', 'tema_logo_version');
        $ext = (string)get_config('block_pulso', 'tema_logo_ext');
        if (!self::version_logo_valida($version) || !in_array($ext, self::LOGO_EXTENSIONES, true)) {
            return '';
        }
        return \moodle_url::make_pluginfile_url(
            \context_system::instance()->id, 'block_pulso', self::LOGO_AREA, 0, '/' . $version . '/', 'logo.' . $ext
        )->out(false);
    }

    // ----------------------------------------------------------------
    // Lectura y validación (se usa al pintar: solo lo guardado).
    // ----------------------------------------------------------------

    /**
     * Colores guardados, REVALIDADOS. [] si no hay tema (o lo guardado no es utilizable).
     *
     * @return array<string, array{valor: string, texto?: string}>
     */
    public static function leer(): array {
        $json = (string)get_config('block_pulso', 'tema_json');
        if ($json === '') {
            return [];
        }
        $datos = json_decode($json, true);
        return self::validar_colores($datos);
    }

    /**
     * Deja SOLO lo que se puede pintar: claves conocidas con forma de clave, `valor` y
     * `texto` con forma #rrggbb (minúsculas, seis cifras). Lo demás se ignora, color a color.
     *
     * @param mixed $colores Lo que venga (de Épica o de la configuración).
     * @return array<string, array{valor: string, texto?: string}>
     */
    public static function validar_colores($colores): array {
        if (!is_array($colores)) {
            return [];
        }
        $limpio = [];
        foreach ($colores as $clave => $color) {
            if (!is_string($clave) || !preg_match(self::RE_CLAVE, $clave)) {
                continue;
            }
            if (!in_array($clave, self::CLAVES, true) || !is_array($color)) {
                continue;
            }
            // Se normaliza a minúsculas y sin espacios ANTES de validar: la regex no cambia.
            $valor = $color['valor'] ?? null;
            $valor = is_string($valor) ? strtolower(trim($valor)) : null;
            if ($valor === null || !preg_match(self::RE_COLOR, $valor)) {
                continue;
            }
            $entrada = ['valor' => $valor];
            $texto = $color['texto'] ?? null;
            $texto = is_string($texto) ? strtolower(trim($texto)) : null;
            if ($texto !== null && preg_match(self::RE_COLOR, $texto)) {
                $entrada['texto'] = $texto;
            }
            $limpio[$clave] = $entrada;
        }
        return $limpio;
    }

    // ----------------------------------------------------------------
    // Contraste (WCAG 2.x) y variables resultantes.
    // ----------------------------------------------------------------

    /** Luminancia relativa de #rrggbb. */
    public static function luminancia(string $hex): float {
        $canales = [];
        foreach ([1, 3, 5] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $canales[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
        }
        return 0.2126 * $canales[0] + 0.7152 * $canales[1] + 0.0722 * $canales[2];
    }

    /** Relación de contraste WCAG entre dos #rrggbb (1 a 21). */
    public static function contraste(string $a, string $b): float {
        $la = self::luminancia($a);
        $lb = self::luminancia($b);
        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Blanco o negro, el que mejor se lee sobre $fondo (siempre >= 4,5:1). */
    public static function mejor_texto(string $fondo): string {
        return self::contraste(self::BLANCO, $fondo) >= self::contraste(self::NEGRO, $fondo)
            ? self::BLANCO : self::NEGRO;
    }

    /**
     * Oscurece un #rrggbb, SIN cambiar su tono, lo justo para que el texto BLANCO dé >= 4,5:1.
     *
     * Mezcla hacia negro en sRGB (multiplicar los tres canales por el mismo factor = bajar solo el
     * valor del HSV: tono y saturación se conservan), en pasos del 2 % del color original, con tope
     * de 50 pasos (el último es negro, que siempre cumple): termina siempre. Si ya cumple, lo
     * devuelve tal cual.
     */
    public static function oscurecer_para_blanco(string $hex): string {
        return self::oscurecer_hasta($hex, self::CONTRASTE_TEXTO);
    }

    /** Como oscurecer_para_blanco() pero con el contraste mínimo sobre blanco que se pida. */
    private static function oscurecer_hasta(string $hex, float $minimo, string $contra = self::BLANCO): string {
        if (self::contraste($hex, $contra) >= $minimo) {
            return $hex;
        }
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        for ($paso = 1; $paso <= 50; $paso++) {
            $k = 1 - 0.02 * $paso;
            $c = sprintf('#%02x%02x%02x', (int)round($r * $k), (int)round($g * $k), (int)round($b * $k));
            if (self::contraste($c, $contra) >= $minimo) {
                return $c;
            }
        }
        return self::NEGRO;
    }

    /** Mezcla $hex con $destino: fracción $k (0–1) del camino hacia $destino. */
    private static function mezclar_hacia(string $hex, string $destino, float $k): string {
        $out = '#';
        foreach ([1, 3, 5] as $i) {
            $a = hexdec(substr($hex, $i, 2));
            $b = hexdec(substr($destino, $i, 2));
            $out .= sprintf('%02x', (int)round($a + ($b - $a) * $k));
        }
        return $out;
    }

    /** El texto fijo con peor contraste sobre $base y ese contraste. @return array{0: string, 1: float} */
    public static function peor_texto_fijo(string $base): array {
        $peor = self::TEXTOS_FIJOS[0];
        $min = self::contraste($peor, $base);
        foreach (self::TEXTOS_FIJOS as $t) {
            $c = self::contraste($t, $base);
            if ($c < $min) {
                $peor = $t;
                $min = $c;
            }
        }
        return [$peor, $min];
    }

    /** ¿Todos los textos fijos pequeños se leen (>= 4,5:1) sobre este fondo? (Las píldoras tintadas no cuentan: van opacas, ver styles.css.) */
    private static function fondo_cumple(string $hex): bool {
        if (self::peor_texto_fijo($hex)[1] < self::CONTRASTE_TEXTO) {
            return false;
        }
        return true;
    }

    /**
     * El fondo del panel del centro    /**
     * El fondo del panel del centro, ACLARADO hacia blanco (nunca oscurecido) en pasos del 2 % hasta que
     * todo el texto fijo pequeño se lea (>= 4,5:1) sobre él (ver TEXTOS_FIJOS). Tope 50
     * pasos (= blanco, que cumple). El texto del panel es fijo y oscuro: no se cambia nunca.
     */
    public static function aclarar_para_panel(string $hex): string {
        if (self::fondo_cumple($hex)) {
            return $hex;
        }
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        for ($paso = 1; $paso <= 50; $paso++) {
            $k = 0.02 * $paso;
            $c = sprintf('#%02x%02x%02x',
                (int)round($r + (255 - $r) * $k), (int)round($g + (255 - $g) * $k), (int)round($b + (255 - $b) * $k));
            if (self::fondo_cumple($c)) {
                return $c;
            }
        }
        return self::BLANCO;
    }

    /**
     * Texto sobre un fondo explícito del centro: el `texto` de Épica solo si da >= 4,5:1; si no,
     * el mejor de blanco/negro.
     */
    private static function texto_para(string $fondo, ?string $texto): string {
        if ($texto === null || $texto === '' || self::contraste($texto, $fondo) < self::CONTRASTE_TEXTO) {
            return self::mejor_texto($fondo);
        }
        return $texto;
    }

    /**
     * De unos colores del centro saca las variables CSS finales (v2.5.0). Pura: sin BD ni Épica.
     *
     * Reglas (CLAUDE.md → «Colores de cada centro»):
     *  - SUPERFICIES GRANDES (cabecera, botón, burbuja propia): una clave enviada se respeta (es la
     *    elección del centro) y su texto se calcula con texto_para(). Una clave no enviada se
     *    DERIVA del principal con oscurecer_para_blanco() y lleva texto blanco: un principal claro
     *    nunca acaba como superficie grande con letras negras (salvo que el centro lo pida
     *    explícitamente con cabecera/boton/burbuja). Sin principal, lo no enviado queda en Pulse.
     *  - ACENTO enviado: >= 3:1 sobre blanco y sobre la cabecera y el botón efectivos; si no, se
     *    ignora. No enviado (o ignorado) y con principal: el principal original si cumple lo mismo;
     *    si no, su versión oscurecida hasta >= 3:1 sobre blanco (iconos y bordes sobre el panel).
     *  - Ni principal ni ninguna clave válida: vacío = sin tema.
     *
     * @param array $colores Colores del centro; se REVALIDAN aquí (validar_colores() es idempotente).
     * @return array{
     *   vars: array<string,string>, flags: string[], ignorados: string[], isotipoclaro: bool,
     *   fondo: ?array{original: string, efectivo: string, aclarado: bool},
     *   detalle: array<int,array{var:string,valor:string,texto:?string,contraste:?float,origen:string}>
     * } `origen`: «epica» (enviado), «derivado» (calculado del principal) o «pulse» (el de siempre).
     */
    public static function resolver_tema(array $colores): array {
        $colores = self::validar_colores($colores);
        $vars = [];
        $flags = [];
        $ignorados = [];
        $origen = [];

        // FONDO del panel (v2.13.0): se aclara si hace falta y de él salen las dos superficies.
        $panel = self::BLANCO;
        $fondoinfo = null;
        if (isset($colores['fondo'])) {
            $original = $colores['fondo']['valor'];
            $panel = self::aclarar_para_panel($original);
            $vars['--pulso-bg'] = $panel;
            $vars['--pulso-surface'] = self::mezclar_hacia($panel, self::BLANCO, self::K_SURFACE);
            $vars['--pulso-surface-2'] = self::mezclar_hacia($panel, self::BLANCO, self::K_SURFACE2);
            $origen['--pulso-bg'] = 'epica';
            $flags[] = 'fondo'; // activa las píldoras opacas del CSS (ver «FONDO del centro» en styles.css)
            $fondoinfo = ['original' => $original, 'efectivo' => $panel, 'aclarado' => $panel !== $original];
        }

        $principal = $colores['principal']['valor'] ?? null;
        $derivado = $principal !== null ? self::oscurecer_para_blanco($principal) : null;
        if ($principal !== null) {
            $flags[] = 'principal';
        }

        // Cada superficie: clave de Épica => [variable de fondo, variable de texto].
        $superficies = [
            'cabecera' => ['--pulso-header-bg', '--pulso-header-text'],
            'boton' => ['--pulso-action-bg', '--pulso-action-text'],
            'burbuja' => ['--pulso-own-bg', '--pulso-own-text'],
        ];
        $fondos = [];
        foreach ($superficies as $clave => [$vbg, $vtxt]) {
            if (isset($colores[$clave])) {
                $fondo = $colores[$clave]['valor'];
                $texto = self::texto_para($fondo, $colores[$clave]['texto'] ?? null);
                $origen[$vbg] = 'epica';
            } else if ($derivado !== null) {
                $fondo = $derivado;
                $texto = self::BLANCO;
                $origen[$vbg] = 'derivado';
            } else {
                continue;
            }
            $vars[$vbg] = $fondo;
            $vars[$vtxt] = $texto;
            $fondos[$clave] = $fondo;
            $flags[] = $clave;
        }

        // «Mis creaciones» (v2.13.0): solo si el centro lo manda; no se deriva del principal (el botón
        // sigue como siempre). Mismas reglas que `boton`: se respeta el color, el texto de Épica si da >= 4,5:1.
        if (isset($colores['creaciones'])) {
            $fondomine = $colores['creaciones']['valor'];
            $vars['--pulso-mine-bg'] = $fondomine;
            $vars['--pulso-mine-text'] = self::texto_para($fondomine, $colores['creaciones']['texto'] ?? null);
            $origen['--pulso-mine-bg'] = 'epica';
            $flags[] = 'creaciones';
        }

        // El acento tiene que verse sobre el panel (blanco): es lo único que se exige para usarlo
        // (foco, bordes e iconos). Solo pinta además los detalles de la cabecera y del botón
        // (indicador de pestaña, orden de tabla) si da el mismo contraste sobre ellos, que son
        // los efectivos (los de Pulse si el centro no puso otros). Con una cabecera apta para texto
        // blanco casi nunca ocurre, así que exigirlo para aceptar el acento lo descartaba siempre.
        $cabecera = $fondos['cabecera'] ?? self::PRINCIPAL_DEFECTO;
        $boton = $fondos['boton'] ?? self::PRINCIPAL_DEFECTO;
        $sobrepanel = function (string $c) use ($panel): bool {
            return self::contraste($c, $panel) >= self::CONTRASTE_GRAFICO;
        };
        $sobresuperficies = function (string $c) use ($cabecera, $boton): bool {
            return self::contraste($c, $cabecera) >= self::CONTRASTE_GRAFICO
                && self::contraste($c, $boton) >= self::CONTRASTE_GRAFICO;
        };
        $acento = null;
        if (isset($colores['acento'])) {
            if ($sobrepanel($colores['acento']['valor'])) {
                $acento = $colores['acento']['valor'];
                $origen['--pulso-accent'] = 'epica';
            } else {
                $ignorados[] = 'acento';
            }
        }
        if ($acento === null && $principal !== null) {
            $origen['--pulso-accent'] = 'derivado';
            // El principal si se ve sobre el panel; si no, su versión oscurecida hasta >= 3:1.
            $acento = $sobrepanel($principal)
                ? $principal
                : self::oscurecer_hasta($principal, self::CONTRASTE_GRAFICO, $panel);
        }
        // Con fondo del centro y sin acento propio ni derivado: el cian de Pulse (#0b93aa, 3,64:1 sobre blanco) puede
        // no llegar a 3:1 sobre el fondo; se oscurece lo justo, igual que el derivado del principal.
        if ($acento === null && $fondoinfo !== null && !$sobrepanel(self::ACENTO_DEFECTO)) {
            $acento = self::oscurecer_hasta(self::ACENTO_DEFECTO, self::CONTRASTE_GRAFICO, $panel);
            $origen['--pulso-accent'] = 'derivado';
        }
        $usoacento = 'panel';
        if ($acento !== null) {
            $enlacabecera = $sobresuperficies($acento);
            $vars['--pulso-accent'] = $acento;
            $flags[] = $enlacabecera ? 'acento' : 'acento-base';
            $usoacento = $enlacabecera ? 'panel y cabecera' : 'panel';
        }

        $detalle = [];
        if ($vars) {
            $defectos = [
                '--pulso-header-bg' => self::PRINCIPAL_DEFECTO,
                '--pulso-action-bg' => self::PRINCIPAL_DEFECTO,
                '--pulso-own-bg' => self::PRINCIPAL_DEFECTO,
                '--pulso-accent' => '#0b93aa',
            ];
            foreach ($defectos as $nombre => $defecto) {
                $valor = $vars[$nombre] ?? $defecto;
                // Fondos: el texto que lleva encima. Acento: se mide contra el panel blanco.
                $texto = $nombre === '--pulso-accent'
                    ? $panel
                    : ($vars[substr($nombre, 0, -3) . '-text'] ?? self::BLANCO);
                $detalle[] = [
                    'var' => $nombre,
                    'valor' => $valor,
                    'texto' => $texto,
                    'contraste' => self::contraste($valor, $texto),
                    'origen' => $origen[$nombre] ?? 'pulse',
                ];
                if ($nombre === '--pulso-accent') {
                    $detalle[count($detalle) - 1]['uso'] = $usoacento;
                }
            }
            if ($fondoinfo !== null) {
                // El peor texto fijo sobre el fondo y sobre cada superficie derivada (más claras que el fondo).
                $filas = [
                    ['--pulso-bg', $panel, 'epica', $fondoinfo['aclarado'] ? 'aclarado desde ' . $fondoinfo['original'] : 'sin cambios'],
                    ['--pulso-surface', $vars['--pulso-surface'], 'derivado', 'más clara que el fondo'],
                    ['--pulso-surface-2', $vars['--pulso-surface-2'], 'derivado', 'más clara que el fondo'],
                ];
                foreach ($filas as [$nombre, $valor, $orig, $nota]) {
                    [$peor, $cpeor] = self::peor_texto_fijo($valor);
                    $detalle[] = [
                        'var' => $nombre, 'valor' => $valor, 'texto' => $peor, 'contraste' => $cpeor, 'origen' => $orig,
                        'nota' => $nota . '; peor texto fijo encima',
                    ];
                }
            }
            if (isset($vars['--pulso-mine-bg'])) {
                $detalle[] = [
                    'var' => '--pulso-mine-bg', 'valor' => $vars['--pulso-mine-bg'], 'texto' => $vars['--pulso-mine-text'],
                    'contraste' => self::contraste($vars['--pulso-mine-bg'], $vars['--pulso-mine-text']), 'origen' => 'epica',
                ];
            }
        }

        return [
            'vars' => $vars,
            'flags' => $flags,
            'ignorados' => $ignorados,
            // Con texto negro la cabecera es clara: el isotipo para fondo claro.
            'isotipoclaro' => ($vars['--pulso-header-text'] ?? '') === self::NEGRO,
            'detalle' => $detalle,
            'fondo' => $fondoinfo,
        ];
    }

    /** Cadena de `style` con las variables resueltas: «--pulso-header-bg:#…;--pulso-accent:#…;». */
    public static function css_de(array $vars): string {
        $css = '';
        foreach ($vars as $nombre => $valor) {
            $css .= $nombre . ':' . $valor . ';';
        }
        return $css;
    }

    /**
     * Lo que necesita la plantilla del chat. Lee SOLO lo guardado (jamás llama a Épica) y lo
     * revalida. Vacío = sin tema: la plantilla no pinta ni `style` ni `data-pulso-tema`.
     *
     * El logo (v2.13.0) es independiente de los colores: puede haber logo sin colores y al revés.
     *
     * @return array{style: string, flags: string, isotipoclaro: bool, logo: string}|array{}
     */
    public static function para_pintar(): array {
        if (!epica_client::disponible()) {
            return [];
        }
        try {
            $res = self::resolver_tema(self::leer());
            $logo = self::logo_para_pintar();
        } catch (\Throwable $e) {
            error_log('Pulso tema: no se pudo leer lo guardado: ' . get_class($e));
            return [];
        }
        if (!$res['vars'] && $logo === '') {
            return [];
        }
        return [
            'style' => self::css_de($res['vars']),
            'flags' => implode(' ', $res['flags']),
            'isotipoclaro' => $res['isotipoclaro'],
            'logo' => $logo,
        ];
    }
}
