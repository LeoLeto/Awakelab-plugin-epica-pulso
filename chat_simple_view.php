<?php
/**
 * CHAT SIMPLE VIEW - render del shell (HTML en templates/chat.mustache, CSS en styles.css, JS en amd/src/*.js)
 * 
 * Archivo simple para renderizar el chat sin dependencias complejas.
 * Calcula el contexto de la plantilla y registra el módulo AMD.
 * 
 * Uso: require_once(__DIR__ . '/chat_simple_view.php');
 *      render_chat_simple($courseid, $context);
 * 
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Renderizar chat simple
 *
 * @param int $courseid
 * @param object $context Contexto del curso
 * @param bool $isteacher ¿Tiene 'block/pulso:viewanalytics'? Con false se
 *                        renderiza la versión de ALUMNO: sin tarjetas de
 *                        analítica, saludo y capacidades de contenido. Es solo
 *                        presentación — el bloqueo de datos está en servidor
 *                        (chat_pipeline / data_retriever / rag_retriever).
 * @param bool $cancreate ¿Tiene 'block/pulso:createactivity'? Con false no se
 *                        renderiza la pestaña «Crear» ni su sección de la home
 *                        (creaciones con Épica y ampliación). Por defecto lo tienen alumnado y
 *                        profesorado: existe para que un centro se lo pueda
 *                        quitar sin tocar código. Es solo presentación — el
 *                        bloqueo real está en los endpoints api_create_*.php.
 * @return string
 */
function render_chat_simple($courseid, $context, $isteacher = true, $cancreate = true) {
    global $OUTPUT, $USER, $CFG, $PAGE;

    // Construir URL base correcta para AJAX
    $api_url = $CFG->wwwroot . '/blocks/pulso/api_chat.php';
    $stream_url = $CFG->wwwroot . '/blocks/pulso/api_chat_stream.php';
    $create_form_url = $CFG->wwwroot . '/blocks/pulso/api_create_form.php';
    $create_submit_url = $CFG->wwwroot . '/blocks/pulso/api_create_submit.php';
    $create_status_url = $CFG->wwwroot . '/blocks/pulso/api_create_status.php';
    $ampliacion_url = $CFG->wwwroot . '/blocks/pulso/api_ampliacion.php';
    $retos_url = $CFG->wwwroot . '/blocks/pulso/api_retos.php';
    $historial_url = $CFG->wwwroot . '/blocks/pulso/epica_historial.php';

    // Leer la versión directamente de version.php (no de la BD) para que el
    // badge del header refleje siempre el código desplegado, incluso antes
    // de ejecutar la actualización de Moodle.
    $plugin = new stdClass();
    include(__DIR__ . '/version.php');
    $pulso_release = 'v' . ($plugin->release ?? $plugin->version ?? '?');

    // Token anti-CSRF de la sesión: los endpoints lo exigen con require_sesskey().
    $pulso_sesskey = sesskey();

    // ¿Hay Épica en este sitio? Solo presentación: los endpoints api_create_submit,
    // api_retos y epica_historial lo vuelven a comprobar.
    require_once(__DIR__ . '/classes/epica_client.php');
    $epicaavailable = \block_pulso\epica_client::disponible();

    // La configuracion que antes viajaba en variables globales window.* va al modulo AMD
    // (amd/src/chat.js). Solo para adaptar la UI: el servidor decide que datos se devuelven.
    $PAGE->requires->js_call_amd('block_pulso/chat', 'init', [[
        'courseid' => (int)$courseid,
        'apiUrl' => $api_url,
        'streamApiUrl' => $stream_url,
        'apiCreateFormUrl' => $create_form_url,
        'apiCreateSubmitUrl' => $create_submit_url,
        'apiCreateStatusUrl' => $create_status_url,
        'apiAmpliacionUrl' => $ampliacion_url,
        'apiRetosUrl' => $retos_url,
        'apiHistorialUrl' => $historial_url,
        'sesskey' => $pulso_sesskey,
        'isTeacher' => !empty($isteacher),
        'epicaAvailable' => (bool)$epicaavailable,
    ]]);
    
    // El saludo del profesor pregunta por el curso; el del alumno, por el
    // contenido: el anterior ofrecía notas y alumnos en riesgo, que ahora se le
    // niegan.
    $firstname = trim((string)($USER->firstname ?? ''));
    if ($isteacher) {
        $greeting = '¡Hola, ' . ($firstname !== '' ? $firstname : 'profe')
            . '! ¿Qué quieres saber de tu curso?';
    } else {
        $greeting = ($firstname !== '' ? '¡Hola, ' . $firstname . '! ' : '¡Hola! ')
            . 'Pregúntame sobre el contenido del curso: pídeme un resumen, '
            . 'una explicación o dudas sobre los materiales.';
    }

    try {
        $coursename = format_string(get_course($courseid)->fullname);
    } catch (Throwable $e) {
        $coursename = '';
    }

    // El shell estático vive en templates/chat.mustache (v1.36.0; antes un nowdoc con marcadores
    // <!--PULSO_…--> y str_replace). Las secciones {{#isteacher}}/{{#cancreate}}/{{#epica}} NO
    // renderizan lo que no le toca a este usuario: no se oculta con CSS, no llega al navegador.
    // Mustache escapa las variables {{…}}; ninguna lleva HTML.
    // La insignia de versión es para quien administra el curso (viewanalytics): sirve para
    // comprobar qué build corre. El alumnado no la ve (a él no le dice nada).
    return $OUTPUT->render_from_template('block_pulso/chat', [
        // Isotipo desde pix/ (antes de media.awakelab.world: una petición a un tercero en cada página).
        'isotipo' => $OUTPUT->image_url('isotipo-oscuro', 'block_pulso')->out(false),
        'isteacher' => !empty($isteacher),
        'cancreate' => !empty($cancreate),
        'epica' => (bool)$epicaavailable,
        'showversion' => !empty($isteacher),
        'release' => $pulso_release,
        'greeting' => $greeting,
        'coursename' => $coursename,
    ]);
}
