<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026102901; // v2.10.0: PDF enlazados desde módulos URL de dominios de confianza
$plugin->release   = '2.10.0';   // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;
// Sin $plugin->dependencies: local_awkepica es OPCIONAL (v1.31.0). Pulse se instala en una
// plataforma sin Épica; las herramientas de Épica solo se ofrecen si
// epica_client::disponible() — ver CLAUDE.md.
