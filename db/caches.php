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
    // Indice de fuentes de Epica por curso (classes/fuentes_service.php, v2.7.0): solo `version` y
    // nº de fuentes (5 min), mas la marca «herramienta no contratada» de todo el sitio (1 h). El TTL
    // de la definicion es el mayor; cada entrada lleva su propia marca de tiempo y fuentes_service
    // decide cuando esta caducada.
    'fuentesidx' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl' => 3600,
    ],
];
