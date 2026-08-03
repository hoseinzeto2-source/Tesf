<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/telegram_api.php';
require_once __DIR__ . '/lib/manage_bots.php';
require_once __DIR__ . '/lib/channels.php';
require_once __DIR__ . '/lib/content_groups.php';
require_once __DIR__ . '/lib/content_group_stats.php';
require_once __DIR__ . '/lib/channel_stats.php';
require_once __DIR__ . '/lib/invite_links.php';

$key = (string) ($_GET['key'] ?? '');
$manageBot = getManageBotByKey($key);
if (!$manageBot || !empty($manageBot['is_primary'])) {
    http_response_code(403);
    exit;
}

$content = file_get_contents('php://input');
if (function_exists('matherLogPath')) {
    file_put_contents(
        matherLogPath('manage_bot_' . (int) ($manageBot['id'] ?? 0) . '.log'),
        date('Y-m-d H:i:s') . ' ' . $content . PHP_EOL,
        FILE_APPEND
    );
}

$update = json_decode((string) $content, true);
if (!$update) {
    http_response_code(400);
    exit;
}

$manageBotId = (int) ($manageBot['id'] ?? 0);

if (isset($update['my_chat_member'])) {
    try {
        $chat = $update['my_chat_member']['chat'] ?? [];
        $chatType = (string) ($chat['type'] ?? '');
        $chatId = (int) ($chat['id'] ?? 0);
        if ($chatType === 'channel') {
            handleMyChatMemberForManageBot($update, $manageBotId);
            if ($chatId > 0) {
                refreshChannelMetadata($chatId);
                recordMemberSnapshot($chatId);
            }
        } elseif (isContentGroupChatType($chatType)) {
            handleContentGroupMyChatMember($update);
        }
    } catch (Throwable $e) {
        error_log('manage_bot my_chat_member failed: ' . $e->getMessage());
    }
}

if (isset($update['channel_post'])) {
    try {
        handleChannelPostUpdate($update['channel_post']);
    } catch (Throwable $e) {
        error_log('manage_bot channel_post failed: ' . $e->getMessage());
    }
}

if (isset($update['edited_channel_post'])) {
    try {
        handleChannelPostUpdate($update['edited_channel_post']);
    } catch (Throwable $e) {
        error_log('manage_bot edited_channel_post failed: ' . $e->getMessage());
    }
}

if (isset($update['chat_member'])) {
    try {
        handleChatMemberUpdate($update);
    } catch (Throwable $e) {
        error_log('manage_bot chat_member failed: ' . $e->getMessage());
    }
}

if (isset($update['message'])) {
    try {
        recordContentGroupMessage($update['message']);
    } catch (Throwable $e) {
        error_log('manage_bot content group stats failed: ' . $e->getMessage());
    }

    $message = $update['message'];
    $chatType = (string) ($message['chat']['type'] ?? '');
    if (isContentGroupChatType($chatType)) {
        try {
            syncContentGroupFromChat($message['chat']);
        } catch (Throwable $e) {
            error_log('manage_bot content group sync failed: ' . $e->getMessage());
        }
    } elseif (($message['chat']['type'] ?? '') === 'private') {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $primary = getPrimaryManageBot();
        $primaryLabel = $primary && !empty($primary['bot_username'])
            ? '@' . $primary['bot_username']
            : '@gpro100_bot';
        if ($chatId > 0) {
            tgRequestWithToken(
                (string) ($manageBot['bot_token'] ?? ''),
                'sendMessage',
                [
                    'chat_id' => $chatId,
                    'text' => "این ربات فقط برای مدیریت کانال‌ها است.\nاز ربات اصلی {$primaryLabel} و مینی‌اپ استفاده کنید.",
                ]
            );
        }
    }
}

if (isset($update['callback_query'])) {
    $callbackId = (string) ($update['callback_query']['id'] ?? '');
    if ($callbackId !== '' && !empty($manageBot['bot_token'])) {
        tgRequestWithToken((string) $manageBot['bot_token'], 'answerCallbackQuery', [
            'callback_query_id' => $callbackId,
            'text' => 'از ربات اصلی استفاده کنید',
            'show_alert' => false,
        ]);
    }
}

http_response_code(200);
echo 'ok';
