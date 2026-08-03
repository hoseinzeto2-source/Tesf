<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/banner_tools.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        ...getBannerToolsOverview($telegramId),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'sync_groups':
            $synced = syncAllContentGroupDescriptions();
            jsonResponse([
                'ok' => true,
                'synced' => $synced,
                ...getBannerToolsOverview($telegramId),
            ]);
            break;

        case 'set_binding':
            $folderId = (int) ($body['channel_folder_id'] ?? 0);
            $enabled = !empty($body['enabled']);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_channel_folder'], 400);
            }
            $binding = setBannerFolderBinding($telegramId, $folderId, $enabled);
            jsonResponse(['ok' => true, 'binding' => $binding]);
            break;

        case 'delete_binding':
            $folderId = (int) ($body['channel_folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteBannerFolderBinding($telegramId, $folderId)) {
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
