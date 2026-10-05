<?php
/**
 * AJAX endpoint: validate the configured OpenAI API key (embeddings del RAG).
 * Calls GET /v1/models — uses no tokens, just verifies auth. Va por el \curl de Moodle
 * (con proxy); la lógica vive en classes/diagnostics.php.
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/classes/anthropic_connector.php');
require_once(__DIR__ . '/classes/diagnostics.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json; charset=utf-8');

try {
    $res = \block_pulso\diagnostics::check_openai();
    echo json_encode(['success' => $res['status'] === \block_pulso\diagnostics::OK, 'message' => $res['message']], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    error_log('Pulso check_api_key: ' . get_class($e) . ': ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se ha podido comprobar la clave (detalle en el log del servidor).'], JSON_UNESCAPED_UNICODE);
}
exit;
