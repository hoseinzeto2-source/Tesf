<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/explorer_pins.php';

function ensureChannelFolderTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS channel_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    icon VARCHAR(40) NOT NULL DEFAULT 'folder',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS channel_folder_items (
    chat_id BIGINT NOT NULL PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_folder_sort (folder_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureChannelFolderParentColumn();
    ensureExplorerPinColumns('channel_folders');
}

function ensureChannelFolderParentColumn(): void
{
    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM channel_folders LIKE 'parent_id'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            'ALTER TABLE channel_folders
             ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER icon,
             ADD KEY idx_parent_sort (parent_id, sort_order)'
        );
    }
}

function normalizeChannelFolderParentId(mixed $parentId): ?int
{
    if ($parentId === null || $parentId === '' || (int) $parentId <= 0) {
        return null;
    }

    return (int) $parentId;
}

/**
 * @return list<array<string, mixed>>
 */
function getChannelFolders(): array
{
    ensureChannelFolderTables();
    $db = getDb();
    $result = $db->query(
        'SELECT f.id, f.name, f.icon, f.sort_order, f.created_at, f.parent_id, f.is_pinned, f.pinned_at,
                (SELECT COUNT(*) FROM channel_folder_items i WHERE i.folder_id = f.id) AS channel_count,
                (SELECT COUNT(*) FROM channel_folders c WHERE c.parent_id = f.id) AS subfolder_count
         FROM channel_folders f
         ORDER BY f.is_pinned DESC, f.pinned_at DESC, f.sort_order ASC, f.id ASC'
    );
    $folders = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $folders[] = formatChannelFolderRow($row);
        }
    }

    return $folders;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function formatChannelFolderRow(array $row): array
{
    $parentId = normalizeChannelFolderParentId($row['parent_id'] ?? null);

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'icon' => $row['icon'] ?: 'folder',
        'sort_order' => (int) $row['sort_order'],
        'parent_id' => $parentId,
        'channel_count' => (int) ($row['channel_count'] ?? 0),
        'subfolder_count' => (int) ($row['subfolder_count'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        ...explorerPinPayload($row),
    ];
}

function setChannelFolderPinned(int $folderId, bool $pinned): bool
{
    ensureChannelFolderTables();
    if ($folderId <= 0 || !channelFolderExists($folderId)) {
        throw new InvalidArgumentException('invalid_folder');
    }

    return setExplorerEntityPinned('channel_folders', 'id', $folderId, $pinned);
}

/**
 * @return array<int, int> chat_id => folder_id
 */
function getChannelFolderAssignments(): array
{
    ensureChannelFolderTables();
    $db = getDb();
    $result = $db->query('SELECT chat_id, folder_id FROM channel_folder_items');
    $map = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $map[(int) $row['chat_id']] = (int) $row['folder_id'];
        }
    }

    return $map;
}

function attachFolderIdsToChannels(array $channels, ?array $folders = null): array
{
    $assignments = getChannelFolderAssignments();
    if ($folders === null) {
        $folders = getChannelFolders();
    }
    $validFolderIds = [];
    foreach ($folders as $folder) {
        $validFolderIds[(int) ($folder['id'] ?? 0)] = true;
    }

    foreach ($channels as &$channel) {
        $chatId = (int) ($channel['chat_id'] ?? 0);
        $folderId = $assignments[$chatId] ?? null;
        if ($folderId !== null && !isset($validFolderIds[$folderId])) {
            assignChannelToFolder($chatId, null);
            $folderId = null;
        }
        $channel['folder_id'] = $folderId;
    }
    unset($channel);

    return $channels;
}

function createChannelFolder(string $name, string $icon = 'folder', ?int $parentId = null): array
{
    ensureChannelFolderTables();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    $icon = sanitizeFolderIcon($icon);
    $parentId = normalizeChannelFolderParentId($parentId);
    if ($parentId !== null && !channelFolderExists($parentId)) {
        throw new InvalidArgumentException('invalid_parent');
    }

    $db = getDb();
    if ($parentId === null) {
        $stmt = $db->prepare('INSERT INTO channel_folders (name, icon, parent_id) VALUES (?, ?, NULL)');
        $stmt->bind_param('ss', $name, $icon);
    } else {
        $stmt = $db->prepare('INSERT INTO channel_folders (name, icon, parent_id) VALUES (?, ?, ?)');
        $stmt->bind_param('ssi', $name, $icon, $parentId);
    }
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'name' => $name,
        'icon' => $icon,
        'sort_order' => 0,
        'parent_id' => $parentId,
        'channel_count' => 0,
        'subfolder_count' => 0,
    ];
}

function updateChannelFolder(int $folderId, ?string $name = null, ?string $icon = null): bool
{
    ensureChannelFolderTables();
    $sets = [];
    $types = '';
    $values = [];

    if ($name !== null) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('name_required');
        }
        $sets[] = 'name = ?';
        $types .= 's';
        $values[] = $name;
    }
    if ($icon !== null) {
        $icon = sanitizeFolderIcon($icon);
        $sets[] = 'icon = ?';
        $types .= 's';
        $values[] = $icon;
    }
    if ($sets === []) {
        return false;
    }

    $db = getDb();
    $sql = 'UPDATE channel_folders SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ?';
    $types .= 'i';
    $values[] = $folderId;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();

    return $ok;
}

function moveChannelFolder(int $folderId, ?int $parentId): bool
{
    ensureChannelFolderTables();
    if ($folderId <= 0 || !channelFolderExists($folderId)) {
        throw new InvalidArgumentException('invalid_folder');
    }

    $parentId = normalizeChannelFolderParentId($parentId);
    if ($parentId !== null) {
        if ($parentId === $folderId) {
            throw new InvalidArgumentException('invalid_parent');
        }
        if (!channelFolderExists($parentId)) {
            throw new InvalidArgumentException('invalid_parent');
        }
        $descendants = getChannelFolderDescendantIds($folderId);
        if (in_array($parentId, $descendants, true)) {
            throw new InvalidArgumentException('invalid_parent');
        }
    }

    $db = getDb();
    if ($parentId === null) {
        $stmt = $db->prepare('UPDATE channel_folders SET parent_id = NULL, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('i', $folderId);
    } else {
        $stmt = $db->prepare('UPDATE channel_folders SET parent_id = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('ii', $parentId, $folderId);
    }
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();

    return $ok;
}

/**
 * @return list<int>
 */
function getChannelFolderDescendantIds(int $folderId): array
{
    ensureChannelFolderTables();
    $db = getDb();
    $ids = [];
    $queue = [$folderId];
    while ($queue !== []) {
        $current = array_shift($queue);
        $stmt = $db->prepare('SELECT id FROM channel_folders WHERE parent_id = ?');
        $stmt->bind_param('i', $current);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $childId = (int) $row['id'];
            $ids[] = $childId;
            $queue[] = $childId;
        }
        $stmt->close();
    }

    return $ids;
}

function deleteChannelFolder(int $folderId): bool
{
    ensureChannelFolderTables();
    $db = getDb();

    $folder = getChannelFolderById($folderId);
    if (!$folder) {
        return false;
    }

    $parentId = normalizeChannelFolderParentId($folder['parent_id'] ?? null);
    $descendants = getChannelFolderDescendantIds($folderId);
    $allIds = array_merge([$folderId], $descendants);

    foreach ($descendants as $childId) {
        moveChannelFolder($childId, $parentId);
    }

    $stmt = $db->prepare('DELETE FROM channel_folder_items WHERE folder_id = ?');
    $stmt->bind_param('i', $folderId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM channel_folders WHERE id = ?');
    $stmt->bind_param('i', $folderId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

function assignChannelToFolder(int $chatId, ?int $folderId): void
{
    ensureChannelFolderTables();
    $db = getDb();

    if ($folderId === null || $folderId === 0) {
        $stmt = $db->prepare('DELETE FROM channel_folder_items WHERE chat_id = ?');
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $stmt = $db->prepare(
        'INSERT INTO channel_folder_items (chat_id, folder_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id), added_at = NOW()'
    );
    $stmt->bind_param('ii', $chatId, $folderId);
    $stmt->execute();
    $stmt->close();
}

function getChannelFolderById(int $folderId): ?array
{
    ensureChannelFolderTables();
    if ($folderId <= 0) {
        return null;
    }
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, name, icon, sort_order, created_at, parent_id,
                (SELECT COUNT(*) FROM channel_folder_items i WHERE i.folder_id = channel_folders.id) AS channel_count,
                (SELECT COUNT(*) FROM channel_folders c WHERE c.parent_id = channel_folders.id) AS subfolder_count
         FROM channel_folders WHERE id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $folderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return formatChannelFolderRow($row);
}

function channelFolderExists(int $folderId): bool
{
    return getChannelFolderById($folderId) !== null;
}

function sanitizeFolderIcon(string $icon): string
{
    $allowed = [
        'folder', 'folder-open', 'star', 'heart', 'bookmark', 'tag', 'bolt',
        'bullhorn', 'users', 'globe', 'lock', 'fire', 'gem',
    ];
    $icon = preg_replace('/[^a-z0-9-]/', '', strtolower($icon)) ?: 'folder';

    return in_array($icon, $allowed, true) ? $icon : 'folder';
}
