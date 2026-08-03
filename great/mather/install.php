<?php

require_once __DIR__ . '/db.php';

if (($_GET['repair_check'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    echo is_file(__DIR__ . '/lib/child_bot_repair.php') ? "repair_lib=yes\n" : "repair_lib=no\n";
    echo is_file(__DIR__ . '/miniapp/api/my_bots.php') ? "my_bots_api=yes\n" : "my_bots_api=no\n";
    exit;
}

if (($_GET['repair_only'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    $provided = (string) ($_GET['key'] ?? '');
    $expected = hash('sha256', 'gpro-mather-github-deploy-361a');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }

    require_once __DIR__ . '/lib/child_bots.php';
    require_once __DIR__ . '/lib/bot_folders.php';
    require_once __DIR__ . '/lib/channel_folders.php';
    require_once __DIR__ . '/lib/child_bot_repair.php';
    $stats = repairChildBotData();
    echo json_encode(['ok' => true, 'repair' => $stats], JSON_UNESCAPED_UNICODE) . "\n";
    exit;
}

if (($_GET['sync_github'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    $provided = (string) ($_GET['key'] ?? '');
    $expected = hash('sha256', 'gpro-mather-github-deploy-361a');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo "forbidden\n";
        exit;
    }

    $branch = preg_replace('/[^a-zA-Z0-9_\\-\\/]/', '', (string) ($_GET['branch'] ?? 'cursor/manage-bots-multi-361a'));
    $repo = 'hoseinzeto2-source/Tesf';
    $base = "https://raw.githubusercontent.com/{$repo}/{$branch}/great/mather";
    $root = __DIR__;
    $files = [
        'install.php',
        'lib/bot_stats.php',
        'lib/bot_profile.php',
        'lib/child_bot_repair.php',
        'lib/bot_folders.php',
        'lib/child_bots.php',
        'lib/channel_folders.php',
        'lib/bot_health.php',
        'miniapp/index.php',
        'miniapp/js/app.js',
        'miniapp/api/my_bots.php',
        'miniapp/api/bot_folders.php',
        'miniapp/api/create_bot.php',
    ];
    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'header' => "User-Agent: gpro-deploy/1.0\r\n"],
    ]);
    foreach ($files as $rel) {
        $content = @file_get_contents($base . '/' . $rel, false, $ctx);
        if ($content === false || $content === '') {
            echo "! {$rel}: download_failed\n";
            continue;
        }
        $local = $root . '/' . $rel;
        $dir = dirname($local);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            echo "! {$rel}: mkdir_failed\n";
            continue;
        }
        if (file_put_contents($local, $content) === false) {
            echo "! {$rel}: write_failed\n";
            continue;
        }
        echo "+ {$rel}\n";
    }
    echo "sync_done\n";
    exit;
}

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
    require_once __DIR__ . '/lib/bot_stats.php';
    ensureBotStatsTables();
    require_once __DIR__ . '/lib/child_bot_repair.php';
    $childBotRepair = repairChildBotData();

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
    if (!empty($childBotRepair)) {
        echo ' Child bot repair: ' . json_encode($childBotRepair, JSON_UNESCAPED_UNICODE);
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
