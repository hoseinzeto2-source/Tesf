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
 * Shared manage panel: if an admin has no owned bots/sessions, use the owner who does.
 * getChildBotsByOwner is strictly per owner_telegram_id, so admins who only manage
 * (e.g. 927959538) otherwise see total=0 while bots live under another admin/user.
 */
function resolveMiniappDataOwnerId(int $telegramId): int
{
    if ($telegramId <= 0) {
        return $telegramId;
    }

    ensureChildBotTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT COUNT(*) AS c FROM child_bots WHERE owner_telegram_id = ?');
    $stmt->bind_param('i', $telegramId);
    $stmt->execute();
    $ownedCount = (int) ($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    if ($ownedCount > 0) {
        return $telegramId;
    }

    global $admin_telegram_ids;
    $adminIds = array_map('intval', $admin_telegram_ids ?? []);
    if (!in_array($telegramId, $adminIds, true)) {
        return $telegramId;
    }

    $result = $db->query(
        'SELECT owner_telegram_id
         FROM child_bots
         GROUP BY owner_telegram_id
         ORDER BY COUNT(*) DESC, owner_telegram_id ASC
         LIMIT 1'
    );
    if ($result && ($row = $result->fetch_assoc())) {
        $ownerId = (int) ($row['owner_telegram_id'] ?? 0);
        if ($ownerId > 0) {
            return $ownerId;
        }
    }

    return $telegramId;
}

/**
 * @return array<string, mixed>
 */
function buildMyBotsPayload(int $telegramId): array
{
    $ownerId = resolveMiniappDataOwnerId($telegramId);
    $bots = getChildBotsByOwner($ownerId, false);
    $folders = getBotFolders($ownerId);
    $bots = attachFolderIdsToBots($bots, $ownerId, $folders);

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
        'viewer_telegram_id' => $telegramId,
        'data_owner_telegram_id' => $ownerId,
    ];
}

/**
 * @return array<string, mixed>
 */
function buildAutoPostListPayload(int $telegramId): array
{
    $ownerId = resolveMiniappDataOwnerId($telegramId);
    $sessions = getAutoPostSessions($ownerId);

    return [
        'ok' => true,
        'folders' => getAutoPostFolders($ownerId),
        'sessions' => $sessions,
        'allowed_channel_folder_ids' => getAutoPostAllowedChannelFolderIds(),
        'total' => count($sessions),
        'viewer_telegram_id' => $telegramId,
        'data_owner_telegram_id' => $ownerId,
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
    $payload = ['ok' => true];

    try {
        $payload['bots'] = buildMyBotsPayload($telegramId);
    } catch (Throwable $e) {
        error_log('bootstrap bots failed: ' . $e->getMessage());
        $payload['bots'] = ['ok' => false, 'total' => 0, 'total_bot_users' => 0, 'bots' => [], 'folders' => []];
    }

    try {
        $payload['auto_post'] = buildAutoPostListPayload($telegramId);
    } catch (Throwable $e) {
        error_log('bootstrap auto_post failed: ' . $e->getMessage());
        $payload['auto_post'] = [
            'ok' => false,
            'folders' => [],
            'sessions' => [],
            'allowed_channel_folder_ids' => [],
            'total' => 0,
        ];
    }

    try {
        $payload['channels'] = buildChannelsLitePayload();
    } catch (Throwable $e) {
        error_log('bootstrap channels failed: ' . $e->getMessage());
        $payload['channels'] = [
            'ok' => false,
            'lite' => true,
            'total' => 0,
            'channels' => [],
            'folders' => [],
            'totals' => ['member_count' => 0, 'joins_1h' => 0, 'joins_12h' => 0, 'joins_24h' => 0],
            'dashboard' => ['channels' => [], 'totals' => ['member_count' => 0]],
        ];
    }

    return $payload;
}
