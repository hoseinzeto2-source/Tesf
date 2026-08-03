<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/uploader_versions.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];

if (!isAdminTelegramId($telegramId)) {
    jsonResponse(['ok' => false, 'error' => 'forbidden'], 403);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonResponse([
        'ok' => true,
        'versions' => listUploaderVersions(),
        'default_version' => getDefaultUploaderVersion(),
    ]);
}

if ($method !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'method_not_allowed'], 405);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? 'create');

try {
    if ($action === 'create') {
        $version = createUploaderVersion(
            (string) ($body['name'] ?? ''),
            (string) ($body['project_path'] ?? ''),
            (string) ($body['webhook_script'] ?? 'index.php'),
            !empty($body['is_default']),
            isset($body['slug']) ? (string) $body['slug'] : null
        );
        jsonResponse(['ok' => true, 'version' => $version]);
    }

    if ($action === 'update') {
        $id = (int) ($body['version_id'] ?? 0);
        if ($id <= 0) {
            jsonResponse(['ok' => false, 'error' => 'version_id_required'], 400);
        }
        $fields = [];
        foreach (['name', 'project_path', 'webhook_script', 'is_default', 'is_active'] as $key) {
            if (array_key_exists($key, $body)) {
                $fields[$key] = $body[$key];
            }
        }
        $version = updateUploaderVersion($id, $fields);
        jsonResponse(['ok' => true, 'version' => $version]);
    }

    jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('uploader_versions api: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
