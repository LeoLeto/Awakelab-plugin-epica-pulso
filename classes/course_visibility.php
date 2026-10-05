<?php
/**
 * Visibilidad del contenido del curso para el usuario ACTUAL.
 *
 * El índice RAG guarda TODOS los módulos (el profesorado debe ver también lo
 * oculto si tiene acceso), así que la visibilidad se decide al LEER, nunca al
 * indexar. Mismo criterio que rag_retriever::build_activity_link():
 * get_fast_modinfo()->get_cm()->uservisible, y uservisible de la sección para los
 * fragmentos sintéticos (course_meta / course_section).
 *
 * Falla CERRADO: si no se puede comprobar, el contenido se considera oculto.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/content_extractor.php');

class course_visibility {

    /** @var array<int,\course_modinfo|null> memo por petición. */
    private static $modinfo = [];

    /**
     * @param int $courseid
     * @return \course_modinfo|null null si no se pudo obtener.
     */
    private static function modinfo(int $courseid) {
        if (!array_key_exists($courseid, self::$modinfo)) {
            try {
                self::$modinfo[$courseid] = get_fast_modinfo($courseid);
            } catch (\Throwable $e) {
                self::$modinfo[$courseid] = null;
            }
        }
        return self::$modinfo[$courseid];
    }

    /**
     * ¿Puede el usuario actual ver este módulo?
     *
     * @param int $courseid
     * @param int $cmid course_modules.id (> 0)
     * @return bool
     */
    public static function cm_visible(int $courseid, int $cmid): bool {
        if ($cmid <= 0) {
            return false;
        }
        $modinfo = self::modinfo($courseid);
        if (!$modinfo) {
            return false;
        }
        try {
            $cminfo = $modinfo->get_cm($cmid);
        } catch (\Throwable $e) {
            return false;
        }
        return !empty($cminfo) && !empty($cminfo->uservisible);
    }

    /**
     * ¿Puede el usuario actual ver esta sección?
     *
     * @param int $courseid
     * @param int $sectionnum
     * @return bool
     */
    public static function section_visible(int $courseid, int $sectionnum): bool {
        $modinfo = self::modinfo($courseid);
        if (!$modinfo) {
            return false;
        }
        try {
            $info = $modinfo->get_section_info($sectionnum);
        } catch (\Throwable $e) {
            return false;
        }
        return !empty($info) && !empty($info->uservisible);
    }

    /**
     * ¿Puede el usuario actual ver el contenido de un fragmento indexado?
     *
     * cmid > 0: módulo real. cmid < 0: fragmento sintético (ver
     * content_extractor): metadatos del curso = -(C*STRIDE) (siempre visibles) y
     * sección S = -(C*STRIDE + S + 1) (visible si lo es la sección).
     *
     * @param int $courseid
     * @param int $cmid
     * @return bool
     */
    public static function chunk_visible(int $courseid, int $cmid): bool {
        if ($cmid > 0) {
            return self::cm_visible($courseid, $cmid);
        }
        $sectionnum = self::synthetic_section($courseid, $cmid);
        if ($sectionnum === null) {
            // Metadatos del curso (nombre, nº de secciones): no son de ningún módulo.
            return $cmid === -1 * ($courseid * content_extractor::SYNTHETIC_CMID_STRIDE);
        }
        return self::section_visible($courseid, $sectionnum);
    }

    /**
     * Número de sección de un cmid sintético de sección, o null si no lo es.
     *
     * @param int $courseid
     * @param int $cmid
     * @return int|null
     */
    public static function synthetic_section(int $courseid, int $cmid): ?int {
        if ($cmid >= 0) {
            return null;
        }
        $offset = -1 * $cmid - ($courseid * content_extractor::SYNTHETIC_CMID_STRIDE);
        if ($offset < 1 || $offset > content_extractor::SYNTHETIC_CMID_STRIDE - 1) {
            return null;
        }
        return $offset - 1;
    }

    /**
     * Filtra registros de una tabla de módulo (quiz, assign, resource...) por
     * id de instancia, dejando solo los que el usuario actual puede ver.
     *
     * @param int $courseid
     * @param string $modname Nombre del módulo ('quiz', 'resource'...)
     * @param array $records Registros con ->id = instancia
     * @return array Mismos registros, mismas claves, solo los visibles.
     */
    public static function filter_instances(int $courseid, string $modname, array $records): array {
        if (empty($records)) {
            return $records;
        }
        $modinfo = self::modinfo($courseid);
        if (!$modinfo) {
            return [];
        }
        $visible = [];
        foreach ($modinfo->get_instances_of($modname) as $instanceid => $cminfo) {
            if (!empty($cminfo->uservisible)) {
                $visible[(int)$instanceid] = true;
            }
        }
        return array_filter($records, function($record) use ($visible) {
            return isset($visible[(int)$record->id]);
        });
    }

    /**
     * Quita de un fragmento de sección las líneas "- [tipo] nombre" de las
     * actividades que el usuario NO puede ver. El fragmento se indexó con todas
     * (content_extractor), así que el nombre de una actividad oculta viajaría
     * dentro de una sección visible.
     *
     * @param int $courseid
     * @param int $sectionnum
     * @param string $text
     * @return string
     */
    public static function scrub_section_text(int $courseid, int $sectionnum, string $text): string {
        $modinfo = self::modinfo($courseid);
        if (!$modinfo || empty($modinfo->sections[$sectionnum])) {
            return $text;
        }
        $hidden = [];
        foreach ($modinfo->sections[$sectionnum] as $cmid) {
            if (!isset($modinfo->cms[$cmid]) || !empty($modinfo->cms[$cmid]->uservisible)) {
                continue;
            }
            $cm = $modinfo->cms[$cmid];
            $name = trim((string)$cm->name);
            $hidden['- [' . $cm->modname . '] ' . ($name === '' ? 'actividad sin nombre' : $name)] = true;
        }
        if (empty($hidden)) {
            return $text;
        }
        $kept = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            if (!isset($hidden[trim($line)])) {
                $kept[] = $line;
            }
        }
        return implode("\n", $kept);
    }
}
