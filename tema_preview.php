<?php
/**
 * Vista previa de los colores del centro para quien administra el sitio (v2.5.0).
 *
 * Dos maquetas estáticas (Pulse por defecto y con los colores del centro) con las clases reales de
 * styles.css, y una tabla con cada variable resuelta. El formulario GET permite probar colores sin
 * guardar: pasan por la MISMA validación y resolución que lo que se pinta de verdad
 * (tema_service::validar_colores() + resolver_tema()), solo afectan a esta vista y no se guardan ni
 * se mandan a Épica. Nada de la URL llega al HTML sin pasar por esa validación.
 *
 * Sin JS del chat, sin llamadas a endpoints, con datos de ejemplo fijos y ficticios.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/epica_client.php');
require_once(__DIR__ . '/classes/tema_service.php');

use block_pulso\tema_service;

require_login();
require_admin();

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/blocks/pulso/tema_preview.php'));
$PAGE->set_pagelayout('admin');
$PAGE->set_title('Vista previa de los colores del centro');
$PAGE->set_heading('Vista previa de los colores del centro');

/** Las cinco claves del formulario, con su explicación. */
$claves = [
    'principal' => 'Principal (marca; de él se deriva lo que falte)',
    'cabecera' => 'Cabecera (cabecera y burbuja flotante)',
    'boton' => 'Botón (botones de acción, filtros activos, tablas)',
    'burbuja' => 'Burbuja (mensajes de quien escribe)',
    'acento' => 'Acento (foco, bordes, iconos)',
];

// --- Colores a probar: los de la URL (validados) o, si no hay ninguno, los guardados. ---
$avisos = [];
$probados = [];
$haypeticion = false;
foreach ($claves as $clave => $etiqueta) {
    $crudo = strtolower((string)optional_param($clave, '', PARAM_RAW_TRIMMED));
    if ($crudo === '') {
        continue;
    }
    $haypeticion = true;
    if (!preg_match(tema_service::RE_COLOR, $crudo)) {
        $avisos[] = 'El valor de «' . $clave . '» («' . mb_substr($crudo, 0, 24, 'UTF-8')
            . '») no tiene la forma #rrggbb y se ha ignorado.';
        continue;
    }
    $probados[$clave] = ['valor' => $crudo];
}
$colores = $haypeticion ? tema_service::validar_colores($probados) : tema_service::leer();
$res = tema_service::resolver_tema($colores);
$hayTema = !empty($res['vars']);

$isotipo = function (bool $claro) use ($OUTPUT): string {
    return $OUTPUT->image_url($claro ? 'isotipo-claro' : 'isotipo-oscuro', 'block_pulso')->out(false);
};

/**
 * Una maqueta estática. $style y $flags solo salen de resolver_tema() (hex validados o calculados).
 */
function pulso_prev_mock(string $titulo, string $style, string $flags, string $isotipourl): string {
    $attr = $style !== '' ? ' style="' . s($style) . '" data-pulso-tema="' . s($flags) . '"' : '';
    $svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">'
        . '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    $h = '<div class="pulso-prev-col"><h3>' . s($titulo) . '</h3>';
    $h .= '<button type="button" class="pulso-chat-bubble"' . $attr . ' tabindex="-1" aria-label="Burbuja flotante (maqueta)">'
        . '<img src="' . s($isotipourl) . '" alt=""></button>';
    $h .= '<div class="pulso-chat-container" role="group" aria-label="Maqueta: ' . s($titulo) . '"' . $attr . '>';
    $h .= '<div class="pulso-chat-header"><div class="pulso-header-top"><div class="pulso-header-brand">'
        . '<img class="pulso-header-logo" src="' . s($isotipourl) . '" alt="">'
        . '<div><h2>Pulse AI <span class="pulso-version-badge">v0.0.0</span></h2>'
        . '<span class="pulso-header-sub"><span class="pulso-status-dot"></span>Asistente del curso</span></div></div>'
        . '<div class="header-controls"><button type="button" class="header-btn" tabindex="-1" aria-label="Botón de cabecera (maqueta)">'
        . $svg . '</button></div></div>'
        . '<div class="pulso-tabs"><button type="button" class="pulso-tab" aria-selected="true" tabindex="-1">' . $svg
        . '<span>Preguntar</span></button><button type="button" class="pulso-tab" aria-selected="false" tabindex="-1">'
        . $svg . '<span>Crear</span></button></div></div>';
    $h .= '<div class="pulso-chat-messages" style="overflow:visible;flex:none">';
    $h .= '<div class="pulso-home"><div class="pulso-home-grid"><button type="button" class="pulso-action-card" tabindex="-1">'
        . '<span class="pulso-action-label">Panorama del curso (ejemplo)</span></button></div></div>';
    $h .= '<div class="pulso-message user"><div class="pulso-message-content">¿Cómo van las entregas del Tema 1?</div></div>';
    $h .= '<div class="pulso-message ai"><div class="pulso-message-content"><div class="pulso-table-card">'
        . '<div class="pulso-table-scroll"><table class="pulso-table"><caption class="pulso-sr-only">Tabla de ejemplo</caption>'
        . '<thead><tr><th scope="col"><button type="button" class="pulso-sort-btn" tabindex="-1">Actividad</button></th>'
        . '<th class="is-sorted" scope="col"><button type="button" class="pulso-sort-btn" tabindex="-1">Entregas</button></th></tr></thead>'
        . '<tbody><tr><td>Tarea de ejemplo A</td><td>12</td></tr><tr><td>Tarea de ejemplo B</td><td>9</td></tr></tbody>'
        . '</table></div></div></div></div>';
    $h .= '<div class="pulso-prev-row"><span class="pulso-create-submit" style="width:auto;padding:10px 18px">Botón de acción</span>'
        . '<span class="pulso-goto-link">Ir a…</span>'
        . '<span class="pulso-step-badge">Paso 1</span>'
        . '<button type="button" class="pulso-send-btn" tabindex="-1" aria-label="Enviar (maqueta)">' . $svg . '</button>'
        . '<span class="pulso-prev-focus">Elemento con foco</span></div>';
    $h .= '</div></div></div>';
    return $h;
}

$etiquetasorigen = [
    'epica' => 'Del centro (enviado)',
    'derivado' => 'Derivado del principal',
    'pulse' => 'La de Pulse',
];
$nombresvar = [
    '--pulso-header-bg' => 'Cabecera y burbuja flotante',
    '--pulso-action-bg' => 'Botones de acción, filtros activos, tablas',
    '--pulso-own-bg' => 'Burbuja propia',
    '--pulso-accent' => 'Acento (contraste frente al blanco del panel)',
];

echo $OUTPUT->header();

echo '<style>
.pulso-prev { display: flex; flex-wrap: wrap; gap: 24px; align-items: flex-start; }
.pulso-prev-col { flex: 1 1 340px; min-width: 0; }
.pulso-prev .pulso-chat-container { display: flex !important; position: static !important; width: 100% !important;
    height: auto !important; max-width: none !important; max-height: none !important; min-width: 0 !important;
    min-height: 0 !important; resize: none; animation: none; margin-top: 12px; }
.pulso-prev .pulso-chat-bubble { position: static !important; display: flex; animation: none; }
.pulso-prev-row { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; padding: 12px 16px 16px; }
.pulso-prev-focus { outline: 2px solid var(--pulso-accent); outline-offset: 2px; padding: 6px 10px; border-radius: 6px;
    font-size: 0.8rem; }
.pulso-prev-sw { display: inline-block; width: 1.2em; height: 1.2em; border: 1px solid #888; vertical-align: middle;
    margin-right: 6px; }
</style>';

echo html_writer::tag('p', 'Solo para administración. Las maquetas usan las clases reales de Pulse con datos de ejemplo '
    . 'ficticios; no llaman a ningún servicio. Lo que pruebes aquí no se guarda ni se manda a Épica.');
if (!\block_pulso\epica_client::disponible()) {
    echo $OUTPUT->notification('Épica no está disponible en este sitio: el chat no pinta los colores del centro. '
        . 'Esta vista sigue mostrando cómo se verían.', 'notifyinfo');
}

// --- Formulario para probar colores sin guardar. ---
echo '<form method="get" action="' . s((new moodle_url('/blocks/pulso/tema_preview.php'))->out(false)) . '" class="mb-3">';
echo '<fieldset><legend class="h5">Probar colores (sin guardar)</legend>';
foreach ($claves as $clave => $etiqueta) {
    $valor = isset($probados[$clave]) ? $probados[$clave]['valor'] : '';
    echo '<div class="mb-2"><label for="pulso-prev-' . s($clave) . '">' . s($etiqueta) . '</label> '
        . '<input type="text" id="pulso-prev-' . s($clave) . '" name="' . s($clave) . '" value="' . s($valor) . '" '
        . 'placeholder="#rrggbb" maxlength="7" size="9" pattern="#[0-9a-fA-F]{6}" autocomplete="off"></div>';
}
echo '<button type="submit" class="btn btn-primary">Probar estos colores</button> ';
echo html_writer::link(new moodle_url('/blocks/pulso/tema_preview.php'), 'Ver el tema guardado', ['class' => 'btn btn-secondary']);
echo '</fieldset></form>';
foreach ($avisos as $aviso) {
    echo $OUTPUT->notification(s($aviso), 'notifyproblem');
}
if ($res['ignorados']) {
    echo $OUTPUT->notification('Ignorado por contraste insuficiente: ' . s(implode(', ', $res['ignorados']))
        . ' (no llega a 3:1 sobre el blanco del panel). Se usa el acento derivado del principal, o el de Pulse si no hay principal.', 'notifywarning');
}

echo html_writer::tag('p', $haypeticion
    ? 'Mostrando los colores del formulario.'
    : ($colores ? 'Mostrando el tema guardado (versión ' . s((string)get_config('block_pulso', 'tema_version')) . ').'
        : 'No hay tema guardado: las dos maquetas son iguales.'));

// --- Dos maquetas, una al lado de la otra. ---
echo '<div class="pulso-prev">';
echo pulso_prev_mock('Sin tema (Pulse por defecto)', '', '', $isotipo(false));
echo pulso_prev_mock(
    'Con los colores del centro',
    $hayTema ? tema_service::css_de($res['vars']) : '',
    $hayTema ? implode(' ', $res['flags']) : '',
    $isotipo($hayTema && $res['isotipoclaro'])
);
echo '</div>';

// --- Variables resueltas. ---
echo $OUTPUT->heading('Variables resueltas', 3);
if (!$hayTema) {
    echo html_writer::tag('p', 'Sin tema: Pulse usa todos sus colores.');
} else {
    $table = new html_table();
    $table->head = ['Variable', 'Papel', 'Valor', 'Texto encima / fondo de comparación', 'Contraste', 'Origen'];
    $table->attributes['class'] = 'generaltable';
    $table->data = [];
    foreach ($res['detalle'] as $fila) {
        $minimo = $fila['var'] === '--pulso-accent' ? tema_service::CONTRASTE_GRAFICO : tema_service::CONTRASTE_TEXTO;
        $cumple = $fila['contraste'] >= $minimo;
        $table->data[] = [
            s($fila['var']),
            s(($nombresvar[$fila['var']] ?? '') . (isset($fila['uso']) ? ' — se usa en: ' . $fila['uso'] : '')),
            '<span class="pulso-prev-sw" style="background:' . s($fila['valor']) . '"></span>' . s($fila['valor']),
            s($fila['texto']) . ($fila['var'] === '--pulso-accent' ? ' (panel)' : ''),
            s(number_format($fila['contraste'], 2, ',', '')) . ':1 ' . ($cumple ? '✓' : '✗ (mín. '
                . number_format($minimo, 1, ',', '') . ')'),
            s($etiquetasorigen[$fila['origen']] ?? $fila['origen']),
        ];
    }
    echo html_writer::table($table);
    echo html_writer::tag('p', 'Tokens de data-pulso-tema: ' . s(implode(' ', $res['flags'])) . '.');
}

echo $OUTPUT->footer();
