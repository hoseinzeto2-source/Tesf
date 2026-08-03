<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/invite_links.php';
require_once __DIR__ . '/channel_folders.php';

function ensureChannelStatsTables(): void
{
    $db = getDb();

    $tables = [
        <<<SQL
CREATE TABLE IF NOT EXISTS channel_member_snapshots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    member_count INT UNSIGNED NOT NULL DEFAULT 0,
    recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_recorded (chat_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS channel_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    message_id BIGINT NOT NULL,
    views INT UNSIGNED NOT NULL DEFAULT 0,
    forwards INT UNSIGNED NOT NULL DEFAULT 0,
    posted_at DATETIME DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chat_message (chat_id, message_id),
    KEY idx_chat_posted (chat_id, posted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
        <<<SQL
CREATE TABLE IF NOT EXISTS channel_join_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    user_id BIGINT DEFAULT NULL,
    source_type VARCHAR(40) NOT NULL DEFAULT 'unknown',
    source_label VARCHAR(255) DEFAULT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_chat_joined (chat_id, joined_at),
    KEY idx_chat_source (chat_id, source_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL,
    ];

    foreach ($tables as $sql) {
        $db->query($sql);
    }

    ensureBotChannelsColumns($db);
}

function ensureBotChannelsColumns(mysqli $db): void
{
    $columns = [
        'photo_file_id' => "VARCHAR(255) DEFAULT NULL",
        'invite_link' => "VARCHAR(512) DEFAULT NULL",
        'description' => "TEXT DEFAULT NULL",
    ];

    foreach ($columns as $name => $definition) {
        $result = $db->query("SHOW COLUMNS FROM bot_channels LIKE '{$name}'");
        if ($result && $result->num_rows === 0) {
            $db->query("ALTER TABLE bot_channels ADD COLUMN {$name} {$definition}");
        }
    }
}

function ensureChannelInviteLink(int $chatId, ?string $storedLink = null): ?string
{
    if ($storedLink) {
        return $storedLink;
    }

    $chatInfo = telegramRequest('getChat', ['chat_id' => $chatId]);
    $inviteLink = $chatInfo['result']['invite_link'] ?? null;
    if ($inviteLink) {
        return $inviteLink;
    }

    $created = telegramRequest('createChatInviteLink', [
        'chat_id' => $chatId,
        'name' => 'Manage Bot',
    ]);
    $inviteLink = $created['result']['invite_link'] ?? null;
    if ($inviteLink) {
        return $inviteLink;
    }

    $exported = telegramRequest('exportChatInviteLink', ['chat_id' => $chatId]);
    return $exported['result'] ?? null;
}

function refreshChannelMetadata(int $chatId): ?array
{
    ensureChannelStatsTables();

    $chatInfo = telegramRequest('getChat', ['chat_id' => $chatId]);
    if (empty($chatInfo['ok']) || empty($chatInfo['result'])) {
        return null;
    }

    $chat = $chatInfo['result'];
    $countResp = telegramRequest('getChatMemberCount', ['chat_id' => $chatId]);
    $memberCount = !empty($countResp['ok']) ? (int) $countResp['result'] : 0;

    $photoFileId = $chat['photo']['big_file_id'] ?? $chat['photo']['small_file_id'] ?? null;
    $username = $chat['username'] ?? null;
    $inviteLink = null;
    if (empty($username)) {
        $db = getDb();
        $stmt = $db->prepare('SELECT invite_link FROM bot_channels WHERE chat_id = ? LIMIT 1');
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $inviteLink = ensureChannelInviteLink($chatId, $row['invite_link'] ?? null);
    } else {
        $inviteLink = 'https://t.me/' . $username;
    }

    $db = getDb();
    $title = $chat['title'] ?? null;
    $description = $chat['description'] ?? null;
    $chatType = $chat['type'] ?? 'channel';

    $stmt = $db->prepare(
        'UPDATE bot_channels SET title = ?, username = ?, member_count = ?, photo_file_id = ?, invite_link = ?, description = ?, updated_at = NOW() WHERE chat_id = ?'
    );
    $stmt->bind_param('ssisssi', $title, $username, $memberCount, $photoFileId, $inviteLink, $description, $chatId);
    $stmt->execute();
    $stmt->close();

    if ($memberCount !== null) {
        recordMemberSnapshot($chatId, $memberCount, false);
    }

    return [
        'chat_id' => $chatId,
        'title' => $title,
        'username' => $username,
        'member_count' => $memberCount,
        'photo_file_id' => $photoFileId,
        'invite_link' => $inviteLink,
        'description' => $description,
        'type' => $chatType,
    ];
}

function recordMemberSnapshot(int $chatId, ?int $memberCount = null, bool $forceHourly = true): void
{
    ensureChannelStatsTables();

    if ($memberCount === null) {
        $countResp = telegramRequest('getChatMemberCount', ['chat_id' => $chatId]);
        if (empty($countResp['ok'])) {
            return;
        }
        $memberCount = (int) $countResp['result'];
    }

    $db = getDb();
    if ($forceHourly) {
        $stmt = $db->prepare(
            'SELECT member_count, recorded_at FROM channel_member_snapshots WHERE chat_id = ? ORDER BY recorded_at DESC LIMIT 1'
        );
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $last = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($last) {
            $lastTime = strtotime((string) $last['recorded_at']);
            $sameCount = (int) $last['member_count'] === $memberCount;
            if ($sameCount && $lastTime > time() - 3600) {
                return;
            }
            if (!$sameCount && $lastTime > time() - 300) {
                // allow quicker updates when count changes
            } elseif ($sameCount && $lastTime > time() - 3600) {
                return;
            }
        }
    }

    $stmt = $db->prepare('INSERT INTO channel_member_snapshots (chat_id, member_count) VALUES (?, ?)');
    $stmt->bind_param('ii', $chatId, $memberCount);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE bot_channels SET member_count = ? WHERE chat_id = ?');
    $stmt->bind_param('ii', $memberCount, $chatId);
    $stmt->execute();
    $stmt->close();
}

function upsertChannelPost(int $chatId, int $messageId, int $views, int $forwards, ?int $postedAt = null): void
{
    ensureChannelStatsTables();

    $postedAtSql = $postedAt ? date('Y-m-d H:i:s', $postedAt) : date('Y-m-d H:i:s');
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO channel_posts (chat_id, message_id, views, forwards, posted_at)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE views = VALUES(views), forwards = VALUES(forwards), updated_at = NOW()'
    );
    $stmt->bind_param('iiiis', $chatId, $messageId, $views, $forwards, $postedAtSql);
    $stmt->execute();
    $stmt->close();
}

function recordChannelJoinEvent(int $chatId, ?int $userId, array $chatMemberUpdate): void
{
    ensureChannelStatsTables();

    $sourceType = 'other';
    $sourceLabel = 'سایر / نامشخص';

    if (!empty($chatMemberUpdate['invite_link'])) {
        $invite = $chatMemberUpdate['invite_link'];
        $sourceType = 'invite_link';
        $sourceLabel = $invite['name'] ?? 'لینک دعوت';
    } elseif (!empty($chatMemberUpdate['via_chat_folder_invite_link'])) {
        $sourceType = 'folder';
        $sourceLabel = 'پوشه کانال';
    } elseif (!empty($chatMemberUpdate['via_join_request'])) {
        $sourceType = 'join_request';
        $sourceLabel = 'درخواست عضویت';
    }

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO channel_join_events (chat_id, user_id, source_type, source_label) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('iiss', $chatId, $userId, $sourceType, $sourceLabel);
    $stmt->execute();
    $stmt->close();
}

function handleChannelPostUpdate(array $post): void
{
    $chat = $post['chat'] ?? [];
    $chatId = (int) ($chat['id'] ?? 0);
    if ($chatId === 0) {
        return;
    }

    syncBotChannelFromChat($chat);
    refreshChannelMetadata($chatId);

    $messageId = (int) ($post['message_id'] ?? 0);
    if ($messageId === 0) {
        return;
    }

    upsertChannelPost(
        $chatId,
        $messageId,
        (int) ($post['views'] ?? 0),
        (int) ($post['forward_count'] ?? 0),
        (int) ($post['date'] ?? 0) ?: null
    );
}

function handleChatMemberUpdate(array $update): void
{
    $payload = $update['chat_member'] ?? [];
    $chat = $payload['chat'] ?? [];
    $chatType = (string) ($chat['type'] ?? '');
    if (!in_array($chatType, ['channel', 'supergroup'], true)) {
        return;
    }

    $chatId = (int) ($chat['id'] ?? 0);
    if ($chatId === 0) {
        return;
    }

    $oldStatus = (string) ($payload['old_chat_member']['status'] ?? '');
    $newStatus = (string) ($payload['new_chat_member']['status'] ?? '');
    $userId = (int) ($payload['new_chat_member']['user']['id'] ?? 0);

    if (in_array($newStatus, ['member', 'administrator', 'restricted'], true)
        && in_array($oldStatus, ['left', 'kicked', ''], true)) {
        recordChannelJoinEvent($chatId, $userId ?: null, $payload);
    }

    handleInviteLinkChatMember($payload);

    recordMemberSnapshot($chatId);
}

function getChannelPhotoFilePath(?string $fileId): ?string
{
    if (!$fileId) {
        return null;
    }

    $file = telegramRequest('getFile', ['file_id' => $fileId]);
    return $file['result']['file_path'] ?? null;
}

function getChannelStats(int $chatId): ?array
{
    ensureChannelStatsTables();

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM bot_channels WHERE chat_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $channel = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$channel) {
        return null;
    }

    try {
        if ((int) ($channel['is_active'] ?? 1) === 1) {
            refreshChannelMetadata($chatId);
        }
    } catch (Throwable $e) {
        error_log('refreshChannelMetadata failed: ' . $e->getMessage());
    }

    $stmt = $db->prepare('SELECT * FROM bot_channels WHERE chat_id = ? LIMIT 1');
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $channel = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $memberHistory = [];
    $stmt = $db->prepare(
        'SELECT member_count, recorded_at FROM channel_member_snapshots WHERE chat_id = ? ORDER BY recorded_at ASC LIMIT 90'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $memberHistory[] = [
            'count' => (int) $row['member_count'],
            'date' => $row['recorded_at'],
        ];
    }
    $stmt->close();

    $posts = [];
    $stmt = $db->prepare(
        'SELECT message_id, views, forwards, posted_at, updated_at FROM channel_posts WHERE chat_id = ? ORDER BY COALESCE(posted_at, updated_at) DESC LIMIT 20'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    $totalViews = 0;
    $postCount = 0;
    while ($row = $result->fetch_assoc()) {
        $views = (int) $row['views'];
        $totalViews += $views;
        $postCount++;
        $posts[] = [
            'message_id' => (int) $row['message_id'],
            'views' => $views,
            'forwards' => (int) $row['forwards'],
            'posted_at' => $row['posted_at'],
        ];
    }
    $stmt->close();

    $avgViews = $postCount > 0 ? (int) round($totalViews / $postCount) : 0;

    $sources = [];
    $stmt = $db->prepare(
        'SELECT source_type, source_label, COUNT(*) AS total FROM channel_join_events WHERE chat_id = ? GROUP BY source_type, source_label ORDER BY total DESC'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $sources[] = [
            'type' => $row['source_type'],
            'label' => mapJoinSourceLabel($row['source_type'], $row['source_label']),
            'count' => (int) $row['total'],
        ];
    }
    $stmt->close();

    $growth = 0;
    if (count($memberHistory) >= 2) {
        $first = $memberHistory[0]['count'];
        $last = $memberHistory[count($memberHistory) - 1]['count'];
        $growth = $last - $first;
    }

    $username = $channel['username'] ?? null;
    $inviteLink = $channel['invite_link'] ?? null;
    $publicLink = $username ? 'https://t.me/' . $username : $inviteLink;
    $health = channelHealthPayload($channel);

    $joinsHourly = getChannelJoinsHourly24($chatId);
    $membersHourly = getChannelMembersHourly24($chatId);
    $recentJoins = getChannelRecentJoinCounts($chatId);

    return [
        'channel' => [
            'chat_id' => (int) $channel['chat_id'],
            'title' => $channel['title'] ?: 'بدون نام',
            'username' => $username,
            'type' => $channel['chat_type'],
            'member_count' => $channel['member_count'] !== null ? (int) $channel['member_count'] : null,
            'invite_link' => $inviteLink,
            'public_link' => $publicLink,
            'is_private' => empty($username),
            'photo_url' => 'api/channel_photo.php?chat_id=' . $chatId,
            'description' => $channel['description'] ?? null,
            'is_active' => $health['is_active'],
            'health_status' => $health['health_status'],
            'health_message' => $health['health_message'],
            'is_banned' => $health['is_banned'],
        ],
        'summary' => [
            'member_count' => $channel['member_count'] !== null ? (int) $channel['member_count'] : null,
            'member_growth' => $growth,
            'avg_post_views' => $avgViews,
            'tracked_posts' => $postCount,
            'snapshots' => count($memberHistory),
            'joins_1h' => $recentJoins['joins_1h'],
            'joins_12h' => $recentJoins['joins_12h'],
            'joins_24h' => $recentJoins['joins_24h'],
        ],
        'member_history' => $memberHistory,
        'joins_hourly' => $joinsHourly['points'],
        'joins_range' => $joinsHourly['range'],
        'members_hourly' => $membersHourly['points'],
        'members_range' => $membersHourly['range'],
        'recent_posts' => array_reverse($posts),
        'viewer_sources' => $sources,
    ];
}

function mapJoinSourceLabel(string $type, ?string $label): string
{
    $map = [
        'invite_link' => 'لینک دعوت',
        'folder' => 'پوشه کانال',
        'join_request' => 'درخواست عضویت',
        'other' => 'سایر',
    ];

    if ($type === 'invite_link' && $label) {
        return 'لینک: ' . $label;
    }

    return $map[$type] ?? ($label ?: 'سایر');
}

/**
 * Hourly join counts for all channels (last 24 complete hours).
 *
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedJoinsHourly24(): array
{
    ensureChannelStatsTables();
    $db = getDb();

    $points = [];
    for ($i = 23; $i >= 0; $i--) {
        $key = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
        $points[$key] = 0;
    }

    $mergeHourly = function ($result) use (&$points): void {
        if (!$result instanceof mysqli_result) {
            return;
        }
        while ($row = $result->fetch_assoc()) {
            $bucket = $row['hour_bucket'] ?? '';
            if ($bucket !== '' && isset($points[$bucket])) {
                $points[$bucket] += (int) ($row['cnt'] ?? 0);
            }
        }
    };

    $mergeHourly($db->query(
        "SELECT DATE_FORMAT(joined_at, '%Y-%m-%d %H:00:00') AS hour_bucket, COUNT(*) AS cnt
         FROM channel_join_events
         WHERE joined_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY hour_bucket"
    ));

    $list = [];
    foreach ($points as $hour => $count) {
        $list[] = ['hour' => $hour, 'count' => $count];
    }

    $from = $list[0]['hour'] ?? date('Y-m-d H:00:00', strtotime('-23 hours'));
    $to = $list[count($list) - 1]['hour'] ?? date('Y-m-d H:00:00');

    return [
        'points' => $list,
        'range' => ['from' => $from, 'to' => $to],
    ];
}

/**
 * Total members across all bot channels per hour (last 24 hours).
 *
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedMembersHourly24(): array
{
    ensureChannelStatsTables();

    $channels = getActiveBotChannels(false, false);
    $chatIds = [];
    foreach ($channels as $channel) {
        $chatId = (int) ($channel['chat_id'] ?? 0);
        if ($chatId !== 0) {
            $chatIds[] = $chatId;
        }
    }

    return getAggregatedMembersHourly24ForChatIds($chatIds);
}

/**
 * @param list<int> $chatIds
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedMembersHourly24ForChatIds(array $chatIds): array
{
    ensureChannelStatsTables();

    $chatIds = array_values(array_unique(array_map('intval', $chatIds)));
    $chatIds = array_filter($chatIds, static fn (int $id): bool => $id !== 0);

    $hourKeys = [];
    for ($i = 23; $i >= 0; $i--) {
        $hourKeys[] = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
    }

    if ($chatIds === []) {
        $empty = array_map(static fn (string $hour): array => ['hour' => $hour, 'count' => 0], $hourKeys);

        return [
            'points' => $empty,
            'range' => ['from' => $hourKeys[0], 'to' => $hourKeys[count($hourKeys) - 1]],
        ];
    }

    $currentCounts = [];
    $db = getDb();
    $idList = implode(',', $chatIds);
    $channelRows = $db->query("SELECT chat_id, member_count FROM bot_channels WHERE chat_id IN ({$idList}) AND is_active = 1");
    if ($channelRows) {
        while ($row = $channelRows->fetch_assoc()) {
            $currentCounts[(int) $row['chat_id']] = $row['member_count'] !== null ? (int) $row['member_count'] : 0;
        }
    }
    foreach ($chatIds as $chatId) {
        if (!isset($currentCounts[$chatId])) {
            $currentCounts[$chatId] = 0;
        }
    }

    $result = $db->query(
        "SELECT chat_id, member_count, recorded_at
         FROM channel_member_snapshots
         WHERE chat_id IN ({$idList}) AND recorded_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
         ORDER BY recorded_at ASC"
    );

    $eventsByChat = [];
    foreach ($chatIds as $chatId) {
        $eventsByChat[$chatId] = [];
    }

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $chatId = (int) $row['chat_id'];
            if (!isset($eventsByChat[$chatId])) {
                continue;
            }
            $eventsByChat[$chatId][] = [
                'ts' => strtotime((string) $row['recorded_at']),
                'count' => (int) $row['member_count'],
            ];
        }
    }

    $firstHourTs = strtotime($hourKeys[0]);
    $state = [];
    foreach ($chatIds as $chatId) {
        $state[$chatId] = $currentCounts[$chatId];
        foreach ($eventsByChat[$chatId] as $event) {
            if ($event['ts'] < $firstHourTs) {
                $state[$chatId] = $event['count'];
            }
        }
    }

    $indexes = array_fill_keys($chatIds, 0);
    $points = [];
    $lastHourKey = $hourKeys[count($hourKeys) - 1];

    foreach ($hourKeys as $hour) {
        $deadline = strtotime($hour) + 3600 - 1;

        foreach ($chatIds as $chatId) {
            $events = $eventsByChat[$chatId];
            $index = $indexes[$chatId];
            while ($index < count($events) && $events[$index]['ts'] <= $deadline) {
                $state[$chatId] = $events[$index]['count'];
                $index++;
            }
            $indexes[$chatId] = $index;
        }

        if ($hour === $lastHourKey) {
            $total = 0;
            foreach ($chatIds as $chatId) {
                $total += $currentCounts[$chatId];
            }
        } else {
            $total = 0;
            foreach ($chatIds as $chatId) {
                $total += $state[$chatId];
            }
        }

        $points[] = ['hour' => $hour, 'count' => $total];
    }

    return [
        'points' => $points,
        'range' => ['from' => $hourKeys[0], 'to' => $lastHourKey],
    ];
}

/**
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getFolderMembersHourly24(int $folderId): array
{
    ensureChannelFolderTables();
    $assignments = getChannelFolderAssignments();
    $chatIds = [];
    foreach ($assignments as $chatId => $assignedFolderId) {
        if ((int) $assignedFolderId === $folderId) {
            $chatIds[] = (int) $chatId;
        }
    }

    return getAggregatedMembersHourly24ForChatIds($chatIds);
}

/**
 * Hourly join counts for one channel (last 24 hours).
 *
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getChannelJoinsHourly24(int $chatId): array
{
    ensureChannelStatsTables();
    $db = getDb();

    $points = [];
    for ($i = 23; $i >= 0; $i--) {
        $key = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
        $points[$key] = 0;
    }

    $stmt = $db->prepare(
        "SELECT DATE_FORMAT(joined_at, '%Y-%m-%d %H:00:00') AS hour_bucket, COUNT(*) AS cnt
         FROM channel_join_events
         WHERE chat_id = ? AND joined_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY hour_bucket"
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $bucket = $row['hour_bucket'] ?? '';
        if ($bucket !== '' && isset($points[$bucket])) {
            $points[$bucket] = (int) ($row['cnt'] ?? 0);
        }
    }
    $stmt->close();

    $list = [];
    foreach ($points as $hour => $count) {
        $list[] = ['hour' => $hour, 'count' => $count];
    }

    $from = $list[0]['hour'] ?? date('Y-m-d H:00:00', strtotime('-23 hours'));
    $to = $list[count($list) - 1]['hour'] ?? date('Y-m-d H:00:00');

    return [
        'points' => $list,
        'range' => ['from' => $from, 'to' => $to],
    ];
}

/**
 * Member count trend for one channel (hourly, last 24 hours).
 *
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getChannelMembersHourly24(int $chatId): array
{
    ensureChannelStatsTables();
    $db = getDb();

    $currentCount = 0;
    $stmt = $db->prepare('SELECT member_count FROM bot_channels WHERE chat_id = ? LIMIT 1');
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && $row['member_count'] !== null) {
        $currentCount = (int) $row['member_count'];
    }

    $hourKeys = [];
    for ($i = 23; $i >= 0; $i--) {
        $hourKeys[] = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
    }

    $events = [];
    $stmt = $db->prepare(
        'SELECT member_count, recorded_at FROM channel_member_snapshots
         WHERE chat_id = ? AND recorded_at >= DATE_SUB(NOW(), INTERVAL 72 HOUR)
         ORDER BY recorded_at ASC'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($snap = $result->fetch_assoc()) {
        $events[] = [
            'ts' => strtotime((string) $snap['recorded_at']),
            'count' => (int) $snap['member_count'],
        ];
    }
    $stmt->close();

    $firstHourTs = strtotime($hourKeys[0]);
    $state = $currentCount;
    foreach ($events as $event) {
        if ($event['ts'] < $firstHourTs) {
            $state = $event['count'];
        }
    }

    $index = 0;
    $points = [];
    $lastHourKey = $hourKeys[count($hourKeys) - 1];

    foreach ($hourKeys as $hour) {
        $deadline = strtotime($hour) + 3600 - 1;
        while ($index < count($events) && $events[$index]['ts'] <= $deadline) {
            $state = $events[$index]['count'];
            $index++;
        }
        $count = $hour === $lastHourKey ? $currentCount : $state;
        $points[] = ['hour' => $hour, 'count' => $count];
    }

    return [
        'points' => $points,
        'range' => ['from' => $hourKeys[0], 'to' => $lastHourKey],
    ];
}

/**
 * @return array{joins_1h: int, joins_12h: int, joins_24h: int}
 */
function getChannelRecentJoinCounts(int $chatId): array
{
    ensureChannelStatsTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT
            SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS joins_1h,
            SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) THEN 1 ELSE 0 END) AS joins_12h,
            SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS joins_24h
         FROM channel_join_events
         WHERE chat_id = ? AND joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return [
        'joins_1h' => (int) ($row['joins_1h'] ?? 0),
        'joins_12h' => (int) ($row['joins_12h'] ?? 0),
        'joins_24h' => (int) ($row['joins_24h'] ?? 0),
    ];
}

/**
 * @param list<array<string, mixed>> $channels
 * @return array{channels: list<array<string, mixed>>, totals: array<string, int|null>}
 */
function attachChannelRecentJoinStats(array $channels): array
{
    ensureChannelStatsTables();

    $statsByChat = [];
    $db = getDb();

    $result = $db->query(
        'SELECT chat_id,
            SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS joins_1h,
            SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) THEN 1 ELSE 0 END) AS joins_12h,
            SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS joins_24h
         FROM channel_join_events
         WHERE joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
         GROUP BY chat_id'
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $chatId = (int) $row['chat_id'];
            $statsByChat[$chatId] = [
                'joins_1h' => (int) $row['joins_1h'],
                'joins_12h' => (int) $row['joins_12h'],
                'joins_24h' => (int) $row['joins_24h'],
            ];
        }
    }

    $inviteTable = $db->query("SHOW TABLES LIKE 'channel_invite_users'");
    if ($inviteTable && $inviteTable->num_rows > 0) {
        $inviteResult = $db->query(
            'SELECT chat_id,
                SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS joins_1h,
                SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 12 HOUR) THEN 1 ELSE 0 END) AS joins_12h,
                SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS joins_24h
             FROM channel_invite_users
             WHERE joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
             GROUP BY chat_id'
        );
        if ($inviteResult) {
            while ($row = $inviteResult->fetch_assoc()) {
                $chatId = (int) $row['chat_id'];
                $inviteStats = [
                    'joins_1h' => (int) $row['joins_1h'],
                    'joins_12h' => (int) $row['joins_12h'],
                    'joins_24h' => (int) $row['joins_24h'],
                ];
                if (!isset($statsByChat[$chatId])) {
                    $statsByChat[$chatId] = $inviteStats;
                    continue;
                }
                foreach (['joins_1h', 'joins_12h', 'joins_24h'] as $key) {
                    $statsByChat[$chatId][$key] = max($statsByChat[$chatId][$key], $inviteStats[$key]);
                }
            }
        }
    }

    $totals = [
        'member_count' => 0,
        'joins_1h' => 0,
        'joins_12h' => 0,
        'joins_24h' => 0,
    ];

    $enriched = [];
    foreach ($channels as $channel) {
        $chatId = (int) ($channel['chat_id'] ?? 0);
        $joins = $statsByChat[$chatId] ?? [
            'joins_1h' => 0,
            'joins_12h' => 0,
            'joins_24h' => 0,
        ];

        $members = $channel['member_count'] ?? null;
        if ($members !== null) {
            $totals['member_count'] += (int) $members;
        }
        $totals['joins_1h'] += $joins['joins_1h'];
        $totals['joins_12h'] += $joins['joins_12h'];
        $totals['joins_24h'] += $joins['joins_24h'];

        $channel['recent_joins'] = $joins;
        $enriched[] = $channel;
    }

    $hourly = getAggregatedJoinsHourly24();
    $membersHourly = getAggregatedMembersHourly24();

    return [
        'channels' => $enriched,
        'totals' => $totals,
        'joins_hourly' => $hourly['points'],
        'joins_range' => $hourly['range'],
        'members_hourly' => $membersHourly['points'],
        'members_range' => $membersHourly['range'],
    ];
}

/**
 * Channels counted on dashboard: must be inside a folder, excluding the ads promo folder.
 *
 * @param list<array<string, mixed>> $channels
 * @return list<array<string, mixed>>
 */
function filterChannelsForDashboard(array $channels, ?int $promoFolderId = null): array
{
    if ($promoFolderId === null) {
        require_once __DIR__ . '/ad_campaigns.php';
        $promoFolderId = (int) (getOrCreateAdsPromoFolder()['id'] ?? 0);
    }

    return array_values(array_filter($channels, static function (array $channel) use ($promoFolderId): bool {
        $folderId = $channel['folder_id'] ?? null;
        if ($folderId === null || $folderId === '') {
            return false;
        }

        return (int) $folderId !== $promoFolderId;
    }));
}

/**
 * @param list<array<string, mixed>> $channels
 * @return array{member_count: int, joins_1h: int, joins_12h: int, joins_24h: int}
 */
function computeChannelTotalsFromList(array $channels): array
{
    $totals = [
        'member_count' => 0,
        'joins_1h' => 0,
        'joins_12h' => 0,
        'joins_24h' => 0,
    ];

    foreach ($channels as $channel) {
        $members = $channel['member_count'] ?? null;
        if ($members !== null) {
            $totals['member_count'] += (int) $members;
        }

        $joins = $channel['recent_joins'] ?? [];
        $totals['joins_1h'] += (int) ($joins['joins_1h'] ?? 0);
        $totals['joins_12h'] += (int) ($joins['joins_12h'] ?? 0);
        $totals['joins_24h'] += (int) ($joins['joins_24h'] ?? 0);
    }

    return $totals;
}

/**
 * @param list<int> $chatIds
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedJoinsHourly24ForChatIds(array $chatIds): array
{
    ensureChannelStatsTables();

    $chatIds = array_values(array_unique(array_filter(array_map('intval', $chatIds), static fn (int $id): bool => $id !== 0)));
    $points = [];
    for ($i = 23; $i >= 0; $i--) {
        $key = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
        $points[$key] = 0;
    }

    if ($chatIds === []) {
        $list = [];
        foreach ($points as $hour => $count) {
            $list[] = ['hour' => $hour, 'count' => $count];
        }

        return [
            'points' => $list,
            'range' => [
                'from' => $list[0]['hour'] ?? date('Y-m-d H:00:00', strtotime('-23 hours')),
                'to' => $list[count($list) - 1]['hour'] ?? date('Y-m-d H:00:00'),
            ],
        ];
    }

    $db = getDb();
    $in = implode(',', $chatIds);
    $result = $db->query(
        "SELECT DATE_FORMAT(joined_at, '%Y-%m-%d %H:00:00') AS hour_bucket, COUNT(*) AS cnt
         FROM channel_join_events
         WHERE chat_id IN ({$in}) AND joined_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
         GROUP BY hour_bucket"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $bucket = $row['hour_bucket'] ?? '';
            if ($bucket !== '' && isset($points[$bucket])) {
                $points[$bucket] += (int) ($row['cnt'] ?? 0);
            }
        }
    }

    $inviteTable = $db->query("SHOW TABLES LIKE 'channel_invite_users'");
    if ($inviteTable && $inviteTable->num_rows > 0) {
        $inviteResult = $db->query(
            "SELECT DATE_FORMAT(joined_at, '%Y-%m-%d %H:00:00') AS hour_bucket, COUNT(*) AS cnt
             FROM channel_invite_users
             WHERE chat_id IN ({$in}) AND joined_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY hour_bucket"
        );
        if ($inviteResult) {
            while ($row = $inviteResult->fetch_assoc()) {
                $bucket = $row['hour_bucket'] ?? '';
                if ($bucket !== '' && isset($points[$bucket])) {
                    $points[$bucket] += (int) ($row['cnt'] ?? 0);
                }
            }
        }
    }

    $list = [];
    foreach ($points as $hour => $count) {
        $list[] = ['hour' => $hour, 'count' => $count];
    }

    return [
        'points' => $list,
        'range' => [
            'from' => $list[0]['hour'] ?? date('Y-m-d H:00:00', strtotime('-23 hours')),
            'to' => $list[count($list) - 1]['hour'] ?? date('Y-m-d H:00:00'),
        ],
    ];
}

/**
 * Dashboard stats exclude unfoldered channels and the ads promo folder.
 *
 * @param list<array<string, mixed>> $channels
 * @return array<string, mixed>
 */
function buildDashboardChannelOverview(array $channels, ?int $promoFolderId = null): array
{
    if ($promoFolderId === null) {
        require_once __DIR__ . '/ad_campaigns.php';
        $promoFolderId = (int) (getOrCreateAdsPromoFolder()['id'] ?? 0);
    }

    $eligible = filterChannelsForDashboard($channels, $promoFolderId);
    $chatIds = [];
    foreach ($eligible as $channel) {
        $chatId = (int) ($channel['chat_id'] ?? 0);
        if ($chatId !== 0) {
            $chatIds[] = $chatId;
        }
    }

    $totals = computeChannelTotalsFromList($eligible);
    $joinsHourly = getAggregatedJoinsHourly24ForChatIds($chatIds);
    $membersHourly = getAggregatedMembersHourly24ForChatIds($chatIds);

    return [
        'promo_folder_id' => $promoFolderId,
        'channels' => $eligible,
        'channel_count' => count($eligible),
        'totals' => $totals,
        'joins_hourly' => $joinsHourly['points'],
        'joins_range' => $joinsHourly['range'],
        'members_hourly' => $membersHourly['points'],
        'members_range' => $membersHourly['range'],
    ];
}
