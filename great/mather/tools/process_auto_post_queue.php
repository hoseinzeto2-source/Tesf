<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/auto_post_schedules.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensureAutoPostScheduleTables();
    $results = processDueAutoPostSchedules(5);
    echo json_encode([
        'ok' => true,
        'processed' => count($results),
        'results' => $results,
        'at' => (new DateTime('now', autoPostIranTimezone()))->format('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
