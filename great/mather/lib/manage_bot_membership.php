<?php

require_once __DIR__ . '/manage_bot_router.php';

function getManageBotToken(): string
{
    $primary = getPrimaryManageBotRaw();

    return (string) ($primary['bot_token'] ?? getManageBotTokenFromConfig());
}

function getManageBotId(): int
{
    static $botId = null;
    if ($botId !== null) {
        return $botId;
    }

    $primary = getPrimaryManageBotRaw();
    if ($primary) {
        $botId = (int) ($primary['bot_telegram_id'] ?? 0);

        return $botId;
    }

    $token = getManageBotToken();
    if ($token === '') {
        $botId = 0;

        return 0;
    }

    $response = tgRequestWithToken($token, 'getMe');
    $botId = (int) ($response['result']['id'] ?? 0);

    return $botId;
}

function manageBotGetChatMember(string $channelId, int $userId): ?array
{
    return manageBotGetChatMemberRouted($channelId, $userId);
}

function manageBotMemberStatusIsJoined(string $status, array $member): bool
{
    if (in_array($status, ['member', 'administrator', 'creator'], true)) {
        return true;
    }
    if ($status === 'restricted') {
        return !empty($member['is_member']);
    }

    return false;
}

function manageBotUserIsMember(string $channelId, int $userId): bool
{
    $resp = manageBotGetChatMember($channelId, $userId);
    if (empty($resp['ok'])) {
        error_log('manage_bot_membership user check failed: ' . json_encode($resp, JSON_UNESCAPED_UNICODE));

        return false;
    }

    $status = (string) ($resp['result']['status'] ?? '');

    return manageBotMemberStatusIsJoined($status, $resp['result'] ?? []);
}

function manageBotChannelAccess(string $channelId): array
{
    return manageBotChannelAccessRouted($channelId);
}

function assertManageBotChannelAdmin(string $channelId): void
{
    $access = manageBotChannelAccess($channelId);
    if (empty($access['ok'])) {
        throw new InvalidArgumentException((string) ($access['error'] ?? 'manage_bot_not_admin'));
    }
}
