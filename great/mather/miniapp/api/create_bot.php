<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/child_bots.php';
require_once dirname(__DIR__, 2) . '/lib/bot_folders.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$body = getJsonRequestBody();
$token = trim($body['token'] ?? '');
$name = trim($body['name'] ?? '');
$type = trim((string) ($body['type'] ?? 'uploader'));
$channelFolderId = (int) ($body['channel_folder_id'] ?? 0);
$botFolderId = isset($body['bot_folder_id']) ? (int) $body['bot_folder_id'] : 0;
$uploaderVersionId = (int) ($body['uploader_version_id'] ?? 0);

if (!in_array($type, ['uploader', 'guardian'], true)) {
    $type = 'uploader';
}

if ($token === '') {
    jsonResponse(['ok' => false, 'error' => 'token_required'], 400);
}

if ($channelFolderId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'channel_folder_required'], 400);
}

try {
    $bot = $type === 'guardian'
        ? createGuardianBot($telegramId, $token, $name, $channelFolderId, $uploaderVersionId)
        : createUploaderBot($telegramId, $token, $name, $channelFolderId, $uploaderVersionId);

    $botId = (int) ($bot['id'] ?? 0);
    if ($botId > 0 && $botFolderId > 0) {
        assignBotToFolder($botId, $botFolderId, $telegramId);
        $bot['folder_id'] = $botFolderId;
    }

    require_once dirname(__DIR__, 2) . '/lib/uploader_versions.php';
    $version = getUploaderVersionById((int) ($bot['uploader_version_id'] ?? 0));
    $versionLabel = $version['name'] ?? ($type === 'guardian' ? 'مسترمحافظ v1.0' : 'مستراپلودر v1.0');

    jsonResponse([
        'ok' => true,
        'message' => $versionLabel . ' با موفقیت ساخته و webhook تنظیم شد.',
        'bot' => $bot,
    ]);
} catch (InvalidArgumentException $e) {
    $code = $e->getMessage();
    $status = in_array($code, ['channel_folder_required', 'invalid_channel_folder', 'uploader_version_required'], true) ? 400 : 400;
    jsonResponse(['ok' => false, 'error' => $code === 'invalid_token' ? 'invalid_token' : $code], $status);
} catch (RuntimeException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('create_bot failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
