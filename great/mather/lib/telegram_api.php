<?php

if (!function_exists('telegramRequest')) {
    function telegramRequest(string $method, array $params): ?array
    {
        global $bot_token;
        $url = "https://api.telegram.org/bot{$bot_token}/{$method}";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response ? json_decode($response, true) : null;
    }
}

if (!function_exists('getBotId')) {
    function getBotId(): int
    {
        static $botId = null;
        if ($botId !== null) {
            return $botId;
        }

        $response = telegramRequest('getMe', []);
        $botId = (int) ($response['result']['id'] ?? 0);

        return $botId;
    }
}

function tgRequestWithToken(string $token, string $method, array $params = []): ?array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response ? json_decode($response, true) : null;
}

function tgGetMe(string $token): ?array
{
    $response = tgRequestWithToken($token, 'getMe');
    if (empty($response['ok']) || empty($response['result'])) {
        return null;
    }

    return $response['result'];
}

function tgSetWebhook(string $token, string $url): bool
{
    $response = tgRequestWithToken($token, 'setWebhook', [
        'url' => $url,
        'allowed_updates' => json_encode(['message', 'callback_query', 'my_chat_member', 'chat_member', 'channel_post']),
        'drop_pending_updates' => true,
    ]);

    return !empty($response['ok']);
}

function tgRequestWithTokenMultipart(string $token, string $method, array $params = []): ?array
{
    $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response ? json_decode($response, true) : null;
}

function tgApiErrorMessage(?array $response, string $fallback = 'telegram_error'): string
{
    if (!$response) {
        return $fallback;
    }
    if (!empty($response['ok'])) {
        return '';
    }

    return (string) ($response['description'] ?? $fallback);
}

function tgMakeUploadFile(string $filePath, string $mime, string $filename): CURLFile
{
    if (class_exists('CURLFile')) {
        return new CURLFile($filePath, $mime, $filename);
    }
    if (function_exists('curl_file_create')) {
        return curl_file_create($filePath, $mime, $filename);
    }

    throw new RuntimeException('curl_upload_unavailable');
}

function tgSetMyProfilePhoto(string $token, string $filePath, string $mime = 'image/jpeg'): ?array
{
    $file = tgMakeUploadFile($filePath, $mime, 'profile.jpg');

    return tgRequestWithTokenMultipart($token, 'setMyProfilePhoto', [
        'photo' => json_encode([
            'type' => 'static',
            'photo' => 'attach://profile_photo',
        ], JSON_UNESCAPED_SLASHES),
        'profile_photo' => $file,
    ]);
}
