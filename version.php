<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026101501; // v2.6.0: modo demostración de Épica (carta 16)
$plugin->release   = '2.6.0';    // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;
// Sin $plugin->dependencies: local_awkepica es OPCIONAL (v1.31.0). Pulse se instala en una
// plataforma sin Épica; las herramientas de Épica solo se ofrecen si
// epica_client::disponible() — ver CLAUDE.md.
