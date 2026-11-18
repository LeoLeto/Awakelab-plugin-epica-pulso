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

require_once(__DIR__ . '/pulso_error.php');
require_once(__DIR__ . '/epica_client.php');
require_once(__DIR__ . '/full_text_store.php');
require_once(__DIR__ . '/course_visibility.php');
require_once(__DIR__ . '/presentacion_service.php');

/**
 * «Crear juego» del PROFESORADO, terminado en Épica (v2.18.0, migración 033 de Épica).
 *
 * El profesor elige en Pulse el recurso y el tipo de juego; Pulse deja en Épica un borrador con la petición y
 * el texto del recurso por `POST /api/moodle/juegos/borrador`, y el navegador entra después por el puente
 * (epica_puente, herramienta `gamificacion`). Épica aterriza en `/gamificacion/nuevo`, donde el juego empieza a
 * generarse solo y se revisa, se cambia, se guarda en el catálogo o se descarga en HTML para Moodle.
 *
 * Es el mismo recorrido que «Presentación» (presentacion_service) y comparte con ella la llamada a Épica
 * (`presentacion_service::pedir_borrador()`, con su ruta y sus cadenas `jep_err_*`). Lo que no tiene es Haiku:
 * el material viaja literal, como en el encargo de siempre (`epica_client::resolve_material()`), y quien lo lee
 * es el motor de Épica. Por eso tampoco tiene tope propio: no gasta nada aquí; el cupo lo pone Épica al generar.
 *
 * El alumnado NO pasa por aquí: su «Crear juego» sigue siendo el encargo de siempre, dentro de Pulse, porque un
 * token de alumno no abre sesión en Épica.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class juego_epica_service {

    const RUTA = '/api/moodle/juegos/borrador';

    /** Límites de la puerta (Épica, `borradores.ts`). */
    const PETICION_MIN = 10;
    const PETICION_MAX = 4000;

    /**
     * Deja el borrador del juego en Épica. El llamador debe haber comprobado ya sesión, sesskey, viewanalytics,
     * `check_enabled()`, `epica_client::disponible()` y que `$cmid` sale de
     * `creation_quota::get_resources_context()` (visible y con texto), y haber cerrado la sesión.
     *
     * @param int $courseid
     * @param int $cmid El recurso elegido.
     * @param string $peticion Lo que se pide (el tipo de juego, ya escrito o retocado).
     * @return array{borrador: string, caduca: ?string}
     * @throws pulso_error con texto para la persona.
     */
    public static function preparar(int $courseid, int $cmid, string $peticion): array {
        $peticion = trim(\mb_scrub($peticion, 'UTF-8'));
        $len = mb_strlen($peticion, 'UTF-8');
        if ($len < self::PETICION_MIN) {
            throw new pulso_error('bad_request', 'Describe qué juego quieres antes de enviarlo.');
        }
        $peticion = mb_substr($peticion, 0, self::PETICION_MAX, 'UTF-8');

        if (!course_visibility::cm_visible($courseid, $cmid)) {
            throw new pulso_error('bad_request', get_string('jep_err_sin_texto', 'block_pulso'));
        }
        $textrow = full_text_store::get_resource_text($courseid, $cmid);
        if ($textrow === null || trim((string)$textrow['texto']) === '') {
            throw new pulso_error('bad_request', get_string('jep_err_sin_texto', 'block_pulso'));
        }

        $cuerpo = [
            'peticion' => $peticion,
            // El mismo sobre que el encargo de siempre: texto literal, con `extraido_por`.
            'material' => epica_client::resolve_material($textrow),
            'titulo' => mb_substr(trim((string)($textrow['module_name'] ?? '')), 0, 200, 'UTF-8'),
        ];
        if ($cuerpo['titulo'] === '') {
            unset($cuerpo['titulo']);
        }

        $res = presentacion_service::pedir_borrador($cuerpo, $courseid, self::RUTA, 'jep_err_');
        return ['borrador' => $res['borrador'], 'caduca' => $res['caduca']];
    }
}
