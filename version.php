<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026100702; // v2.1.0: colores de cada centro (carta 11); db/tasks.php cambia (tarea sync_tema)
$plugin->release   = '2.1.0';    // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;
// Sin $plugin->dependencies: local_awkepica es OPCIONAL (v1.31.0). Pulse se instala en una
// plataforma sin Épica; las herramientas de Épica solo se ofrecen si
// epica_client::disponible() — ver CLAUDE.md.
