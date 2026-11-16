<?php
/**
 * Event observers de block_pulso.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Borrado de la cuenta: pedir a Épica que borre lo suyo (carta 14). Solo encola una tarea adhoc.
        'eventname' => '\core\event\user_deleted',
        'callback'  => '\block_pulso\observer::user_deleted',
    ],
    [
        // Curso borrado: su mapa de unidades ya no es de nadie (v2.16.0).
        'eventname' => '\core\event\course_deleted',
        'callback'  => '\block_pulso\observer::course_deleted',
    ],
];
