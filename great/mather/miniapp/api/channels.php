<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/channels.php';
require_once dirname(__DIR__, 2) . '/lib/channel_folders.php';
require_once dirname(__DIR__, 2) . '/lib/content_groups.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

requireTelegramUser();

$action = (string) ($_GET['action'] ?? '');
if ($action === 'folder_members') {
    require_once dirname(__DIR__, 2) . '/lib/channel_stats.php';
    $folderId = (int) ($_GET['folder_id'] ?? 0);
    if ($folderId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'invalid_folder_id'], 400);
    }
    try {
        if (function_exists('getFolderMembersHourly24')) {
            $membersHourly = getFolderMembersHourly24($folderId);
        } else {
            ensureChannelFolderTables();
            $assignments = getChannelFolderAssignments();
            $chatIds = [];
            foreach ($assignments as $chatId => $assignedFolderId) {
                if ((int) $assignedFolderId === $folderId) {
                    $chatIds[] = (int) $chatId;
                }
            }
            if (!function_exists('getAggregatedMembersHourly24ForChatIds')) {
                jsonResponse(['ok' => false, 'error' => 'server_outdated'], 503);
            }
            $membersHourly = getAggregatedMembersHourly24ForChatIds($chatIds);
        }
        jsonResponse([
            'ok' => true,
            'folder_id' => $folderId,
            'members_hourly' => $membersHourly['points'],
            'members_range' => $membersHourly['range'],
        ]);
    } catch (Throwable $e) {
        error_log('folder_members failed: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'database_error'], 500);
    }
}

$lite = !empty($_GET['lite']);

try {
    if (!empty($_GET['reconcile'])) {
        reconcileAllContentGroupPlacements(80);
    }
    if (!empty($_GET['deep_health'])) {
        runServerChannelHealthPass(3);
    }

    $channels = getActiveBotChannels(false, false, true);
    if (count($channels) === 0 && !$lite) {
        resyncKnownBotChannels();
        $channels = getActiveBotChannels(false, false, true);
    }

    $folders = [];
    $overview = null;

    $filterChannels = static function (array $list): array {
        return array_values(array_filter(
            $list,
            static fn(array $item): bool => in_array($item['type'] ?? '', ['channel', 'group', 'supergroup'], true)
        ));
    };

    if ($lite) {
        $filtered = $filterChannels(attachFolderIdsToChannels($channels));
        $memberTotal = 0;
        foreach ($filtered as $channel) {
            $memberTotal += (int) ($channel['member_count'] ?? 0);
        }
        try {
            $folders = getChannelFolders();
        } catch (Throwable $folderError) {
            error_log('channel folders failed: ' . $folderError->getMessage());
            $folders = [];
        }
        $overview = [
            'channels' => $filtered,
            'totals' => [
                'member_count' => $memberTotal,
                'joins_1h' => 0,
                'joins_12h' => 0,
                'joins_24h' => 0,
            ],
            'joins_hourly' => [],
            'joins_range' => null,
            'members_hourly' => [],
            'members_range' => null,
        ];
    } else {
        try {
            require_once dirname(__DIR__, 2) . '/lib/channel_stats.php';
            $overview = attachChannelRecentJoinStats($channels);
            $overview['channels'] = $filterChannels(attachFolderIdsToChannels($overview['channels']));
            $folders = getChannelFolders();
        } catch (Throwable $statsError) {
            error_log('channels overview failed: ' . $statsError->getMessage());
            $overview = null;
        }

        if ($overview === null) {
            $memberTotal = 0;
            foreach ($channels as $channel) {
                $memberTotal += (int) ($channel['member_count'] ?? 0);
            }
            $overview = [
                'channels' => $filterChannels(attachFolderIdsToChannels($channels)),
                'totals' => [
                    'member_count' => $memberTotal,
                    'joins_1h' => 0,
                    'joins_12h' => 0,
                    'joins_24h' => 0,
                ],
                'joins_hourly' => [],
                'joins_range' => null,
                'members_hourly' => [],
                'members_range' => null,
            ];
            try {
                $folders = getChannelFolders();
            } catch (Throwable $folderError) {
                error_log('channel folders failed: ' . $folderError->getMessage());
                $folders = [];
            }
        }
    }

    if (!function_exists('buildDashboardChannelOverview')) {
        require_once dirname(__DIR__, 2) . '/lib/channel_stats.php';
    }
    $dashboard = buildDashboardChannelOverview($overview['channels']);

    jsonResponse([
        'ok' => true,
        'lite' => $lite,
        'total' => count($overview['channels']),
        'channels' => $overview['channels'],
        'folders' => $folders,
        'totals' => $overview['totals'],
        'joins_hourly' => $overview['joins_hourly'] ?? [],
        'joins_range' => $overview['joins_range'] ?? null,
        'members_hourly' => $overview['members_hourly'] ?? [],
        'members_range' => $overview['members_range'] ?? null,
        'dashboard' => $dashboard,
        'promo_folder_id' => $dashboard['promo_folder_id'] ?? null,
    ]);
} catch (Throwable $e) {
    error_log('channels api failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'database_error'], 500);
}
