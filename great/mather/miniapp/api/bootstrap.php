<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/miniapp_bootstrap.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

try {
    jsonResponse(buildMiniappBootstrapPayload($telegramId));
} catch (Throwable $e) {
    error_log('bootstrap failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
