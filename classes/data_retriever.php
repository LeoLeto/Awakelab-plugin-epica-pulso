<?php
// Definir el Namespace siguiendo el estándar de Moodle (componente)
namespace block_pulso;

// Previene el acceso directo al archivo por seguridad
defined('MOODLE_INTERNAL') || die();

/**
 * Clase data_retriever
 *
 * Encargada de extraer datos de analítica de la base de datos de Moodle
 * para enviarlos a una IA (OpenAI) en formato JSON.
 *
 * Implementa los Tasks T2.2.2 al T2.2.5 del Backlog.
 *
 * @package    block_pulso
 * @copyright  2024 Pulso Team
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class data_retriever {

    /**
     * Tope de filas que se TRAEN de la BD por cada consulta de analitica.
     *
     * Antes las tres consultas hacian get_records_sql() sin limite y el recorte a
     * 250 se aplicaba despues, ya en PHP (chat_pipeline::MAX_ANALYTICS_ROWS): un
     * curso de 3.000 alumnos con 50 items calificables cargaba cientos de miles de
     * filas en memoria para tirar el 99%. El limite se aplica ahora en SQL, y el
     * total REAL se obtiene con un COUNT(*) aparte para no mentirle al modelo sobre
     * la magnitud de los datos.
     *
     * Debe ser >= chat_pipeline::MAX_ANALYTICS_ROWS para que el tope de allí siga
     * siendo la referencia semantica y no oculte filas que aqui si cabrian.
     */
    const MAX_ROWS = 250;

    /**
     * Fragmento SQL que exige que el usuario tenga una matricula ACTIVA en el curso.
     *
     * Se usa como EXISTS y no como JOIN a proposito: un usuario puede estar
     * matriculado por varios metodos a la vez (manual + auto-matriculacion), y un
     * JOIN multiplicaria sus filas de notas/completitud falseando cualquier media.
     *
     * Filtra dos cosas distintas: ue.status = 0 (matricula del usuario activa, no
     * suspendida) y e.status = 0 (el metodo de matriculacion sigue habilitado).
     * Sin esto, los ex-alumnos seguian entrando en las estadisticas.
     *
     * @param string $useridfield Campo con el id de usuario en la consulta externa.
     * @param string $paramname   Nombre del parametro para el courseid (unico por consulta).
     * @return string
     */
    private static function active_enrolment_exists_sql(string $useridfield, string $paramname): string {
        return "EXISTS (
                    SELECT 1
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON e.id = ue.enrolid
                     WHERE ue.userid = {$useridfield}
                       AND e.courseid = :{$paramname}
                       AND ue.status = 0
                       AND e.status = 0
                )";
    }

    /**
     * T2.2.2: Obtiene el estado de finalización del curso para cada usuario enrolado.
     *
     * Consulta la tabla {course_completions} para obtener:
     * - ID del usuario
     * - Nombre del usuario
     * - Estado de completitud (0 = No completado, 1 = Completado)
     * - Fecha de completitud (si aplica)
     *
     * @param int $courseid El ID del curso a analizar (obligatorio).
     * @return array Array asociativo con los datos de completitud del curso.
     * @throws \moodle_exception Si hay error en la base de datos.
     */
    public function get_course_completions(int $courseid): array {
        global $DB;

        // SQL: Obtener completions del curso
        // Joinea {course_completions} con {user} para obtener nombres
        // y con {course_modules} para información de módulos
        $from = "FROM {course_completions} cc
                 JOIN {user} u ON cc.userid = u.id
                WHERE cc.course = :courseid
                  AND u.deleted = 0
                  AND " . self::active_enrolment_exists_sql('u.id', 'ccourseid');

        $sql = "SELECT
                    cc.id,
                    u.id AS userid,
                    u.firstname,
                    u.lastname,
                    cc.timecompleted,
                    cc.timeenrolled,
                    CASE WHEN cc.timecompleted > 0 THEN 1 ELSE 0 END AS is_completed
                {$from}
                ORDER BY u.firstname ASC, u.lastname ASC";

        $params = ['courseid' => $courseid, 'ccourseid' => $courseid];

        try {
            $total = (int)$DB->count_records_sql("SELECT COUNT(*) {$from}", $params);
            $records = $DB->get_records_sql($sql, $params, 0, self::MAX_ROWS);

            // Convertir registros a array asociativo formateable
            $result = [];
            foreach ($records as $record) {
                $result[] = [
                    'userid' => (int)$record->userid,
                    'firstname' => $record->firstname,
                    'lastname' => $record->lastname,
                    'is_completed' => (int)$record->is_completed,
                    'time_completed' => $record->timecompleted ? userdate($record->timecompleted, '%Y-%m-%d %H:%M:%S') : null,
                    'time_enrolled' => $record->timeenrolled ? userdate($record->timeenrolled, '%Y-%m-%d %H:%M:%S') : null,
                    'timestamp_completed_unix' => (int)$record->timecompleted
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $result,
                // `count` es el total REAL en BD, no el numero de filas devueltas.
                'count' => $total,
                'truncated' => $total > count($result)
            ];

        } catch (\moodle_exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error retrieving course completions: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * T2.2.3: Obtiene las calificaciones y resultados de cuestionarios por usuario.
     *
     * Joinea {grade_grades} con {grade_items} para obtener:
     * - Nombre del ítem (tarea, examen, etc.)
     * - Nota obtenida
     * - Escala máxima
     * - Estado de aprobación (basado en criterios de aprobación)
     * - Fecha de calificación
     *
     * @param int $courseid El ID del curso a analizar (obligatorio).
     * @return array Array asociativo con los datos de calificaciones y quizzes.
     * @throws \moodle_exception Si hay error en la base de datos.
     */
    public function get_grades_and_quizzes(int $courseid): array {
        global $DB;

        // SQL: Obtener grades & quizzes joinando múltiples tablas
        // {grade_grades} → datos de calificación
        // {grade_items} → información del ítem (nombre, tipo)
        // {user} → información del usuario
        $from = "FROM {grade_grades} gg
                 JOIN {grade_items} gi ON gg.itemid = gi.id
                 JOIN {user} u ON gg.userid = u.id
                WHERE gi.courseid = :courseid
                  -- Los valores reales de grade_items.itemtype en Moodle son
                  -- mod / manual / category / course / outcome. El filtro anterior
                  -- pedia 'assignment' y 'quiz', que NO existen (eso va en
                  -- itemmodule), asi que en la practica solo entraba 'mod' y los
                  -- items calificados a mano en el libro de calificaciones eran
                  -- invisibles. Se excluyen category/course a proposito: son
                  -- agregados y falsearian las medias por doble conteo.
                  AND gi.itemtype IN ('mod', 'manual')
                  AND u.deleted = 0
                  AND " . self::active_enrolment_exists_sql('u.id', 'gcourseid');

        $sql = "SELECT
                    gg.id,
                    u.id AS userid,
                    u.firstname,
                    u.lastname,
                    gi.itemname,
                    gi.itemtype,
                    gi.itemmodule,
                    gi.grademax,
                    gi.gradepass,
                    gg.rawgrade,
                    gg.finalgrade,
                    gg.timemodified,
                    -- Tres estados, NO dos. gradepass vale 0 cuando el profesor no
                    -- ha configurado nota de corte (lo normal), y antes ese caso
                    -- caia en el ELSE como reprobado: en cualquier curso sin corte
                    -- definido TODO el mundo salia suspendido y el porcentaje de
                    -- aprobados daba 0%. Sin corte -> NULL (desconocido).
                    CASE
                        WHEN gg.finalgrade IS NULL THEN NULL
                        WHEN gi.gradepass IS NULL OR gi.gradepass <= 0 THEN NULL
                        WHEN gg.finalgrade >= gi.gradepass THEN 1
                        ELSE 0
                    END AS is_passed
                {$from}
                ORDER BY u.firstname ASC, u.lastname ASC, gi.itemname ASC";

        $params = ['courseid' => $courseid, 'gcourseid' => $courseid];

        try {
            $total = (int)$DB->count_records_sql("SELECT COUNT(*) {$from}", $params);
            $records = $DB->get_records_sql($sql, $params, 0, self::MAX_ROWS);

            // Convertir y formatear registros
            $result = [];
            foreach ($records as $record) {
                $finalgrade = $record->finalgrade !== null ? (float)$record->finalgrade : null;
                $grademax = (float)$record->grademax;
                
                // Calcular porcentaje si hay nota
                $percentage = ($finalgrade !== null && $grademax > 0) 
                    ? round(($finalgrade / $grademax) * 100, 2) 
                    : null;

                $result[] = [
                    'userid' => (int)$record->userid,
                    'firstname' => $record->firstname,
                    'lastname' => $record->lastname,
                    'item_name' => $record->itemname,
                    // Para itemtype='mod' el tipo util esta en itemmodule
                    // (quiz, assign, forum...); 'manual' se queda tal cual.
                    'item_type' => $record->itemtype === 'mod' && !empty($record->itemmodule)
                        ? $record->itemmodule
                        : $record->itemtype,
                    'grade_obtained' => $finalgrade,
                    'grade_max' => $grademax,
                    'grade_pass' => $record->gradepass !== null && (float)$record->gradepass > 0
                        ? (float)$record->gradepass
                        : null,
                    'percentage' => $percentage,
                    // null = no se puede saber (sin nota, o sin nota de corte
                    // configurada en el item). NO confundir con reprobado.
                    'is_passed' => $record->is_passed !== null ? (int)$record->is_passed : null,
                    'time_graded' => $record->timemodified ? userdate($record->timemodified, '%Y-%m-%d %H:%M:%S') : null,
                    'timestamp_graded_unix' => (int)$record->timemodified
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $result,
                'count' => $total,
                'truncated' => $total > count($result)
            ];

        } catch (\moodle_exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error retrieving grades and quizzes: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * T2.2.4: Obtiene el estado de completitud de cada módulo/recurso por usuario.
     *
     * Usa {course_modules_completion} para listar:
     * - Nombre del módulo/recurso
     * - Tipo de módulo (assign, quiz, resource, etc.)
     * - Estado de completitud (Completado, Iniciado, No iniciado)
     * - Fecha de completitud
     * - Progreso de completitud
     *
     * @param int $courseid El ID del curso a analizar (obligatorio).
     * @return array Array asociativo con los datos de completitud de módulos.
     * @throws \moodle_exception Si hay error en la base de datos.
     */
    public function get_module_completions(int $courseid): array {
        global $DB;

        // SQL: Obtener module completions
        // {course_modules_completion} → estados de completitud de módulos
        // {course_modules} → información del módulo
        // {modules} → tipo de módulo
        // {user} → información del usuario
        $from = "FROM {course_modules_completion} cmc
                 JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id
                 JOIN {modules} m ON cm.module = m.id
                 JOIN {user} u ON cmc.userid = u.id
                WHERE cm.course = :courseid
                  AND u.deleted = 0
                  AND " . self::active_enrolment_exists_sql('u.id', 'mcourseid');

        $sql = "SELECT
                    cmc.id,
                    u.id AS userid,
                    u.firstname,
                    u.lastname,
                    cm.id AS moduleid,
                    cm.module,
                    m.name AS moduletype,
                    cm.instance,
                    cmc.timemodified,
                    cmc.completionstate,
                    -- Constantes reales de Moodle (lib/completionlib.php):
                    -- 0 COMPLETION_INCOMPLETE, 1 COMPLETION_COMPLETE,
                    -- 2 COMPLETION_COMPLETE_PASS, 3 COMPLETION_COMPLETE_FAIL.
                    -- Los estados 2 y 3 SI son actividad completada (con aprobado o
                    -- con suspenso); antes se traducian como 'incomplete'/'started',
                    -- asi que toda actividad con completitud por nota llegaba al
                    -- modelo con el estado equivocado.
                    CASE
                        WHEN cmc.completionstate = 1 THEN 'completed'
                        WHEN cmc.completionstate = 2 THEN 'completed_pass'
                        WHEN cmc.completionstate = 3 THEN 'completed_fail'
                        ELSE 'not_completed'
                    END AS completion_status
                {$from}
                ORDER BY u.firstname ASC, u.lastname ASC, cm.id ASC";

        $params = ['courseid' => $courseid, 'mcourseid' => $courseid];

        try {
            $total = (int)$DB->count_records_sql("SELECT COUNT(*) {$from}", $params);
            $records = $DB->get_records_sql($sql, $params, 0, self::MAX_ROWS);

            // Convertir y formatear registros
            $result = [];
            foreach ($records as $record) {
                $result[] = [
                    'userid' => (int)$record->userid,
                    'firstname' => $record->firstname,
                    'lastname' => $record->lastname,
                    'module_id' => (int)$record->moduleid,
                    'module_type' => $record->moduletype,
                    'module_instance' => (int)$record->instance,
                    'completion_state_code' => (int)$record->completionstate,
                    'completion_status' => $record->completion_status,
                    // Booleano explicito para que el modelo no tenga que
                    // interpretar la semantica de los 4 estados.
                    'is_completed' => (int)$record->completionstate > 0 ? 1 : 0,
                    'time_modified' => $record->timemodified ? userdate($record->timemodified, '%Y-%m-%d %H:%M:%S') : null,
                    'timestamp_modified_unix' => (int)$record->timemodified
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $result,
                'count' => $total,
                'truncated' => $total > count($result)
            ];

        } catch (\moodle_exception $e) {
            return [
                'status' => 'error',
                'message' => 'Error retrieving module completions: ' . $e->getMessage(),
                'data' => []
            ];
        }
    }

    /**
     * T2.2.5: Obtiene un "snapshot" unificado del contexto del curso.
     *
     * Este método consolida TODA la información del curso llamando a:
     * - get_course_completions()
     * - get_grades_and_quizzes()
     * - get_module_completions()
     * - access_log_reader->get_recent_access_logs_json() [si está disponible]
     *
     * El resultado es un único objeto JSON estructurado que sirve como
     * entrada para sistemas de IA (OpenAI, etc.) para análisis intelligente.
     *
     * @param int $courseid El ID del curso a analizar (obligatorio).
     * @param int $daysback Cuántos días atrás obtener los logs de acceso (por defecto 7).
     * @return array Array asociativo con el contexto unificado del curso en formato JSON-listo.
     * @throws \moodle_exception Si hay error en la base de datos.
     */
    public function get_unified_course_context(int $courseid, int $daysback = 7): array {
        global $DB;

        // Obtener información básica del curso
        try {
            $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        } catch (\moodle_exception $e) {
            return [
                'status' => 'error',
                'message' => 'Course not found: ' . $e->getMessage(),
                'data' => []
            ];
        }

        // Obtener todos los datos usando los métodos anteriores
        $completions = $this->get_course_completions($courseid);
        $grades = $this->get_grades_and_quizzes($courseid);
        $modules = $this->get_module_completions($courseid);

        // Intentar obtener logs de acceso si el módulo access_log_reader está disponible
        $logs = [];
        try {
            $access_reader = new access_log_reader();
            $logs_json = $access_reader->get_recent_access_logs_json($courseid, $daysback);
            // Parsear el JSON devuelto en array
            if (is_string($logs_json)) {
                $logs = json_decode($logs_json, true) ?: [];
            } else {
                $logs = $logs_json;
            }
        } catch (\Exception $e) {
            // Si no está disponible, continuar sin logs
            $logs = [
                'status' => 'unavailable',
                'message' => 'Access logs not available'
            ];
        }

        // Construir el payload unificado
        $payload = [
            'status' => 'success',
            'timestamp_generated' => userdate(time(), '%Y-%m-%d %H:%M:%S'),
            'timestamp_generated_unix' => time(),
            'course_context' => [
                'course_id' => (int)$course->id,
                'course_name' => $course->fullname,
                'course_shortname' => $course->shortname,
                'course_summary' => $course->summary,
                'course_startdate' => userdate($course->startdate, '%Y-%m-%d %H:%M:%S'),
                'course_startdate_unix' => (int)$course->startdate
            ],
            'analytics' => [
                // *_count es el total REAL en BD; *_truncated avisa de que la lista
                // adjunta es una muestra (tope MAX_ROWS aplicado en SQL).
                'course_completions' => $completions['data'] ?? [],
                'course_completions_count' => $completions['count'] ?? 0,
                'course_completions_truncated' => !empty($completions['truncated']),
                'grades_and_quizzes' => $grades['data'] ?? [],
                'grades_and_quizzes_count' => $grades['count'] ?? 0,
                'grades_and_quizzes_truncated' => !empty($grades['truncated']),
                'module_completions' => $modules['data'] ?? [],
                'module_completions_count' => $modules['count'] ?? 0,
                'module_completions_truncated' => !empty($modules['truncated'])
            ],
            'access_logs' => $logs,
            'metadata' => [
                'total_enrolled_users' => $this->count_enrolled_users($courseid),
                'total_students' => $this->count_students($courseid),
                'days_back_for_logs' => $daysback,
                'data_retriever_version' => '1.0.0',
                'moodle_version' => $GLOBALS['CFG']->version ?? 'unknown'
            ]
        ];

        return $payload;
    }

    /**
     * Método auxiliar: Obtiene el total de usuarios enrolados en un curso.
     *
     * @param int $courseid El ID del curso.
     * @return int Número total de usuarios enrolados.
     */
    private function count_enrolled_users(int $courseid): int {
        global $DB;

        try {
            // Contar usuarios con matricula activa: incluye profesorado. Para el
            // numero de ALUMNOS, ver count_students().
            $sql = "SELECT COUNT(DISTINCT ue.userid) as count
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON ue.enrolid = e.id
                   JOIN {user} u ON u.id = ue.userid
                   WHERE e.courseid = :courseid
                     AND ue.status = 0
                     AND e.status = 0
                     AND u.deleted = 0";

            $result = $DB->get_record_sql($sql, ['courseid' => $courseid]);
            return (int)($result->count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Numero de ALUMNOS del curso: matriculados activos MENOS el personal docente.
     *
     * "Cuantos alumnos hay" es una de las preguntas centrales del chat, y
     * total_enrolled_users incluye a profesores y gestores, asi que respondia de
     * mas. Se usa moodle/grade:viewall como discriminador de staff — la misma
     * capacidad con la que chat_pipeline decide si enseñar datos individuales, para
     * que ambos criterios no se contradigan.
     *
     * @param int $courseid
     * @return int|null null si no se pudo determinar (mejor que mentir con un 0).
     */
    private function count_students(int $courseid): ?int {
        global $DB;

        try {
            $context = \context_course::instance($courseid);

            [$esql, $eparams] = get_enrolled_sql($context, '', 0, true);
            $enrolledids = $DB->get_fieldset_sql(
                "SELECT u.id
                   FROM {user} u
                   JOIN ({$esql}) je ON je.id = u.id
                  WHERE u.deleted = 0",
                $eparams
            );

            if (empty($enrolledids)) {
                return 0;
            }

            $staff = get_users_by_capability($context, 'moodle/grade:viewall', 'u.id');
            $staffids = is_array($staff) ? array_keys($staff) : [];

            return count(array_diff($enrolledids, $staffids));
        } catch (\Throwable $e) {
            return null;
        }
    }
}
