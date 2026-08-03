<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/telegram_api.php';
require_once __DIR__ . '/lib/channels.php';
require_once __DIR__ . '/lib/content_groups.php';
require_once __DIR__ . '/lib/content_group_stats.php';
require_once __DIR__ . '/lib/channel_stats.php';
require_once __DIR__ . '/lib/invite_links.php';

$content = file_get_contents('php://input');
file_put_contents(matherLogPath('update.log'), date('Y-m-d H:i:s') . ' ' . $content . PHP_EOL, FILE_APPEND);

$update = json_decode($content, true);
if (!$update) {
    http_response_code(400);
    exit;
}

if (isset($update['my_chat_member'])) {
    try {
        $chat = $update['my_chat_member']['chat'] ?? [];
        $chatType = (string) ($chat['type'] ?? '');
        $chatId = (int) ($chat['id'] ?? 0);
        if ($chatType === 'channel') {
            handleMyChatMember($update);
            if ($chatId > 0) {
                refreshChannelMetadata($chatId);
                recordMemberSnapshot($chatId);
            }
        } elseif (isContentGroupChatType($chatType)) {
            handleContentGroupMyChatMember($update);
        }
    } catch (Throwable $e) {
        error_log('my_chat_member failed: ' . $e->getMessage());
    }
}

if (isset($update['channel_post'])) {
    try {
        handleChannelPostUpdate($update['channel_post']);
    } catch (Throwable $e) {
        error_log('channel_post failed: ' . $e->getMessage());
    }
}

if (isset($update['edited_channel_post'])) {
    try {
        handleChannelPostUpdate($update['edited_channel_post']);
    } catch (Throwable $e) {
        error_log('edited_channel_post failed: ' . $e->getMessage());
    }
}

if (isset($update['chat_member'])) {
    try {
        handleChatMemberUpdate($update);
    } catch (Throwable $e) {
        error_log('chat_member failed: ' . $e->getMessage());
    }
}

if (isset($update['message'])) {
    try {
        recordContentGroupMessage($update['message']);
    } catch (Throwable $e) {
        error_log('content group stats failed: ' . $e->getMessage());
    }
    handleMessage($update['message']);
}

if (isset($update['callback_query'])) {
    handleCallback($update['callback_query']);
}

function handleMessage(array $message): void
{
    $chat_id = $message['chat']['id'];
    $chatType = (string) ($message['chat']['type'] ?? '');
    $text = trim($message['text'] ?? '');
    $from = $message['from'] ?? [];
    $first_name = $from['first_name'] ?? 'کاربر';

    if (isContentGroupChatType($chatType)) {
        try {
            syncContentGroupFromChat($message['chat']);
        } catch (Throwable $e) {
            error_log('content group sync failed: ' . $e->getMessage());
        }
        return;
    }

    try {
        $userInfo = registerOrUpdateUser($from, $message['chat']);
    } catch (Throwable $e) {
        error_log('User registration failed: ' . $e->getMessage());
        sendMessage($chat_id, 'خطا در اتصال به دیتابیس. لطفاً بعداً دوباره تلاش کنید.');
        return;
    }

    if ($text === '/start') {
        $reply = "سلام {$first_name}! 👋\n\n";
        $reply .= "به ربات manage (@gpro100_bot) خوش آمدید.\n\n";

        if ($userInfo['is_new']) {
            $reply .= "حساب شما با موفقیت در دیتابیس ثبت شد.\n\n";
        } else {
            $reply .= "خوش برگشتید! اطلاعات شما به‌روزرسانی شد.\n\n";
        }

        $reply .= "دستورات:\n";
        $reply .= "/start - شروع\n";
        $reply .= "/panel - باز کردن پنل مینی‌اپ\n";
        $reply .= "/help - راهنما\n";
        $reply .= "/id - نمایش شناسه چت\n";
        $reply .= "/stats - تعداد کاربران ثبت‌شده";
        sendMessage($chat_id, $reply, [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '📱 باز کردن پنل', 'web_app' => ['url' => 'https://shombol.s16.viptelbot.top/great/mather/miniapp/index.php']],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    if ($text === '/panel') {
        sendMessage($chat_id, 'پنل مینی‌اپ را از دکمه زیر باز کنید:', [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[
                    ['text' => '📱 پنل manage', 'web_app' => ['url' => 'https://shombol.s16.viptelbot.top/great/mather/miniapp/index.php']],
                ]],
            ], JSON_UNESCAPED_UNICODE),
        ]);
        return;
    }

    if ($text === '/help') {
        sendMessage($chat_id, "ربات آماده است. هر پیامی بفرستید تا echo شود.");
        return;
    }

    if ($text === '/id') {
        sendMessage($chat_id, "شناسه چت شما: {$chat_id}");
        return;
    }

    if ($text === '/stats') {
        try {
            $total = getUserCount();
            sendMessage($chat_id, "تعداد کاربران ثبت‌شده در دیتابیس: {$total}");
        } catch (Throwable $e) {
            sendMessage($chat_id, 'خطا در خواندن آمار کاربران.');
        }
        return;
    }

    global $admin_telegram_ids;
    $isAdmin = in_array((int) ($from['id'] ?? 0), $admin_telegram_ids ?? [], true);
    if ($isAdmin && $text === '/publish_miniapp') {
        if (!is_file(__DIR__ . '/lib/miniapp_publish.php')) {
            sendMessage($chat_id, 'فایل lib/miniapp_publish.php روی سرور نیست. publish_miniapp.php را آپلود کنید یا بسته zip را با POST بفرستید.');
            return;
        }
        require_once __DIR__ . '/lib/miniapp_publish.php';
        $pub = publishMiniappFromGitHub('cursor/channel-analytics-ui-0883');
        if (!$pub['ok'] && is_dir(__DIR__ . '/miniapp')) {
            $pub = [
                'ok' => true,
                'written' => ['local_skip'],
                'note' => 'GitHub در دسترس نیست؛ فایل‌های محلی سرور بدون تغییر ماندند.',
            ];
        }
        $msg = !empty($pub['ok'])
            ? 'مینی‌اپ: ' . implode(', ', $pub['written'] ?? [])
            : 'خطا: ' . implode('; ', $pub['errors'] ?? []);
        sendMessage($chat_id, $msg);
        return;
    }

    if ($isAdmin && !empty($message['document']) && ($message['caption'] ?? '') === '#miniapp_zip') {
        $doc = $message['document'];
        $fileId = $doc['file_id'] ?? '';
        if ($fileId === '') {
            return;
        }
        $file = telegramRequest('getFile', ['file_id' => $fileId]);
        $path = $file['result']['file_path'] ?? '';
        if ($path === '') {
            sendMessage($chat_id, 'دریافت فایل ناموفق بود.');
            return;
        }
        global $bot_token;
        $url = "https://api.telegram.org/file/bot{$bot_token}/{$path}";
        $zipBody = file_get_contents($url);
        if ($zipBody === false || $zipBody === '') {
            sendMessage($chat_id, 'دانلود zip ناموفق بود.');
            return;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'miniapp');
        file_put_contents($tmp, $zipBody);
        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            sendMessage($chat_id, 'zip نامعتبر است.');
            return;
        }
        $root = __DIR__ . '/miniapp';
        $allowed = [
            'index.php' => $root . '/index.php',
            'css/miniapp.css' => $root . '/css/miniapp.css',
            'js/app.js' => $root . '/js/app.js',
        ];
        $written = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = str_replace('\\', '/', (string) $zip->getNameIndex($i));
            $name = ltrim(preg_replace('#^miniapp/#', '', $name), '/');
            if (!isset($allowed[$name])) {
                continue;
            }
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                continue;
            }
            $localPath = $allowed[$name];
            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($localPath, $content);
            $written[] = $name;
        }
        $zip->close();
        @unlink($tmp);
        sendMessage($chat_id, $written ? 'مینی‌اپ به‌روز شد: ' . implode(', ', $written) : 'هیچ فایل مجازی در zip نبود.');
        return;
    }

    if ($text !== '') {
        sendMessage($chat_id, "پیام شما دریافت شد:\n" . $text);
    }
}

function handleCallback(array $callback): void
{
    answerCallbackQuery($callback['id']);

    $chat_id = $callback['message']['chat']['id'] ?? null;
    $chatType = (string) ($callback['message']['chat']['type'] ?? '');
    $data = $callback['data'] ?? '';
    if (!$chat_id || isContentGroupChatType($chatType)) {
        return;
    }
    sendMessage($chat_id, "دکمه انتخاب شد: {$data}");
}

function sendMessage($chat_id, string $text, array $extra = []): void
{
    telegramRequest('sendMessage', array_merge([
        'chat_id' => $chat_id,
        'text' => $text,
    ], $extra));
}

function answerCallbackQuery(string $callback_id): void
{
    telegramRequest('answerCallbackQuery', ['callback_query_id' => $callback_id]);
}
