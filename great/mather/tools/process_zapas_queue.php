<?php

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/zapas_bots.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $replaced = runServerZapasReplacementPass(30);
    echo json_encode([
        'ok' => true,
        'replaced' => $replaced,
        'checked_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
