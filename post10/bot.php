
<?php
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

date_default_timezone_set('Africa/Cairo');

// لاگ برای دیباگ - غیرفعال شده برای کاهش حجم فایل
function log_message($message) {
    // Logging disabled to reduce file size
    // file_put_contents(__DIR__ . '/bot.log', date('Y-m-d H:i:s') . " - $message\n", FILE_APPEND);
}

// تابع برای تبدیل زمان تهران به قاهره
function tehran_to_cairo($datetime) {
    $tehran = new DateTimeZone('Asia/Tehran');
    $cairo = new DateTimeZone('Africa/Cairo');
    $date = new DateTime($datetime, $tehran);
    $date->setTimezone($cairo);
    return $date->format('Y-m-d H:i:s');
}

// تابع برای تبدیل زمان قاهره به تهران
function cairo_to_tehran($datetime) {
    $cairo = new DateTimeZone('Africa/Cairo');
    $tehran = new DateTimeZone('Asia/Tehran');
    $date = new DateTime($datetime, $cairo);
    $date->setTimezone($tehran);
    return $date->format('Y-m-d H:i:s');
}

// بارگذاری تنظیمات از .env یا env
function load_env_file($path) {
    if (!is_readable($path)) {
        return false;
    }
    $env = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return false;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || $line[0] === ';') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        $parts = explode('=', $line, 2);
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        if ((strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') ||
            ($value[0] === "'" && substr($value, -1) === "'")))) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }
    return $env;
}

$env_path_dot = __DIR__ . '/.env';
$env_path_nodot = __DIR__ . '/env';
$env = false;
if (file_exists($env_path_dot)) {
    $env = load_env_file($env_path_dot);
    if ($env === false) {
        log_message("Failed to parse .env file at $env_path_dot");
    }
} elseif (file_exists($env_path_nodot)) {
    $env = load_env_file($env_path_nodot);
    if ($env === false) {
        log_message("Failed to parse env file at $env_path_nodot");
    }
} else {
    log_message("No env file found (.env or env) in script directory");
}

if ($env === false) {
    die("Error: Configuration file not found or invalid. Create '.env' or 'env' next to bot.php.");
}

$bot_token = trim($env['BOT_TOKEN'] ?? '');
$bot_username = trim($env['BOT_USERNAME'] ?? '');
$admins = isset($env['ADMINS']) ? array_filter(array_map('trim', explode(',', $env['ADMINS']))) : [];
$bot_id = trim((string)($env['BOT_ID'] ?? ''));
$webhook_url = trim($env['WEBHOOK_URL'] ?? '');
$db_host = $env['DB_HOST'] ?? 'localhost';
$db_database = $env['DB_DATABASE'] ?? '';
$db_username = $env['DB_USERNAME'] ?? '';
$db_password = $env['DB_PASSWORD'] ?? '';

// اگر BOT_ID در .env نباشد، از توکن استخراج می‌شود (قسمت قبل از :)
if ($bot_id === '' && preg_match('/^(\d+):/', $bot_token, $tokenMatch)) {
    $bot_id = $tokenMatch[1];
}

// بررسی مقادیر ضروری
if (empty($bot_token) || empty($bot_id) || empty($db_database) || empty($db_username)) {
    log_message("Missing required .env variables");
    die("Error: Missing required .env variables (BOT_TOKEN, BOT_ID, DB_DATABASE, DB_USERNAME)");
}

// اتصال به دیتابیس
function create_pdo($host, $database, $username, $password) {
    return new PDO(
        "mysql:host=$host;dbname=$database;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
}

function reconnect_pdo_if_needed(PDOException $e) {
    global $pdo, $db_host, $db_database, $db_username, $db_password;
    $mysqlErrNo = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
    if ($mysqlErrNo == 2006 || stripos($e->getMessage(), 'gone away') !== false) {
        $pdo = create_pdo($db_host, $db_database, $db_username, $db_password);
        return true;
    }
    return false;
}

try {
    $pdo = create_pdo($db_host, $db_database, $db_username, $db_password);
    log_message("Database connected successfully");
} catch (PDOException $e) {
    log_message("Database connection failed: " . $e->getMessage());
    die("Database connection failed: " . $e->getMessage());
}

// Ensure required tables exist BEFORE any scheduling call
function init_tables_if_needed(PDO $pdo) {
    $tables = [
        'users' => "CREATE TABLE IF NOT EXISTS users (
            telegram_id BIGINT(20) NOT NULL,
            step VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (telegram_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'options' => "CREATE TABLE IF NOT EXISTS options (
            id INT(11) NOT NULL,
            channels TEXT NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'temp_posts' => "CREATE TABLE IF NOT EXISTS temp_posts (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            telegram_id BIGINT(20) NOT NULL,
            message_id INT(11) NOT NULL,
            type ENUM('forward','copy') NOT NULL,
            status ENUM('active','hidden') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'posts' => "CREATE TABLE IF NOT EXISTS posts (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            from_chat_id BIGINT(20) NOT NULL,
            message_id INT(11) NOT NULL,
            time DATETIME NOT NULL,
            type ENUM('forward','copy') NOT NULL,
            status ENUM('active','hidden') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_time_status (time,status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'daily_posts' => "CREATE TABLE IF NOT EXISTS daily_posts (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            from_chat_id BIGINT(20) NOT NULL,
            message_id INT(11) NOT NULL,
            time TIME NOT NULL,
            type ENUM('forward','copy') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'daily_post_logs' => "CREATE TABLE IF NOT EXISTS daily_post_logs (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            daily_post_id BIGINT(20) NOT NULL,
            sent_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_daily_post_id_sent_date (daily_post_id,sent_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'daily_post_reservations' => "CREATE TABLE IF NOT EXISTS daily_post_reservations (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            daily_post_id BIGINT(20) NOT NULL,
            reserved_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reservation (daily_post_id, reserved_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'daily_post_sends' => "CREATE TABLE IF NOT EXISTS daily_post_sends (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            daily_post_id BIGINT(20) NOT NULL,
            channel_id VARCHAR(128) NOT NULL,
            tg_message_id BIGINT(20) DEFAULT NULL,
            status ENUM('sent','failed','deleted') NOT NULL DEFAULT 'sent',
            attempt_count INT NOT NULL DEFAULT 1,
            last_error TEXT DEFAULT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $table_name => $create_sql) {
        try {
            $pdo->exec($create_sql);
            log_message("[init] Table $table_name checked/created successfully");
        } catch (Exception $e) {
            log_message("[init] Error with table $table_name: " . $e->getMessage());
        }
    }

    // Add generated column and unique index for minute-level uniqueness (idempotent attempts)
    try {
        $pdo->exec("ALTER TABLE daily_post_sends ADD COLUMN sent_minute DATETIME GENERATED ALWAYS AS (FROM_UNIXTIME(UNIX_TIMESTAMP(sent_at) - MOD(UNIX_TIMESTAMP(sent_at), 60))) STORED");
        log_message("[init] Added sent_minute generated column to daily_post_sends");
    } catch (Exception $e) {
        // likely already exists
    }
    try {
        $pdo->exec("ALTER TABLE daily_post_sends ADD UNIQUE KEY uniq_post_channel_minute (daily_post_id, channel_id, sent_minute)");
        log_message("[init] Added uniq_post_channel_minute to daily_post_sends");
    } catch (Exception $e) {
        // likely already exists
    }
    // Ensure daily_post_logs has unique per day
    try {
        $pdo->exec("ALTER TABLE daily_post_logs ADD UNIQUE KEY uniq_daily_date (daily_post_id, sent_date)");
        log_message("[init] Added uniq_daily_date to daily_post_logs");
    } catch (Exception $e) {
        // likely already exists
    }

    // Ensure options row exists
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM options WHERE id = 1");
        $has_options_row = (int)$stmt->fetchColumn() > 0;
        if (!$has_options_row) {
            $pdo->exec("INSERT INTO options (id, channels) VALUES (1, '[]')");
            log_message("[init] Created options row with id=1");
        }
    } catch (Exception $e) {
        log_message("[init] Error checking/creating options row: " . $e->getMessage());
    }

    // پاکسازی temp_posts از ردیف‌های مشکل‌ساز
    try {
        $pdo->exec("DELETE FROM temp_posts WHERE id = 0 OR id IS NULL");
        log_message("[init] Cleaned up temp_posts table");
    } catch (Exception $e) {
        log_message("[init] Error cleaning temp_posts: " . $e->getMessage());
    }
}

// ایجاد یا بازیابی کاربر بدون خطای duplicate key در درخواست‌های همزمان
function ensure_user(PDO $pdo, $telegram_id) {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
    $stmt->execute([$telegram_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
        return $user;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO users (telegram_id, step) VALUES (?, NULL)");
        $stmt->execute([$telegram_id]);
        log_message("New user added: $telegram_id");
        return ['telegram_id' => $telegram_id, 'step' => null];
    } catch (PDOException $e) {
        $mysqlErrNo = is_array($e->errorInfo ?? null) ? ($e->errorInfo[1] ?? null) : null;
        if ($mysqlErrNo != 1062) {
            throw $e;
        }
        $stmt = $pdo->prepare("SELECT * FROM users WHERE telegram_id = ?");
        $stmt->execute([$telegram_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: ['telegram_id' => $telegram_id, 'step' => null];
    }
}

// Call initialization early
init_tables_if_needed($pdo);

// تابع برای ارسال درخواست به API تلگرام
function telegram_api($method, $params) {
    global $bot_token;
    $url = "https://api.telegram.org/bot$bot_token/$method";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $result = json_decode($response, true);
    if ($http_code !== 200 || !isset($result['ok']) || !$result['ok']) {
        $error_description = $result['description'] ?? 'Unknown error';
        $error_code = $result['error_code'] ?? 'Unknown';
        log_message("Telegram API error ($method): HTTP $http_code - Error $error_code: $error_description" . ($error ? " - cURL error: $error" : ''));
        return false;
    }
    log_message("Telegram API success ($method): HTTP $http_code - " . json_encode($result));
    return $result;
}

// تابع برای ارسال پیام
function send_message($chat_id, $text, $reply_markup = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($reply_markup) {
        $params['reply_markup'] = json_encode($reply_markup);
    }
    $response = telegram_api('sendMessage', $params);
    return $response !== false;
}

// Helper: delete duplicate messages for same post+channel on the same date
function cleanup_duplicate_sends(PDO $pdo, $dailyPostId, $channelId) {
    global $bot_id;
    // Check admin rights once before attempting deletions
    $canDelete = false;
    try {
        $cm = telegram_api('getChatMember', [
            'chat_id' => $channelId,
            'user_id' => $bot_id
        ]);
        if ($cm !== false && isset($cm['result']['status'])) {
            $status = $cm['result']['status'];
            if (in_array($status, ['administrator', 'creator'])) {
                $canDelete = true;
            }
        }
    } catch (Exception $e) {
        $canDelete = false;
    }
    try {
        $q = $pdo->prepare("\n            SELECT id, tg_message_id, status, sent_at\n            FROM daily_post_sends\n            WHERE daily_post_id = ? AND channel_id = ? AND DATE(sent_at) = DATE(NOW())\n            ORDER BY sent_at ASC\n        ");
        $q->execute([$dailyPostId, $channelId]);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        log_message("cleanup_duplicate_sends: query failed: " . $e->getMessage());
        return;
    }
    if (!$rows || count($rows) <= 1) {
        return;
    }

    $keepIndex = null;
    foreach ($rows as $idx => $r) {
        if ($r['status'] === 'sent') { $keepIndex = $idx; break; }
    }
    if ($keepIndex === null) { $keepIndex = 0; }

    $keepRow = $rows[$keepIndex];

    foreach ($rows as $idx => $r) {
        if ($idx === $keepIndex) continue;
        $tgMsgId = $r['tg_message_id'];
        if (!empty($tgMsgId)) {
            if (!$canDelete) {
                log_message("cleanup_duplicate_sends: bot is not admin in {$channelId}; skip deleting msg {$tgMsgId}.");
                // Still mark as deleted to avoid future duplicate cleanups looping endlessly
                $upd = $pdo->prepare("UPDATE daily_post_sends SET status = 'deleted', last_error = ? WHERE id = ?");
                $upd->execute(['not_admin_skip_delete', $r['id']]);
                continue;
            }
            try {
                $delRes = telegram_api('deleteMessage', [
                    'chat_id' => $channelId,
                    'message_id' => $tgMsgId
                ]);
                if ($delRes === false || empty($delRes['ok'])) {
                    log_message("cleanup_duplicate_sends: failed to delete telegram message {$tgMsgId} in {$channelId}");
                    $upd = $pdo->prepare("UPDATE daily_post_sends SET status = 'deleted', last_error = ? WHERE id = ?");
                    $upd->execute(['delete_failed', $r['id']]);
                } else {
                    log_message("cleanup_duplicate_sends: deleted duplicate message {$tgMsgId} in {$channelId} (db id {$r['id']}).");
                    $upd = $pdo->prepare("UPDATE daily_post_sends SET status = 'deleted' WHERE id = ?");
                    $upd->execute([$r['id']]);
                }
            } catch (Exception $e) {
                log_message("cleanup_duplicate_sends: exception deleting msg {$tgMsgId} in {$channelId}: " . $e->getMessage());
                $upd = $pdo->prepare("UPDATE daily_post_sends SET status = 'deleted', last_error = ? WHERE id = ?");
                $upd->execute(['delete_exception', $r['id']]);
            }
        } else {
            $upd = $pdo->prepare("UPDATE daily_post_sends SET status = 'deleted' WHERE id = ?");
            $upd->execute([$r['id']]);
        }
    }

    try {
        $updKeep = $pdo->prepare("UPDATE daily_post_sends SET status = 'sent' WHERE id = ?");
        $updKeep->execute([$keepRow['id']]);
    } catch (Exception $e) {
        log_message("cleanup_duplicate_sends: couldn't update keep-row: " . $e->getMessage());
    }
}

// تابع برای اعتبارسنجی زمان (YYYY-MM-DD HH:MM)
function validate_datetime($datetime) {
    return preg_match('/^\d{4}-\d{2}-\d{2} (?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/', $datetime);
}

// تابع برای اعتبارسنجی زمان روزانه (HH:MM)
function validate_time($time) {
    return preg_match('/^(?:[01]?[0-9]|2[0-3]):[0-5][0-9]$/', $time);
}

// تابع برای تنظیم step کاربر
function set_step($telegram_id, $step = null) {
    global $pdo;
    $stmt = $pdo->prepare("UPDATE users SET step = ? WHERE telegram_id = ?");
    $stmt->execute([$step, $telegram_id]);
    log_message("Set step for user $telegram_id to: " . ($step ?? 'null'));
}

// تابع برای ارسال پست‌های آماده و روزانه
function send_scheduled_posts() {
    global $pdo;
    // Hardening: ensure tables exist (handles early calls on webhook paths)
    init_tables_if_needed($pdo);

    // Reconnect if MySQL connection timed out between webhook calls
    try {
        $pdo->query("SELECT 1");
    } catch (PDOException $e) {
        if (!reconnect_pdo_if_needed($e)) {
            log_message("send_scheduled_posts: DB ping failed: " . $e->getMessage());
            return;
        }
        init_tables_if_needed($pdo);
    }

    $current_time = date('Y-m-d H:i:00'); // زمان قاهره
    $past_time = date('Y-m-d H:i:00', strtotime('-2 minutes')); // بازه دو دقیقه‌ای (قاهره)
    $current_time_only = date('H:i:00'); // زمان فعلی (فقط ساعت و دقیقه، قاهره)
    $current_date = date('Y-m-d'); // تاریخ فعلی (قاهره)

    // Optional: Global lock to prevent overlapping runs
    $lockName = 'send_scheduled_posts_lock';
    try {
        $gotLock = (bool)$pdo->query("SELECT GET_LOCK('$lockName', 5)")->fetchColumn();
    } catch (Exception $e) {
        $gotLock = false;
    }
    if (!$gotLock) {
        log_message("Another process is running send_scheduled_posts(), exiting.");
        return;
    }

    // ارسال پست‌های عادی - بررسی پست‌هایی که زمانشان رسیده یا گذشته
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE time <= ? AND status IN ('active', 'hidden') ORDER BY time ASC");
    $stmt->execute([$current_time]);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    log_message("Checking posts for time: $current_time (Cairo), found " . count($posts) . " posts");

    // ارسال پست‌های روزانه - بررسی پست‌هایی که زمانشان رسیده بر اساس زمان تهران
    // زمان اکنون تهران → تبدیل به قاهره برای تطبیق با زمان ذخیره شده
    $nowTehran = new DateTime('now', new DateTimeZone('Asia/Tehran'));
    $nowTehranStr = $nowTehran->format('Y-m-d H:i:00');
    $nowCairoFromTehran = tehran_to_cairo($nowTehranStr); // رشته زمان در قاهره
    $current_hour_minute = substr($nowCairoFromTehran, 11, 5); // HH:MM
    $stmt = $pdo->prepare("SELECT * FROM daily_posts WHERE time LIKE ?");
    $stmt->execute([$current_hour_minute . '%']);
    $daily_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    log_message("Checking daily posts for time: $current_hour_minute (Cairo), found " . count($daily_posts) . " daily posts");

    $stmt = $pdo->query("SELECT channels FROM options WHERE id = 1");
    $channels = json_decode($stmt->fetchColumn() ?: '[]', true);
    log_message("Channels: " . json_encode($channels));

    if (empty($channels)) {
        log_message("No channels found, skipping post sending");
        try { $pdo->query("SELECT RELEASE_LOCK('$lockName')"); } catch (Exception $e) { }
        return;
    }

    // پردازش پست‌های عادی
    foreach ($posts as $post) {
        $success = true;
        $sent_count = 0;
        $total_channels = count($channels);
        
        log_message("Processing post {$post['id']} (type: {$post['type']}, status: {$post['status']}, time: {$post['time']})");
        
        foreach ($channels as $channel) {
            $response = $post['type'] == 'forward' ?
                telegram_api('forwardMessage', [
                    'chat_id' => $channel,
                    'from_chat_id' => $post['from_chat_id'],
                    'message_id' => $post['message_id']
                ]) :
                telegram_api('copyMessage', [
                    'chat_id' => $channel,
                    'from_chat_id' => $post['from_chat_id'],
                    'message_id' => $post['message_id']
                ]);
            if ($response === false) {
                $success = false;
                log_message("Failed to send post {$post['id']} to channel $channel");
            } else {
                $sent_count++;
                log_message("Post {$post['id']} sent to channel $channel");
            }
        }
        
        // حذف پست فقط اگر به همه کانال‌ها ارسال شده باشد
        if ($success && $sent_count == $total_channels) {
            $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
            $stmt->execute([$post['id']]);
            log_message("Post {$post['id']} sent to all channels and deleted successfully");
        } else {
            log_message("Post {$post['id']} not deleted - sent to $sent_count/$total_channels channels");
        }
    }

    // پردازش پست‌های روزانه (ارسال امن و اتمیک)
    foreach ($daily_posts as $post) {
        $postId = $post['id'];

        // 1) Reserve this post for today (atomic). If duplicate, proceed.
        try {
            $resStmt = $pdo->prepare("INSERT INTO daily_post_reservations (daily_post_id, reserved_date) VALUES (?, ?)");
            $resStmt->execute([$postId, $current_date]);
            log_message("Reserved daily_post {$postId} for date {$current_date}.");
        } catch (PDOException $e) {
            $errInfo = $e->errorInfo ?? null;
            $sqlState = is_array($errInfo) ? ($errInfo[0] ?? null) : $e->getCode();
            $mysqlErrNo = is_array($errInfo) ? ($errInfo[1] ?? null) : null;
            if ($sqlState === '23000' || $mysqlErrNo == 1062) {
                log_message("daily_post {$postId} reservation already exists; proceeding to per-channel sends.");
            } else {
                log_message("Error reserving daily_post {$postId}: " . $e->getMessage());
                continue;
            }
        }

        log_message("Processing daily post {$postId} (type: {$post['type']}, time: {$post['time']})");

        foreach ($channels as $channel) {
            // Skip if already sent today
            $checkStmt = $pdo->prepare("\n                SELECT id, status, tg_message_id, sent_at\n                FROM daily_post_sends\n                WHERE daily_post_id = ? AND channel_id = ? AND DATE(sent_at) = ?\n                LIMIT 1\n            ");
            $checkStmt->execute([$postId, $channel, $current_date]);
            $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
            if ($existing && $existing['status'] === 'sent') {
                log_message("Post {$postId} already sent to {$channel} today (db id {$existing['id']}), skipping.");
                continue;
            }

            // Attempt to send
            $apiResult = null;
            try {
                if (($post['type'] ?? '') === 'forward') {
                    $apiResult = telegram_api('forwardMessage', [
                        'chat_id' => $channel,
                        'from_chat_id' => $post['from_chat_id'],
                        'message_id' => $post['message_id']
                    ]);
                } else {
                    $apiResult = telegram_api('copyMessage', [
                        'chat_id' => $channel,
                        'from_chat_id' => $post['from_chat_id'],
                        'message_id' => $post['message_id']
                    ]);
                }
            } catch (Exception $e) {
                $apiResult = false;
            }

            if ($apiResult === false || empty($apiResult['ok'])) {
                $lastError = is_array($apiResult) && !empty($apiResult['description']) ? $apiResult['description'] : 'telegram_api_failed';
                log_message("Failed to send post {$postId} to {$channel}: " . (string)$lastError);
                // Upsert failure
                try {
                    $insertFail = $pdo->prepare("\n                        INSERT INTO daily_post_sends (daily_post_id, channel_id, status, attempt_count, last_error)\n                        VALUES (?, ?, 'failed', 1, ?)\n                        ON DUPLICATE KEY UPDATE\n                          attempt_count = attempt_count + 1,\n                          last_error = VALUES(last_error),\n                          sent_at = CURRENT_TIMESTAMP,\n                          status = 'failed'\n                    ");
                    $insertFail->execute([$postId, $channel, $lastError]);
                } catch (Exception $ie) {
                    log_message("DB error recording failed send for post {$postId} channel {$channel}: " . $ie->getMessage());
                }
                continue;
            }

            // Success: capture Telegram message_id
            $tgMsgId = null;
            if (isset($apiResult['result']['message_id'])) {
                $tgMsgId = $apiResult['result']['message_id'];
            } elseif (isset($apiResult['result']) && is_array($apiResult['result'])) {
                foreach ($apiResult['result'] as $k => $v) {
                    if (is_array($v) && isset($v['message_id'])) {
                        $tgMsgId = $v['message_id'];
                        break;
                    }
                }
            }

            try {
                $ins = $pdo->prepare("\n                    INSERT INTO daily_post_sends (daily_post_id, channel_id, tg_message_id, status, attempt_count)\n                    VALUES (?, ?, ?, 'sent', 1)\n                    ON DUPLICATE KEY UPDATE\n                      tg_message_id = VALUES(tg_message_id),\n                      status = 'sent',\n                      last_error = NULL,\n                      attempt_count = attempt_count + 1,\n                      sent_at = CURRENT_TIMESTAMP\n                ");
                $ins->execute([$postId, $channel, $tgMsgId]);
                log_message("Post {$postId} sent to {$channel}, tg_msg_id={$tgMsgId}.");
            } catch (Exception $ie) {
                log_message("DB error recording success for post {$postId} channel {$channel}: " . $ie->getMessage());
            }

            // Cleanup duplicates if any
            cleanup_duplicate_sends($pdo, $postId, $channel);
        }
    }

    // release lock
    try { $pdo->query("SELECT RELEASE_LOCK('$lockName')"); } catch (Exception $e) { }
}

// دریافت آپدیت از تلگرام
$content = file_get_contents("php://input");
$update = json_decode($content, true);

// بررسی وجود آپدیت و پیام
if (!isset($update['message']) && !isset($update['callback_query']) && !isset($update['chat_member'])) {
    log_message("Invalid update received");
    send_scheduled_posts();
    http_response_code(200);
    exit();
}

$from_id = isset($update['message']) ? ($update['message']['from']['id'] ?? 0) : (isset($update['callback_query']) ? ($update['callback_query']['from']['id'] ?? 0) : ($update['chat_member']['from']['id'] ?? 0));
$text = $update['message']['text'] ?? '';
$message_id = $update['message']['message_id'] ?? 0;

// بررسی کاربر در دیتابیس
try {
    $user = ensure_user($pdo, $from_id);
} catch (PDOException $e) {
    if (!reconnect_pdo_if_needed($e)) {
        throw $e;
    }
    $user = ensure_user($pdo, $from_id);
}

// بررسی دسترسی ادمین
if (!in_array((string)$from_id, $admins)) {
    send_message($from_id, "❌ دسترسی غیر مجاز");
    log_message("Unauthorized access attempt by: $from_id");
    send_scheduled_posts();
    http_response_code(200);
    exit();
}

$step = $user['step'] ?? null;
log_message("User $from_id step: " . ($step ?? 'null') . ", text: $text");

// بررسی پست‌های آماده ارسال در هر فراخوانی وب‌هوک
send_scheduled_posts();

// منوی اصلی
if ($text == '/start' || $text == 'بازگشت ⬅️') {
    set_step($from_id);
    send_message($from_id, "👋 به ربات مدیریت ارسال پیام‌ها خوش آمدید.", [
        'keyboard' => [
            [['text' => '🔗 مدیریت کانال‌ها و گروه‌ها']],
            [['text' => '🎛 مدیریت پست‌ها']],
            [['text' => '📅 ارسال پست روزانه']],
            [['text' => '👥 پیام همگانی']],
            [['text' => '⚙️ تنظیمات ربات']]
        ],
        'resize_keyboard' => true
    ]);
    http_response_code(200);
    exit();
}

// تنظیمات ربات
if ($text == '⚙️ تنظیمات ربات') {
    set_step($from_id);
    send_message($from_id, "👇 یکی از گزینه‌های زیر را انتخاب کنید:", [
        'keyboard' => [
            [['text' => '✏️ تغییر نام ربات']],
            [['text' => '📝 تغییر توضیحات ربات']],
            [['text' => 'ℹ️ تغییر بیو ربات']],
            [['text' => 'بازگشت ⬅️']]
        ],
        'resize_keyboard' => true
    ]);
    http_response_code(200);
    exit();
}

// تغییر نام ربات
if ($text == '✏️ تغییر نام ربات') {
    set_step($from_id, 'set_bot_name');
    send_message($from_id, "⚠️ نام جدید ربات را ارسال کنید (حداکثر 64 کاراکتر):");
    http_response_code(200);
    exit();
}
if ($step == 'set_bot_name') {
    if (empty($text) || mb_strlen($text, 'UTF-8') > 64) {
        send_message($from_id, "❌ نام نامعتبر است. لطفاً نامی تا 64 کاراکتر وارد کنید.");
        log_message("Invalid bot name by user $from_id: $text");
        http_response_code(200);
        exit();
    }
    $response = telegram_api('setMyName', ['name' => $text]);
    if ($response === false) {
        send_message($from_id, "❌ خطا در تغییر نام ربات. لطفاً دوباره تلاش کنید.");
        log_message("Failed to set bot name for user $from_id: $text");
    } else {
        send_message($from_id, "✅ نام ربات با موفقیت به <b>$text</b> تغییر کرد.");
        log_message("Bot name changed to $text by user $from_id");
    }
    set_step($from_id);
    http_response_code(200);
    exit();
}

// تغییر توضیحات ربات
if ($text == '📝 تغییر توضیحات ربات') {
    set_step($from_id, 'set_bot_description');
    send_message($from_id, "⚠️ توضیحات جدید ربات را ارسال کنید (حداکثر 512 کاراکتر):");
    http_response_code(200);
    exit();
}
if ($step == 'set_bot_description') {
    if (empty($text) || mb_strlen($text, 'UTF-8') > 512) {
        send_message($from_id, "❌ توضیحات نامعتبر است. لطفاً متنی تا 512 کاراکتر وارد کنید.");
        log_message("Invalid bot description by user $from_id: $text");
        http_response_code(200);
        exit();
    }
    $response = telegram_api('setMyDescription', ['description' => $text]);
    if ($response === false) {
        send_message($from_id, "❌ خطا در تغییر توضیحات ربات. لطفاً دوباره تلاش کنید.");
        log_message("Failed to set bot description for user $from_id: $text");
    } else {
        send_message($from_id, "✅ توضیحات ربات با موفقیت به <b>$text</b> تغییر کرد.");
        log_message("Bot description changed to $text by user $from_id");
    }
    set_step($from_id);
    http_response_code(200);
    exit();
}

// تغییر بیو ربات
if ($text == 'ℹ️ تغییر بیو ربات') {
    set_step($from_id, 'set_bot_short_description');
    send_message($from_id, "⚠️ بیو جدید ربات را ارسال کنید (حداکثر 120 کاراکتر):");
    http_response_code(200);
    exit();
}
if ($step == 'set_bot_short_description') {
    if (empty($text) || mb_strlen($text, 'UTF-8') > 120) {
        send_message($from_id, "❌ بیو نامعتبر است. لطفاً متنی تا 120 کاراکتر وارد کنید.");
        log_message("Invalid bot short description by user $from_id: $text");
        http_response_code(200);
        exit();
    }
    $response = telegram_api('setMyShortDescription', ['short_description' => $text]);
    if ($response === false) {
        send_message($from_id, "❌ خطا در تغییر بیو ربات. لطفاً دوباره تلاش کنید.");
        log_message("Failed to set bot short description for user $from_id: $text");
    } else {
        send_message($from_id, "✅ بیو ربات با موفقیت به <b>$text</b> تغییر کرد.");
        log_message("Bot short description changed to $text by user $from_id");
    }
    set_step($from_id);
    http_response_code(200);
    exit();
}

// مدیریت کانال‌ها و گروه‌ها
if ($text == '🔗 مدیریت کانال‌ها و گروه‌ها') {
    set_step($from_id);
    $stmt = $pdo->query("SELECT channels FROM options WHERE id = 1");
    $channels = json_decode($stmt->fetchColumn() ?: '[]', true);
    $chs = empty($channels) ? "هیچ کانالی ثبت نشده است." : implode("\n", $channels);
    send_message($from_id, "👇 لیست کانال‌ها:\n\n$chs", [
        'keyboard' => [
            [['text' => '➕ افزودن کانال یا گروه']],
            [['text' => '➖ حذف کانال یا گروه']],
            [['text' => 'بازگشت ⬅️']]
        ],
        'resize_keyboard' => true
    ]);
    http_response_code(200);
    exit();
}

// افزودن کانال یا گروه
if ($text == '➕ افزودن کانال یا گروه') {
    set_step($from_id, 'add_ch');
    send_message($from_id, "⚠️ فقط آیدی عددی کانال یا گروه را ارسال کنید:\n\n- حتماً ربات را در گروه یا کانال ادمین کنید.");
    http_response_code(200);
    exit();
}
if ($step == 'add_ch') {
    if (!is_numeric($text) || $text >= 0) {
        send_message($from_id, "❌ آیدی وارد شده معتبر نیست. لطفاً یک عدد منفی وارد کنید.");
        log_message("Invalid chat ID by user $from_id: $text");
        http_response_code(200);
        exit();
    }
    // بررسی ادمین بودن ربات
    $chat_member = telegram_api('getChatMember', [
        'chat_id' => $text,
        'user_id' => $bot_id
    ]);
    if ($chat_member === false || !isset($chat_member['result']['status']) || !in_array($chat_member['result']['status'], ['administrator', 'creator'], true)) {
        send_message($from_id, "❌ ربات در این کانال/گروه ادمین نیست.");
        log_message("Bot not admin in chat $text for user $from_id");
        http_response_code(200);
        exit();
    }
    $stmt = $pdo->query("SELECT channels FROM options WHERE id = 1");
    $channels = json_decode($stmt->fetchColumn() ?: '[]', true);
    if (!in_array((string)$text, array_map('strval', $channels), true)) {
        $channels[] = (string)$text;
        $stmt = $pdo->prepare("UPDATE options SET channels = ? WHERE id = 1");
        $result = $stmt->execute([json_encode($channels)]);
        if ($result) {
            log_message("Channel $text added by user $from_id");
        } else {
            log_message("Failed to add channel $text by user $from_id");
        }
    } else {
        log_message("Channel $text already exists for user $from_id");
    }
    send_message($from_id, "✅ کانال یا گروه با موفقیت اضافه شد.");
    set_step($from_id);
    http_response_code(200);
    exit();
}

// حذف کانال یا گروه
if ($text == '➖ حذف کانال یا گروه') {
    set_step($from_id, 'remove_ch');
    send_message($from_id, "⚠️ فقط آیدی عددی کانال یا گروه را ارسال کنید:");
    http_response_code(200);
    exit();
}
if ($step == 'remove_ch') {
    $stmt = $pdo->query("SELECT channels FROM options WHERE id = 1");
    $channels = json_decode($stmt->fetchColumn() ?: '[]', true);
    if (!in_array((string)$text, array_map('strval', $channels), true)) {
        send_message($from_id, "❌ این آیدی در لیست کانال‌ها وجود ندارد.");
        log_message("Chat ID $text not found for removal by user $from_id");
        http_response_code(200);
        exit();
    }
    $channels = array_values(array_diff($channels, [$text]));
    $stmt = $pdo->prepare("UPDATE options SET channels = ? WHERE id = 1");
    $stmt->execute([json_encode($channels)]);
    send_message($from_id, "✅ کانال یا گروه با موفقیت حذف شد.");
    log_message("Channel $text removed by user $from_id");
    set_step($from_id);
    http_response_code(200);
    exit();
}

// مدیریت پست‌ها
if ($text == '🎛 مدیریت پست‌ها') {
    set_step($from_id);
    send_message($from_id, "👇 یکی از گزینه‌های زیر را انتخاب کنید:", [
        'keyboard' => [
            [['text' => '🚩 لیست پست‌ها']],
            [['text' => '➕ افزودن پست']],
            [['text' => 'بازگشت ⬅️']]
        ],
        'resize_keyboard' => true
    ]);
    http_response_code(200);
    exit();
}

// افزودن پست
if ($text == '➕ افزودن پست') {
    set_step($from_id, 'addPost_active');
    send_message($from_id, "⚠️ پست مورد نظر خود را ارسال کنید (متن، عکس، ویدیو و غیره).");
    http_response_code(200);
    exit();
}

// افزودن پیام همگانی
if ($text == '➕ افزودن پیام همگانی') {
    set_step($from_id, 'addPost_hidden');
    send_message($from_id, "⚠️ پست مورد نظر خود را ارسال کنید (متن، عکس، ویدیو و غیره).");
    http_response_code(200);
    exit();
}

// ذخیره پیام پست
if ($step == 'addPost_active' || $step == 'addPost_hidden') {
    // بررسی اینکه آیا پیام معتبر است
    if (!isset($update['message']['message_id'])) {
        send_message($from_id, "❌ لطفاً یک پست معتبر (متن، عکس، ویدیو، فایل یا صوت) ارسال کنید.");
        log_message("Invalid post message by user $from_id - no message_id");
        http_response_code(200);
        exit();
    }
    
    // بررسی اینکه آیا پیام محتوای معتبری دارد
    $has_content = isset($update['message']['text']) ||
                   isset($update['message']['photo']) ||
                   isset($update['message']['video']) ||
                   isset($update['message']['document']) ||
                   isset($update['message']['audio']) ||
                   isset($update['message']['voice']) ||
                   isset($update['message']['sticker']) ||
                   isset($update['message']['animation']);
    
    if (!$has_content) {
        send_message($from_id, "❌ لطفاً یک پست معتبر (متن، عکس، ویدیو، فایل یا صوت) ارسال کنید.");
        log_message("Invalid post message by user $from_id - no valid content");
        http_response_code(200);
        exit();
    }
    $type = isset($update['message']['forward_origin']) ? 'forward' : 'copy';
    $status = $step == 'addPost_active' ? 'active' : 'hidden';
    // ذخیره پیام در جدول temp_posts
    try {
        // حذف ردیف‌های قدیمی کاربر قبل از اضافه کردن جدید
        $cleanup_stmt = $pdo->prepare("DELETE FROM temp_posts WHERE telegram_id = ?");
        $cleanup_stmt->execute([$from_id]);
        
        $stmt = $pdo->prepare("INSERT INTO temp_posts (telegram_id, message_id, type, status) VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([$from_id, $message_id, $type, $status]);
        
        if (!$result) {
            throw new Exception("Failed to execute INSERT statement");
        }
        
        // دریافت ID با استفاده از lastInsertId()
        $temp_post_id = $pdo->lastInsertId();
        
        // اگر lastInsertId() کار نکرد، آخرین ردیف اضافه شده را پیدا کنیم
        if (!$temp_post_id || $temp_post_id <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM temp_posts WHERE telegram_id = ? AND message_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$from_id, $message_id]);
            $temp_post_id = $stmt->fetchColumn();
        }
        
        if (!$temp_post_id || $temp_post_id <= 0) {
            throw new Exception("Failed to get valid temp_post_id");
        }
        
        log_message("Temp post created successfully: temp_post_id=$temp_post_id for user $from_id");
        
    } catch (Exception $e) {
        log_message("Exception inserting temp post for user $from_id: " . $e->getMessage());
        send_message($from_id, "❌ خطا در ذخیره پست. مجدد ارسال کنید.");
        http_response_code(200);
        exit();
    }
    
    set_step($from_id, "setT_{$temp_post_id}");
    $current_time_example = substr(cairo_to_tehran(date('Y-m-d H:i:00')), 0, 16); // زمان تهران
 send_message($from_id, "🕐 تاریخ و ساعت قرار گرفتن پست را به وقت تهران ارسال کنید:\n\n⚠️ فرمت: <code>YYYY-MM-DD HH:MM</code>\nمثال: <code>$current_time_example</code>");

    log_message("Post message $message_id saved in temp_posts for user $from_id, temp_post_id=$temp_post_id");
    http_response_code(200);
    exit();
}

// پردازش زمان ارسالی
if (is_string($step) && strpos($step, 'setT_') === 0) {
    if (!validate_datetime($text)) {
        $current_time_example = substr(cairo_to_tehran(date('Y-m-d H:i:00')), 0, 16); // زمان تهران
        send_message($from_id, "❌ فرمت تاریخ و ساعت اشتباه است. لطفاً به وقت تهران و با فرمت زیر وارد کنید:\n\n<code>YYYY-MM-DD HH:MM</code>\nمثال: <code>$current_time_example</code>");
        log_message("Invalid datetime format by user $from_id: $text");
        http_response_code(200);
        exit();
    }
    $temp_post_id = explode('_', $step)[1] ?? null;
    if (!$temp_post_id || !is_numeric($temp_post_id)) {
        send_message($from_id, "❌ خطا: شناسه پست موقت نامعتبر است. لطفاً دوباره پست را ارسال کنید.");
        set_step($from_id);
        log_message("Invalid temp_post_id for user $from_id: $temp_post_id");
        http_response_code(200);
        exit();
    }
    $stmt = $pdo->prepare("SELECT * FROM temp_posts WHERE id = ? AND telegram_id = ?");
    $stmt->execute([$temp_post_id, $from_id]);
    $temp_post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$temp_post) {
        send_message($from_id, "❌ خطا: پیام پست یافت نشد. لطفاً دوباره پست را ارسال کنید.");
        set_step($from_id, $temp_post['status'] ?? 'addPost_active');
        log_message("No temp post found for user $from_id, temp_post_id=$temp_post_id");
        http_response_code(200);
        exit();
    }
    $cairo_time = tehran_to_cairo("$text:00"); // تبدیل زمان تهران به قاهره
    $stmt = $pdo->prepare("INSERT INTO posts (from_chat_id, message_id, time, type, status) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$from_id, $temp_post['message_id'], $cairo_time, $temp_post['type'], $temp_post['status']]);
    $stmt = $pdo->prepare("DELETE FROM temp_posts WHERE id = ?");
    $stmt->execute([$temp_post_id]);
    send_message($from_id, "✅ پست با موفقیت اضافه شد و در زمان <code>$text</code> به وقت تهران ارسال خواهد شد.");
    log_message("Post added by user $from_id: message_id={$temp_post['message_id']}, time=$cairo_time (Cairo, $text Tehran), type={$temp_post['type']}, status={$temp_post['status']}");
    set_step($from_id);
    http_response_code(200);
    exit();
}

// لیست پست‌ها
if ($text == '🚩 لیست پست‌ها') {
    set_step($from_id);
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE status = ? ORDER BY time ASC");
    $stmt->execute(['active']);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$posts) {
        send_message($from_id, "⚠️ پستی وجود ندارد.");
        http_response_code(200);
        exit();
    }
    foreach ($posts as $post) {
        $type = $post['type'] == 'forward' ? 'فوروارد' : 'کپی';
        $tehran_time = substr(cairo_to_tehran($post['time']), 0, 16); // تبدیل به وقت تهران
        $response = telegram_api('copyMessage', [
            'chat_id' => $from_id,
            'from_chat_id' => $post['from_chat_id'],
            'message_id' => $post['message_id'],
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "ساعت: $tehran_time (به وقت تهران)", 'callback_data' => 'none']],
                    [['text' => "نوع: $type", 'callback_data' => 'none']],
                    [['text' => '❌ حذف پست', 'callback_data' => "rm_{$post['id']}"]]
                ]
            ])
        ]);
        if ($response === false) {
            send_message($from_id, "⚠️ خطا در نمایش پست با شناسه {$post['id']}.");
            log_message("Failed to display post {$post['id']} for user $from_id");
        }
    }
    http_response_code(200);
    exit();
}

// پیام همگانی
if ($text == '👥 پیام همگانی') {
    set_step($from_id);
    send_message($from_id, "👇 یکی از گزینه‌های زیر را انتخاب کنید:", [
        'keyboard' => [
            [['text' => '🚩 لیست پیام همگانی']],
            [['text' => '➕ افزودن پیام همگانی']],
            [['text' => 'بازگشت ⬅️']]
        ],
        'resize_keyboard' => true
    ]);
    http_response_code(200);
    exit();
}

// لیست پیام همگانی
if ($text == '🚩 لیست پیام همگانی') {
    set_step($from_id);
    $stmt = $pdo->prepare("SELECT * FROM posts WHERE status = ? ORDER BY time ASC");
    $stmt->execute(['hidden']);
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$posts) {
        send_message($from_id, "⚠️ پستی وجود ندارد.");
        http_response_code(200);
        exit();
    }
    foreach ($posts as $post) {
        $type = $post['type'] == 'forward' ? 'فوروارد' : 'کپی';
        $tehran_time = substr(cairo_to_tehran($post['time']), 0, 16); // تبدیل به وقت تهران
        $response = telegram_api('copyMessage', [
            'chat_id' => $from_id,
            'from_chat_id' => $post['from_chat_id'],
            'message_id' => $post['message_id'],
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "ساعت: $tehran_time (به وقت تهران)", 'callback_data' => 'none']],
                    [['text' => "نوع: $type", 'callback_data' => 'none']],
                    [['text' => '❌ حذف پست', 'callback_data' => "rm_{$post['id']}"]]
                ]
            ])
        ]);
        if ($response === false) {
            send_message($from_id, "⚠️ خطا در نمایش پست با شناسه {$post['id']}.");
            log_message("Failed to display post {$post['id']} for user $from_id");
        }
    }
    http_response_code(200);
    exit();
}

// ارسال پست روزانه
if ($text == '📅 ارسال پست روزانه') {
    set_step($from_id);
    send_message($from_id, "👇 یکی از گزینه‌های زیر را انتخاب کنید:", [
        'keyboard' => [
            [['text' => '🚩 لیست پست‌های روزانه']],
            [['text' => '➕ افزودن پست روزانه']],
            [['text' => 'بازگشت ⬅️']]
        ],
        'resize_keyboard' => true
    ]);
    http_response_code(200);
    exit();
}

// افزودن پست روزانه
if ($text == '➕ افزودن پست روزانه') {
    set_step($from_id, 'addDailyPost');
    send_message($from_id, "⚠️ پست مورد نظر خود را ارسال کنید (متن، عکس، ویدیو و غیره).");
    http_response_code(200);
    exit();
}

// ذخیره پیام پست روزانه
if ($step == 'addDailyPost') {
    log_message("Entered addDailyPost handler for user $from_id");
    // بررسی اینکه آیا پیام معتبر است
    if (!isset($update['message']['message_id'])) {
        send_message($from_id, "❌ لطفاً یک پست معتبر (متن، عکس، ویدیو، فایل یا صوت) ارسال کنید.");
        log_message("Invalid daily post message by user $from_id - no message_id");
        http_response_code(200);
        exit();
    }
    
    // بررسی اینکه آیا پیام محتوای معتبری دارد
    $has_content = isset($update['message']['text']) ||
                   isset($update['message']['photo']) ||
                   isset($update['message']['video']) ||
                   isset($update['message']['document']) ||
                   isset($update['message']['audio']) ||
                   isset($update['message']['voice']) ||
                   isset($update['message']['sticker']) ||
                   isset($update['message']['animation']);
    
    if (!$has_content) {
        send_message($from_id, "❌ لطفاً یک پست معتبر (متن، عکس، ویدیو، فایل یا صوت) ارسال کنید.");
        log_message("Invalid daily post message by user $from_id - no valid content");
        http_response_code(200);
        exit();
    }
    $type = isset($update['message']['forward_origin']) ? 'forward' : 'copy';
    try {
        // حذف ردیف‌های قدیمی کاربر قبل از اضافه کردن جدید
        $cleanup_stmt = $pdo->prepare("DELETE FROM temp_posts WHERE telegram_id = ?");
        $cleanup_stmt->execute([$from_id]);
        
        $stmt = $pdo->prepare("INSERT INTO temp_posts (telegram_id, message_id, type, status) VALUES (?, ?, ?, ?)");
        $result = $stmt->execute([$from_id, $message_id, $type, 'active']);
        
        if (!$result) {
            throw new Exception("Failed to execute INSERT statement");
        }
        
        // دریافت ID با استفاده از lastInsertId()
        $temp_post_id = $pdo->lastInsertId();
        
        // اگر lastInsertId() کار نکرد، آخرین ردیف اضافه شده را پیدا کنیم
        if (!$temp_post_id || $temp_post_id <= 0) {
            $stmt = $pdo->prepare("SELECT id FROM temp_posts WHERE telegram_id = ? AND message_id = ? ORDER BY id DESC LIMIT 1");
            $stmt->execute([$from_id, $message_id]);
            $temp_post_id = $stmt->fetchColumn();
        }
        
        if (!$temp_post_id || $temp_post_id <= 0) {
            throw new Exception("Failed to get valid temp_post_id");
        }
        
        log_message("Temp daily post created successfully: temp_post_id=$temp_post_id for user $from_id");
        
    } catch (Exception $e) {
        log_message("Exception inserting temp daily post for user $from_id: " . $e->getMessage());
        send_message($from_id, "❌ خطا در ذخیره پست. مجدد ارسال کنید.");
        http_response_code(200);
        exit();
    }
    
    set_step($from_id, "setDailyT_{$temp_post_id}");
    $current_time_example = substr(cairo_to_tehran(date('Y-m-d H:i:00')), 11, 5); // فقط ساعت و دقیقه تهران
    $sentPrompt = send_message($from_id, "🕐 ساعت ارسال روزانه را به وقت تهران ارسال کنید:\n\n⚠️ فرمت: <code>HH:MM</code>\nمثال: <code>$current_time_example</code>");

    log_message("Daily post message $message_id saved in temp_posts for user $from_id, temp_post_id=$temp_post_id; prompt sent=" . ($sentPrompt ? 'yes' : 'no'));
    http_response_code(200);
    exit();
}

// پردازش زمان ارسالی برای پست روزانه
if (is_string($step) && strpos($step, 'setDailyT_') === 0) {
    if (!validate_time($text)) {
        $current_time_example = substr(cairo_to_tehran(date('Y-m-d H:i:00')), 11, 5); // فقط ساعت و دقیقه تهران
        send_message($from_id, "❌ فرمت ساعت اشتباه است. لطفاً به وقت تهران و با فرمت زیر وارد کنید:\n\n<code>HH:MM</code>\nمثال: <code>$current_time_example</code>");
        log_message("Invalid time format for daily post by user $from_id: $text");
        http_response_code(200);
        exit();
    }
    $temp_post_id = explode('_', $step)[1] ?? null;
    if (!$temp_post_id || !is_numeric($temp_post_id)) {
        send_message($from_id, "❌ خطا: شناسه پست موقت نامعتبر است. لطفاً دوباره پست را ارسال کنید.");
        set_step($from_id);
        log_message("Invalid temp_post_id for daily post by user $from_id: $temp_post_id");
        http_response_code(200);
        exit();
    }
    $stmt = $pdo->prepare("SELECT * FROM temp_posts WHERE id = ? AND telegram_id = ?");
    $stmt->execute([$temp_post_id, $from_id]);
    $temp_post = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$temp_post) {
        send_message($from_id, "❌ خطا: پیام پست یافت نشد. لطفاً دوباره پست را ارسال کنید.");
        set_step($from_id, 'addDailyPost');
        log_message("No temp post found for daily post by user $from_id, temp_post_id=$temp_post_id");
        http_response_code(200);
        exit();
    }
    // تبدیل زمان تهران به قاهره برای ذخیره در دیتابیس
    $today = date('Y-m-d'); // تاریخ امروز (قاهره)
    $cairo_time = tehran_to_cairo("$today $text:00");
    $cairo_time_only = substr($cairo_time, 11, 8); // استخراج HH:MM:SS
    $stmt = $pdo->prepare("INSERT INTO daily_posts (from_chat_id, message_id, time, type) VALUES (?, ?, ?, ?)");
    $stmt->execute([$from_id, $temp_post['message_id'], $cairo_time_only, $temp_post['type']]);
    $stmt = $pdo->prepare("DELETE FROM temp_posts WHERE id = ?");
    $stmt->execute([$temp_post_id]);
    send_message($from_id, "✅ پست روزانه با موفقیت اضافه شد و هر روز در ساعت <code>$text</code> به وقت تهران ارسال خواهد شد.");
    log_message("Daily post added by user $from_id: message_id={$temp_post['message_id']}, time=$cairo_time_only (Cairo, $text Tehran), type={$temp_post['type']}");
    set_step($from_id);
    http_response_code(200);
    exit();
}

// لیست پست‌های روزانه
if ($text == '🚩 لیست پست‌های روزانه') {
    set_step($from_id);
    $stmt = $pdo->prepare("SELECT * FROM daily_posts ORDER BY time ASC");
    $stmt->execute();
    $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$posts) {
        send_message($from_id, "⚠️ پست روزانه‌ای وجود ندارد.");
        http_response_code(200);
        exit();
    }
    foreach ($posts as $post) {
        $type = $post['type'] == 'forward' ? 'فوروارد' : 'کپی';
        $tehran_time = substr(cairo_to_tehran("2025-01-01 {$post['time']}"), 11, 5); // تبدیل به وقت تهران
        $response = telegram_api('copyMessage', [
            'chat_id' => $from_id,
            'from_chat_id' => $post['from_chat_id'],
            'message_id' => $post['message_id'],
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "ساعت: $tehran_time (به وقت تهران)", 'callback_data' => 'none']],
                    [['text' => "نوع: $type", 'callback_data' => 'none']],
                    [['text' => '❌ حذف پست روزانه', 'callback_data' => "rmDaily_{$post['id']}"]]
                ]
            ])
        ]);
        if ($response === false) {
            send_message($from_id, "⚠️ خطا در نمایش پست روزانه با شناسه {$post['id']}.");
            log_message("Failed to display daily post {$post['id']} for user $from_id");
        }
    }
    http_response_code(200);
    exit();
}

// مدیریت callback_query
if (isset($update['callback_query'])) {
    $callback_data = $update['callback_query']['data'] ?? '';
    $callback_id = $update['callback_query']['id'];
    telegram_api('answerCallbackQuery', ['callback_query_id' => $callback_id]);
    if (is_string($callback_data) && strpos($callback_data, 'rmDaily_') === 0) {
        $post_id = explode('_', $callback_data)[1] ?? null;
        if (!$post_id || !is_numeric($post_id)) {
            send_message($from_id, "❌ خطا: شناسه پست روزانه نامعتبر است.");
            log_message("Invalid daily post_id in callback for user $from_id: $callback_data");
            http_response_code(200);
            exit();
        }
        $stmt = $pdo->prepare("DELETE FROM daily_posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $response = telegram_api('deleteMessage', [
            'chat_id' => $from_id,
            'message_id' => $update['callback_query']['message']['message_id']
        ]);
        if ($response !== false) {
            send_message($from_id, "✅ پست روزانه با موفقیت حذف شد.");
        } else {
            send_message($from_id, "⚠️ خطا در حذف پست روزانه.");
        }
        log_message("Daily post $post_id deleted by user $from_id");
        http_response_code(200);
        exit();
    }
    if (is_string($callback_data) && strpos($callback_data, 'rm_') === 0) {
        $post_id = explode('_', $callback_data)[1] ?? null;
        if (!$post_id || !is_numeric($post_id)) {
            send_message($from_id, "❌ خطا: شناسه پست نامعتبر است.");
            log_message("Invalid post_id in callback for user $from_id: $callback_data");
            http_response_code(200);
            exit();
        }
        $stmt = $pdo->prepare("DELETE FROM posts WHERE id = ?");
        $stmt->execute([$post_id]);
        $response = telegram_api('deleteMessage', [
            'chat_id' => $from_id,
            'message_id' => $update['callback_query']['message']['message_id']
        ]);
        if ($response !== false) {
            send_message($from_id, "✅ پست با موفقیت حذف شد.");
        } else {
            send_message($from_id, "⚠️ خطا در حذف پست.");
        }
        log_message("Post $post_id deleted by user $from_id");
        http_response_code(200);
        exit();
    }
    http_response_code(200);
    exit();
}

http_response_code(200);
?>
