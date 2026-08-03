<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/content_groups.php';

function ensureContentGroupStatColumns(): void
{
    ensureContentGroupTables();
    $db = getDb();
    $columns = [
        'message_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'photo_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'video_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'last_message_at' => 'DATETIME NULL DEFAULT NULL',
    ];

    foreach ($columns as $name => $definition) {
        $check = $db->query("SHOW COLUMNS FROM content_groups LIKE '{$name}'");
        if ($check && $check->num_rows === 0) {
            $db->query("ALTER TABLE content_groups ADD COLUMN {$name} {$definition}");
        }
    }

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS content_group_seen_messages (
    chat_id BIGINT NOT NULL,
    message_id INT NOT NULL,
    media_kind VARCHAR(20) NOT NULL DEFAULT 'other',
    seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id, message_id),
    KEY idx_chat_kind (chat_id, media_kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function classifyContentGroupMessage(array $message): ?array
{
    if (!empty($message['service'])) {
        return null;
    }

    $chatType = (string) ($message['chat']['type'] ?? '');
    if (!isContentGroupChatType($chatType)) {
        return null;
    }

    $chatId = (int) ($message['chat']['id'] ?? 0);
    $messageId = (int) ($message['message_id'] ?? 0);
    if ($chatId === 0 || $messageId === 0) {
        return null;
    }

    $hasPhoto = !empty($message['photo']);
    $hasVideo = !empty($message['video']) || !empty($message['video_note']);
    $hasVoice = !empty($message['voice']);
    $hasDocument = !empty($message['document']);

    $mediaKind = 'other';
    $telegramFileId = null;
    if ($hasPhoto) {
        $mediaKind = 'photo';
        $photos = $message['photo'];
        $telegramFileId = (string) (end($photos)['file_id'] ?? '');
    } elseif ($hasVideo) {
        $mediaKind = 'video';
        $telegramFileId = (string) (($message['video']['file_id'] ?? $message['video_note']['file_id'] ?? ''));
    } elseif ($hasVoice) {
        $mediaKind = 'voice';
        $telegramFileId = (string) ($message['voice']['file_id'] ?? '');
    } elseif ($hasDocument) {
        $mediaKind = 'document';
        $telegramFileId = (string) ($message['document']['file_id'] ?? '');
    }

    $messageDate = null;
    if (!empty($message['date'])) {
        $messageDate = date('Y-m-d H:i:s', (int) $message['date']);
    }

    return [
        'chat_id' => $chatId,
        'message_id' => $messageId,
        'media_kind' => $mediaKind,
        'has_photo' => $hasPhoto,
        'has_video' => $hasVideo,
        'has_voice' => $hasVoice,
        'has_document' => $hasDocument,
        'telegram_file_id' => $telegramFileId,
        'caption' => trim((string) ($message['caption'] ?? '')),
        'message_date' => $messageDate,
    ];
}

function recordContentGroupMessage(array $message): bool
{
    ensureContentGroupStatColumns();

    $payload = classifyContentGroupMessage($message);
    if ($payload === null) {
        return false;
    }

    $db = getDb();
    $caption = $payload['caption'] ?? '';
    $fileId = $payload['telegram_file_id'] ?? null;
    $stmt = $db->prepare(
        'INSERT IGNORE INTO content_group_seen_messages (chat_id, message_id, media_kind, telegram_file_id, caption) VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisss', $payload['chat_id'], $payload['message_id'], $payload['media_kind'], $fileId, $caption);
    $stmt->execute();
    $inserted = $stmt->affected_rows > 0;
    $stmt->close();

    if (!$inserted) {
        return false;
    }

    $photoInc = $payload['has_photo'] ? 1 : 0;
    $videoInc = $payload['has_video'] ? 1 : 0;
    $messageDate = $payload['message_date'] ?? date('Y-m-d H:i:s');
    $chatId = $payload['chat_id'];

    $stmt = $db->prepare(
        'UPDATE content_groups
         SET message_count = message_count + 1,
             photo_count = photo_count + ?,
             video_count = video_count + ?,
             last_message_at = GREATEST(COALESCE(last_message_at, ?), ?),
             updated_at = NOW()
         WHERE chat_id = ?'
    );
    $stmt->bind_param('iissi', $photoInc, $videoInc, $messageDate, $messageDate, $chatId);
    $stmt->execute();
    $stmt->close();

    return true;
}

function recomputeContentGroupStats(int $chatId): array
{
    ensureContentGroupStatColumns();
    $db = getDb();

    $stmt = $db->prepare(
        'SELECT
            COUNT(*) AS message_count,
            SUM(CASE WHEN media_kind = \'photo\' THEN 1 ELSE 0 END) AS photo_count,
            SUM(CASE WHEN media_kind = \'video\' THEN 1 ELSE 0 END) AS video_count,
            MAX(seen_at) AS last_message_at
         FROM content_group_seen_messages
         WHERE chat_id = ?'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $messageCount = (int) ($row['message_count'] ?? 0);
    $photoCount = (int) ($row['photo_count'] ?? 0);
    $videoCount = (int) ($row['video_count'] ?? 0);
    $lastMessageAt = $row['last_message_at'] ?? null;

    $stmt = $db->prepare(
        'UPDATE content_groups
         SET message_count = ?, photo_count = ?, video_count = ?, last_message_at = ?, updated_at = NOW()
         WHERE chat_id = ?'
    );
    $stmt->bind_param('iiisi', $messageCount, $photoCount, $videoCount, $lastMessageAt, $chatId);
    $stmt->execute();
    $stmt->close();

    return [
        'message_count' => $messageCount,
        'photo_count' => $photoCount,
        'video_count' => $videoCount,
        'last_message_at' => $lastMessageAt,
    ];
}

function backfillContentGroupStatsFromLog(?int $onlyChatId = null): int
{
    ensureContentGroupStatColumns();

    $paths = [
        dirname(__DIR__) . '/storage/logs/update.log',
        dirname(__DIR__) . '/update.log',
    ];

    $processed = 0;
    foreach ($paths as $path) {
        if (!is_readable($path)) {
            continue;
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            continue;
        }

        while (($line = fgets($handle)) !== false) {
            $jsonStart = strpos($line, '{');
            if ($jsonStart === false) {
                continue;
            }

            $update = json_decode(substr($line, $jsonStart), true);
            if (!is_array($update)) {
                continue;
            }

            $message = $update['message'] ?? $update['edited_message'] ?? null;
            if (!is_array($message)) {
                continue;
            }

            $chatId = (int) ($message['chat']['id'] ?? 0);
            if ($onlyChatId !== null && $chatId !== $onlyChatId) {
                continue;
            }

            if (recordContentGroupMessage($message)) {
                $processed++;
            }
        }

        fclose($handle);
    }

    if ($onlyChatId !== null) {
        recomputeContentGroupStats($onlyChatId);
    } else {
        $db = getDb();
        $result = $db->query('SELECT chat_id FROM content_groups WHERE is_active = 1');
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                recomputeContentGroupStats((int) $row['chat_id']);
            }
        }
    }

    return $processed;
}

/**
 * @return array<string, mixed>|null
 */
/**
 * @param list<array<string, mixed>> $groups
 * @return list<array<string, mixed>>
 */
function enrichContentGroupsWithStats(array $groups): array
{
    ensureContentGroupStatColumns();
    if ($groups === []) {
        return [];
    }

    $db = getDb();
    foreach ($groups as $index => $group) {
        $chatId = (int) ($group['chat_id'] ?? 0);
        if ($chatId === 0) {
            continue;
        }

        $photoCount = (int) ($group['photo_count'] ?? 0);
        $videoCount = (int) ($group['video_count'] ?? 0);
        $messageCount = (int) ($group['message_count'] ?? 0);
        if ($photoCount > 0 || $videoCount > 0 || $messageCount > 0) {
            continue;
        }

        $stats = recomputeContentGroupStats($chatId);
        if ((int) ($stats['message_count'] ?? 0) === 0) {
            backfillContentGroupStatsFromLog($chatId);
            $stats = recomputeContentGroupStats($chatId);
        }

        if ((int) ($stats['message_count'] ?? 0) > 0) {
            $groups[$index] = array_merge($group, $stats);
        }
    }

    return $groups;
}

function getContentGroupDetails(int $chatId): ?array
{
    ensureContentGroupStatColumns();
    ensureContentGroupTables();

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT chat_id, chat_type, title, username, chat_description, member_count, bot_status, is_active, is_private,
                photo_file_id, health_status, health_message, added_at, updated_at,
                message_count, photo_count, video_count, last_message_at
         FROM content_groups
         WHERE chat_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    if ((int) ($row['message_count'] ?? 0) === 0) {
        backfillContentGroupStatsFromLog($chatId);
        $stmt = $db->prepare(
            'SELECT message_count, photo_count, video_count, last_message_at
             FROM content_groups WHERE chat_id = ? LIMIT 1'
        );
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $stats = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($stats) {
            $row = array_merge($row, $stats);
        }
    }

    syncContentGroupFromChat([
        'id' => $chatId,
        'type' => (string) ($row['chat_type'] ?? 'supergroup'),
    ]);

    $stmt = $db->prepare(
        'SELECT chat_id, chat_type, title, username, chat_description, member_count, bot_status, is_active, is_private,
                photo_file_id, health_status, health_message, added_at, updated_at,
                message_count, photo_count, video_count, last_message_at
         FROM content_groups
         WHERE chat_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('i', $chatId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    $formatted = formatContentGroupRow($row);

    return array_merge($formatted, [
        'message_count' => (int) ($row['message_count'] ?? 0),
        'photo_count' => (int) ($row['photo_count'] ?? 0),
        'video_count' => (int) ($row['video_count'] ?? 0),
        'last_message_at' => $row['last_message_at'] ?? null,
    ]);
}
