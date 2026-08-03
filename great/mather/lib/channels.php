<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/explorer_pins.php';
require_once __DIR__ . '/manage_bots.php';
require_once __DIR__ . '/manage_bot_router.php';

function ensureBotChannelsTable(): void
{
    $db = getDb();
    $sql = <<<SQL
CREATE TABLE IF NOT EXISTS bot_channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    chat_type VARCHAR(20) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    username VARCHAR(255) DEFAULT NULL,
    member_count INT UNSIGNED DEFAULT NULL,
    bot_status VARCHAR(30) NOT NULL DEFAULT 'administrator',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chat_id (chat_id),
    KEY idx_active_type (is_active, chat_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    $db->query($sql);
    ensureBotChannelHealthColumns();
    ensureExplorerPinColumns('bot_channels');
}

function ensureBotChannelHealthColumns(): void
{
    $db = getDb();
    $columns = [
        'deactivated_reason' => "VARCHAR(64) NULL DEFAULT NULL",
        'deactivated_at' => "DATETIME NULL DEFAULT NULL",
        'health_status' => "VARCHAR(32) NOT NULL DEFAULT 'ok'",
        'health_message' => "VARCHAR(255) NULL DEFAULT NULL",
        'last_health_check' => "DATETIME NULL DEFAULT NULL",
    ];

    foreach ($columns as $name => $definition) {
        $safe = preg_replace('/[^a-z_]/', '', $name);
        $result = $db->query("SHOW COLUMNS FROM bot_channels LIKE '{$safe}'");
        if ($result && $result->num_rows === 0) {
            $db->query("ALTER TABLE bot_channels ADD COLUMN {$safe} {$definition}");
        }
    }
}

function labelChannelProblem(string $code, ?string $detail = null): string
{
    $map = [
        'ok' => 'سالم',
        'bot_kicked' => 'ربات از کانال حذف شده',
        'bot_left' => 'ربات کانال را ترک کرده',
        'bot_restricted' => 'دسترسی ربات محدود شده',
        'bot_not_admin' => 'ربات دیگر ادمین نیست',
        'chat_not_found' => 'کانال یافت نشد (حذف، بن یا دسترسی قطع)',
        'chat_deactivated' => 'کانال توسط تلگرام غیرفعال شده',
        'api_error' => 'خطا در ارتباط با تلگرام',
        'bot_removed' => 'ربات دسترسی ندارد',
    ];

    $label = $map[$code] ?? 'مشکل دسترسی';
    if ($detail && $code === 'api_error') {
        return $label . ': ' . $detail;
    }

    return $label;
}

/**
 * @return array{code: string, label: string, raw: string}
 */
function classifyTelegramApiError(?array $response): array
{
    if (!empty($response['ok'])) {
        return ['code' => 'ok', 'label' => labelChannelProblem('ok'), 'raw' => ''];
    }

    $raw = (string) ($response['description'] ?? 'unknown');
    $desc = strtolower($raw);

    if (str_contains($desc, 'chat not found') || str_contains($desc, 'group chat was upgraded')) {
        return ['code' => 'chat_not_found', 'label' => labelChannelProblem('chat_not_found'), 'raw' => $raw];
    }
    if (str_contains($desc, 'deactivated') || str_contains($desc, 'banned')) {
        return ['code' => 'chat_deactivated', 'label' => labelChannelProblem('chat_deactivated'), 'raw' => $raw];
    }
    if (str_contains($desc, 'kicked')) {
        return ['code' => 'bot_kicked', 'label' => labelChannelProblem('bot_kicked'), 'raw' => $raw];
    }
    if (str_contains($desc, 'not a member')) {
        return ['code' => 'bot_left', 'label' => labelChannelProblem('bot_left'), 'raw' => $raw];
    }
    if (str_contains($desc, 'restricted') || str_contains($desc, 'not enough rights')) {
        return ['code' => 'bot_restricted', 'label' => labelChannelProblem('bot_restricted'), 'raw' => $raw];
    }

    return ['code' => 'api_error', 'label' => labelChannelProblem('api_error', $raw), 'raw' => $raw];
}

function memberStatusToProblemCode(string $status): string
{
    switch ($status) {
        case 'kicked':
            return 'bot_kicked';
        case 'left':
            return 'bot_left';
        case 'restricted':
            return 'bot_restricted';
        case 'member':
            return 'bot_not_admin';
        default:
            return 'bot_removed';
    }
}

function notifyAdminsChannelAccessLost(int $chatId, string $title, string $label): void
{
    global $admin_telegram_ids;
    if (empty($admin_telegram_ids)) {
        return;
    }

    $title = trim($title) !== '' ? $title : 'کانال';
    $text = "⚠️ مشکل دسترسی کانال\n\n"
        . "📢 {$title}\n"
        . "🆔 chat_id: {$chatId}\n"
        . "📋 {$label}\n\n"
        . 'جزئیات در مینی‌اپ → تب سرور';

    foreach ($admin_telegram_ids as $adminId) {
        $id = (int) $adminId;
        if ($id <= 0) {
            continue;
        }
        telegramRequest('sendMessage', [
            'chat_id' => $id,
            'text' => $text,
        ]);
    }
}

/**
 * @return array{code: string, label: string, raw: string}
 */
function probeBotChannelHealth(int $chatId): array
{
    ensureBotChannelHealthColumns();

    $candidates = listManageBotCandidatesForChannel($chatId);
    if ($candidates === []) {
        $primary = getPrimaryManageBotRaw();
        if ($primary) {
            $formatted = formatManageBotRow($primary);
            $formatted['bot_token'] = (string) ($primary['bot_token'] ?? '');
            $candidates = [$formatted];
        }
    }

    $lastIssue = ['code' => 'api_error', 'label' => labelChannelProblem('api_error', 'manage_bot'), 'raw' => 'manage_bot'];

    foreach ($candidates as $bot) {
        $token = (string) ($bot['bot_token'] ?? '');
        $botId = (int) ($bot['bot_telegram_id'] ?? 0);
        if ($token === '' || $botId <= 0) {
            continue;
        }

        $chatResp = tgRequestWithToken($token, 'getChat', ['chat_id' => $chatId]);
        $chatIssue = classifyTelegramApiError($chatResp);
        if ($chatIssue['code'] !== 'ok') {
            $lastIssue = $chatIssue;
            continue;
        }

        $memberResp = tgRequestWithToken($token, 'getChatMember', [
            'chat_id' => $chatId,
            'user_id' => $botId,
        ]);

        if (empty($memberResp['ok'])) {
            $lastIssue = classifyTelegramApiError($memberResp);
            continue;
        }

        $status = (string) ($memberResp['result']['status'] ?? '');
        if (isBotAdminStatus($status)) {
            return ['code' => 'ok', 'label' => labelChannelProblem('ok'), 'raw' => $status];
        }

        $code = memberStatusToProblemCode($status);
        $lastIssue = ['code' => $code, 'label' => labelChannelProblem($code), 'raw' => $status];
    }

    return $lastIssue;
}

function persistChannelHealthCheck(int $chatId, array $issue, bool $deactivateOnFailure = true): void
{
    ensureBotChannelHealthColumns();
    $db = getDb();
    $code = $issue['code'] ?? 'api_error';
    $message = $issue['label'] ?? labelChannelProblem($code);

    if ($code === 'ok') {
        $stmt = $db->prepare(
            'UPDATE bot_channels SET health_status = ?, health_message = NULL, last_health_check = NOW(),
             deactivated_reason = NULL, deactivated_at = NULL, is_active = 1, updated_at = NOW() WHERE chat_id = ?'
        );
        $stmt->bind_param('si', $code, $chatId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    if ($deactivateOnFailure) {
        $stmt = $db->prepare(
            'UPDATE bot_channels SET is_active = 0, health_status = ?, health_message = ?, last_health_check = NOW(),
             deactivated_reason = ?, deactivated_at = COALESCE(deactivated_at, NOW()), updated_at = NOW() WHERE chat_id = ?'
        );
        $stmt->bind_param('sssi', $code, $message, $code, $chatId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $stmt = $db->prepare(
        'UPDATE bot_channels SET health_status = ?, health_message = ?, last_health_check = NOW(), updated_at = NOW() WHERE chat_id = ?'
    );
    $stmt->bind_param('ssi', $code, $message, $chatId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return list<array<string, mixed>>
 */
function runServerChannelHealthPass(int $maxProbes = 3): void
{
    ensureBotChannelHealthColumns();
    $db = getDb();

    $probeIds = [];
    $result = $db->query(
        "SELECT chat_id FROM bot_channels
         WHERE is_active = 1 AND (last_health_check IS NULL OR last_health_check < DATE_SUB(NOW(), INTERVAL 6 HOUR))
         ORDER BY last_health_check IS NULL DESC, last_health_check ASC
         LIMIT " . (int) $maxProbes
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $probeIds[] = (int) $row['chat_id'];
        }
    }

    foreach ($probeIds as $chatId) {
        $issue = probeBotChannelHealth($chatId);
        persistChannelHealthCheck($chatId, $issue, true);
    }
}

/**
 * @return list<array<string, mixed>>
 */
function getBotChannelProblems(): array
{
    ensureBotChannelHealthColumns();
    $db = getDb();

    try {
        $result = $db->query(
            "SELECT chat_id, chat_type, title, username, member_count, bot_status, is_active,
                    deactivated_reason, deactivated_at, health_status, health_message, updated_at
             FROM bot_channels
             WHERE is_active = 0 OR health_status NOT IN ('ok')
             ORDER BY COALESCE(deactivated_at, updated_at) DESC, chat_id DESC"
        );
    } catch (Throwable $e) {
        error_log('getBotChannelProblems: ' . $e->getMessage());

        return [];
    }

    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $code = $row['deactivated_reason'] ?: $row['health_status'] ?: 'bot_removed';
        if ($code === 'ok') {
            $code = 'bot_removed';
        }
        $label = $row['health_message'] ?: labelChannelProblem((string) $code);
        $since = $row['deactivated_at'] ?: $row['updated_at'];
        $rows[] = [
            'chat_id' => (int) $row['chat_id'],
            'title' => $row['title'] ?: 'بدون نام',
            'username' => $row['username'],
            'type' => $row['chat_type'],
            'is_active' => (int) $row['is_active'] === 1,
            'problem_code' => $code,
            'problem_label' => $label,
            'since_at' => $since,
        ];
    }

    return $rows;
}

function isTrackableChatType(string $type): bool
{
    return $type === 'channel';
}

function isBotAdminStatus(string $status): bool
{
    return in_array($status, ['administrator', 'creator'], true);
}

function upsertBotChannel(array $chat, string $botStatus): void
{
    ensureBotChannelsTable();

    $chatId = (int) ($chat['id'] ?? 0);
    if ($chatId === 0) {
        return;
    }

    $chatType = (string) ($chat['type'] ?? 'channel');
    $title = $chat['title'] ?? null;
    $username = $chat['username'] ?? null;
    $memberCount = isset($chat['member_count']) ? (int) $chat['member_count'] : null;

    $chatInfo = telegramRequest('getChat', ['chat_id' => $chatId]);
    if (!empty($chatInfo['ok']) && !empty($chatInfo['result'])) {
        $result = $chatInfo['result'];
        $title = $result['title'] ?? $title;
        $username = $result['username'] ?? $username;
        if (isset($result['member_count'])) {
            $memberCount = (int) $result['member_count'];
        }
    }

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO bot_channels (chat_id, chat_type, title, username, member_count, bot_status, is_active, health_status, deactivated_reason, deactivated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, \'ok\', NULL, NULL)
         ON DUPLICATE KEY UPDATE
            chat_type = VALUES(chat_type),
            title = VALUES(title),
            username = VALUES(username),
            member_count = VALUES(member_count),
            bot_status = VALUES(bot_status),
            is_active = 1,
            health_status = \'ok\',
            health_message = NULL,
            deactivated_reason = NULL,
            deactivated_at = NULL,
            updated_at = NOW()'
    );
    $stmt->bind_param('isssis', $chatId, $chatType, $title, $username, $memberCount, $botStatus);
    $stmt->execute();
    $stmt->close();
}

function deactivateBotChannel(int $chatId, string $reason = 'bot_removed', ?string $message = null): void
{
    ensureBotChannelsTable();
    ensureBotChannelHealthColumns();

    $message = $message ?? labelChannelProblem($reason);
    $db = getDb();

    $wasActive = false;
    $channelTitle = 'کانال';
    $stmt = $db->prepare('SELECT is_active, title FROM bot_channels WHERE chat_id = ? LIMIT 1');
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $wasActive = (int) ($row['is_active'] ?? 0) === 1;
        $channelTitle = (string) ($row['title'] ?? 'کانال');
    }

    $stmt = $db->prepare(
        'UPDATE bot_channels SET is_active = 0, health_status = ?, health_message = ?,
         deactivated_reason = ?, deactivated_at = COALESCE(deactivated_at, NOW()), updated_at = NOW() WHERE chat_id = ?'
    );
    $stmt->bind_param('sssi', $reason, $message, $reason, $chatId);
    $stmt->execute();
    $stmt->close();

    if ($wasActive) {
        notifyAdminsChannelAccessLost($chatId, $channelTitle, $message);
    }
}

function syncBotChannelFromChat(array $chat, ?int $manageBotId = null): bool
{
    $chatId = (int) ($chat['id'] ?? 0);
    $chatType = (string) ($chat['type'] ?? '');
    if ($chatId === 0 || !isTrackableChatType($chatType)) {
        return false;
    }

    if ($manageBotId === null || $manageBotId <= 0) {
        $primary = getPrimaryManageBotRaw();
        $manageBotId = (int) ($primary['id'] ?? 0);
    }

    $bot = $manageBotId > 0 ? getManageBotById($manageBotId, true) : null;
    $botId = (int) ($bot['bot_telegram_id'] ?? getManageBotId());
    $token = (string) ($bot['bot_token'] ?? getManageBotToken());
    if ($botId <= 0 || $token === '') {
        return false;
    }

    $member = tgRequestWithToken($token, 'getChatMember', [
        'chat_id' => $chatId,
        'user_id' => $botId,
    ]);

    $status = (string) ($member['result']['status'] ?? '');
    if (isBotAdminStatus($status)) {
        upsertBotChannel($chat, $status);
        if ($manageBotId > 0) {
            bindManageBotToChannel($manageBotId, $chatId, $status, true);
        }

        return true;
    }

    $code = memberStatusToProblemCode($status);
    if ($manageBotId > 0) {
        markManageBotChannelBindingInactive($manageBotId, $chatId, $status);
    }
    deactivateBotChannel($chatId, $code, labelChannelProblem($code));

    return false;
}

function handleMyChatMemberForManageBot(array $update, int $manageBotId): void
{
    $payload = $update['my_chat_member'] ?? [];
    $chat = $payload['chat'] ?? [];
    $newMember = $payload['new_chat_member'] ?? [];
    $chatType = (string) ($chat['type'] ?? '');

    if (!isTrackableChatType($chatType)) {
        return;
    }

    $chatId = (int) ($chat['id'] ?? 0);
    $status = (string) ($newMember['status'] ?? '');
    if (isBotAdminStatus($status)) {
        upsertBotChannel($chat, $status);
        bindManageBotToChannel($manageBotId, $chatId, $status, true);
        if ($chatId > 0 && function_exists('refreshChannelMetadata')) {
            refreshChannelMetadata($chatId);
        }

        return;
    }

    $code = memberStatusToProblemCode($status);
    markManageBotChannelBindingInactive($manageBotId, $chatId, $status);
    deactivateBotChannel($chatId, $code, labelChannelProblem($code));
}

function handleMyChatMember(array $update): void
{
    $primary = getPrimaryManageBotRaw();
    $manageBotId = (int) ($primary['id'] ?? 0);
    if ($manageBotId <= 0) {
        $payload = $update['my_chat_member'] ?? [];
        $chat = $payload['chat'] ?? [];
        $newMember = $payload['new_chat_member'] ?? [];
        $chatType = (string) ($chat['type'] ?? '');

        if (!isTrackableChatType($chatType)) {
            return;
        }

        $status = (string) ($newMember['status'] ?? '');
        if (isBotAdminStatus($status)) {
            upsertBotChannel($chat, $status);
            $chatId = (int) ($chat['id'] ?? 0);
            if ($chatId > 0 && function_exists('refreshChannelMetadata')) {
                refreshChannelMetadata($chatId);
            }

            return;
        }

        $chatId = (int) ($chat['id'] ?? 0);
        $code = memberStatusToProblemCode($status);
        deactivateBotChannel($chatId, $code, labelChannelProblem($code));

        return;
    }

    handleMyChatMemberForManageBot($update, $manageBotId);
}

function getActiveBotChannels(bool $refreshStale = true, bool $refreshIncomplete = true, bool $includeInactive = false): array
{
    ensureBotChannelsTable();
    if (function_exists('ensureChannelStatsTables')) {
        ensureChannelStatsTables();
    }
    if ($refreshIncomplete && !function_exists('refreshChannelMetadata')) {
        require_once __DIR__ . '/channel_stats.php';
    }

    $db = getDb();
    $activeFilter = $includeInactive ? '' : 'WHERE is_active = 1';
    $result = $db->query(
        "SELECT chat_id, chat_type, title, username, member_count, bot_status, photo_file_id, invite_link,
                is_active, health_status, health_message, deactivated_reason, added_at, updated_at
         FROM bot_channels
         {$activeFilter}
         ORDER BY is_pinned DESC, pinned_at DESC, is_active DESC, title ASC, chat_id ASC"
    );

    if (!$result) {
        $result = $db->query(
            "SELECT chat_id, chat_type, title, username, member_count, bot_status, is_active,
                    health_status, health_message, deactivated_reason, added_at, updated_at
             FROM bot_channels
             {$activeFilter}
             ORDER BY is_active DESC, title ASC, chat_id ASC"
        );
    }

    if (!$result) {
        return [];
    }

    $rows = [];
    $refreshCount = 0;
    $maxRefresh = 10;
    while ($row = $result->fetch_assoc()) {
        $chatId = (int) $row['chat_id'];
        $isActive = (int) ($row['is_active'] ?? 1) === 1;
        $shouldRefresh = false;
        if ($isActive && $refreshCount < $maxRefresh && function_exists('refreshChannelMetadata')) {
            $photoMissing = empty($row['photo_file_id']);
            $membersMissing = $row['member_count'] === null || $row['member_count'] === '';
            $addedAt = strtotime((string) ($row['added_at'] ?? $row['updated_at'] ?? ''));
            $isRecent = $addedAt > 0 && $addedAt > time() - 1800;
            $updatedAt = strtotime((string) ($row['updated_at'] ?? ''));
            $isStale = $updatedAt > 0 && $updatedAt < time() - 1800;
            if ($refreshIncomplete && ($photoMissing || $membersMissing || $isRecent)) {
                $shouldRefresh = true;
            } elseif ($refreshStale && $isStale) {
                $shouldRefresh = true;
            }
        }

        if ($shouldRefresh) {
            refreshChannelMetadata($chatId);
            $refreshCount++;
            $stmt = $db->prepare(
                'SELECT chat_id, chat_type, title, username, member_count, bot_status, photo_file_id, invite_link,
                        is_active, health_status, health_message, deactivated_reason, added_at, updated_at
                 FROM bot_channels WHERE chat_id = ? LIMIT 1'
            );
            $stmt->bind_param('i', $chatId);
            $stmt->execute();
            $fresh = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if ($fresh) {
                $row = $fresh;
            }
        }

        $rows[] = formatBotChannelRow($row);
    }

    return $rows;
}

function resyncKnownBotChannels(): int
{
    ensureBotChannelsTable();

    $chatIds = [];
    $db = getDb();

    $result = $db->query('SELECT chat_id, chat_type FROM bot_channels');
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $chatIds[(int) $row['chat_id']] = (string) ($row['chat_type'] ?? 'channel');
        }
    }

    $logPath = dirname(__DIR__) . '/update.log';
    if (is_readable($logPath)) {
        $content = (string) file_get_contents($logPath);
        if (preg_match_all('/"chat":\{"id":(-?\d+),"[^}]*"type":"(channel|supergroup)"/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $chatIds[(int) $match[1]] = $match[2];
            }
        }
    }

    $synced = 0;
    foreach ($chatIds as $chatId => $type) {
        if (syncBotChannelFromChat(['id' => $chatId, 'type' => $type])) {
            $synced++;
        }
    }

    return $synced;
}

/**
 * @return array{health_status: string, health_message: ?string, is_banned: bool, is_active: bool}
 */
function channelHealthPayload(array $row): array
{
    $isActive = (int) ($row['is_active'] ?? 1) === 1;
    $code = (string) ($row['health_status'] ?? 'ok');
    if (!$isActive && ($code === '' || $code === 'ok')) {
        $code = (string) ($row['deactivated_reason'] ?? 'bot_removed');
    }
    if ($code === '') {
        $code = 'ok';
    }
    $hasProblem = !$isActive || $code !== 'ok';

    return [
        'health_status' => $code,
        'health_message' => $row['health_message'] ?? null,
        'is_banned' => $hasProblem,
        'is_active' => $isActive,
    ];
}

function formatBotChannelRow(array $row): array
{
    $chatId = (int) $row['chat_id'];
    $username = $row['username'] ?? null;
    $inviteLink = $row['invite_link'] ?? null;
    $photoFileId = $row['photo_file_id'] ?? null;
    $health = channelHealthPayload($row);

    return [
        'chat_id' => $chatId,
        'type' => $row['chat_type'],
        'title' => $row['title'] ?: 'بدون نام',
        'username' => $username,
        'member_count' => $row['member_count'] !== null ? (int) $row['member_count'] : null,
        'bot_status' => $row['bot_status'],
        'added_at' => $row['added_at'],
        'updated_at' => $row['updated_at'],
        'is_private' => empty($username),
        'photo_url' => $photoFileId ? 'api/channel_photo.php?chat_id=' . $chatId : null,
        'invite_link' => $inviteLink,
        'link' => $username ? 'https://t.me/' . $username : $inviteLink,
        'is_active' => $health['is_active'],
        'health_status' => $health['health_status'],
        'health_message' => $health['health_message'],
        'is_banned' => $health['is_banned'],
        ...explorerPinPayload($row),
    ];
}

function setChannelPinned(int $chatId, bool $pinned): bool
{
    ensureBotChannelsTable();
    if ($chatId === 0) {
        throw new InvalidArgumentException('invalid_chat');
    }

    return setExplorerEntityPinned('bot_channels', 'chat_id', $chatId, $pinned);
}
