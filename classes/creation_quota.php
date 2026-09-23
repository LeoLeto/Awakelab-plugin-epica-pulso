<?php
/**
 * Cupos anti-abuso y registro de encargos de creacion para Epica
 * (infografias en v1.18.0; retos/presentaciones en versiones futuras).
 *
 * Los cupos cuentan ENCARGOS, no creaciones terminadas: Epica tiene cola
 * compartida entre herramientas, asi que el mismo contador protege a las
 * dos. Ver CLAUDE.md, seccion de integracion con Epica.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_pulso;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/full_text_store.php');

class creation_quota {

    /** @var string Unica herramienta en v1.18.0; 'reto'/'presentacion' llegaran despues. */
    const TOOL_INFOGRAFIA = 'infografia';

    /** @var string */
    const STATUS_PENDING = 'pendiente';

    /** @var string[] Formatos que acepta el contrato con Epica para infografias. */
    const FORMATS = ['poster_2_3', 'square', 'landscape_3_2'];

    /** @var int Tope duro del campo "que quieres" (contrato con Epica). */
    const MAX_PROMPT_LENGTH = 4000;

    /** @var int Por debajo de esto, el recurso se marca como "resultado pobre" en el desplegable. */
    const LOW_TEXT_THRESHOLD = 500;

    /**
     * Recursos que ESTE usuario puede elegir en el desplegable: con texto
     * aprovechable (full_text_store::get_available_resources) y visibles de
     * verdad para el (get_fast_modinfo()->uservisible, igual criterio que
     * rag_retriever::build_activity_link() usa para no filtrar enlaces a
     * actividades ocultas/restringidas).
     *
     * @param int $courseid
     * @param int $userid Para calcular el cupo de seccion ya consumido hoy.
     * @return array{resources: array, reason: ?string} reason en
     *               'not_indexed'|'no_usable'|'no_visible' cuando resources
     *               esta vacio; null si hay resultados.
     */
    public static function get_resources_context(int $courseid, int $userid): array {
        $raw = full_text_store::get_available_resources($courseid);
        if (empty($raw)) {
            $reason = full_text_store::has_any_indexed($courseid) ? 'no_usable' : 'not_indexed';
            return ['resources' => [], 'reason' => $reason];
        }

        $visible = self::filter_visible($raw, $courseid);
        if (empty($visible)) {
            return ['resources' => [], 'reason' => 'no_visible'];
        }

        return ['resources' => self::attach_section_usage($visible, $courseid, $userid), 'reason' => null];
    }

    /**
     * Filtra por get_fast_modinfo()->uservisible: sin esto, un alumno vería en
     * el desplegable el nombre de un recurso oculto o restringido -y el
     * nombre ya es información.
     */
    private static function filter_visible(array $raw, int $courseid): array {
        if (!function_exists('\get_fast_modinfo')) {
            return [];
        }
        try {
            $modinfo = \get_fast_modinfo($courseid);
        } catch (\Throwable $e) {
            return [];
        }

        $out = [];
        foreach ($raw as $cmid => $info) {
            try {
                $cm = $modinfo->get_cm($cmid);
            } catch (\Throwable $e) {
                continue;
            }
            if (empty($cm) || empty($cm->uservisible)) {
                continue;
            }
            $out[] = [
                'cmid' => $cmid,
                'name' => trim((string)$cm->name),
                'moduletype' => $info['module_type'],
                'moduletypelabel' => self::module_type_label($info['module_type']),
                'caracteres' => $info['caracteres'],
                'lowtext' => $info['caracteres'] < self::LOW_TEXT_THRESHOLD,
                'sectionnum' => (int)($cm->sectionnum ?? 0),
            ];
        }
        return $out;
    }

    /**
     * Cupo de sección+día ya consumido HOY por este usuario, por cada sección
     * presente en la lista. Se calcula aquí (en el mismo viaje que arma el
     * desplegable) para que el frontend pueda avisar al elegir el recurso,
     * ANTES de que el usuario escriba su petición -no al enviarla.
     */
    private static function attach_section_usage(array $resources, int $courseid, int $userid): array {
        $limit = (int)self::cfg('quota_user_section_day', 2);

        if ($userid <= 0) {
            foreach ($resources as &$r) {
                $r['sectionused'] = 0;
                $r['sectionlimit'] = $limit;
            }
            return $resources;
        }

        $midnight = usergetmidnight(time());
        $cache = [];
        foreach ($resources as &$r) {
            $section = $r['sectionnum'];
            if (!array_key_exists($section, $cache)) {
                $cache[$section] = self::count_rows(
                    'courseid = :courseid AND userid = :userid AND sectionnum = :sectionnum AND timecreated >= :since',
                    ['courseid' => $courseid, 'userid' => $userid, 'sectionnum' => $section, 'since' => $midnight]
                );
            }
            $r['sectionused'] = $cache[$section];
            $r['sectionlimit'] = $limit;
        }
        return $resources;
    }

    /**
     * Etiqueta legible del tipo de módulo, mismo criterio que
     * rag_retriever::build_generic_activity_answer().
     */
    public static function module_type_label(string $modtype): string {
        $labels = [
            'resource' => 'Archivo',
            'page' => 'Página',
            'url' => 'URL',
            'book' => 'Libro',
            'folder' => 'Carpeta',
            'quiz' => 'Cuestionario',
            'assign' => 'Tarea',
            'forum' => 'Foro',
            'glossary' => 'Glosario',
            'wiki' => 'Wiki',
            'lesson' => 'Lección',
            'scorm' => 'SCORM',
        ];
        return $labels[$modtype] ?? ucfirst($modtype);
    }

    /**
     * Cupos que NO dependen de qué recurso se elija: se comprueban al pulsar
     * el botón "Crear infografía", ANTES de enseñar el formulario. El cupo de
     * sección sí depende del recurso — ver attach_section_usage() (para
     * avisar pronto) y check_section_quota() (para revalidar al enviar).
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public static function check_general_quota(int $courseid, int $userid, bool $isteacher): array {
        $now = time();
        $midnight = usergetmidnight($now);

        $usercourselimit = (int)self::cfg('quota_user_course_day', 5);
        $usercourseused = self::count_rows(
            'courseid = :courseid AND userid = :userid AND timecreated >= :since',
            ['courseid' => $courseid, 'userid' => $userid, 'since' => $midnight]
        );
        if ($usercourseused >= $usercourselimit) {
            return ['allowed' => false, 'reason' =>
                "Has llegado a tu límite de encargos de hoy en este curso ({$usercourselimit}). Vuelve mañana."];
        }

        // Cupo adicional del docente, SIN filtrar por curso: cuenta sus
        // encargos en TODOS sus cursos, porque "por usuario y curso" de
        // arriba ya lo protege dentro de este curso.
        if ($isteacher) {
            $teacherlimit = (int)self::cfg('quota_teacher_day', 10);
            $teacherused = self::count_rows(
                'userid = :userid AND timecreated >= :since',
                ['userid' => $userid, 'since' => $midnight]
            );
            if ($teacherused >= $teacherlimit) {
                return ['allowed' => false, 'reason' =>
                    "Has llegado a tu límite diario de encargos como docente ({$teacherlimit}), contando todos tus cursos. Vuelve mañana."];
            }
        }

        $hourlimit = (int)self::cfg('quota_course_hour', 15);
        $hourused = self::count_rows(
            'courseid = :courseid AND timecreated >= :since',
            ['courseid' => $courseid, 'since' => $now - HOURSECS]
        );
        if ($hourused >= $hourlimit) {
            return ['allowed' => false, 'reason' =>
                "Este curso ha llegado al límite de encargos de la última hora ({$hourlimit}). Inténtalo más tarde."];
        }

        $daylimit = self::course_day_limit($courseid);
        $dayused = self::count_rows(
            'courseid = :courseid AND timecreated >= :since',
            ['courseid' => $courseid, 'since' => $midnight]
        );
        if ($dayused >= $daylimit) {
            return ['allowed' => false, 'reason' =>
                "Este curso ha llegado a su límite de encargos de hoy ({$daylimit}). Vuelve mañana."];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Revalidación en servidor, al ENVIAR, del cupo por usuario+sección+día
     * del recurso concreto elegido. El del desplegable (attach_section_usage)
     * es informativo y puede haber quedado desfasado entre que se abrió el
     * formulario y se pulsó enviar.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public static function check_section_quota(int $courseid, int $userid, int $sectionnum): array {
        $limit = (int)self::cfg('quota_user_section_day', 2);
        $used = self::count_rows(
            'courseid = :courseid AND userid = :userid AND sectionnum = :sectionnum AND timecreated >= :since',
            ['courseid' => $courseid, 'userid' => $userid, 'sectionnum' => $sectionnum, 'since' => usergetmidnight(time())]
        );
        if ($used >= $limit) {
            return ['allowed' => false, 'reason' =>
                "Has llegado a tu límite de encargos de hoy para esta sección ({$limit}). Prueba con otro recurso o mañana."];
        }
        return ['allowed' => true, 'reason' => null];
    }

    /**
     * max(suelo, matriculados x multiplicador). Los dos numeros son ajustes
     * de plugin (pendientes de aprobación de negocio), no constantes.
     */
    private static function course_day_limit(int $courseid): int {
        $floor = (int)self::cfg('quota_course_day_floor', 40);
        $multiplier = (float)self::cfg('quota_course_day_multiplier', 1.5);
        $enrolled = self::count_enrolled_users($courseid);
        return (int)max($floor, (int)ceil($enrolled * $multiplier));
    }

    private static function count_enrolled_users(int $courseid): int {
        global $DB;
        try {
            $sql = "SELECT COUNT(DISTINCT ue.userid) as cnt
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON ue.enrolid = e.id
                      JOIN {user} u ON u.id = ue.userid
                     WHERE e.courseid = :courseid AND ue.status = 0 AND e.status = 0 AND u.deleted = 0";
            $result = $DB->get_record_sql($sql, ['courseid' => $courseid]);
            return (int)($result->cnt ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private static function cfg(string $name, $default) {
        $value = get_config('block_pulso', $name);
        return ($value === false || $value === null || $value === '') ? $default : $value;
    }

    private static function count_rows(string $where, array $params): int {
        global $DB;
        if (!self::table_exists()) {
            return 0;
        }
        return (int)$DB->count_records_select('block_pulso_encargos', $where, $params);
    }

    /**
     * Registra el encargo con estado "pendiente". Quien llama debe haber
     * revalidado YA el cupo (check_general_quota + check_section_quota) y que
     * el recurso sigue siendo visible/aprovechable para este usuario.
     *
     * @return int id del encargo insertado
     */
    public static function record_encargo(
        int $courseid,
        int $cmid,
        int $sectionnum,
        int $userid,
        string $tool,
        string $prompt,
        string $format
    ): int {
        global $DB;

        if (!self::table_exists()) {
            throw new \Exception('La tabla de encargos no existe todavía — pendiente de ejecutar la actualización de Moodle.');
        }

        $now = time();
        $record = (object)[
            'courseid' => $courseid,
            'cmid' => $cmid,
            'sectionnum' => $sectionnum,
            'userid' => $userid,
            'tool' => $tool,
            'format' => $format,
            'prompt' => $prompt,
            'status' => self::STATUS_PENDING,
            'epica_job_id' => null,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $record->id = $DB->insert_record('block_pulso_encargos', $record);

        self::dispatch_to_epica($record);

        return $record->id;
    }

    /**
     * Encola el ciclo completo con Epica (firmar, encargar, sondear, recoger)
     * como una tarea adhoc: nunca se llama a Epica dentro de esta petición
     * web. Ver classes/task/epica_ciclo_adhoc.php y classes/epica_client.php
     * (paso 3 de la integración).
     */
    private static function dispatch_to_epica(\stdClass $encargo): void {
        try {
            $task = new \block_pulso\task\epica_ciclo_adhoc();
            $task->set_component('block_pulso');
            $task->set_custom_data(['encargoid' => $encargo->id]);
            \core\task\manager::queue_adhoc_task($task);
        } catch (\Throwable $e) {
            error_log('Pulso: no se pudo encolar el ciclo de Epica para el encargo '
                . $encargo->id . ': ' . $e->getMessage());
        }
    }

    private static function table_exists(): bool {
        global $DB;
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $exists = in_array('block_pulso_encargos', $DB->get_tables(), true);
        } catch (\Throwable $e) {
            $exists = false;
        }
        return $exists;
    }
}
