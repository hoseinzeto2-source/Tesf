<?php

require_once dirname(__DIR__) . '/db.php';

function schemaMarkerPath(): string
{
    return matherPath('storage/.schema_v3');
}

function schemaMigrationsComplete(): bool
{
    return is_file(schemaMarkerPath());
}

function markSchemaMigrationsComplete(): void
{
    $path = schemaMarkerPath();
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }

    file_put_contents($path, date('c'));
}

function schemaColumnsLookReady(): bool
{
    try {
        $db = getDb();
        $checks = [
            "SHOW COLUMNS FROM bot_folders LIKE 'parent_id'",
            "SHOW COLUMNS FROM child_bots LIKE 'profile_photo_file_id'",
            "SHOW COLUMNS FROM child_bots LIKE 'health_status'",
        ];
        foreach ($checks as $sql) {
            $result = $db->query($sql);
            if (!$result || $result->num_rows === 0) {
                return false;
            }
        }

        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * @return array<string, mixed>
 */
function runSchemaMigrations(bool $forceMarkerOnly = false): array
{
    if (schemaMigrationsComplete()) {
        return ['ok' => true, 'skipped' => true, 'reason' => 'already_complete'];
    }

    if ($forceMarkerOnly || schemaColumnsLookReady()) {
        markSchemaMigrationsComplete();

        return ['ok' => true, 'skipped' => true, 'reason' => 'columns_ready_marker_written'];
    }

    $db = getDb();
    $lockRow = $db->query("SELECT GET_LOCK('mather_schema_migrate', 5)");
    $gotLock = $lockRow && ($lockRow->fetch_row()[0] ?? 0) == 1;

    if (!$gotLock) {
        if (schemaColumnsLookReady()) {
            markSchemaMigrationsComplete();

            return ['ok' => true, 'skipped' => true, 'reason' => 'lock_busy_columns_ready'];
        }

        return ['ok' => false, 'error' => 'schema_lock_timeout'];
    }

    $steps = [];

    try {
        require_once __DIR__ . '/explorer_pins.php';
        require_once __DIR__ . '/bot_folders.php';
        require_once __DIR__ . '/child_bots.php';
        require_once __DIR__ . '/bot_health.php';
        require_once __DIR__ . '/bot_stats.php';

        ensureBotFolderParentColumn();
        $steps[] = 'bot_folders.parent_id';

        foreach (explorerPinTables() as $table) {
            ensureExplorerPinColumns($table);
            $steps[] = 'pins.' . $table;
        }

        ensureChildBotChannelFolderColumn();
        $steps[] = 'child_bots.columns';
        ensureChildBotProfilePhotoColumn();
        $steps[] = 'child_bots.profile_photo';
        ensureChildBotHealthColumns();
        $steps[] = 'child_bots.health';
        ensureUploaderFilesLocalCacheColumn();
        $steps[] = 'uploader_files.columns';
        ensureUploaderUsersCreatedAtColumn();
        $steps[] = 'uploader_users.created_at';

        markSchemaMigrationsComplete();
        $steps[] = 'marker_written';
    } finally {
        $db->query("SELECT RELEASE_LOCK('mather_schema_migrate')");
    }

    return ['ok' => true, 'steps' => $steps];
}
