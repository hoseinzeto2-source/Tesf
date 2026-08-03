<?php

require_once __DIR__ . '/db.php';

$sql = <<<SQL
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    first_name VARCHAR(255) DEFAULT NULL,
    last_name VARCHAR(255) DEFAULT NULL,
    language_code VARCHAR(10) DEFAULT NULL,
    is_bot TINYINT(1) NOT NULL DEFAULT 0,
    chat_type VARCHAR(50) NOT NULL DEFAULT 'private',
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_telegram_id (telegram_id),
    KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

try {
    $db = getDb();
    if (!$db->query($sql)) {
        http_response_code(500);
        echo 'Table creation failed: ' . $db->error;
        exit;
    }

    $channelsSql = <<<SQL
CREATE TABLE IF NOT EXISTS bot_channels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    chat_id BIGINT NOT NULL,
    chat_type VARCHAR(20) NOT NULL,
    title VARCHAR(255) DEFAULT NULL,
    username VARCHAR(255) DEFAULT NULL,
    member_count INT UNSIGNED DEFAULT NULL,
    bot_status VARCHAR(30) NOT NULL DEFAULT 'administrator',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_chat_id (chat_id),
    KEY idx_active_type (is_active, chat_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    if (!$db->query($channelsSql)) {
        http_response_code(500);
        echo 'bot_channels table creation failed: ' . $db->error;
        exit;
    }

    require_once __DIR__ . '/lib/channel_stats.php';
    ensureChannelStatsTables();
    require_once __DIR__ . '/lib/invite_links.php';
    ensureInviteLinkTables(true);
    require_once __DIR__ . '/lib/channel_folders.php';
    ensureChannelFolderTables();
    require_once __DIR__ . '/lib/ad_campaigns.php';
    ensureAdCampaignsTable();
    getOrCreateAdsPromoFolder();
    require_once __DIR__ . '/lib/child_bots.php';
    ensureChildBotTables();
    require_once __DIR__ . '/lib/bot_folders.php';
    ensureBotFolderTables();
    require_once __DIR__ . '/lib/uploader_versions.php';
    ensureUploaderVersionTables();
    require_once __DIR__ . '/lib/global_bot_owners.php';
    ensureGlobalBotOwnerTables();
    require_once __DIR__ . '/lib/bot_joins.php';
    ensureBotJoinTables();
    require_once __DIR__ . '/lib/bot_health.php';
    ensureChildBotHealthColumns();
    seedDefaultStartMessagesForAllChildBots();
    require_once __DIR__ . '/lib/content_groups.php';
    ensureContentGroupTables();
    require_once __DIR__ . '/lib/content_group_stats.php';
    ensureContentGroupStatColumns();
    $backfilledGroupStats = backfillContentGroupStatsFromLog();
    require_once __DIR__ . '/lib/content_group_folders.php';
    ensureContentGroupFolderTables();
    require_once __DIR__ . '/lib/auto_post.php';
    ensureAutoPostTables();
    require_once __DIR__ . '/lib/auto_post_schedules.php';
    ensureAutoPostScheduleTables();
    require_once __DIR__ . '/lib/hashtag_tools.php';
    ensureHashtagToolTables();
    require_once __DIR__ . '/lib/banner_tools.php';
    ensureBannerToolTables();
    require_once __DIR__ . '/lib/glass_button_tools.php';
    ensureGlassButtonToolTables();
    require_once __DIR__ . '/lib/auto_post_channel_posts.php';
    ensureAutoPostChannelPostTables();
    require_once __DIR__ . '/lib/zapas_bots.php';
    ensureZapasToolTables();
    require_once __DIR__ . '/lib/manage_bots.php';
    ensureManageBotTables();
    seedPrimaryManageBotFromConfig();
    $manageBotsBound = backfillPrimaryManageBotBindings();
    $manageBotWebhooks = syncAllManageBotWebhooks();
    require_once __DIR__ . '/lib/channel_profile.php';
    require_once __DIR__ . '/lib/explorer_pins.php';
    ensureAllExplorerPinColumns();
    $migratedGroups = migrateMisplacedGroupsFromBotChannels();

    echo 'OK: users, bot_channels and stats tables are ready.';
    if ($migratedGroups > 0) {
        echo ' Migrated content groups: ' . $migratedGroups;
    }
    if ($manageBotsBound > 0) {
        echo ' Manage bot bindings: ' . $manageBotsBound;
    }
    if (!empty($manageBotWebhooks['synced'])) {
        echo ' Manage bot webhooks: ' . count($manageBotWebhooks['synced']);
    }

    if (!empty($_GET['deploy_miniapp'])) {
        require_once __DIR__ . '/lib/miniapp_publish.php';
        $secret = (string) ($_GET['key'] ?? '');
        $expected = miniappPublishExpectedKey((string) ($bot_token ?? ''));
        if ($expected !== '' && hash_equals($expected, $secret)) {
            $ref = (string) ($_GET['ref'] ?? 'cursor/channel-analytics-ui-0883');
            $pub = publishMiniappFromGitHub($ref);
            echo ' MINIAPP:' . json_encode($pub, JSON_UNESCAPED_UNICODE);
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Install failed: ' . $e->getMessage();
}
