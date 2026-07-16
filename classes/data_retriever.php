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
        $sql = "SELECT
                    cc.id,
                    u.id AS userid,
                    u.firstname,
                    u.lastname,
                    cc.timecompleted,
                    cc.timeenrolled,
                    CASE WHEN cc.timecompleted > 0 THEN 1 ELSE 0 END AS is_completed
                FROM {course_completions} cc
                JOIN {user} u ON cc.userid = u.id
                WHERE cc.course = :courseid
                ORDER BY u.firstname ASC, u.lastname ASC";

        $params = ['courseid' => $courseid];

        try {
            $records = $DB->get_records_sql($sql, $params);
            
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
                'count' => count($result)
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
        // {course_modules} → información de módulos
        $sql = "SELECT
                    gg.id,
                    u.id AS userid,
                    u.firstname,
                    u.lastname,
                    gi.itemname,
                    gi.itemtype,
                    gi.grademax,
                    gg.rawgrade,
                    gg.finalgrade,
                    gg.timemodified,
                    CASE 
                        WHEN gg.finalgrade >= gi.gradepass AND gi.gradepass > 0 THEN 1
                        WHEN gg.finalgrade IS NULL THEN 0
                        ELSE 0 
                    END AS is_passed
                FROM {grade_grades} gg
                JOIN {grade_items} gi ON gg.itemid = gi.id
                JOIN {user} u ON gg.userid = u.id
                WHERE gi.courseid = :courseid
                  AND gi.itemtype IN ('assignment', 'quiz', 'mod')
                ORDER BY u.firstname ASC, u.lastname ASC, gi.itemname ASC";

        $params = ['courseid' => $courseid];

        try {
            $records = $DB->get_records_sql($sql, $params);
            
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
                    'item_type' => $record->itemtype,
                    'grade_obtained' => $finalgrade,
                    'grade_max' => $grademax,
                    'percentage' => $percentage,
                    'is_passed' => (int)$record->is_passed,
                    'time_graded' => $record->timemodified ? userdate($record->timemodified, '%Y-%m-%d %H:%M:%S') : null,
                    'timestamp_graded_unix' => (int)$record->timemodified
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $result,
                'count' => count($result)
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
                    CASE 
                        WHEN cmc.completionstate = 1 THEN 'completed'
                        WHEN cmc.completionstate = 2 THEN 'incomplete'
                        WHEN cmc.completionstate = 3 THEN 'started'
                        ELSE 'not_started'
                    END AS completion_status
                FROM {course_modules_completion} cmc
                JOIN {course_modules} cm ON cmc.coursemoduleid = cm.id
                JOIN {modules} m ON cm.module = m.id
                JOIN {user} u ON cmc.userid = u.id
                WHERE cm.course = :courseid
                ORDER BY u.firstname ASC, u.lastname ASC, cm.id ASC";

        $params = ['courseid' => $courseid];

        try {
            $records = $DB->get_records_sql($sql, $params);
            
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
                    'time_modified' => $record->timemodified ? userdate($record->timemodified, '%Y-%m-%d %H:%M:%S') : null,
                    'timestamp_modified_unix' => (int)$record->timemodified
                ];
            }
            
            return [
                'status' => 'success',
                'data' => $result,
                'count' => count($result)
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
                'course_completions' => $completions['data'] ?? [],
                'course_completions_count' => $completions['count'] ?? 0,
                'grades_and_quizzes' => $grades['data'] ?? [],
                'grades_and_quizzes_count' => $grades['count'] ?? 0,
                'module_completions' => $modules['data'] ?? [],
                'module_completions_count' => $modules['count'] ?? 0
            ],
            'access_logs' => $logs,
            'metadata' => [
                'total_enrolled_users' => $this->count_enrolled_users($courseid),
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
            // Contar usuarios activos enrolados en el curso
            $sql = "SELECT COUNT(DISTINCT ue.userid) as count
                   FROM {user_enrolments} ue
                   JOIN {enrol} e ON ue.enrolid = e.id
                   WHERE e.courseid = :courseid
                     AND ue.status = 0"; // 0 = activo

            $result = $DB->get_record_sql($sql, ['courseid' => $courseid]);
            return (int)($result->count ?? 0);
        } catch (\Exception $e) {
            return 0;
        }
    }
}
