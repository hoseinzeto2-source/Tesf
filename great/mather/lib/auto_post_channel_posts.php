<?php

require_once dirname(__DIR__) . '/db.php';

function ensureAutoPostChannelPostTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_channel_posts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    schedule_id INT UNSIGNED NOT NULL,
    run_id INT UNSIGNED DEFAULT NULL,
    channel_chat_id BIGINT NOT NULL,
    message_id INT NOT NULL,
    guardian_bot_id INT UNSIGNED NOT NULL,
    uploader_bot_id INT UNSIGNED NOT NULL,
    link_code VARCHAR(32) NOT NULL,
    media_type VARCHAR(20) NOT NULL DEFAULT 'photo',
    content_label VARCHAR(80) DEFAULT NULL,
    source_chat_id BIGINT DEFAULT NULL,
    source_message_id INT DEFAULT NULL,
    channel_folder_id INT UNSIGNED DEFAULT NULL,
    has_banner TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_channel_message (channel_chat_id, message_id),
    KEY idx_guardian (guardian_bot_id),
    KEY idx_uploader (uploader_bot_id),
    KEY idx_link_code (link_code),
    KEY idx_owner (owner_telegram_id),
    KEY idx_schedule (schedule_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function saveAutoPostChannelPost(array $payload): int
{
    ensureAutoPostChannelPostTables();
    $db = getDb();

    $ownerId = (int) ($payload['owner_telegram_id'] ?? 0);
    $scheduleId = (int) ($payload['schedule_id'] ?? 0);
    $runId = isset($payload['run_id']) ? (int) $payload['run_id'] : null;
    $channelChatId = (int) ($payload['channel_chat_id'] ?? 0);
    $messageId = (int) ($payload['message_id'] ?? 0);
    $guardianBotId = (int) ($payload['guardian_bot_id'] ?? 0);
    $uploaderBotId = (int) ($payload['uploader_bot_id'] ?? 0);
    $linkCode = (string) ($payload['link_code'] ?? '');
    $mediaType = (string) ($payload['media_type'] ?? 'photo');
    $contentLabel = $payload['content_label'] ?? null;
    $sourceChatId = isset($payload['source_chat_id']) ? (int) $payload['source_chat_id'] : null;
    $sourceMessageId = isset($payload['source_message_id']) ? (int) $payload['source_message_id'] : null;
    $channelFolderId = isset($payload['channel_folder_id']) ? (int) $payload['channel_folder_id'] : null;
    $hasBanner = !empty($payload['has_banner']) ? 1 : 0;

    if ($channelChatId === 0 || $messageId === 0 || $linkCode === '') {
        return 0;
    }

    $stmt = $db->prepare(
        'INSERT INTO auto_post_channel_posts
         (owner_telegram_id, schedule_id, run_id, channel_chat_id, message_id,
          guardian_bot_id, uploader_bot_id, link_code, media_type, content_label,
          source_chat_id, source_message_id, channel_folder_id, has_banner)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           schedule_id = VALUES(schedule_id),
           run_id = VALUES(run_id),
           guardian_bot_id = VALUES(guardian_bot_id),
           uploader_bot_id = VALUES(uploader_bot_id),
           link_code = VALUES(link_code),
           media_type = VALUES(media_type),
           content_label = VALUES(content_label),
           source_chat_id = VALUES(source_chat_id),
           source_message_id = VALUES(source_message_id),
           channel_folder_id = VALUES(channel_folder_id),
           has_banner = VALUES(has_banner),
           updated_at = NOW()'
    );
    $stmt->bind_param(
        'iiiiiiiisssiiii',
        $ownerId,
        $scheduleId,
        $runId,
        $channelChatId,
        $messageId,
        $guardianBotId,
        $uploaderBotId,
        $linkCode,
        $mediaType,
        $contentLabel,
        $sourceChatId,
        $sourceMessageId,
        $channelFolderId,
        $hasBanner
    );
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    if ($id === 0) {
        $lookup = $db->prepare(
            'SELECT id FROM auto_post_channel_posts WHERE channel_chat_id = ? AND message_id = ? LIMIT 1'
        );
        $lookup->bind_param('ii', $channelChatId, $messageId);
        $lookup->execute();
        $row = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        $id = (int) ($row['id'] ?? 0);
    }

    return $id;
}

/**
 * @return list<array<string, mixed>>
 */
function listChannelPostsByGuardianBot(int $guardianBotId): array
{
    ensureAutoPostChannelPostTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM auto_post_channel_posts WHERE guardian_bot_id = ? ORDER BY id DESC'
    );
    $stmt->bind_param('i', $guardianBotId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

/**
 * @return list<array<string, mixed>>
 */
function listChannelPostsByUploaderBot(int $uploaderBotId): array
{
    ensureAutoPostChannelPostTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM auto_post_channel_posts WHERE uploader_bot_id = ? ORDER BY id DESC'
    );
    $stmt->bind_param('i', $uploaderBotId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function updateChannelPostGuardianBot(int $oldGuardianBotId, int $newGuardianBotId): int
{
    ensureAutoPostChannelPostTables();
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE auto_post_channel_posts SET guardian_bot_id = ?, updated_at = NOW() WHERE guardian_bot_id = ?'
    );
    $stmt->bind_param('ii', $newGuardianBotId, $oldGuardianBotId);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    return $count;
}

function updateChannelPostUploaderBot(int $oldUploaderBotId, int $newUploaderBotId): int
{
    ensureAutoPostChannelPostTables();
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE auto_post_channel_posts SET uploader_bot_id = ?, updated_at = NOW() WHERE uploader_bot_id = ?'
    );
    $stmt->bind_param('ii', $newUploaderBotId, $oldUploaderBotId);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    return $count;
}
