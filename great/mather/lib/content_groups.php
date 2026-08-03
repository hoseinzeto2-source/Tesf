<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/explorer_pins.php';

function isContentGroupChatType(string $type): bool
{
    return in_array($type, ['group', 'supergroup'], true);
}

function normalizeGroupBioMarker(?string $value): string
{
    return trim((string) $value);
}

function getGroupBioCategory(?string $description): string
{
    $bio = normalizeGroupBioMarker($description);
    if ($bio === '01') {
        return 'content';
    }
    if ($bio === '02') {
        return 'banner';
    }

    return 'general';
}

function isContentPostBioGroup(?string $description): bool
{
    return getGroupBioCategory($description) === 'content';
}

function isBannerBioGroup(?string $description): bool
{
    return getGroupBioCategory($description) === 'banner';
}

function isGeneralBioGroup(?string $description): bool
{
    return getGroupBioCategory($description) === 'general';
}

function syncContentGroupDescription(int $chatId): ?string
{
    ensureContentGroupDescriptionColumn();
    $chatInfo = telegramRequest('getChat', ['chat_id' => $chatId]);
    if (empty($chatInfo['ok']) || empty($chatInfo['result'])) {
        return null;
    }

    $description = normalizeGroupBioMarker((string) ($chatInfo['result']['description'] ?? ''));
    $db = getDb();
    $stmt = $db->prepare('UPDATE content_groups SET chat_description = NULLIF(?, \'\'), updated_at = NOW() WHERE chat_id = ?');
    $stmt->bind_param('si', $description, $chatId);
    $stmt->execute();
    $stmt->close();

    return $description !== '' ? $description : null;
}

function syncAllContentGroupDescriptions(): int
{
    ensureContentGroupDescriptionColumn();
    $db = getDb();
    $result = $db->query('SELECT chat_id FROM content_groups WHERE is_active = 1');
    if (!$result) {
        return 0;
    }

    $synced = 0;
    while ($row = $result->fetch_assoc()) {
        if (syncContentGroupDescription((int) $row['chat_id']) !== null) {
            $synced++;
        }
    }

    return $synced;
}

function routeContentGroupByBio(int $chatId, array $chat, string $botStatus, ?string $description): void
{
    ensureBotChannelsTable();
    $category = getGroupBioCategory($description);

    if ($category === 'general') {
        upsertBotChannel($chat, $botStatus);

        return;
    }

    $db = getDb();
    $stmt = $db->prepare('DELETE FROM bot_channels WHERE chat_id = ?');
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $stmt->close();
}

function reconcileAllContentGroupPlacements(int $limit = 80): int
{
    ensureContentGroupTables();
    ensureContentGroupDescriptionColumn();
    syncAllContentGroupDescriptions();

    $db = getDb();
    $result = $db->query(
        'SELECT chat_id, chat_type, title, username, bot_status, chat_description
         FROM content_groups
         WHERE is_active = 1
         ORDER BY updated_at DESC
         LIMIT ' . max(1, (int) $limit)
    );
    if (!$result) {
        return 0;
    }

    $count = 0;
    while ($row = $result->fetch_assoc()) {
        $chatId = (int) $row['chat_id'];
        routeContentGroupByBio(
            $chatId,
            [
                'id' => $chatId,
                'type' => (string) ($row['chat_type'] ?? 'supergroup'),
                'title' => $row['title'] ?? null,
                'username' => $row['username'] ?? null,
            ],
            (string) ($row['bot_status'] ?? 'administrator'),
            $row['chat_description'] ?? null
        );
        $count++;
    }

    return $count;
}

function ensureContentGroupTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS content_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    chat_type VARCHAR(20) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    username VARCHAR(255) DEFAULT NULL,
    member_count INT UNSIGNED DEFAULT NULL,
    bot_status VARCHAR(30) NOT NULL DEFAULT 'administrator',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    is_private TINYINT(1) NOT NULL DEFAULT 1,
    photo_file_id VARCHAR(255) DEFAULT NULL,
    health_status VARCHAR(32) NOT NULL DEFAULT 'ok',
    health_message VARCHAR(255) DEFAULT NULL,
    deactivated_reason VARCHAR(64) NULL DEFAULT NULL,
    deactivated_at DATETIME NULL DEFAULT NULL,
    last_health_check DATETIME NULL DEFAULT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chat_id (chat_id),
    KEY idx_active_type (is_active, chat_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureExplorerPinColumns('content_groups');
    ensureContentGroupDescriptionColumn();
}

function ensureContentGroupDescriptionColumn(): void
{
    $db = getDb();
    $check = $db->query("SHOW COLUMNS FROM content_groups LIKE 'chat_description'");
    if ($check && $check->num_rows === 0) {
        $db->query('ALTER TABLE content_groups ADD COLUMN chat_description VARCHAR(255) DEFAULT NULL AFTER username');
    }
}

function migrateMisplacedGroupsFromBotChannels(): int
{
    ensureContentGroupTables();
    ensureBotChannelsTable();

    $db = getDb();
    $result = $db->query(
        "SELECT chat_id, chat_type, title, username, member_count, bot_status, is_active, added_at, updated_at
         FROM bot_channels
         WHERE chat_type IN ('group', 'supergroup')"
    );
    if (!$result) {
        return 0;
    }

    $migrated = 0;
    while ($row = $result->fetch_assoc()) {
        $chatId = (int) $row['chat_id'];
        upsertContentGroupRow($row);
        $description = syncContentGroupDescription($chatId);
        routeContentGroupByBio(
            $chatId,
            [
                'id' => $chatId,
                'type' => (string) ($row['chat_type'] ?? 'supergroup'),
                'title' => $row['title'] ?? null,
                'username' => $row['username'] ?? null,
            ],
            (string) ($row['bot_status'] ?? 'administrator'),
            $description
        );
        $migrated++;
    }

    return $migrated;
}

function upsertContentGroupRow(array $data): void
{
    ensureContentGroupTables();
    $db = getDb();

    $chatId = (int) ($data['chat_id'] ?? 0);
    if ($chatId === 0) {
        return;
    }

    $chatType = (string) ($data['chat_type'] ?? 'supergroup');
    $title = (string) ($data['title'] ?? '');
    $username = (string) ($data['username'] ?? '');
    $memberCount = isset($data['member_count']) ? (int) $data['member_count'] : null;
    $botStatus = (string) ($data['bot_status'] ?? 'administrator');
    $photoFileId = $data['photo_file_id'] ?? null;
    $chatDescription = isset($data['chat_description']) ? (string) $data['chat_description'] : null;
    $isPrivate = empty($username) ? 1 : 0;
    $memberCountValue = $memberCount ?? 0;
    $photoFileIdValue = $photoFileId ?? '';
    $chatDescriptionValue = $chatDescription ?? '';

    $stmt = $db->prepare(
        'INSERT INTO content_groups
            (chat_id, chat_type, title, username, chat_description, member_count, bot_status, is_active, is_private, photo_file_id,
             health_status, health_message, deactivated_reason, deactivated_at)
         VALUES (?, ?, ?, ?, NULLIF(?, \'\'), NULLIF(?, 0), ?, 1, ?, NULLIF(?, \'\'), \'ok\', NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE
            chat_type = VALUES(chat_type),
            title = VALUES(title),
            username = VALUES(username),
            chat_description = VALUES(chat_description),
            member_count = COALESCE(NULLIF(VALUES(member_count), 0), member_count),
            bot_status = VALUES(bot_status),
            is_active = 1,
            is_private = VALUES(is_private),
            photo_file_id = COALESCE(NULLIF(VALUES(photo_file_id), \'\'), photo_file_id),
            health_status = \'ok\',
            health_message = NULL,
            deactivated_reason = NULL,
            deactivated_at = NULL,
            updated_at = NOW()'
    );
    $stmt->bind_param(
        'issssisis',
        $chatId,
        $chatType,
        $title,
        $username,
        $chatDescriptionValue,
        $memberCountValue,
        $botStatus,
        $isPrivate,
        $photoFileIdValue
    );
    $stmt->execute();
    $stmt->close();
}

function deactivateContentGroup(int $chatId, string $reason = 'bot_removed', ?string $message = null): void
{
    ensureContentGroupTables();
    $message = $message ?? labelChannelProblem($reason);
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE content_groups SET is_active = 0, health_status = ?, health_message = ?,
         deactivated_reason = ?, deactivated_at = COALESCE(deactivated_at, NOW()), updated_at = NOW()
         WHERE chat_id = ?'
    );
    $stmt->bind_param('sssi', $reason, $message, $reason, $chatId);
    $stmt->execute();
    $stmt->close();
}

function syncContentGroupFromChat(array $chat): bool
{
    $chatId = (int) ($chat['id'] ?? 0);
    $chatType = (string) ($chat['type'] ?? '');
    if ($chatId === 0 || !isContentGroupChatType($chatType)) {
        return false;
    }

    $botId = getBotId();
    if ($botId <= 0) {
        return false;
    }

    $chatInfo = telegramRequest('getChat', ['chat_id' => $chatId]);
    if (!empty($chatInfo['ok']) && !empty($chatInfo['result'])) {
        $chat = array_merge($chat, $chatInfo['result']);
    }

    $member = telegramRequest('getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $botId,
    ]);

    $status = (string) ($member['result']['status'] ?? '');
    if (isBotAdminStatus($status)) {
        $photoFileId = null;
        $photos = telegramRequest('getChat', ['chat_id' => $chatId]);
        if (!empty($photos['ok']) && !empty($photos['result']['photo']['small_file_id'])) {
            $photoFileId = (string) $photos['result']['photo']['small_file_id'];
        }

        $description = trim((string) ($chat['description'] ?? ''));

        upsertContentGroupRow([
            'chat_id' => $chatId,
            'chat_type' => (string) ($chat['type'] ?? $chatType),
            'title' => $chat['title'] ?? null,
            'username' => $chat['username'] ?? null,
            'chat_description' => $description !== '' ? $description : null,
            'member_count' => isset($chat['member_count']) ? (int) $chat['member_count'] : null,
            'bot_status' => $status,
            'photo_file_id' => $photoFileId,
        ]);
        routeContentGroupByBio($chatId, $chat, $status, $description !== '' ? $description : null);

        return true;
    }

    $code = memberStatusToProblemCode($status);
    deactivateContentGroup($chatId, $code, labelChannelProblem($code));

    return false;
}

function handleContentGroupMyChatMember(array $update): void
{
    $payload = $update['my_chat_member'] ?? [];
    $chat = $payload['chat'] ?? [];
    $newMember = $payload['new_chat_member'] ?? [];
    $chatType = (string) ($chat['type'] ?? '');

    if (!isContentGroupChatType($chatType)) {
        return;
    }

    $status = (string) ($newMember['status'] ?? '');
    if (isBotAdminStatus($status)) {
        syncContentGroupFromChat($chat);
        return;
    }

    $chatId = (int) ($chat['id'] ?? 0);
    $code = memberStatusToProblemCode($status);
    deactivateContentGroup($chatId, $code, labelChannelProblem($code));
}

function resyncKnownContentGroups(): int
{
    ensureContentGroupTables();
    $db = getDb();
    $chatIds = [];

    $result = $db->query('SELECT chat_id, chat_type FROM content_groups');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $chatIds[(int) $row['chat_id']] = (string) ($row['chat_type'] ?? 'supergroup');
        }
    }

    $logPath = dirname(__DIR__) . '/storage/logs/update.log';
    if (!is_readable($logPath)) {
        $logPath = dirname(__DIR__) . '/update.log';
    }
    if (is_readable($logPath)) {
        $content = (string) file_get_contents($logPath);
        if (preg_match_all('/"chat":\{"id":(-?\d+),"[^}]*"type":"(group|supergroup)"/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $chatIds[(int) $match[1]] = $match[2];
            }
        }
    }

    $synced = 0;
    foreach ($chatIds as $chatId => $type) {
        if (syncContentGroupFromChat(['id' => $chatId, 'type' => $type])) {
            $synced++;
        }
    }

    return $synced;
}

/**
 * @return list<array<string, mixed>>
 */
function getActiveContentGroups(bool $includeInactive = false, ?string $bioMarker = null): array
{
    ensureContentGroupTables();
    migrateMisplacedGroupsFromBotChannels();

    $db = getDb();
    $conditions = [];
    if (!$includeInactive) {
        $conditions[] = 'is_active = 1';
    }
    if ($bioMarker === '01' || $bioMarker === '02') {
        $safeBio = $db->real_escape_string($bioMarker);
        $conditions[] = "TRIM(COALESCE(chat_description, '')) = '{$safeBio}'";
    } elseif ($bioMarker === 'general') {
        $conditions[] = "TRIM(COALESCE(chat_description, '')) NOT IN ('01', '02')";
    }
    $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';
    $result = $db->query(
        "SELECT chat_id, chat_type, title, username, chat_description, member_count, bot_status, is_active, is_private,
                photo_file_id, health_status, health_message, deactivated_reason, added_at, updated_at,
                message_count, photo_count, video_count, last_message_at
         FROM content_groups
         {$where}
         ORDER BY is_pinned DESC, pinned_at DESC, is_active DESC, title ASC, chat_id ASC"
    );

    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = formatContentGroupRow($row);
    }

    return $rows;
}

function formatContentGroupRow(array $row): array
{
    $chatId = (int) $row['chat_id'];
    $username = $row['username'] ?? null;
    $health = channelHealthPayload($row);
    $isPrivate = (int) ($row['is_private'] ?? 0) === 1 || empty($username);
    $photoFileId = $row['photo_file_id'] ?? null;

    $bioMarker = normalizeGroupBioMarker($row['chat_description'] ?? '');

    return [
        'chat_id' => $chatId,
        'type' => $row['chat_type'],
        'title' => $row['title'] ?: 'گروه بدون نام',
        'username' => $username,
        'chat_description' => $bioMarker !== '' ? $bioMarker : null,
        'bio_marker' => $bioMarker !== '' ? $bioMarker : null,
        'bio_category' => getGroupBioCategory($bioMarker),
        'member_count' => $row['member_count'] !== null ? (int) $row['member_count'] : null,
        'bot_status' => $row['bot_status'],
        'added_at' => $row['added_at'],
        'updated_at' => $row['updated_at'],
        'is_private' => $isPrivate,
        'privacy_label' => $isPrivate ? 'خصوصی' : ('@' . $username),
        'photo_url' => $photoFileId ? 'api/channel_photo.php?chat_id=' . $chatId : null,
        'link' => $username ? 'https://t.me/' . $username : null,
        'is_active' => $health['is_active'],
        'health_status' => $health['health_status'],
        'health_message' => $health['health_message'],
        'is_banned' => $health['is_banned'],
        'message_count' => (int) ($row['message_count'] ?? 0),
        'photo_count' => (int) ($row['photo_count'] ?? 0),
        'video_count' => (int) ($row['video_count'] ?? 0),
        'last_message_at' => $row['last_message_at'] ?? null,
        ...explorerPinPayload($row),
    ];
}

function runContentGroupHealthPass(int $limit = 5): int
{
    ensureContentGroupTables();
    $db = getDb();
    $result = $db->query(
        "SELECT chat_id, chat_type FROM content_groups
         WHERE is_active = 1
         ORDER BY COALESCE(last_health_check, '1970-01-01') ASC, chat_id ASC
         LIMIT " . max(1, (int) $limit)
    );
    if (!$result) {
        return 0;
    }

    $checked = 0;
    while ($row = $result->fetch_assoc()) {
        syncContentGroupFromChat([
            'id' => (int) $row['chat_id'],
            'type' => (string) ($row['chat_type'] ?? 'supergroup'),
        ]);
        $chatId = (int) $row['chat_id'];
        $db->query("UPDATE content_groups SET last_health_check = NOW() WHERE chat_id = {$chatId}");
        $checked++;
    }

    return $checked;
}
