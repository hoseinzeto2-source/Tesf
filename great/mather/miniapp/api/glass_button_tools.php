<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/glass_button_tools.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        ...getGlassButtonToolsOverview($telegramId),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'save_settings':
            $folderId = (int) ($body['channel_folder_id'] ?? 0);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_channel_folder'], 400);
            }
            $settings = saveGlassButtonFolderSettings($telegramId, $folderId, $body);
            jsonResponse(['ok' => true, 'settings' => $settings]);
            break;

        case 'delete_settings':
            $folderId = (int) ($body['channel_folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteGlassButtonFolderSettings($telegramId, $folderId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
