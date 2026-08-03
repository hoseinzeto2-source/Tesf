<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';

/**
 * @var list<string>
 */
function explorerPinTables(): array
{
    return [
        'channel_folders',
        'bot_folders',
        'content_group_folders',
        'auto_post_folders',
        'auto_post_sessions',
        'hashtag_tool_folders',
        'hashtag_post_sets',
        'bot_channels',
        'child_bots',
        'content_groups',
    ];
}

function ensureExplorerPinColumns(string $table): void
{
    static $done = [];
    if (isset($done[$table])) {
        return;
    }

    if (!in_array($table, explorerPinTables(), true)) {
        throw new InvalidArgumentException('invalid_table');
    }

    if (schemaMigrationsComplete()) {
        $done[$table] = true;

        return;
    }

    $db = getDb();
    foreach ([
        'is_pinned' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'pinned_at' => 'DATETIME NULL DEFAULT NULL',
    ] as $column => $definition) {
        $safeColumn = preg_replace('/[^a-z_]/', '', $column);
        $result = $db->query("SHOW COLUMNS FROM `{$table}` LIKE '{$safeColumn}'");
        if ($result && $result->num_rows === 0) {
            $db->query("ALTER TABLE `{$table}` ADD COLUMN `{$safeColumn}` {$definition}");
        }
    }

    $done[$table] = true;
}

function ensureAllExplorerPinColumns(): void
{
    foreach (explorerPinTables() as $table) {
        ensureExplorerPinColumns($table);
    }
}

function explorerPinPayload(array $row): array
{
    return [
        'is_pinned' => (int) ($row['is_pinned'] ?? 0) === 1,
        'pinned_at' => $row['pinned_at'] ?? null,
    ];
}

function setExplorerEntityPinned(
    string $table,
    string $idColumn,
    int $id,
    bool $pinned,
    ?string $ownerColumn = null,
    ?int $ownerId = null
): bool {
    ensureExplorerPinColumns($table);

    $safeTable = preg_replace('/[^a-z_]/', '', $table);
    $safeIdColumn = preg_replace('/[^a-z_]/', '', $idColumn);
    if ($safeTable === '' || $safeIdColumn === '') {
        throw new InvalidArgumentException('invalid_table');
    }

    if ($id <= 0) {
        throw new InvalidArgumentException('invalid_id');
    }

    $db = getDb();
    if ($pinned) {
        $sql = "UPDATE `{$safeTable}` SET is_pinned = 1, pinned_at = NOW() WHERE `{$safeIdColumn}` = ?";
    } else {
        $sql = "UPDATE `{$safeTable}` SET is_pinned = 0, pinned_at = NULL WHERE `{$safeIdColumn}` = ?";
    }

    $types = 'i';
    $values = [$id];

    if ($ownerColumn !== null && $ownerId !== null) {
        $safeOwnerColumn = preg_replace('/[^a-z_]/', '', $ownerColumn);
        if ($safeOwnerColumn === '') {
            throw new InvalidArgumentException('invalid_owner_column');
        }
        $sql .= " AND `{$safeOwnerColumn}` = ?";
        $types .= 'i';
        $values[] = $ownerId;
    }

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $ok = $stmt->affected_rows >= 0;
    $stmt->close();

    return $ok;
}

function explorerPinOrderSql(string $prefix = ''): string
{
    $p = $prefix !== '' ? $prefix . '.' : '';

    return "{$p}is_pinned DESC, {$p}pinned_at DESC";
}
