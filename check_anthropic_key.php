<?php
/**
 * AJAX endpoint: validate the configured Anthropic API key (used for chat).
 * Calls GET /v1/models — uses no tokens, just verifies auth.
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

header('Content-Type: application/json; charset=utf-8');

try {
    $apikey = get_config('block_pulso', 'anthropic_key');

    if (empty($apikey)) {
        echo json_encode(['success' => false, 'message' => 'No API key configured.']);
        exit;
    }

    $ch = curl_init('https://api.anthropic.com/v1/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'x-api-key: ' . $apikey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $body     = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerr  = curl_error($ch);
    curl_close($ch);

    if ($curlerr) {
        echo json_encode(['success' => false, 'message' => 'Connection error: ' . $curlerr]);
        exit;
    }

    if ($httpcode === 200) {
        echo json_encode(['success' => true, 'message' => 'API key is valid.']);
    } else {
        $data    = json_decode($body, true);
        $message = $data['error']['message'] ?? 'HTTP ' . $httpcode;
        echo json_encode(['success' => false, 'message' => $message]);
    }
} catch (\Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
exit;
