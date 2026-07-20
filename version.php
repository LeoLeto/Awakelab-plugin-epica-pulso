<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026072000; // Boton de microfono: dictado por voz (Web Speech API)
$plugin->release   = '1.5.0';       // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;