<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$body = getJsonRequestBody();
$initData = getRequestInitData();
if ($initData === '' && !empty($body['initData'])) {
    $initData = (string) $body['initData'];
}

$parsed = parseTelegramInitData($initData);
if (!$parsed || empty($parsed['user']['id'])) {
    jsonResponse(['ok' => false, 'error' => 'invalid_init_data'], 401);
}

$user = $parsed['user'];
$chat = ['type' => 'private', 'id' => $user['id']];

try {
    $registration = registerOrUpdateUser($user, $chat);
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM users WHERE telegram_id = ? LIMIT 1');
    $telegramId = (int) $user['id'];
    $stmt->bind_param('i', $telegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
} catch (Throwable $e) {
    error_log('miniapp auth failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'database_error'], 500);
}

jsonResponse([
    'ok' => true,
    'is_new' => $registration['is_new'],
    'is_admin' => isAdminTelegramId($telegramId),
    'user' => [
        'telegram_id' => (int) $row['telegram_id'],
        'username' => $row['username'],
        'first_name' => $row['first_name'],
        'last_name' => $row['last_name'],
        'language_code' => $row['language_code'],
        'joined_at' => $row['joined_at'],
        'last_seen_at' => $row['last_seen_at'],
    ],
    'telegram' => [
        'id' => (int) ($user['id'] ?? 0),
        'first_name' => $user['first_name'] ?? '',
        'last_name' => $user['last_name'] ?? '',
        'username' => $user['username'] ?? '',
        'photo_url' => $user['photo_url'] ?? null,
    ],
]);
