<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/child_bots.php';
require_once __DIR__ . '/telegram_api.php';

function ensureChildBotProfilePhotoColumn(): void
{
    if (schemaMigrationsComplete()) {
        return;
    }

    ensureChildBotTables();
    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM child_bots LIKE 'profile_photo_file_id'");
    if ($result && $result->num_rows === 0) {
        $db->query('ALTER TABLE child_bots ADD COLUMN profile_photo_file_id VARCHAR(255) NULL DEFAULT NULL AFTER bot_name');
    }
}

/**
 * @return array{has_photo: bool, photo_url: ?string}
 */
function botPhotoPayloadFromFileId(int $botId, ?string $fileId): array
{
    if ($botId <= 0 || $fileId === null || trim($fileId) === '') {
        return ['has_photo' => false, 'photo_url' => null];
    }

    return [
        'has_photo' => true,
        'photo_url' => 'bot_photo.php?bot_id=' . $botId,
    ];
}

function syncMissingChildBotProfilePhotos(int $ownerTelegramId, int $limit = 8): void
{
    ensureChildBotProfilePhotoColumn();
    $ownerTelegramId = (int) $ownerTelegramId;
    if ($ownerTelegramId <= 0 || $limit <= 0) {
        return;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, bot_token, profile_photo_file_id
         FROM child_bots
         WHERE owner_telegram_id = ? AND status = "active" AND (profile_photo_file_id IS NULL OR profile_photo_file_id = "")
         ORDER BY id DESC
         LIMIT ' . (int) $limit
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        syncChildBotProfilePhotoCache((int) ($row['id'] ?? 0), $row);
    }
    $stmt->close();
}

/**
 * @param array<string, mixed> $bot
 */
function syncChildBotProfilePhotoCache(int $botId, array $bot): void
{
    ensureChildBotProfilePhotoColumn();
    if ($botId <= 0) {
        return;
    }

    $token = (string) ($bot['bot_token'] ?? '');
    if ($token === '') {
        return;
    }

    $photos = tgRequestWithToken($token, 'getUserProfilePhotos', ['user_id' => (int) ($bot['bot_telegram_id'] ?? 0), 'limit' => 1]);
    $fileId = null;
    if (!empty($photos['ok']) && !empty($photos['result']['photos'][0][0]['file_id'])) {
        $fileId = (string) $photos['result']['photos'][0][0]['file_id'];
    }

    $db = getDb();
    $stmt = $db->prepare('UPDATE child_bots SET profile_photo_file_id = ? WHERE id = ?');
    $stmt->bind_param('si', $fileId, $botId);
    $stmt->execute();
    $stmt->close();
}
