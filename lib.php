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
