<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/channel_stats.php';
require_once dirname(__DIR__, 2) . '/lib/channel_folders.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

requireTelegramUser();

$folderParam = (string) ($_GET['folder_id'] ?? 'all');
$folderParam = trim($folderParam);

try {
    if ($folderParam === '' || $folderParam === 'all') {
        $membersHourly = getAggregatedMembersHourly24();
    } else {
        $folderId = (int) $folderParam;
        if ($folderId <= 0) {
            jsonResponse(['ok' => false, 'error' => 'invalid_folder_id'], 400);
        }
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
    }

    jsonResponse([
        'ok' => true,
        'folder_id' => $folderParam === '' || $folderParam === 'all' ? 'all' : (int) $folderParam,
        'members_hourly' => $membersHourly['points'],
        'members_range' => $membersHourly['range'],
    ]);
} catch (Throwable $e) {
    error_log('dashboard_members api failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'database_error'], 500);
}
