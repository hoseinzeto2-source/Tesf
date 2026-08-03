<?php

require_once __DIR__ . '/config.php';

if (!defined('MATHER_ROOT')) {
    define('MATHER_ROOT', __DIR__);
}

/**
 * Absolute path inside great/mather (project root).
 */
function matherPath(string $relative = ''): string
{
    if ($relative === '') {
        return MATHER_ROOT;
    }

    return MATHER_ROOT . '/' . ltrim(str_replace('\\', '/', $relative), '/');
}

/**
 * Writable log file under storage/logs (creates directory if needed).
 */
function matherLogPath(string $filename): string
{
    $dir = matherPath('storage/logs');
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return MATHER_ROOT . '/' . ltrim($filename, '/');
    }

    return $dir . '/' . ltrim($filename, '/');
}

function getDb(): mysqli
{
    global $db_host, $db_user, $db_pass, $db_name;
    static $db = null;

    if ($db === null) {
        $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($db->connect_error) {
            error_log('DB connection failed: ' . $db->connect_error);
            throw new RuntimeException('Database connection failed');
        }
        $db->set_charset('utf8mb4');
    }

    return $db;
}

function registerOrUpdateUser(array $from, array $chat): array
{
    $db = getDb();
    $telegramId = (int) ($from['id'] ?? 0);
    if ($telegramId <= 0) {
        return ['is_new' => false, 'telegram_id' => 0];
    }

    $username = $from['username'] ?? null;
    $firstName = $from['first_name'] ?? null;
    $lastName = $from['last_name'] ?? null;
    $languageCode = $from['language_code'] ?? null;
    $isBot = !empty($from['is_bot']) ? 1 : 0;
    $chatType = $chat['type'] ?? 'private';

    $stmt = $db->prepare(
        'SELECT id FROM users WHERE telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $telegramId);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing) {
        $stmt = $db->prepare(
            'UPDATE users SET username = ?, first_name = ?, last_name = ?, language_code = ?, is_bot = ?, chat_type = ?, last_seen_at = NOW() WHERE telegram_id = ?'
        );
        $stmt->bind_param(
            'ssssisi',
            $username,
            $firstName,
            $lastName,
            $languageCode,
            $isBot,
            $chatType,
            $telegramId
        );
        $stmt->execute();
        $stmt->close();

        return ['is_new' => false, 'telegram_id' => $telegramId];
    }

    $stmt = $db->prepare(
        'INSERT INTO users (telegram_id, username, first_name, last_name, language_code, is_bot, chat_type) VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'issssis',
        $telegramId,
        $username,
        $firstName,
        $lastName,
        $languageCode,
        $isBot,
        $chatType
    );
    $stmt->execute();
    $stmt->close();

    return ['is_new' => true, 'telegram_id' => $telegramId];
}

function getUserCount(): int
{
    $db = getDb();
    $result = $db->query('SELECT COUNT(*) AS total FROM users');
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    return (int) ($row['total'] ?? 0);
}
