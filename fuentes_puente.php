<?php
/**
 * Puente del PROFESORADO hacia Épica: «Material para Pulse» (carta 17, herramienta `fuentes`, por
 * defecto) y «Abrir en la Factoría» (carta 18, `herramienta=factoria` + `borrador`).
 *
 * Toda la lógica vive en classes/epica_puente.php (POST → autenticar → sesskey → viewanalytics →
 * herramienta de lista cerrada → Épica disponible → precondiciones → token nuevo → formulario
 * autoenviado a epica_client::endpoint('/api/auth/moodle')). Mismo patrón y mismas cabeceras que
 * epica_historial.php: el token solo viaja en el cuerpo de un POST del navegador.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/epica_puente.php');

// Sin `herramienta` es «Material para Pulse», como antes de la carta 18. Se lee tal cual (PARAM_RAW) y
// la valida epica_puente contra su lista cerrada; el borrador, con su regex (\z).
\block_pulso\epica_puente::abrir(
    optional_param('herramienta', 'fuentes', PARAM_RAW),
    optional_param('borrador', '', PARAM_RAW)
);
