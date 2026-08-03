<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/channel_folders.php';
require_once __DIR__ . '/explorer_pins.php';

function ensureHashtagToolTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS hashtag_tool_folders (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    name VARCHAR(120) NOT NULL,
    icon VARCHAR(40) NOT NULL DEFAULT 'hashtag',
    parent_id INT UNSIGNED NULL DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    pinned_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owner_sort (owner_telegram_id, sort_order),
    KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS hashtag_post_sets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    name VARCHAR(160) NOT NULL,
    channel_folder_id INT UNSIGNED NOT NULL,
    folder_id INT UNSIGNED NULL DEFAULT NULL,
    selection_mode VARCHAR(20) NOT NULL DEFAULT 'random',
    random_count INT UNSIGNED NOT NULL DEFAULT 5,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    sort_order INT NOT NULL DEFAULT 0,
    is_pinned TINYINT(1) NOT NULL DEFAULT 0,
    pinned_at DATETIME NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_owner (owner_telegram_id),
    KEY idx_channel_folder (channel_folder_id),
    KEY idx_folder (folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS hashtag_post_tags (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    set_id INT UNSIGNED NOT NULL,
    tag_text VARCHAR(120) NOT NULL,
    rule_mode VARCHAR(20) NOT NULL DEFAULT 'pool',
    trigger_keywords TEXT DEFAULT NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_set_sort (set_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    ensureExplorerPinColumns('hashtag_tool_folders');
    ensureExplorerPinColumns('hashtag_post_sets');
    ensureHashtagFolderDefaultColumn();
}

function ensureHashtagFolderDefaultColumn(): void
{
    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM hashtag_tool_folders LIKE 'is_default'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            'ALTER TABLE hashtag_tool_folders ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0 AFTER icon'
        );
    }
}

/**
 * @return array{id:int,name:string,created:bool}
 */
function ensureDefaultHashtagFolder(int $ownerTelegramId): array
{
    ensureHashtagToolTables();
    $db = getDb();

    $stmt = $db->prepare(
        'SELECT id, name FROM hashtag_tool_folders
         WHERE owner_telegram_id = ? AND is_default = 1
         ORDER BY id ASC LIMIT 1'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $created = false;
    if (!$row) {
        $name = 'پوشه هشتگ';
        $icon = 'hashtag';
        $isDefault = 1;
        $stmt = $db->prepare(
            'INSERT INTO hashtag_tool_folders (owner_telegram_id, name, icon, is_default, parent_id)
             VALUES (?, ?, ?, ?, NULL)'
        );
        $stmt->bind_param('issi', $ownerTelegramId, $name, $icon, $isDefault);
        $stmt->execute();
        $folderId = (int) $stmt->insert_id;
        $stmt->close();
        $created = true;
    } else {
        $folderId = (int) $row['id'];
    }

    $stmt = $db->prepare(
        'UPDATE hashtag_post_sets SET folder_id = ?
         WHERE owner_telegram_id = ? AND (folder_id IS NULL OR folder_id = 0)'
    );
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $stmt->close();

    return ['id' => $folderId, 'name' => 'پوشه هشتگ', 'created' => $created];
}

function normalizeHashtagTag(string $tag): string
{
    $tag = trim($tag);
    if ($tag === '') {
        return '';
    }
    if (!str_starts_with($tag, '#')) {
        $tag = '#' . $tag;
    }

    return $tag;
}

/**
 * @return list<string>
 */
function decodeTriggerKeywords(?string $json): array
{
    if ($json === null || trim($json) === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $word) {
        $word = trim((string) $word);
        if ($word !== '') {
            $out[] = mb_strtolower($word, 'UTF-8');
        }
    }

    return array_values(array_unique($out));
}

/**
 * @param list<string> $keywords
 */
function encodeTriggerKeywords(array $keywords): string
{
    $clean = [];
    foreach ($keywords as $word) {
        $word = trim((string) $word);
        if ($word !== '') {
            $clean[] = $word;
        }
    }

    return json_encode(array_values(array_unique($clean)), JSON_UNESCAPED_UNICODE);
}

function captionMatchesKeywords(string $caption, array $keywords): bool
{
    if ($keywords === []) {
        return false;
    }
    $captionLower = mb_strtolower($caption, 'UTF-8');
    foreach ($keywords as $word) {
        if ($word !== '' && mb_strpos($captionLower, $word) !== false) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<array<string, mixed>>
 */
function getHashtagToolFolders(int $ownerTelegramId): array
{
    ensureHashtagToolTables();
    ensureDefaultHashtagFolder($ownerTelegramId);
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT f.*,
                (SELECT COUNT(*) FROM hashtag_post_sets s WHERE s.folder_id = f.id AND s.owner_telegram_id = ?) AS set_count,
                (SELECT COUNT(*) FROM hashtag_tool_folders c WHERE c.parent_id = f.id) AS subfolder_count
         FROM hashtag_tool_folders f
         WHERE f.owner_telegram_id = ?
         ORDER BY f.is_pinned DESC, f.pinned_at DESC, f.sort_order ASC, f.id ASC'
    );
    $stmt->bind_param('ii', $ownerTelegramId, $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'icon' => $row['icon'] ?: 'hashtag',
            'parent_id' => !empty($row['parent_id']) ? (int) $row['parent_id'] : null,
            'set_count' => (int) ($row['set_count'] ?? 0),
            'subfolder_count' => (int) ($row['subfolder_count'] ?? 0),
            'is_default' => (int) ($row['is_default'] ?? 0) === 1,
            'is_pinned' => (int) ($row['is_pinned'] ?? 0) === 1,
            'pinned_at' => $row['pinned_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
    $stmt->close();

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function getHashtagPostSets(int $ownerTelegramId): array
{
    ensureHashtagToolTables();
    ensureDefaultHashtagFolder($ownerTelegramId);
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT s.*, (SELECT COUNT(*) FROM hashtag_post_tags t WHERE t.set_id = s.id) AS tag_count
         FROM hashtag_post_sets s
         WHERE s.owner_telegram_id = ?
         ORDER BY s.is_pinned DESC, s.pinned_at DESC, s.sort_order ASC, s.id DESC'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $sets = [];
    while ($row = $result->fetch_assoc()) {
        $sets[] = formatHashtagPostSetRow($row);
    }
    $stmt->close();

    return $sets;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function formatHashtagPostSetRow(array $row): array
{
    $setId = (int) ($row['id'] ?? 0);
    $folderId = (int) ($row['channel_folder_id'] ?? 0);

    return [
        'id' => $setId,
        'name' => $row['name'] ?? '',
        'channel_folder_id' => $folderId,
        'channel_folder_path' => getChannelFolderPathLabel($folderId),
        'folder_id' => !empty($row['folder_id']) ? (int) $row['folder_id'] : null,
        'selection_mode' => $row['selection_mode'] ?? 'random',
        'selection_mode_label' => hashtagSelectionModeLabel((string) ($row['selection_mode'] ?? 'random')),
        'random_count' => (int) ($row['random_count'] ?? 5),
        'tag_count' => (int) ($row['tag_count'] ?? 0),
        'status' => $row['status'] ?? 'active',
        'is_pinned' => (int) ($row['is_pinned'] ?? 0) === 1,
        'pinned_at' => $row['pinned_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
        'tags' => getHashtagPostTags($setId),
    ];
}

function hashtagSelectionModeLabel(string $mode): string
{
    return match ($mode) {
        'all' => 'همه هشتگ‌ها',
        'smart' => 'هوشمند + تصادفی',
        default => 'تصادفی',
    };
}

/**
 * @return list<array<string, mixed>>
 */
function getHashtagPostTags(int $setId): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, tag_text, rule_mode, trigger_keywords, is_required, sort_order
         FROM hashtag_post_tags WHERE set_id = ? ORDER BY sort_order ASC, id ASC'
    );
    $stmt->bind_param('i', $setId);
    $stmt->execute();
    $result = $stmt->get_result();
    $tags = [];
    while ($row = $result->fetch_assoc()) {
        $tags[] = [
            'id' => (int) $row['id'],
            'tag_text' => $row['tag_text'],
            'rule_mode' => $row['rule_mode'] ?? 'pool',
            'trigger_keywords' => decodeTriggerKeywords($row['trigger_keywords'] ?? null),
            'is_required' => (int) ($row['is_required'] ?? 0) === 1,
            'sort_order' => (int) ($row['sort_order'] ?? 0),
        ];
    }
    $stmt->close();

    return $tags;
}

/**
 * @param list<array{tag_text:string,rule_mode?:string,trigger_keywords?:array,is_required?:bool}> $tags
 */
function createHashtagPostSet(
    int $ownerTelegramId,
    string $name,
    int $channelFolderId,
    string $selectionMode,
    int $randomCount,
    array $tags,
    ?int $folderId = null
): array {
    ensureHashtagToolTables();
    ensureChannelFolderTables();

    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    if (!channelFolderExists($channelFolderId)) {
        throw new InvalidArgumentException('invalid_channel_folder');
    }
    if ($tags === []) {
        throw new InvalidArgumentException('tags_required');
    }

    $selectionMode = in_array($selectionMode, ['all', 'random', 'smart'], true) ? $selectionMode : 'random';
    $randomCount = max(1, min(50, $randomCount));
    if ($folderId === null || $folderId <= 0) {
        $folderId = ensureDefaultHashtagFolder($ownerTelegramId)['id'];
    }

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO hashtag_post_sets
         (owner_telegram_id, name, channel_folder_id, folder_id, selection_mode, random_count)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isiisi', $ownerTelegramId, $name, $channelFolderId, $folderId, $selectionMode, $randomCount);
    $stmt->execute();
    $setId = (int) $stmt->insert_id;
    $stmt->close();

    saveHashtagPostTags($setId, $tags);

    return getHashtagPostSetById($ownerTelegramId, $setId) ?? ['id' => $setId];
}

/**
 * @param list<array{tag_text:string,rule_mode?:string,trigger_keywords?:array,is_required?:bool}> $tags
 */
function saveHashtagPostTags(int $setId, array $tags): void
{
    $db = getDb();
    $db->query('DELETE FROM hashtag_post_tags WHERE set_id = ' . (int) $setId);

    $sort = 0;
    foreach ($tags as $tag) {
        $text = normalizeHashtagTag((string) ($tag['tag_text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $ruleMode = (string) ($tag['rule_mode'] ?? 'pool');
        if (!in_array($ruleMode, ['pool', 'keyword'], true)) {
            $ruleMode = 'pool';
        }
        $keywords = encodeTriggerKeywords(is_array($tag['trigger_keywords'] ?? null) ? $tag['trigger_keywords'] : []);
        $required = !empty($tag['is_required']) ? 1 : 0;

        $stmt = $db->prepare(
            'INSERT INTO hashtag_post_tags (set_id, tag_text, rule_mode, trigger_keywords, is_required, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('isssii', $setId, $text, $ruleMode, $keywords, $required, $sort);
        $stmt->execute();
        $stmt->close();
        $sort++;
    }
}

/**
 * @return array<string, mixed>|null
 */
function getHashtagPostSetById(int $ownerTelegramId, int $setId): ?array
{
    ensureHashtagToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT s.*, (SELECT COUNT(*) FROM hashtag_post_tags t WHERE t.set_id = s.id) AS tag_count
         FROM hashtag_post_sets s WHERE s.id = ? AND s.owner_telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $setId, $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? formatHashtagPostSetRow($row) : null;
}

/**
 * @param list<array{tag_text:string,rule_mode?:string,trigger_keywords?:array,is_required?:bool}> $tags
 */
function updateHashtagPostSet(
    int $ownerTelegramId,
    int $setId,
    ?string $name = null,
    ?int $channelFolderId = null,
    ?string $selectionMode = null,
    ?int $randomCount = null,
    ?array $tags = null
): bool {
    ensureHashtagToolTables();
    $existing = getHashtagPostSetById($ownerTelegramId, $setId);
    if (!$existing) {
        return false;
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
    if ($channelFolderId !== null && $channelFolderId > 0) {
        if (!channelFolderExists($channelFolderId)) {
            throw new InvalidArgumentException('invalid_channel_folder');
        }
        $fields[] = 'channel_folder_id = ?';
        $types .= 'i';
        $values[] = $channelFolderId;
    }
    if ($selectionMode !== null) {
        $selectionMode = in_array($selectionMode, ['all', 'random', 'smart'], true) ? $selectionMode : 'random';
        $fields[] = 'selection_mode = ?';
        $types .= 's';
        $values[] = $selectionMode;
    }
    if ($randomCount !== null) {
        $randomCount = max(1, min(50, $randomCount));
        $fields[] = 'random_count = ?';
        $types .= 'i';
        $values[] = $randomCount;
    }

    if ($fields !== []) {
        $db = getDb();
        $sql = 'UPDATE hashtag_post_sets SET ' . implode(', ', $fields) . ' WHERE id = ? AND owner_telegram_id = ?';
        $types .= 'ii';
        $values[] = $setId;
        $values[] = $ownerTelegramId;
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }

    if ($tags !== null) {
        if ($tags === []) {
            throw new InvalidArgumentException('tags_required');
        }
        saveHashtagPostTags($setId, $tags);
    }

    return true;
}

function deleteHashtagPostSet(int $ownerTelegramId, int $setId): bool
{
    ensureHashtagToolTables();
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM hashtag_post_sets WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('ii', $setId, $ownerTelegramId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    if ($deleted) {
        $db->query('DELETE FROM hashtag_post_tags WHERE set_id = ' . (int) $setId);
    }

    return $deleted;
}

function createHashtagToolFolder(int $ownerTelegramId, string $name, string $icon = 'hashtag', ?int $parentId = null): array
{
    ensureHashtagToolTables();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    $parentId = $parentId !== null && $parentId > 0 ? $parentId : null;

    $db = getDb();
    if ($parentId === null) {
        $stmt = $db->prepare(
            'INSERT INTO hashtag_tool_folders (owner_telegram_id, name, icon, parent_id) VALUES (?, ?, ?, NULL)'
        );
        $stmt->bind_param('iss', $ownerTelegramId, $name, $icon);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO hashtag_tool_folders (owner_telegram_id, name, icon, parent_id) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $ownerTelegramId, $name, $icon, $parentId);
    }
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return ['id' => $id, 'name' => $name, 'icon' => $icon, 'parent_id' => $parentId];
}

function deleteHashtagToolFolder(int $ownerTelegramId, int $folderId): bool
{
    ensureHashtagToolTables();
    $db = getDb();
    $check = $db->prepare(
        'SELECT is_default FROM hashtag_tool_folders WHERE id = ? AND owner_telegram_id = ? LIMIT 1'
    );
    $check->bind_param('ii', $folderId, $ownerTelegramId);
    $check->execute();
    $folderRow = $check->get_result()->fetch_assoc();
    $check->close();
    if (!$folderRow) {
        return false;
    }
    if ((int) ($folderRow['is_default'] ?? 0) === 1) {
        throw new InvalidArgumentException('default_folder_protected');
    }

    $defaultFolderId = ensureDefaultHashtagFolder($ownerTelegramId)['id'];
    $stmt = $db->prepare('UPDATE hashtag_post_sets SET folder_id = ? WHERE folder_id = ?');
    $stmt->bind_param('ii', $defaultFolderId, $folderId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('DELETE FROM hashtag_tool_folders WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

function setHashtagPostSetPinned(int $ownerTelegramId, int $setId, bool $pinned): bool
{
    ensureHashtagToolTables();
    if (!getHashtagPostSetById($ownerTelegramId, $setId)) {
        throw new InvalidArgumentException('invalid_set');
    }

    return setExplorerEntityPinned('hashtag_post_sets', 'id', $setId, $pinned);
}

function setHashtagToolFolderPinned(int $ownerTelegramId, int $folderId, bool $pinned): bool
{
    ensureHashtagToolTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM hashtag_tool_folders WHERE id = ? AND owner_telegram_id = ? LIMIT 1');
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $exists = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$exists) {
        throw new InvalidArgumentException('invalid_folder');
    }

    return setExplorerEntityPinned('hashtag_tool_folders', 'id', $folderId, $pinned);
}

/**
 * @return list<int>
 */
function getChannelFolderIdsForChat(int $chatId): array
{
    ensureChannelFolderTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT folder_id FROM channel_folder_items WHERE chat_id = ?');
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $ids[] = (int) $row['folder_id'];
    }
    $stmt->close();

    return $ids;
}

/**
 * @return array<string, mixed>|null
 */
function findHashtagSetForChannelFolder(int $ownerTelegramId, int $folderId): ?array
{
    ensureHashtagToolTables();
    $db = getDb();
    $current = $folderId;
    $guard = 0;
    while ($current > 0 && $guard < 32) {
        $stmt = $db->prepare(
            "SELECT id FROM hashtag_post_sets
             WHERE owner_telegram_id = ? AND channel_folder_id = ? AND status = 'active'
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->bind_param('ii', $ownerTelegramId, $current);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            return getHashtagPostSetById($ownerTelegramId, (int) $row['id']);
        }

        $folders = getChannelFolders();
        $parent = null;
        foreach ($folders as $folder) {
            if ((int) $folder['id'] === $current) {
                $parent = normalizeChannelFolderParentId($folder['parent_id'] ?? null);
                break;
            }
        }
        $current = $parent ?? 0;
        $guard++;
    }

    return null;
}

/**
 * @param array<string, mixed> $set
 * @return list<string>
 */
function resolveHashtagsFromSet(array $set, string $caption): array
{
    $tags = $set['tags'] ?? [];
    if ($tags === []) {
        return [];
    }

    $mode = (string) ($set['selection_mode'] ?? 'random');
    $randomCount = max(1, (int) ($set['random_count'] ?? 5));
    $selected = [];
    $selectedKeys = [];
    $pool = [];

    foreach ($tags as $tag) {
        $text = normalizeHashtagTag((string) ($tag['tag_text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $ruleMode = (string) ($tag['rule_mode'] ?? 'pool');
        $keywords = is_array($tag['trigger_keywords'] ?? null) ? $tag['trigger_keywords'] : [];
        $matches = captionMatchesKeywords($caption, $keywords);

        if ($ruleMode === 'keyword' && $matches) {
            if (!isset($selectedKeys[$text])) {
                $selected[] = $text;
                $selectedKeys[$text] = true;
            }
            continue;
        }

        if (!empty($tag['is_required']) && $matches) {
            if (!isset($selectedKeys[$text])) {
                $selected[] = $text;
                $selectedKeys[$text] = true;
            }
            continue;
        }

        if ($ruleMode === 'pool' || $mode === 'all') {
            $pool[] = $text;
        }
    }

    if ($mode === 'all') {
        foreach ($pool as $text) {
            if (!isset($selectedKeys[$text])) {
                $selected[] = $text;
                $selectedKeys[$text] = true;
            }
        }

        return $selected;
    }

    shuffle($pool);
    $remaining = max(0, $randomCount - count($selected));
    foreach ($pool as $text) {
        if ($remaining <= 0) {
            break;
        }
        if (isset($selectedKeys[$text])) {
            continue;
        }
        $selected[] = $text;
        $selectedKeys[$text] = true;
        $remaining--;
    }

    return $selected;
}

function buildHashtagBlockForChannel(int $ownerTelegramId, int $channelChatId, string $caption): string
{
    $folderIds = getChannelFolderIdsForChat($channelChatId);
    if ($folderIds === []) {
        return '';
    }

    $allTags = [];
    $seen = [];
    foreach ($folderIds as $folderId) {
        $set = findHashtagSetForChannelFolder($ownerTelegramId, $folderId);
        if ($set === null) {
            continue;
        }
        foreach (resolveHashtagsFromSet($set, $caption) as $tag) {
            if (!isset($seen[$tag])) {
                $allTags[] = $tag;
                $seen[$tag] = true;
            }
        }
    }

    if ($allTags === []) {
        return '';
    }

    return implode("\n", $allTags);
}

function appendHashtagsToCaption(int $ownerTelegramId, int $channelChatId, string $caption): string
{
    $caption = trim($caption);
    $hashtags = buildHashtagBlockForChannel($ownerTelegramId, $channelChatId, $caption);
    if ($hashtags === '') {
        return $caption;
    }

    if ($caption === '') {
        return $hashtags;
    }

    return $caption . "\n\n" . $hashtags;
}
