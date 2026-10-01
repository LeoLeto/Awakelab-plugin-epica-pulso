<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'block_pulso'; // Nombre técnico exacto
$plugin->version = 2026100101; // Ampliacion: filter OpenAlex + juez Haiku; upgrade borra cache
$plugin->release   = '1.24.2';      // Semver visible en el header del chat — bump en CADA cambio
$plugin->requires  = 2022111800;    // Moodle 4.1 o superior
$plugin->maturity  = MATURITY_ALPHA;
$plugin->dependencies = [
    'local_awkepica' => 2026090902, // 1.1.0 — expone epica::rol_de()/firmar_por()/pedir()/ESPERA_LARGA_S
];