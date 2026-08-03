<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/lib/manage_bots.php';

header('Content-Type: application/json; charset=utf-8');

try {
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();
    $bound = backfillPrimaryManageBotBindings();
    $webhooks = syncAllManageBotWebhooks();
    $overview = getManageBotsOverview();

    echo json_encode([
        'ok' => true,
        'bindings_backfilled' => $bound,
        'webhooks_synced' => $webhooks['synced'] ?? [],
        'webhook_errors' => $webhooks['errors'] ?? [],
        'overview' => $overview,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
