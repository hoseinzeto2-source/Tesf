<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/child_bots.php';
require_once dirname(__DIR__, 2) . '/lib/bot_folders.php';
require_once dirname(__DIR__, 2) . '/lib/channel_folders.php';
require_once dirname(__DIR__, 2) . '/lib/uploader_versions.php';
require_once dirname(__DIR__, 2) . '/lib/bot_stats.php';
require_once dirname(__DIR__, 2) . '/lib/bot_health.php';
require_once dirname(__DIR__, 2) . '/lib/bot_profile.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

try {
    try {
        runOwnerChildBotHealthPass($telegramId, 2);
    } catch (Throwable $healthError) {
        error_log('my_bots health pass skipped: ' . $healthError->getMessage());
    }
    try {
        syncMissingChildBotProfilePhotos($telegramId, 2);
    } catch (Throwable $photoError) {
        error_log('my_bots profile sync skipped: ' . $photoError->getMessage());
    }
    $bots = getChildBotsByOwner($telegramId);
    $folders = getBotFolders($telegramId);
    $bots = attachFolderIdsToBots($bots, $telegramId, $folders);
    $channelFolders = [];
    try {
        $channelFolders = getChannelFolders();
    } catch (Throwable $e) {
        error_log('my_bots channel folders skipped: ' . $e->getMessage());
    }
    $channelFolderMap = [];
    foreach ($channelFolders as $folder) {
        $channelFolderMap[(int) $folder['id']] = $folder['name'];
    }
    $versionMap = [];
    try {
        foreach (listUploaderVersions(true) as $version) {
            $versionMap[(int) $version['id']] = $version['name'];
        }
    } catch (Throwable $e) {
        error_log('my_bots versions skipped: ' . $e->getMessage());
    }

    $safe = array_map(static function ($bot) use ($channelFolderMap, $versionMap) {
        $channelFolderId = isset($bot['channel_folder_id']) ? (int) $bot['channel_folder_id'] : null;
        $versionId = isset($bot['uploader_version_id']) ? (int) $bot['uploader_version_id'] : null;
        $botId = (int) $bot['id'];
        $userCount = countUploaderUsersForBot($botId);
        $userGrowth24h = 0;
        ensureBotStatsTables();
        $db = getDb();
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS cnt FROM uploader_users WHERE child_bot_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'
        );
        $stmt->bind_param('i', $botId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $userGrowth24h = (int) ($row['cnt'] ?? 0);

        $health = childBotHealthPayload($bot);
        $photo = botPhotoPayloadFromFileId($botId, $bot['profile_photo_file_id'] ?? null);

        return [
            'id' => $botId,
            'bot_username' => $bot['bot_username'],
            'bot_name' => $bot['bot_name'],
            'bot_type' => $bot['bot_type'],
            'status' => $bot['status'],
            'health_status' => $health['health_status'],
            'health_message' => $health['health_message'],
            'is_banned' => $health['is_banned'],
            'has_photo' => $photo['has_photo'],
            'photo_url' => $photo['photo_url'],
            'uploads_count' => (int) ($bot['uploads_count'] ?? 0),
            'user_count' => $userCount,
            'user_growth_24h' => $userGrowth24h,
            'created_at' => $bot['created_at'],
            'folder_id' => $bot['folder_id'] ?? null,
            'channel_folder_id' => $channelFolderId ?: null,
            'channel_folder_name' => $channelFolderId ? ($channelFolderMap[$channelFolderId] ?? null) : null,
            'uploader_version_id' => $versionId ?: null,
            'uploader_version_name' => $versionId ? ($versionMap[$versionId] ?? null) : null,
            'link' => $bot['bot_username'] ? 'https://t.me/' . $bot['bot_username'] : null,
            'is_pinned' => (int) ($bot['is_pinned'] ?? 0) === 1,
            'pinned_at' => $bot['pinned_at'] ?? null,
        ];
    }, $bots);

    $totalBotUsers = 0;
    foreach ($safe as $botRow) {
        if (!empty($botRow['is_banned'])) {
            continue;
        }
        $totalBotUsers += (int) ($botRow['user_count'] ?? 0);
    }

    jsonResponse([
        'ok' => true,
        'total' => count($safe),
        'total_bot_users' => $totalBotUsers,
        'bots' => $safe,
        'folders' => $folders,
    ]);
} catch (Throwable $e) {
    error_log('my_bots failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
