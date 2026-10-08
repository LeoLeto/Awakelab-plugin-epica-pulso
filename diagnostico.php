<?php
/**
 * Página de diagnóstico de Pulse para quien administra el sitio (v1.31.0).
 *
 * Enlazada desde los ajustes del plugin. Comprueba claves y servicios externos (con el
 * proxy de Moodle), Épica, el cron, las tareas adhoc del plugin y los cursos con el bloque
 * pero sin texto completo indexado. NUNCA muestra claves.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/diagnostics.php');

use block_pulso\diagnostics;

require_login();
require_admin();

// Cuatro llamadas de red de hasta 10 s cada una.
core_php_time_limit::raise(120);

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/pulso/diagnostico.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Diagnóstico de Pulse');
$PAGE->set_heading('Diagnóstico de Pulse');

/**
 * Fila de la tabla: etiqueta de estado + mensaje (ya limpio de claves).
 */
function pulso_diag_row(string $name, array $res): array {
    $labels = [
        diagnostics::OK => ['OK', 'success'],
        diagnostics::WARN => ['Aviso', 'warning'],
        diagnostics::FAIL => ['Fallo', 'danger'],
    ];
    [$text, $class] = $labels[$res['status']] ?? ['?', 'secondary'];
    // Clases de Bootstrap 4 y 5 a la vez: el tema de cada sitio usa una u otra.
    $badge = html_writer::span($text, "badge badge-{$class} bg-{$class}" . ($class === 'warning' ? ' text-dark' : ' text-white'));
    return [s($name), $badge, s($res['message'])];
}

$checks = [
    'Anthropic (chat)' => 'check_anthropic',
    'OpenAI (embeddings del RAG)' => 'check_openai',
    'YouTube (Ampliar recurso)' => 'check_youtube',
    'OpenAlex (Ampliar recurso)' => 'check_openalex',
    'Épica (local_awkepica)' => 'check_epica',
    'Colores del centro (Épica)' => 'check_tema',
    'Cron de Moodle' => 'check_cron',
    'Tarea de indexación' => 'check_index_task',
    'Tareas adhoc de Pulse' => 'check_adhoc',
];

$table = new html_table();
$table->head = ['Comprobación', 'Estado', 'Detalle'];
$table->attributes['class'] = 'generaltable';
$table->data = [];
foreach ($checks as $name => $method) {
    try {
        $res = diagnostics::$method();
    } catch (\Throwable $e) {
        error_log('Pulso diagnostico ' . $method . ': ' . get_class($e) . ': ' . $e->getMessage());
        $res = ['status' => diagnostics::FAIL, 'message' => 'La comprobación ha fallado (detalle en el log del servidor).'];
    }
    $table->data[] = pulso_diag_row($name, $res);
}

echo $OUTPUT->header();
echo html_writer::tag('p', 'Las claves nunca se muestran. Las llamadas de red usan el proxy de Moodle, si lo hay. '
    . 'Recarga la página para volver a comprobar.');
echo html_writer::table($table);
echo html_writer::tag('p', html_writer::link(new moodle_url('/blocks/pulso/tema_preview.php'), 'Vista previa de los colores del centro'));

// Cursos con el bloque y sin texto completo.
echo $OUTPUT->heading('Cursos con Pulse sin texto completo indexado', 3);
try {
    $nofulltext = diagnostics::courses_without_text();
    if ($nofulltext['total'] === 0) {
        echo $OUTPUT->notification('Todos los cursos con el bloque tienen texto indexado.', 'notifysuccess');
    } else {
        echo html_writer::tag('p', "{$nofulltext['total']} curso(s). Sin texto, «Crear» dice que no hay recursos. "
            . 'Se resuelve en la próxima pasada de la tarea «Indexar el contenido del curso» (o ejecutándola a mano en '
            . 'Administración del sitio → Servidor → Tareas → Tareas programadas).');
        $items = [];
        foreach ($nofulltext['courses'] as $id => $fullname) {
            $items[] = html_writer::link(new moodle_url('/course/view.php', ['id' => $id]), $fullname);
        }
        echo html_writer::alist($items);
        if ($nofulltext['total'] > count($nofulltext['courses'])) {
            echo html_writer::tag('p', '… y ' . ($nofulltext['total'] - count($nofulltext['courses'])) . ' más.');
        }
    }
} catch (\Throwable $e) {
    error_log('Pulso diagnostico cursos: ' . get_class($e) . ': ' . $e->getMessage());
    echo $OUTPUT->notification('No se ha podido consultar (detalle en el log del servidor).', 'notifyproblem');
}

echo $OUTPUT->footer();
