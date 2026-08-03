<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/hashtag_tools.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $setId = isset($_GET['set_id']) ? (int) $_GET['set_id'] : 0;
    if ($setId > 0) {
        $set = getHashtagPostSetById($telegramId, $setId);
        if (!$set) {
            jsonResponse(['ok' => false, 'error' => 'set_not_found'], 404);
        }
        jsonResponse(['ok' => true, 'set' => $set]);
    }

    jsonResponse([
        'ok' => true,
        'default_folder_id' => ensureDefaultHashtagFolder($telegramId)['id'],
        'folders' => getHashtagToolFolders($telegramId),
        'sets' => getHashtagPostSets($telegramId),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'create_folder':
            $parentId = isset($body['parent_id']) && (int) $body['parent_id'] > 0 ? (int) $body['parent_id'] : null;
            $folder = createHashtagToolFolder(
                $telegramId,
                (string) ($body['name'] ?? ''),
                (string) ($body['icon'] ?? 'hashtag'),
                $parentId
            );
            jsonResponse(['ok' => true, 'folder' => $folder]);
            break;

        case 'delete_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteHashtagToolFolder($telegramId, $folderId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'pin_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            $pinned = !empty($body['pinned']);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            setHashtagToolFolderPinned($telegramId, $folderId, $pinned);
            jsonResponse(['ok' => true]);
            break;

        case 'create_set':
            $tags = is_array($body['tags'] ?? null) ? $body['tags'] : [];
            $folderId = isset($body['folder_id']) && (int) $body['folder_id'] > 0 ? (int) $body['folder_id'] : null;
            $set = createHashtagPostSet(
                $telegramId,
                (string) ($body['name'] ?? ''),
                (int) ($body['channel_folder_id'] ?? 0),
                (string) ($body['selection_mode'] ?? 'random'),
                (int) ($body['random_count'] ?? 5),
                $tags,
                $folderId
            );
            jsonResponse(['ok' => true, 'set' => $set]);
            break;

        case 'update_set':
            $setId = (int) ($body['set_id'] ?? 0);
            if ($setId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_set'], 400);
            }
            $tags = array_key_exists('tags', $body) && is_array($body['tags']) ? $body['tags'] : null;
            updateHashtagPostSet(
                $telegramId,
                $setId,
                array_key_exists('name', $body) ? (string) $body['name'] : null,
                isset($body['channel_folder_id']) ? (int) $body['channel_folder_id'] : null,
                array_key_exists('selection_mode', $body) ? (string) $body['selection_mode'] : null,
                array_key_exists('random_count', $body) ? (int) $body['random_count'] : null,
                $tags
            );
            $set = getHashtagPostSetById($telegramId, $setId);
            jsonResponse(['ok' => true, 'set' => $set]);
            break;

        case 'delete_set':
            $setId = (int) ($body['set_id'] ?? 0);
            if ($setId <= 0 || !deleteHashtagPostSet($telegramId, $setId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'pin_set':
            $setId = (int) ($body['set_id'] ?? 0);
            $pinned = !empty($body['pinned']);
            if ($setId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_set'], 400);
            }
            setHashtagPostSetPinned($telegramId, $setId, $pinned);
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('hashtag_tools api failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
