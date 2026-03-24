<?php
// 1. Definir el Namespace siguiendo el estándar de Moodle (componente)
namespace block_pulso;

// Previene el acceso directo al archivo por seguridad
defined('MOODLE_INTERNAL') || die();

/**
 * Clase encargada de leer los logs de acceso de Moodle de forma segura.
 * Cumple con el Task T2.2.1 del Backlog.
 */
class access_log_reader {

    /**
     * Obtiene los logs de acceso recientes de un curso y los devuelve en formato JSON limpio para la IA.
     *
     * @param int $courseid El ID del curso a analizar (obligatorio).
     * @param int $daysback Cuántos días atrás buscar (por defecto 7).
     * @return string Cadena JSON con los logs formateados.
     */
    public function get_recent_access_logs_json(int $courseid, int $daysback = 7) {
        global $DB; // Usar el objeto global de base de datos de Moodle

        // 2. Calcular el Timestamp (segundos) para el filtro temporal
        $timetocheck = time() - ($daysback * DAYSECS); // DAYSECS es constante de Moodle

        // 3. Construir la consulta SQL segura usando placeholders (:courseid, :timetocheck)
        // Hacemos un JOIN con la tabla 'user' para tener nombres reales, no solo IDs.
        // Requisito T2.2.1: "Usar $DB para recuperar logs de acceso"
        $sql = "SELECT l.id, l.timecreated, l.action, l.target,
                       u.id AS userid, u.firstname, u.lastname
                FROM {logstore_standard_log} l
                JOIN {user} u ON l.userid = u.id
                WHERE l.courseid = :courseid
                  AND l.timecreated > :timetocheck
                ORDER BY l.timecreated DESC
                LIMIT 100"; // Limitamos para no saturar a la IA

        // Parámetros para la consulta (Placeholders seguros contra SQL Injection)
        $params = [
            'courseid' => $courseid,
            'timetocheck' => $timetocheck
        ];

        // 4. Ejecutar la consulta usando la API nativa $DB
        try {
            // get_records_sql devuelve un array de objetos
            $records = $DB->get_records_sql($sql, $params);
        } catch (\moodle_exception $e) {
            // Manejo de errores básico si falla la base de datos
            debugging('Error al recuperar logs para Pulso: ' . $e->getMessage());
            return json_encode(['error' => 'No se pudieron recuperar los logs de acceso.']);
        }

        if (empty($records)) {
            return json_encode(['message' => 'No hay actividad de acceso reciente en este curso en los últimos ' . $daysback . ' días.']);
        }

        // 5. Procesar y formatear los datos para la IA
        $formattedlogs = [];
        foreach ($records as $log) {
            // Requisito T2.2.1: "Devolver objetos JSON" - Primero creamos el array
            $formattedlogs[] = [
                'log_id' => $log->id,
                // Convertir timestamp a fecha legible por humanos (userdate usa la zona horaria del usuario)
                'fecha_hora_legible' => userdate($log->timecreated, '%d/%m/%Y %H:%M'),
                'timestamp' => $log->timecreated,
                'nombre_usuario' => $log->firstname . ' ' . $log->lastname,
                'user_id' => $log->userid,
                'accion_realizada' => $log->action, // ej: viewed, updated
                'componente_objetivo' => $log->target // ej: course, course_module
            ];
        }

        // Convertir el array final a una cadena JSON (Requisito del Task)
        // Usamos PRETTY_PRINT para que sea legible al probar, UNESCAPED para tildes.
        return json_encode($formattedlogs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}