<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/manage_bots.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

if (!isAdminTelegramId($telegramId)) {
    jsonResponse(['ok' => false, 'error' => 'forbidden'], 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        ...getManageBotsOverview(),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'add_bot':
            $token = trim((string) ($body['token'] ?? ''));
            $bot = addManageBot($token);
            jsonResponse(['ok' => true, 'bot' => $bot, ...getManageBotsOverview()]);
            break;

        case 'remove_bot':
            $botId = (int) ($body['manage_bot_id'] ?? 0);
            if ($botId <= 0 || !removeManageBot($botId)) {
                jsonResponse(['ok' => false, 'error' => 'remove_failed'], 400);
            }
            jsonResponse(['ok' => true, ...getManageBotsOverview()]);
            break;

        case 'refresh_health':
            $botId = (int) ($body['manage_bot_id'] ?? 0);
            if ($botId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_bot'], 400);
            }
            $health = refreshManageBotHealth($botId);
            jsonResponse(['ok' => true, 'health' => $health, ...getManageBotsOverview()]);
            break;

        case 'refresh_all_health':
            $bots = listManageBots();
            $results = [];
            foreach ($bots as $bot) {
                $results[] = refreshManageBotHealth((int) ($bot['id'] ?? 0));
            }
            jsonResponse(['ok' => true, 'results' => $results, ...getManageBotsOverview()]);
            break;

        case 'suggest_bot':
            $suggested = pickManageBotForNewChannel();
            jsonResponse([
                'ok' => true,
                'suggested' => $suggested,
                ...getManageBotsOverview(),
            ]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
