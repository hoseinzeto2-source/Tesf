<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/telegram_api.php';

function ensureInviteLinkTables(bool $runMaintenance = false): void
{
    static $schemaReady = false;
    if ($schemaReady) {
        if ($runMaintenance) {
            runInviteLinkMaintenance();
        }
        return;
    }

    $db = getDb();
    $db->query(<<<SQL
CREATE TABLE IF NOT EXISTS channel_invite_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    name VARCHAR(255) NOT NULL,
    invite_url VARCHAR(512) NOT NULL,
    member_limit INT UNSIGNED DEFAULT NULL,
    join_count INT UNSIGNED NOT NULL DEFAULT 0,
    leave_count INT UNSIGNED NOT NULL DEFAULT 0,
    is_revoked TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_invite_url (invite_url),
    KEY idx_chat_created (chat_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $db->query(<<<SQL
CREATE TABLE IF NOT EXISTS channel_invite_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    invite_link_id INT UNSIGNED NOT NULL,
    telegram_user_id BIGINT NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    first_name VARCHAR(255) DEFAULT NULL,
    last_name VARCHAR(255) DEFAULT NULL,
    language_code VARCHAR(10) DEFAULT NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    left_at DATETIME DEFAULT NULL,
    KEY idx_chat_user_active (chat_id, telegram_user_id, left_at),
    KEY idx_link_joined (invite_link_id, joined_at),
    KEY idx_user_chat (telegram_user_id, chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

    $schemaReady = true;

    if ($runMaintenance) {
        runInviteLinkMaintenance();
    }
}

function runInviteLinkMaintenance(): void
{
    static $maintained = false;
    if ($maintained) {
        return;
    }
    $maintained = true;

    migrateInviteMembersToUsers();
    repairInviteUserSessions();
    recalculateInviteLinkCounts();
}

function repairInviteUserSessions(): void
{
    $db = getDb();
    $result = $db->query(
        'SELECT chat_id, telegram_user_id, COUNT(*) AS c
         FROM channel_invite_users
         WHERE left_at IS NULL
         GROUP BY chat_id, telegram_user_id
         HAVING c > 1'
    );
    if (!$result) {
        return;
    }

    while ($row = $result->fetch_assoc()) {
        $chatId = (int) $row['chat_id'];
        $userId = (int) $row['telegram_user_id'];
        $stmt = $db->prepare(
            'SELECT id FROM channel_invite_users
             WHERE chat_id = ? AND telegram_user_id = ? AND left_at IS NULL
             ORDER BY joined_at DESC'
        );
        $stmt->bind_param('ii', $chatId, $userId);
        $stmt->execute();
        $ids = [];
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $ids[] = (int) $r['id'];
        }
        $stmt->close();

        array_shift($ids);
        foreach ($ids as $duplicateId) {
            closeActiveInviteUserRow($duplicateId);
        }
    }
}

function migrateInviteMembersToUsers(): void
{
    $db = getDb();
    $result = $db->query('SHOW TABLES LIKE "channel_invite_members"');
    if (!$result || $result->num_rows === 0) {
        return;
    }

    $db->query(
        'INSERT IGNORE INTO channel_invite_users (chat_id, invite_link_id, telegram_user_id, joined_at, left_at)
         SELECT chat_id, invite_link_id, user_id, joined_at, left_at
         FROM channel_invite_members
         WHERE NOT EXISTS (
            SELECT 1 FROM channel_invite_users u
            WHERE u.chat_id = channel_invite_members.chat_id
              AND u.invite_link_id = channel_invite_members.invite_link_id
              AND u.telegram_user_id = channel_invite_members.user_id
              AND u.joined_at = channel_invite_members.joined_at
         )'
    );
}

function findInviteLinkIdByUrl(string $url): ?int
{
    ensureInviteLinkTables();
    $url = normalizeInviteUrl($url);
    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM channel_invite_links WHERE invite_url = ? LIMIT 1');
    $stmt->bind_param('s', $url);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : null;
}

function normalizeInviteUrl(string $url): string
{
    return rtrim(trim($url), '/');
}

function registerInviteLinkFromTelegram(int $chatId, array $invite): ?int
{
    $url = normalizeInviteUrl((string) ($invite['invite_link'] ?? ''));
    if ($url === '') {
        return null;
    }

    $existing = findInviteLinkIdByUrl($url);
    if ($existing) {
        return $existing;
    }

    ensureInviteLinkTables();
    $name = (string) ($invite['name'] ?? 'لینک تلگرام');
    $memberLimit = isset($invite['member_limit']) ? (int) $invite['member_limit'] : null;

    $db = getDb();
    if ($memberLimit !== null && $memberLimit > 0) {
        $stmt = $db->prepare(
            'INSERT INTO channel_invite_links (chat_id, name, invite_url, member_limit) VALUES (?, ?, ?, ?)'
        );
        $stmt->bind_param('issi', $chatId, $name, $url, $memberLimit);
    } else {
        $stmt = $db->prepare(
            'INSERT INTO channel_invite_links (chat_id, name, invite_url, member_limit) VALUES (?, ?, ?, NULL)'
        );
        $stmt->bind_param('iss', $chatId, $name, $url);
    }
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id ?: null;
}

function createChannelInviteLink(int $chatId, string $name, ?int $memberLimit = null): array
{
    ensureInviteLinkTables();

    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }

    $params = [
        'chat_id' => $chatId,
        'name' => $name,
    ];
    if ($memberLimit !== null && $memberLimit > 0) {
        $params['member_limit'] = $memberLimit;
    }

    $response = telegramRequest('createChatInviteLink', $params);
    if (empty($response['ok']) || empty($response['result']['invite_link'])) {
        throw new RuntimeException('telegram_create_failed');
    }

    $result = $response['result'];
    $linkId = registerInviteLinkFromTelegram($chatId, $result);
    if (!$linkId) {
        throw new RuntimeException('save_failed');
    }

    recalculateInviteLinkCounts($chatId);

    return formatInviteLinkRow(getInviteLinkRowById($linkId));
}

function getInviteLinkRowById(int $linkId): array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM channel_invite_links WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $linkId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    return $row;
}

function recalculateInviteLinkCounts(?int $chatId = null): void
{
    ensureInviteLinkTables();
    $db = getDb();

    if ($chatId !== null) {
        $stmt = $db->prepare(
            'UPDATE channel_invite_links l
             SET join_count = (
                    SELECT COUNT(*) FROM channel_invite_users u WHERE u.invite_link_id = l.id
                 ),
                 leave_count = (
                    SELECT COUNT(*) FROM channel_invite_users u WHERE u.invite_link_id = l.id AND u.left_at IS NOT NULL
                 )
             WHERE l.chat_id = ?'
        );
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $stmt->close();
        return;
    }

    $db->query(
        'UPDATE channel_invite_links l
         SET join_count = (
                SELECT COUNT(*) FROM channel_invite_users u WHERE u.invite_link_id = l.id
             ),
             leave_count = (
                SELECT COUNT(*) FROM channel_invite_users u WHERE u.invite_link_id = l.id AND u.left_at IS NOT NULL
             )'
    );
}

function listChannelInviteLinks(int $chatId, bool $withMembers = true): array
{
    ensureInviteLinkTables(false);
    recalculateInviteLinkCounts($chatId);

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, chat_id, name, invite_url, member_limit, join_count, leave_count, is_revoked, created_at
         FROM channel_invite_links
         WHERE chat_id = ? AND is_revoked = 0
         ORDER BY created_at DESC'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $formatted = formatInviteLinkRow($row);
        if ($withMembers) {
            $formatted['members'] = listInviteLinkUsers((int) $row['id']);
        }
        $rows[] = $formatted;
    }
    $stmt->close();

    return $rows;
}

function listInviteLinkUsers(int $inviteLinkId): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, chat_id, invite_link_id, telegram_user_id, username, first_name, last_name, language_code, joined_at, left_at
         FROM channel_invite_users
         WHERE invite_link_id = ?
         ORDER BY joined_at DESC
         LIMIT 200'
    );
    $stmt->bind_param('i', $inviteLinkId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = formatInviteUserRow($row);
    }
    $stmt->close();

    return $rows;
}

function formatInviteLinkRow(array $row): array
{
    $joins = (int) ($row['join_count'] ?? 0);
    $leaves = (int) ($row['leave_count'] ?? 0);

    return [
        'id' => (int) $row['id'],
        'chat_id' => (int) $row['chat_id'],
        'name' => $row['name'],
        'invite_url' => $row['invite_url'],
        'member_limit' => $row['member_limit'] !== null ? (int) $row['member_limit'] : null,
        'join_count' => $joins,
        'leave_count' => $leaves,
        'net_count' => $joins - $leaves,
        'is_revoked' => !empty($row['is_revoked']),
        'created_at' => $row['created_at'],
    ];
}

function formatInviteUserRow(array $row): array
{
    $name = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
    if ($name === '') {
        $name = 'کاربر';
    }

    return [
        'id' => (int) $row['id'],
        'invite_link_id' => (int) $row['invite_link_id'],
        'telegram_user_id' => (int) $row['telegram_user_id'],
        'username' => $row['username'] ?? null,
        'first_name' => $row['first_name'] ?? null,
        'last_name' => $row['last_name'] ?? null,
        'display_name' => $name,
        'language_code' => $row['language_code'] ?? null,
        'joined_at' => $row['joined_at'],
        'left_at' => $row['left_at'],
        'is_active' => empty($row['left_at']),
    ];
}

function getActiveInviteUserRow(int $chatId, int $userId): ?array
{
    ensureInviteLinkTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM channel_invite_users
         WHERE chat_id = ? AND telegram_user_id = ? AND left_at IS NULL
         ORDER BY joined_at DESC LIMIT 1'
    );
    $stmt->bind_param('ii', $chatId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function closeActiveInviteUserRow(int $rowId): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE channel_invite_users SET left_at = NOW() WHERE id = ? AND left_at IS NULL');
    $stmt->bind_param('i', $rowId);
    $stmt->execute();
    $stmt->close();
}

function getOrCreateUnknownInviteLink(int $chatId): int
{
    ensureInviteLinkTables(false);
    $url = 'internal://unknown/' . $chatId;
    $existing = findInviteLinkIdByUrl($url);
    if ($existing) {
        return $existing;
    }

    $name = 'لینک نامشخص (تلگرام اعلام نکرد)';
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO channel_invite_links (chat_id, name, invite_url, member_limit) VALUES (?, ?, ?, NULL)'
    );
    $stmt->bind_param('iss', $chatId, $name, $url);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
}

function guessInviteLinkIdForJoin(int $chatId, int $userId): ?int
{
    ensureInviteLinkTables(false);
    $db = getDb();

    $stmt = $db->prepare(
        'SELECT invite_link_id, left_at FROM channel_invite_users
         WHERE chat_id = ? AND telegram_user_id = ? AND left_at IS NOT NULL
         ORDER BY left_at DESC LIMIT 1'
    );
    $stmt->bind_param('ii', $chatId, $userId);
    $stmt->execute();
    $last = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($last) {
        $lastLinkId = (int) $last['invite_link_id'];
        $leftAt = strtotime((string) $last['left_at']);
        if ($leftAt > time() - 7200) {
            $stmt = $db->prepare(
                'SELECT id FROM channel_invite_links
                 WHERE chat_id = ? AND id != ? AND is_revoked = 0 AND invite_url NOT LIKE "internal://%"
                 ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->bind_param('ii', $chatId, $lastLinkId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($row) {
                return (int) $row['id'];
            }
        }
    }

    $stmt = $db->prepare(
        'SELECT id FROM channel_invite_links
         WHERE chat_id = ? AND is_revoked = 0 AND invite_url NOT LIKE "internal://%"
         ORDER BY created_at DESC LIMIT 1'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? (int) $row['id'] : null;
}

function resolveInviteLinkIdForJoin(int $chatId, int $userId, ?array $inviteLinkPayload): int
{
    if ($inviteLinkPayload && !empty($inviteLinkPayload['invite_link'])) {
        $linkId = registerInviteLinkFromTelegram($chatId, $inviteLinkPayload);
        if ($linkId) {
            return $linkId;
        }
    }

    $guessed = guessInviteLinkIdForJoin($chatId, $userId);
    if ($guessed) {
        return $guessed;
    }

    return getOrCreateUnknownInviteLink($chatId);
}

function insertInviteUserMembership(int $chatId, int $linkId, array $profile): void
{
    $db = getDb();
    $userId = (int) $profile['id'];
    $username = $profile['username'];
    $firstName = $profile['first_name'];
    $lastName = $profile['last_name'];
    $languageCode = $profile['language_code'];

    $stmt = $db->prepare(
        'INSERT INTO channel_invite_users
         (chat_id, invite_link_id, telegram_user_id, username, first_name, last_name, language_code)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iiissss',
        $chatId,
        $linkId,
        $userId,
        $username,
        $firstName,
        $lastName,
        $languageCode
    );
    $stmt->execute();
    $stmt->close();
}

function extractTelegramUserProfile(array $member, ?array $from = null): array
{
    $user = $member['user'] ?? $member;
    if (is_array($from)) {
        foreach (['id', 'username', 'first_name', 'last_name', 'language_code'] as $key) {
            if (empty($user[$key]) && isset($from[$key]) && $from[$key] !== '') {
                $user[$key] = $from[$key];
            }
        }
    }

    return [
        'id' => (int) ($user['id'] ?? 0),
        'username' => $user['username'] ?? null,
        'first_name' => $user['first_name'] ?? null,
        'last_name' => $user['last_name'] ?? null,
        'language_code' => $user['language_code'] ?? null,
    ];
}

function trackInviteLinkJoin(int $chatId, array $newMember, ?array $inviteLinkPayload, ?array $from = null): void
{
    $profile = extractTelegramUserProfile($newMember, $from);
    $userId = (int) ($profile['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    ensureInviteLinkTables(false);
    $linkId = resolveInviteLinkIdForJoin($chatId, $userId, $inviteLinkPayload);

    $active = getActiveInviteUserRow($chatId, $userId);
    if ($active) {
        if ((int) $active['invite_link_id'] === $linkId) {
            return;
        }
        closeActiveInviteUserRow((int) $active['id']);
        recalculateInviteLinkCounts($chatId);
    }

    insertInviteUserMembership($chatId, $linkId, $profile);
    recalculateInviteLinkCounts($chatId);
}

function trackInviteLinkLeave(int $chatId, int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    ensureInviteLinkTables();
    $active = getActiveInviteUserRow($chatId, $userId);
    if (!$active) {
        return;
    }

    closeActiveInviteUserRow((int) $active['id']);
    recalculateInviteLinkCounts($chatId);
}

function handleInviteLinkChatMember(array $payload): void
{
    $chat = $payload['chat'] ?? [];
    $chatId = (int) ($chat['id'] ?? 0);
    if ($chatId === 0) {
        return;
    }

    $oldStatus = (string) ($payload['old_chat_member']['status'] ?? '');
    $newStatus = (string) ($payload['new_chat_member']['status'] ?? '');
    $userId = (int) ($payload['new_chat_member']['user']['id'] ?? 0);

    if (in_array($newStatus, ['left', 'kicked'], true)
        && in_array($oldStatus, ['member', 'administrator', 'restricted'], true)) {
        trackInviteLinkLeave($chatId, $userId);
        return;
    }

    if (in_array($newStatus, ['member', 'administrator', 'restricted'], true)
        && in_array($oldStatus, ['left', 'kicked', ''], true)) {
        trackInviteLinkJoin(
            $chatId,
            $payload['new_chat_member'] ?? [],
            $payload['invite_link'] ?? null,
            $payload['from'] ?? null
        );
    }
}
