<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026071605; // Fix: quita el prefill assistant '{' (claude-sonnet-5 lo rechaza con 400)
$plugin->release   = '1.2.4';       // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;