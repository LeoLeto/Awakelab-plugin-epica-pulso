<?php
/**
 * Capability definitions for block_pulso
 * Task T2.6.2: Implement data access permission controls
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [

    // Uso del chat para preguntas de CONTENIDO del curso (resumenes, materiales,
    // secciones, actividades). La tienen tambien los alumnos: es el permiso
    // minimo para que el bloque se renderice y los endpoints acepten la peticion.
    // NO da acceso a ningun dato analitico ni a datos de otros usuarios: eso lo
    // sigue guardando 'block/pulso:viewanalytics', que solo tiene el profesorado.
    'block/pulso:usechat' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'student' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // T2.6.2: Capability to view analytics data via Pulso chat
    'block/pulso:viewanalytics' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],

    // Standard Moodle block capabilities
    'block/pulso:addinstance' => [
        'riskbitmask' => RISK_SPAM | RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_BLOCK,
        'archetypes' => [
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
        'clonepermissionsfrom' => 'moodle/site:manageblocks',
    ],

    'block/pulso:myaddinstance' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],
];
