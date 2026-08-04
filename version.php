<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026080404; // Fix: mapeo real de completionstate (2=PASS, 3=FAIL son completadas)
$plugin->release   = '1.8.6';       // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;