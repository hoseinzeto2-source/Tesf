<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/content_groups.php';
require_once dirname(__DIR__, 2) . '/lib/content_group_folders.php';
require_once dirname(__DIR__, 2) . '/lib/content_group_stats.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

requireTelegramUser();

try {
    ensureContentGroupStatColumns();
    reconcileAllContentGroupPlacements(80);
    runContentGroupHealthPass(3);
    $groups = getActiveContentGroups(false, '01');
    if (count($groups) === 0) {
        resyncKnownContentGroups();
        reconcileAllContentGroupPlacements(80);
        $groups = getActiveContentGroups(false, '01');
    }
    $groups = enrichContentGroupsWithStats($groups);

    $folders = getContentGroupFolders();
    $groups = attachFolderIdsToContentGroups($groups, $folders);

    jsonResponse([
        'ok' => true,
        'groups' => $groups,
        'folders' => $folders,
        'total' => count($groups),
    ]);
} catch (Throwable $e) {
    error_log('content_groups failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
