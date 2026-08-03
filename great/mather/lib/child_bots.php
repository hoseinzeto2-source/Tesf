<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';
require_once __DIR__ . '/telegram_api.php';
require_once __DIR__ . '/campaigns.php';
require_once __DIR__ . '/channel_folders.php';

function ensureChildBotTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS child_bots (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    bot_telegram_id BIGINT NOT NULL,
    bot_username VARCHAR(255) DEFAULT NULL,
    bot_name VARCHAR(255) DEFAULT NULL,
    bot_token TEXT NOT NULL,
    webhook_key VARCHAR(64) NOT NULL,
    bot_type VARCHAR(20) NOT NULL DEFAULT 'uploader',
    channel_folder_id INT UNSIGNED DEFAULT NULL,
    uploader_version_id INT UNSIGNED DEFAULT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bot_telegram_id (bot_telegram_id),
    KEY idx_owner (owner_telegram_id),
    KEY idx_owner_type (owner_telegram_id, bot_type),
    KEY idx_channel_folder (channel_folder_id),
    KEY idx_uploader_version (uploader_version_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureChildBotChannelFolderColumn();
    require_once __DIR__ . '/bot_profile.php';
    ensureChildBotProfilePhotoColumn();
    require_once __DIR__ . '/explorer_pins.php';
    ensureExplorerPinColumns('child_bots');
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS uploader_files (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_bot_id INT UNSIGNED NOT NULL,
    owner_telegram_id BIGINT NOT NULL,
    link_code VARCHAR(32) NOT NULL,
    file_type VARCHAR(32) NOT NULL,
    file_id VARCHAR(255) DEFAULT NULL,
    caption TEXT DEFAULT NULL,
    text_content TEXT DEFAULT NULL,
    mime_type VARCHAR(128) DEFAULT NULL,
    download_count INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_child_link (child_bot_id, link_code),
    KEY idx_owner (owner_telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureUploaderFilesLocalCacheColumn();
}

function ensureUploaderFilesLocalCacheColumn(): void
{
    if (schemaMigrationsComplete()) {
        return;
    }

    $db = getDb();
    $check = $db->query("SHOW COLUMNS FROM uploader_files LIKE 'local_cache_path'");
    if ($check && $check->num_rows === 0) {
        $db->query('ALTER TABLE uploader_files ADD COLUMN local_cache_path VARCHAR(512) DEFAULT NULL');
    }

    $columns = [
        'link_code' => 'VARCHAR(32) DEFAULT NULL',
        'caption' => 'TEXT DEFAULT NULL',
        'file_type' => 'VARCHAR(32) DEFAULT NULL',
        'owner_telegram_id' => 'BIGINT DEFAULT NULL',
        'file_id' => 'VARCHAR(255) DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        $col = $db->query("SHOW COLUMNS FROM uploader_files LIKE '{$name}'");
        if ($col && $col->num_rows === 0) {
            $db->query("ALTER TABLE uploader_files ADD COLUMN {$name} {$definition}");
        }
    }
}

function ensureChildBotChannelFolderColumn(): void
{
    if (schemaMigrationsComplete()) {
        return;
    }

    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM child_bots LIKE 'channel_folder_id'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            'ALTER TABLE child_bots ADD COLUMN channel_folder_id INT UNSIGNED DEFAULT NULL AFTER bot_type, ADD KEY idx_channel_folder (channel_folder_id)'
        );
    }

    $result = $db->query("SHOW COLUMNS FROM child_bots LIKE 'uploader_version_id'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            'ALTER TABLE child_bots ADD COLUMN uploader_version_id INT UNSIGNED DEFAULT NULL AFTER channel_folder_id, ADD KEY idx_uploader_version (uploader_version_id)'
        );
    }
}

function childBotsBaseUrl(): string
{
    global $base_url;
    return rtrim($base_url ?? 'https://shombol.s16.viptelbot.top/great/mather', '/');
}

function generateWebhookKey(): string
{
    return bin2hex(random_bytes(16));
}

function generateLinkCode(): string
{
    return substr(bin2hex(random_bytes(6)), 0, 12);
}

function getChildBotByKey(string $key): ?array
{
    ensureChildBotTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM child_bots WHERE webhook_key = ? AND status = "active" LIMIT 1'
    );
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getChildBotsByOwner(int $ownerId, bool $withUploadsCount = true): array
{
    ensureChildBotTables();
    require_once __DIR__ . '/bot_health.php';
    ensureChildBotHealthColumns();
    $db = getDb();
    $uploadsSql = $withUploadsCount
        ? ', (SELECT COUNT(*) FROM uploader_files uf WHERE uf.child_bot_id = child_bots.id) AS uploads_count'
        : ', 0 AS uploads_count';
    $stmt = $db->prepare(
        'SELECT id, bot_telegram_id, bot_username, bot_name, bot_type, channel_folder_id, uploader_version_id, status,
                health_status, health_message, problem_since, last_health_check, profile_photo_file_id, created_at,
                is_pinned, pinned_at' . $uploadsSql . '
        FROM child_bots WHERE owner_telegram_id = ? ORDER BY is_pinned DESC, pinned_at DESC, id DESC'
    );
    $stmt->bind_param('i', $ownerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getAllChildBots(): array
{
    $db = getDb();
    $result = $db->query(
        'SELECT cb.*, u.username AS owner_username, u.first_name AS owner_name,
        (SELECT COUNT(*) FROM uploader_files uf WHERE uf.child_bot_id = cb.id) AS uploads_count
        FROM child_bots cb
        LEFT JOIN users u ON u.telegram_id = cb.owner_telegram_id
        ORDER BY cb.id DESC LIMIT 200'
    );
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    return $rows;
}

function getChildBotByOwnerAndType(int $ownerId, string $type): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM child_bots WHERE owner_telegram_id = ? AND bot_type = ? AND status = "active" ORDER BY id DESC LIMIT 1'
    );
    $stmt->bind_param('is', $ownerId, $type);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function resolveTelegramBotDisplayName(string $token, array $me, string $type = 'uploader'): string
{
    $nameResp = tgRequestWithToken($token, 'getMyName');
    if (!empty($nameResp['ok']) && isset($nameResp['result']['name'])) {
        $name = trim((string) $nameResp['result']['name']);
        if ($name !== '') {
            return $name;
        }
    }

    $firstName = trim((string) ($me['first_name'] ?? ''));
    if ($firstName !== '') {
        return $firstName;
    }

    $defaults = [
        'uploader' => 'Uploader Bot',
        'guardian' => 'Guardian Bot',
        'panel' => 'Panel Bot',
        'gos' => 'Gos Bot',
    ];

    return $defaults[$type] ?? 'Bot';
}

function createChildBot(int $ownerId, string $token, string $type, string $customName = '', int $channelFolderId = 0, int $uploaderVersionId = 0): array
{
    ensureChildBotTables();
    if ($channelFolderId <= 0) {
        throw new InvalidArgumentException('channel_folder_required');
    }
    if (!channelFolderExists($channelFolderId)) {
        throw new InvalidArgumentException('invalid_channel_folder');
    }

    $bot = tgGetMe($token);
    if (!$bot) {
        throw new InvalidArgumentException('invalid_token');
    }

    $webhookUrl = '';
    $resolvedVersionId = null;
    if (in_array($type, ['uploader', 'guardian', 'zapas'], true)) {
        require_once __DIR__ . '/uploader_versions.php';
        $roleForVersion = $type === 'zapas' ? 'uploader' : $type;
        $version = resolveUploaderVersionForBot($uploaderVersionId, $roleForVersion);
        $resolvedVersionId = (int) $version['id'];
        $webhookKey = generateWebhookKey();
        $webhookUrl = buildUploaderVersionWebhookUrl($version, $webhookKey);
    } else {
        $paths = [
            'panel' => '/panel/bot.php',
            'gos' => '/gos/bot.php',
        ];
        if (!isset($paths[$type])) {
            throw new InvalidArgumentException('invalid_type');
        }
        $webhookKey = generateWebhookKey();
        $webhookUrl = childBotsBaseUrl() . $paths[$type] . '?key=' . urlencode($webhookKey);
    }

    $botTelegramId = (int) $bot['id'];
    $botUsername = $bot['username'] ?? '';
    $botName = $customName !== '' ? $customName : resolveTelegramBotDisplayName($token, $bot, $type);

    if (!tgSetWebhook($token, $webhookUrl)) {
        throw new RuntimeException('webhook_failed');
    }

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO child_bots (owner_telegram_id, bot_telegram_id, bot_username, bot_name, bot_token, webhook_key, bot_type, channel_folder_id, uploader_version_id, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "active")
         ON DUPLICATE KEY UPDATE
           owner_telegram_id = VALUES(owner_telegram_id),
           bot_username = VALUES(bot_username),
           bot_name = VALUES(bot_name),
           bot_token = VALUES(bot_token),
           webhook_key = VALUES(webhook_key),
           bot_type = VALUES(bot_type),
           channel_folder_id = VALUES(channel_folder_id),
           uploader_version_id = VALUES(uploader_version_id),
           status = "active",
           updated_at = NOW()'
    );
    $versionIdForDb = $resolvedVersionId !== null ? (int) $resolvedVersionId : 0;
    $stmt->bind_param('iisssssii', $ownerId, $botTelegramId, $botUsername, $botName, $token, $webhookKey, $type, $channelFolderId, $versionIdForDb);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    if ($id === 0) {
        $stmt = $db->prepare('SELECT id FROM child_bots WHERE bot_telegram_id = ? LIMIT 1');
        $stmt->bind_param('i', $botTelegramId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $id = (int) ($row['id'] ?? 0);
    }

    if (in_array($type, ['uploader', 'guardian', 'zapas'], true) && $id > 0) {
        require_once __DIR__ . '/bot_joins.php';
        initializeChildBotUploaderDefaults($id);
    }

    if ($id > 0) {
        require_once __DIR__ . '/bot_profile.php';
        syncChildBotProfilePhotoCache($id, [
            'id' => $id,
            'bot_telegram_id' => $botTelegramId,
            'bot_token' => $token,
        ]);
    }

    if ($type === 'panel') {
        approveGosUser($ownerId, $ownerId);
    }

    return [
        'id' => $id,
        'bot_telegram_id' => $botTelegramId,
        'bot_username' => $botUsername,
        'bot_name' => $botName,
        'bot_type' => $type,
        'channel_folder_id' => $channelFolderId,
        'uploader_version_id' => $resolvedVersionId,
        'webhook_url' => $webhookUrl,
    ];
}

function createGuardianBot(int $ownerId, string $token, string $customName = '', int $channelFolderId = 0, int $uploaderVersionId = 0): array
{
    $result = createChildBot($ownerId, $token, 'guardian', $customName, $channelFolderId, $uploaderVersionId);
    $result['uploads_count'] = 0;
    return $result;
}

function createUploaderBot(int $ownerId, string $token, string $customName = '', int $channelFolderId = 0, int $uploaderVersionId = 0): array
{
    $result = createChildBot($ownerId, $token, 'uploader', $customName, $channelFolderId, $uploaderVersionId);
    $result['uploads_count'] = 0;
    return $result;
}

function createPanelBot(int $ownerId, string $token, string $customName = '', int $channelFolderId = 0, int $uploaderVersionId = 0): array
{
    return createChildBot($ownerId, $token, 'panel', $customName, $channelFolderId, $uploaderVersionId);
}

function createGosBot(int $ownerId, string $token, string $customName = '', int $channelFolderId = 0, int $uploaderVersionId = 0): array
{
    return createChildBot($ownerId, $token, 'gos', $customName, $channelFolderId, $uploaderVersionId);
}

function saveUploaderFile(int $childBotId, int $ownerId, array $payload): string
{
    ensureChildBotTables();
    ensureUploaderFilesLocalCacheColumn();

    $db = getDb();
    $code = generateLinkCode();
    $localPath = $payload['local_cache_path'] ?? null;
    $fileType = (string) ($payload['file_type'] ?? 'photo');
    $fileId = (string) ($payload['file_id'] ?? '');
    $caption = $payload['caption'] ?? '';
    $mimeType = $payload['mime_type'] ?? '';
    $originalName = basename((string) ($localPath ?: 'auto-post-media'));
    $relativePath = $localPath;

    $expiresAt = '2099-12-31 23:59:59';
    $stmt = $db->prepare(
        'INSERT INTO uploader_files
         (child_bot_id, owner_telegram_id, link_code, file_type, file_id, local_cache_path,
          caption, mime_type, public_token, telegram_id, original_name, stored_name, relative_path,
          telegram_file_id, expires_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->bind_param(
        'iisssssssisssss',
        $childBotId,
        $ownerId,
        $code,
        $fileType,
        $fileId,
        $localPath,
        $caption,
        $mimeType,
        $code,
        $ownerId,
        $originalName,
        $originalName,
        $relativePath,
        $fileId,
        $expiresAt
    );
    $stmt->execute();
    $stmt->close();

    return $code;
}

function getUploaderFileByCode(int $childBotId, string $code): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM uploader_files WHERE child_bot_id = ? AND link_code = ? LIMIT 1'
    );
    $stmt->bind_param('is', $childBotId, $code);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function incrementDownloadCount(int $fileId): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE uploader_files SET download_count = download_count + 1 WHERE id = ?');
    $stmt->bind_param('i', $fileId);
    $stmt->execute();
    $stmt->close();
}
