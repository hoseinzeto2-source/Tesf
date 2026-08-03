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
    $bots = getChildBotsByOwner($telegramId);
    $folders = getBotFolders($telegramId);
    $bots = attachFolderIdsToBots($bots, $telegramId, $folders);

    $channelFolderMap = [];
    try {
        foreach (getChannelFolders() as $folder) {
            $channelFolderMap[(int) $folder['id']] = $folder['name'];
        }
    } catch (Throwable $e) {
        error_log('my_bots channel folders skipped: ' . $e->getMessage());
    }

    $versionMap = [];
    try {
        foreach (listUploaderVersions(true) as $version) {
            $versionMap[(int) $version['id']] = $version['name'];
        }
    } catch (Throwable $e) {
        error_log('my_bots versions skipped: ' . $e->getMessage());
    }

    $botIds = array_values(array_filter(array_map(static fn(array $bot): int => (int) ($bot['id'] ?? 0), $bots)));
    $userCounts = [];
    $growthCounts = [];
    if ($botIds !== []) {
        try {
            ensureBotStatsTables();
            $db = getDb();
            $idList = implode(',', array_map('intval', $botIds));
            $countResult = $db->query(
                "SELECT child_bot_id, COUNT(*) AS cnt FROM uploader_users WHERE child_bot_id IN ({$idList}) GROUP BY child_bot_id"
            );
            if ($countResult) {
                while ($row = $countResult->fetch_assoc()) {
                    $userCounts[(int) $row['child_bot_id']] = (int) $row['cnt'];
                }
            }
            $growthResult = $db->query(
                "SELECT child_bot_id, COUNT(*) AS cnt
                 FROM uploader_users
                 WHERE child_bot_id IN ({$idList}) AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 GROUP BY child_bot_id"
            );
            if ($growthResult) {
                while ($row = $growthResult->fetch_assoc()) {
                    $growthCounts[(int) $row['child_bot_id']] = (int) $row['cnt'];
                }
            }
        } catch (Throwable $statsError) {
            error_log('my_bots stats batch skipped: ' . $statsError->getMessage());
        }
    }

    $safe = [];
    foreach ($bots as $bot) {
        try {
            $channelFolderId = isset($bot['channel_folder_id']) ? (int) $bot['channel_folder_id'] : null;
            $versionId = isset($bot['uploader_version_id']) ? (int) $bot['uploader_version_id'] : null;
            $botId = (int) $bot['id'];
            $health = childBotHealthPayload($bot);
            $photo = botPhotoPayloadFromFileId($botId, $bot['profile_photo_file_id'] ?? null);

            $safe[] = [
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
                'user_count' => $userCounts[$botId] ?? 0,
                'user_growth_24h' => $growthCounts[$botId] ?? 0,
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
        } catch (Throwable $botError) {
            error_log('my_bots row failed bot ' . ($bot['id'] ?? '?') . ': ' . $botError->getMessage());
        }
    }

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
