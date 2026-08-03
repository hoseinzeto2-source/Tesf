<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/child_bots.php';
require_once __DIR__ . '/bot_folders.php';
require_once __DIR__ . '/manage_bot_membership.php';

function defaultUserPlainStartMessage(): string
{
    return "<b>👋 سلام خیلی خوش اومدید</b>\n<blockquote>🗃 دوباره با لینک وارد ربات بشید</blockquote>";
}

function ensureBotJoinTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS uploader_settings (
    child_bot_id INT UNSIGNED NOT NULL DEFAULT 0,
    setting_key VARCHAR(64) NOT NULL,
    setting_value TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (child_bot_id, setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $col = $db->query("SHOW COLUMNS FROM uploader_settings LIKE 'child_bot_id'");
    if ($col && $col->num_rows === 0) {
        $db->query('ALTER TABLE uploader_settings ADD COLUMN child_bot_id INT UNSIGNED NOT NULL DEFAULT 0 FIRST');
        $pk = $db->query("SHOW INDEX FROM uploader_settings WHERE Key_name = 'PRIMARY'");
        if ($pk && $pk->num_rows > 0) {
            $db->query('ALTER TABLE uploader_settings DROP PRIMARY KEY, ADD PRIMARY KEY (child_bot_id, setting_key)');
        }
    }

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS uploader_forced_joins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_bot_id INT UNSIGNED NOT NULL DEFAULT 0,
    channel_id VARCHAR(64) NOT NULL,
    link VARCHAR(512) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_child_link (child_bot_id, link),
    KEY idx_child_status (child_bot_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS uploader_fake_joins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_bot_id INT UNSIGNED NOT NULL DEFAULT 0,
    link VARCHAR(512) NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_child_fake_link (child_bot_id, link),
    KEY idx_child_status (child_bot_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    foreach (['uploader_forced_joins', 'uploader_fake_joins'] as $table) {
        $c = $db->query("SHOW COLUMNS FROM {$table} LIKE 'child_bot_id'");
        if ($c && $c->num_rows === 0) {
            $db->query("ALTER TABLE {$table} ADD COLUMN child_bot_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER id");
        }
    }

    $legacy = $db->query("SHOW INDEX FROM uploader_forced_joins WHERE Key_name = 'uniq_link'");
    if ($legacy && $legacy->num_rows > 0) {
        $db->query('ALTER TABLE uploader_forced_joins DROP INDEX uniq_link');
    }
    $newIdx = $db->query("SHOW INDEX FROM uploader_forced_joins WHERE Key_name = 'uniq_child_link'");
    if (!$newIdx || $newIdx->num_rows === 0) {
        $db->query('ALTER TABLE uploader_forced_joins ADD UNIQUE KEY uniq_child_link (child_bot_id, link)');
    }

    $legacyFake = $db->query("SHOW INDEX FROM uploader_fake_joins WHERE Key_name = 'uniq_link'");
    if ($legacyFake && $legacyFake->num_rows > 0) {
        $db->query('ALTER TABLE uploader_fake_joins DROP INDEX uniq_link');
    }
    $newFakeIdx = $db->query("SHOW INDEX FROM uploader_fake_joins WHERE Key_name = 'uniq_child_fake_link'");
    if (!$newFakeIdx || $newFakeIdx->num_rows === 0) {
        $db->query('ALTER TABLE uploader_fake_joins ADD UNIQUE KEY uniq_child_fake_link (child_bot_id, link)');
    }
}

function setChildBotStartMessage(int $childBotId, string $text): void
{
    ensureBotJoinTables();
    $db = getDb();
    $key = 'user_plain_start_message';
    $stmt = $db->prepare(
        'INSERT INTO uploader_settings (child_bot_id, setting_key, setting_value) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->bind_param('iss', $childBotId, $key, $text);
    $stmt->execute();
    $stmt->close();
}

function assertChildBotOwned(int $ownerTelegramId, int $botId): array
{
    ensureChildBotTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, bot_name, bot_username, bot_type FROM child_bots WHERE id = ? AND owner_telegram_id = ? AND status = "active" LIMIT 1'
    );
    $stmt->bind_param('ii', $botId, $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new InvalidArgumentException('bot_not_found');
    }

    return $row;
}

function assertBotFolderOwned(int $ownerTelegramId, int $folderId): array
{
    ensureBotFolderTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT id, name FROM bot_folders WHERE id = ? AND owner_telegram_id = ? LIMIT 1');
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        throw new InvalidArgumentException('invalid_folder');
    }

    return $row;
}

/**
 * @return list<int>
 */
function getBotIdsInFolder(int $ownerTelegramId, int $folderId): array
{
    assertBotFolderOwned($ownerTelegramId, $folderId);
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT i.bot_id
         FROM bot_folder_items i
         INNER JOIN bot_folders f ON f.id = i.folder_id
         INNER JOIN child_bots cb ON cb.id = i.bot_id
         WHERE i.folder_id = ? AND f.owner_telegram_id = ? AND cb.status = "active"
         ORDER BY i.bot_id ASC'
    );
    $stmt->bind_param('ii', $folderId, $ownerTelegramId);
    $stmt->execute();
    $res = $stmt->get_result();
    $ids = [];
    while ($row = $res->fetch_assoc()) {
        $ids[] = (int) $row['bot_id'];
    }
    $stmt->close();

    return $ids;
}

function getBotJoinSettings(int $ownerTelegramId, int $botId): array
{
    assertChildBotOwned($ownerTelegramId, $botId);
    ensureBotJoinTables();
    $db = getDb();

    $forced = [];
    $stmt = $db->prepare(
        "SELECT id, channel_id, link, created_at FROM uploader_forced_joins WHERE child_bot_id = ? AND status = 'active' ORDER BY id"
    );
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $forced[] = [
            'id' => (int) $row['id'],
            'channel_id' => $row['channel_id'],
            'link' => $row['link'],
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    $fake = [];
    $stmt = $db->prepare(
        "SELECT id, link, created_at FROM uploader_fake_joins WHERE child_bot_id = ? AND status = 'active' ORDER BY id"
    );
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $fake[] = [
            'id' => (int) $row['id'],
            'link' => $row['link'],
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    return [
        'forced_joins' => $forced,
        'fake_joins' => $fake,
    ];
}

function addBotForcedJoin(int $ownerTelegramId, int $botId, string $channelId, string $link): void
{
    assertChildBotOwned($ownerTelegramId, $botId);
    ensureBotJoinTables();
    $channelId = trim($channelId);
    $link = trim($link);
    if ($channelId === '' || $link === '') {
        throw new InvalidArgumentException('fields_required');
    }
    assertManageBotChannelAdmin($channelId);
    $db = getDb();
    $stmt = $db->prepare(
        "INSERT INTO uploader_forced_joins (child_bot_id, channel_id, link, status) VALUES (?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE channel_id = VALUES(channel_id), status = 'active'"
    );
    $stmt->bind_param('iss', $botId, $channelId, $link);
    $stmt->execute();
    $stmt->close();
}

function clearFakeJoinVerificationsForBot(int $botId, ?int $fakeId = null): int
{
    ensureBotJoinTables();
    $db = getDb();
    if ($fakeId !== null && $fakeId > 0) {
        $stmt = $db->prepare('DELETE FROM uploader_user_fake_joins WHERE fake_id = ?');
        $stmt->bind_param('i', $fakeId);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        return $deleted;
    }

    $stmt = $db->prepare(
        'DELETE uf FROM uploader_user_fake_joins uf
         INNER JOIN uploader_fake_joins fj ON fj.id = uf.fake_id
         WHERE fj.child_bot_id = ?'
    );
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $deleted = $stmt->affected_rows;
    $stmt->close();

    return $deleted;
}

function addBotFakeJoin(int $ownerTelegramId, int $botId, string $link): void
{
    assertChildBotOwned($ownerTelegramId, $botId);
    ensureBotJoinTables();
    $link = trim($link);
    if ($link === '') {
        throw new InvalidArgumentException('link_required');
    }
    $db = getDb();
    $stmt = $db->prepare(
        "INSERT INTO uploader_fake_joins (child_bot_id, link, status) VALUES (?, ?, 'active')
         ON DUPLICATE KEY UPDATE status = 'active'"
    );
    $stmt->bind_param('is', $botId, $link);
    $stmt->execute();
    $stmt->close();

    $fakeId = 0;
    $stmt = $db->prepare('SELECT id FROM uploader_fake_joins WHERE child_bot_id = ? AND link = ? LIMIT 1');
    $stmt->bind_param('is', $botId, $link);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $fakeId = (int) $row['id'];
    }
    if ($fakeId > 0) {
        clearFakeJoinVerificationsForBot($botId, $fakeId);
    }
}

function removeBotForcedJoin(int $ownerTelegramId, int $botId, string $link): bool
{
    assertChildBotOwned($ownerTelegramId, $botId);
    ensureBotJoinTables();
    $db = getDb();
    $stmt = $db->prepare("UPDATE uploader_forced_joins SET status = 'inactive' WHERE child_bot_id = ? AND link = ?");
    $stmt->bind_param('is', $botId, $link);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

function removeBotFakeJoin(int $ownerTelegramId, int $botId, string $link): bool
{
    assertChildBotOwned($ownerTelegramId, $botId);
    ensureBotJoinTables();
    $link = trim($link);
    $db = getDb();
    $fakeId = 0;
    $stmt = $db->prepare('SELECT id FROM uploader_fake_joins WHERE child_bot_id = ? AND link = ? LIMIT 1');
    $stmt->bind_param('is', $botId, $link);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $fakeId = (int) $row['id'];
    }
    $stmt = $db->prepare("UPDATE uploader_fake_joins SET status = 'inactive' WHERE child_bot_id = ? AND link = ?");
    $stmt->bind_param('is', $botId, $link);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    if ($fakeId > 0) {
        clearFakeJoinVerificationsForBot($botId, $fakeId);
    }

    return $ok;
}

function clearBotJoins(int $ownerTelegramId, int $botId, string $type = 'all'): void
{
    assertChildBotOwned($ownerTelegramId, $botId);
    ensureBotJoinTables();
    $db = getDb();
    if ($type === 'all' || $type === 'forced') {
        $stmt = $db->prepare("UPDATE uploader_forced_joins SET status = 'inactive' WHERE child_bot_id = ? AND status = 'active'");
        $stmt->bind_param('i', $botId);
        $stmt->execute();
        $stmt->close();
    }
    if ($type === 'all' || $type === 'fake') {
        clearFakeJoinVerificationsForBot($botId);
        $stmt = $db->prepare("UPDATE uploader_fake_joins SET status = 'inactive' WHERE child_bot_id = ? AND status = 'active'");
        $stmt->bind_param('i', $botId);
        $stmt->execute();
        $stmt->close();
    }
}

function resetAllFakeJoinVerifications(): int
{
    ensureBotJoinTables();
    $db = getDb();
    $db->query('DELETE FROM uploader_user_fake_joins');

    return (int) $db->affected_rows;
}

function getFolderJoinSettings(int $ownerTelegramId, int $folderId): array
{
    $folder = assertBotFolderOwned($ownerTelegramId, $folderId);
    $botIds = getBotIdsInFolder($ownerTelegramId, $folderId);
    if ($botIds === []) {
        return [
            'folder' => $folder,
            'bot_count' => 0,
            'forced_joins' => [],
            'fake_joins' => [],
        ];
    }

    ensureBotJoinTables();
    $db = getDb();
    $placeholders = implode(',', array_fill(0, count($botIds), '?'));
    $types = str_repeat('i', count($botIds));

    $forced = [];
    $sqlForced = "SELECT MIN(id) AS id, channel_id, link, COUNT(DISTINCT child_bot_id) AS bot_count, MIN(created_at) AS created_at
                  FROM uploader_forced_joins
                  WHERE child_bot_id IN ({$placeholders}) AND status = 'active'
                  GROUP BY channel_id, link
                  ORDER BY id ASC";
    $stmt = $db->prepare($sqlForced);
    $stmt->bind_param($types, ...$botIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $forced[] = [
            'id' => (int) $row['id'],
            'channel_id' => $row['channel_id'],
            'link' => $row['link'],
            'bot_count' => (int) $row['bot_count'],
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    $fake = [];
    $sqlFake = "SELECT MIN(id) AS id, link, COUNT(DISTINCT child_bot_id) AS bot_count, MIN(created_at) AS created_at
                FROM uploader_fake_joins
                WHERE child_bot_id IN ({$placeholders}) AND status = 'active'
                GROUP BY link
                ORDER BY id ASC";
    $stmt = $db->prepare($sqlFake);
    $stmt->bind_param($types, ...$botIds);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $fake[] = [
            'id' => (int) $row['id'],
            'link' => $row['link'],
            'bot_count' => (int) $row['bot_count'],
            'created_at' => $row['created_at'],
        ];
    }
    $stmt->close();

    return [
        'folder' => $folder,
        'bot_count' => count($botIds),
        'forced_joins' => $forced,
        'fake_joins' => $fake,
    ];
}

function removeFolderForcedJoin(int $ownerTelegramId, int $folderId, string $link): int
{
    $link = trim($link);
    if ($link === '') {
        throw new InvalidArgumentException('link_required');
    }
    $botIds = getBotIdsInFolder($ownerTelegramId, $folderId);
    $removed = 0;
    foreach ($botIds as $botId) {
        if (removeBotForcedJoin($ownerTelegramId, $botId, $link)) {
            $removed++;
        }
    }

    return $removed;
}

function removeFolderFakeJoin(int $ownerTelegramId, int $folderId, string $link): int
{
    $link = trim($link);
    if ($link === '') {
        throw new InvalidArgumentException('link_required');
    }
    $botIds = getBotIdsInFolder($ownerTelegramId, $folderId);
    $removed = 0;
    foreach ($botIds as $botId) {
        if (removeBotFakeJoin($ownerTelegramId, $botId, $link)) {
            $removed++;
        }
    }

    return $removed;
}

function applyJoinsToFolderBots(int $ownerTelegramId, int $folderId, ?array $forcedJoin = null, ?array $fakeJoin = null, string $mode = 'add'): array
{
    $botIds = getBotIdsInFolder($ownerTelegramId, $folderId);
    if ($botIds === []) {
        throw new InvalidArgumentException('folder_empty');
    }

    $updated = 0;
    foreach ($botIds as $botId) {
        if ($mode === 'clear_forced') {
            clearBotJoins($ownerTelegramId, $botId, 'forced');
            $updated++;
            continue;
        }
        if ($mode === 'clear_fake') {
            clearBotJoins($ownerTelegramId, $botId, 'fake');
            $updated++;
            continue;
        }
        if ($mode === 'clear_all') {
            clearBotJoins($ownerTelegramId, $botId, 'all');
            $updated++;
            continue;
        }
        if ($forcedJoin !== null) {
            addBotForcedJoin(
                $ownerTelegramId,
                $botId,
                (string) ($forcedJoin['channel_id'] ?? ''),
                (string) ($forcedJoin['link'] ?? '')
            );
            $updated++;
        }
        if ($fakeJoin !== null) {
            addBotFakeJoin($ownerTelegramId, $botId, (string) ($fakeJoin['link'] ?? ''));
            $updated++;
        }
    }

    return ['bot_count' => count($botIds), 'updated' => $updated];
}

function updateChildBotName(int $ownerTelegramId, int $botId, string $name): bool
{
    assertChildBotOwned($ownerTelegramId, $botId);
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }
    $db = getDb();
    $stmt = $db->prepare('UPDATE child_bots SET bot_name = ?, updated_at = NOW() WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('sii', $name, $botId, $ownerTelegramId);
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();

    return $ok;
}

function seedDefaultStartMessagesForAllChildBots(): int
{
    ensureBotJoinTables();
    $default = defaultUserPlainStartMessage();
    $db = getDb();
    $res = $db->query("SELECT id FROM child_bots WHERE bot_type = 'uploader' AND status = 'active'");
    $count = 0;
    while ($row = $res->fetch_assoc()) {
        setChildBotStartMessage((int) $row['id'], $default);
        $count++;
    }

    return $count;
}

function initializeChildBotUploaderDefaults(int $childBotId): void
{
    if ($childBotId <= 0) {
        return;
    }
    setChildBotStartMessage($childBotId, defaultUserPlainStartMessage());
}
