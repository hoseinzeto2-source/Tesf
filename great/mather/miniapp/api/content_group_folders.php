<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/content_group_folders.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

requireTelegramUser();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        'folders' => getContentGroupFolders(),
        'assignments' => getContentGroupFolderAssignments(),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'create':
            $folder = createContentGroupFolder(
                (string) ($body['name'] ?? ''),
                (string) ($body['icon'] ?? 'folder')
            );
            jsonResponse(['ok' => true, 'folder' => $folder]);
            break;

        case 'update':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            updateContentGroupFolder(
                $folderId,
                isset($body['name']) ? (string) $body['name'] : null,
                isset($body['icon']) ? (string) $body['icon'] : null
            );
            jsonResponse(['ok' => true]);
            break;

        case 'delete':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteContentGroupFolder($folderId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'assign':
            $chatId = (int) ($body['chat_id'] ?? 0);
            if ($chatId === 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_group'], 400);
            }
            $folderId = $body['folder_id'] ?? null;
            $folderId = $folderId === null || $folderId === '' ? null : (int) $folderId;
            assignContentGroupToFolder($chatId, $folderId);
            jsonResponse(['ok' => true]);
            break;

        case 'pin':
            $entity = (string) ($body['entity'] ?? 'folder');
            $pinned = !empty($body['pinned']);
            if ($entity === 'group') {
                $chatId = (int) ($body['chat_id'] ?? 0);
                if ($chatId === 0) {
                    jsonResponse(['ok' => false, 'error' => 'invalid_group'], 400);
                }
                setContentGroupPinned($chatId, $pinned);
            } else {
                $folderId = (int) ($body['folder_id'] ?? 0);
                if ($folderId <= 0) {
                    jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
                }
                setContentGroupFolderPinned($folderId, $pinned);
            }
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('content_group_folders failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
