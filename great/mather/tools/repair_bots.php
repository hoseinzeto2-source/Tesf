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

$stats = [
    'orphan_items_removed' => 0,
    'cross_owner_items_removed' => 0,
    'admin_bots_transferred' => 0,
    'bots_folder_assigned' => 0,
    'primary_owner' => null,
    'test_folder_id' => null,
];

try {
    $db = getDb();
    $adminIds = array_values(array_unique(array_map('intval', $admin_telegram_ids ?? [])));

    $db->query('DELETE i FROM bot_folder_items i LEFT JOIN child_bots b ON b.id = i.bot_id WHERE b.id IS NULL');
    $stats['orphan_items_removed'] = (int) $db->affected_rows;

    $db->query(
        'DELETE i FROM bot_folder_items i
         INNER JOIN child_bots b ON b.id = i.bot_id
         INNER JOIN bot_folders f ON f.id = i.folder_id
         WHERE b.owner_telegram_id <> f.owner_telegram_id'
    );
    $stats['cross_owner_items_removed'] = (int) $db->affected_rows;

    $primaryOwner = 0;
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

    $stats['primary_owner'] = $primaryOwner > 0 ? $primaryOwner : null;

    if ($primaryOwner > 0 && $adminIds !== []) {
        $adminList = implode(',', $adminIds);
        $db->query("UPDATE child_bots SET owner_telegram_id = {$primaryOwner} WHERE owner_telegram_id IN ({$adminList})");
        $stats['admin_bots_transferred'] = (int) $db->affected_rows;
    }

    $testFolderId = 0;
    if ($primaryOwner > 0) {
        $stmt = $db->prepare(
            'SELECT id, name, parent_id FROM bot_folders WHERE owner_telegram_id = ?'
        );
        $stmt->bind_param('i', $primaryOwner);
        $stmt->execute();
        $folders = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $rootId = 0;
        foreach ($folders as $folder) {
            $parentId = $folder['parent_id'] ?? null;
            if ($parentId !== null && (int) $parentId > 0) {
                continue;
            }
            $name = mb_strtolower(preg_replace('/\s+/u', '', (string) ($folder['name'] ?? '')), 'UTF-8');
            if (str_contains($name, 'غیر') && str_contains($name, 'اخلاق')) {
                $rootId = (int) $folder['id'];
                break;
            }
        }

        if ($rootId > 0) {
            foreach ($folders as $folder) {
                if ((int) ($folder['parent_id'] ?? 0) !== $rootId) {
                    continue;
                }
                $name = mb_strtolower(preg_replace('/\s+/u', '', (string) ($folder['name'] ?? '')), 'UTF-8');
                if ($name === 'تست' || str_contains($name, 'تست')) {
                    $testFolderId = (int) $folder['id'];
                    break;
                }
            }
        }
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
    }

    echo json_encode(['ok' => true, 'repair' => $stats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage(), 'repair' => $stats], JSON_UNESCAPED_UNICODE);
}
