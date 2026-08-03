<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/child_bots.php';
require_once __DIR__ . '/channel_folders.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/auto_post_channel_posts.php';
require_once __DIR__ . '/glass_button_tools.php';
require_once __DIR__ . '/auto_post_schedules.php';

function ensureZapasToolTables(): void
{
    ensureChildBotTables();
    ensureChannelFolderTables();
    ensureAutoPostChannelPostTables();

    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS zapas_pool (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    child_bot_id INT UNSIGNED NOT NULL,
    pool_status VARCHAR(20) NOT NULL DEFAULT 'standby',
    assigned_role VARCHAR(20) DEFAULT NULL,
    replaced_bot_id INT UNSIGNED DEFAULT NULL,
    replaced_bot_type VARCHAR(20) DEFAULT NULL,
    assigned_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_child_bot (child_bot_id),
    KEY idx_owner_status (owner_telegram_id, pool_status),
    KEY idx_replaced (replaced_bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS zapas_replacements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    old_bot_id INT UNSIGNED NOT NULL,
    new_bot_id INT UNSIGNED NOT NULL,
    old_bot_type VARCHAR(20) NOT NULL,
    old_bot_username VARCHAR(255) DEFAULT NULL,
    new_bot_username VARCHAR(255) DEFAULT NULL,
    posts_updated INT UNSIGNED NOT NULL DEFAULT 0,
    links_updated INT UNSIGNED NOT NULL DEFAULT 0,
    files_migrated INT UNSIGNED NOT NULL DEFAULT 0,
    reason VARCHAR(80) NOT NULL DEFAULT 'bot_banned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_owner (owner_telegram_id),
    KEY idx_old_bot (old_bot_id),
    KEY idx_new_bot (new_bot_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS zapas_folder_bindings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    channel_folder_id INT UNSIGNED NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_owner_folder (owner_telegram_id, channel_folder_id),
    KEY idx_owner (owner_telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function getOrCreateZapasChannelFolder(int $ownerTelegramId): int
{
    ensureChannelFolderTables();
    $db = getDb();
    $name = 'زاپاس';
    $stmt = $db->prepare(
        'SELECT id FROM channel_folders WHERE owner_telegram_id = ? AND name = ? AND parent_id IS NULL LIMIT 1'
    );
    $stmt->bind_param('is', $ownerTelegramId, $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row['id'];
    }

    $stmt = $db->prepare(
        'INSERT INTO channel_folders (owner_telegram_id, name, parent_id) VALUES (?, ?, NULL)'
    );
    $stmt->bind_param('is', $ownerTelegramId, $name);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return $id;
}

/**
 * @return array<string, mixed>
 */
function createZapasBot(int $ownerTelegramId, string $token): array
{
    ensureZapasToolTables();
    $token = trim($token);
    if ($token === '') {
        throw new InvalidArgumentException('token_required');
    }

    $folderId = getOrCreateZapasChannelFolder($ownerTelegramId);
    $bot = createChildBot($ownerTelegramId, $token, 'zapas', '', $folderId, 0);

    $db = getDb();
    $childBotId = (int) ($bot['id'] ?? 0);
    if ($childBotId <= 0) {
        throw new RuntimeException('zapas_create_failed');
    }

    $stmt = $db->prepare(
        'INSERT INTO zapas_pool (owner_telegram_id, child_bot_id, pool_status)
         VALUES (?, ?, "standby")
         ON DUPLICATE KEY UPDATE pool_status = "standby", assigned_role = NULL, replaced_bot_id = NULL, updated_at = NOW()'
    );
    $stmt->bind_param('ii', $ownerTelegramId, $childBotId);
    $stmt->execute();
    $stmt->close();

    return [
        'id' => $childBotId,
        'bot_username' => $bot['bot_username'] ?? '',
        'bot_name' => $bot['bot_name'] ?? 'Zapas Bot',
        'pool_status' => 'standby',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function listZapasBots(int $ownerTelegramId): array
{
    ensureZapasToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT zp.*, cb.bot_username, cb.bot_name, cb.health_status, cb.status AS bot_status
         FROM zapas_pool zp
         INNER JOIN child_bots cb ON cb.id = zp.child_bot_id
         WHERE zp.owner_telegram_id = ?
         ORDER BY zp.id DESC'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'id' => (int) ($row['child_bot_id'] ?? 0),
            'bot_username' => (string) ($row['bot_username'] ?? ''),
            'bot_name' => (string) ($row['bot_name'] ?? ''),
            'pool_status' => (string) ($row['pool_status'] ?? 'standby'),
            'assigned_role' => $row['assigned_role'] ?? null,
            'replaced_bot_id' => isset($row['replaced_bot_id']) ? (int) $row['replaced_bot_id'] : null,
            'health_status' => (string) ($row['health_status'] ?? 'ok'),
            'created_at' => $row['created_at'] ?? null,
            'assigned_at' => $row['assigned_at'] ?? null,
        ];
    }
    $stmt->close();

    return $rows;
}

/**
 * @return array<string, mixed>|null
 */
function pickStandbyZapasBot(int $ownerTelegramId): ?array
{
    ensureZapasToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT zp.*, cb.*
         FROM zapas_pool zp
         INNER JOIN child_bots cb ON cb.id = zp.child_bot_id
         WHERE zp.owner_telegram_id = ?
           AND zp.pool_status = "standby"
           AND cb.status = "active"
           AND (cb.health_status = "ok" OR cb.health_status IS NULL OR cb.health_status = "")
         ORDER BY zp.id ASC
         LIMIT 1'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function isZapasEnabledForChannelFolder(int $ownerTelegramId, int $channelFolderId): bool
{
    ensureZapasToolTables();
    if ($channelFolderId <= 0) {
        return true;
    }

    $folderIds = getChannelFolderTreeIds($channelFolderId);
    if ($folderIds === []) {
        return false;
    }

    $inList = implode(',', array_map('intval', $folderIds));
    $db = getDb();
    $result = $db->query(
        "SELECT id FROM zapas_folder_bindings
         WHERE owner_telegram_id = {$ownerTelegramId}
           AND channel_folder_id IN ({$inList})
           AND is_enabled = 1
         LIMIT 1"
    );

    if ($result && $result->num_rows > 0) {
        return true;
    }

    $countResult = $db->query(
        "SELECT COUNT(*) AS cnt FROM zapas_folder_bindings WHERE owner_telegram_id = {$ownerTelegramId}"
    );
    $countRow = $countResult ? $countResult->fetch_assoc() : null;

    return (int) ($countRow['cnt'] ?? 0) === 0;
}

function setZapasFolderBinding(int $ownerTelegramId, int $channelFolderId, bool $enabled): array
{
    ensureZapasToolTables();
    if ($channelFolderId <= 0 || !channelFolderExists($channelFolderId)) {
        throw new InvalidArgumentException('invalid_channel_folder');
    }

    $db = getDb();
    $enabledInt = $enabled ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO zapas_folder_bindings (owner_telegram_id, channel_folder_id, is_enabled)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE is_enabled = VALUES(is_enabled), updated_at = NOW()'
    );
    $stmt->bind_param('iii', $ownerTelegramId, $channelFolderId, $enabledInt);
    $stmt->execute();
    $stmt->close();

    return [
        'channel_folder_id' => $channelFolderId,
        'channel_folder_path' => getChannelFolderPathLabel($channelFolderId),
        'is_enabled' => $enabled,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function getZapasFolderBindings(int $ownerTelegramId): array
{
    ensureZapasToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT channel_folder_id, is_enabled, created_at
         FROM zapas_folder_bindings
         WHERE owner_telegram_id = ?
         ORDER BY id DESC'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $folderId = (int) ($row['channel_folder_id'] ?? 0);
        $rows[] = [
            'channel_folder_id' => $folderId,
            'channel_folder_path' => getChannelFolderPathLabel($folderId),
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'created_at' => $row['created_at'] ?? null,
        ];
    }
    $stmt->close();

    return $rows;
}

function deleteZapasFolderBinding(int $ownerTelegramId, int $channelFolderId): bool
{
    ensureZapasToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'DELETE FROM zapas_folder_bindings WHERE owner_telegram_id = ? AND channel_folder_id = ?'
    );
    $stmt->bind_param('ii', $ownerTelegramId, $channelFolderId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

/**
 * @return list<array<string, mixed>>
 */
function listZapasReplacements(int $ownerTelegramId, int $limit = 100): array
{
    ensureZapasToolTables();
    $db = getDb();
    $limit = max(1, min(200, $limit));
    $stmt = $db->prepare(
        "SELECT * FROM zapas_replacements
         WHERE owner_telegram_id = ?
         ORDER BY id DESC
         LIMIT {$limit}"
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function getChildBotRowById(int $botId): ?array
{
    ensureChildBotTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM child_bots WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function migrateUploaderAssets(int $oldBotId, int $newBotId): int
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE uploader_files SET child_bot_id = ? WHERE child_bot_id = ?');
    $stmt->bind_param('ii', $newBotId, $oldBotId);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    $stmt = $db->prepare(
        'UPDATE auto_post_uploader_media_cache SET uploader_bot_id = ? WHERE uploader_bot_id = ?'
    );
    $stmt->bind_param('ii', $newBotId, $oldBotId);
    $stmt->execute();
    $stmt->close();

    return $count;
}

function remapGuardianLinks(int $oldBotId, int $newBotId, string $role): int
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    if ($role === 'guardian') {
        $stmt = $db->prepare(
            'UPDATE auto_post_guardian_links SET guardian_bot_id = ? WHERE guardian_bot_id = ?'
        );
    } else {
        $stmt = $db->prepare(
            'UPDATE auto_post_guardian_links SET uploader_bot_id = ? WHERE uploader_bot_id = ?'
        );
    }
    $stmt->bind_param('ii', $newBotId, $oldBotId);
    $stmt->execute();
    $count = $stmt->affected_rows;
    $stmt->close();

    return $count;
}

function activateZapasBotForRole(array $zapasPoolRow, array $oldBot, string $role): int
{
    ensureZapasToolTables();
    $zapasBotId = (int) ($zapasPoolRow['child_bot_id'] ?? 0);
    $oldBotId = (int) ($oldBot['id'] ?? 0);
    if ($zapasBotId <= 0 || $oldBotId <= 0) {
        return 0;
    }

    $db = getDb();
    $channelFolderId = (int) ($oldBot['channel_folder_id'] ?? 0);
    $versionId = (int) ($oldBot['uploader_version_id'] ?? 0);

    $stmt = $db->prepare(
        'UPDATE child_bots
         SET bot_type = ?, channel_folder_id = ?, uploader_version_id = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('siii', $role, $channelFolderId, $versionId, $zapasBotId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        'UPDATE zapas_pool
         SET pool_status = "active", assigned_role = ?, replaced_bot_id = ?, replaced_bot_type = ?, assigned_at = NOW(), updated_at = NOW()
         WHERE child_bot_id = ?'
    );
    $stmt->bind_param('sisi', $role, $oldBotId, $role, $zapasBotId);
    $stmt->execute();
    $stmt->close();

    return $zapasBotId;
}

function updateChannelPostsAfterBotSwap(
    int $ownerTelegramId,
    int $oldBotId,
    int $newBotId,
    string $role,
    array $newBot
): int {
    ensureAutoPostChannelPostTables();
    $posts = $role === 'guardian'
        ? listChannelPostsByGuardianBot($oldBotId)
        : listChannelPostsByUploaderBot($oldBotId);

    if ($posts === []) {
        return 0;
    }

    $manageToken = manageBotToken();
    if ($manageToken === '') {
        return 0;
    }

    $updated = 0;
    $newUsername = trim((string) ($newBot['bot_username'] ?? ''));
    if ($newUsername === '') {
        return 0;
    }

    foreach ($posts as $post) {
        $channelChatId = (int) ($post['channel_chat_id'] ?? 0);
        $messageId = (int) ($post['message_id'] ?? 0);
        $linkCode = (string) ($post['link_code'] ?? '');
        $mediaType = (string) ($post['media_type'] ?? 'photo');
        $channelFolderId = (int) ($post['channel_folder_id'] ?? 0);
        $sourceChatId = (int) ($post['source_chat_id'] ?? 0);
        $sourceMessageId = (int) ($post['source_message_id'] ?? 0);
        $hasBanner = (int) ($post['has_banner'] ?? 0) === 1;

        if ($channelChatId === 0 || $messageId === 0 || $linkCode === '') {
            continue;
        }

        if ($role === 'guardian') {
            $startPayload = 'g_' . $linkCode;
            $url = 'https://t.me/' . $newUsername . '?start=' . rawurlencode($startPayload);
            $baseCaption = $sourceChatId > 0 && $sourceMessageId > 0
                ? resolveMediaCaptionForPost($sourceChatId, $sourceMessageId, $mediaType)
                : 'برای دریافت ' . autoPostMediaTypeLabel($mediaType) . ' روی دکمه زیر بزنید';
            $baseCaption = appendHashtagsToCaption($ownerTelegramId, $channelChatId, $baseCaption);
            if (mb_strlen($baseCaption) > 1024) {
                $baseCaption = mb_substr($baseCaption, 0, 1020) . '…';
            }

            $delivery = buildGlassButtonDelivery($ownerTelegramId, $channelFolderId, $url, $mediaType, $baseCaption);

            if (!empty($delivery['use_inline'])) {
                if ($hasBanner) {
                    $resp = tgRequestWithToken($manageToken, 'editMessageReplyMarkup', [
                        'chat_id' => $channelChatId,
                        'message_id' => $messageId,
                        'reply_markup' => $delivery['reply_markup'],
                    ]);
                } else {
                    $params = [
                        'chat_id' => $channelChatId,
                        'message_id' => $messageId,
                        'text' => $delivery['text'],
                        'reply_markup' => $delivery['reply_markup'],
                    ];
                    if (!empty($delivery['parse_mode'])) {
                        $params['parse_mode'] = $delivery['parse_mode'];
                    }
                    $resp = tgRequestWithToken($manageToken, 'editMessageText', $params);
                }
            } else {
                if ($hasBanner) {
                    $resp = tgRequestWithToken($manageToken, 'editMessageCaption', [
                        'chat_id' => $channelChatId,
                        'message_id' => $messageId,
                        'caption' => $delivery['text'],
                        'parse_mode' => $delivery['parse_mode'],
                        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
                    ]);
                } else {
                    $resp = tgRequestWithToken($manageToken, 'editMessageText', [
                        'chat_id' => $channelChatId,
                        'message_id' => $messageId,
                        'text' => $delivery['text'],
                        'parse_mode' => $delivery['parse_mode'],
                        'reply_markup' => json_encode(['inline_keyboard' => []], JSON_UNESCAPED_UNICODE),
                    ]);
                }
            }

            if (!empty($resp['ok'])) {
                $updated++;
            }
        }
    }

    if ($role === 'guardian') {
        updateChannelPostGuardianBot($oldBotId, $newBotId);
    } else {
        updateChannelPostUploaderBot($oldBotId, $newBotId);
    }

    return $updated;
}

/**
 * @return array{ok:bool,replaced?:bool,reason?:string,stats?:array<string,int>}
 */
function attemptZapasReplacementForBot(int $botId, string $reason = 'bot_banned'): array
{
    ensureZapasToolTables();
    $oldBot = getChildBotRowById($botId);
    if ($oldBot === null) {
        return ['ok' => false, 'reason' => 'bot_not_found'];
    }

    $role = (string) ($oldBot['bot_type'] ?? '');
    if (!in_array($role, ['guardian', 'uploader'], true)) {
        return ['ok' => false, 'reason' => 'unsupported_bot_type'];
    }

    $ownerId = (int) ($oldBot['owner_telegram_id'] ?? 0);
    $channelFolderId = (int) ($oldBot['channel_folder_id'] ?? 0);
    if (!isZapasEnabledForChannelFolder($ownerId, $channelFolderId)) {
        return ['ok' => false, 'reason' => 'zapas_disabled_for_folder'];
    }

    $zapas = pickStandbyZapasBot($ownerId);
    if ($zapas === null) {
        return ['ok' => false, 'reason' => 'no_standby_zapas'];
    }

    $newBotId = activateZapasBotForRole($zapas, $oldBot, $role);
    $linksUpdated = remapGuardianLinks($botId, $newBotId, $role);
    $filesMigrated = $role === 'uploader' ? migrateUploaderAssets($botId, $newBotId) : 0;

    $newBot = getChildBotRowById($newBotId) ?? [];
    $postsUpdated = updateChannelPostsAfterBotSwap($ownerId, $botId, $newBotId, $role, $newBot);

    $db = getDb();
    $oldUsername = (string) ($oldBot['bot_username'] ?? '');
    $newUsername = (string) ($newBot['bot_username'] ?? '');
    $stmt = $db->prepare(
        'INSERT INTO zapas_replacements
         (owner_telegram_id, old_bot_id, new_bot_id, old_bot_type, old_bot_username, new_bot_username,
          posts_updated, links_updated, files_migrated, reason)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iiissssiiis',
        $ownerId,
        $botId,
        $newBotId,
        $role,
        $oldUsername,
        $newUsername,
        $postsUpdated,
        $linksUpdated,
        $filesMigrated,
        $reason
    );
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE child_bots SET status = "replaced", updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('i', $botId);
    $stmt->execute();
    $stmt->close();

    notifyZapasReplacement($ownerId, $oldBot, $newBot, $role, $postsUpdated);

    return [
        'ok' => true,
        'replaced' => true,
        'stats' => [
            'posts_updated' => $postsUpdated,
            'links_updated' => $linksUpdated,
            'files_migrated' => $filesMigrated,
        ],
    ];
}

function notifyZapasReplacement(int $ownerId, array $oldBot, array $newBot, string $role, int $postsUpdated): void
{
    $roleLabel = $role === 'guardian' ? 'محافظ' : 'آپلودر';
    $oldName = trim((string) ($oldBot['bot_username'] ?? ''));
    $newName = trim((string) ($newBot['bot_username'] ?? ''));
    $text = "🔄 جایگزینی زاپاس\n\n"
        . "نوع: {$roleLabel}\n"
        . 'قدیمی: ' . ($oldName !== '' ? '@' . $oldName : '—') . "\n"
        . 'جدید: ' . ($newName !== '' ? '@' . $newName : '—') . "\n"
        . "پست‌های ویرایش‌شده: {$postsUpdated}";

    telegramRequest('sendMessage', [
        'chat_id' => $ownerId,
        'text' => $text,
    ]);
}

/**
 * @return array<string, mixed>
 */
function getZapasToolsOverview(int $ownerTelegramId): array
{
    $bots = listZapasBots($ownerTelegramId);
    $bindings = getZapasFolderBindings($ownerTelegramId);
    $replacements = listZapasReplacements($ownerTelegramId, 50);
    $standbyCount = count(array_filter($bots, static fn (array $b): bool => ($b['pool_status'] ?? '') === 'standby'));
    $activeCount = count(array_filter($bots, static fn (array $b): bool => ($b['pool_status'] ?? '') === 'active'));

    return [
        'bots' => $bots,
        'bindings' => $bindings,
        'replacements' => $replacements,
        'stats' => [
            'total_bots' => count($bots),
            'standby_count' => $standbyCount,
            'active_count' => $activeCount,
            'replacement_count' => count($replacements),
            'posts_updated_total' => array_sum(array_map(
                static fn (array $r): int => (int) ($r['posts_updated'] ?? 0),
                $replacements
            )),
        ],
    ];
}

function processZapasReplacementsForOwner(int $ownerTelegramId): int
{
    ensureZapasToolTables();
    require_once __DIR__ . '/bot_health.php';
    ensureChildBotHealthColumns();

    $db = getDb();
    $stmt = $db->prepare(
        "SELECT id FROM child_bots
         WHERE owner_telegram_id = ?
           AND status = 'active'
           AND bot_type IN ('guardian', 'uploader')
           AND health_status IS NOT NULL
           AND health_status != ''
           AND health_status != 'ok'"
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $replaced = 0;
    while ($row = $result->fetch_assoc()) {
        $botId = (int) ($row['id'] ?? 0);
        if ($botId <= 0) {
            continue;
        }
        $outcome = attemptZapasReplacementForBot($botId, 'health_check');
        if (!empty($outcome['replaced'])) {
            $replaced++;
        }
    }
    $stmt->close();

    return $replaced;
}

function runServerZapasReplacementPass(int $maxChecks = 20): int
{
    ensureZapasToolTables();
    $db = getDb();
    $limit = max(1, min(100, $maxChecks));
    $result = $db->query(
        "SELECT DISTINCT owner_telegram_id FROM child_bots
         WHERE status = 'active' AND bot_type IN ('guardian', 'uploader')
         ORDER BY owner_telegram_id ASC
         LIMIT {$limit}"
    );
    if (!$result) {
        return 0;
    }

    $total = 0;
    while ($row = $result->fetch_assoc()) {
        $ownerId = (int) ($row['owner_telegram_id'] ?? 0);
        if ($ownerId <= 0) {
            continue;
        }
        $total += processZapasReplacementsForOwner($ownerId);
    }

    return $total;
}
