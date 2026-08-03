<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/lib/channels.php';
require_once dirname(__DIR__, 2) . '/lib/bot_health.php';
require_once dirname(__DIR__, 2) . '/lib/uploader_versions.php';
require_once dirname(__DIR__, 2) . '/lib/global_bot_owners.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

$checkedAt = date('Y-m-d H:i:s');
$dbOk = false;
$totalUsers = 0;
$lastSeen = null;
$dbError = null;

try {
    $db = getDb();
    $dbOk = true;
    $totalUsers = getUserCount();
    $stmt = $db->prepare('SELECT last_seen_at FROM users WHERE telegram_id = ? LIMIT 1');
    $stmt->bind_param('i', $telegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $lastSeen = $row['last_seen_at'] ?? null;
} catch (Throwable $e) {
    $dbError = 'connection_failed';
}

$botOk = false;
$webhookOk = false;
$webhookUrl = '';
$pendingUpdates = 0;

global $bot_token, $bot_username;
$ch = curl_init('https://api.telegram.org/bot' . $bot_token . '/getMe');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$meResponse = curl_exec($ch);
curl_close($ch);
$me = json_decode((string) $meResponse, true);
$botOk = !empty($me['ok']);

$ch = curl_init('https://api.telegram.org/bot' . $bot_token . '/getWebhookInfo');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$whResponse = curl_exec($ch);
curl_close($ch);
$wh = json_decode((string) $whResponse, true);
if (!empty($wh['ok'])) {
    $webhookUrl = $wh['result']['url'] ?? '';
    $pendingUpdates = (int) ($wh['result']['pending_update_count'] ?? 0);
    $webhookOk = $webhookUrl !== '' && empty($wh['result']['last_error_message']);
}

$allOk = $dbOk && $botOk && $webhookOk;

$isAdmin = isAdminTelegramId($telegramId);
$defaultUploaderVersion = getDefaultUploaderVersion();
$defaultGuardianVersion = getDefaultVersionForBotRole('guardian');
$uploaderVersions = $isAdmin ? listUploaderVersions() : [];
$globalBotOwners = $isAdmin ? listGlobalBotOwners() : [];
$manageBotsOverview = ['bots' => [], 'stats' => []];
if ($isAdmin) {
    $manageBotsLib = dirname(__DIR__, 2) . '/lib/manage_bots.php';
    if (is_file($manageBotsLib)) {
        require_once $manageBotsLib;
        try {
            $manageBotsOverview = getManageBotsOverview();
        } catch (Throwable $e) {
            error_log('manage_bots overview: ' . $e->getMessage());
        }
    }
}

$channelProblems = [];
$botProblems = [];
$healthProbed = 0;
$botHealthProbed = 0;
if ($dbOk) {
    try {
        $deepHealth = !empty($_GET['deep_health']) || !empty($_GET['refresh_health']);
        if ($deepHealth) {
            runServerChannelHealthPass(3);
            $healthProbed = 3;
            $botHealthProbed = runServerChildBotHealthPass(0);
        }
        $channelProblems = getBotChannelProblems();
        $botProblems = getChildBotProblems();
    } catch (Throwable $e) {
        error_log('server channel health: ' . $e->getMessage());
        $channelProblems = [];
        $botProblems = [];
    }
}

jsonResponse([
    'ok' => true,
    'server' => [
        'status' => $allOk ? 'ok' : 'degraded',
        'bot' => '@' . ($bot_username ?? 'gpro100_bot'),
        'bot_ok' => $botOk,
        'database' => $dbOk ? 'متصل' : 'قطع',
        'db_ok' => $dbOk,
        'webhook' => $webhookOk ? 'فعال' : 'غیرفعال',
        'webhook_ok' => $webhookOk,
        'webhook_url' => $webhookUrl,
        'pending_updates' => $pendingUpdates,
        'php_version' => PHP_VERSION,
        'total_users' => $totalUsers,
        'last_seen_at' => $lastSeen,
        'checked_at' => $checkedAt,
        'error' => $dbError,
        'channel_problems' => $channelProblems,
        'channel_problems_count' => count($channelProblems),
        'bot_problems' => $botProblems,
        'bot_problems_count' => count($botProblems),
        'health_probed' => $healthProbed,
        'bot_health_probed' => $botHealthProbed,
        'is_admin' => $isAdmin,
        'default_uploader_version' => $defaultUploaderVersion,
        'default_guardian_version' => $defaultGuardianVersion,
        'uploader_versions' => $uploaderVersions,
        'global_bot_owners' => $globalBotOwners,
        'manage_bots' => $manageBotsOverview['bots'] ?? [],
        'manage_bots_stats' => $manageBotsOverview['stats'] ?? [],
        'manage_bots_available' => $isAdmin && is_file(dirname(__DIR__, 2) . '/lib/manage_bots.php'),
    ],
]);
