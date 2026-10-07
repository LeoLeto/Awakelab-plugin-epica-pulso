<?php
/**
 * «Sincronizar los colores con Épica» (carta 11): lo llama el botón de los ajustes del plugin.
 *
 * Solo administración, solo POST y con sesskey. Llama a tema_service::sincronizar() y vuelve a
 * los ajustes con el resultado en texto para persona (nunca el detalle técnico: va a error_log).
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/tema_service.php');

require_login();
require_admin();

$ajustes = new moodle_url('/admin/settings.php', ['section' => 'blocksettingpulso']);

// Un GET (o un enlace) no sincroniza nada: solo el botón, que manda POST + sesskey.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($ajustes);
}
require_sesskey();

core_php_time_limit::raise(60);
$res = \block_pulso\tema_service::sincronizar();

redirect(
    $ajustes,
    s($res['mensaje']),
    null,
    $res['ok'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
);
