<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/epica_client.php');

/**
 * Puente del PROFESORADO hacia Épica (`/api/auth/moodle`): lógica común de «Material para Pulse»
 * (carta 17, herramienta `fuentes`) y «Abrir en la Factoría» (carta 18, herramienta `factoria`).
 *
 * Firma un token NUEVO de docente y devuelve una página mínima que autoenvía un `<form method="post">`
 * a `epica_client::endpoint('/api/auth/moodle')`. El token viaja en el CUERPO de un POST del
 * navegador: nunca en una URL, nunca en un log, nunca por el JS de Pulse. NUNCA `epica::auth_url()`
 * ni `epica::api_url()`: apuntan al Épica de `local_awkepica`, no al de Pulse (`epica_base_url`).
 *
 * Orden: POST → autenticar → sesskey → viewanalytics → herramienta/borrador → Épica disponible →
 * precondiciones → firmar. El rol sale de `block/pulso:viewanalytics` en contexto de CURSO (nunca
 * `createactivity`: con ella todo el alumnado saldría firmado como docente).
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class epica_puente {

    /** Herramientas que admite el puente (lista cerrada). */
    const HERRAMIENTAS = ['fuentes', 'factoria'];

    /** Forma del código de borrador que devuelve la Factoría (carta 18 §2). */
    const BORRADOR_REGEX = '/^[A-Za-z0-9]{16,64}\z/';

    /** Ruta del puente de docentes en Épica. */
    const RUTA = '/api/auth/moodle';

    /** @return bool ¿Tiene un código de borrador la forma esperada? */
    public static function borrador_valido($borrador): bool {
        return is_string($borrador) && preg_match(self::BORRADOR_REGEX, $borrador) === 1;
    }

    /**
     * Texto de la página mientras se autoenvía el formulario.
     *
     * @param string $herramienta
     * @return string
     */
    public static function texto_abriendo(string $herramienta): string {
        return $herramienta === 'factoria'
            ? 'Abriendo la Factoría en Épica…'
            : 'Abriendo el material para Pulse en Épica…';
    }

    /**
     * Campos del formulario hacia Épica (salvo el token, que se firma al final). `borrador` solo
     * viaja con `factoria` y ya validado.
     *
     * @param string $herramienta
     * @param string $cursonombre
     * @param string $borrador
     * @return array<string, string>
     */
    public static function campos(string $herramienta, string $cursonombre, string $borrador = ''): array {
        $campos = ['herramienta' => $herramienta, 'curso_nombre' => $cursonombre];
        if ($herramienta === 'factoria') {
            $campos['borrador'] = $borrador;
        }
        return $campos;
    }

    /**
     * Página mínima, sin el tema de Moodle.
     *
     * @param string $title Titulo de la pagina (texto plano).
     * @param string $bodyhtml HTML ya escapado del cuerpo.
     * @param int $status Codigo HTTP.
     */
    public static function page(string $title, string $bodyhtml, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        header('Referrer-Policy: no-referrer');
        echo '<!doctype html><html lang="es"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="referrer" content="no-referrer">'
            . '<title>' . s($title) . '</title>'
            . '<style>body{font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#F0F3FC;color:#27334F;'
            . 'display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:16px;text-align:center}'
            . 'p{max-width:420px;line-height:1.5}button{background:#003670;color:#fff;border:0;border-radius:8px;'
            . 'padding:10px 18px;font-size:1rem;cursor:pointer}</style></head><body><div>' . $bodyhtml . '</div></body></html>';
        exit;
    }

    /**
     * Atiende la petición completa del puente (termina siempre con una página).
     *
     * @param string $herramienta Valor de `herramienta` tal cual llegó (se valida contra HERRAMIENTAS).
     * @param mixed $borrador Valor de `borrador` tal cual llegó (solo se mira con `factoria`).
     */
    public static function abrir($herramienta, $borrador): void {
        global $USER;

        $titulo = 'Épica';
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            self::page($titulo, '<p>Método no permitido.</p>', 405);
        }

        $courseid = required_param('courseid', PARAM_INT);
        $course = get_course($courseid);
        $context = \context_course::instance($courseid);

        require_login($course);
        require_sesskey();
        // Solo profesorado, y no solo «con la UI»: el botón del bloque no es control de acceso.
        require_capability('block/pulso:viewanalytics', $context);

        if (!is_string($herramienta) || !in_array($herramienta, self::HERRAMIENTAS, true)) {
            self::page($titulo, '<p>Petición no válida.</p>', 400);
        }
        if ($herramienta === 'factoria' && !self::borrador_valido($borrador)) {
            self::page($titulo, '<p>El borrador de la presentación no es válido. Vuelve a Pulse y prepárala de nuevo.</p>', 400);
        }

        if (!epica_client::disponible()) {
            self::page($titulo, '<p>' . s(epica_client::MENSAJE_NO_DISPONIBLE) . '</p>', 503);
        }

        $user = \core_user::get_user((int)$USER->id, '*', MUST_EXIST);

        $motivo = epica_client::precondiciones_error($user);
        if ($motivo !== null) {
            self::page($titulo, '<p>' . s($motivo) . '</p>', 503);
        }

        try {
            // El puente de docentes, sobre el epica_base_url de Pulse (endpoint() exige https://: el token
            // no puede viajar sin cifrar).
            $urlpuente = epica_client::endpoint(self::RUTA);
            // Token nuevo justo ahora: vale 120 s y un solo uso.
            $token = \local_awkepica\epica::firmar_por($user, $context, epica_client::CAPABILITY_ROL, (string)$course->id);
        } catch (\Throwable $e) {
            // Nunca el token ni el mensaje técnico hacia el navegador; solo la clase al log.
            error_log('Pulso puente ' . $herramienta . ': fallo al firmar: ' . get_class($e));
            self::page($titulo,
                '<p>No hemos podido abrir Épica ahora mismo. Cierra esta pestaña e inténtalo de nuevo en unos minutos.</p>', 503);
        }

        $texto = self::texto_abriendo($herramienta);
        $campos = ['token' => $token]
            + self::campos($herramienta, format_string($course->fullname), is_string($borrador) ? $borrador : '');

        $inputs = '';
        foreach ($campos as $nombre => $valor) {
            $inputs .= '<input type="hidden" name="' . s($nombre) . '" value="' . s($valor) . '">';
        }

        self::page($texto,
            '<form id="puente" method="post" action="' . s($urlpuente) . '">'
            . $inputs
            . '<p>' . s($texto) . '</p>'
            . '<noscript><button type="submit">Continuar a Épica</button></noscript>'
            . '</form>'
            . '<script>document.getElementById("puente").submit();</script>');
    }
}
