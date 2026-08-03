<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/telegram_api.php';

const MANAGE_BOT_CHANNEL_LIMIT = 500;

function manageBotsBaseUrl(): string
{
    global $base_url;

    return rtrim($base_url ?? 'https://shombol.s16.viptelbot.top/great/mather', '/');
}

function ensureManageBotTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS manage_bots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    bot_token TEXT NOT NULL,
    bot_telegram_id BIGINT NOT NULL,
    bot_username VARCHAR(255) DEFAULT NULL,
    bot_name VARCHAR(255) DEFAULT NULL,
    webhook_key VARCHAR(64) NOT NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    channel_count INT UNSIGNED NOT NULL DEFAULT 0,
    health_status VARCHAR(32) NOT NULL DEFAULT 'ok',
    health_message VARCHAR(255) DEFAULT NULL,
    last_health_check DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_manage_bot_telegram_id (bot_telegram_id),
    UNIQUE KEY uniq_manage_webhook_key (webhook_key),
    KEY idx_status_primary (status, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS bot_channel_manage_bots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_chat_id BIGINT NOT NULL,
    manage_bot_id INT UNSIGNED NOT NULL,
    bot_status VARCHAR(30) NOT NULL DEFAULT 'administrator',
    is_primary_binding TINYINT(1) NOT NULL DEFAULT 0,
    last_seen_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_channel_manage_bot (channel_chat_id, manage_bot_id),
    KEY idx_manage_bot (manage_bot_id),
    KEY idx_channel (channel_chat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    ensureBotChannelsManageBotColumn();
}

function ensureBotChannelsManageBotColumn(): void
{
    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM bot_channels LIKE 'manage_bot_id'");
    if ($result && $result->num_rows === 0) {
        $db->query('ALTER TABLE bot_channels ADD COLUMN manage_bot_id INT UNSIGNED NULL DEFAULT NULL AFTER bot_status, ADD KEY idx_manage_bot_id (manage_bot_id)');
    }
}

function buildManageBotWebhookUrl(string $webhookKey): string
{
    return manageBotsBaseUrl() . '/manage_bot.php?key=' . urlencode($webhookKey);
}

function buildManageBotWebhookUrlForRow(array $row): string
{
    if ((int) ($row['is_primary'] ?? 0) === 1) {
        return manageBotsBaseUrl() . '/index.php';
    }

    return buildManageBotWebhookUrl((string) ($row['webhook_key'] ?? ''));
}

/**
 * @return array{synced: list<int>, errors: list<int>}
 */
function syncAllManageBotWebhooks(): array
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $synced = [];
    $errors = [];
    $db = getDb();
    $result = $db->query('SELECT * FROM manage_bots WHERE status = "active"');
    if (!$result) {
        return ['synced' => [], 'errors' => []];
    }

    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        $token = trim((string) ($row['bot_token'] ?? ''));
        if ($id <= 0 || $token === '') {
            continue;
        }

        $url = buildManageBotWebhookUrlForRow($row);
        if (setManageBotWebhook($token, $url)) {
            $synced[] = $id;
        } else {
            $errors[] = $id;
        }
    }

    return ['synced' => $synced, 'errors' => $errors];
}

function manageBotAllowedUpdates(): array
{
    return [
        'message',
        'callback_query',
        'my_chat_member',
        'chat_member',
        'channel_post',
        'edited_channel_post',
    ];
}

function setManageBotWebhook(string $token, string $webhookUrl): bool
{
    $response = tgRequestWithToken($token, 'setWebhook', [
        'url' => $webhookUrl,
        'allowed_updates' => json_encode(manageBotAllowedUpdates()),
        'drop_pending_updates' => false,
    ]);

    if (!empty($response['ok'])) {
        return true;
    }

    $description = (string) ($response['description'] ?? '');

    return stripos($description, 'already') !== false;
}

function seedPrimaryManageBotFromConfig(): void
{
    ensureManageBotTables();

    global $bot_token, $bot_username;
    $token = trim((string) ($bot_token ?? ''));
    if ($token === '') {
        return;
    }

    $me = tgGetMe($token);
    if (!$me) {
        return;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT id, webhook_key FROM manage_bots WHERE is_primary = 1 LIMIT 1');
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $botTelegramId = (int) ($me['id'] ?? 0);
    $username = (string) ($me['username'] ?? $bot_username ?? '');
    $name = trim((string) ($me['first_name'] ?? 'Manage Bot'));
    $webhookKey = (string) ($existing['webhook_key'] ?? bin2hex(random_bytes(16)));

    if ($existing) {
        $id = (int) $existing['id'];
        $stmt = $db->prepare(
            'UPDATE manage_bots SET bot_token = ?, bot_telegram_id = ?, bot_username = ?, bot_name = ?,
             status = "active", updated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('sissi', $token, $botTelegramId, $username, $name, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare(
            'INSERT INTO manage_bots (bot_token, bot_telegram_id, bot_username, bot_name, webhook_key, is_primary, status)
             VALUES (?, ?, ?, ?, ?, 1, "active")'
        );
        $stmt->bind_param('sisss', $token, $botTelegramId, $username, $name, $webhookKey);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
    }

    if ($id > 0) {
        recalculateManageBotChannelCount($id);
    }
}

function formatManageBotRow(array $row): array
{
    $channelCount = (int) ($row['channel_count'] ?? 0);
    $limit = MANAGE_BOT_CHANNEL_LIMIT;

    return [
        'id' => (int) ($row['id'] ?? 0),
        'bot_telegram_id' => (int) ($row['bot_telegram_id'] ?? 0),
        'bot_username' => (string) ($row['bot_username'] ?? ''),
        'bot_name' => (string) ($row['bot_name'] ?? ''),
        'is_primary' => (int) ($row['is_primary'] ?? 0) === 1,
        'status' => (string) ($row['status'] ?? 'active'),
        'channel_count' => $channelCount,
        'channel_limit' => $limit,
        'capacity_percent' => $limit > 0 ? (int) round(($channelCount / $limit) * 100) : 0,
        'near_capacity' => $channelCount >= 450,
        'at_capacity' => $channelCount >= $limit,
        'health_status' => (string) ($row['health_status'] ?? 'ok'),
        'health_message' => $row['health_message'] ?? null,
        'last_health_check' => $row['last_health_check'] ?? null,
        'webhook_url' => buildManageBotWebhookUrlForRow($row),
    ];
}

function getPrimaryManageBot(): ?array
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $db = getDb();
    $result = $db->query('SELECT * FROM manage_bots WHERE is_primary = 1 AND status = "active" LIMIT 1');
    $row = $result ? $result->fetch_assoc() : null;

    return $row ? formatManageBotRow($row) : null;
}

function getPrimaryManageBotRaw(): ?array
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $db = getDb();
    $result = $db->query('SELECT * FROM manage_bots WHERE is_primary = 1 AND status = "active" LIMIT 1');

    return $result ? $result->fetch_assoc() : null;
}

function getManageBotById(int $id, bool $includeToken = false): ?array
{
    ensureManageBotTables();
    if ($id <= 0) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM manage_bots WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $formatted = formatManageBotRow($row);
    if ($includeToken) {
        $formatted['bot_token'] = (string) ($row['bot_token'] ?? '');
        $formatted['webhook_key'] = (string) ($row['webhook_key'] ?? '');
    }

    return $formatted;
}

function getManageBotByKey(string $key): ?array
{
    ensureManageBotTables();
    $key = trim($key);
    if ($key === '') {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM manage_bots WHERE webhook_key = ? AND status = "active" LIMIT 1');
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    $formatted = formatManageBotRow($row);
    $formatted['bot_token'] = (string) ($row['bot_token'] ?? '');
    $formatted['webhook_key'] = (string) ($row['webhook_key'] ?? '');

    return $formatted;
}

/**
 * @return list<array<string, mixed>>
 */
function listManageBots(bool $includeToken = false): array
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $db = getDb();
    $result = $db->query(
        'SELECT * FROM manage_bots WHERE status != "deleted" ORDER BY is_primary DESC, channel_count ASC, id ASC'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $formatted = formatManageBotRow($row);
        if ($includeToken) {
            $formatted['bot_token'] = (string) ($row['bot_token'] ?? '');
            $formatted['webhook_key'] = (string) ($row['webhook_key'] ?? '');
        }
        $rows[] = $formatted;
    }

    return $rows;
}

function recalculateManageBotChannelCount(int $manageBotId): void
{
    ensureManageBotTables();
    if ($manageBotId <= 0) {
        return;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT COUNT(DISTINCT channel_chat_id) AS cnt
         FROM bot_channel_manage_bots
         WHERE manage_bot_id = ? AND bot_status IN ("administrator", "creator")'
    );
    $stmt->bind_param('i', $manageBotId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $count = (int) ($row['cnt'] ?? 0);
    $stmt = $db->prepare('UPDATE manage_bots SET channel_count = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ii', $count, $manageBotId);
    $stmt->execute();
    $stmt->close();
}

function pickManageBotForNewChannel(): ?array
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $db = getDb();
    $result = $db->query(
        'SELECT * FROM manage_bots
         WHERE status = "active" AND channel_count < ' . MANAGE_BOT_CHANNEL_LIMIT . '
         ORDER BY is_primary DESC, channel_count ASC, id ASC
         LIMIT 1'
    );
    $row = $result ? $result->fetch_assoc() : null;

    return $row ? formatManageBotRow($row) : null;
}

function bindManageBotToChannel(int $manageBotId, int $channelChatId, string $botStatus, bool $setPrimary = false): void
{
    ensureManageBotTables();
    ensureBotChannelsManageBotColumn();

    if ($manageBotId <= 0 || $channelChatId === 0) {
        return;
    }

    $db = getDb();
    if ($setPrimary) {
        $stmt = $db->prepare(
            'UPDATE bot_channel_manage_bots SET is_primary_binding = 0 WHERE channel_chat_id = ?'
        );
        $stmt->bind_param('i', $channelChatId);
        $stmt->execute();
        $stmt->close();
    }

    $primaryBinding = $setPrimary ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO bot_channel_manage_bots (channel_chat_id, manage_bot_id, bot_status, is_primary_binding, last_seen_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           bot_status = VALUES(bot_status),
           is_primary_binding = GREATEST(is_primary_binding, VALUES(is_primary_binding)),
           last_seen_at = NOW(),
           updated_at = NOW()'
    );
    $stmt->bind_param('iisi', $channelChatId, $manageBotId, $botStatus, $primaryBinding);
    $stmt->execute();
    $stmt->close();

    if ($setPrimary) {
        $stmt = $db->prepare('UPDATE bot_channels SET manage_bot_id = ? WHERE chat_id = ?');
        $stmt->bind_param('ii', $manageBotId, $channelChatId);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare('SELECT manage_bot_id FROM bot_channels WHERE chat_id = ? LIMIT 1');
        $stmt->bind_param('i', $channelChatId);
        $stmt->execute();
        $current = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (empty($current['manage_bot_id'])) {
            $stmt = $db->prepare('UPDATE bot_channels SET manage_bot_id = ? WHERE chat_id = ?');
            $stmt->bind_param('ii', $manageBotId, $channelChatId);
            $stmt->execute();
            $stmt->close();
        }
    }

    recalculateManageBotChannelCount($manageBotId);
}

function markManageBotChannelBindingInactive(int $manageBotId, int $channelChatId, string $botStatus): void
{
    ensureManageBotTables();
    if ($manageBotId <= 0 || $channelChatId === 0) {
        return;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE bot_channel_manage_bots SET bot_status = ?, updated_at = NOW() WHERE channel_chat_id = ? AND manage_bot_id = ?'
    );
    $stmt->bind_param('sii', $botStatus, $channelChatId, $manageBotId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('SELECT manage_bot_id FROM bot_channels WHERE chat_id = ? LIMIT 1');
    $stmt->bind_param('i', $channelChatId);
    $stmt->execute();
    $current = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ((int) ($current['manage_bot_id'] ?? 0) === $manageBotId) {
        $stmt = $db->prepare(
            'SELECT manage_bot_id FROM bot_channel_manage_bots
             WHERE channel_chat_id = ? AND bot_status IN ("administrator", "creator")
             ORDER BY is_primary_binding DESC, updated_at DESC LIMIT 1'
        );
        $stmt->bind_param('i', $channelChatId);
        $stmt->execute();
        $replacement = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $newManageBotId = $replacement ? (int) $replacement['manage_bot_id'] : null;
        if ($newManageBotId) {
            $stmt = $db->prepare('UPDATE bot_channels SET manage_bot_id = ? WHERE chat_id = ?');
            $stmt->bind_param('ii', $newManageBotId, $channelChatId);
            $stmt->execute();
            $stmt->close();
        } else {
            $null = null;
            $stmt = $db->prepare('UPDATE bot_channels SET manage_bot_id = NULL WHERE chat_id = ?');
            $stmt->bind_param('i', $channelChatId);
            $stmt->execute();
            $stmt->close();
        }
    }

    recalculateManageBotChannelCount($manageBotId);
}

/**
 * @return list<array<string, mixed>>
 */
function listManageBotCandidatesForChannel(int $channelChatId): array
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $db = getDb();
    $candidates = [];

    $stmt = $db->prepare('SELECT manage_bot_id FROM bot_channels WHERE chat_id = ? LIMIT 1');
    $stmt->bind_param('i', $channelChatId);
    $stmt->execute();
    $channelRow = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $assignedId = (int) ($channelRow['manage_bot_id'] ?? 0);
    if ($assignedId > 0) {
        $bot = getManageBotById($assignedId, true);
        if ($bot && !empty($bot['bot_token'])) {
            $candidates[$assignedId] = $bot;
        }
    }

    $stmt = $db->prepare(
        'SELECT mb.*
         FROM bot_channel_manage_bots bcm
         INNER JOIN manage_bots mb ON mb.id = bcm.manage_bot_id
         WHERE bcm.channel_chat_id = ? AND bcm.bot_status IN ("administrator", "creator") AND mb.status = "active"
         ORDER BY bcm.is_primary_binding DESC, bcm.updated_at DESC'
    );
    $stmt->bind_param('i', $channelChatId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && !isset($candidates[$id])) {
            $formatted = formatManageBotRow($row);
            $formatted['bot_token'] = (string) ($row['bot_token'] ?? '');
            $candidates[$id] = $formatted;
        }
    }
    $stmt->close();

    $primary = getPrimaryManageBotRaw();
    if ($primary) {
        $id = (int) ($primary['id'] ?? 0);
        if ($id > 0 && !isset($candidates[$id])) {
            $formatted = formatManageBotRow($primary);
            $formatted['bot_token'] = (string) ($primary['bot_token'] ?? '');
            $candidates[$id] = $formatted;
        }
    }

    $activeBots = $db->query('SELECT * FROM manage_bots WHERE status = "active" ORDER BY is_primary DESC, channel_count ASC');
    if ($activeBots) {
        while ($row = $activeBots->fetch_assoc()) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && !isset($candidates[$id])) {
                $formatted = formatManageBotRow($row);
                $formatted['bot_token'] = (string) ($row['bot_token'] ?? '');
                $candidates[$id] = $formatted;
            }
        }
    }

    return array_values($candidates);
}

function addManageBot(string $token): array
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $token = trim($token);
    if ($token === '') {
        throw new InvalidArgumentException('token_required');
    }

    $me = tgGetMe($token);
    if (!$me) {
        throw new InvalidArgumentException('invalid_token');
    }

    $botTelegramId = (int) ($me['id'] ?? 0);
    $primary = getPrimaryManageBotRaw();
    if ($primary && (int) ($primary['bot_telegram_id'] ?? 0) === $botTelegramId) {
        throw new InvalidArgumentException('primary_bot_exists');
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT id, is_primary FROM manage_bots WHERE bot_telegram_id = ? LIMIT 1');
    $stmt->bind_param('i', $botTelegramId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing && (int) ($existing['is_primary'] ?? 0) === 1) {
        throw new InvalidArgumentException('cannot_replace_primary');
    }

    $username = (string) ($me['username'] ?? '');
    $name = trim((string) ($me['first_name'] ?? 'Manage Bot'));
    $webhookKey = bin2hex(random_bytes(16));
    $webhookUrl = buildManageBotWebhookUrl($webhookKey);

    if (!setManageBotWebhook($token, $webhookUrl)) {
        throw new RuntimeException('webhook_failed');
    }

    if ($existing) {
        $id = (int) $existing['id'];
        $stmt = $db->prepare(
            'UPDATE manage_bots SET bot_token = ?, bot_username = ?, bot_name = ?, webhook_key = ?,
             status = "active", health_status = "ok", health_message = NULL, updated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('ssssi', $token, $username, $name, $webhookKey, $id);
        $stmt->execute();
        $stmt->close();
    } else {
        $stmt = $db->prepare(
            'INSERT INTO manage_bots (bot_token, bot_telegram_id, bot_username, bot_name, webhook_key, is_primary, status)
             VALUES (?, ?, ?, ?, ?, 0, "active")'
        );
        $stmt->bind_param('sisss', $token, $botTelegramId, $username, $name, $webhookKey);
        $stmt->execute();
        $id = (int) $stmt->insert_id;
        $stmt->close();
    }

    recalculateManageBotChannelCount($id);

    return getManageBotById($id, true) ?? [];
}

function removeManageBot(int $manageBotId): bool
{
    ensureManageBotTables();
    if ($manageBotId <= 0) {
        return false;
    }

    $bot = getManageBotById($manageBotId, true);
    if (!$bot || !empty($bot['is_primary'])) {
        throw new InvalidArgumentException('cannot_remove_primary');
    }

    if ((int) ($bot['channel_count'] ?? 0) > 0) {
        throw new InvalidArgumentException('manage_bot_has_channels');
    }

    $token = (string) ($bot['bot_token'] ?? '');
    if ($token !== '') {
        tgRequestWithToken($token, 'deleteWebhook', ['drop_pending_updates' => false]);
    }

    $db = getDb();
    $stmt = $db->prepare('UPDATE manage_bots SET status = "deleted", updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $manageBotId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

function refreshManageBotHealth(int $manageBotId): array
{
    ensureManageBotTables();
    $bot = getManageBotById($manageBotId, true);
    if (!$bot || empty($bot['bot_token'])) {
        return ['ok' => false, 'error' => 'manage_bot_not_found'];
    }

    $token = (string) $bot['bot_token'];
    $me = tgGetMe($token);
    $wh = tgRequestWithToken($token, 'getWebhookInfo', []);

    $health = 'ok';
    $message = null;
    if (!$me) {
        $health = 'invalid_token';
        $message = 'توکن نامعتبر';
    } elseif (empty($wh['ok']) || trim((string) ($wh['result']['url'] ?? '')) === '') {
        $health = 'webhook_missing';
        $message = 'وب‌هوک تنظیم نشده';
    } elseif (!empty($wh['result']['last_error_message'])) {
        $health = 'webhook_error';
        $message = (string) $wh['result']['last_error_message'];
    }

    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE manage_bots SET health_status = ?, health_message = ?, last_health_check = NOW(), updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('ssi', $health, $message, $manageBotId);
    $stmt->execute();
    $stmt->close();

    if ($health === 'webhook_missing' || $health === 'webhook_error') {
        setManageBotWebhook($token, buildManageBotWebhookUrlForRow([
            'is_primary' => !empty($bot['is_primary']) ? 1 : 0,
            'webhook_key' => (string) ($bot['webhook_key'] ?? ''),
        ]));
    }

    return [
        'ok' => $health === 'ok',
        'health_status' => $health,
        'health_message' => $message,
        'bot' => getManageBotById($manageBotId),
    ];
}

function getManageBotsOverview(): array
{
    $bots = listManageBots();
    $totalChannels = 0;
    $activeCount = 0;
    foreach ($bots as $bot) {
        $totalChannels += (int) ($bot['channel_count'] ?? 0);
        if (($bot['status'] ?? '') === 'active') {
            $activeCount++;
        }
    }

    $suggested = pickManageBotForNewChannel();

    return [
        'bots' => $bots,
        'stats' => [
            'bot_count' => count($bots),
            'active_bot_count' => $activeCount,
            'total_channel_bindings' => $totalChannels,
            'channel_limit' => MANAGE_BOT_CHANNEL_LIMIT,
            'suggested_bot_username' => (string) ($suggested['bot_username'] ?? ''),
            'suggested_bot_name' => (string) ($suggested['bot_name'] ?? ''),
            'all_bots_at_capacity' => $suggested === null,
        ],
    ];
}

function backfillPrimaryManageBotBindings(): int
{
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();

    $primary = getPrimaryManageBotRaw();
    if (!$primary) {
        return 0;
    }

    $manageBotId = (int) ($primary['id'] ?? 0);
    if ($manageBotId <= 0) {
        return 0;
    }

    $db = getDb();
    $result = $db->query('SELECT chat_id, manage_bot_id FROM bot_channels');
    if (!$result) {
        return 0;
    }

    $bound = 0;
    while ($row = $result->fetch_assoc()) {
        $chatId = (int) ($row['chat_id'] ?? 0);
        if ($chatId === 0) {
            continue;
        }

        $stmt = $db->prepare(
            'SELECT id FROM bot_channel_manage_bots WHERE channel_chat_id = ? AND manage_bot_id = ? LIMIT 1'
        );
        $stmt->bind_param('ii', $chatId, $manageBotId);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_row();
        $stmt->close();

        if (!$exists) {
            bindManageBotToChannel($manageBotId, $chatId, 'administrator', true);
            $bound++;
        } elseif (empty($row['manage_bot_id'])) {
            $stmt = $db->prepare('UPDATE bot_channels SET manage_bot_id = ? WHERE chat_id = ?');
            $stmt->bind_param('ii', $manageBotId, $chatId);
            $stmt->execute();
            $stmt->close();
        }
    }

    recalculateManageBotChannelCount($manageBotId);

    return $bound;
}
