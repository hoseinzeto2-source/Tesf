<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/zapas_bots.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        ...getZapasToolsOverview($telegramId),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'add_bot':
            $token = trim((string) ($body['token'] ?? ''));
            if ($token === '') {
                jsonResponse(['ok' => false, 'error' => 'token_required'], 400);
            }
            $bot = createZapasBot($telegramId, $token);
            jsonResponse([
                'ok' => true,
                'bot' => $bot,
                ...getZapasToolsOverview($telegramId),
            ]);
            break;

        case 'set_binding':
            $folderId = (int) ($body['channel_folder_id'] ?? 0);
            $enabled = !empty($body['enabled']);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_channel_folder'], 400);
            }
            $binding = setZapasFolderBinding($telegramId, $folderId, $enabled);
            jsonResponse(['ok' => true, 'binding' => $binding]);
            break;

        case 'delete_binding':
            $folderId = (int) ($body['channel_folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteZapasFolderBinding($telegramId, $folderId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'run_check':
            $count = processZapasReplacementsForOwner($telegramId);
            jsonResponse([
                'ok' => true,
                'replaced_count' => $count,
                ...getZapasToolsOverview($telegramId),
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
