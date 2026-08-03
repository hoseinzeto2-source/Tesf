<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/explorer_pins.php';

function ensureBotFolderTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS bot_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    name VARCHAR(120) NOT NULL,
    icon VARCHAR(40) NOT NULL DEFAULT 'folder',
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owner_sort (owner_telegram_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS bot_folder_items (
    bot_id INT UNSIGNED NOT NULL PRIMARY KEY,
    folder_id INT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_folder_sort (folder_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureBotFolderParentColumn();
    ensureExplorerPinColumns('bot_folders');
}

function ensureBotFolderParentColumn(): void
{
    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM bot_folders LIKE 'parent_id'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            'ALTER TABLE bot_folders
             ADD COLUMN parent_id INT UNSIGNED NULL DEFAULT NULL AFTER icon,
             ADD KEY idx_parent_sort (parent_id, sort_order)'
        );
    }
}

function normalizeBotFolderParentId(mixed $parentId): ?int
{
    if ($parentId === null || $parentId === '' || (int) $parentId <= 0) {
        return null;
    }

    return (int) $parentId;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function formatBotFolderRow(array $row): array
{
    $parentId = normalizeBotFolderParentId($row['parent_id'] ?? null);

    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'icon' => $row['icon'] ?: 'folder',
        'sort_order' => (int) $row['sort_order'],
        'parent_id' => $parentId,
        'bot_count' => (int) ($row['bot_count'] ?? 0),
        'subfolder_count' => (int) ($row['subfolder_count'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'is_pinned' => (int) ($row['is_pinned'] ?? 0) === 1,
        'pinned_at' => $row['pinned_at'] ?? null,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function getBotFolders(int $ownerTelegramId): array
{
    ensureBotFolderTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT f.id, f.name, f.icon, f.sort_order, f.created_at, f.parent_id, f.is_pinned, f.pinned_at,
                (SELECT COUNT(*) FROM bot_folder_items i WHERE i.folder_id = f.id) AS bot_count,
                (SELECT COUNT(*) FROM bot_folders c WHERE c.parent_id = f.id AND c.owner_telegram_id = f.owner_telegram_id) AS subfolder_count
         FROM bot_folders f
         WHERE f.owner_telegram_id = ?
         ORDER BY f.is_pinned DESC, f.pinned_at DESC, f.sort_order ASC, f.id ASC'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $folders = [];
    while ($row = $result->fetch_assoc()) {
        $folders[] = formatBotFolderRow($row);
    }
    $stmt->close();

    return $folders;
}

function getBotFolderById(int $folderId, int $ownerTelegramId): ?array
{
    ensureBotFolderTables();
    if ($folderId <= 0) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, name, icon, sort_order, created_at, parent_id, is_pinned, pinned_at,
                (SELECT COUNT(*) FROM bot_folder_items i WHERE i.folder_id = bot_folders.id) AS bot_count,
                (SELECT COUNT(*) FROM bot_folders c WHERE c.parent_id = bot_folders.id AND c.owner_telegram_id = bot_folders.owner_telegram_id) AS subfolder_count
         FROM bot_folders
         WHERE id = ? AND owner_telegram_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    return formatBotFolderRow($row);
}

function botFolderExists(int $folderId, int $ownerTelegramId): bool
{
    return getBotFolderById($folderId, $ownerTelegramId) !== null;
}

/**
 * @return array<int, int> bot_id => folder_id
 */
function getBotFolderAssignments(int $ownerTelegramId): array
{
    ensureBotFolderTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT i.bot_id, i.folder_id
         FROM bot_folder_items i
         INNER JOIN bot_folders f ON f.id = i.folder_id
         WHERE f.owner_telegram_id = ?'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $map = [];
    while ($row = $result->fetch_assoc()) {
        $map[(int) $row['bot_id']] = (int) $row['folder_id'];
    }
    $stmt->close();

    return $map;
}

function attachFolderIdsToBots(array $bots, int $ownerTelegramId, ?array $folders = null): array
{
    $assignments = getBotFolderAssignments($ownerTelegramId);
    if ($folders === null) {
        $folders = getBotFolders($ownerTelegramId);
    }
    $validFolderIds = [];
    foreach ($folders as $folder) {
        $validFolderIds[(int) ($folder['id'] ?? 0)] = true;
    }

    foreach ($bots as &$bot) {
        $botId = (int) ($bot['id'] ?? 0);
        $folderId = $assignments[$botId] ?? null;
        if ($folderId !== null && !isset($validFolderIds[$folderId])) {
            assignBotToFolder($botId, null, $ownerTelegramId);
            $folderId = null;
        }
        $bot['folder_id'] = $folderId;
    }
    unset($bot);

    return $bots;
}

function createBotFolder(int $ownerTelegramId, string $name, string $icon = 'folder', ?int $parentId = null): array
{
    ensureBotFolderTables();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    $icon = sanitizeBotFolderIcon($icon);
    $parentId = normalizeBotFolderParentId($parentId);
    if ($parentId !== null && !botFolderExists($parentId, $ownerTelegramId)) {
        throw new InvalidArgumentException('invalid_parent');
    }

    $db = getDb();
    if ($parentId === null) {
        $stmt = $db->prepare('INSERT INTO bot_folders (owner_telegram_id, name, icon, parent_id) VALUES (?, ?, ?, NULL)');
        $stmt->bind_param('iss', $ownerTelegramId, $name, $icon);
    } else {
        $stmt = $db->prepare('INSERT INTO bot_folders (owner_telegram_id, name, icon, parent_id) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('issi', $ownerTelegramId, $name, $icon, $parentId);
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
        'bot_count' => 0,
        'subfolder_count' => 0,
    ];
}

function updateBotFolder(int $ownerTelegramId, int $folderId, ?string $name = null, ?string $icon = null): bool
{
    ensureBotFolderTables();
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
        $icon = sanitizeBotFolderIcon($icon);
        $sets[] = 'icon = ?';
        $types .= 's';
        $values[] = $icon;
    }
    if ($sets === []) {
        return false;
    }

    $db = getDb();
    $sql = 'UPDATE bot_folders SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = ? AND owner_telegram_id = ?';
    $types .= 'ii';
    $values[] = $folderId;
    $values[] = $ownerTelegramId;

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();

    return $ok;
}

function moveBotFolder(int $ownerTelegramId, int $folderId, ?int $parentId): bool
{
    ensureBotFolderTables();
    if ($folderId <= 0 || !botFolderExists($folderId, $ownerTelegramId)) {
        throw new InvalidArgumentException('invalid_folder');
    }

    $parentId = normalizeBotFolderParentId($parentId);
    if ($parentId !== null) {
        if ($parentId === $folderId) {
            throw new InvalidArgumentException('invalid_parent');
        }
        if (!botFolderExists($parentId, $ownerTelegramId)) {
            throw new InvalidArgumentException('invalid_parent');
        }
        $descendants = getBotFolderDescendantIds($folderId, $ownerTelegramId);
        if (in_array($parentId, $descendants, true)) {
            throw new InvalidArgumentException('invalid_parent');
        }
    }

    $db = getDb();
    if ($parentId === null) {
        $stmt = $db->prepare('UPDATE bot_folders SET parent_id = NULL, updated_at = NOW() WHERE id = ? AND owner_telegram_id = ?');
        $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    } else {
        $stmt = $db->prepare('UPDATE bot_folders SET parent_id = ?, updated_at = NOW() WHERE id = ? AND owner_telegram_id = ?');
        $stmt->bind_param('iii', $parentId, $folderId, $ownerTelegramId);
    }
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();

    return $ok;
}

/**
 * @return list<int>
 */
/**
 * @return list<int>
 */
function getBotFolderTreeIds(int $ownerTelegramId, int $folderId): array
{
    if ($folderId <= 0) {
        return [];
    }

    return array_values(array_unique(array_merge([$folderId], getBotFolderDescendantIds($folderId, $ownerTelegramId))));
}

function getBotFolderPathLabel(int $ownerTelegramId, int $folderId): string
{
    if ($folderId <= 0) {
        return '';
    }

    $folders = getBotFolders($ownerTelegramId);
    $byId = [];
    foreach ($folders as $folder) {
        $byId[(int) $folder['id']] = $folder;
    }

    $parts = [];
    $current = $folderId;
    $guard = 0;
    while ($current > 0 && isset($byId[$current]) && $guard < 32) {
        $parts[] = (string) ($byId[$current]['name'] ?? 'پوشه');
        $parent = normalizeBotFolderParentId($byId[$current]['parent_id'] ?? null);
        $current = $parent ?? 0;
        $guard++;
    }

    return implode(' / ', array_reverse($parts));
}

function getBotFolderDescendantIds(int $folderId, int $ownerTelegramId): array
{
    ensureBotFolderTables();
    $db = getDb();
    $ids = [];
    $queue = [$folderId];
    while ($queue !== []) {
        $current = array_shift($queue);
        $stmt = $db->prepare('SELECT id FROM bot_folders WHERE parent_id = ? AND owner_telegram_id = ?');
        $stmt->bind_param('ii', $current, $ownerTelegramId);
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

function deleteBotFolder(int $ownerTelegramId, int $folderId): bool
{
    ensureBotFolderTables();
    $folder = getBotFolderById($folderId, $ownerTelegramId);
    if (!$folder) {
        return false;
    }

    $parentId = normalizeBotFolderParentId($folder['parent_id'] ?? null);
    $descendants = getBotFolderDescendantIds($folderId, $ownerTelegramId);
    foreach ($descendants as $childId) {
        moveBotFolder($ownerTelegramId, $childId, $parentId);
    }

    $db = getDb();
    $stmt = $db->prepare(
        'DELETE i FROM bot_folder_items i
         INNER JOIN bot_folders f ON f.id = i.folder_id
         WHERE i.folder_id = ? AND f.owner_telegram_id = ?'
    );
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM bot_folders WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

function assignBotToFolder(int $botId, ?int $folderId, int $ownerTelegramId): void
{
    ensureBotFolderTables();
    require_once __DIR__ . '/child_bots.php';
    ensureChildBotTables();

    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM child_bots WHERE id = ? AND owner_telegram_id = ? LIMIT 1');
    $stmt->bind_param('ii', $botId, $ownerTelegramId);
    $stmt->execute();
    $owned = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$owned) {
        throw new InvalidArgumentException('bot_not_found');
    }

    if ($folderId === null || $folderId === 0) {
        $stmt = $db->prepare('DELETE FROM bot_folder_items WHERE bot_id = ?');
        $stmt->bind_param('i', $botId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    if (!botFolderExists($folderId, $ownerTelegramId)) {
        throw new InvalidArgumentException('invalid_folder');
    }

    $stmt = $db->prepare(
        'INSERT INTO bot_folder_items (bot_id, folder_id) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id), added_at = NOW()'
    );
    $stmt->bind_param('ii', $botId, $folderId);
    $stmt->execute();
    $stmt->close();
}

function sanitizeBotFolderIcon(string $icon): string
{
    $allowed = [
        'folder', 'folder-open', 'star', 'heart', 'bookmark', 'tag', 'bolt',
        'bullhorn', 'users', 'globe', 'lock', 'fire', 'gem', 'robot',
    ];
    $icon = preg_replace('/[^a-z0-9-]/', '', strtolower($icon)) ?: 'folder';

    return in_array($icon, $allowed, true) ? $icon : 'folder';
}

/**
 * Folder details with bots and user stats for mini-app.
 *
 * @return array<string, mixed>|null
 */
function getBotFolderDetails(int $folderId, int $ownerTelegramId): ?array
{
    ensureBotFolderTables();
    require_once __DIR__ . '/bot_stats.php';

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, name, icon, created_at
         FROM bot_folders
         WHERE id = ? AND owner_telegram_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $folderRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$folderRow) {
        return null;
    }

    ensureBotStatsTables();
    ensureChildBotTables();
    require_once __DIR__ . '/bot_health.php';
    require_once __DIR__ . '/bot_profile.php';
    ensureChildBotHealthColumns();
    ensureChildBotProfilePhotoColumn();

    $stmt = $db->prepare(
        'SELECT c.id, c.bot_telegram_id, c.bot_username, c.bot_name, c.bot_type, c.status, c.created_at,
                c.health_status, c.health_message, c.profile_photo_file_id,
                COALESCE(u.user_count, 0) AS user_count,
                COALESCE(u.user_growth_24h, 0) AS user_growth_24h,
                COALESCE(u.active_users_24h, 0) AS active_users_24h
         FROM bot_folder_items i
         INNER JOIN child_bots c ON c.id = i.bot_id
         LEFT JOIN (
             SELECT child_bot_id,
                    COUNT(*) AS user_count,
                    SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS user_growth_24h,
                    SUM(CASE WHEN last_seen_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS active_users_24h
             FROM uploader_users
             GROUP BY child_bot_id
         ) u ON u.child_bot_id = c.id
         WHERE i.folder_id = ? AND c.owner_telegram_id = ?
         ORDER BY i.sort_order ASC, i.added_at ASC, c.id ASC'
    );
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();

    $bots = [];
    $totalUsers = 0;
    $totalGrowth24h = 0;
    while ($row = $result->fetch_assoc()) {
        $userCount = (int) ($row['user_count'] ?? 0);
        $growth24h = (int) ($row['user_growth_24h'] ?? 0);

        $username = (string) ($row['bot_username'] ?? '');
        $health = childBotHealthPayload($row);
        $photo = botPhotoPayloadFromFileId((int) $row['id'], $row['profile_photo_file_id'] ?? null);
        if (!$health['is_banned']) {
            $totalUsers += $userCount;
            $totalGrowth24h += $growth24h;
        }
        $bots[] = [
            'id' => (int) $row['id'],
            'bot_telegram_id' => (int) $row['bot_telegram_id'],
            'bot_username' => $username ?: null,
            'bot_name' => $row['bot_name'] ?: ($username ? '@' . $username : 'ربات'),
            'bot_type' => $row['bot_type'],
            'status' => $row['status'],
            'health_status' => $health['health_status'],
            'health_message' => $health['health_message'],
            'is_banned' => $health['is_banned'],
            'has_photo' => $photo['has_photo'],
            'photo_url' => $photo['photo_url'],
            'created_at' => $row['created_at'],
            'user_count' => $userCount,
            'user_growth_24h' => $growth24h,
            'active_users_24h' => (int) ($row['active_users_24h'] ?? 0),
            'link' => $username ? 'https://t.me/' . $username : null,
        ];
    }
    $stmt->close();

    return [
        'folder' => [
            'id' => (int) $folderRow['id'],
            'name' => $folderRow['name'],
            'icon' => $folderRow['icon'] ?: 'folder',
            'created_at' => $folderRow['created_at'],
        ],
        'bot_count' => count($bots),
        'total_users' => $totalUsers,
        'user_growth_24h' => $totalGrowth24h,
        'created_at' => $folderRow['created_at'],
        'bots' => $bots,
    ];
}

function setBotFolderPinned(int $folderId, int $ownerTelegramId, bool $pinned): bool
{
    ensureBotFolderTables();
    if ($folderId <= 0) {
        throw new InvalidArgumentException('invalid_folder');
    }

    return setExplorerEntityPinned('bot_folders', 'id', $folderId, $pinned, 'owner_telegram_id', $ownerTelegramId);
}

function setBotPinned(int $botId, int $ownerTelegramId, bool $pinned): bool
{
    ensureBotFolderTables();
    require_once __DIR__ . '/child_bots.php';
    ensureChildBotTables();
    ensureExplorerPinColumns('child_bots');

    if ($botId <= 0) {
        throw new InvalidArgumentException('invalid_bot');
    }

    return setExplorerEntityPinned('child_bots', 'id', $botId, $pinned, 'owner_telegram_id', $ownerTelegramId);
}
