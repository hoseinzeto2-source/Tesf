<?php
header('Content-Type: text/plain; charset=utf-8');

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
        if (strlen($value) >= 2 && (($value[0] === '"' && substr($value, -1) === '"') ||
            ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        $env[$key] = $value;
    }
    return $env;
}

function create_tables(PDO $pdo) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            telegram_id BIGINT(20) NOT NULL,
            step VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (telegram_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS options (
            id INT(11) NOT NULL,
            channels TEXT NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS temp_posts (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            telegram_id BIGINT(20) NOT NULL,
            message_id INT(11) NOT NULL,
            type ENUM('forward','copy') NOT NULL,
            status ENUM('active','hidden') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS posts (
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
        "CREATE TABLE IF NOT EXISTS daily_posts (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            from_chat_id BIGINT(20) NOT NULL,
            message_id INT(11) NOT NULL,
            time TIME NOT NULL,
            type ENUM('forward','copy') NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS daily_post_logs (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            daily_post_id BIGINT(20) NOT NULL,
            sent_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_daily_post_id_sent_date (daily_post_id,sent_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS daily_post_reservations (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            daily_post_id BIGINT(20) NOT NULL,
            reserved_date DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_reservation (daily_post_id, reserved_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS daily_post_sends (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            daily_post_id BIGINT(20) NOT NULL,
            channel_id VARCHAR(128) NOT NULL,
            sent_date DATE NOT NULL,
            tg_message_id BIGINT(20) DEFAULT NULL,
            status ENUM('sent','failed','deleted') NOT NULL DEFAULT 'sent',
            attempt_count INT NOT NULL DEFAULT 1,
            last_error TEXT DEFAULT NULL,
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_daily_channel_date (daily_post_id, channel_id, sent_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS post_sends (
            id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY,
            post_id BIGINT(20) NOT NULL,
            channel_id VARCHAR(128) NOT NULL,
            status ENUM('sent','failed') NOT NULL DEFAULT 'sent',
            sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_post_channel (post_id, channel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    try {
        $pdo->exec("ALTER TABLE daily_post_sends ADD COLUMN sent_minute DATETIME GENERATED ALWAYS AS (FROM_UNIXTIME(UNIX_TIMESTAMP(sent_at) - MOD(UNIX_TIMESTAMP(sent_at), 60))) STORED");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE daily_post_sends ADD UNIQUE KEY uniq_post_channel_minute (daily_post_id, channel_id, sent_minute)");
    } catch (Exception $e) {}
    try {
        $pdo->exec("ALTER TABLE daily_post_logs ADD UNIQUE KEY uniq_daily_date (daily_post_id, sent_date)");
    } catch (Exception $e) {}

    $count = (int)$pdo->query("SELECT COUNT(*) FROM options WHERE id = 1")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO options (id, channels) VALUES (1, '[]')");
    }
}

$envPath = __DIR__ . '/.env';
if (!file_exists($envPath)) {
    die("FAIL: .env not found\n");
}

$env = load_env_file($envPath);
if ($env === false || empty($env)) {
    die("FAIL: cannot parse .env\n");
}

$botToken = trim(isset($env['BOT_TOKEN']) ? $env['BOT_TOKEN'] : '');
$botId = trim(isset($env['BOT_ID']) ? $env['BOT_ID'] : '');
$botUsername = trim(isset($env['BOT_USERNAME']) ? $env['BOT_USERNAME'] : '');
$webhookUrl = trim(isset($env['WEBHOOK_URL']) ? $env['WEBHOOK_URL'] : '');
$dbHost = isset($env['DB_HOST']) ? $env['DB_HOST'] : 'localhost';
$dbName = isset($env['DB_DATABASE']) ? $env['DB_DATABASE'] : '';
$dbUser = isset($env['DB_USERNAME']) ? $env['DB_USERNAME'] : '';
$dbPass = isset($env['DB_PASSWORD']) ? $env['DB_PASSWORD'] : '';

if ($botId === '' && preg_match('/^(\d+):/', $botToken, $m)) {
    $botId = $m[1];
}

echo "=== Post10s Bot Setup (@post10sbot) ===\n\n";

echo "[1] Database connection\n";
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
    );
    echo "OK: connected to {$dbName}\n";
} catch (Exception $e) {
    die("FAIL: " . $e->getMessage() . "\n");
}

echo "\n[2] Creating tables\n";
create_tables($pdo);
$tableNames = array('users', 'options', 'temp_posts', 'posts', 'daily_posts', 'daily_post_logs', 'daily_post_reservations', 'daily_post_sends', 'post_sends');
foreach ($tableNames as $table) {
    $exists = (int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = " . $pdo->quote($table)
    )->fetchColumn();
    echo ($exists ? 'OK' : 'MISSING') . ": {$table}\n";
}

echo "\n[3] Telegram bot info\n";
$ch = curl_init("https://api.telegram.org/bot{$botToken}/getMe");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
$meResponse = json_decode(curl_exec($ch), true);
curl_close($ch);

if (!empty($meResponse['ok'])) {
    $me = $meResponse['result'];
    echo "OK: @{$me['username']} (ID: {$me['id']})\n";
    echo "BOT_ID in .env: {$botId}\n";
    echo "BOT_USERNAME in .env: {$botUsername}\n";
} else {
    echo "FAIL: getMe - " . (isset($meResponse['description']) ? $meResponse['description'] : 'unknown') . "\n";
}

echo "\n[4] Webhook setup\n";
if ($webhookUrl === '') {
    echo "SKIP: WEBHOOK_URL not set in .env\n";
} else {
    $ch = curl_init("https://api.telegram.org/bot{$botToken}/setWebhook");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
        'url' => $webhookUrl,
        'drop_pending_updates' => true,
        'allowed_updates' => json_encode(array(
            'message', 'edited_message', 'channel_post',
            'callback_query', 'my_chat_member', 'chat_member', 'chat_join_request'
        )),
    )));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $whResponse = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if (!empty($whResponse['ok'])) {
        echo "OK: webhook set to {$webhookUrl}\n";
    } else {
        echo "FAIL: " . (isset($whResponse['description']) ? $whResponse['description'] : 'setWebhook failed') . "\n";
    }

    $ch = curl_init("https://api.telegram.org/bot{$botToken}/getWebhookInfo");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $info = json_decode(curl_exec($ch), true);
    curl_close($ch);
    if (!empty($info['ok'])) {
        $r = $info['result'];
        echo "Webhook URL: " . (isset($r['url']) ? $r['url'] : 'none') . "\n";
        echo "Pending updates: " . (isset($r['pending_update_count']) ? $r['pending_update_count'] : 0) . "\n";
        if (!empty($r['last_error_message'])) {
            echo "Last error: " . $r['last_error_message'] . "\n";
        }
    }
}

echo "\n=== Setup complete ===\n";
echo "Delete setup.php after verification.\n";
