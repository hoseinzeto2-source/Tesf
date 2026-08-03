<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/bot_folders.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $folderId = isset($_GET['folder_id']) ? (int) $_GET['folder_id'] : 0;
    if ($folderId > 0) {
        $details = getBotFolderDetails($folderId, $telegramId);
        if (!$details) {
            jsonResponse(['ok' => false, 'error' => 'folder_not_found'], 404);
        }
        jsonResponse(['ok' => true, 'details' => $details]);
    }

    jsonResponse([
        'ok' => true,
        'folders' => getBotFolders($telegramId),
        'assignments' => getBotFolderAssignments($telegramId),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'create':
            $parentId = normalizeBotFolderParentId($body['parent_id'] ?? null);
            $folder = createBotFolder(
                $telegramId,
                (string) ($body['name'] ?? ''),
                (string) ($body['icon'] ?? 'folder'),
                $parentId
            );
            jsonResponse(['ok' => true, 'folder' => $folder]);
            break;

        case 'update':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            updateBotFolder(
                $telegramId,
                $folderId,
                isset($body['name']) ? (string) $body['name'] : null,
                isset($body['icon']) ? (string) $body['icon'] : null
            );
            jsonResponse(['ok' => true]);
            break;

        case 'delete':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteBotFolder($telegramId, $folderId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'assign':
            $botId = (int) ($body['bot_id'] ?? 0);
            if ($botId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_bot'], 400);
            }
            $folderId = $body['folder_id'] ?? null;
            $folderId = $folderId === null || $folderId === '' ? null : (int) $folderId;
            assignBotToFolder($botId, $folderId, $telegramId);
            jsonResponse(['ok' => true]);
            break;

        case 'move_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            $parentId = normalizeBotFolderParentId($body['parent_id'] ?? null);
            if (!moveBotFolder($telegramId, $folderId, $parentId)) {
                jsonResponse(['ok' => false, 'error' => 'move_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'pin':
            $entity = (string) ($body['entity'] ?? 'folder');
            $pinned = !empty($body['pinned']);
            if ($entity === 'bot') {
                $botId = (int) ($body['bot_id'] ?? 0);
                if ($botId <= 0) {
                    jsonResponse(['ok' => false, 'error' => 'invalid_bot'], 400);
                }
                setBotPinned($botId, $telegramId, $pinned);
            } else {
                $folderId = (int) ($body['folder_id'] ?? 0);
                if ($folderId <= 0) {
                    jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
                }
                setBotFolderPinned($folderId, $telegramId, $pinned);
            }
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('bot_folders api: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
