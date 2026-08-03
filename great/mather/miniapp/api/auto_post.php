<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/auto_post.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
    if ($sessionId > 0) {
        try {
            $stats = getAutoPostSessionStats($telegramId, $sessionId);
            if (!$stats) {
                jsonResponse(['ok' => false, 'error' => 'session_not_found'], 404);
            }
            jsonResponse(['ok' => true, ...$stats]);
        } catch (Throwable $e) {
            error_log('auto_post session stats failed: ' . $e->getMessage());
            jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
        }
    }

    jsonResponse([
        'ok' => true,
        'folders' => getAutoPostFolders($telegramId),
        'sessions' => getAutoPostSessions($telegramId),
        'allowed_channel_folder_ids' => getAutoPostAllowedChannelFolderIds(),
        'total' => count(getAutoPostSessions($telegramId)),
    ]);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'create_folder':
            $parentId = normalizeAutoPostFolderParentId($body['parent_id'] ?? null);
            $folder = createAutoPostFolder(
                $telegramId,
                (string) ($body['name'] ?? ''),
                (string) ($body['icon'] ?? 'folder'),
                $parentId
            );
            jsonResponse(['ok' => true, 'folder' => $folder]);
            break;

        case 'update_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            updateAutoPostFolder(
                $telegramId,
                $folderId,
                isset($body['name']) ? (string) $body['name'] : null,
                isset($body['icon']) ? (string) $body['icon'] : null
            );
            jsonResponse(['ok' => true]);
            break;

        case 'delete_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0 || !deleteAutoPostFolder($telegramId, $folderId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'move_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            $parentId = array_key_exists('parent_id', $body)
                ? normalizeAutoPostFolderParentId($body['parent_id'])
                : null;
            if (!moveAutoPostFolder($telegramId, $folderId, $parentId)) {
                jsonResponse(['ok' => false, 'error' => 'move_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'pin_folder':
            $folderId = (int) ($body['folder_id'] ?? 0);
            $pinned = !empty($body['pinned']);
            if ($folderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_folder'], 400);
            }
            setAutoPostFolderPinned($telegramId, $folderId, $pinned);
            jsonResponse(['ok' => true]);
            break;

        case 'create_session':
            $channelFolderId = (int) ($body['channel_folder_id'] ?? 0);
            $botFolderId = (int) ($body['bot_folder_id'] ?? $body['bot_channel_folder_id'] ?? 0);
            if ($channelFolderId <= 0 || $botFolderId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'folders_required'], 400);
            }
            $folderId = isset($body['folder_id']) ? (int) $body['folder_id'] : null;
            if ($folderId !== null && $folderId <= 0) {
                $folderId = null;
            }
            $session = createAutoPostSession(
                $telegramId,
                $channelFolderId,
                $botFolderId,
                isset($body['name']) ? (string) $body['name'] : null,
                $folderId
            );
            jsonResponse(['ok' => true, 'session' => $session]);
            break;

        case 'delete_session':
            $sessionId = (int) ($body['session_id'] ?? 0);
            if ($sessionId <= 0 || !deleteAutoPostSession($telegramId, $sessionId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'update_session':
            $sessionId = (int) ($body['session_id'] ?? 0);
            $name = trim((string) ($body['name'] ?? ''));
            if ($sessionId <= 0 || $name === '') {
                jsonResponse(['ok' => false, 'error' => 'invalid_session'], 400);
            }
            if (!updateAutoPostSession($telegramId, $sessionId, $name)) {
                jsonResponse(['ok' => false, 'error' => 'update_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'assign_session':
            $sessionId = (int) ($body['session_id'] ?? 0);
            if ($sessionId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_session'], 400);
            }
            $folderId = $body['folder_id'] ?? null;
            $folderId = $folderId === null || $folderId === '' ? null : (int) $folderId;
            assignAutoPostSessionToFolder($telegramId, $sessionId, $folderId);
            jsonResponse(['ok' => true]);
            break;

        case 'pin_session':
            $sessionId = (int) ($body['session_id'] ?? 0);
            $pinned = !empty($body['pinned']);
            if ($sessionId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_session'], 400);
            }
            setAutoPostSessionPinned($telegramId, $sessionId, $pinned);
            jsonResponse(['ok' => true]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('auto_post failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
