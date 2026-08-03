<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';
require_once __DIR__ . '/channel_folders.php';
require_once __DIR__ . '/bot_folders.php';
require_once __DIR__ . '/child_bots.php';
require_once __DIR__ . '/bot_health.php';
require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/channel_stats.php';
require_once __DIR__ . '/explorer_pins.php';

function ensureAutoPostTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    name VARCHAR(120) NOT NULL,
    icon VARCHAR(40) NOT NULL DEFAULT 'folder',
    parent_id INT UNSIGNED NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owner_sort (owner_telegram_id, sort_order),
    KEY idx_parent_sort (parent_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_sessions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    name VARCHAR(160) NOT NULL,
    channel_folder_id INT UNSIGNED NOT NULL,
    bot_folder_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owner (owner_telegram_id),
    KEY idx_channel_folder (channel_folder_id),
    KEY idx_bot_folder (bot_folder_id),
    KEY idx_auto_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureAutoPostBotFolderColumn();
    if (!schemaMigrationsComplete()) {
        ensureExplorerPinColumns('auto_post_folders');
        ensureExplorerPinColumns('auto_post_sessions');
    }
}

function ensureAutoPostBotFolderColumn(): void
{
    if (schemaMigrationsComplete()) {
        return;
    }

    $db = getDb();
    $hasNew = $db->query("SHOW COLUMNS FROM auto_post_sessions LIKE 'bot_folder_id'");
    if ($hasNew && $hasNew->num_rows > 0) {
        return;
    }

    $hasLegacy = $db->query("SHOW COLUMNS FROM auto_post_sessions LIKE 'bot_channel_folder_id'");
    if ($hasLegacy && $hasLegacy->num_rows > 0) {
        $db->query(
            'ALTER TABLE auto_post_sessions
             CHANGE bot_channel_folder_id bot_folder_id INT UNSIGNED NOT NULL'
        );
        $idx = $db->query("SHOW INDEX FROM auto_post_sessions WHERE Key_name = 'idx_bot_channel_folder'");
        if ($idx && $idx->num_rows > 0) {
            $db->query('ALTER TABLE auto_post_sessions DROP INDEX idx_bot_channel_folder');
        }
        $newIdx = $db->query("SHOW INDEX FROM auto_post_sessions WHERE Key_name = 'idx_bot_folder'");
        if (!$newIdx || $newIdx->num_rows === 0) {
            $db->query('ALTER TABLE auto_post_sessions ADD KEY idx_bot_folder (bot_folder_id)');
        }
    }
}

function normalizeAutoPostFolderParentId(mixed $parentId): ?int
{
    if ($parentId === null || $parentId === '' || (int) $parentId <= 0) {
        return null;
    }

    return (int) $parentId;
}

function normalizeFolderNameForAutoPostMatch(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');

    return preg_replace('/\s+/u', '', $name) ?? $name;
}

function isAutoPostAllowedChannelRootName(string $name): bool
{
    $normalized = normalizeFolderNameForAutoPostMatch($name);
    if ($normalized === '') {
        return false;
    }
    if (str_contains($normalized, 'تبلیغ')) {
        return false;
    }
    if (str_contains($normalized, 'غیر') && str_contains($normalized, 'اخلاق')) {
        return true;
    }
    if (str_contains($normalized, 'اخلاق')) {
        return true;
    }

    return false;
}

/**
 * @return list<int>
 */
function getAutoPostAllowedChannelFolderRootIds(): array
{
    ensureChannelFolderTables();
    $folders = getChannelFolders();
    $roots = [];
    foreach ($folders as $folder) {
        if (!empty($folder['parent_id'])) {
            continue;
        }
        if (isAutoPostAllowedChannelRootName((string) ($folder['name'] ?? ''))) {
            $roots[] = (int) $folder['id'];
        }
    }

    return $roots;
}

/**
 * @return list<int>
 */
function getAutoPostAllowedChannelFolderIds(): array
{
    $allowed = [];
    foreach (getAutoPostAllowedChannelFolderRootIds() as $rootId) {
        $allowed[] = $rootId;
        foreach (getChannelFolderDescendantIds($rootId) as $childId) {
            $allowed[] = $childId;
        }
    }

    return array_values(array_unique($allowed));
}

function isAutoPostAllowedChannelFolderId(int $folderId): bool
{
    if ($folderId <= 0) {
        return false;
    }

    return in_array($folderId, getAutoPostAllowedChannelFolderIds(), true);
}

/**
 * @return list<int>
 */
function getChannelFolderTreeIds(int $folderId): array
{
    if ($folderId <= 0) {
        return [];
    }

    return array_values(array_unique(array_merge([$folderId], getChannelFolderDescendantIds($folderId))));
}

function getChannelFolderPathLabel(int $folderId): string
{
    $folders = getChannelFolders();
    $byId = [];
    foreach ($folders as $folder) {
        $byId[(int) $folder['id']] = $folder;
    }

    $parts = [];
    $current = $folderId;
    $guard = 0;
    while ($current > 0 && isset($byId[$current]) && $guard < 32) {
        $parts[] = (string) ($byId[$current]['name'] ?? 'پوشه');
        $parent = normalizeChannelFolderParentId($byId[$current]['parent_id'] ?? null);
        $current = $parent ?? 0;
        $guard++;
    }

    return implode(' / ', array_reverse($parts));
}

/**
 * @return list<array<string, mixed>>
 */
function getAutoPostFolders(int $ownerTelegramId): array
{
    ensureAutoPostTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT f.id, f.name, f.icon, f.sort_order, f.parent_id, f.created_at, f.is_pinned, f.pinned_at,
                (SELECT COUNT(*) FROM auto_post_sessions s WHERE s.folder_id = f.id AND s.owner_telegram_id = ?) AS session_count,
                (SELECT COUNT(*) FROM auto_post_folders c WHERE c.parent_id = f.id) AS subfolder_count
         FROM auto_post_folders f
         WHERE f.owner_telegram_id = ?
         ORDER BY f.is_pinned DESC, f.pinned_at DESC, f.sort_order ASC, f.id ASC'
    );
    $stmt->bind_param('ii', $ownerTelegramId, $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $folders = [];
    while ($row = $result->fetch_assoc()) {
        $folders[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'icon' => $row['icon'] ?: 'folder',
            'sort_order' => (int) $row['sort_order'],
            'parent_id' => normalizeAutoPostFolderParentId($row['parent_id'] ?? null),
            'session_count' => (int) ($row['session_count'] ?? 0),
            'subfolder_count' => (int) ($row['subfolder_count'] ?? 0),
            'created_at' => $row['created_at'] ?? null,
            'is_pinned' => (int) ($row['is_pinned'] ?? 0) === 1,
            'pinned_at' => $row['pinned_at'] ?? null,
        ];
    }
    $stmt->close();

    return $folders;
}

/**
 * @return list<array<string, mixed>>
 */
function getAutoPostSessions(int $ownerTelegramId): array
{
    ensureAutoPostTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT s.id, s.name, s.channel_folder_id, s.bot_folder_id, s.folder_id, s.created_at,
                s.is_pinned, s.pinned_at
         FROM auto_post_sessions s
         WHERE s.owner_telegram_id = ?
         ORDER BY s.is_pinned DESC, s.pinned_at DESC, s.sort_order ASC, s.id DESC'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $sessions = [];
    while ($row = $result->fetch_assoc()) {
        $channelFolderId = (int) $row['channel_folder_id'];
        $botFolderId = (int) $row['bot_folder_id'];
        $sessions[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'channel_folder_id' => $channelFolderId,
            'bot_folder_id' => $botFolderId,
            'channel_folder_path' => getChannelFolderPathLabel($channelFolderId),
            'bot_folder_path' => getBotFolderPathLabel($ownerTelegramId, $botFolderId),
            'folder_id' => isset($row['folder_id']) ? (int) $row['folder_id'] : null,
            'created_at' => $row['created_at'] ?? null,
            'is_pinned' => (int) ($row['is_pinned'] ?? 0) === 1,
            'pinned_at' => $row['pinned_at'] ?? null,
        ];
    }
    $stmt->close();

    return $sessions;
}

function createAutoPostFolder(int $ownerTelegramId, string $name, string $icon = 'folder', ?int $parentId = null): array
{
    ensureAutoPostTables();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    $icon = trim($icon) ?: 'folder';
    $parentId = normalizeAutoPostFolderParentId($parentId);
    if ($parentId !== null) {
        $db = getDb();
        $stmt = $db->prepare('SELECT id FROM auto_post_folders WHERE id = ? AND owner_telegram_id = ? LIMIT 1');
        $stmt->bind_param('ii', $parentId, $ownerTelegramId);
        $stmt->execute();
        $exists = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$exists) {
            throw new InvalidArgumentException('invalid_parent');
        }
    }

    $db = getDb();
    if ($parentId === null) {
        $stmt = $db->prepare('INSERT INTO auto_post_folders (owner_telegram_id, name, icon, parent_id) VALUES (?, ?, ?, NULL)');
        $stmt->bind_param('iss', $ownerTelegramId, $name, $icon);
    } else {
        $stmt = $db->prepare('INSERT INTO auto_post_folders (owner_telegram_id, name, icon, parent_id) VALUES (?, ?, ?, ?)');
        $stmt->bind_param('issi', $ownerTelegramId, $name, $icon, $parentId);
    }
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'name' => $name,
        'icon' => $icon,
        'parent_id' => $parentId,
        'session_count' => 0,
        'subfolder_count' => 0,
    ];
}

function updateAutoPostFolder(int $ownerTelegramId, int $folderId, ?string $name = null, ?string $icon = null): void
{
    ensureAutoPostTables();
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
    $sql = 'UPDATE auto_post_folders SET ' . implode(', ', $fields) . ' WHERE id = ? AND owner_telegram_id = ?';
    $types .= 'ii';
    $values[] = $folderId;
    $values[] = $ownerTelegramId;
    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $stmt->close();
}

function deleteAutoPostFolder(int $ownerTelegramId, int $folderId): bool
{
    ensureAutoPostTables();
    if ($folderId <= 0) {
        return false;
    }

    $db = getDb();
    $db->query('UPDATE auto_post_sessions SET folder_id = NULL WHERE folder_id = ' . (int) $folderId);
    $stmt = $db->prepare('DELETE FROM auto_post_folders WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

function moveAutoPostFolder(int $ownerTelegramId, int $folderId, ?int $parentId): bool
{
    ensureAutoPostTables();
    if ($folderId <= 0) {
        return false;
    }
    $parentId = normalizeAutoPostFolderParentId($parentId);
    if ($parentId === $folderId) {
        throw new InvalidArgumentException('invalid_move');
    }
    if ($parentId !== null && in_array($parentId, getAutoPostFolderDescendantIds($ownerTelegramId, $folderId), true)) {
        throw new InvalidArgumentException('invalid_move');
    }

    $db = getDb();
    if ($parentId === null) {
        $stmt = $db->prepare('UPDATE auto_post_folders SET parent_id = NULL WHERE id = ? AND owner_telegram_id = ?');
        $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    } else {
        $stmt = $db->prepare('UPDATE auto_post_folders SET parent_id = ? WHERE id = ? AND owner_telegram_id = ?');
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
function getAutoPostFolderDescendantIds(int $ownerTelegramId, int $folderId): array
{
    ensureAutoPostTables();
    $db = getDb();
    $ids = [];
    $queue = [$folderId];
    while ($queue !== []) {
        $current = array_shift($queue);
        $stmt = $db->prepare('SELECT id FROM auto_post_folders WHERE parent_id = ? AND owner_telegram_id = ?');
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

function createAutoPostSession(
    int $ownerTelegramId,
    int $channelFolderId,
    int $botFolderId,
    ?string $name = null,
    ?int $folderId = null
): array {
    ensureAutoPostTables();
    ensureChannelFolderTables();
    ensureBotFolderTables();

    if (!channelFolderExists($channelFolderId)) {
        throw new InvalidArgumentException('invalid_channel_folder');
    }
    if (!isAutoPostAllowedChannelFolderId($channelFolderId)) {
        throw new InvalidArgumentException('channel_folder_not_allowed');
    }
    if (!botFolderExists($botFolderId, $ownerTelegramId)) {
        throw new InvalidArgumentException('invalid_bot_folder');
    }

    $channelPath = getChannelFolderPathLabel($channelFolderId);
    $botPath = getBotFolderPathLabel($ownerTelegramId, $botFolderId);
    $name = trim((string) $name);
    if ($name === '') {
        $name = $channelPath . ' → ' . $botPath;
    }
    if (mb_strlen($name) > 160) {
        $name = mb_substr($name, 0, 157) . '...';
    }

    $folderId = $folderId !== null && $folderId > 0 ? $folderId : null;

    $db = getDb();
    if ($folderId === null) {
        $stmt = $db->prepare(
            'INSERT INTO auto_post_sessions
             (owner_telegram_id, name, channel_folder_id, bot_folder_id, folder_id)
             VALUES (?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('isii', $ownerTelegramId, $name, $channelFolderId, $botFolderId);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO auto_post_sessions
             (owner_telegram_id, name, channel_folder_id, bot_folder_id, folder_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isiii', $ownerTelegramId, $name, $channelFolderId, $botFolderId, $folderId);
    }
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'name' => $name,
        'channel_folder_id' => $channelFolderId,
        'bot_folder_id' => $botFolderId,
        'channel_folder_path' => $channelPath,
        'bot_folder_path' => $botPath,
        'folder_id' => $folderId,
    ];
}

function deleteAutoPostSession(int $ownerTelegramId, int $sessionId): bool
{
    ensureAutoPostTables();
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM auto_post_sessions WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

function updateAutoPostSession(int $ownerTelegramId, int $sessionId, string $name): bool
{
    ensureAutoPostTables();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    if (mb_strlen($name) > 160) {
        $name = mb_substr($name, 0, 157) . '...';
    }

    $db = getDb();
    $stmt = $db->prepare('UPDATE auto_post_sessions SET name = ? WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('sii', $name, $sessionId, $ownerTelegramId);
    $stmt->execute();
    $updated = $stmt->affected_rows > 0;
    $stmt->close();

    return $updated;
}

function assignAutoPostSessionToFolder(int $ownerTelegramId, int $sessionId, ?int $folderId): void
{
    ensureAutoPostTables();
    $db = getDb();
    if ($folderId === null || $folderId <= 0) {
        $stmt = $db->prepare('UPDATE auto_post_sessions SET folder_id = NULL WHERE id = ? AND owner_telegram_id = ?');
        $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    } else {
        $stmt = $db->prepare('UPDATE auto_post_sessions SET folder_id = ? WHERE id = ? AND owner_telegram_id = ?');
        $stmt->bind_param('iii', $folderId, $sessionId, $ownerTelegramId);
    }
    $stmt->execute();
    $stmt->close();
}

function setAutoPostFolderPinned(int $ownerTelegramId, int $folderId, bool $pinned): bool
{
    ensureAutoPostTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM auto_post_folders WHERE id = ? AND owner_telegram_id = ? LIMIT 1');
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        throw new InvalidArgumentException('invalid_folder');
    }

    return setExplorerEntityPinned('auto_post_folders', 'id', $folderId, $pinned);
}

function setAutoPostSessionPinned(int $ownerTelegramId, int $sessionId, bool $pinned): bool
{
    ensureAutoPostTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM auto_post_sessions WHERE id = ? AND owner_telegram_id = ? LIMIT 1');
    $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        throw new InvalidArgumentException('invalid_session');
    }

    return setExplorerEntityPinned('auto_post_sessions', 'id', $sessionId, $pinned);
}

/**
 * @param array{points?: list<array{hour: string, count: int}>, range?: array{from: string, to: string}} $chartA
 * @param array{points?: list<array{hour: string, count: int}>, range?: array{from: string, to: string}} $chartB
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function mergeHourlySumCharts(array $chartA, array $chartB): array
{
    $mapA = [];
    foreach ($chartA['points'] ?? [] as $point) {
        $hour = (string) ($point['hour'] ?? '');
        if ($hour !== '') {
            $mapA[$hour] = (int) ($point['count'] ?? 0);
        }
    }
    $mapB = [];
    foreach ($chartB['points'] ?? [] as $point) {
        $hour = (string) ($point['hour'] ?? '');
        if ($hour !== '') {
            $mapB[$hour] = (int) ($point['count'] ?? 0);
        }
    }

    $hours = array_values(array_unique(array_merge(array_keys($mapA), array_keys($mapB))));
    sort($hours, SORT_STRING);

    $points = [];
    foreach ($hours as $hour) {
        $points[] = [
            'hour' => $hour,
            'count' => ($mapA[$hour] ?? 0) + ($mapB[$hour] ?? 0),
        ];
    }

    $from = $chartA['range']['from'] ?? ($chartB['range']['from'] ?? ($hours[0] ?? ''));
    $to = $chartA['range']['to'] ?? ($chartB['range']['to'] ?? ($hours[count($hours) - 1] ?? ''));

    return [
        'points' => $points,
        'range' => ['from' => $from, 'to' => $to],
    ];
}

/**
 * @return array<string, mixed>|null
 */
function getAutoPostSessionStats(int $ownerTelegramId, int $sessionId): ?array
{
    ensureAutoPostTables();
    ensureChildBotTables();
    require_once __DIR__ . '/bot_stats.php';
    ensureBotStatsTables();
    ensureChildBotHealthColumns();

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, name, channel_folder_id, bot_folder_id, created_at
         FROM auto_post_sessions WHERE id = ? AND owner_telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        return null;
    }

    $channelFolderIds = getChannelFolderTreeIds((int) $session['channel_folder_id']);
    $botFolderIds = getBotFolderTreeIds($ownerTelegramId, (int) $session['bot_folder_id']);

    $channels = [];
    $channelMembers = 0;
    $channelGrowth24h = 0;
    if ($channelFolderIds !== []) {
        $inFolders = implode(',', array_map('intval', $channelFolderIds));
        $result = $db->query(
            "SELECT c.chat_id, c.title, c.username, c.member_count, c.is_active, c.health_status
             FROM channel_folder_items i
             INNER JOIN bot_channels c ON c.chat_id = i.chat_id
             WHERE i.folder_id IN ({$inFolders}) AND c.is_active = 1
             ORDER BY c.title ASC"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $members = (int) ($row['member_count'] ?? 0);
                $channelMembers += $members;
                $health = channelHealthPayload($row);
                $channels[] = [
                    'chat_id' => (int) $row['chat_id'],
                    'title' => $row['title'] ?: 'کانال',
                    'username' => $row['username'] ?: null,
                    'member_count' => $members,
                    'is_banned' => $health['is_banned'],
                ];
            }
        }

        if ($channels !== []) {
            $chatIds = implode(',', array_map(static fn ($c) => (int) $c['chat_id'], $channels));
            $joinResult = $db->query(
                "SELECT COUNT(*) AS cnt FROM channel_join_events
                 WHERE chat_id IN ({$chatIds}) AND joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)"
            );
            if ($joinResult) {
                $joinRow = $joinResult->fetch_assoc();
                $channelGrowth24h = (int) ($joinRow['cnt'] ?? 0);
            }

            $snapResult = $db->query(
                "SELECT COALESCE(SUM(delta), 0) AS growth FROM (
                    SELECT chat_id,
                           MAX(member_count) - MIN(member_count) AS delta
                    FROM channel_member_snapshots
                    WHERE chat_id IN ({$chatIds})
                      AND recorded_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                    GROUP BY chat_id
                 ) t"
            );
            if ($snapResult) {
                $snapRow = $snapResult->fetch_assoc();
                $snapGrowth = (int) ($snapRow['growth'] ?? 0);
                if ($snapGrowth > 0) {
                    $channelGrowth24h = max($channelGrowth24h, $snapGrowth);
                }
            }
        }
    }

    $bots = [];
    $uploaderCount = 0;
    $guardianCount = 0;
    $healthyBotIds = [];
    if ($botFolderIds !== []) {
        $inBotFolders = implode(',', array_map('intval', $botFolderIds));
        $botResult = $db->query(
            "SELECT c.id, c.bot_telegram_id, c.bot_username, c.bot_name, c.bot_type, c.health_status, c.health_message
             FROM bot_folder_items i
             INNER JOIN child_bots c ON c.id = i.bot_id
             WHERE i.folder_id IN ({$inBotFolders})
               AND c.owner_telegram_id = {$ownerTelegramId}
               AND c.status = 'active'
             ORDER BY c.bot_type ASC, c.id ASC"
        );
        if ($botResult) {
            while ($row = $botResult->fetch_assoc()) {
                $health = childBotHealthPayload($row);
                $type = (string) ($row['bot_type'] ?? 'uploader');
                if ($type === 'guardian') {
                    $guardianCount++;
                } else {
                    $uploaderCount++;
                }
                if (!$health['is_banned']) {
                    $healthyBotIds[] = (int) $row['id'];
                }
                $bots[] = [
                    'id' => (int) $row['id'],
                    'bot_name' => $row['bot_name'] ?: ($row['bot_username'] ? '@' . $row['bot_username'] : 'ربات'),
                    'bot_username' => $row['bot_username'] ?: null,
                    'bot_type' => $type,
                    'is_banned' => $health['is_banned'],
                ];
            }
        }
    }

    $uniqueBotUsers = 0;
    $botUsers24h = 0;
    $botActive24h = 0;
    $botJoins1h = 0;
    $botJoins24h = 0;
    if ($healthyBotIds !== []) {
        try {
            $botIdList = implode(',', $healthyBotIds);
            $userResult = $db->query(
                "SELECT COUNT(DISTINCT telegram_id) AS unique_users,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS joins_1h,
                        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS joins_24h,
                        COUNT(DISTINCT CASE WHEN last_seen_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN telegram_id END) AS active_24h
                 FROM uploader_users WHERE child_bot_id IN ({$botIdList})"
            );
            if ($userResult) {
                $userRow = $userResult->fetch_assoc();
                $uniqueBotUsers = (int) ($userRow['unique_users'] ?? 0);
                $botJoins1h = (int) ($userRow['joins_1h'] ?? 0);
                $botUsers24h = (int) ($userRow['joins_24h'] ?? 0);
                $botJoins24h = $botUsers24h;
                $botActive24h = (int) ($userRow['active_24h'] ?? 0);
            }
        } catch (Throwable $e) {
            error_log('getAutoPostSessionStats user aggregates: ' . $e->getMessage());
        }
    }

    $channelJoins1h = 0;
    $channelJoins24h = 0;
    $chatIdsForCharts = array_map(static fn (array $c): int => (int) $c['chat_id'], $channels);
    if ($chatIdsForCharts !== []) {
        $chatIdList = implode(',', $chatIdsForCharts);
        $channelJoinResult = $db->query(
            "SELECT
                SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR) THEN 1 ELSE 0 END) AS joins_1h,
                SUM(CASE WHEN joined_at >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN 1 ELSE 0 END) AS joins_24h
             FROM channel_join_events WHERE chat_id IN ({$chatIdList})"
        );
        if ($channelJoinResult) {
            $channelJoinRow = $channelJoinResult->fetch_assoc();
            $channelJoins1h = (int) ($channelJoinRow['joins_1h'] ?? 0);
            $channelJoins24h = (int) ($channelJoinRow['joins_24h'] ?? 0);
        }
    }

    if ($bots !== []) {
        $botUserCounts = [];
        if ($healthyBotIds !== []) {
            $botIdList = implode(',', $healthyBotIds);
            $countResult = $db->query(
                "SELECT child_bot_id, COUNT(*) AS cnt FROM uploader_users
                 WHERE child_bot_id IN ({$botIdList}) GROUP BY child_bot_id"
            );
            if ($countResult) {
                while ($row = $countResult->fetch_assoc()) {
                    $botUserCounts[(int) $row['child_bot_id']] = (int) ($row['cnt'] ?? 0);
                }
            }
        }
        foreach ($bots as $idx => $bot) {
            $bots[$idx]['user_count'] = $botUserCounts[(int) $bot['id']] ?? 0;
        }
    }

    ensureChannelStatsTables();
    try {
        $channelJoinsHourly = getAggregatedJoinsHourly24ForChatIds($chatIdsForCharts);
        $channelMembersHourly = getAggregatedMembersHourly24ForChatIds($chatIdsForCharts);
        $botJoinsHourly = getAggregatedBotUserJoinsHourly24($healthyBotIds);
        $botUsersHourly = getAggregatedBotUsersTotalHourly24($healthyBotIds);
        $uniqueBotJoinsHourly = getAggregatedUniqueBotUserJoinsHourly24($healthyBotIds);
        $uniqueBotUsersHourly = getAggregatedUniqueBotUsersTotalHourly24($healthyBotIds);
    } catch (Throwable $e) {
        error_log('getAutoPostSessionStats charts: ' . $e->getMessage());
        $empty = emptyBotStatsHourlyChart();
        $channelJoinsHourly = getAggregatedJoinsHourly24ForChatIds([]);
        $channelMembersHourly = getAggregatedMembersHourly24ForChatIds([]);
        $botJoinsHourly = $empty;
        $botUsersHourly = $empty;
        $uniqueBotJoinsHourly = $empty;
        $uniqueBotUsersHourly = $empty;
    }
    $combinedReachHourly = mergeHourlySumCharts($channelMembersHourly, $uniqueBotUsersHourly);
    $combinedNewHourly = mergeHourlySumCharts($channelJoinsHourly, $uniqueBotJoinsHourly);
    $combinedNew24h = 0;
    foreach ($combinedNewHourly['points'] ?? [] as $point) {
        $combinedNew24h += (int) ($point['count'] ?? 0);
    }

    return [
        'session' => [
            'id' => (int) $session['id'],
            'name' => $session['name'],
            'channel_folder_path' => getChannelFolderPathLabel((int) $session['channel_folder_id']),
            'bot_folder_path' => getBotFolderPathLabel($ownerTelegramId, (int) $session['bot_folder_id']),
            'created_at' => $session['created_at'],
        ],
        'stats' => [
            'channel_count' => count($channels),
            'channel_members' => $channelMembers,
            'channel_growth_24h' => $channelGrowth24h,
            'channel_joins_1h' => $channelJoins1h,
            'channel_joins_24h' => $channelJoins24h,
            'uploader_count' => $uploaderCount,
            'guardian_count' => $guardianCount,
            'bot_count' => count($bots),
            'healthy_bot_count' => count($healthyBotIds),
            'unique_bot_users' => $uniqueBotUsers,
            'bot_users_24h' => $botUsers24h,
            'bot_joins_1h' => $botJoins1h,
            'bot_joins_24h' => $botJoins24h,
            'bot_active_24h' => $botActive24h,
            'combined_reach' => $channelMembers + $uniqueBotUsers,
            'joins_hourly' => $channelJoinsHourly['points'],
            'joins_range' => $channelJoinsHourly['range'],
            'members_hourly' => $channelMembersHourly['points'],
            'members_range' => $channelMembersHourly['range'],
            'bot_joins_hourly' => $botJoinsHourly['points'],
            'bot_joins_range' => $botJoinsHourly['range'],
            'bot_users_hourly' => $botUsersHourly['points'],
            'bot_users_range' => $botUsersHourly['range'],
            'unique_bot_joins_hourly' => $uniqueBotJoinsHourly['points'],
            'unique_bot_joins_range' => $uniqueBotJoinsHourly['range'],
            'unique_bot_users_hourly' => $uniqueBotUsersHourly['points'],
            'unique_bot_users_range' => $uniqueBotUsersHourly['range'],
            'combined_reach_hourly' => $combinedReachHourly['points'],
            'combined_reach_range' => $combinedReachHourly['range'],
            'combined_new_hourly' => $combinedNewHourly['points'],
            'combined_new_range' => $combinedNewHourly['range'],
            'combined_new_24h' => $combinedNew24h,
        ],
        'channels' => $channels,
        'bots' => $bots,
    ];
}
