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
 *  - El contraste lo calculamos nosotros (WCAG); la variable que no lo cumple se ignora y
 *    se queda la de Pulse. El `texto` de Épica se acepta solo si da >= 4,5:1 sobre `valor`.
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

    /** Las únicas claves que Pulse usa; cualquier otra que mande Épica se ignora. */
    const CLAVES = ['principal', 'acento'];

    const RE_CLAVE = '/^[a-z][a-z0-9-]{0,31}\z/';
    const RE_COLOR = '/^#[0-9a-f]{6}\z/';
    const RE_VERSION = '/^[0-9A-Za-z_-]{1,32}\z/';

    /** Color de cabecera de Pulse (el que hay cuando Épica no manda `principal`). */
    const PRINCIPAL_DEFECTO = '#003670';
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

        if (!array_key_exists('tema', $datos)) {
            error_log('Pulso tema: la respuesta 200 no trae el campo «tema».');
            return self::fallo('respuesta', 'tema_msg_error_respuesta');
        }

        $tema = $datos['tema'];
        $hayguardado = self::hay_guardado();

        if ($tema === null) {
            // El centro ha vuelto al tema por defecto de Pulse.
            self::borrar();
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
        if (!$colores) {
            // Nada utilizable (p. ej. solo claves que Pulse no usa): equivale a no tener tema.
            error_log('Pulso tema: el tema no trae colores que Pulse use o que tengan forma #rrggbb.');
            self::borrar();
            self::marcar_ok();
            return self::resultado($hayguardado ? self::ESTADO_BORRADO : self::ESTADO_SIN_TEMA,
                $hayguardado ? 'tema_msg_borrado' : 'tema_msg_sin_tema');
        }

        if ($hayguardado && (string)get_config('block_pulso', 'tema_version') === $version) {
            self::marcar_ok();
            return self::resultado(self::ESTADO_SIN_CAMBIOS, 'tema_msg_sin_cambios', $version);
        }

        set_config('tema_json', json_encode($colores), 'block_pulso');
        set_config('tema_version', $version, 'block_pulso');
        self::marcar_ok();
        return self::resultado(self::ESTADO_GUARDADO, 'tema_msg_guardado', $version);
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
            $valor = $color['valor'] ?? null;
            if (!is_string($valor) || !preg_match(self::RE_COLOR, $valor)) {
                continue;
            }
            $entrada = ['valor' => $valor];
            $texto = $color['texto'] ?? null;
            if (is_string($texto) && preg_match(self::RE_COLOR, $texto)) {
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
     * De unos colores YA validados saca las variables que Pulse pinta, aplicando el contraste:
     *  - principal: se usa `valor`; su `texto` solo si da >= 4,5:1 sobre él, y si no se calcula
     *    blanco o negro. Nunca se ignora (siempre hay un texto legible).
     *  - acento: >= 3:1 sobre blanco (foco, bordes e iconos sobre el panel) Y >= 3:1 sobre el
     *    principal efectivo (indicador de pestaña activa en la cabecera); si no, se ignora y
     *    se queda el acento de Pulse.
     *
     * @param array $colores Salida de validar_colores().
     * @return array{vars: array<string,string>, flags: string[], ignorados: string[], textoprincipal: ?string}
     */
    public static function resolver(array $colores): array {
        $vars = [];
        $flags = [];
        $ignorados = [];
        $textoprincipal = null;
        $principal = self::PRINCIPAL_DEFECTO;

        if (isset($colores['principal'])) {
            $valor = $colores['principal']['valor'];
            $texto = $colores['principal']['texto'] ?? '';
            if ($texto === '' || self::contraste($texto, $valor) < self::CONTRASTE_TEXTO) {
                $texto = self::mejor_texto($valor);
            }
            $vars['--pulso-brand'] = $valor;
            $vars['--pulso-brand-text'] = $texto;
            $flags[] = 'principal';
            $textoprincipal = $texto;
            $principal = $valor;
        }

        if (isset($colores['acento'])) {
            $valor = $colores['acento']['valor'];
            if (self::contraste($valor, self::BLANCO) >= self::CONTRASTE_GRAFICO
                && self::contraste($valor, $principal) >= self::CONTRASTE_GRAFICO) {
                $vars['--pulso-accent'] = $valor;
                $flags[] = 'acento';
            } else {
                $ignorados[] = 'acento';
            }
        }

        return ['vars' => $vars, 'flags' => $flags, 'ignorados' => $ignorados, 'textoprincipal' => $textoprincipal];
    }

    /** Cadena de `style` con las variables resueltas: «--pulso-brand:#…;--pulso-accent:#…;». */
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
     * @return array{style: string, flags: string, isotipoclaro: bool}|array{}
     */
    public static function para_pintar(): array {
        if (!epica_client::disponible()) {
            return [];
        }
        try {
            $res = self::resolver(self::leer());
        } catch (\Throwable $e) {
            error_log('Pulso tema: no se pudo leer lo guardado: ' . get_class($e));
            return [];
        }
        if (!$res['vars']) {
            return [];
        }
        return [
            'style' => self::css_de($res['vars']),
            'flags' => implode(' ', $res['flags']),
            // Con texto negro la cabecera es clara: el isotipo para fondo claro.
            'isotipoclaro' => $res['textoprincipal'] === self::NEGRO,
        ];
    }
}
