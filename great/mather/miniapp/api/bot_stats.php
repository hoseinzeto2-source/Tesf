<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/bot_stats.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

$botId = (int) ($_GET['bot_id'] ?? 0);
if ($botId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'invalid_bot_id'], 400);
}

try {
    $stats = getBotStats($botId, $telegramId);
} catch (Throwable $e) {
    error_log('bot_stats api failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'database_error'], 500);
}

if (!$stats) {
    jsonResponse(['ok' => false, 'error' => 'bot_not_found'], 404);
}

jsonResponse([
    'ok' => true,
    'stats' => $stats,
]);
