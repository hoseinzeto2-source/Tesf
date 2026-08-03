<?php

require_once dirname(__DIR__) . '/db.php';

function ensureBotStatsTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS uploader_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_bot_id INT UNSIGNED NOT NULL,
    telegram_id BIGINT NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    first_name VARCHAR(255) DEFAULT NULL,
    last_name VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bot_user (child_bot_id, telegram_id),
    KEY idx_child_bot (child_bot_id),
    KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function countUploaderUsersForBot(int $childBotId): int
{
    if ($childBotId <= 0) {
        return 0;
    }

    ensureBotStatsTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM uploader_users WHERE child_bot_id = ?');
    $stmt->bind_param('i', $childBotId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['cnt'] ?? 0);
}
