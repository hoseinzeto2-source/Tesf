<?php

require_once __DIR__ . '/child_bots.php';
require_once __DIR__ . '/bot_folders.php';
require_once __DIR__ . '/bot_stats.php';
require_once __DIR__ . '/bot_health.php';
require_once __DIR__ . '/bot_profile.php';
require_once __DIR__ . '/channel_folders.php';
require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/auto_post.php';

/**
 * @return array<string, mixed>
 */
function buildMyBotsPayload(int $telegramId): array
{
    $bots = getChildBotsByOwner($telegramId, false);
    $folders = getBotFolders($telegramId);
    $bots = attachFolderIdsToBots($bots, $telegramId, $folders);

    $folderNameMap = [];
    foreach ($folders as $folder) {
        $folderNameMap[(int) ($folder['id'] ?? 0)] = (string) ($folder['name'] ?? '');
    }

    $botIds = array_values(array_filter(array_map(static fn (array $bot): int => (int) ($bot['id'] ?? 0), $bots)));
    $userCounts = [];
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
        } catch (Throwable $e) {
            error_log('buildMyBotsPayload stats skipped: ' . $e->getMessage());
        }
    }

    $safe = [];
    foreach ($bots as $bot) {
        $botId = (int) ($bot['id'] ?? 0);
        $health = childBotHealthPayload($bot);
        $photo = botPhotoPayloadFromFileId($botId, $bot['profile_photo_file_id'] ?? null);
        $folderId = $bot['folder_id'] ?? null;
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
            'user_growth_24h' => 0,
            'created_at' => $bot['created_at'],
            'folder_id' => $folderId,
            'folder_name' => $folderId ? ($folderNameMap[(int) $folderId] ?? null) : null,
            'link' => $bot['bot_username'] ? 'https://t.me/' . $bot['bot_username'] : null,
            'is_pinned' => (int) ($bot['is_pinned'] ?? 0) === 1,
            'pinned_at' => $bot['pinned_at'] ?? null,
        ];
    }

    $totalBotUsers = 0;
    foreach ($safe as $botRow) {
        if (!empty($botRow['is_banned'])) {
            continue;
        }
        $totalBotUsers += (int) ($botRow['user_count'] ?? 0);
    }

    return [
        'ok' => true,
        'total' => count($safe),
        'total_bot_users' => $totalBotUsers,
        'bots' => $safe,
        'folders' => $folders,
    ];
}

/**
 * @return array<string, mixed>
 */
function buildAutoPostListPayload(int $telegramId): array
{
    $sessions = getAutoPostSessions($telegramId);

    return [
        'ok' => true,
        'folders' => getAutoPostFolders($telegramId),
        'sessions' => $sessions,
        'allowed_channel_folder_ids' => getAutoPostAllowedChannelFolderIds(),
        'total' => count($sessions),
    ];
}

/**
 * @return array<string, mixed>
 */
function buildChannelsLitePayload(): array
{
    $channels = getActiveBotChannels(false, false, true);
    $memberTotal = 0;
    foreach ($channels as $channel) {
        $memberTotal += (int) ($channel['member_count'] ?? 0);
    }

    $folders = [];
    try {
        $folders = getChannelFolders();
    } catch (Throwable $e) {
        error_log('buildChannelsLitePayload folders: ' . $e->getMessage());
    }

    $filtered = array_values(array_filter(
        attachFolderIdsToChannels($channels),
        static fn (array $item): bool => in_array($item['type'] ?? '', ['channel', 'group', 'supergroup'], true)
    ));

    return [
        'ok' => true,
        'lite' => true,
        'total' => count($filtered),
        'channels' => $filtered,
        'folders' => $folders,
        'totals' => ['member_count' => $memberTotal, 'joins_1h' => 0, 'joins_12h' => 0, 'joins_24h' => 0],
        'dashboard' => ['channels' => [], 'totals' => ['member_count' => $memberTotal]],
    ];
}

/**
 * @return array<string, mixed>
 */
function buildMiniappBootstrapPayload(int $telegramId): array
{
    return [
        'ok' => true,
        'bots' => buildMyBotsPayload($telegramId),
        'auto_post' => buildAutoPostListPayload($telegramId),
        'channels' => buildChannelsLitePayload(),
    ];
}
