<?php

require_once __DIR__ . '/manage_bots.php';

function normalizeManageBotChannelId(int|string $channelId): string
{
    return trim((string) $channelId);
}

function resolveManageBotForChannel(int|string $channelId): ?array
{
    $channelKey = normalizeManageBotChannelId($channelId);
    if ($channelKey === '' || $channelKey === '0') {
        $primary = getPrimaryManageBotRaw();
        if (!$primary) {
            return null;
        }
        $formatted = formatManageBotRow($primary);
        $formatted['bot_token'] = (string) ($primary['bot_token'] ?? '');

        return $formatted;
    }

    $numericChannelId = (int) $channelKey;
    if ($numericChannelId !== 0) {
        $candidates = listManageBotCandidatesForChannel($numericChannelId);
        if ($candidates !== []) {
            return $candidates[0];
        }
    }

    $primary = getPrimaryManageBotRaw();
    if (!$primary) {
        return null;
    }

    $formatted = formatManageBotRow($primary);
    $formatted['bot_token'] = (string) ($primary['bot_token'] ?? '');

    return $formatted;
}

function resolveManageBotTokenForChannel(int|string $channelId = 0): string
{
    if ($channelId === 0 || $channelId === '0' || $channelId === '') {
        $primary = getPrimaryManageBotRaw();
        return (string) ($primary['bot_token'] ?? getManageBotTokenFromConfig());
    }

    $bot = resolveManageBotForChannel($channelId);

    return (string) ($bot['bot_token'] ?? getManageBotTokenFromConfig());
}

function getManageBotTokenFromConfig(): string
{
    static $token = null;
    if ($token !== null) {
        return $token;
    }

    $configPath = dirname(__DIR__) . '/config.php';
    if (!is_file($configPath)) {
        $token = '';

        return $token;
    }

    $token = (static function () use ($configPath): string {
        $bot_token = '';
        require $configPath;

        return (string) $bot_token;
    })();

    return $token;
}

function manageBotTelegramRequestForChannel(int|string $channelId, string $method, array $params = []): ?array
{
    $token = resolveManageBotTokenForChannel($channelId);
    if ($token === '') {
        return null;
    }

    return tgRequestWithToken($token, $method, $params);
}

function manageBotGetChatMemberRouted(string $channelId, int $userId): ?array
{
    $channelId = normalizeManageBotChannelId($channelId);
    if ($channelId === '' || $userId <= 0) {
        return null;
    }

    $numericChannelId = (int) $channelId;
    $candidates = $numericChannelId !== 0
        ? listManageBotCandidatesForChannel($numericChannelId)
        : [];

    if ($candidates === []) {
        $primary = getPrimaryManageBotRaw();
        if ($primary) {
            $formatted = formatManageBotRow($primary);
            $formatted['bot_token'] = (string) ($primary['bot_token'] ?? '');
            $candidates = [$formatted];
        }
    }

    foreach ($candidates as $bot) {
        $token = (string) ($bot['bot_token'] ?? '');
        if ($token === '') {
            continue;
        }
        $resp = tgRequestWithToken($token, 'getChatMember', [
            'chat_id' => $channelId,
            'user_id' => $userId,
        ]);
        if (!empty($resp['ok'])) {
            return $resp;
        }
    }

    $fallbackToken = getManageBotTokenFromConfig();
    if ($fallbackToken === '') {
        return null;
    }

    return tgRequestWithToken($fallbackToken, 'getChatMember', [
        'chat_id' => $channelId,
        'user_id' => $userId,
    ]);
}

function manageBotChannelAccessRouted(string $channelId): array
{
    $channelId = normalizeManageBotChannelId($channelId);
    if ($channelId === '') {
        return ['ok' => false, 'error' => 'channel_id_required', 'label' => 'آیدی کانال الزامی است'];
    }

    $numericChannelId = (int) $channelId;
    $candidates = $numericChannelId !== 0
        ? listManageBotCandidatesForChannel($numericChannelId)
        : [];

    if ($candidates === []) {
        $primary = getPrimaryManageBotRaw();
        if ($primary) {
            $formatted = formatManageBotRow($primary);
            $formatted['bot_token'] = (string) ($primary['bot_token'] ?? '');
            $candidates = [$formatted];
        }
    }

    $lastError = null;
    foreach ($candidates as $bot) {
        $token = (string) ($bot['bot_token'] ?? '');
        $botId = (int) ($bot['bot_telegram_id'] ?? 0);
        if ($token === '' || $botId <= 0) {
            continue;
        }

        $resp = tgRequestWithToken($token, 'getChatMember', [
            'chat_id' => $channelId,
            'user_id' => $botId,
        ]);

        if (empty($resp['ok'])) {
            $lastError = $resp;
            continue;
        }

        $status = (string) ($resp['result']['status'] ?? 'unknown');
        if (in_array($status, ['administrator', 'creator'], true)) {
            return [
                'ok' => true,
                'status' => $status,
                'label' => $status === 'creator' ? 'ربات مدیریت سازنده کانال است' : 'ربات مدیریت ادمین کانال است',
                'manage_bot_id' => (int) ($bot['id'] ?? 0),
                'manage_bot_username' => (string) ($bot['bot_username'] ?? ''),
            ];
        }

        $lastError = $resp;
    }

    if ($lastError) {
        $desc = (string) ($lastError['description'] ?? 'خطای API');

        return [
            'ok' => false,
            'error' => 'manage_bot_api_error',
            'label' => 'خطا در بررسی کانال: ' . $desc,
            'description' => $desc,
        ];
    }

    return ['ok' => false, 'error' => 'manage_bot_unavailable', 'label' => 'ربات مدیریت در دسترس نیست'];
}
