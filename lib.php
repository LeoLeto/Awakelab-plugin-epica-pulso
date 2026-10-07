<?php
/**
 * Library functions for block_pulso.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Sirve el PNG de una infografía generada por Epica (classes/epica_client.php
 * la guarda con la File API, nunca en el historial del chat ni en un log).
 *
 * El fichero vive en el contexto de CURSO, filearea "encargo", itemid = id de
 * la fila en block_pulso_encargos — no en un contexto de bloque, porque el
 * encargo no está atado a una instancia de bloque concreta.
 *
 * Acceso: solo quien hizo el encargo, o alguien con la capacidad de
 * analítica (profesorado) del mismo curso — igual de conservador que el
 * resto del modo alumno del plugin.
 *
 * La filearea "juego" (el HTML de Gamificación, ver epica_client::guardar_juego())
 * vive en el MISMO contexto de curso pero NO se sirve NUNCA por aquí —
 * "$filearea !== 'encargo'" la excluye junto con cualquier otra, y es
 * PERMANENTE, no un pendiente: el HTML lo escribe un modelo, y servirlo tal
 * cual (sin sandbox/CSP) ejecutaría su JavaScript con el origen y la sesión
 * de Moodle de quien abra el enlace. La única vía para jugarlo es
 * juego_html.php (paso 2), que sirve el fichero modificado -con el puente de
 * puntuación y la CSP inyectados- y con cabeceras de aislamiento propias.
 */
function block_pulso_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($context->contextlevel !== CONTEXT_COURSE || $filearea !== 'encargo') {
        send_file_not_found();
    }

    require_login($course);

    $itemid = (int)array_shift($args);
    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';

    $encargo = $DB->get_record('block_pulso_encargos', ['id' => $itemid]);
    if (!$encargo || (int)$encargo->courseid !== (int)$course->id) {
        send_file_not_found();
    }

    $isowner = (int)$encargo->userid === (int)$USER->id;
    $isteacher = has_capability('block/pulso:viewanalytics', $context);
    if (!$isowner && !$isteacher) {
        send_file_not_found();
    }

    $fs = get_file_storage();
    $file = $fs->get_file($context->id, 'block_pulso', 'encargo', $itemid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        send_file_not_found();
    }

    send_stored_file($file, 0, 0, $forcedownload, $options);
}

/**
 * Limpieza al borrar un curso: Moodle llama a <componente>_pre_course_delete($course) desde
 * delete_course() (lib de cursos), con los datos del curso aún en pie.
 *
 * Borra todas las filas del curso en TODAS las tablas del plugin y sus ajustes por curso
 * (enabled_course_N, lastindexqueue_N). Los ficheros (encargo/juego) los borra Moodle con el
 * contexto del curso: no se tocan aquí. Cada tabla en su try/catch; ante un fallo, error_log y NO
 * se lanza nada: un fallo nuestro no puede bloquear el borrado de un curso.
 *
 * Cualquier tabla nueva del plugin con courseid/userid entra aquí y en classes/privacy/provider.php.
 *
 * @param stdClass $course
 */
function block_pulso_pre_course_delete($course) {
    global $DB;

    $courseid = (int)$course->id;
    $tables = [
        'block_pulso_content_chunks',
        'block_pulso_full_text',
        'block_pulso_encargos',
        'block_pulso_ampliaciones',
        'block_pulso_reto_propuestas',
        'block_pulso_retos',
    ];

    // Sin transacción: un rollback anidado dentro de la de delete_course() tumbaría el borrado del curso.
    foreach ($tables as $table) {
        try {
            if ($DB->get_manager()->table_exists($table)) {
                $DB->delete_records($table, ['courseid' => $courseid]);
            }
        } catch (\Throwable $e) {
            error_log('Pulso: no se pudo limpiar ' . $table . ' del curso ' . $courseid . ': ' . get_class($e) . ': ' . $e->getMessage());
        }
    }

    foreach (['enabled_course_', 'lastindexqueue_'] as $prefix) {
        try {
            unset_config($prefix . $courseid, 'block_pulso');
        } catch (\Throwable $e) {
            error_log('Pulso: no se pudo quitar el ajuste ' . $prefix . $courseid . ': ' . $e->getMessage());
        }
    }
}
