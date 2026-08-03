<?php

declare(strict_types=1);

/**
 * One-shot: cache Peyda fonts locally for faster miniapp loads.
 * Open once: /great/mather/tools/cache_miniapp_fonts.php
 */

$root = dirname(__DIR__) . '/miniapp/assets/fonts';
$sources = [
    'PeydaWeb-Regular.woff2' => 'https://cdn.jsdelivr.net/gh/AmirAbbasVafaee/persian-fonts-cdn@main/fonts/peyda/PeydaWeb-Regular.woff2',
    'PeydaWeb-Bold.woff2' => 'https://cdn.jsdelivr.net/gh/AmirAbbasVafaee/persian-fonts-cdn@main/fonts/peyda/PeydaWeb-Bold.woff2',
    'PeydaWeb-Black.woff2' => 'https://cdn.jsdelivr.net/gh/AmirAbbasVafaee/persian-fonts-cdn@main/fonts/peyda/PeydaWeb-Black.woff2',
];

header('Content-Type: text/plain; charset=utf-8');

if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
    echo "mkdir_failed\n";
    exit;
}

$written = [];
$errors = [];
foreach ($sources as $name => $url) {
    $ctx = stream_context_create([
        'http' => ['timeout' => 30, 'header' => "User-Agent: gpro-font-cache/1.0\r\n"],
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        $errors[] = $name . ': download_failed';
        continue;
    }
    if (file_put_contents($root . '/' . $name, $body) === false) {
        $errors[] = $name . ': write_failed';
        continue;
    }
    $written[] = $name;
}

echo 'written=' . count($written) . "\n";
foreach ($written as $f) {
    echo "+ {$f}\n";
}
if ($errors !== []) {
    echo "errors=" . count($errors) . "\n";
    foreach ($errors as $e) {
        echo "! {$e}\n";
    }
}
