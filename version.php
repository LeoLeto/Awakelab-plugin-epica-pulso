<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026102902; // v2.10.1: SCORM sin texto (remotos) avisados en Crear y en el chat
$plugin->release   = '2.10.1';  // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;
// Sin $plugin->dependencies: local_awkepica es OPCIONAL (v1.31.0). Pulse se instala en una
// plataforma sin Épica; las herramientas de Épica solo se ofrecen si
// epica_client::disponible() — ver CLAUDE.md.
