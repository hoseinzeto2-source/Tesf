<?php

/**
 * One-shot GitHub deploy for great/mather.
 * Upload this file to the server, open once with the key, then delete it.
 *
 * Example:
 * /great/mather/tools/pull_github_deploy.php?key=...&branch=cursor/manage-bots-multi-361a
 */

declare(strict_types=1);

$deployKey = hash('sha256', 'gpro-mather-github-deploy-361a');
$provided = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
if ($provided === '' || !hash_equals($deployKey, $provided)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit;
}

$branch = preg_replace('/[^a-zA-Z0-9_\\-\\/]/', '', (string) ($_GET['branch'] ?? 'cursor/manage-bots-multi-361a'));
$repo = 'hoseinzeto2-source/Tesf';
$base = "https://raw.githubusercontent.com/{$repo}/{$branch}/great/mather";
$root = dirname(__DIR__);

$files = [
    'install.php',
    'index.php',
    'manage_bot.php',
    'lib/manage_bots.php',
    'lib/manage_bot_router.php',
    'lib/manage_bot_membership.php',
    'lib/channels.php',
    'lib/child_bots.php',
    'lib/auto_post_schedules.php',
    'lib/zapas_bots.php',
    'lib/glass_button_tools.php',
    'lib/auto_post_channel_posts.php',
    'miniapp/index.php',
    'miniapp/css/miniapp.css',
    'miniapp/js/app.js',
    'miniapp/api/server.php',
    'miniapp/api/manage_bots.php',
    'miniapp/api/glass_button_tools.php',
    'miniapp/api/zapas_bots.php',
];

header('Content-Type: text/plain; charset=utf-8');

$written = [];
$errors = [];

foreach ($files as $rel) {
    $url = $base . '/' . str_replace('%2F', '/', rawurlencode($rel));
    $url = $base . '/' . $rel;
    $ctx = stream_context_create([
        'http' => ['timeout' => 60, 'header' => "User-Agent: gpro-deploy/1.0\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $content = @file_get_contents($url, false, $ctx);
    if ($content === false || $content === '') {
        $errors[] = $rel . ': download_failed';
        continue;
    }

    $local = $root . '/' . $rel;
    $dir = dirname($local);
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        $errors[] = $rel . ': mkdir_failed';
        continue;
    }
    if (file_put_contents($local, $content) === false) {
        $errors[] = $rel . ': write_failed';
        continue;
    }
    $written[] = $rel;
}

echo "written=" . count($written) . "\n";
foreach ($written as $f) {
    echo "+ {$f}\n";
}
if ($errors !== []) {
    echo "errors=" . count($errors) . "\n";
    foreach ($errors as $e) {
        echo "! {$e}\n";
    }
}

if ($errors === [] && is_file($root . '/install.php')) {
    echo "\nRunning install.php...\n";
    ob_start();
    try {
        include $root . '/install.php';
        $installOut = trim((string) ob_get_clean());
        echo $installOut . "\n";
    } catch (Throwable $e) {
        ob_end_clean();
        echo 'install_error: ' . $e->getMessage() . "\n";
    }
}

echo "\nDone. Delete tools/pull_github_deploy.php after success.\n";
