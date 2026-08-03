<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/auto_post.php';
require_once __DIR__ . '/content_groups.php';
require_once __DIR__ . '/content_group_stats.php';
require_once __DIR__ . '/child_bots.php';
require_once __DIR__ . '/bot_folders.php';
require_once __DIR__ . '/bot_health.php';
require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/hashtag_tools.php';
require_once __DIR__ . '/banner_tools.php';
require_once __DIR__ . '/telegram_api.php';

function autoPostIranTimezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Tehran');
}

function ensureAutoPostScheduleTables(): void
{
    ensureAutoPostTables();
    ensureContentGroupStatColumns();
    ensureAutoPostScheduleMediaColumns();

    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_id INT UNSIGNED NOT NULL,
    owner_telegram_id BIGINT NOT NULL,
    name VARCHAR(160) NOT NULL DEFAULT '',
    content_group_chat_id BIGINT NOT NULL,
    media_type VARCHAR(20) NOT NULL DEFAULT 'photo',
    schedule_mode VARCHAR(20) NOT NULL DEFAULT 'daily_fixed',
    start_time TIME NOT NULL DEFAULT '17:30:00',
    rotate_hours DECIMAL(5,2) NOT NULL DEFAULT 1.00,
    media_cursor INT UNSIGNED NOT NULL DEFAULT 0,
    guardian_cursor INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    last_post_at DATETIME NULL DEFAULT NULL,
    next_post_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_session (session_id),
    KEY idx_next_post (status, next_post_at),
    KEY idx_owner (owner_telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_schedule_media (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT UNSIGNED NOT NULL,
    chat_id BIGINT NOT NULL,
    message_id INT NOT NULL,
    media_kind VARCHAR(20) NOT NULL,
    telegram_file_id VARCHAR(255) DEFAULT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    label VARCHAR(80) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_schedule_message (schedule_id, chat_id, message_id),
    KEY idx_schedule_sort (schedule_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_runs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT UNSIGNED NOT NULL,
    media_item_id INT UNSIGNED NOT NULL,
    guardian_bot_id INT UNSIGNED NOT NULL,
    uploader_bot_id INT UNSIGNED NOT NULL,
    link_code VARCHAR(32) NOT NULL,
    channels_posted INT UNSIGNED NOT NULL DEFAULT 0,
    channels_failed INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_message TEXT DEFAULT NULL,
    run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_schedule (schedule_id),
    KEY idx_run_at (run_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_guardian_links (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    guardian_bot_id INT UNSIGNED NOT NULL,
    uploader_bot_id INT UNSIGNED NOT NULL,
    link_code VARCHAR(32) NOT NULL,
    media_type VARCHAR(20) NOT NULL,
    content_label VARCHAR(80) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_guardian_code (guardian_bot_id, link_code),
    KEY idx_uploader_code (uploader_bot_id, link_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );

    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS auto_post_uploader_media_cache (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uploader_bot_id INT UNSIGNED NOT NULL,
    source_chat_id BIGINT NOT NULL,
    source_message_id INT NOT NULL,
    link_code VARCHAR(32) NOT NULL,
    file_type VARCHAR(20) NOT NULL,
    file_id VARCHAR(255) NOT NULL,
    caption TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_uploader_source (uploader_bot_id, source_chat_id, source_message_id),
    KEY idx_link_code (link_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function ensureAutoPostScheduleMediaColumns(): void
{
    $db = getDb();
    $check = $db->query("SHOW COLUMNS FROM content_group_seen_messages LIKE 'telegram_file_id'");
    if ($check && $check->num_rows === 0) {
        $db->query('ALTER TABLE content_group_seen_messages ADD COLUMN telegram_file_id VARCHAR(255) DEFAULT NULL AFTER media_kind');
    }
    $check2 = $db->query("SHOW COLUMNS FROM content_group_seen_messages LIKE 'caption'");
    if ($check2 && $check2->num_rows === 0) {
        $db->query('ALTER TABLE content_group_seen_messages ADD COLUMN caption TEXT DEFAULT NULL AFTER telegram_file_id');
    }

    $check3 = $db->query("SHOW COLUMNS FROM uploader_files LIKE 'local_cache_path'");
    if ($check3 && $check3->num_rows === 0) {
        $db->query('ALTER TABLE uploader_files ADD COLUMN local_cache_path VARCHAR(512) DEFAULT NULL');
    }

    ensureMatherUploaderDeliveryColumns();
}

function ensureMatherUploaderDeliveryColumns(): void
{
    ensureUploaderFilesLocalCacheColumn();
}

function normalizeAutoPostMediaType(string $type): string
{
    $type = strtolower(trim($type));
    $allowed = ['photo', 'video', 'voice', 'file'];
    if (!in_array($type, $allowed, true)) {
        throw new InvalidArgumentException('invalid_media_type');
    }

    return $type;
}

function mediaKindMatchesType(string $mediaKind, string $mediaType): bool
{
    if ($mediaType === 'file') {
        return in_array($mediaKind, ['document', 'file', 'other'], true);
    }

    return $mediaKind === $mediaType;
}

function autoPostMediaTypeLabel(string $type): string
{
    return match ($type) {
        'photo' => 'عکس',
        'video' => 'فیلم',
        'voice' => 'ویس',
        'file' => 'فایل',
        default => 'محتوا',
    };
}

function persianMediaOrdinal(int $index): string
{
    $labels = ['اول', 'دوم', 'سوم', 'چهارم', 'پنجم', 'ششم', 'هفتم', 'هشتم', 'نهم', 'دهم'];
    if ($index >= 1 && $index <= count($labels)) {
        return $labels[$index - 1];
    }

    return (string) $index;
}

/**
 * @return list<array<string, mixed>>
 */
function listContentGroupMediaItems(int $chatId, string $mediaType, int $limit = 60, int $offset = 0): array
{
    ensureAutoPostScheduleTables();
    $mediaType = normalizeAutoPostMediaType($mediaType);
    $db = getDb();

    $kinds = [];
    if ($mediaType === 'file') {
        $kinds = ['document', 'file', 'other'];
    } else {
        $kinds = [$mediaType];
    }

    $placeholders = implode(',', array_fill(0, count($kinds), '?'));
    $types = str_repeat('s', count($kinds)) . 'iii';
    $params = [...$kinds, $chatId, $limit, $offset];

    $sql = "SELECT chat_id, message_id, media_kind, telegram_file_id, caption, seen_at
            FROM content_group_seen_messages
            WHERE media_kind IN ({$placeholders}) AND chat_id = ?
            ORDER BY seen_at DESC, message_id DESC
            LIMIT ? OFFSET ?";

    $stmt = $db->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    $items = [];
    $ordinal = $offset + 1;
    while ($row = $result->fetch_assoc()) {
        $kind = (string) ($row['media_kind'] ?? 'other');
        $typeLabel = autoPostMediaTypeLabel($kind === 'document' ? 'file' : $kind);
        $items[] = [
            'chat_id' => (int) $row['chat_id'],
            'message_id' => (int) $row['message_id'],
            'media_kind' => $kind,
            'media_type' => $mediaType,
            'telegram_file_id' => $row['telegram_file_id'] ?? null,
            'caption' => $row['caption'] ?? null,
            'seen_at' => $row['seen_at'] ?? null,
            'label' => $typeLabel . ' ' . persianMediaOrdinal($ordinal),
            'ordinal' => $ordinal,
        ];
        $ordinal++;
    }
    $stmt->close();

    return $items;
}

function countContentGroupMediaItems(int $chatId, string $mediaType): int
{
    ensureAutoPostScheduleTables();
    $mediaType = normalizeAutoPostMediaType($mediaType);
    $db = getDb();

    if ($mediaType === 'file') {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS cnt FROM content_group_seen_messages
             WHERE chat_id = ? AND media_kind IN ('document', 'file', 'other')"
        );
        $stmt->bind_param('i', $chatId);
    } else {
        $stmt = $db->prepare(
            'SELECT COUNT(*) AS cnt FROM content_group_seen_messages WHERE chat_id = ? AND media_kind = ?'
        );
        $stmt->bind_param('is', $chatId, $mediaType);
    }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['cnt'] ?? 0);
}

/**
 * @param list<array{chat_id:int,message_id:int,media_kind?:string,telegram_file_id?:string|null}> $mediaItems
 */
function createAutoPostSchedule(
    int $ownerTelegramId,
    int $sessionId,
    int $contentGroupChatId,
    string $mediaType,
    array $mediaItems,
    string $scheduleMode,
    string $startTime,
    float $rotateHours = 1.0,
    string $name = ''
): array {
    ensureAutoPostScheduleTables();

    if ($sessionId <= 0) {
        throw new InvalidArgumentException('invalid_session');
    }
    if ($contentGroupChatId === 0) {
        throw new InvalidArgumentException('content_group_required');
    }
    if ($mediaItems === []) {
        throw new InvalidArgumentException('media_items_required');
    }

    $mediaType = normalizeAutoPostMediaType($mediaType);
    $scheduleMode = in_array($scheduleMode, ['daily_fixed', 'daily_rotate'], true)
        ? $scheduleMode
        : 'daily_fixed';

    if (!preg_match('/^\d{1,2}:\d{2}$/', $startTime)) {
        throw new InvalidArgumentException('invalid_start_time');
    }
    [$h, $m] = array_map('intval', explode(':', $startTime));
    if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
        throw new InvalidArgumentException('invalid_start_time');
    }
    $startTimeSql = sprintf('%02d:%02d:00', $h, $m);

    if ($rotateHours <= 0 || $rotateHours > 24) {
        $rotateHours = 1.0;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id FROM auto_post_sessions WHERE id = ? AND owner_telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        throw new InvalidArgumentException('session_not_found');
    }

    $group = getContentGroupDetails($contentGroupChatId);
    if (!$group || !isContentPostBioGroup($group['chat_description'] ?? null)) {
        throw new InvalidArgumentException('content_group_not_found');
    }

    $name = trim($name);
    if ($name === '') {
        $name = autoPostMediaTypeLabel($mediaType) . ' · ' . ($group['title'] ?? 'گروه');
    }
    if (mb_strlen($name) > 160) {
        $name = mb_substr($name, 0, 157) . '...';
    }

    $nextPostAt = computeNextAutoPostTime(null, $scheduleMode, $startTimeSql, $rotateHours);

    $stmt = $db->prepare(
        'INSERT INTO auto_post_schedules
         (session_id, owner_telegram_id, name, content_group_chat_id, media_type,
          schedule_mode, start_time, rotate_hours, next_post_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $nextStr = $nextPostAt->format('Y-m-d H:i:s');
    $stmt->bind_param(
        'iisissdss',
        $sessionId,
        $ownerTelegramId,
        $name,
        $contentGroupChatId,
        $mediaType,
        $scheduleMode,
        $startTimeSql,
        $rotateHours,
        $nextStr
    );
    $stmt->execute();
    $scheduleId = (int) $stmt->insert_id;
    $stmt->close();

    $sort = 0;
    foreach ($mediaItems as $item) {
        $chatId = (int) ($item['chat_id'] ?? 0);
        $messageId = (int) ($item['message_id'] ?? 0);
        if ($chatId === 0 || $messageId === 0) {
            continue;
        }
        $kind = (string) ($item['media_kind'] ?? $mediaType);
        $fileId = $item['telegram_file_id'] ?? null;
        $label = trim((string) ($item['label'] ?? ''));
        if ($label === '') {
            $label = autoPostMediaTypeLabel($mediaType) . ' ' . persianMediaOrdinal($sort + 1);
        }

        $stmt = $db->prepare(
            'INSERT INTO auto_post_schedule_media
             (schedule_id, chat_id, message_id, media_kind, telegram_file_id, sort_order, label)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param('iiissis', $scheduleId, $chatId, $messageId, $kind, $fileId, $sort, $label);
        $stmt->execute();
        $stmt->close();
        $sort++;
    }

    if ($sort === 0) {
        $db->query('DELETE FROM auto_post_schedules WHERE id = ' . $scheduleId);
        throw new InvalidArgumentException('media_items_required');
    }

    return getAutoPostScheduleById($ownerTelegramId, $scheduleId) ?? ['id' => $scheduleId];
}

/**
 * @param list<array{chat_id:int,message_id:int,media_kind?:string,telegram_file_id?:string|null,label?:string}>|null $mediaItems
 */
function updateAutoPostSchedule(
    int $ownerTelegramId,
    int $scheduleId,
    ?string $name = null,
    ?int $contentGroupChatId = null,
    ?string $mediaType = null,
    ?array $mediaItems = null,
    ?string $scheduleMode = null,
    ?string $startTime = null,
    ?float $rotateHours = null
): array {
    ensureAutoPostScheduleTables();
    $existing = getAutoPostScheduleById($ownerTelegramId, $scheduleId);
    if (!$existing) {
        throw new InvalidArgumentException('schedule_not_found');
    }

    $fields = [];
    $types = '';
    $values = [];

    if ($name !== null) {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('name_required');
        }
        if (mb_strlen($name) > 160) {
            $name = mb_substr($name, 0, 157) . '...';
        }
        $fields[] = 'name = ?';
        $types .= 's';
        $values[] = $name;
    }

    if ($contentGroupChatId !== null && $contentGroupChatId !== 0) {
        $group = getContentGroupDetails($contentGroupChatId);
        if (!$group || !isContentPostBioGroup($group['chat_description'] ?? null)) {
            throw new InvalidArgumentException('content_group_not_found');
        }
        $fields[] = 'content_group_chat_id = ?';
        $types .= 'i';
        $values[] = $contentGroupChatId;
    }

    if ($mediaType !== null) {
        $mediaType = normalizeAutoPostMediaType($mediaType);
        $fields[] = 'media_type = ?';
        $types .= 's';
        $values[] = $mediaType;
    }

    $recomputeNext = false;
    if ($scheduleMode !== null) {
        $scheduleMode = in_array($scheduleMode, ['daily_fixed', 'daily_rotate'], true)
            ? $scheduleMode
            : 'daily_fixed';
        $fields[] = 'schedule_mode = ?';
        $types .= 's';
        $values[] = $scheduleMode;
        $recomputeNext = true;
    }

    if ($startTime !== null) {
        if (!preg_match('/^\d{1,2}:\d{2}$/', $startTime)) {
            throw new InvalidArgumentException('invalid_start_time');
        }
        [$h, $m] = array_map('intval', explode(':', $startTime));
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            throw new InvalidArgumentException('invalid_start_time');
        }
        $startTimeSql = sprintf('%02d:%02d:00', $h, $m);
        $fields[] = 'start_time = ?';
        $types .= 's';
        $values[] = $startTimeSql;
        $recomputeNext = true;
    }

    if ($rotateHours !== null) {
        $rotateHours = $rotateHours <= 0 || $rotateHours > 24 ? 1.0 : $rotateHours;
        $fields[] = 'rotate_hours = ?';
        $types .= 'd';
        $values[] = $rotateHours;
        $recomputeNext = true;
    }

    $db = getDb();
    if ($fields !== []) {
        $sql = 'UPDATE auto_post_schedules SET ' . implode(', ', $fields)
            . ' WHERE id = ? AND owner_telegram_id = ?';
        $types .= 'ii';
        $values[] = $scheduleId;
        $values[] = $ownerTelegramId;
        $stmt = $db->prepare($sql);
        $stmt->bind_param($types, ...$values);
        $stmt->execute();
        $stmt->close();
    }

    if ($mediaItems !== null) {
        if ($mediaItems === []) {
            throw new InvalidArgumentException('media_items_required');
        }
        $db->query('DELETE FROM auto_post_schedule_media WHERE schedule_id = ' . (int) $scheduleId);
        $mediaTypeForItems = $mediaType ?? (string) ($existing['media_type'] ?? 'photo');
        $sort = 0;
        foreach ($mediaItems as $item) {
            $chatId = (int) ($item['chat_id'] ?? 0);
            $messageId = (int) ($item['message_id'] ?? 0);
            if ($chatId === 0 || $messageId === 0) {
                continue;
            }
            $kind = (string) ($item['media_kind'] ?? $mediaTypeForItems);
            $fileId = $item['telegram_file_id'] ?? null;
            $label = trim((string) ($item['label'] ?? ''));
            if ($label === '') {
                $label = autoPostMediaTypeLabel($mediaTypeForItems) . ' ' . persianMediaOrdinal($sort + 1);
            }
            $stmt = $db->prepare(
                'INSERT INTO auto_post_schedule_media
                 (schedule_id, chat_id, message_id, media_kind, telegram_file_id, sort_order, label)
                 VALUES (?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->bind_param('iiissis', $scheduleId, $chatId, $messageId, $kind, $fileId, $sort, $label);
            $stmt->execute();
            $stmt->close();
            $sort++;
        }
        if ($sort === 0) {
            throw new InvalidArgumentException('media_items_required');
        }
        $db->query('UPDATE auto_post_schedules SET media_cursor = 0 WHERE id = ' . (int) $scheduleId);
    }

    if ($recomputeNext) {
        $stmt = $db->prepare('SELECT schedule_mode, start_time, rotate_hours, last_post_at FROM auto_post_schedules WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $scheduleId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $lastAt = !empty($row['last_post_at']) ? new DateTime((string) $row['last_post_at'], autoPostIranTimezone()) : null;
            $next = computeNextAutoPostTime(
                $lastAt,
                (string) $row['schedule_mode'],
                (string) $row['start_time'],
                (float) $row['rotate_hours']
            );
            $nextStr = $next->format('Y-m-d H:i:s');
            $stmt = $db->prepare('UPDATE auto_post_schedules SET next_post_at = ? WHERE id = ?');
            $stmt->bind_param('si', $nextStr, $scheduleId);
            $stmt->execute();
            $stmt->close();
        }
    }

    return getAutoPostScheduleById($ownerTelegramId, $scheduleId) ?? ['id' => $scheduleId];
}

function computeNextAutoPostTime(
    ?DateTime $lastPostAt,
    string $scheduleMode,
    string $startTime,
    float $rotateHours
): DateTime {
    $tz = autoPostIranTimezone();
    $now = new DateTime('now', $tz);

    if ($scheduleMode === 'daily_rotate' && $lastPostAt instanceof DateTime) {
        $next = clone $lastPostAt;
        $next->setTimezone($tz);
        $minutes = (int) round($rotateHours * 60);
        $next->modify('+' . $minutes . ' minutes');
        if ($next <= $now) {
            $next = clone $now;
            $next->modify('+5 minutes');
        }

        return $next;
    }

    [$h, $m] = array_map('intval', explode(':', $startTime));
    $candidate = new DateTime('now', $tz);
    $candidate->setTime($h, $m, 0);
    if ($candidate <= $now) {
        $candidate->modify('+1 day');
    }

    return $candidate;
}

/**
 * @return array<string, mixed>|null
 */
function getAutoPostScheduleById(int $ownerTelegramId, int $scheduleId): ?array
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT s.*, cg.title AS group_title
         FROM auto_post_schedules s
         LEFT JOIN content_groups cg ON cg.chat_id = s.content_group_chat_id
         WHERE s.id = ? AND s.owner_telegram_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $scheduleId, $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    return formatAutoPostScheduleRow($row);
}

/**
 * @return list<array<string, mixed>>
 */
function getAutoPostSchedulesForSession(int $ownerTelegramId, int $sessionId): array
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT s.*, cg.title AS group_title
         FROM auto_post_schedules s
         LEFT JOIN content_groups cg ON cg.chat_id = s.content_group_chat_id
         WHERE s.session_id = ? AND s.owner_telegram_id = ?
         ORDER BY s.next_post_at ASC, s.id DESC'
    );
    $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = formatAutoPostScheduleRow($row);
    }
    $stmt->close();

    return $rows;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function formatAutoPostScheduleRow(array $row): array
{
    $scheduleId = (int) ($row['id'] ?? 0);
    $media = getAutoPostScheduleMediaItems($scheduleId);

    return [
        'id' => $scheduleId,
        'session_id' => (int) ($row['session_id'] ?? 0),
        'name' => $row['name'] ?? '',
        'content_group_chat_id' => (int) ($row['content_group_chat_id'] ?? 0),
        'group_title' => $row['group_title'] ?? 'گروه',
        'media_type' => $row['media_type'] ?? 'photo',
        'media_type_label' => autoPostMediaTypeLabel((string) ($row['media_type'] ?? 'photo')),
        'schedule_mode' => $row['schedule_mode'] ?? 'daily_fixed',
        'schedule_mode_label' => ($row['schedule_mode'] ?? '') === 'daily_rotate' ? 'چرخشی' : 'روزانه ثابت',
        'start_time' => substr((string) ($row['start_time'] ?? '17:30:00'), 0, 5),
        'rotate_hours' => (float) ($row['rotate_hours'] ?? 1),
        'media_count' => count($media),
        'media_items' => $media,
        'status' => $row['status'] ?? 'active',
        'last_post_at' => $row['last_post_at'] ?? null,
        'next_post_at' => $row['next_post_at'] ?? null,
        'created_at' => $row['created_at'] ?? null,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function getAutoPostScheduleMediaItems(int $scheduleId): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT id, chat_id, message_id, media_kind, telegram_file_id, sort_order, label
         FROM auto_post_schedule_media
         WHERE schedule_id = ?
         ORDER BY sort_order ASC, id ASC'
    );
    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'id' => (int) $row['id'],
            'chat_id' => (int) $row['chat_id'],
            'message_id' => (int) $row['message_id'],
            'media_kind' => $row['media_kind'],
            'telegram_file_id' => $row['telegram_file_id'] ?? null,
            'sort_order' => (int) $row['sort_order'],
            'label' => $row['label'] ?? '',
        ];
    }
    $stmt->close();

    return $items;
}

function deleteAutoPostSchedule(int $ownerTelegramId, int $scheduleId): bool
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    $stmt = $db->prepare('DELETE FROM auto_post_schedules WHERE id = ? AND owner_telegram_id = ?');
    $stmt->bind_param('ii', $scheduleId, $ownerTelegramId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();
    if ($deleted) {
        $db->query('DELETE FROM auto_post_schedule_media WHERE schedule_id = ' . (int) $scheduleId);
    }

    return $deleted;
}

function toggleAutoPostSchedule(int $ownerTelegramId, int $scheduleId, bool $active): bool
{
    ensureAutoPostScheduleTables();
    $status = $active ? 'active' : 'paused';
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE auto_post_schedules SET status = ? WHERE id = ? AND owner_telegram_id = ?'
    );
    $stmt->bind_param('sii', $status, $scheduleId, $ownerTelegramId);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();

    return $ok;
}

/**
 * @return list<array<string, mixed>>
 */
function getSessionBotsByType(int $ownerTelegramId, int $sessionId, string $botType): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT channel_folder_id, bot_folder_id FROM auto_post_sessions WHERE id = ? AND owner_telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        return [];
    }

    $botFolderIds = getBotFolderTreeIds($ownerTelegramId, (int) $session['bot_folder_id']);
    if ($botFolderIds === []) {
        return [];
    }

    $inFolders = implode(',', array_map('intval', $botFolderIds));
    $botTypeEsc = $db->real_escape_string($botType);
    $result = $db->query(
        "SELECT c.id, c.bot_telegram_id, c.bot_username, c.bot_name, c.bot_type, c.bot_token,
                c.health_status, c.webhook_key
         FROM bot_folder_items i
         INNER JOIN child_bots c ON c.id = i.bot_id
         WHERE i.folder_id IN ({$inFolders})
           AND c.owner_telegram_id = {$ownerTelegramId}
           AND c.status = 'active'
           AND c.bot_type = '{$botTypeEsc}'
         ORDER BY c.id ASC"
    );

    $bots = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $health = childBotHealthPayload($row);
            if ($health['is_banned']) {
                continue;
            }
            $bots[] = $row;
        }
    }

    return $bots;
}

/**
 * @param list<array<string, mixed>> $bots
 * @return array<string, mixed>|null
 */
function pickBotFromPool(array $bots, int &$cursor): ?array
{
    if ($bots === []) {
        return null;
    }
    $index = $cursor % count($bots);
    $cursor++;

    return $bots[$index];
}

/**
 * @return list<array<string, mixed>>
 */
function getSessionChannelsForPost(int $ownerTelegramId, int $sessionId): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT channel_folder_id FROM auto_post_sessions WHERE id = ? AND owner_telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $sessionId, $ownerTelegramId);
    $stmt->execute();
    $session = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$session) {
        return [];
    }

    $folderIds = getChannelFolderTreeIds((int) $session['channel_folder_id']);
    if ($folderIds === []) {
        return [];
    }

    $inFolders = implode(',', array_map('intval', $folderIds));
    $result = $db->query(
        "SELECT c.chat_id, c.title, c.username
         FROM channel_folder_items i
         INNER JOIN bot_channels c ON c.chat_id = i.chat_id
         WHERE i.folder_id IN ({$inFolders}) AND c.is_active = 1
         ORDER BY c.title ASC"
    );

    $channels = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $channels[] = $row;
        }
    }

    return $channels;
}

function manageBotToken(): string
{
    global $bot_token;

    return (string) ($bot_token ?? '');
}

/**
 * Chat used briefly to obtain uploader-scoped file_id (message deleted right after).
 * Set $auto_post_stash_chat_id in config.php to a private channel/group without owner notifications.
 */
function resolveAutoPostStashChatId(int $ownerTelegramId): int
{
    global $auto_post_stash_chat_id;
    if (!empty($auto_post_stash_chat_id)) {
        return (int) $auto_post_stash_chat_id;
    }

    return $ownerTelegramId;
}

function markAutoPostScheduleRetry(int $scheduleId, string $error, int $delayMinutes = 15): void
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    $next = new DateTime('now', autoPostIranTimezone());
    $next->modify('+' . max(5, $delayMinutes) . ' minutes');
    $nextStr = $next->format('Y-m-d H:i:s');
    $stmt = $db->prepare(
        'UPDATE auto_post_schedules SET next_post_at = ?, updated_at = NOW() WHERE id = ?'
    );
    $stmt->bind_param('si', $nextStr, $scheduleId);
    $stmt->execute();
    $stmt->close();
    error_log('auto_post schedule #' . $scheduleId . ' retry in ' . $delayMinutes . 'm: ' . $error);
}

/**
 * @return array{link_code:string,file_type:string,file_id:string}|null
 */
function getCachedUploaderMedia(int $uploaderBotId, int $sourceChatId, int $sourceMessageId): ?array
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT link_code, file_type, file_id FROM auto_post_uploader_media_cache
         WHERE uploader_bot_id = ? AND source_chat_id = ? AND source_message_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('iii', $uploaderBotId, $sourceChatId, $sourceMessageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    return [
        'link_code' => (string) $row['link_code'],
        'file_type' => (string) $row['file_type'],
        'file_id' => (string) $row['file_id'],
    ];
}

function saveCachedUploaderMedia(
    int $uploaderBotId,
    int $sourceChatId,
    int $sourceMessageId,
    string $linkCode,
    string $fileType,
    string $fileId,
    ?string $caption
): void {
    ensureAutoPostScheduleTables();
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO auto_post_uploader_media_cache
         (uploader_bot_id, source_chat_id, source_message_id, link_code, file_type, file_id, caption)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           link_code = VALUES(link_code),
           file_type = VALUES(file_type),
           file_id = VALUES(file_id),
           caption = VALUES(caption)'
    );
    $caption = $caption ?? '';
    $stmt->bind_param(
        'iiissss',
        $uploaderBotId,
        $sourceChatId,
        $sourceMessageId,
        $linkCode,
        $fileType,
        $fileId,
        $caption
    );
    $stmt->execute();
    $stmt->close();
}

function deleteTelegramMessageQuiet(string $token, int $chatId, int $messageId): void
{
    if ($chatId === 0 || $messageId <= 0) {
        return;
    }
    tgRequestWithToken($token, 'deleteMessage', [
        'chat_id' => $chatId,
        'message_id' => $messageId,
    ]);
}

/**
 * @return array{path:string,filename:string,mime:string}|null
 */
function downloadTelegramFileWithToken(string $botToken, string $fileId): ?array
{
    $resp = tgRequestWithToken($botToken, 'getFile', ['file_id' => $fileId]);
    if (empty($resp['ok']) || empty($resp['result']['file_path'])) {
        return null;
    }

    $remotePath = (string) $resp['result']['file_path'];
    $fileSize = (int) ($resp['result']['file_size'] ?? 0);
    if ($fileSize > 48 * 1024 * 1024) {
        return null;
    }

    $url = 'https://api.telegram.org/file/bot' . $botToken . '/' . $remotePath;
    $tmp = tempnam(sys_get_temp_dir(), 'ap_media_');
    if ($tmp === false) {
        return null;
    }

    $fp = fopen($tmp, 'wb');
    if (!$fp) {
        @unlink($tmp);

        return null;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if ($httpCode !== 200) {
        @unlink($tmp);

        return null;
    }

    $ext = strtolower(pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'bin');
    $mime = match ($ext) {
        'jpg', 'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        'mp4' => 'video/mp4',
        'mov' => 'video/quicktime',
        'ogg' => 'audio/ogg',
        default => 'application/octet-stream',
    };

    return [
        'path' => $tmp,
        'filename' => basename($remotePath) ?: ('media.' . $ext),
        'mime' => $mime,
    ];
}

/**
 * Upload local file with uploader bot, extract scoped file_id, delete temp chat message.
 *
 * @return array{file_type:string,file_id:string,caption?:string,mime_type?:string}|null
 */
function uploadLocalFileForUploaderBot(
    string $uploaderToken,
    int $stashChatId,
    string $localPath,
    string $fileType,
    ?string $caption,
    string $mime,
    string $filename
): ?array {
    $file = tgMakeUploadFile($localPath, $mime, $filename);
    $params = [
        'chat_id' => $stashChatId,
        'disable_notification' => true,
    ];
    if ($caption !== null && trim($caption) !== '') {
        $params['caption'] = trim($caption);
    }

    $method = match ($fileType) {
        'photo' => 'sendPhoto',
        'video' => 'sendVideo',
        'voice' => 'sendVoice',
        default => 'sendDocument',
    };
    $field = match ($fileType) {
        'photo' => 'photo',
        'video' => 'video',
        'voice' => 'voice',
        default => 'document',
    };
    $params[$field] = $file;

    $resp = tgRequestWithTokenMultipart($uploaderToken, $method, $params);
    if (empty($resp['ok']) || empty($resp['result']) || !is_array($resp['result'])) {
        return null;
    }

    $payload = extractFilePayloadFromMessage($resp['result']);
    deleteTelegramMessageQuiet(
        $uploaderToken,
        $stashChatId,
        (int) ($resp['result']['message_id'] ?? 0)
    );

    return $payload;
}

/**
 * Copy media from content group into uploader scope without forwarding to owner DM.
 *
 * @return array{file_type:string,file_id:string,caption?:string,mime_type?:string}|null
 */
function copyGroupMediaForUploaderBot(
    string $manageToken,
    string $uploaderToken,
    int $uploaderTelegramId,
    int $groupChatId,
    int $messageId,
    int $stashChatId
): ?array {
    if ($stashChatId === 0) {
        return null;
    }

    if ($uploaderTelegramId > 0) {
        tgRequestWithToken($manageToken, 'addChatMember', [
            'chat_id' => $stashChatId,
            'user_id' => $uploaderTelegramId,
        ]);
    }

    $manageCopy = tgRequestWithToken($manageToken, 'copyMessage', [
        'chat_id' => $stashChatId,
        'from_chat_id' => $groupChatId,
        'message_id' => $messageId,
        'disable_notification' => true,
    ]);

    if (empty($manageCopy['ok']) || empty($manageCopy['result']) || !is_array($manageCopy['result'])) {
        if ($uploaderTelegramId <= 0) {
            return null;
        }

        tgRequestWithToken($manageToken, 'addChatMember', [
            'chat_id' => $groupChatId,
            'user_id' => $uploaderTelegramId,
        ]);

        $directCopy = tgRequestWithToken($uploaderToken, 'copyMessage', [
            'chat_id' => $stashChatId,
            'from_chat_id' => $groupChatId,
            'message_id' => $messageId,
            'disable_notification' => true,
        ]);

        if (empty($directCopy['ok']) || empty($directCopy['result']) || !is_array($directCopy['result'])) {
            return null;
        }

        $payload = extractFilePayloadFromMessage($directCopy['result']);
        deleteTelegramMessageQuiet(
            $uploaderToken,
            $stashChatId,
            (int) ($directCopy['result']['message_id'] ?? 0)
        );

        return $payload;
    }

    $bridgeMessageId = (int) ($manageCopy['result']['message_id'] ?? 0);
    if ($bridgeMessageId <= 0) {
        return null;
    }

    $caption = trim((string) ($manageCopy['result']['caption'] ?? ''));

    $uploaderCopy = tgRequestWithToken($uploaderToken, 'copyMessage', [
        'chat_id' => $stashChatId,
        'from_chat_id' => $stashChatId,
        'message_id' => $bridgeMessageId,
        'disable_notification' => true,
    ]);

    deleteTelegramMessageQuiet($manageToken, $stashChatId, $bridgeMessageId);

    if (empty($uploaderCopy['ok']) || empty($uploaderCopy['result']) || !is_array($uploaderCopy['result'])) {
        return null;
    }

    $payload = extractFilePayloadFromMessage($uploaderCopy['result']);
    if ($payload !== null && $caption !== '' && empty($payload['caption'])) {
        $payload['caption'] = $caption;
    }

    deleteTelegramMessageQuiet(
        $uploaderToken,
        $stashChatId,
        (int) ($uploaderCopy['result']['message_id'] ?? 0)
    );

    return $payload;
}

/**
 * Uploader bots do not need to be members of the content group.
 * Manage bot file_id is transferred via copy or download — never forwardMessage to owner.
 *
 * @return array{file_type:string,file_id:string,caption?:string,mime_type?:string}|null
 */
function extractFilePayloadFromMessage(array $msg): ?array
{
    if (!empty($msg['photo']) && is_array($msg['photo'])) {
        $photo = end($msg['photo']);

        return [
            'file_type' => 'photo',
            'file_id' => (string) ($photo['file_id'] ?? ''),
            'caption' => $msg['caption'] ?? null,
        ];
    }
    if (!empty($msg['video']) && is_array($msg['video'])) {
        return [
            'file_type' => 'video',
            'file_id' => (string) ($msg['video']['file_id'] ?? ''),
            'caption' => $msg['caption'] ?? null,
        ];
    }
    if (!empty($msg['voice']) && is_array($msg['voice'])) {
        return [
            'file_type' => 'voice',
            'file_id' => (string) ($msg['voice']['file_id'] ?? ''),
            'caption' => $msg['caption'] ?? null,
        ];
    }
    if (!empty($msg['document']) && is_array($msg['document'])) {
        return [
            'file_type' => 'document',
            'file_id' => (string) ($msg['document']['file_id'] ?? ''),
            'caption' => $msg['caption'] ?? null,
            'mime_type' => $msg['document']['mime_type'] ?? null,
        ];
    }

    return null;
}

function autoPostMediaStorageDir(): string
{
    $dir = dirname(__DIR__) . '/storage/auto_post_media';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    return $dir;
}

function persistGroupMessageFileMeta(int $groupChatId, int $messageId, string $fileId, ?string $caption): void
{
    $db = getDb();
    $caption = $caption ?? '';
    $stmt = $db->prepare(
        'UPDATE content_group_seen_messages
         SET telegram_file_id = ?, caption = CASE WHEN caption IS NULL OR caption = \'\' THEN ? ELSE caption END
         WHERE chat_id = ? AND message_id = ?'
    );
    $stmt->bind_param('ssii', $fileId, $caption, $groupChatId, $messageId);
    $stmt->execute();
    $stmt->close();
}

/**
 * @return array{file_type:string,file_id:string,caption?:string,mime_type?:string}|null
 */
function resolveManageFileIdForGroupMessage(
    string $manageToken,
    int $groupChatId,
    int $messageId,
    string $mediaType,
    int $bridgeChatId
): ?array {
    $stored = buildFilePayloadFromStoredMessage($groupChatId, $messageId, $mediaType);
    if ($stored !== null && ($stored['file_id'] ?? '') !== '') {
        return $stored;
    }

    // One-time bridge: forward silently, read file_id from API response, delete immediately.
    $resp = tgRequestWithToken($manageToken, 'forwardMessage', [
        'chat_id' => $bridgeChatId,
        'from_chat_id' => $groupChatId,
        'message_id' => $messageId,
        'disable_notification' => true,
    ]);
    if (empty($resp['ok']) || empty($resp['result']) || !is_array($resp['result'])) {
        return null;
    }

    $payload = extractFilePayloadFromMessage($resp['result']);
    deleteTelegramMessageQuiet($manageToken, $bridgeChatId, (int) ($resp['result']['message_id'] ?? 0));

    if ($payload !== null && ($payload['file_id'] ?? '') !== '') {
        persistGroupMessageFileMeta(
            $groupChatId,
            $messageId,
            (string) $payload['file_id'],
            $payload['caption'] ?? null
        );
    }

    return $payload;
}

/**
 * @return array{file_type:string,file_id:string,local_cache_path:string,caption?:string,mime_type?:string}|null
 */
function cacheGroupMediaOnDisk(
    string $manageToken,
    int $groupChatId,
    int $messageId,
    string $mediaType,
    int $bridgeChatId
): ?array {
    $payload = resolveManageFileIdForGroupMessage(
        $manageToken,
        $groupChatId,
        $messageId,
        $mediaType,
        $bridgeChatId
    );
    if ($payload === null || ($payload['file_id'] ?? '') === '') {
        return null;
    }

    $download = downloadTelegramFileWithToken($manageToken, (string) $payload['file_id']);
    if ($download === null) {
        return null;
    }

    $ext = pathinfo($download['filename'], PATHINFO_EXTENSION) ?: 'bin';
    $target = autoPostMediaStorageDir()
        . '/' . $groupChatId . '_' . $messageId . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

    if (!@rename($download['path'], $target)) {
        if (!@copy($download['path'], $target)) {
            @unlink($download['path']);

            return null;
        }
        @unlink($download['path']);
    }

    return [
        'file_type' => (string) ($payload['file_type'] ?? 'photo'),
        'file_id' => '',
        'local_cache_path' => $target,
        'caption' => $payload['caption'] ?? null,
        'mime_type' => $download['mime'],
    ];
}

function cacheContentForUploaderBot(
    array $uploaderBot,
    int $ownerTelegramId,
    int $groupChatId,
    int $messageId,
    string $mediaType
): ?array {
    $uploaderBotId = (int) ($uploaderBot['id'] ?? 0);
    if ($uploaderBotId <= 0) {
        return null;
    }

    $existing = getCachedUploaderMedia($uploaderBotId, $groupChatId, $messageId);
    if ($existing !== null) {
        return $existing;
    }

    $manageToken = manageBotToken();
    if ($manageToken === '') {
        return null;
    }

    $stored = buildFilePayloadFromStoredMessage($groupChatId, $messageId, $mediaType);
    $caption = $stored['caption'] ?? null;

    // Never spam owner DM during cron — use owner chat only as one-time silent bridge if file_id missing.
    $filePayload = cacheGroupMediaOnDisk(
        $manageToken,
        $groupChatId,
        $messageId,
        $mediaType,
        $ownerTelegramId
    );

    if ($filePayload === null || empty($filePayload['local_cache_path'])) {
        return null;
    }

    if ($caption !== null && trim($caption) !== '' && empty($filePayload['caption'])) {
        $filePayload['caption'] = trim($caption);
    }

    $linkCode = saveUploaderFile($uploaderBotId, $ownerTelegramId, $filePayload);
    saveCachedUploaderMedia(
        $uploaderBotId,
        $groupChatId,
        $messageId,
        $linkCode,
        (string) $filePayload['file_type'],
        (string) ($filePayload['local_cache_path'] ?? ''),
        $filePayload['caption'] ?? null
    );

    return [
        'link_code' => $linkCode,
        'file_type' => $filePayload['file_type'],
        'file_id' => (string) ($filePayload['local_cache_path'] ?? ''),
    ];
}

/**
 * @return array{file_type:string,file_id:string,caption?:string,mime_type?:string}|null
 */
function buildFilePayloadFromStoredMessage(int $chatId, int $messageId, string $mediaType): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT media_kind, telegram_file_id, caption FROM content_group_seen_messages
         WHERE chat_id = ? AND message_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $chatId, $messageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['telegram_file_id'])) {
        return null;
    }

    $kind = (string) ($row['media_kind'] ?? $mediaType);
    $fileType = match ($kind) {
        'video' => 'video',
        'voice' => 'voice',
        'document', 'file' => 'document',
        default => 'photo',
    };

    return [
        'file_type' => $fileType,
        'file_id' => (string) $row['telegram_file_id'],
        'caption' => $row['caption'] ?? null,
        'mime_type' => null,
    ];
}

/**
 * @return array{file_type:string,file_id:string,caption?:string,mime_type?:string}|null
 */
function extractFilePayloadFromUpdates(?array $updates, int $messageId, int $chatId): ?array
{
    if (empty($updates['ok']) || empty($updates['result'])) {
        return null;
    }

    foreach ($updates['result'] as $update) {
        $msg = $update['message'] ?? null;
        if (!is_array($msg)) {
            continue;
        }
        if ((int) ($msg['message_id'] ?? 0) !== $messageId) {
            continue;
        }
        if ((int) ($msg['chat']['id'] ?? 0) !== $chatId) {
            continue;
        }

        if (!empty($msg['photo'])) {
            $photo = end($msg['photo']);

            return ['file_type' => 'photo', 'file_id' => (string) $photo['file_id']];
        }
        if (!empty($msg['video'])) {
            return ['file_type' => 'video', 'file_id' => (string) $msg['video']['file_id']];
        }
        if (!empty($msg['voice'])) {
            return ['file_type' => 'voice', 'file_id' => (string) $msg['voice']['file_id']];
        }
        if (!empty($msg['document'])) {
            return [
                'file_type' => 'document',
                'file_id' => (string) $msg['document']['file_id'],
                'mime_type' => $msg['document']['mime_type'] ?? null,
            ];
        }
    }

    return null;
}

function registerGuardianLink(
    int $guardianBotId,
    int $uploaderBotId,
    string $linkCode,
    string $mediaType,
    string $contentLabel
): void {
    ensureAutoPostScheduleTables();
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO auto_post_guardian_links (guardian_bot_id, uploader_bot_id, link_code, media_type, content_label)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('iisss', $guardianBotId, $uploaderBotId, $linkCode, $mediaType, $contentLabel);
    $stmt->execute();
    $stmt->close();
}

function resolveMediaCaptionForPost(int $groupChatId, int $messageId, string $mediaType): string
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT caption FROM content_group_seen_messages WHERE chat_id = ? AND message_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $groupChatId, $messageId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $caption = trim((string) ($row['caption'] ?? ''));
    if ($caption !== '') {
        return $caption;
    }

    $typeLabel = autoPostMediaTypeLabel($mediaType);

    return "برای دریافت {$typeLabel} روی دکمه زیر بزنید";
}

function postAutoContentToChannel(
    int $ownerTelegramId,
    int $channelChatId,
    array $guardianBot,
    string $linkCode,
    string $mediaType,
    string $contentLabel,
    int $groupChatId,
    int $sourceMessageId,
    int $channelFolderId,
    int $scheduleId = 0,
    int $runId = 0,
    int $uploaderBotId = 0
): ?array {
    require_once __DIR__ . '/glass_button_tools.php';
    require_once __DIR__ . '/auto_post_channel_posts.php';

    $username = trim((string) ($guardianBot['bot_username'] ?? ''));
    if ($username === '') {
        return null;
    }

    $baseCaption = resolveMediaCaptionForPost($groupChatId, $sourceMessageId, $mediaType);
    $finalCaption = appendHashtagsToCaption($ownerTelegramId, $channelChatId, $baseCaption);
    if (mb_strlen($finalCaption) > 1024) {
        $finalCaption = mb_substr($finalCaption, 0, 1020) . '…';
    }

    $startPayload = 'g_' . $linkCode;
    $url = 'https://t.me/' . $username . '?start=' . rawurlencode($startPayload);
    $delivery = buildGlassButtonDelivery($ownerTelegramId, $channelFolderId, $url, $mediaType, $finalCaption);

    $manageToken = manageBotToken();
    if ($manageToken === '') {
        return null;
    }

    $useBanner = isBannerActiveForAutoPost($ownerTelegramId, $channelFolderId);
    $hasBanner = false;
    $messageId = 0;

    if ($useBanner) {
        $banner = pickRandomBannerPhoto();
        if ($banner !== null && ($banner['telegram_file_id'] ?? '') !== '') {
            $params = [
                'chat_id' => $channelChatId,
                'photo' => (string) $banner['telegram_file_id'],
                'caption' => $delivery['text'],
                'reply_markup' => $delivery['reply_markup'],
            ];
            if (!empty($delivery['parse_mode'])) {
                $params['parse_mode'] = $delivery['parse_mode'];
            }
            if (empty($delivery['use_inline'])) {
                unset($params['reply_markup']);
            }
            $resp = tgRequestWithToken($manageToken, 'sendPhoto', $params);
            $hasBanner = true;
            $messageId = (int) ($resp['result']['message_id'] ?? 0);

            if (!empty($resp['ok']) && $messageId > 0) {
                saveAutoPostChannelPost([
                    'owner_telegram_id' => $ownerTelegramId,
                    'schedule_id' => $scheduleId,
                    'run_id' => $runId,
                    'channel_chat_id' => $channelChatId,
                    'message_id' => $messageId,
                    'guardian_bot_id' => (int) ($guardianBot['id'] ?? 0),
                    'uploader_bot_id' => $uploaderBotId,
                    'link_code' => $linkCode,
                    'media_type' => $mediaType,
                    'content_label' => $contentLabel,
                    'source_chat_id' => $groupChatId,
                    'source_message_id' => $sourceMessageId,
                    'channel_folder_id' => $channelFolderId,
                    'has_banner' => $hasBanner,
                ]);
            }

            return !empty($resp['ok']) ? ['message_id' => $messageId, 'has_banner' => $hasBanner] : null;
        }
    }

    $params = [
        'chat_id' => $channelChatId,
        'text' => $delivery['text'],
    ];
    if (!empty($delivery['parse_mode'])) {
        $params['parse_mode'] = $delivery['parse_mode'];
    }
    if (!empty($delivery['use_inline']) && !empty($delivery['reply_markup'])) {
        $params['reply_markup'] = $delivery['reply_markup'];
    }
    $resp = tgRequestWithToken($manageToken, 'sendMessage', $params);
    $messageId = (int) ($resp['result']['message_id'] ?? 0);

    if (!empty($resp['ok']) && $messageId > 0) {
        saveAutoPostChannelPost([
            'owner_telegram_id' => $ownerTelegramId,
            'schedule_id' => $scheduleId,
            'run_id' => $runId,
            'channel_chat_id' => $channelChatId,
            'message_id' => $messageId,
            'guardian_bot_id' => (int) ($guardianBot['id'] ?? 0),
            'uploader_bot_id' => $uploaderBotId,
            'link_code' => $linkCode,
            'media_type' => $mediaType,
            'content_label' => $contentLabel,
            'source_chat_id' => $groupChatId,
            'source_message_id' => $sourceMessageId,
            'channel_folder_id' => $channelFolderId,
            'has_banner' => $hasBanner,
        ]);
    }

    return !empty($resp['ok']) ? ['message_id' => $messageId, 'has_banner' => $hasBanner] : null;
}

function ensureUploaderCanReceiveViaManageBot(int $groupChatId, array $uploaderBot): void
{
    // Uploader bots are cached via manage-bot forward + uploader copyMessage.
    // They do not need to be added or promoted inside the content group.
    unset($groupChatId, $uploaderBot);
}

function runAutoPostSchedule(int $scheduleId): array
{
    ensureAutoPostScheduleTables();
    ensureChildBotHealthColumns();

    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM auto_post_schedules WHERE id = ? AND status = \'active\' LIMIT 1');
    $stmt->bind_param('i', $scheduleId);
    $stmt->execute();
    $schedule = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$schedule) {
        return ['ok' => false, 'error' => 'schedule_not_found'];
    }

    $ownerId = (int) $schedule['owner_telegram_id'];
    $sessionId = (int) $schedule['session_id'];
    $mediaItems = getAutoPostScheduleMediaItems($scheduleId);
    if ($mediaItems === []) {
        markAutoPostScheduleRetry($scheduleId, 'no_media', 60);

        return ['ok' => false, 'error' => 'no_media'];
    }

    $channels = getSessionChannelsForPost($ownerId, $sessionId);
    if ($channels === []) {
        markAutoPostScheduleRetry($scheduleId, 'no_channels', 60);

        return ['ok' => false, 'error' => 'no_channels'];
    }

    $mediaCursor = (int) ($schedule['media_cursor'] ?? 0);
    $mediaIndex = $mediaCursor % count($mediaItems);
    $mediaItem = $mediaItems[$mediaIndex];

    $guardians = getSessionBotsByType($ownerId, $sessionId, 'guardian');
    $uploaders = getSessionBotsByType($ownerId, $sessionId, 'uploader');
    if ($guardians === [] || $uploaders === []) {
        markAutoPostScheduleRetry($scheduleId, 'bots_missing', 30);

        return ['ok' => false, 'error' => 'bots_missing'];
    }

    $guardianCursor = (int) ($schedule['guardian_cursor'] ?? 0);
    $guardian = pickBotFromPool($guardians, $guardianCursor);
    $uploader = pickBotFromPool($uploaders, $guardianCursor);
    if ($guardian === null || $uploader === null) {
        markAutoPostScheduleRetry($scheduleId, 'bots_pick_failed', 30);

        return ['ok' => false, 'error' => 'bots_pick_failed'];
    }

    ensureUploaderCanReceiveViaManageBot((int) $schedule['content_group_chat_id'], $uploader);

    $mediaType = (string) ($schedule['media_type'] ?? 'photo');
    $cached = cacheContentForUploaderBot(
        $uploader,
        $ownerId,
        (int) $mediaItem['chat_id'],
        (int) $mediaItem['message_id'],
        $mediaType
    );

    if ($cached === null) {
        markAutoPostScheduleRetry($scheduleId, 'cache_failed', 15);

        return ['ok' => false, 'error' => 'cache_failed'];
    }

    $linkCode = (string) $cached['link_code'];
    $contentLabel = (string) ($mediaItem['label'] ?? autoPostMediaTypeLabel($mediaType));

    registerGuardianLink(
        (int) $guardian['id'],
        (int) $uploader['id'],
        $linkCode,
        $mediaType,
        $contentLabel
    );

    $posted = 0;
    $failed = 0;
    $sessionChannelFolderId = 0;
    $sessionStmt = $db->prepare(
        'SELECT channel_folder_id FROM auto_post_sessions WHERE id = ? AND owner_telegram_id = ? LIMIT 1'
    );
    $sessionStmt->bind_param('ii', $sessionId, $ownerId);
    $sessionStmt->execute();
    $sessionRow = $sessionStmt->get_result()->fetch_assoc();
    $sessionStmt->close();
    if ($sessionRow) {
        $sessionChannelFolderId = (int) ($sessionRow['channel_folder_id'] ?? 0);
    }

    foreach ($channels as $channel) {
        $result = postAutoContentToChannel(
            $ownerId,
            (int) $channel['chat_id'],
            $guardian,
            $linkCode,
            $mediaType,
            $contentLabel,
            (int) $mediaItem['chat_id'],
            (int) $mediaItem['message_id'],
            $sessionChannelFolderId,
            $scheduleId,
            0,
            (int) $uploader['id']
        );
        if ($result !== null) {
            $posted++;
        } else {
            $failed++;
        }
        usleep(300000);
    }

    if ($posted === 0) {
        markAutoPostScheduleRetry($scheduleId, 'channel_post_failed', 30);

        return [
            'ok' => false,
            'error' => 'channel_post_failed',
            'failed' => $failed,
            'link_code' => $linkCode,
        ];
    }

    $runStatus = 'done';
    $stmt = $db->prepare(
        'INSERT INTO auto_post_runs
         (schedule_id, media_item_id, guardian_bot_id, uploader_bot_id, link_code,
          channels_posted, channels_failed, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $mediaItemId = (int) $mediaItem['id'];
    $guardianId = (int) $guardian['id'];
    $uploaderId = (int) $uploader['id'];
    $stmt->bind_param(
        'iiiisiis',
        $scheduleId,
        $mediaItemId,
        $guardianId,
        $uploaderId,
        $linkCode,
        $posted,
        $failed,
        $runStatus
    );
    $stmt->execute();
    $stmt->close();

    $now = new DateTime('now', autoPostIranTimezone());
    $next = computeNextAutoPostTime(
        $now,
        (string) $schedule['schedule_mode'],
        (string) $schedule['start_time'],
        (float) $schedule['rotate_hours']
    );

    $nextStr = $next->format('Y-m-d H:i:s');
    $nowStr = $now->format('Y-m-d H:i:s');
    $newMediaCursor = $mediaCursor + 1;

    $stmt = $db->prepare(
        'UPDATE auto_post_schedules
         SET last_post_at = ?, next_post_at = ?, media_cursor = ?, guardian_cursor = ?, updated_at = NOW()
         WHERE id = ?'
    );
    $stmt->bind_param('ssiii', $nowStr, $nextStr, $newMediaCursor, $guardianCursor, $scheduleId);
    $stmt->execute();
    $stmt->close();

    return [
        'ok' => true,
        'posted' => $posted,
        'failed' => $failed,
        'link_code' => $linkCode,
        'next_post_at' => $nextStr,
    ];
}

function processDueAutoPostSchedules(int $limit = 5): array
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    $now = (new DateTime('now', autoPostIranTimezone()))->format('Y-m-d H:i:s');
    $result = $db->query(
        "SELECT id FROM auto_post_schedules
         WHERE status = 'active' AND next_post_at <= '{$now}'
         ORDER BY next_post_at ASC
         LIMIT " . (int) $limit
    );

    $processed = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $processed[] = runAutoPostSchedule((int) $row['id']);
        }
    }

    return $processed;
}

/**
 * @return array<string, mixed>|null
 */
function resolveGuardianLink(int $guardianBotId, string $linkCode): ?array
{
    ensureAutoPostScheduleTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT gl.*, u.bot_username AS uploader_username, u.bot_token AS uploader_token
         FROM auto_post_guardian_links gl
         INNER JOIN child_bots u ON u.id = gl.uploader_bot_id
         WHERE gl.guardian_bot_id = ? AND gl.link_code = ?
         ORDER BY gl.id DESC LIMIT 1'
    );
    $stmt->bind_param('is', $guardianBotId, $linkCode);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}
