<?php

require_once dirname(__DIR__) . '/db.php';

function uploaderVersionsDocRoot(): string
{
    return '/home/shombols/public_html';
}

function uploaderVersionsPublicHost(): string
{
    return 'https://shombol.s16.viptelbot.top';
}

function ensureUploaderVersionTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS uploader_versions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(64) NOT NULL,
    project_path VARCHAR(512) NOT NULL,
    public_url VARCHAR(512) NOT NULL,
    webhook_script VARCHAR(120) NOT NULL DEFAULT 'index.php',
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_slug (slug),
    KEY idx_active_default (is_active, is_default)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    ensureUploaderVersionBotRoleColumn();
    ensureChildBotUploaderVersionColumn();
    seedDefaultUploaderVersionIfEmpty();
    seedDefaultGuardianVersionIfMissing();
}

function ensureUploaderVersionBotRoleColumn(): void
{
    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM uploader_versions LIKE 'bot_role'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            "ALTER TABLE uploader_versions ADD COLUMN bot_role VARCHAR(20) NOT NULL DEFAULT 'uploader' AFTER slug"
        );
        $db->query("UPDATE uploader_versions SET bot_role = 'uploader' WHERE bot_role = '' OR bot_role IS NULL");
    }
}

function ensureChildBotUploaderVersionColumn(): void
{
    $db = getDb();
    $tableCheck = $db->query("SHOW TABLES LIKE 'child_bots'");
    if (!$tableCheck || $tableCheck->num_rows === 0) {
        return;
    }

    $result = $db->query("SHOW COLUMNS FROM child_bots LIKE 'uploader_version_id'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            'ALTER TABLE child_bots ADD COLUMN uploader_version_id INT UNSIGNED DEFAULT NULL AFTER channel_folder_id, ADD KEY idx_uploader_version (uploader_version_id)'
        );
    }
}

function normalizeUploaderProjectPath(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = rtrim($path, '/');
    if ($path === '') {
        throw new InvalidArgumentException('project_path_required');
    }

    $docRoot = uploaderVersionsDocRoot();
    if (!str_starts_with($path, $docRoot)) {
        if (str_starts_with($path, 'public_html/')) {
            $path = $docRoot . '/' . ltrim($path, '/');
        } elseif (str_starts_with($path, '/public_html/')) {
            $path = $docRoot . substr($path, strlen('/public_html'));
        } else {
            throw new InvalidArgumentException('invalid_project_path');
        }
    }

    if (!is_dir($path)) {
        throw new InvalidArgumentException('project_path_not_found');
    }

    return $path;
}

function publicUrlFromProjectPath(string $projectPath): string
{
    $docRoot = uploaderVersionsDocRoot();
    $host = rtrim(uploaderVersionsPublicHost(), '/');
    if (!str_starts_with($projectPath, $docRoot)) {
        throw new InvalidArgumentException('invalid_project_path');
    }

    return $host . substr($projectPath, strlen($docRoot));
}

function slugifyUploaderVersionName(string $name): string
{
    $slug = strtolower(trim($name));
    $slug = preg_replace('/[^a-z0-9]+/i', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') {
        $slug = 'uploader-' . substr(bin2hex(random_bytes(4)), 0, 8);
    }

    return substr($slug, 0, 64);
}

function seedDefaultUploaderVersionIfEmpty(): void
{
    $db = getDb();
    $result = $db->query('SELECT COUNT(*) AS c FROM uploader_versions');
    $count = (int) ($result->fetch_assoc()['c'] ?? 0);
    if ($count > 0) {
        return;
    }

    $path = normalizeUploaderProjectPath('/home/shombols/public_html/great/uploader');
    $publicUrl = publicUrlFromProjectPath($path);
    $name = 'مستراپلودر v1.0';
    $slug = 'master-uploader-v1';

    $stmt = $db->prepare(
        'INSERT INTO uploader_versions (name, slug, bot_role, project_path, public_url, webhook_script, is_default, is_active)
         VALUES (?, ?, "uploader", ?, ?, "index.php", 1, 1)'
    );
    $stmt->bind_param('ssss', $name, $slug, $path, $publicUrl);
    $stmt->execute();
    $stmt->close();
}

function seedDefaultGuardianVersionIfMissing(): void
{
    ensureUploaderVersionBotRoleColumn();
    $db = getDb();
    $result = $db->query("SELECT id FROM uploader_versions WHERE bot_role = 'guardian' LIMIT 1");
    if ($result && $result->num_rows > 0) {
        return;
    }

    $path = normalizeUploaderProjectPath('/home/shombols/public_html/great/uploader');
    $publicUrl = publicUrlFromProjectPath($path);
    $name = 'مسترمحافظ v1.0';
    $slug = 'master-guardian-v1';

    $stmt = $db->prepare(
        'INSERT INTO uploader_versions (name, slug, bot_role, project_path, public_url, webhook_script, is_default, is_active)
         VALUES (?, ?, "guardian", ?, ?, "index.php", 1, 1)'
    );
    $stmt->bind_param('ssss', $name, $slug, $path, $publicUrl);
    $stmt->execute();
    $stmt->close();
}

function isUploaderLikeBotRole(string $role): bool
{
    return in_array($role, ['uploader', 'guardian'], true);
}

function getDefaultVersionForBotRole(string $role = 'uploader'): ?array
{
    ensureUploaderVersionTables();
    $role = isUploaderLikeBotRole($role) ? $role : 'uploader';
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT uv.*,
        (SELECT COUNT(*) FROM child_bots cb WHERE cb.uploader_version_id = uv.id AND cb.status = "active") AS bots_count
        FROM uploader_versions uv
        WHERE uv.is_active = 1 AND uv.bot_role = ?
        ORDER BY uv.is_default DESC, uv.id ASC
        LIMIT 1'
    );
    $stmt->bind_param('s', $role);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? formatUploaderVersionRow($row) : null;
}

function listUploaderVersions(bool $activeOnly = false): array
{
    ensureUploaderVersionTables();
    $db = getDb();
    $sql = 'SELECT uv.*,
        (SELECT COUNT(*) FROM child_bots cb WHERE cb.uploader_version_id = uv.id AND cb.status = "active") AS bots_count
        FROM uploader_versions uv';
    if ($activeOnly) {
        $sql .= ' WHERE uv.is_active = 1';
    }
    $sql .= ' ORDER BY uv.is_default DESC, uv.id ASC';

    $result = $db->query($sql);
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = formatUploaderVersionRow($row);
    }

    return $rows;
}

function formatUploaderVersionRow(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'slug' => $row['slug'],
        'bot_role' => (string) ($row['bot_role'] ?? 'uploader'),
        'project_path' => $row['project_path'],
        'public_url' => $row['public_url'],
        'webhook_script' => $row['webhook_script'],
        'is_default' => (int) ($row['is_default'] ?? 0) === 1,
        'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        'bots_count' => (int) ($row['bots_count'] ?? 0),
        'created_at' => $row['created_at'] ?? null,
        'updated_at' => $row['updated_at'] ?? null,
    ];
}

function getUploaderVersionById(int $id): ?array
{
    ensureUploaderVersionTables();
    if ($id <= 0) {
        return null;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT uv.*,
        (SELECT COUNT(*) FROM child_bots cb WHERE cb.uploader_version_id = uv.id AND cb.status = "active") AS bots_count
        FROM uploader_versions uv WHERE uv.id = ? LIMIT 1'
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ? formatUploaderVersionRow($row) : null;
}

function getDefaultUploaderVersion(): ?array
{
    return getDefaultVersionForBotRole('uploader');
}

function getDefaultUploaderVersionId(): int
{
    $version = getDefaultUploaderVersion();

    return (int) ($version['id'] ?? 0);
}

function buildUploaderVersionWebhookUrl(array $version, string $webhookKey): string
{
    $base = rtrim((string) ($version['public_url'] ?? ''), '/');
    $script = ltrim((string) ($version['webhook_script'] ?? 'index.php'), '/');
    if ($base === '') {
        throw new InvalidArgumentException('invalid_uploader_version');
    }

    return $base . '/' . $script . '?key=' . rawurlencode($webhookKey);
}

function clearDefaultUploaderVersions(?int $exceptId = null, ?string $botRole = null): void
{
    $db = getDb();
    $scopedRole = ($botRole !== null && isUploaderLikeBotRole($botRole)) ? $botRole : null;

    if ($exceptId !== null && $exceptId > 0) {
        if ($scopedRole !== null) {
            $stmt = $db->prepare('UPDATE uploader_versions SET is_default = 0 WHERE id <> ? AND bot_role = ?');
            $stmt->bind_param('is', $exceptId, $scopedRole);
        } else {
            $stmt = $db->prepare('UPDATE uploader_versions SET is_default = 0 WHERE id <> ?');
            $stmt->bind_param('i', $exceptId);
        }
        $stmt->execute();
        $stmt->close();

        return;
    }

    if ($scopedRole !== null) {
        $stmt = $db->prepare('UPDATE uploader_versions SET is_default = 0 WHERE bot_role = ?');
        $stmt->bind_param('s', $scopedRole);
        $stmt->execute();
        $stmt->close();

        return;
    }

    $db->query('UPDATE uploader_versions SET is_default = 0');
}

function createUploaderVersion(
    string $name,
    string $projectPath,
    string $webhookScript = 'index.php',
    bool $isDefault = false,
    ?string $slug = null,
    string $botRole = 'uploader'
): array {
    ensureUploaderVersionTables();
    $name = trim($name);
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }

    $botRole = isUploaderLikeBotRole($botRole) ? $botRole : 'uploader';
    $path = normalizeUploaderProjectPath($projectPath);
    $publicUrl = publicUrlFromProjectPath($path);
    $webhookScript = trim($webhookScript) !== '' ? trim($webhookScript) : 'index.php';
    $slug = $slug !== null && trim($slug) !== '' ? slugifyUploaderVersionName($slug) : slugifyUploaderVersionName($name);

    $db = getDb();
    $stmt = $db->prepare('SELECT id FROM uploader_versions WHERE slug = ? LIMIT 1');
    $stmt->bind_param('s', $slug);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($existing) {
        $slug .= '-' . substr(bin2hex(random_bytes(3)), 0, 6);
    }

    if ($isDefault) {
        clearDefaultUploaderVersions(null, $botRole);
    }

    $defaultFlag = $isDefault ? 1 : 0;
    $stmt = $db->prepare(
        'INSERT INTO uploader_versions (name, slug, bot_role, project_path, public_url, webhook_script, is_default, is_active)
         VALUES (?, ?, ?, ?, ?, ?, ?, 1)'
    );
    $stmt->bind_param('ssssssi', $name, $slug, $botRole, $path, $publicUrl, $webhookScript, $defaultFlag);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    if (!$isDefault && !getDefaultVersionForBotRole($botRole)) {
        $stmt = $db->prepare('UPDATE uploader_versions SET is_default = 1 WHERE id = ?');
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
    }

    return getUploaderVersionById($id) ?? [];
}

function updateUploaderVersion(int $id, array $fields): array
{
    ensureUploaderVersionTables();
    $current = getUploaderVersionById($id);
    if (!$current) {
        throw new InvalidArgumentException('version_not_found');
    }

    $name = array_key_exists('name', $fields) ? trim((string) $fields['name']) : $current['name'];
    if ($name === '') {
        throw new InvalidArgumentException('name_required');
    }

    $path = array_key_exists('project_path', $fields)
        ? normalizeUploaderProjectPath((string) $fields['project_path'])
        : $current['project_path'];
    $publicUrl = publicUrlFromProjectPath($path);
    $webhookScript = array_key_exists('webhook_script', $fields)
        ? trim((string) $fields['webhook_script'])
        : $current['webhook_script'];
    if ($webhookScript === '') {
        $webhookScript = 'index.php';
    }

    $isDefault = array_key_exists('is_default', $fields) ? !empty($fields['is_default']) : $current['is_default'];
    $isActive = array_key_exists('is_active', $fields) ? !empty($fields['is_active']) : $current['is_active'];

    if ($isDefault) {
        clearDefaultUploaderVersions($id, (string) ($current['bot_role'] ?? 'uploader'));
    }

    $defaultFlag = $isDefault ? 1 : 0;
    $activeFlag = $isActive ? 1 : 0;
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE uploader_versions
         SET name = ?, project_path = ?, public_url = ?, webhook_script = ?, is_default = ?, is_active = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('ssssiii', $name, $path, $publicUrl, $webhookScript, $defaultFlag, $activeFlag, $id);
    $stmt->execute();
    $stmt->close();

    if (!$isDefault && $current['is_default'] && !$isActive) {
        $fallback = getDefaultUploaderVersion();
        if (!$fallback || (int) $fallback['id'] === $id) {
            $db->query('UPDATE uploader_versions SET is_default = 1 WHERE is_active = 1 ORDER BY id ASC LIMIT 1');
        }
    }

    return getUploaderVersionById($id) ?? [];
}

function resolveUploaderVersionForBot(int $versionId = 0, string $botRole = 'uploader'): array
{
    $botRole = isUploaderLikeBotRole($botRole) ? $botRole : 'uploader';
    if ($versionId > 0) {
        $version = getUploaderVersionById($versionId);
        if ($version && (string) ($version['bot_role'] ?? 'uploader') !== $botRole) {
            $version = null;
        }
    } else {
        $version = getDefaultVersionForBotRole($botRole);
        if (!$version && $botRole === 'uploader') {
            $version = getDefaultUploaderVersion();
        }
    }
    if (!$version || empty($version['is_active'])) {
        throw new InvalidArgumentException('uploader_version_required');
    }

    return $version;
}
