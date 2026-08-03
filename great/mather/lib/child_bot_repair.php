<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/child_bots.php';
require_once __DIR__ . '/bot_folders.php';

function normalizeFolderName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');

    return preg_replace('/\s+/u', '', $name) ?? $name;
}

/**
 * @return list<int>
 */
function adminTelegramIds(): array
{
    global $admin_telegram_ids;

    return array_values(array_unique(array_map('intval', $admin_telegram_ids ?? [])));
}

function getPrimaryMiniappOwnerId(): ?int
{
    $adminSet = array_flip(adminTelegramIds());
    $db = getDb();
    $result = $db->query(
        'SELECT telegram_id FROM users WHERE is_bot = 0 ORDER BY last_seen_at DESC, id DESC LIMIT 10'
    );
    if (!$result) {
        return null;
    }

    while ($row = $result->fetch_assoc()) {
        $id = (int) ($row['telegram_id'] ?? 0);
        if ($id > 0 && !isset($adminSet[$id])) {
            return $id;
        }
    }

    return null;
}

function findBotFolderByNamePath(int $ownerTelegramId, array $pathNames): int
{
    if ($pathNames === []) {
        return 0;
    }

    $folders = getBotFolders($ownerTelegramId);
    $parentId = null;
    foreach ($pathNames as $index => $segment) {
        $target = normalizeFolderName($segment);
        $found = 0;
        foreach ($folders as $folder) {
            $folderParent = normalizeBotFolderParentId($folder['parent_id'] ?? null);
            $sameParent = ($index === 0 && $folderParent === null)
                || ($index > 0 && $folderParent === $parentId);
            if (!$sameParent) {
                continue;
            }
            if (normalizeFolderName((string) ($folder['name'] ?? '')) === $target) {
                $found = (int) $folder['id'];
                break;
            }
        }
        if ($found <= 0) {
            return 0;
        }
        $parentId = $found;
    }

    return (int) $parentId;
}

/**
 * @return array<string, int>
 */
function repairChildBotData(): array
{
    ensureChildBotTables();
    ensureBotFolderTables();

    $stats = [
        'orphan_items_removed' => 0,
        'cross_owner_items_removed' => 0,
        'admin_bots_transferred' => 0,
        'bots_folder_assigned' => 0,
    ];

    $db = getDb();

    $db->query('DELETE i FROM bot_folder_items i LEFT JOIN child_bots b ON b.id = i.bot_id WHERE b.id IS NULL');
    $stats['orphan_items_removed'] = (int) $db->affected_rows;

    $db->query(
        'DELETE i FROM bot_folder_items i
         INNER JOIN child_bots b ON b.id = i.bot_id
         INNER JOIN bot_folders f ON f.id = i.folder_id
         WHERE b.owner_telegram_id <> f.owner_telegram_id'
    );
    $stats['cross_owner_items_removed'] = (int) $db->affected_rows;

    $primaryOwner = getPrimaryMiniappOwnerId();
    if ($primaryOwner === null) {
        return $stats;
    }

    $adminIds = adminTelegramIds();
    if ($adminIds !== []) {
        $placeholders = implode(',', array_fill(0, count($adminIds), '?'));
        $types = str_repeat('i', count($adminIds));
        $sql = "UPDATE child_bots SET owner_telegram_id = ? WHERE owner_telegram_id IN ({$placeholders})";
        $stmt = $db->prepare($sql);
        $params = array_merge([$primaryOwner], $adminIds);
        $stmt->bind_param('i' . $types, ...$params);
        $stmt->execute();
        $stats['admin_bots_transferred'] = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $testFolderId = findBotFolderByNamePath($primaryOwner, ['غیر اخلاقی', 'تست']);
    if ($testFolderId <= 0) {
        $testFolderId = findBotFolderByNamePath($primaryOwner, ['غیراخلاقی', 'تست']);
    }

    if ($testFolderId <= 0) {
        return $stats;
    }

    $stmt = $db->prepare(
        'SELECT c.id
         FROM child_bots c
         LEFT JOIN bot_folder_items i ON i.bot_id = c.id
         WHERE c.owner_telegram_id = ? AND i.bot_id IS NULL'
    );
    $stmt->bind_param('i', $primaryOwner);
    $stmt->execute();
    $result = $stmt->get_result();
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
    $stmt->close();

    return $stats;
}
