<?php

declare(strict_types=1);

$key = (string) ($_GET['key'] ?? '');
$expected = hash('sha256', 'gpro-mather-github-deploy-361a');
if ($key === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_GET['restore'])) {
    require_once dirname(__DIR__) . '/lib/child_bots.php';
    require_once dirname(__DIR__) . '/lib/bot_folders.php';
    require_once dirname(__DIR__) . '/lib/channel_folders.php';

    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = $_POST;
    }

    $ownerId = (int) ($body['owner_id'] ?? 0);
    $uploaderToken = trim((string) ($body['uploader_token'] ?? ''));
    $guardianToken = trim((string) ($body['guardian_token'] ?? ''));
    $botFolderId = (int) ($body['bot_folder_id'] ?? 0);

    try {
        if ($ownerId <= 0 || ($uploaderToken === '' && $guardianToken === '')) {
            throw new InvalidArgumentException('invalid_request');
        }

        ensureChildBotTables();
        ensureBotFolderTables();
        ensureChannelFolderTables();

        $channelFolderId = 0;
        foreach (getChannelFolders() as $folder) {
            $name = normFolderName((string) ($folder['name'] ?? ''));
            if (str_contains($name, 'غیر') && str_contains($name, 'اخلاق')) {
                $channelFolderId = (int) $folder['id'];
                break;
            }
        }
        if ($channelFolderId <= 0) {
            throw new InvalidArgumentException('channel_folder_not_found');
        }

        if ($botFolderId <= 0) {
            $stmt = getDb()->prepare('SELECT id, name, parent_id FROM bot_folders WHERE owner_telegram_id = ?');
            $stmt->bind_param('i', $ownerId);
            $stmt->execute();
            $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            $botFolderId = findTestFolderId($folders);
        }
        if ($botFolderId <= 0) {
            throw new InvalidArgumentException('bot_folder_not_found');
        }

        $restored = [];
        foreach ([['uploader', $uploaderToken], ['guardian', $guardianToken]] as [$type, $token]) {
            if ($token === '') {
                continue;
            }
            $bot = $type === 'guardian'
                ? createGuardianBot($ownerId, $token, '', $channelFolderId)
                : createUploaderBot($ownerId, $token, '', $channelFolderId);
            $botId = (int) ($bot['id'] ?? 0);
            if ($botId > 0) {
                assignBotToFolder($botId, $botFolderId, $ownerId);
            }
            $restored[] = ['type' => $type, 'id' => $botId, 'username' => $bot['bot_username'] ?? null, 'folder_id' => $botFolderId];
        }

        echo json_encode(['ok' => true, 'restored' => $restored], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');

function normFolderName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');

    return preg_replace('/\s+/u', '', $name) ?? $name;
}

/**
 * @param list<array<string, mixed>> $folders
 */
function findTestFolderId(array $folders): int
{
    $rootId = 0;
    foreach ($folders as $folder) {
        $parentId = $folder['parent_id'] ?? null;
        if ($parentId !== null && (int) $parentId > 0) {
            continue;
        }
        $name = normFolderName((string) ($folder['name'] ?? ''));
        if (str_contains($name, 'غیر') && str_contains($name, 'اخلاق')) {
            $rootId = (int) $folder['id'];
            break;
        }
    }

    if ($rootId <= 0) {
        return 0;
    }

    foreach ($folders as $folder) {
        if ((int) ($folder['parent_id'] ?? 0) !== $rootId) {
            continue;
        }
        $name = normFolderName((string) ($folder['name'] ?? ''));
        if ($name === 'تست' || str_contains($name, 'تست')) {
            return (int) $folder['id'];
        }
    }

    return 0;
}

try {
    $db = getDb();
    $adminIds = array_values(array_unique(array_map('intval', $admin_telegram_ids ?? [])));

    if (!empty($_GET['diag'])) {
        $out = ['ok' => true, 'admin_ids' => $adminIds, 'users' => [], 'child_bots' => [], 'bot_folders' => [], 'bot_folder_items' => [], 'simulate_my_bots' => []];
        $r = $db->query('SELECT telegram_id, username, first_name, last_seen_at FROM users ORDER BY last_seen_at DESC LIMIT 20');
        while ($row = $r->fetch_assoc()) {
            $out['users'][] = $row;
        }
        $r = $db->query('SELECT id, owner_telegram_id, bot_username, bot_type, status FROM child_bots ORDER BY id');
        while ($row = $r->fetch_assoc()) {
            $out['child_bots'][] = $row;
        }
        $r = $db->query('SELECT id, owner_telegram_id, name, parent_id FROM bot_folders ORDER BY owner_telegram_id, id');
        while ($row = $r->fetch_assoc()) {
            $out['bot_folders'][] = $row;
        }
        $r = $db->query('SELECT bot_id, folder_id FROM bot_folder_items ORDER BY bot_id');
        while ($row = $r->fetch_assoc()) {
            $out['bot_folder_items'][] = $row;
        }
        $ownerId = (int) ($_GET['owner_id'] ?? 8806407819);
        if ($ownerId > 0) {
            require_once dirname(__DIR__) . '/lib/child_bots.php';
            require_once dirname(__DIR__) . '/lib/bot_folders.php';
            $bots = attachFolderIdsToBots(getChildBotsByOwner($ownerId), $ownerId);
            foreach ($bots as $bot) {
                $out['simulate_my_bots'][] = [
                    'id' => (int) ($bot['id'] ?? 0),
                    'username' => $bot['bot_username'] ?? null,
                    'folder_id' => $bot['folder_id'] ?? null,
                ];
            }
            $out['simulate_folders'] = getBotFolders($ownerId);
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stats = [
        'orphan_items_removed' => 0,
        'cross_owner_items_removed' => 0,
        'admin_bots_transferred' => 0,
        'bots_folder_assigned' => 0,
        'primary_owner' => null,
        'test_folder_id' => null,
    ];

    $db->query('DELETE i FROM bot_folder_items i LEFT JOIN child_bots b ON b.id = i.bot_id WHERE b.id IS NULL');
    $stats['orphan_items_removed'] = (int) $db->affected_rows;

    $db->query(
        'DELETE i FROM bot_folder_items i
         INNER JOIN child_bots b ON b.id = i.bot_id
         INNER JOIN bot_folders f ON f.id = i.folder_id
         WHERE b.owner_telegram_id <> f.owner_telegram_id'
    );
    $stats['cross_owner_items_removed'] = (int) $db->affected_rows;

    $forcedOwner = isset($_GET['owner_id']) ? (int) $_GET['owner_id'] : 0;
    $primaryOwner = $forcedOwner;

    if ($primaryOwner <= 0) {
        if ($adminIds !== []) {
            $adminList = implode(',', $adminIds);
            $result = $db->query(
                "SELECT telegram_id FROM users WHERE is_bot = 0 AND telegram_id NOT IN ({$adminList}) ORDER BY last_seen_at DESC, id DESC LIMIT 1"
            );
            if ($result && ($row = $result->fetch_assoc())) {
                $primaryOwner = (int) ($row['telegram_id'] ?? 0);
            }
        } else {
            $result = $db->query('SELECT telegram_id FROM users WHERE is_bot = 0 ORDER BY last_seen_at DESC, id DESC LIMIT 1');
            if ($result && ($row = $result->fetch_assoc())) {
                $primaryOwner = (int) ($row['telegram_id'] ?? 0);
            }
        }
    }

    if ($primaryOwner <= 0) {
        $result = $db->query('SELECT DISTINCT owner_telegram_id FROM bot_folders ORDER BY owner_telegram_id');
        while ($result && ($row = $result->fetch_assoc())) {
            $candidate = (int) ($row['owner_telegram_id'] ?? 0);
            if ($candidate <= 0) {
                continue;
            }
            if ($adminIds !== [] && in_array($candidate, $adminIds, true)) {
                continue;
            }
            $primaryOwner = $candidate;
            break;
        }
    }

    if ($primaryOwner <= 0) {
        $result = $db->query('SELECT telegram_id FROM users WHERE is_bot = 0 ORDER BY last_seen_at DESC, id DESC LIMIT 1');
        if ($result && ($row = $result->fetch_assoc())) {
            $primaryOwner = (int) ($row['telegram_id'] ?? 0);
        }
    }

    $stats['primary_owner'] = $primaryOwner > 0 ? $primaryOwner : null;

    if ($primaryOwner > 0 && $adminIds !== []) {
        $adminList = implode(',', $adminIds);
        $db->query("UPDATE child_bots SET owner_telegram_id = {$primaryOwner} WHERE owner_telegram_id IN ({$adminList})");
        $stats['admin_bots_transferred'] = (int) $db->affected_rows;
    }

    $testFolderId = 0;
    if ($primaryOwner > 0) {
        $stmt = $db->prepare('SELECT id, name, parent_id FROM bot_folders WHERE owner_telegram_id = ?');
        $stmt->bind_param('i', $primaryOwner);
        $stmt->execute();
        $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $testFolderId = findTestFolderId($folders);
    }

    $stats['test_folder_id'] = $testFolderId > 0 ? $testFolderId : null;

    if ($primaryOwner > 0 && $testFolderId > 0) {
        $result = $db->query(
            "SELECT c.id
             FROM child_bots c
             LEFT JOIN bot_folder_items i ON i.bot_id = c.id
             WHERE c.owner_telegram_id = {$primaryOwner} AND i.bot_id IS NULL"
        );
        if ($result) {
            $insert = $db->prepare(
                'INSERT INTO bot_folder_items (bot_id, folder_id) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE folder_id = VALUES(folder_id), added_at = NOW()'
            );
            while ($row = $result->fetch_assoc()) {
                $botId = (int) ($row['id'] ?? 0);
                if ($botId <= 0) {
                    continue;
                }
                $insert->bind_param('ii', $botId, $testFolderId);
                $insert->execute();
                $stats['bots_folder_assigned']++;
            }
            $insert->close();
        }

        $db->query(
            "UPDATE bot_folder_items i
             INNER JOIN child_bots c ON c.id = i.bot_id
             SET i.folder_id = {$testFolderId}
             WHERE c.owner_telegram_id = {$primaryOwner}"
        );
        $stats['bots_folder_assigned'] += (int) $db->affected_rows;
    }

    echo json_encode(['ok' => true, 'repair' => $stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
