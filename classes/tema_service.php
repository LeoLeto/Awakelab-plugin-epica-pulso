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
    const CLAVES = ['principal', 'acento', 'cabecera', 'boton', 'burbuja'];

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
    private static function oscurecer_hasta(string $hex, float $minimo): string {
        if (self::contraste($hex, self::BLANCO) >= $minimo) {
            return $hex;
        }
        $r = hexdec(substr($hex, 1, 2));
        $g = hexdec(substr($hex, 3, 2));
        $b = hexdec(substr($hex, 5, 2));
        for ($paso = 1; $paso <= 50; $paso++) {
            $k = 1 - 0.02 * $paso;
            $c = sprintf('#%02x%02x%02x', (int)round($r * $k), (int)round($g * $k), (int)round($b * $k));
            if (self::contraste($c, self::BLANCO) >= $minimo) {
                return $c;
            }
        }
        return self::NEGRO;
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
     *   detalle: array<int,array{var:string,valor:string,texto:?string,contraste:?float,origen:string}>
     * } `origen`: «epica» (enviado), «derivado» (calculado del principal) o «pulse» (el de siempre).
     */
    public static function resolver_tema(array $colores): array {
        $colores = self::validar_colores($colores);
        $vars = [];
        $flags = [];
        $ignorados = [];
        $origen = [];

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

        // El acento tiene que verse sobre el panel (blanco): es lo único que se exige para usarlo
        // (foco, bordes e iconos). Solo pinta además los detalles de la cabecera y del botón
        // (indicador de pestaña, orden de tabla) si da el mismo contraste sobre ellos, que son
        // los efectivos (los de Pulse si el centro no puso otros). Con una cabecera apta para texto
        // blanco casi nunca ocurre, así que exigirlo para aceptar el acento lo descartaba siempre.
        $cabecera = $fondos['cabecera'] ?? self::PRINCIPAL_DEFECTO;
        $boton = $fondos['boton'] ?? self::PRINCIPAL_DEFECTO;
        $sobrepanel = function (string $c): bool {
            return self::contraste($c, self::BLANCO) >= self::CONTRASTE_GRAFICO;
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
                : self::oscurecer_hasta($principal, self::CONTRASTE_GRAFICO);
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
                    ? self::BLANCO
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
        }

        return [
            'vars' => $vars,
            'flags' => $flags,
            'ignorados' => $ignorados,
            // Con texto negro la cabecera es clara: el isotipo para fondo claro.
            'isotipoclaro' => ($vars['--pulso-header-text'] ?? '') === self::NEGRO,
            'detalle' => $detalle,
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
     * @return array{style: string, flags: string, isotipoclaro: bool}|array{}
     */
    public static function para_pintar(): array {
        if (!epica_client::disponible()) {
            return [];
        }
        try {
            $res = self::resolver_tema(self::leer());
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
            'isotipoclaro' => $res['isotipoclaro'],
        ];
    }
}
