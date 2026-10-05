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
    // Contador de preguntas del chat por usuario (classes/chat_rate_limiter.php, v1.31.0).
    // TTL de un día: el contador diario se reinicia solo y no se acumulan claves de
    // usuarios que ya no usan el chat.
    'chatrate' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 86400,
    ],
];
