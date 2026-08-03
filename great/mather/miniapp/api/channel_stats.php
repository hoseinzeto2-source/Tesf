<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/channel_stats.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

requireTelegramUser();

$chatId = (int) ($_GET['chat_id'] ?? 0);
if ($chatId === 0) {
    jsonResponse(['ok' => false, 'error' => 'invalid_chat_id'], 400);
}

try {
    $stats = getChannelStats($chatId);
} catch (Throwable $e) {
    error_log('channel_stats api failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'database_error'], 500);
}

if (!$stats) {
    jsonResponse(['ok' => false, 'error' => 'channel_not_found'], 404);
}

jsonResponse([
    'ok' => true,
    'stats' => $stats,
]);
