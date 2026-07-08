<?php
/**
 * Cache definitions for block_pulso.
 *
 * @package    block_pulso
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$definitions = [
    // Unified analytics context per course. Rebuilding it runs several heavy
    // queries (completions, grades, module completions, logs) on every chat
    // message; entries carry their own timestamp and chat_pipeline treats
    // them as stale after ~2 minutes.
    'coursecontext' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
    ],
];
