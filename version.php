<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026071408; // Mejora #6.3: indexacion de contenido SCORM
$plugin->release   = '1.1.10';       // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;