<?php
/**
 * Página para jugar un juego de Gamificación (Épica). El HTML del juego
 * NUNCA se sirve desde aquí: vive en un <iframe> cuyo src es
 * juego_html.php, la única vía segura para servirlo (ver su docblock).
 * Esta página solo pinta el marco (cabecera, avisos, marcador) y escucha el
 * mensaje de puntuación.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/creation_quota.php');

use block_pulso\creation_quota;

$id = required_param('id', PARAM_INT);

global $DB, $USER;

// Mismo orden y mismo criterio de acceso que juego_html.php: existe ->
// require_login -> dueño/viewanalytics -> tool/status. Los dos últimos dan
// el mismo 404 para no distinguir "no es tuyo" de "no está listo".
$encargo = $DB->get_record('block_pulso_encargos', ['id' => $id]);
if (!$encargo) {
    send_file_not_found();
}

$course = get_course((int)$encargo->courseid);
require_login($course);

$context = context_course::instance((int)$course->id);
$isowner = (int)$encargo->userid === (int)$USER->id;
$isteacher = has_capability('block/pulso:viewanalytics', $context);
if (!$isowner && !$isteacher) {
    send_file_not_found();
}

if ($encargo->tool !== creation_quota::TOOL_GAMIFICACION || $encargo->status !== 'listo' || empty($encargo->filename)) {
    send_file_not_found();
}

// Nombre del recurso origen, solo como ÚLTIMO respaldo del título (si el
// módulo se borró después de generar el juego, se degrada sin romper la
// página: el título cae a "Juego" a secas).
$resourcename = '';
try {
    $modinfo = get_fast_modinfo((int)$course->id);
    $cm = $modinfo->get_cm((int)$encargo->cmid);
    $resourcename = trim((string)$cm->name);
} catch (\Throwable $e) {
    $resourcename = '';
}

$tema = trim((string)$encargo->tema);
$titulo = trim((string)$encargo->titulo);
if ($titulo === '') {
    if ($tema !== '') {
        $titulo = 'Juego sobre ' . $tema;
    } else if ($resourcename !== '') {
        $titulo = 'Juego sobre ' . $resourcename;
    } else {
        $titulo = 'Juego';
    }
}

$PAGE->set_url('/blocks/pulso/juego.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_course($course);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title($titulo);
$PAGE->set_heading($titulo);

$playurl = new moodle_url('/blocks/pulso/juego_html.php', ['id' => $encargo->id]);
$courseurl = new moodle_url('/course/view.php', ['id' => $course->id]);

// verificado es tri-state (null = no se comprobó, no se enseña nada): solo
// "false" es un aviso real (carta 5 §3.4, con esas palabras literales).
$verificado = isset($encargo->verificado) && $encargo->verificado !== null ? (bool)$encargo->verificado : null;
$mock = !empty($encargo->mock);
$puntua = !empty($encargo->puntua);

echo $OUTPUT->header();
?>
<style>
/* Poppins viene de styles.css del bloque (@font-face local): sin Google Fonts. */
.pulso-juego-page {
    --pulso-surface: #F7F9FD;
    --pulso-ink: #27334F;
    --pulso-slate: #34547A;
    --pulso-line: #DCE3F2;
    --pulso-deep: #003670;
    --pulso-cyan-soft: #D9FBFF;
    --pulso-warning: #8A6100;
    font-family: 'Pulso Poppins', 'Poppins', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
    color: var(--pulso-ink);
    max-width: 960px;
    margin: 0 auto;
    padding: 8px 16px 32px;
}
.pulso-juego-aviso {
    border: 1px solid var(--pulso-line);
    border-radius: 8px;
    padding: 10px 14px;
    margin-bottom: 12px;
    font-size: 0.95rem;
}
.pulso-juego-aviso-warning {
    color: var(--pulso-warning);
    border-color: var(--pulso-warning);
    background: #FFF8E8;
}
.pulso-juego-aviso-info {
    color: var(--pulso-slate);
    background: var(--pulso-surface);
}
.pulso-juego-frame-wrap {
    border: 1px solid var(--pulso-line);
    border-radius: 12px;
    overflow: hidden;
    background: var(--pulso-surface);
}
.pulso-juego-frame-wrap iframe {
    display: block;
    width: 100%;
    height: min(80vh, 900px);
    border: 0;
}
.pulso-juego-score {
    margin-top: 14px;
    padding: 10px 14px;
    border-radius: 8px;
    background: var(--pulso-cyan-soft);
    color: var(--pulso-deep);
    font-weight: 600;
}
.pulso-juego-nota {
    margin-top: 14px;
    font-size: 0.85rem;
    color: var(--pulso-slate);
}
.pulso-juego-volver {
    display: inline-block;
    margin-top: 8px;
    color: #FFFFFF !important;
    background: var(--pulso-deep);
    padding: 8px 16px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
}
.pulso-juego-volver:hover {
    opacity: 0.9;
}
</style>
<div class="pulso-juego-page">
  <?php if ($verificado === false): ?>
    <div class="pulso-juego-aviso pulso-juego-aviso-warning">
      No hemos podido confirmar que este juego trate de <?php echo $tema !== '' ? s($tema) : 'el tema pedido'; ?>.
    </div>
  <?php endif; ?>
  <?php if ($mock): ?>
    <div class="pulso-juego-aviso pulso-juego-aviso-info">Este juego es de prueba.</div>
  <?php endif; ?>

  <div class="pulso-juego-frame-wrap">
    <iframe id="pulso-juego-iframe"
        src="<?php echo $playurl->out(false); ?>"
        sandbox="allow-scripts"
        allow="fullscreen; autoplay"
        referrerpolicy="no-referrer"
        title="<?php echo s($titulo); ?>"></iframe>
  </div>

  <?php if ($puntua): ?>
    <?php // Región en vivo SIEMPRE en el DOM y visible: un contenedor con `hidden` que se
          // destapa no se anuncia. El marcador va dentro y es lo que aparece. ?>
    <div role="status" aria-live="polite" aria-atomic="true">
      <div id="pulso-juego-score" class="pulso-juego-score" hidden>
        Tu puntuación: <span id="pulso-juego-score-value"></span>
      </div>
    </div>
  <?php endif; ?>

  <p class="pulso-juego-nota">La puntuación se calcula en tu navegador y no se guarda.</p>

  <a class="pulso-juego-volver" href="<?php echo $courseurl->out(false); ?>">Volver al curso</a>
</div>
<?php if ($puntua): ?>
<script>
(function () {
    var iframe = document.getElementById('pulso-juego-iframe');
    var scorebox = document.getElementById('pulso-juego-score');
    var scoreval = document.getElementById('pulso-juego-score-value');
    if (!iframe || !scorebox || !scoreval) {
        return;
    }
    // Validar por event.source (la ventana concreta del iframe), NO por
    // event.origin: dentro del sandbox sin allow-same-origin el origen del
    // juego es "null", así que comparar origen no distinguiría nada.
    window.addEventListener('message', function (e) {
        if (e.source !== iframe.contentWindow) {
            return;
        }
        if (!e.data || e.data.type !== 'pulso-score') {
            return;
        }
        var score = Number(e.data.score);
        var max = Number(e.data.maxscore);
        if (!isFinite(score) || !isFinite(max) || max <= 0) {
            return;
        }
        score = Math.max(0, Math.min(score, max));
        scoreval.textContent = score + ' / ' + max;
        scorebox.hidden = false;
    });
})();
</script>
<?php endif; ?>
<?php
echo $OUTPUT->footer();
