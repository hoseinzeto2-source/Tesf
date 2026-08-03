<?php

require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/force_join.php';
require_once __DIR__ . '/child_runtime.php';

function isGuardianBotMode(): bool
{
    $bot = activeChildBot();

    return is_array($bot) && (($bot['bot_type'] ?? '') === 'guardian');
}

function autoPostMediaLabel(string $type): string
{
    return match ($type) {
        'photo' => 'عکس',
        'video' => 'فیلم',
        'voice' => 'ویس',
        default => 'فایل',
    };
}

/**
 * @return array<string, mixed>|null
 */
function getGuardianLinkRow(int $guardianBotId, string $linkCode): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT gl.*, u.bot_username AS uploader_username
         FROM auto_post_guardian_links gl
         INNER JOIN child_bots u ON u.id = gl.uploader_bot_id
         WHERE gl.guardian_bot_id = ? AND gl.link_code = ?
         ORDER BY gl.id DESC LIMIT 1'
    );
    $stmt->bind_param('is', $guardianBotId, $linkCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

/**
 * @return array<string, mixed>|null
 */
function getMatherUploaderFile(int $childBotId, string $linkCode): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM uploader_files WHERE child_bot_id = ? AND link_code = ? LIMIT 1'
    );
    $stmt->bind_param('is', $childBotId, $linkCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function incrementMatherDownloadCount(int $fileId): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE uploader_files SET download_count = download_count + 1 WHERE id = ?');
    $stmt->bind_param('i', $fileId);
    $stmt->execute();
    $stmt->close();
}

function resolveMatherAutoPostLocalPath(string $path): string
{
    $path = trim($path);
    if ($path === '') {
        return '';
    }
    if (is_file($path)) {
        return $path;
    }

    $basename = basename($path);
    $candidates = [
        dirname(__DIR__, 2) . '/../mather/storage/auto_post_media/' . $basename,
        dirname(__DIR__, 3) . '/great/mather/storage/auto_post_media/' . $basename,
    ];
    foreach ($candidates as $candidate) {
        $resolved = realpath($candidate);
        if ($resolved !== false && is_file($resolved)) {
            return $resolved;
        }
    }

    return $path;
}

function deliverMatherUploaderFile(int $chatId, array $fileRow): bool
{
    $fileType = (string) ($fileRow['file_type'] ?? 'photo');
    $fileId = (string) ($fileRow['file_id'] ?? $fileRow['telegram_file_id'] ?? '');
    $localPath = resolveMatherAutoPostLocalPath((string) ($fileRow['local_cache_path'] ?? $fileRow['relative_path'] ?? ''));
    $caption = trim((string) ($fileRow['caption'] ?? ''));
    $mimeType = (string) ($fileRow['mime_type'] ?? 'application/octet-stream');

    if ($fileId === '' && $localPath !== '' && is_file($localPath)) {
        $filename = basename($localPath);
        if (class_exists('CURLFile')) {
            $upload = new CURLFile($localPath, $mimeType !== '' ? $mimeType : 'application/octet-stream', $filename);
        } elseif (function_exists('curl_file_create')) {
            $upload = curl_file_create($localPath, $mimeType !== '' ? $mimeType : 'application/octet-stream', $filename);
        } else {
            return false;
        }

        $params = [
            'chat_id' => $chatId,
            'disable_notification' => false,
        ];
        if ($caption !== '') {
            $params['caption'] = $caption;
        }

        $method = match ($fileType) {
            'photo' => 'sendPhoto',
            'video' => 'sendVideo',
            'voice' => 'sendVoice',
            default => 'sendDocument',
        };
        $field = match ($fileType) {
            'photo' => 'photo',
            'video' => 'video',
            'voice' => 'voice',
            default => 'document',
        };
        $params[$field] = $upload;

        $resp = telegramRequestMultipart($method, $params);
        if (!empty($resp['ok'])) {
            incrementMatherDownloadCount((int) ($fileRow['id'] ?? 0));

            return true;
        }

        return false;
    }

    if ($fileId === '') {
        return false;
    }

    $params = [
        'chat_id' => $chatId,
        'disable_notification' => false,
    ];
    if ($caption !== '') {
        $params['caption'] = $caption;
    }

    $ok = false;
    if ($fileType === 'photo') {
        $params['photo'] = $fileId;
        $resp = telegramRequest('sendPhoto', $params);
        $ok = !empty($resp['ok']);
    } elseif ($fileType === 'video') {
        $params['video'] = $fileId;
        $resp = telegramRequest('sendVideo', $params);
        $ok = !empty($resp['ok']);
    } elseif ($fileType === 'voice') {
        $params['voice'] = $fileId;
        $resp = telegramRequest('sendVoice', $params);
        $ok = !empty($resp['ok']);
    } else {
        $params['document'] = $fileId;
        $resp = telegramRequest('sendDocument', $params);
        $ok = !empty($resp['ok']);
    }

    if ($ok) {
        incrementMatherDownloadCount((int) ($fileRow['id'] ?? 0));
    }

    return $ok;
}

function handleGuardianStartPayload(int $chatId, int $telegramId, string $payload, bool $isAdminUser): bool
{
    if (!isGuardianBotMode()) {
        return false;
    }
    if (!preg_match('/^g_([a-zA-Z0-9]{8,20})$/', $payload, $m)) {
        return false;
    }

    $linkCode = (string) $m[1];
    $guardianId = activeChildBotId();
    if ($guardianId <= 0) {
        return false;
    }

    if (!$isAdminUser && !userPassesAllJoinRules($telegramId)) {
        sendForceJoinPrompt($chatId, $telegramId);

        return true;
    }

    $link = getGuardianLinkRow($guardianId, $linkCode);
    if ($link === null) {
        sendMessage($chatId, '⚠️ لینک منقضی یا نامعتبر است.');

        return true;
    }

    $mediaType = (string) ($link['media_type'] ?? 'photo');
    $typeLabel = autoPostMediaLabel($mediaType);
    $uploaderUsername = trim((string) ($link['uploader_username'] ?? ''));
    if ($uploaderUsername === '') {
        sendMessage($chatId, '⚠️ ربات محتوا در دسترس نیست.');

        return true;
    }

    $url = 'https://t.me/' . $uploaderUsername . '?start=' . rawurlencode($linkCode);
    $text = "لینک {$typeLabel} این است 👇";

    $ownerId = (int) (activeChildBot()['owner_telegram_id'] ?? 0);
    $glassLib = dirname(__DIR__, 2) . '/mather/lib/glass_button_tools.php';
    if ($ownerId > 0 && is_file($glassLib)) {
        require_once $glassLib;
        $delivery = buildGuardianGlassButtonDelivery($ownerId, $url, $mediaType, $text);
        $extra = [];
        if (!empty($delivery['use_inline']) && !empty($delivery['reply_markup'])) {
            $decoded = json_decode((string) $delivery['reply_markup'], true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }
        sendMessage($chatId, $delivery['text'], $extra);

        return true;
    }

    sendMessage($chatId, $text, [
        'inline_keyboard' => [[
            ['text' => "دریافت {$typeLabel}", 'url' => $url],
        ]],
    ]);

    return true;
}

function handleMatherLinkCodeStart(int $chatId, int $telegramId, string $linkCode, bool $isAdminUser): bool
{
    if (isGuardianBotMode()) {
        return false;
    }

    $childBotId = activeChildBotId();
    if ($childBotId <= 0) {
        return false;
    }

    if (!preg_match('/^[a-zA-Z0-9]{8,20}$/', $linkCode)) {
        return false;
    }

    if (!$isAdminUser && !userPassesAllJoinRules($telegramId)) {
        sendForceJoinPrompt($chatId, $telegramId);

        return true;
    }

    $fileRow = getMatherUploaderFile($childBotId, $linkCode);
    if ($fileRow === null) {
        sendMessage($chatId, '⚠️ محتوا یافت نشد یا منقضی شده است.');

        return true;
    }

    if (!deliverMatherUploaderFile($chatId, $fileRow)) {
        sendMessage($chatId, '⚠️ ارسال محتوا ناموفق بود.');

        return true;
    }

    return true;
}
