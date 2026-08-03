<?php

require_once dirname(__DIR__) . '/db.php';

function ensurePanelUser(int $ecosystemOwnerId, int $telegramId): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT IGNORE INTO panel_users (ecosystem_owner_id, telegram_id) VALUES (?, ?)'
    );
    $stmt->bind_param('ii', $ecosystemOwnerId, $telegramId);
    $stmt->execute();
    $stmt->close();
}

function getPanelUser(int $ecosystemOwnerId, int $telegramId): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM panel_users WHERE ecosystem_owner_id = ? AND telegram_id = ? LIMIT 1'
    );
    $stmt->bind_param('ii', $ecosystemOwnerId, $telegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function updatePanelUserPhone(int $ecosystemOwnerId, int $telegramId, string $phone): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE panel_users SET phone_number = ?, is_verified = "verified" WHERE ecosystem_owner_id = ? AND telegram_id = ?'
    );
    $stmt->bind_param('sii', $phone, $ecosystemOwnerId, $telegramId);
    $stmt->execute();
    $stmt->close();
}

function addPanelBalance(int $ecosystemOwnerId, int $telegramId, float $amount): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE panel_users SET balance = balance + ? WHERE ecosystem_owner_id = ? AND telegram_id = ?'
    );
    $stmt->bind_param('dii', $amount, $ecosystemOwnerId, $telegramId);
    $stmt->execute();
    $stmt->close();
}

function deductPanelBalance(int $ecosystemOwnerId, int $telegramId, float $amount): bool
{
    $db = getDb();
    $stmt = $db->prepare(
        'UPDATE panel_users SET balance = balance - ? WHERE ecosystem_owner_id = ? AND telegram_id = ? AND balance >= ?'
    );
    $stmt->bind_param('diid', $amount, $ecosystemOwnerId, $telegramId, $amount);
    $stmt->execute();
    $ok = $stmt->affected_rows > 0;
    $stmt->close();
    return $ok;
}

function generateUniqueId(string $prefix, string $table, string $column): string
{
    $db = getDb();
    do {
        $id = $prefix . strtoupper(substr(bin2hex(random_bytes(5)), 0, 8));
        $stmt = $db->prepare("SELECT id FROM {$table} WHERE {$column} = ? LIMIT 1");
        $stmt->bind_param('s', $id);
        $stmt->execute();
        $exists = (bool) $stmt->get_result()->fetch_assoc();
        $stmt->close();
    } while ($exists);
    return $id;
}

function getPricePerK(int $ecosystemOwnerId, string $memberType, int $memberCount): ?float
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT price_per_k FROM panel_prices
         WHERE ecosystem_owner_id IN (0, ?) AND member_type = ?
         AND min_members <= ? AND (max_members IS NULL OR max_members >= ?)
         ORDER BY ecosystem_owner_id DESC, min_members DESC LIMIT 1'
    );
    $stmt->bind_param('isii', $ecosystemOwnerId, $memberType, $memberCount, $memberCount);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (float) $row['price_per_k'] : null;
}

function createCampaign(array $data): string
{
    $db = getDb();
    $transactionId = $data['transaction_id'];
    $stmt = $db->prepare(
        'INSERT INTO campaigns (transaction_id, ecosystem_owner_id, panel_bot_id, buyer_telegram_id, channel_id, channel_link, member_count, price, member_type, is_bot_admin, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, "yes", "active")'
    );
    $stmt->bind_param(
        'siiiisids',
        $transactionId,
        $data['ecosystem_owner_id'],
        $data['panel_bot_id'],
        $data['buyer_telegram_id'],
        $data['channel_id'],
        $data['channel_link'],
        $data['member_count'],
        $data['price'],
        $data['member_type']
    );
    $stmt->execute();
    $stmt->close();
    return $transactionId;
}

function getCampaignByTransactionId(string $transactionId): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM campaigns WHERE transaction_id = ? LIMIT 1');
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function getActiveCampaigns(int $ecosystemOwnerId, int $offset = 0, int $limit = 5): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM campaigns WHERE ecosystem_owner_id = ? AND status = "active" AND is_bot_admin = "yes"
         ORDER BY id DESC LIMIT ?, ?'
    );
    $stmt->bind_param('iii', $ecosystemOwnerId, $offset, $limit);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getBuyerCampaigns(int $ecosystemOwnerId, int $buyerId): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM campaigns WHERE ecosystem_owner_id = ? AND buyer_telegram_id = ? ORDER BY id DESC LIMIT 50'
    );
    $stmt->bind_param('ii', $ecosystemOwnerId, $buyerId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getDistributorCampaigns(int $ecosystemOwnerId, int $distributorId): array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT c.*, cl.link, cl.join_count FROM campaign_links cl
         JOIN campaigns c ON c.transaction_id = cl.transaction_id
         WHERE cl.ecosystem_owner_id = ? AND cl.distributor_telegram_id = ? AND cl.status = "active"
         ORDER BY cl.id DESC LIMIT 50'
    );
    $stmt->bind_param('ii', $ecosystemOwnerId, $distributorId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function getCampaignLink(int $ecosystemOwnerId, int $distributorId, string $transactionId): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM campaign_links WHERE ecosystem_owner_id = ? AND distributor_telegram_id = ? AND transaction_id = ? AND status = "active" LIMIT 1'
    );
    $stmt->bind_param('iis', $ecosystemOwnerId, $distributorId, $transactionId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function saveCampaignLink(int $ecosystemOwnerId, int $distributorId, string $transactionId, string $link): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO campaign_links (ecosystem_owner_id, link, distributor_telegram_id, transaction_id) VALUES (?, ?, ?, ?)'
    );
    $stmt->bind_param('isis', $ecosystemOwnerId, $link, $distributorId, $transactionId);
    $stmt->execute();
    $stmt->close();
}

function getCampaignByInviteLink(string $link): ?array
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT c.*, cl.distributor_telegram_id FROM campaign_links cl
         JOIN campaigns c ON c.transaction_id = cl.transaction_id
         WHERE cl.link = ? AND cl.status = "active" LIMIT 1'
    );
    $stmt->bind_param('s', $link);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function recordMemberJoin(int $ecosystemOwnerId, int $userId, string $link, int $channelId, string $transactionId, bool $isMember): bool
{
    $db = getDb();

    $stmt = $db->prepare(
        'SELECT status FROM campaign_members WHERE user_telegram_id = ? AND link = ? LIMIT 1'
    );
    $stmt->bind_param('is', $userId, $link);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $status = $isMember ? 'true' : 'false';
    $stmt = $db->prepare(
        'INSERT INTO campaign_members (ecosystem_owner_id, user_telegram_id, link, channel_id, transaction_id, status)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status)'
    );
    $stmt->bind_param('iisiss', $ecosystemOwnerId, $userId, $link, $channelId, $transactionId, $status);
    $stmt->execute();
    $stmt->close();

    if (!$isMember || ($existing && $existing['status'] === 'true')) {
        return $isMember;
    }

    $stmt = $db->prepare('UPDATE campaign_links SET join_count = join_count + 1 WHERE link = ? AND status = "active"');
    $stmt->bind_param('s', $link);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare(
        'UPDATE campaigns SET deposited_member_count = deposited_member_count + 1 WHERE transaction_id = ? AND status = "active"'
    );
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $stmt->close();

    $campaign = getCampaignByTransactionId($transactionId);
    if ($campaign && (int) $campaign['deposited_member_count'] >= (int) $campaign['member_count']) {
        deactivateCampaign($transactionId);
    }

    return true;
}

function deactivateCampaign(string $transactionId): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE campaigns SET status = "inactive" WHERE transaction_id = ?');
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $stmt->close();

    $stmt = $db->prepare('UPDATE campaign_links SET status = "inactive" WHERE transaction_id = ?');
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $stmt->close();
}

function isInviteLinkValid(string $link): bool
{
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT c.status FROM campaign_links cl JOIN campaigns c ON c.transaction_id = cl.transaction_id
         WHERE cl.link = ? AND cl.status = "active" LIMIT 1'
    );
    $stmt->bind_param('s', $link);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && $row['status'] === 'active';
}

function createPanelTransaction(int $ecosystemOwnerId, int $userId, float $amount, string $transactionId, string $authority): void
{
    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO panel_transactions (ecosystem_owner_id, user_telegram_id, amount, transaction_id, authority, status)
         VALUES (?, ?, ?, ?, ?, "pending")'
    );
    $stmt->bind_param('iidss', $ecosystemOwnerId, $userId, $amount, $transactionId, $authority);
    $stmt->execute();
    $stmt->close();
}

function getPanelTransactionByAuthority(string $authority): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM panel_transactions WHERE authority = ? AND status = "pending" LIMIT 1');
    $stmt->bind_param('s', $authority);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function completePanelTransaction(string $transactionId, string $refId): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM panel_transactions WHERE transaction_id = ? LIMIT 1');
    $stmt->bind_param('s', $transactionId);
    $stmt->execute();
    $txn = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$txn) {
        return null;
    }

    $stmt = $db->prepare('UPDATE panel_transactions SET status = "successful", ref_id = ? WHERE transaction_id = ?');
    $stmt->bind_param('ss', $refId, $transactionId);
    $stmt->execute();
    $stmt->close();

    addPanelBalance((int) $txn['ecosystem_owner_id'], (int) $txn['user_telegram_id'], (float) $txn['amount']);
    return $txn;
}

function cancelPanelTransaction(string $transactionId, string $status): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE panel_transactions SET status = ? WHERE transaction_id = ?');
    $stmt->bind_param('ss', $status, $transactionId);
    $stmt->execute();
    $stmt->close();
}

function ensureGosUser(int $ecosystemOwnerId, int $telegramId, ?string $username = null): void
{
    $db = getDb();
    $level = 'no';
    $stmt = $db->prepare(
        'INSERT INTO gos_users (ecosystem_owner_id, telegram_id, username, level) VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE username = COALESCE(VALUES(username), username)'
    );
    $stmt->bind_param('iiss', $ecosystemOwnerId, $telegramId, $username, $level);
    $stmt->execute();
    $stmt->close();
}

function getGosUser(int $ecosystemOwnerId, int $telegramId): ?array
{
    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM gos_users WHERE ecosystem_owner_id = ? AND telegram_id = ? LIMIT 1');
    $stmt->bind_param('ii', $ecosystemOwnerId, $telegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function approveGosUser(int $ecosystemOwnerId, int $telegramId): void
{
    $db = getDb();
    $stmt = $db->prepare('UPDATE gos_users SET level = "yes" WHERE ecosystem_owner_id = ? AND telegram_id = ?');
    $stmt->bind_param('ii', $ecosystemOwnerId, $telegramId);
    $stmt->execute();
    $stmt->close();
}
