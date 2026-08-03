<?php

require_once __DIR__ . '/channels.php';
require_once __DIR__ . '/channel_folders.php';

const ADS_PROMO_FOLDER_NAME = 'تبلیغات';

function ensureAdCampaignsTable(): void
{
    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS ad_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    channel_chat_id BIGINT NOT NULL,
    channel_input VARCHAR(255) NOT NULL,
    member_order INT UNSIGNED NOT NULL DEFAULT 0,
    folder_splits JSON NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'active',
    created_by BIGINT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_channel (channel_chat_id),
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

function getOrCreateAdsPromoFolder(): array
{
    ensureChannelFolderTables();
    foreach (getChannelFolders() as $folder) {
        if (trim((string) ($folder['name'] ?? '')) === ADS_PROMO_FOLDER_NAME) {
            return $folder;
        }
    }

    return createChannelFolder(ADS_PROMO_FOLDER_NAME, 'bullhorn');
}

function normalizeChannelAddressInput(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }
    if (preg_match('#(?:https?://)?t\.me/([a-zA-Z0-9_]+)#i', $input, $m)) {
        return $m[1];
    }
    if (str_starts_with($input, '@')) {
        return substr($input, 1);
    }

    return $input;
}

function resolveChannelChatIdFromInput(string $input): ?int
{
    ensureBotChannelsTable();
    $norm = normalizeChannelAddressInput($input);
    if ($norm === '') {
        return null;
    }

    $db = getDb();

    if (preg_match('/^-?\d+$/', $norm)) {
        $chatId = (int) $norm;
        $stmt = $db->prepare('SELECT chat_id FROM bot_channels WHERE chat_id = ? AND is_active = 1 LIMIT 1');
        $stmt->bind_param('i', $chatId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ? (int) $row['chat_id'] : null;
    }

    $username = strtolower($norm);
    $stmt = $db->prepare('SELECT chat_id FROM bot_channels WHERE LOWER(username) = ? AND is_active = 1 LIMIT 1');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        return (int) $row['chat_id'];
    }

    $chatRef = '@' . $username;
    $resp = telegramRequest('getChat', ['chat_id' => $chatRef]);
    if (empty($resp['ok']) || empty($resp['result']['id'])) {
        return null;
    }
    $chat = $resp['result'];
    upsertBotChannel($chat, 'administrator');

    return (int) $chat['id'];
}

/**
 * @param list<array{folder_id: int, percent: int}> $splits
 */
function normalizeFolderSplits(array $splits): array
{
    $clean = [];
    foreach ($splits as $row) {
        $folderId = (int) ($row['folder_id'] ?? 0);
        if ($folderId <= 0) {
            continue;
        }
        $percent = isset($row['percent']) && $row['percent'] !== '' && $row['percent'] !== null
            ? (int) $row['percent']
            : null;
        $clean[] = ['folder_id' => $folderId, 'percent' => $percent];
    }

    if ($clean === []) {
        throw new InvalidArgumentException('folders_required');
    }

    $sum = 0;
    $seenFolders = [];
    foreach ($clean as &$row) {
        if ($row['percent'] === null) {
            throw new InvalidArgumentException('percent_required_for_all_folders');
        }
        $p = (int) $row['percent'];
        if ($p < 0 || $p > 100) {
            throw new InvalidArgumentException('invalid_percent');
        }
        $sum += $p;
        $fid = (int) $row['folder_id'];
        if (isset($seenFolders[$fid])) {
            throw new InvalidArgumentException('duplicate_folder');
        }
        $seenFolders[$fid] = true;
        $row['percent'] = $p;
    }
    unset($row);

    if ($sum !== 100) {
        throw new InvalidArgumentException('percent_sum_must_be_100');
    }

    return $clean;
}

function createAdCampaign(int $createdBy, string $channelInput, int $memberOrder, array $folderSplits): array
{
    ensureAdCampaignsTable();

    if ($memberOrder <= 0) {
        throw new InvalidArgumentException('invalid_member_order');
    }

    $chatId = resolveChannelChatIdFromInput($channelInput);
    if ($chatId === null) {
        throw new InvalidArgumentException('channel_not_found');
    }

    $splits = normalizeFolderSplits($folderSplits);

    $promoFolder = getOrCreateAdsPromoFolder();
    assignChannelToFolder($chatId, (int) $promoFolder['id']);

    $json = json_encode($splits, JSON_UNESCAPED_UNICODE);
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO ad_campaigns (channel_chat_id, channel_input, member_order, folder_splits, created_by)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->bind_param('isisi', $chatId, $channelInput, $memberOrder, $json, $createdBy);
    $stmt->execute();
    $id = (int) $stmt->insert_id;
    $stmt->close();

    return [
        'id' => $id,
        'channel_chat_id' => $chatId,
        'channel_input' => $channelInput,
        'member_order' => $memberOrder,
        'folder_splits' => $splits,
        'promo_folder_id' => (int) $promoFolder['id'],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function listAdCampaigns(int $limit = 50): array
{
    ensureAdCampaignsTable();
    $db = getDb();
    $limit = max(1, min(100, $limit));
    $res = $db->query(
        "SELECT c.*, ch.title AS channel_title, ch.username AS channel_username
         FROM ad_campaigns c
         LEFT JOIN bot_channels ch ON ch.chat_id = c.channel_chat_id
         ORDER BY c.id DESC
         LIMIT {$limit}"
    );
    $rows = [];
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $splits = json_decode((string) ($row['folder_splits'] ?? '[]'), true);
            $rows[] = [
                'id' => (int) $row['id'],
                'channel_chat_id' => (int) $row['channel_chat_id'],
                'channel_input' => $row['channel_input'],
                'channel_title' => $row['channel_title'],
                'channel_username' => $row['channel_username'],
                'member_order' => (int) $row['member_order'],
                'folder_splits' => is_array($splits) ? $splits : [],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            ];
        }
    }

    return $rows;
}

function folderNameMap(): array
{
    $map = [];
    foreach (getChannelFolders() as $folder) {
        $map[(int) $folder['id']] = (string) $folder['name'];
    }

    return $map;
}
