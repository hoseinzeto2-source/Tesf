<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/explorer_pins.php';

function ensureContentGroupFolderTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS content_group_folders (
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
CREATE TABLE IF NOT EXISTS content_group_folder_items (
    chat_id BIGINT NOT NULL PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_folder_sort (folder_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureExplorerPinColumns('content_group_folders');
}

/**
 * @return list<array<string, mixed>>
 */
function getContentGroupFolders(): array
{
    ensureContentGroupFolderTables();
    $db = getDb();
    $result = $db->query(
        'SELECT f.id, f.name, f.icon, f.sort_order, f.created_at, f.is_pinned, f.pinned_at,
                (SELECT COUNT(*) FROM content_group_folder_items i WHERE i.folder_id = f.id) AS group_count
         FROM content_group_folders f
         ORDER BY f.is_pinned DESC, f.pinned_at DESC, f.sort_order ASC, f.id ASC'
    );
    if (!$result) {
        return [];
    }

    $folders = [];
    while ($row = $result->fetch_assoc()) {
        $folders[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'icon' => $row['icon'] ?: 'folder',
            'sort_order' => (int) $row['sort_order'],
            'group_count' => (int) $row['group_count'],
            'created_at' => $row['created_at'] ?? null,
            'is_pinned' => (int) ($row['is_pinned'] ?? 0) === 1,
            'pinned_at' => $row['pinned_at'] ?? null,
        ];
    }

    return $folders;
}

/**
 * @return array<int, int> chat_id => folder_id
 */
function getContentGroupFolderAssignments(): array
{
    ensureContentGroupFolderTables();
    $db = getDb();
    $result = $db->query('SELECT chat_id, folder_id FROM content_group_folder_items');
    if (!$result) {
        return [];
    }

    $map = [];
    while ($row = $result->fetch_assoc()) {
        $map[(int) $row['chat_id']] = (int) $row['folder_id'];
    }

    return $map;
}

function attachFolderIdsToContentGroups(array $groups, ?array $folders = null): array
{
    $assignments = getContentGroupFolderAssignments();
    if ($folders === null) {
        $folders = getContentGroupFolders();
    }
    $validFolderIds = [];
    foreach ($folders as $folder) {
        $validFolderIds[(int) $folder['id']] = true;
    }

    foreach ($groups as &$group) {
        $folderId = $assignments[(int) $group['chat_id']] ?? null;
        if ($folderId !== null && !isset($validFolderIds[$folderId])) {
            $folderId = null;
        }
        $group['folder_id'] = $folderId;
    }
    unset($group);

    return $groups;
}

function createContentGroupFolder(string $name, string $icon = 'folder'): array
{
    ensureContentGroupFolderTables();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }

    $db = getDb();
    $icon = trim($icon) ?: 'folder';
    $stmt = $db->prepare('INSERT INTO content_group_folders (name, icon) VALUES (?, ?)');
    $stmt->bind_param('ss', $name, $icon);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'name' => $name,
        'icon' => $icon,
        'group_count' => 0,
    ];
}

function updateContentGroupFolder(int $folderId, ?string $name = null, ?string $icon = null): void
{
    ensureContentGroupFolderTables();
    if ($folderId <= 0) {
        throw new InvalidArgumentException('invalid_folder');
    }

    $fields = [];
    $types = '';
    $values = [];

    if ($name !== null) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('name_required');
        }
        $fields[] = 'name = ?';
        $types .= 's';
        $values[] = $name;
    }
    if ($icon !== null) {
        $icon = trim($icon) ?: 'folder';
        $fields[] = 'icon = ?';
        $types .= 's';
        $values[] = $icon;
    }

    if (!$fields) {
        return;
    }

    $db = getDb();
    $sql = 'UPDATE content_group_folders SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $types .= 'i';
    $values[] = $folderId;
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function deleteContentGroupFolder(int $folderId): bool
{
    ensureContentGroupFolderTables();
    if ($folderId <= 0) {
        return false;
    }

    $db = getDb();
    $db->query('DELETE FROM content_group_folder_items WHERE folder_id = ' . (int) $folderId);
    $stmt = $db->prepare('DELETE FROM content_group_folders WHERE id = ?');
    $stmt->bind_param('i', $folderId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

function assignContentGroupToFolder(int $chatId, ?int $folderId): void
{
    ensureContentGroupFolderTables();
    if ($chatId === 0) {
        throw new InvalidArgumentException('invalid_group');
    }

    $db = getDb();
    if ($folderId === null || $folderId <= 0) {
        $stmt = $db->prepare('DELETE FROM content_group_folder_items WHERE chat_id = ?');
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $check = $db->prepare('SELECT id FROM content_group_folders WHERE id = ? LIMIT 1');
    $check->bind_param('i', $folderId);
    $check->execute();
    $exists = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$exists) {
        throw new InvalidArgumentException('folder_not_found');
    }

    $stmt = $db->prepare(
        'INSERT INTO content_group_folder_items (chat_id, folder_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id), added_at = NOW()'
    );
    $stmt->bind_param('ii', $chatId, $folderId);
    $stmt->execute();
    $stmt->close();
}

function setContentGroupFolderPinned(int $folderId, bool $pinned): bool
{
    ensureContentGroupFolderTables();
    if ($folderId <= 0) {
        throw new InvalidArgumentException('invalid_folder');
    }

    return setExplorerEntityPinned('content_group_folders', 'id', $folderId, $pinned);
}

function setContentGroupPinned(int $chatId, bool $pinned): bool
{
    ensureContentGroupFolderTables();
    require_once __DIR__ . '/content_groups.php';
    ensureContentGroupTables();
    ensureExplorerPinColumns('content_groups');

    if ($chatId === 0) {
        throw new InvalidArgumentException('invalid_group');
    }

    return setExplorerEntityPinned('content_groups', 'chat_id', $chatId, $pinned);
}
