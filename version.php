<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026111800; // v2.18.0: «Crear juego» del profesorado se termina en Épica
$plugin->release   = '2.18.0';  // Semver: se ve en diagnostico.php (ya no en el chat) — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;
// Sin $plugin->dependencies: local_awkepica es OPCIONAL (v1.31.0). Pulse se instala en una
// plataforma sin Épica; las herramientas de Épica solo se ofrecen si
// epica_client::disponible() — ver CLAUDE.md.
