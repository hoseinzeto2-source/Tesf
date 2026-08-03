<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/channel_folders.php';
require_once __DIR__ . '/content_groups.php';
require_once __DIR__ . '/content_group_stats.php';
require_once __DIR__ . '/auto_post.php';
require_once __DIR__ . '/telegram_api.php';

function ensureBannerToolTables(): void
{
    ensureContentGroupTables();
    ensureContentGroupStatColumns();
    ensureContentGroupDescriptionColumn();

    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS banner_folder_bindings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    channel_folder_id INT UNSIGNED NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_owner_folder (owner_telegram_id, channel_folder_id),
    KEY idx_owner (owner_telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

/**
 * @return list<array<string, mixed>>
 */
function getBannerSourceGroups(): array
{
    ensureBannerToolTables();
    syncAllContentGroupDescriptions();
    reconcileAllContentGroupPlacements(80);

    $groups = getActiveContentGroups(false, '02');
    $mapped = [];
    foreach ($groups as $group) {
        $photoCount = (int) ($group['photo_count'] ?? 0);
        if ($photoCount <= 0) {
            $photoCount = countBannerPhotosForGroup((int) $group['chat_id']);
        }
        $mapped[] = [
            'chat_id' => (int) $group['chat_id'],
            'title' => $group['title'] ?: 'گروه',
            'username' => $group['username'] ?: null,
            'is_private' => !empty($group['is_private']),
            'member_count' => (int) ($group['member_count'] ?? 0),
            'photo_count' => $photoCount,
            'message_count' => (int) ($group['message_count'] ?? 0),
            'bio_marker' => '02',
        ];
    }

    return $mapped;
}

function countBannerPhotosForGroup(int $chatId): int
{
    ensureContentGroupStatColumns();
    $db = getDb();
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS cnt FROM content_group_seen_messages
         WHERE chat_id = ? AND media_kind = 'photo'"
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['cnt'] ?? 0);
}

/**
 * @return array{chat_id:int,message_id:int,telegram_file_id:string,caption?:string}|null
 */
function pickRandomBannerPhoto(): ?array
{
    ensureBannerToolTables();
    $groups = getBannerSourceGroups();
    if ($groups === []) {
        return null;
    }

    $chatIds = array_map(static fn (array $g): int => (int) $g['chat_id'], $groups);
    $inList = implode(',', array_map('intval', $chatIds));
    $db = getDb();
    $result = $db->query(
        "SELECT chat_id, message_id, telegram_file_id, caption
         FROM content_group_seen_messages
         WHERE chat_id IN ({$inList})
           AND media_kind = 'photo'
           AND telegram_file_id IS NOT NULL
           AND telegram_file_id != ''
         ORDER BY RAND()
         LIMIT 1"
    );
    if (!$result) {
        return null;
    }
    $row = $result->fetch_assoc();
    if (!$row) {
        return null;
    }

    return [
        'chat_id' => (int) $row['chat_id'],
        'message_id' => (int) $row['message_id'],
        'telegram_file_id' => (string) $row['telegram_file_id'],
        'caption' => $row['caption'] ?? null,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function getBannerFolderBindings(int $ownerTelegramId): array
{
    ensureBannerToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT b.id, b.channel_folder_id, b.is_enabled, b.created_at
         FROM banner_folder_bindings b
         WHERE b.owner_telegram_id = ?
         ORDER BY b.id DESC'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $bindings = [];
    while ($row = $result->fetch_assoc()) {
        $folderId = (int) $row['channel_folder_id'];
        $bindings[] = [
            'id' => (int) $row['id'],
            'channel_folder_id' => $folderId,
            'channel_folder_path' => getChannelFolderPathLabel($folderId),
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'has_auto_post_session' => hasAutoPostSessionForChannelFolder($ownerTelegramId, $folderId),
            'created_at' => $row['created_at'] ?? null,
        ];
    }
    $stmt->close();

    return $bindings;
}

function hasAutoPostSessionForChannelFolder(int $ownerTelegramId, int $channelFolderId): bool
{
    ensureAutoPostTables();
    $folderIds = getChannelFolderTreeIds($channelFolderId);
    if ($folderIds === []) {
        return false;
    }

    $inList = implode(',', array_map('intval', $folderIds));
    $db = getDb();
    $result = $db->query(
        "SELECT id FROM auto_post_sessions
         WHERE owner_telegram_id = {$ownerTelegramId}
           AND channel_folder_id IN ({$inList})
         LIMIT 1"
    );

    return $result !== false && $result->num_rows > 0;
}

function isBannerActiveForAutoPost(int $ownerTelegramId, int $channelFolderId): bool
{
    ensureBannerToolTables();
    if ($channelFolderId <= 0) {
        return false;
    }

    if (!hasAutoPostSessionForChannelFolder($ownerTelegramId, $channelFolderId)) {
        return false;
    }

    $folderIds = getChannelFolderTreeIds($channelFolderId);
    if ($folderIds === []) {
        return false;
    }

    $inList = implode(',', array_map('intval', $folderIds));
    $db = getDb();
    $result = $db->query(
        "SELECT id FROM banner_folder_bindings
         WHERE owner_telegram_id = {$ownerTelegramId}
           AND channel_folder_id IN ({$inList})
           AND is_enabled = 1
         LIMIT 1"
    );

    return $result !== false && $result->num_rows > 0;
}

function setBannerFolderBinding(int $ownerTelegramId, int $channelFolderId, bool $enabled): array
{
    ensureBannerToolTables();
    ensureChannelFolderTables();

    if ($channelFolderId <= 0 || !channelFolderExists($channelFolderId)) {
        throw new InvalidArgumentException('invalid_channel_folder');
    }

    $db = getDb();
    $enabledInt = $enabled ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO banner_folder_bindings (owner_telegram_id, channel_folder_id, is_enabled)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = NOW()'
    );
    $stmt->bind_param('iii', $ownerTelegramId, $channelFolderId, $enabledInt);
    $stmt->execute();
    $stmt->close();

    return [
        'channel_folder_id' => $channelFolderId,
        'channel_folder_path' => getChannelFolderPathLabel($channelFolderId),
        'is_enabled' => $enabled,
        'has_auto_post_session' => hasAutoPostSessionForChannelFolder($ownerTelegramId, $channelFolderId),
    ];
}

function deleteBannerFolderBinding(int $ownerTelegramId, int $channelFolderId): bool
{
    ensureBannerToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'DELETE FROM banner_folder_bindings WHERE owner_telegram_id = ? AND channel_folder_id = ?'
    );
    $stmt->bind_param('ii', $ownerTelegramId, $channelFolderId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

/**
 * @return array<string, mixed>
 */
function getBannerToolsOverview(int $ownerTelegramId): array
{
    ensureBannerToolTables();
    $groups = getBannerSourceGroups();
    $bindings = getBannerFolderBindings($ownerTelegramId);
    $totalPhotos = 0;
    foreach ($groups as $group) {
        $totalPhotos += (int) ($group['photo_count'] ?? 0);
    }

    return [
        'groups' => $groups,
        'bindings' => $bindings,
        'stats' => [
            'group_count' => count($groups),
            'photo_count' => $totalPhotos,
            'binding_count' => count($bindings),
            'active_binding_count' => count(array_filter($bindings, static fn (array $b): bool => !empty($b['is_enabled']))),
        ],
    ];
}
