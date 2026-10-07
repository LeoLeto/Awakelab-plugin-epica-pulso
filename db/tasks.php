<?php
/**
 * Scheduled task definitions for block_pulso
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname'   => '\block_pulso\task\index_course_content',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '3',  // 3:00 AM daily
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
    [
        // Colores de cada centro (carta 11): cada hora, fuera de la petición de cualquier página.
        'classname'   => '\block_pulso\task\sync_tema',
        'blocking'    => 0,
        'minute'      => '17',
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
];
