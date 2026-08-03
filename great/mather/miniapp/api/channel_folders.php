<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/channel_folders.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

requireTelegramUser();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        'folders' => getChannelFolders(),
        'assignments' => getChannelFolderAssignments(),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'create':
            $parentId = normalizeChannelFolderParentId($body['parent_id'] ?? null);
            $folder = createChannelFolder(
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
            updateChannelFolder(
                $folderId,
                isset($body['name']) ? (string) $body['name'] : null,
                isset($body['icon']) ? (string) $body['icon'] : null
            );
            jsonResponse(['ok' => true]);
            break;

        case 'delete':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteChannelFolder($folderId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'assign':
            $chatId = (int) ($body['chat_id'] ?? 0);
            if ($chatId === 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_chat'], 400);
            }
            $folderId = $body['folder_id'] ?? null;
            $folderId = $folderId === null || $folderId === '' ? null : (int) $folderId;
            assignChannelToFolder($chatId, $folderId);
            jsonResponse(['ok' => true]);
            break;

        case 'move_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            $parentId = normalizeChannelFolderParentId($body['parent_id'] ?? null);
            if (!moveChannelFolder($folderId, $parentId)) {
                jsonResponse(['ok' => false, 'error' => 'move_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'pin':
            require_once dirname(__DIR__, 2) . '/lib/channels.php';
            $entity = (string) ($body['entity'] ?? 'folder');
            $pinned = !empty($body['pinned']);
            if ($entity === 'channel') {
                $chatId = (int) ($body['chat_id'] ?? 0);
                if ($chatId === 0) {
                    jsonResponse(['ok' => false, 'error' => 'invalid_chat'], 400);
                }
                setChannelPinned($chatId, $pinned);
            } else {
                $folderId = (int) ($body['folder_id'] ?? 0);
                if ($folderId <= 0) {
                    jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
                }
                setChannelFolderPinned($folderId, $pinned);
            }
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('channel_folders api: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
