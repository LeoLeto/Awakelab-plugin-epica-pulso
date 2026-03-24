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
];
