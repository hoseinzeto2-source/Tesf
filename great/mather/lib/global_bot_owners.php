<?php

if (!function_exists('getDb')) {
    require_once dirname(__DIR__) . '/db.php';
}

function ensureGlobalBotOwnerTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS global_bot_owners (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    display_name VARCHAR(255) DEFAULT NULL,
    added_by BIGINT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_telegram_id (telegram_id),
    KEY idx_active (is_active),
    KEY idx_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function normalizeOwnerUsername(string $username): string
{
    $username = trim($username);
    if ($username !== '' && $username[0] === '@') {
        $username = substr($username, 1);
    }

    return strtolower($username);
}

function findTelegramUserByUsername(string $username): ?array
{
    $username = normalizeOwnerUsername($username);
    if ($username === '') {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT telegram_id, username, first_name, last_name
         FROM users
         WHERE LOWER(username) = ?
         ORDER BY last_seen_at DESC
         LIMIT 1'
    );
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function resolveGlobalBotOwnerInput(string $input): array
{
    $input = trim($input);
    if ($input === '') {
        throw new InvalidArgumentException('owner_input_required');
    }

    if (preg_match('/^-?\d+$/', $input) === 1) {
        $telegramId = (int) $input;
        if ($telegramId <= 0) {
            throw new InvalidArgumentException('invalid_telegram_id');
        }

        return [
            'telegram_id' => $telegramId,
            'username' => null,
            'display_name' => null,
        ];
    }

    $username = normalizeOwnerUsername($input);
    if ($username === '' || !preg_match('/^[a-z0-9_]{3,32}$/i', $username)) {
        throw new InvalidArgumentException('invalid_username');
    }

    $user = findTelegramUserByUsername($username);
    if (!$user) {
        throw new InvalidArgumentException('username_not_found');
    }

    $displayName = trim((string) ($user['first_name'] ?? ''));
    $lastName = trim((string) ($user['last_name'] ?? ''));
    if ($lastName !== '') {
        $displayName = trim($displayName . ' ' . $lastName);
    }

    return [
        'telegram_id' => (int) $user['telegram_id'],
        'username' => $user['username'] ?? $username,
        'display_name' => $displayName !== '' ? $displayName : null,
    ];
}

function formatGlobalBotOwnerRow(array $row): array
{
    $username = trim((string) ($row['username'] ?? ''));
    $displayName = trim((string) ($row['display_name'] ?? ''));

    return [
        'id' => (int) $row['id'],
        'telegram_id' => (int) $row['telegram_id'],
        'username' => $username !== '' ? $username : null,
        'display_name' => $displayName !== '' ? $displayName : null,
        'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        'created_at' => $row['created_at'] ?? null,
    ];
}

function listGlobalBotOwners(bool $activeOnly = true): array
{
    ensureGlobalBotOwnerTables();
    $db = getDb();
    $sql = 'SELECT * FROM global_bot_owners';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY id ASC';

    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = formatGlobalBotOwnerRow($row);
    }

    return $rows;
}

function getGlobalBotOwnerTelegramIds(): array
{
    ensureGlobalBotOwnerTables();
    $db = getDb();
    $result = $db->query('SELECT telegram_id FROM global_bot_owners WHERE is_active = 1 ORDER BY id ASC');
    $ids = [];
    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['telegram_id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return $ids;
}

function getGlobalBotOwnerById(int $id): ?array
{
    ensureGlobalBotOwnerTables();
    if ($id <= 0) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM global_bot_owners WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? formatGlobalBotOwnerRow($row) : null;
}

function enrichOwnerProfile(int $telegramId, ?string $username, ?string $displayName): array
{
    if ($username !== null && trim($username) !== '' && $displayName !== null && trim($displayName) !== '') {
        return [$username, $displayName];
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT username, first_name, last_name FROM users WHERE telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $telegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return [$username, $displayName];
    }

    if (($username === null || trim($username) === '') && !empty($row['username'])) {
        $username = (string) $row['username'];
    }

    if ($displayName === null || trim($displayName) === '') {
        $displayName = trim((string) ($row['first_name'] ?? ''));
        $lastName = trim((string) ($row['last_name'] ?? ''));
        if ($lastName !== '') {
            $displayName = trim($displayName . ' ' . $lastName);
        }
        if ($displayName === '') {
            $displayName = null;
        }
    }

    return [$username, $displayName];
}

function addGlobalBotOwner(string $input, int $addedBy = 0, ?string $displayName = null): array
{
    ensureGlobalBotOwnerTables();
    $resolved = resolveGlobalBotOwnerInput($input);
    $telegramId = (int) $resolved['telegram_id'];
    $username = $resolved['username'] ?? null;
    if ($displayName !== null && trim($displayName) !== '') {
        $displayName = trim($displayName);
    } else {
        $displayName = $resolved['display_name'] ?? null;
    }

    [$username, $displayName] = enrichOwnerProfile($telegramId, $username, $displayName);

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id FROM global_bot_owners WHERE telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $telegramId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $ownerId = (int) $existing['id'];
        $stmt = $db->prepare(
            'UPDATE global_bot_owners
             SET username = COALESCE(?, username),
                 display_name = COALESCE(?, display_name),
                 added_by = CASE WHEN ? > 0 THEN ? ELSE added_by END,
                 is_active = 1,
                 updated_at = NOW()
             WHERE id = ?'
        );
        $stmt->bind_param('ssiii', $username, $displayName, $addedBy, $addedBy, $ownerId);
        $stmt->execute();
        $stmt->close();

        return getGlobalBotOwnerById($ownerId) ?? [];
    }

    $stmt = $db->prepare(
        'INSERT INTO global_bot_owners (telegram_id, username, display_name, added_by, is_active)
         VALUES (?, ?, ?, ?, 1)'
    );
    $addedByParam = $addedBy > 0 ? $addedBy : null;
    $stmt->bind_param('issi', $telegramId, $username, $displayName, $addedByParam);
    $stmt->execute();
    $ownerId = (int) $stmt->insert_id;
    $stmt->close();

    return getGlobalBotOwnerById($ownerId) ?? [];
}

function removeGlobalBotOwner(int $id): bool
{
    ensureGlobalBotOwnerTables();
    if ($id <= 0) {
        return false;
    }

    $db = getDb();
    $stmt = $db->prepare('UPDATE global_bot_owners SET is_active = 0, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $changed = $stmt->affected_rows > 0;
    $stmt->close();

    return $changed;
}
