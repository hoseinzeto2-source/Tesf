<?php

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/lib/auto_post_schedules.php';
require_once dirname(__DIR__) . '/lib/telegram_webapp.php';

$user = requireTelegramUser();
$telegramId = (int) $user['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $sessionId = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
    $chatId = isset($_GET['content_group_chat_id']) ? (int) $_GET['content_group_chat_id'] : 0;
    $mediaType = (string) ($_GET['media_type'] ?? 'photo');
    $offset = max(0, (int) ($_GET['offset'] ?? 0));
    $limit = min(80, max(10, (int) ($_GET['limit'] ?? 40)));

    if ($chatId !== 0 && isset($_GET['media_type'])) {
        jsonResponse([
            'ok' => true,
            'items' => listContentGroupMediaItems($chatId, $mediaType, $limit, $offset),
            'total' => countContentGroupMediaItems($chatId, $mediaType),
            'offset' => $offset,
            'limit' => $limit,
        ]);
    }

    if ($sessionId > 0) {
        jsonResponse([
            'ok' => true,
            'schedules' => getAutoPostSchedulesForSession($telegramId, $sessionId),
        ]);
    }

    $scheduleId = isset($_GET['schedule_id']) ? (int) $_GET['schedule_id'] : 0;
    if ($scheduleId > 0) {
        $schedule = getAutoPostScheduleById($telegramId, $scheduleId);
        if (!$schedule) {
            jsonResponse(['ok' => false, 'error' => 'schedule_not_found'], 404);
        }
        jsonResponse(['ok' => true, 'schedule' => $schedule]);
    }

    jsonResponse(['ok' => false, 'error' => 'invalid_request'], 400);
}

$body = getJsonRequestBody();
$action = (string) ($body['action'] ?? '');

try {
    switch ($action) {
        case 'create_schedule':
            $sessionId = (int) ($body['session_id'] ?? 0);
            $chatId = (int) ($body['content_group_chat_id'] ?? 0);
            $mediaType = (string) ($body['media_type'] ?? 'photo');
            $mediaItems = is_array($body['media_items'] ?? null) ? $body['media_items'] : [];
            $scheduleMode = (string) ($body['schedule_mode'] ?? 'daily_fixed');
            $startTime = (string) ($body['start_time'] ?? '17:30');
            $rotateHours = (float) ($body['rotate_hours'] ?? 1);
            $name = (string) ($body['name'] ?? '');

            $schedule = createAutoPostSchedule(
                $telegramId,
                $sessionId,
                $chatId,
                $mediaType,
                $mediaItems,
                $scheduleMode,
                $startTime,
                $rotateHours,
                $name
            );
            jsonResponse(['ok' => true, 'schedule' => $schedule]);
            break;

        case 'delete_schedule':
            $scheduleId = (int) ($body['schedule_id'] ?? 0);
            if ($scheduleId <= 0 || !deleteAutoPostSchedule($telegramId, $scheduleId)) {
                jsonResponse(['ok' => false, 'error' => 'delete_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'toggle_schedule':
            $scheduleId = (int) ($body['schedule_id'] ?? 0);
            $active = !empty($body['active']);
            if ($scheduleId <= 0 || !toggleAutoPostSchedule($telegramId, $scheduleId, $active)) {
                jsonResponse(['ok' => false, 'error' => 'toggle_failed'], 400);
            }
            jsonResponse(['ok' => true]);
            break;

        case 'run_now':
            $scheduleId = (int) ($body['schedule_id'] ?? 0);
            if ($scheduleId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_schedule'], 400);
            }
            $schedule = getAutoPostScheduleById($telegramId, $scheduleId);
            if (!$schedule) {
                jsonResponse(['ok' => false, 'error' => 'schedule_not_found'], 404);
            }
            $result = runAutoPostSchedule($scheduleId, true);
            if (empty($result['ok'])) {
                jsonResponse([
                    'ok' => false,
                    'error' => (string) ($result['error'] ?? 'send_failed'),
                    'result' => $result,
                ], 400);
            }
            jsonResponse(['ok' => true, 'result' => $result]);
            break;

        case 'update_schedule':
            $scheduleId = (int) ($body['schedule_id'] ?? 0);
            if ($scheduleId <= 0) {
                jsonResponse(['ok' => false, 'error' => 'invalid_schedule'], 400);
            }
            $mediaItems = array_key_exists('media_items', $body) && is_array($body['media_items'])
                ? $body['media_items']
                : null;
            $schedule = updateAutoPostSchedule(
                $telegramId,
                $scheduleId,
                array_key_exists('name', $body) ? (string) $body['name'] : null,
                isset($body['content_group_chat_id']) ? (int) $body['content_group_chat_id'] : null,
                array_key_exists('media_type', $body) ? (string) $body['media_type'] : null,
                $mediaItems,
                array_key_exists('schedule_mode', $body) ? (string) $body['schedule_mode'] : null,
                array_key_exists('start_time', $body) ? (string) $body['start_time'] : null,
                array_key_exists('rotate_hours', $body) ? (float) $body['rotate_hours'] : null
            );
            jsonResponse(['ok' => true, 'schedule' => $schedule]);
            break;

        default:
            jsonResponse(['ok' => false, 'error' => 'unknown_action'], 400);
    }
} catch (InvalidArgumentException $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('auto_post_schedules failed: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'server_error'], 500);
}
