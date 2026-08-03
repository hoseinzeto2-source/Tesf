<?php

declare(strict_types=1);

/**
 * DANGER: wipes application data tables.
 * Web access is blocked — CLI only with explicit confirmation flag.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "CLI only. Pass --confirm-wipe-all-data to proceed.\n";
    exit(1);
}

$argv = $_SERVER['argv'] ?? [];
if (!in_array('--confirm-wipe-all-data', $argv, true)) {
    fwrite(STDERR, "Refusing to run without --confirm-wipe-all-data\n");
    exit(1);
}

require_once dirname(__DIR__, 2) . '/db.php';

$tables = [
    'user_fake_joins',
    'forced_joins',
    'gos_users',
    'bot_states',
    'panel_transactions',
    'campaign_members',
    'campaign_links',
    'campaigns',
    'panel_prices',
    'panel_users',
    'uploader_files',
    'child_bots',
    'users',
];

try {
    $db = getDb();
    $db->query('SET FOREIGN_KEY_CHECKS = 0');

    foreach ($tables as $table) {
        $db->query("DROP TABLE IF EXISTS `{$table}`");
    }

    $db->query('SET FOREIGN_KEY_CHECKS = 1');

    $sql = <<<SQL
CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL,
    username VARCHAR(255) NULL,
    first_name VARCHAR(255) NULL,
    last_name VARCHAR(255) NULL,
    language_code VARCHAR(16) NULL,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    chat_type VARCHAR(32) NOT NULL DEFAULT 'private',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_telegram_id (telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

    if (!$db->query($sql)) {
        throw new RuntimeException($db->error);
    }

    echo "OK: database reset complete. Only users table remains (empty).\n";
    echo "Run install.php to recreate other tables.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Reset failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
