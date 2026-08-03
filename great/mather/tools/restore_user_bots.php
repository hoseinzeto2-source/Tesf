<?php

declare(strict_types=1);

$key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
$expected = hash('sha256', 'gpro-mather-github-deploy-361a');
if ($key === '' || !hash_equals($expected, $key)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/lib/child_bots.php';
require_once dirname(__DIR__) . '/lib/bot_folders.php';
require_once dirname(__DIR__) . '/lib/channel_folders.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true);
if (!is_array($body)) {
    $body = $_POST;
}

$ownerId = (int) ($body['owner_id'] ?? 0);
$uploaderToken = trim((string) ($body['uploader_token'] ?? ''));
$guardianToken = trim((string) ($body['guardian_token'] ?? ''));
$botFolderId = (int) ($body['bot_folder_id'] ?? 0);
$channelFolderId = (int) ($body['channel_folder_id'] ?? 0);

if ($ownerId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'owner_id_required'], JSON_UNESCAPED_UNICODE);
    exit;
}

function normName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');

    return preg_replace('/\s+/u', '', $name) ?? $name;
}

function findImmoralChannelFolderId(): int
{
    ensureChannelFolderTables();
    foreach (getChannelFolders() as $folder) {
        $name = normName((string) ($folder['name'] ?? ''));
        if (str_contains($name, 'غیر') && str_contains($name, 'اخلاق')) {
            return (int) $folder['id'];
        }
    }

    return 0;
}

function findTestBotFolderId(int $ownerId): int
{
    ensureBotFolderTables();
    $folders = getBotFolders($ownerId);
    $rootId = 0;
    foreach ($folders as $folder) {
        if (!empty($folder['parent_id'])) {
            continue;
        }
        $name = normName((string) ($folder['name'] ?? ''));
        if (str_contains($name, 'غیر') && str_contains($name, 'اخلاق')) {
            $rootId = (int) $folder['id'];
            break;
        }
    }
    if ($rootId <= 0) {
        return 0;
    }
    foreach ($folders as $folder) {
        if ((int) ($folder['parent_id'] ?? 0) !== $rootId) {
            continue;
        }
        if (normName((string) ($folder['name'] ?? '')) === 'تست') {
            return (int) $folder['id'];
        }
    }

    return 0;
}

try {
    ensureChildBotTables();
    ensureBotFolderTables();
    ensureChannelFolderTables();

    if ($channelFolderId <= 0) {
        $channelFolderId = findImmoralChannelFolderId();
    }
    if ($channelFolderId <= 0) {
        throw new InvalidArgumentException('channel_folder_not_found');
    }

    if ($botFolderId <= 0) {
        $botFolderId = findTestBotFolderId($ownerId);
    }
    if ($botFolderId <= 0) {
        $root = createBotFolder($ownerId, 'غیر اخلاقی', 'folder');
        $rootId = (int) ($root['id'] ?? 0);
        $child = createBotFolder($ownerId, 'تست', 'folder', $rootId > 0 ? $rootId : null);
        $botFolderId = (int) ($child['id'] ?? 0);
    }

    $results = [];
    $specs = [];
    if ($uploaderToken !== '') {
        $specs[] = ['type' => 'uploader', 'token' => $uploaderToken];
    }
    if ($guardianToken !== '') {
        $specs[] = ['type' => 'guardian', 'token' => $guardianToken];
    }
    if ($specs === []) {
        throw new InvalidArgumentException('token_required');
    }

    foreach ($specs as $spec) {
        $type = (string) $spec['type'];
        $token = (string) $spec['token'];
        $bot = $type === 'guardian'
            ? createGuardianBot($ownerId, $token, '', $channelFolderId)
            : createUploaderBot($ownerId, $token, '', $channelFolderId);
        $botId = (int) ($bot['id'] ?? 0);
        if ($botId > 0 && $botFolderId > 0) {
            assignBotToFolder($botId, $botFolderId, $ownerId);
        }
        $results[] = [
            'type' => $type,
            'id' => $botId,
            'username' => $bot['bot_username'] ?? null,
            'folder_id' => $botFolderId,
        ];
    }

    $bots = attachFolderIdsToBots(getChildBotsByOwner($ownerId), $ownerId);

    echo json_encode([
        'ok' => true,
        'owner_id' => $ownerId,
        'channel_folder_id' => $channelFolderId,
        'bot_folder_id' => $botFolderId,
        'restored' => $results,
        'verify' => array_map(static function ($bot) {
            return [
                'id' => (int) ($bot['id'] ?? 0),
                'username' => $bot['bot_username'] ?? null,
                'folder_id' => $bot['folder_id'] ?? null,
            ];
        }, $bots),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
