<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/schema_bootstrap.php';

function ensureBotStatsTables(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS uploader_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_bot_id INT UNSIGNED NOT NULL,
    telegram_id BIGINT NOT NULL,
    username VARCHAR(255) DEFAULT NULL,
    first_name VARCHAR(255) DEFAULT NULL,
    last_name VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_bot_user (child_bot_id, telegram_id),
    KEY idx_child_bot (child_bot_id),
    KEY idx_last_seen (last_seen_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
    ensureUploaderUsersCreatedAtColumn();
}

function ensureUploaderUsersCreatedAtColumn(): void
{
    if (schemaMigrationsComplete()) {
        return;
    }

    $db = getDb();
    $result = $db->query("SHOW COLUMNS FROM uploader_users LIKE 'created_at'");
    if ($result && $result->num_rows === 0) {
        $db->query(
            'ALTER TABLE uploader_users ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER last_name'
        );
    }

    $seen = $db->query("SHOW COLUMNS FROM uploader_users LIKE 'last_seen_at'");
    if ($seen && $seen->num_rows === 0) {
        $db->query(
            'ALTER TABLE uploader_users ADD COLUMN last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at'
        );
    }
}

/**
 * @return array<string, int>
 */
function initBotStatsHourBuckets(): array
{
    $points = [];
    for ($i = 23; $i >= 0; $i--) {
        $key = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
        $points[$key] = 0;
    }

    return $points;
}

/**
 * @param array<string, int> $buckets
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function buildBotStatsHourlyChart(array $buckets): array
{
    $list = [];
    foreach ($buckets as $hour => $count) {
        $list[] = ['hour' => $hour, 'count' => (int) $count];
    }

    return [
        'points' => $list,
        'range' => [
            'from' => $list[0]['hour'] ?? date('Y-m-d H:00:00', strtotime('-23 hours')),
            'to' => $list[count($list) - 1]['hour'] ?? date('Y-m-d H:00:00'),
        ],
    ];
}

/**
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function emptyBotStatsHourlyChart(): array
{
    return buildBotStatsHourlyChart(initBotStatsHourBuckets());
}

/**
 * @param list<int> $botIds
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedBotUserJoinsHourly24(array $botIds): array
{
    ensureBotStatsTables();
    $botIds = array_values(array_unique(array_filter(array_map('intval', $botIds), static fn (int $id): bool => $id > 0)));
    if ($botIds === []) {
        return emptyBotStatsHourlyChart();
    }

    $buckets = initBotStatsHourBuckets();
    try {
        $db = getDb();
        $in = implode(',', $botIds);
        $result = $db->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00') AS hour_bucket, COUNT(*) AS cnt
             FROM uploader_users
             WHERE child_bot_id IN ({$in}) AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY hour_bucket"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $bucket = (string) ($row['hour_bucket'] ?? '');
                if ($bucket !== '' && isset($buckets[$bucket])) {
                    $buckets[$bucket] += (int) ($row['cnt'] ?? 0);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('getAggregatedBotUserJoinsHourly24: ' . $e->getMessage());
    }

    return buildBotStatsHourlyChart($buckets);
}

/**
 * @param list<int> $botIds
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedUniqueBotUserJoinsHourly24(array $botIds): array
{
    ensureBotStatsTables();
    $botIds = array_values(array_unique(array_filter(array_map('intval', $botIds), static fn (int $id): bool => $id > 0)));
    if ($botIds === []) {
        return emptyBotStatsHourlyChart();
    }

    $buckets = initBotStatsHourBuckets();
    try {
        $db = getDb();
        $in = implode(',', $botIds);
        $result = $db->query(
            "SELECT hour_bucket, COUNT(*) AS cnt FROM (
                SELECT DATE_FORMAT(MIN(created_at), '%Y-%m-%d %H:00:00') AS hour_bucket, telegram_id
                FROM uploader_users
                WHERE child_bot_id IN ({$in}) AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                GROUP BY telegram_id
             ) t
             GROUP BY hour_bucket"
        );
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $bucket = (string) ($row['hour_bucket'] ?? '');
                if ($bucket !== '' && isset($buckets[$bucket])) {
                    $buckets[$bucket] += (int) ($row['cnt'] ?? 0);
                }
            }
        }
    } catch (Throwable $e) {
        error_log('getAggregatedUniqueBotUserJoinsHourly24: ' . $e->getMessage());
    }

    return buildBotStatsHourlyChart($buckets);
}

/**
 * @param list<int> $botIds
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedBotUsersTotalHourly24(array $botIds): array
{
    return buildBotCumulativeUsersHourly24($botIds, false);
}

/**
 * @param list<int> $botIds
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function getAggregatedUniqueBotUsersTotalHourly24(array $botIds): array
{
    return buildBotCumulativeUsersHourly24($botIds, true);
}

/**
 * @param list<int> $botIds
 * @return array{points: list<array{hour: string, count: int}>, range: array{from: string, to: string}}
 */
function buildBotCumulativeUsersHourly24(array $botIds, bool $uniqueTelegramIds): array
{
    ensureBotStatsTables();
    $botIds = array_values(array_unique(array_filter(array_map('intval', $botIds), static fn (int $id): bool => $id > 0)));
    $buckets = initBotStatsHourBuckets();
    if ($botIds === []) {
        return buildBotStatsHourlyChart($buckets);
    }

    try {
        $db = getDb();
        $in = implode(',', $botIds);
        $result = $db->query(
            "SELECT telegram_id, created_at
             FROM uploader_users
             WHERE child_bot_id IN ({$in})"
        );
        $events = [];
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $telegramId = (int) ($row['telegram_id'] ?? 0);
                $createdAt = strtotime((string) ($row['created_at'] ?? ''));
                if ($createdAt <= 0) {
                    continue;
                }
                if ($uniqueTelegramIds) {
                    if (!isset($events[$telegramId]) || $createdAt < $events[$telegramId]) {
                        $events[$telegramId] = $createdAt;
                    }
                } else {
                    $events[] = $createdAt;
                }
            }
        }

        $timestamps = $uniqueTelegramIds ? array_values($events) : $events;
        foreach (array_keys($buckets) as $hourKey) {
            $hourEnd = strtotime($hourKey . ' +1 hour');
            $count = 0;
            foreach ($timestamps as $ts) {
                if ($ts < $hourEnd) {
                    $count++;
                }
            }
            $buckets[$hourKey] = $count;
        }
    } catch (Throwable $e) {
        error_log('buildBotCumulativeUsersHourly24: ' . $e->getMessage());
    }

    return buildBotStatsHourlyChart($buckets);
}

function countUploaderUsersForBot(int $childBotId): int
{
    if ($childBotId <= 0) {
        return 0;
    }

    ensureBotStatsTables();
    $db = getDb();
    $stmt = $db->prepare('SELECT COUNT(*) AS cnt FROM uploader_users WHERE child_bot_id = ?');
    $stmt->bind_param('i', $childBotId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['cnt'] ?? 0);
}
