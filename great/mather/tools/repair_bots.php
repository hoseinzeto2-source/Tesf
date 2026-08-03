<?php

declare(strict_types=1);

$key = (string) ($_GET['key'] ?? '');
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
require_once dirname(__DIR__) . '/lib/child_bot_repair.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'repair' => repairChildBotData()], JSON_UNESCAPED_UNICODE);
