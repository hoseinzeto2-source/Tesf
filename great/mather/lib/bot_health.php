<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/child_bots.php';

function ensureChildBotHealthColumns(): void
{
    ensureChildBotTables();
    $db = getDb();
    $columns = [
        'health_status' => "VARCHAR(32) NOT NULL DEFAULT 'ok'",
        'health_message' => 'VARCHAR(255) NULL DEFAULT NULL',
        'last_health_check' => 'DATETIME NULL DEFAULT NULL',
        'problem_since' => 'DATETIME NULL DEFAULT NULL',
        'webhook_error' => 'VARCHAR(255) NULL DEFAULT NULL',
    ];

    foreach ($columns as $name => $definition) {
        $safe = preg_replace('/[^a-z_]/', '', $name);
        $result = $db->query("SHOW COLUMNS FROM child_bots LIKE '{$safe}'");
        if ($result && $result->num_rows === 0) {
            $db->query("ALTER TABLE child_bots ADD COLUMN {$safe} {$definition}");
        }
    }
}

function childBotHasProblem(array $bot): bool
{
    if (($bot['status'] ?? 'active') !== 'active') {
        return true;
    }

    $health = (string) ($bot['health_status'] ?? 'ok');

    return $health !== '' && $health !== 'ok';
}

/**
 * @return array{health_status: string, health_message: ?string, is_banned: bool}
 */
function childBotHealthPayload(array $bot): array
{
    ensureChildBotHealthColumns();

    $code = (string) ($bot['health_status'] ?? 'ok');
    if (($bot['status'] ?? 'active') !== 'active' && $code === 'ok') {
        $code = 'bot_disabled';
    }

    $hasProblem = childBotHasProblem($bot);
    $label = (string) ($bot['health_message'] ?? '');
    if ($label === '' || $label === 'ok') {
        $label = labelBotProblem($code);
    }

    return [
        'health_status' => $code,
        'health_message' => $hasProblem ? $label : null,
        'is_banned' => $hasProblem,
    ];
}

function runOwnerChildBotHealthPass(int $ownerTelegramId, int $maxProbes = 50): int
{
    ensureChildBotHealthColumns();
    $db = getDb();
    $ownerTelegramId = (int) $ownerTelegramId;
    if ($ownerTelegramId <= 0) {
        return 0;
    }

    $limit = $maxProbes > 0 ? (int) $maxProbes : 50;
    $stmt = $db->prepare(
        "SELECT id, bot_telegram_id, bot_username, bot_name, bot_token, webhook_key, bot_type,
                uploader_version_id, health_status, status
         FROM child_bots
         WHERE owner_telegram_id = ? AND status = 'active'
         ORDER BY last_health_check IS NULL DESC, last_health_check ASC, id ASC
         LIMIT {$limit}"
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $stmt->close();

    if (!$result) {
        return 0;
    }

    $probed = 0;
    while ($row = $result->fetch_assoc()) {
        $botId = (int) ($row['id'] ?? 0);
        if ($botId <= 0) {
            continue;
        }

        $previousCode = (string) ($row['health_status'] ?? 'ok');
        $issue = probeChildBotHealth($row);
        $webhookError = ($issue['code'] ?? '') === 'webhook_error' ? ($issue['raw'] ?? null) : null;
        persistChildBotHealthCheck($botId, $issue, is_string($webhookError) ? $webhookError : null);

        if (($issue['code'] ?? '') !== 'ok' && $previousCode === 'ok') {
            notifyAdminsChildBotProblem($row, (string) ($issue['label'] ?? labelBotProblem('api_error')));
            if (in_array((string) ($row['bot_type'] ?? ''), ['guardian', 'uploader'], true)) {
                require_once __DIR__ . '/zapas_bots.php';
                attemptZapasReplacementForBot($botId, (string) ($issue['code'] ?? 'bot_banned'));
            }
        }

        $probed++;
    }

    return $probed;
}

function labelBotProblem(string $code, ?string $detail = null): string
{
    $map = [
        'ok' => 'سالم',
        'token_invalid' => 'توکن نامعتبر یا لغو شده',
        'bot_deleted' => 'ربات حذف شده',
        'bot_deactivated' => 'ربات توسط تلگرام مسدود یا غیرفعال شده',
        'webhook_error' => 'خطای webhook',
        'webhook_missing' => 'webhook تنظیم نشده',
        'webhook_mismatch' => 'آدرس webhook اشتباه است',
        'api_error' => 'خطا در ارتباط با تلگرام',
        'bot_disabled' => 'ربات غیرفعال شده',
    ];

    $label = $map[$code] ?? 'مشکل دسترسی ربات';
    if ($detail && in_array($code, ['api_error', 'webhook_error'], true)) {
        return $label . ': ' . $detail;
    }

    return $label;
}

/**
 * @return array{code: string, label: string, raw: string}
 */
function classifyChildBotApiError(?array $response): array
{
    if (!empty($response['ok'])) {
        return ['code' => 'ok', 'label' => labelBotProblem('ok'), 'raw' => ''];
    }

    $raw = (string) ($response['description'] ?? 'unknown');
    $desc = strtolower($raw);

    if (str_contains($desc, 'unauthorized') || str_contains($desc, 'invalid token')) {
        return ['code' => 'token_invalid', 'label' => labelBotProblem('token_invalid'), 'raw' => $raw];
    }
    if (str_contains($desc, 'bot was deleted') || str_contains($desc, 'not found')) {
        return ['code' => 'bot_deleted', 'label' => labelBotProblem('bot_deleted'), 'raw' => $raw];
    }
    if (str_contains($desc, 'deactivated') || str_contains($desc, 'banned')) {
        return ['code' => 'bot_deactivated', 'label' => labelBotProblem('bot_deactivated'), 'raw' => $raw];
    }

    return ['code' => 'api_error', 'label' => labelBotProblem('api_error', $raw), 'raw' => $raw];
}

function normalizeWebhookUrl(string $url): string
{
    $url = trim(strtolower($url));
    $url = rtrim($url, '/');

    return $url;
}

/**
 * @return array{code: string, label: string, raw: string}
 */
function probeChildBotHealth(array $bot): array
{
    ensureChildBotHealthColumns();

    $token = trim((string) ($bot['bot_token'] ?? ''));
    if ($token === '') {
        return ['code' => 'token_invalid', 'label' => labelBotProblem('token_invalid'), 'raw' => 'empty_token'];
    }

    $meResp = tgRequestWithToken($token, 'getMe');
    if (empty($meResp['ok'])) {
        return classifyChildBotApiError($meResp);
    }

    $whResp = tgRequestWithToken($token, 'getWebhookInfo');
    if (empty($whResp['ok'])) {
        return classifyChildBotApiError($whResp);
    }

    $whResult = $whResp['result'] ?? [];
    $currentUrl = (string) ($whResult['url'] ?? '');
    $lastError = (string) ($whResult['last_error_message'] ?? '');

    if ($currentUrl === '') {
        return ['code' => 'webhook_missing', 'label' => labelBotProblem('webhook_missing'), 'raw' => ''];
    }

    if (in_array($bot['bot_type'] ?? '', ['uploader', 'guardian', 'zapas'], true)) {
        try {
            require_once __DIR__ . '/uploader_versions.php';
            $versionId = (int) ($bot['uploader_version_id'] ?? 0);
            $role = (string) ($bot['bot_type'] ?? 'uploader');
            $version = resolveUploaderVersionForBot($versionId, $role);
            $expectedUrl = buildUploaderVersionWebhookUrl($version, (string) ($bot['webhook_key'] ?? ''));
            if ($expectedUrl !== '' && normalizeWebhookUrl($currentUrl) !== normalizeWebhookUrl($expectedUrl)) {
                return [
                    'code' => 'webhook_mismatch',
                    'label' => labelBotProblem('webhook_mismatch'),
                    'raw' => $currentUrl,
                ];
            }
        } catch (Throwable $e) {
            // Skip mismatch check when version metadata is unavailable.
        }
    }

    if ($lastError !== '') {
        return [
            'code' => 'webhook_error',
            'label' => labelBotProblem('webhook_error', $lastError),
            'raw' => $lastError,
        ];
    }

    return ['code' => 'ok', 'label' => labelBotProblem('ok'), 'raw' => ''];
}

function persistChildBotHealthCheck(int $botId, array $issue, ?string $webhookError = null): void
{
    ensureChildBotHealthColumns();
    $db = getDb();
    $code = (string) ($issue['code'] ?? 'api_error');
    $message = (string) ($issue['label'] ?? labelBotProblem($code));
    $webhookError = $webhookError ?? (($code === 'webhook_error') ? ($issue['raw'] ?? null) : null);

    if ($code === 'ok') {
        $stmt = $db->prepare(
            'UPDATE child_bots SET health_status = ?, health_message = NULL, webhook_error = NULL,
             last_health_check = NOW(), problem_since = NULL, updated_at = NOW() WHERE id = ?'
        );
        $stmt->bind_param('si', $code, $botId);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $stmt = $db->prepare(
        'UPDATE child_bots SET health_status = ?, health_message = ?, webhook_error = ?,
         last_health_check = NOW(), problem_since = COALESCE(problem_since, NOW()), updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('sssi', $code, $message, $webhookError, $botId);
    $stmt->execute();
    $stmt->close();
}

function notifyAdminsChildBotProblem(array $bot, string $label): void
{
    global $admin_telegram_ids;
    if (empty($admin_telegram_ids)) {
        return;
    }

    $name = trim((string) ($bot['bot_name'] ?? ''));
    if ($name === '') {
        $name = 'ربات';
    }
    $username = trim((string) ($bot['bot_username'] ?? ''));
    $usernameLine = $username !== '' ? '@' . $username : 'بدون یوزرنیم';
    $text = "⚠️ مشکل ربات آپلودر\n\n"
        . "🤖 {$name}\n"
        . "👤 {$usernameLine}\n"
        . "🆔 bot_id: " . (int) ($bot['bot_telegram_id'] ?? 0) . "\n"
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

function runServerChildBotHealthPass(int $maxProbes = 0): int
{
    ensureChildBotHealthColumns();
    $db = getDb();

    $limit = $maxProbes > 0 ? (int) $maxProbes : 100;
    $result = $db->query(
        "SELECT id, bot_telegram_id, bot_username, bot_name, bot_token, webhook_key, bot_type,
                uploader_version_id, health_status, status
         FROM child_bots
         WHERE status = 'active'
         ORDER BY last_health_check IS NULL DESC, last_health_check ASC, id ASC
         LIMIT {$limit}"
    );

    if (!$result) {
        return 0;
    }

    $probed = 0;
    while ($row = $result->fetch_assoc()) {
        $botId = (int) ($row['id'] ?? 0);
        if ($botId <= 0) {
            continue;
        }

        $previousCode = (string) ($row['health_status'] ?? 'ok');
        $issue = probeChildBotHealth($row);
        $webhookError = ($issue['code'] ?? '') === 'webhook_error' ? ($issue['raw'] ?? null) : null;
        persistChildBotHealthCheck($botId, $issue, is_string($webhookError) ? $webhookError : null);

        if (($issue['code'] ?? '') !== 'ok' && $previousCode === 'ok') {
            notifyAdminsChildBotProblem($row, (string) ($issue['label'] ?? labelBotProblem('api_error')));
            if (in_array((string) ($row['bot_type'] ?? ''), ['guardian', 'uploader'], true)) {
                require_once __DIR__ . '/zapas_bots.php';
                attemptZapasReplacementForBot($botId, (string) ($issue['code'] ?? 'bot_banned'));
            }
        }

        $probed++;
    }

    return $probed;
}

/**
 * @return list<array<string, mixed>>
 */
function getChildBotProblems(): array
{
    ensureChildBotHealthColumns();
    $db = getDb();

    $userCountSql = '';
    $tableCheck = $db->query("SHOW TABLES LIKE 'uploader_users'");
    if ($tableCheck && $tableCheck->num_rows > 0) {
        $userCountSql = ', (SELECT COUNT(*) FROM uploader_users uu WHERE uu.child_bot_id = cb.id) AS user_count';
    }

    try {
        $result = $db->query(
            "SELECT cb.id, cb.bot_telegram_id, cb.bot_username, cb.bot_name, cb.bot_type, cb.status,
                    cb.health_status, cb.health_message, cb.problem_since, cb.last_health_check, cb.webhook_error,
                    cb.updated_at{$userCountSql}
             FROM child_bots cb
             WHERE cb.status <> 'active' OR cb.health_status NOT IN ('ok')
             ORDER BY COALESCE(cb.problem_since, cb.updated_at) DESC, cb.id DESC"
        );
    } catch (Throwable $e) {
        error_log('getChildBotProblems: ' . $e->getMessage());

        return [];
    }

    if (!$result) {
        return [];
    }

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $code = (string) ($row['health_status'] ?? 'api_error');
        if ($code === 'ok' && ($row['status'] ?? '') !== 'active') {
            $code = 'bot_disabled';
        }
        if ($code === 'ok') {
            continue;
        }

        $label = (string) ($row['health_message'] ?? labelBotProblem($code));
        if ($label === '' || $label === 'ok') {
            $label = labelBotProblem($code);
        }

        $rows[] = [
            'id' => (int) ($row['id'] ?? 0),
            'bot_telegram_id' => (int) ($row['bot_telegram_id'] ?? 0),
            'bot_name' => (string) ($row['bot_name'] ?? 'بدون نام'),
            'bot_username' => (string) ($row['bot_username'] ?? ''),
            'bot_type' => (string) ($row['bot_type'] ?? 'uploader'),
            'status' => (string) ($row['status'] ?? 'active'),
            'problem_code' => $code,
            'problem_label' => $label,
            'webhook_error' => (string) ($row['webhook_error'] ?? ''),
            'since_at' => $row['problem_since'] ?: $row['updated_at'],
            'last_health_check' => $row['last_health_check'] ?? null,
            'user_count' => isset($row['user_count']) ? (int) $row['user_count'] : null,
        ];
    }

    return $rows;
}
