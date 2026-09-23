<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026092304; // Ciclo con Epica (paso 3): firmar, encargar, sondear, recoger
$plugin->release   = '1.19.0';      // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;
$plugin->dependencies = [
    'local_awkepica' => 2026090902, // 1.1.0 — expone epica::rol_de()/firmar_por()/pedir()/ESPERA_LARGA_S
];