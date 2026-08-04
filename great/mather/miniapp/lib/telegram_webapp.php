<?php

function normalizeInitData(string $initData): string
{
    $initData = trim($initData);
    if ($initData === '') {
        return '';
    }

    if (!str_contains($initData, 'hash=') && str_contains($initData, '%')) {
        $decoded = urldecode($initData);
        if (str_contains($decoded, 'hash=')) {
            return $decoded;
        }
    }

    return $initData;
}

function parseTelegramInitData(string $initData): ?array
{
    $initData = normalizeInitData($initData);
    if ($initData === '') {
        return null;
    }

    parse_str($initData, $params);
    if (empty($params['hash'])) {
        return null;
    }

    $hash = $params['hash'];
    unset($params['hash']);

    ksort($params);
    $pairs = [];
    foreach ($params as $key => $value) {
        $pairs[] = $key . '=' . $value;
    }
    $dataCheckString = implode("\n", $pairs);

    global $bot_token;
    $secretKey = hash_hmac('sha256', $bot_token, 'WebAppData', true);
    $calculatedHash = bin2hex(hash_hmac('sha256', $dataCheckString, $secretKey, true));

    if (!hash_equals($calculatedHash, $hash)) {
        return null;
    }

    if (!empty($params['auth_date'])) {
        $authDate = (int) $params['auth_date'];
        if ($authDate < time() - 86400 * 7) {
            return null;
        }
    }

    $user = [];
    if (!empty($params['user'])) {
        $decoded = json_decode($params['user'], true);
        if (is_array($decoded)) {
            $user = $decoded;
        }
    }

    return [
        'user' => $user,
        'auth_date' => (int) ($params['auth_date'] ?? 0),
        'query_id' => $params['query_id'] ?? null,
    ];
}

function getRequestInitData(): string
{
    $header = $_SERVER['HTTP_X_TELEGRAM_INIT_DATA'] ?? '';
    if ($header !== '') {
        return $header;
    }

    if (!empty($_POST['initData'])) {
        return (string) $_POST['initData'];
    }

    return $_GET['initData'] ?? '';
}

function getJsonRequestBody(): array
{
    static $body = null;
    if ($body !== null) {
        return $body;
    }
    $raw = file_get_contents('php://input');
    $body = is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : [];
    return $body;
}

function requireTelegramUser(): array
{
    require_once dirname(__DIR__, 2) . '/config.php';

    $initData = getRequestInitData();
    if ($initData === '') {
        $body = getJsonRequestBody();
        if (!empty($body['initData'])) {
            $initData = (string) $body['initData'];
        }
    }

    $parsed = parseTelegramInitData($initData);
    if (!$parsed || empty($parsed['user']['id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    return $parsed['user'];
}

function isAdminTelegramId(int $telegramId): bool
{
    global $admin_telegram_ids;
    $ids = array_map('intval', $admin_telegram_ids ?? []);

    return in_array($telegramId, $ids, true);
}

function jsonResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
