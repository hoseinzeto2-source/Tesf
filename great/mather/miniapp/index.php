<?php
require_once dirname(__DIR__) . '/config.php';

if (($_GET['repair_bots'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    $provided = (string) ($_GET['key'] ?? '');
    $expected = hash('sha256', 'gpro-mather-github-deploy-361a');
    if ($provided === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once dirname(__DIR__) . '/lib/child_bots.php';
    require_once dirname(__DIR__) . '/lib/bot_folders.php';
    require_once dirname(__DIR__) . '/lib/channel_folders.php';
    require_once dirname(__DIR__) . '/lib/child_bot_repair.php';
    $stats = repairChildBotData();
    echo json_encode(['ok' => true, 'repair' => $stats], JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_GET['bootstrap_github'] ?? '') === '1') {
    header('Content-Type: text/plain; charset=utf-8');
    $deployKey = hash('sha256', 'gpro-mather-github-deploy-361a');
    $provided = (string) ($_GET['key'] ?? '');
    if ($provided === '' || !hash_equals($deployKey, $provided)) {
        http_response_code(403);
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
        'lib/bot_folders.php',
        'lib/channel_folders.php',
        'lib/bot_stats.php',
        'lib/child_bot_repair.php',
        'lib/bot_health.php',
        'lib/bot_profile.php',
        'lib/auto_post_schedules.php',
        'lib/zapas_bots.php',
        'lib/glass_button_tools.php',
        'lib/auto_post_channel_posts.php',
        'miniapp/index.php',
        'miniapp/css/miniapp.css',
        'miniapp/assets/.htaccess',
        'miniapp/assets/fontawesome/css/all.min.css',
        'miniapp/assets/fontawesome/webfonts/fa-solid-900.woff2',
        'miniapp/assets/fontawesome/webfonts/fa-regular-400.woff2',
        'miniapp/assets/fontawesome/webfonts/fa-brands-400.woff2',
        'miniapp/assets/fonts/PeydaWeb-Regular.woff2',
        'miniapp/assets/fonts/PeydaWeb-Bold.woff2',
        'miniapp/assets/fonts/PeydaWeb-Black.woff2',
        'miniapp/js/app.js',
        'miniapp/api/server.php',
        'miniapp/api/manage_bots.php',
        'miniapp/api/my_bots.php',
        'miniapp/api/bot_folders.php',
        'miniapp/api/create_bot.php',
        'miniapp/api/glass_button_tools.php',
        'miniapp/api/zapas_bots.php',
        'tools/pull_github_deploy.php',
    ];

    $written = [];
    $errors = [];
    foreach ($files as $rel) {
        $url = $base . '/' . $rel;
        $ctx = stream_context_create([
            'http' => ['timeout' => 60, 'header' => "User-Agent: gpro-deploy/1.0\r\n"],
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

    echo 'written=' . count($written) . "\n";
    foreach ($written as $f) {
        echo "+ {$f}\n";
    }
    if ($errors !== []) {
        echo 'errors=' . count($errors) . "\n";
        foreach ($errors as $e) {
            echo "! {$e}\n";
        }
    }

    if ($errors === [] && is_file($root . '/install.php')) {
        echo "\nRunning install.php...\n";
        ob_start();
        try {
            include $root . '/install.php';
            echo trim((string) ob_get_clean()) . "\n";
        } catch (Throwable $e) {
            ob_end_clean();
            echo 'install_error: ' . $e->getMessage() . "\n";
        }
    }
    exit;
}

if (($_GET['action'] ?? '') === 'deploy_mather') {
    header('Content-Type: application/json; charset=utf-8');
    $key = (string) ($_GET['key'] ?? $_POST['key'] ?? '');
    $expected = hash('sha256', (string) ($bot_token ?? '') . 'miniapp_publish');
    if ($expected === '' || !hash_equals($expected, $key)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['bundle']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bundle_required'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $matherRoot = dirname(__DIR__);
    $allowed = [
        'lib/mather_deploy.php' => $matherRoot . '/lib/mather_deploy.php',
        'lib/channel_stats.php' => $matherRoot . '/lib/channel_stats.php',
        'lib/channel_folders.php' => $matherRoot . '/lib/channel_folders.php',
        'lib/channels.php' => $matherRoot . '/lib/channels.php',
        'lib/miniapp_publish.php' => $matherRoot . '/lib/miniapp_publish.php',
        'publish_miniapp.php' => $matherRoot . '/publish_miniapp.php',
        'install.php' => $matherRoot . '/install.php',
        'miniapp/index.php' => $matherRoot . '/miniapp/index.php',
        'miniapp/css/miniapp.css' => $matherRoot . '/miniapp/css/miniapp.css',
        'miniapp/js/app.js' => $matherRoot . '/miniapp/js/app.js',
        'miniapp/api/channels.php' => $matherRoot . '/miniapp/api/channels.php',
        'miniapp/api/channel_folders.php' => $matherRoot . '/miniapp/api/channel_folders.php',
        'miniapp/api/bot_folders.php' => $matherRoot . '/miniapp/api/bot_folders.php',
        'miniapp/api/my_bots.php' => $matherRoot . '/miniapp/api/my_bots.php',
        'miniapp/api/create_bot.php' => $matherRoot . '/miniapp/api/create_bot.php',
        'lib/bot_folders.php' => $matherRoot . '/lib/bot_folders.php',
        'lib/child_bots.php' => $matherRoot . '/lib/child_bots.php',
        'lib/uploader_versions.php' => $matherRoot . '/lib/uploader_versions.php',
        'miniapp/api/uploader_versions.php' => $matherRoot . '/miniapp/api/uploader_versions.php',
        'miniapp/api/channel_stats.php' => $matherRoot . '/miniapp/api/channel_stats.php',
        'miniapp/api/dashboard_members.php' => $matherRoot . '/miniapp/api/dashboard_members.php',
        'miniapp/api/server.php' => $matherRoot . '/miniapp/api/server.php',
        'miniapp/api/manage_bots.php' => $matherRoot . '/miniapp/api/manage_bots.php',
        'miniapp/api/glass_button_tools.php' => $matherRoot . '/miniapp/api/glass_button_tools.php',
        'miniapp/api/zapas_bots.php' => $matherRoot . '/miniapp/api/zapas_bots.php',
        'lib/manage_bots.php' => $matherRoot . '/lib/manage_bots.php',
        'lib/manage_bot_router.php' => $matherRoot . '/lib/manage_bot_router.php',
        'lib/manage_bot_membership.php' => $matherRoot . '/lib/manage_bot_membership.php',
        'manage_bot.php' => $matherRoot . '/manage_bot.php',
        'miniapp/api/channel_invites.php' => $matherRoot . '/miniapp/api/channel_invites.php',
        'miniapp/api/auth.php' => $matherRoot . '/miniapp/api/auth.php',
        'miniapp/api/ad_campaigns.php' => $matherRoot . '/miniapp/api/ad_campaigns.php',
        'lib/ad_campaigns.php' => $matherRoot . '/lib/ad_campaigns.php',
    ];
    $zip = new ZipArchive();
    if ($zip->open($_FILES['bundle']['tmp_name']) !== true) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'invalid_zip'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $written = [];
    $errors = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = ltrim(str_replace('\\', '/', (string) $zip->getNameIndex($i)), '/');
        $name = preg_replace('#^great/mather/#', '', $name) ?? $name;
        $name = preg_replace('#^mather/#', '', $name) ?? $name;
        if (!isset($allowed[$name])) {
            continue;
        }
        $content = $zip->getFromIndex($i);
        if ($content === false) {
            $errors[] = $name . ': read_failed';
            continue;
        }
        $localPath = $allowed[$name];
        $dir = dirname($localPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $errors[] = $name . ': mkdir_failed';
            continue;
        }
        if (file_put_contents($localPath, $content) === false) {
            $errors[] = $name . ': write_failed';
            continue;
        }
        $written[] = $name;
    }
    $zip->close();
    echo json_encode(['ok' => $errors === [], 'written' => $written, 'errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

$assetVersion = '112';
$logoUrl = 'https://mr-cheat.ir/assets/logo.png';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
  <head><meta charset="utf-8">
    
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
    <meta name="theme-color" content="#eef1f6" />
    <meta name="color-scheme" content="light" />
    <meta name="description" content="پنل manage | gpro100_bot" />
    <title>manage | gpro100_bot</title>
    <link rel="preload" href="assets/fontawesome/webfonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin="anonymous" />
    <link rel="preload" href="assets/fonts/PeydaWeb-Regular.woff2" as="font" type="font/woff2" crossorigin="anonymous" />
    <link rel="stylesheet" href="assets/fontawesome/css/all.min.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>" />
    <style>
      html { color-scheme: light !important; }
      body { background: #eef1f6 !important; color: #1e293b !important; }
    </style>
    <link rel="stylesheet" href="css/miniapp.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <script defer src="js/app.js?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>"></script>
  </head>
  <body>
    <div class="app">
      <section id="screenAuth" class="screen auth is-active" aria-label="احراز هویت">
        <div class="glass-card auth__card">
          <header class="auth__head">
            <span class="auth__shield" aria-hidden="true">
              <i class="fa-solid fa-shield-halved"></i>
            </span>
            <div class="auth__brand">
              <img class="auth__logo" src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" width="48" height="48" alt="" />
              <span class="auth__brand-text">manage</span>
            </div>
            <h1 class="auth__title">ورود به پنل</h1>
            <p class="auth__sub">@gpro100_bot</p>
          </header>
          <div class="auth__loader">
            <div class="spinner" aria-hidden="true"></div>
            <p id="authStatus" class="auth__status">در حال اتصال...</p>
            <div id="authError" class="error-box" hidden></div>
          </div>
        </div>
      </section>

      <main id="appMain" class="app-main" hidden>
        <!-- داشبورد -->
        <section id="screenHome" class="screen is-active" aria-label="داشبورد">
          <header class="topbar">
            <div class="topbar__brand">
              <img class="topbar__logo" src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" width="38" height="38" alt="" />
              <div>
                <h2 class="topbar__title">داشبورد</h2>
                <p class="topbar__sub">خلاصه وضعیت</p>
              </div>
            </div>
          </header>

          <div class="quick-stats quick-stats--3">
            <article class="quick-stat">
              <span class="quick-stat__icon quick-stat__icon--purple"><i class="fa-solid fa-robot"></i></span>
              <div>
                <p class="quick-stat__label">اعضای ربات</p>
                <p id="homeBotMembers" class="quick-stat__value">—</p>
              </div>
            </article>
            <article class="quick-stat">
              <span class="quick-stat__icon quick-stat__icon--blue"><i class="fa-solid fa-users"></i></span>
              <div>
                <p class="quick-stat__label">اعضای کانال</p>
                <p id="homeChannelMembers" class="quick-stat__value">—</p>
              </div>
            </article>
            <article class="quick-stat">
              <span class="quick-stat__icon quick-stat__icon--amber"><i class="fa-solid fa-clock"></i></span>
              <div>
                <p class="quick-stat__label">آخرین فعالیت</p>
                <p id="homeLastSeen" class="quick-stat__value quick-stat__value--multiline">—</p>
              </div>
            </article>
          </div>

          <article class="glass-card panel-card dash-analytics">
            <div class="dash-analytics__top">
              <h3 class="panel-card__title dash-analytics__title">
                <i class="fa-solid fa-chart-column"></i>
                آمار کانال‌ها
              </h3>
              <button type="button" class="dash-analytics__cta" data-go="channels">
                همه کانال‌ها
                <i class="fa-solid fa-arrow-left-long"></i>
              </button>
            </div>

            <div id="dashboardChannelsOverview" class="dash-analytics__body" aria-live="polite">
              <div class="dash-analytics__loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>در حال آماده‌سازی آمار...</span>
              </div>
            </div>
          </article>

          <article class="glass-card panel-card">
            <h3 class="panel-card__title">
              <i class="fa-solid fa-bolt"></i>
              دسترسی سریع
            </h3>
            <div class="menu-grid">
              <button type="button" class="menu-card" data-go="channels">
                <span class="menu-card__icon menu-card__icon--violet"><i class="fa-solid fa-bullhorn"></i></span>
                <span class="menu-card__title">کانال‌های ربات</span>
                <span class="menu-card__sub">جایی که ربات ادمین است</span>
              </button>
              <button type="button" class="menu-card" data-go="ads">
                <span class="menu-card__icon menu-card__icon--green"><i class="fa-solid fa-rectangle-ad"></i></span>
                <span class="menu-card__title">تبلیغات</span>
                <span class="menu-card__sub">سفارش ممبر و پوشه‌های تبلیغ</span>
              </button>
              <button type="button" class="menu-card" data-go="server">
                <span class="menu-card__icon menu-card__icon--green"><i class="fa-solid fa-server"></i></span>
                <span class="menu-card__title">وضعیت سرور</span>
                <span class="menu-card__sub">ربات و دیتابیس</span>
              </button>
            </div>
          </article>

          <article class="glass-card panel-card">
            <button id="btnRefresh" class="btn btn--primary btn--block" type="button">
              <i class="fa-solid fa-rotate"></i>
              بروزرسانی اطلاعات
            </button>
          </article>
        </section>

        <!-- کانال‌های ربات -->
        <section id="screenChannels" class="screen" aria-label="کانال‌های ربات">
          <header class="topbar">
            <div class="topbar__brand">
              <span class="topbar__icon" aria-hidden="true"><i class="fa-solid fa-folder-tree"></i></span>
              <div>
                <h2 class="topbar__title">کانال‌های ربات</h2>
                <p id="channelsCount" class="topbar__sub">در حال بارگذاری...</p>
              </div>
            </div>
          </header>

          <article class="glass-card panel-card panel-card--explorer">
            <div class="explorer-bar">
              <button id="btnChannelsUp" class="explorer-bar__up" type="button" hidden aria-label="بازگشت">
                <i class="fa-solid fa-arrow-right"></i>
              </button>
              <p id="channelsBreadcrumb" class="explorer-bar__path">صفحه اصلی</p>
              <button id="btnNewFolder" class="explorer-bar__action" type="button">
                <i class="fa-solid fa-folder-plus"></i>
                پوشه جدید
              </button>
            </div>
            <div id="channelsList" class="explorer-grid">
              <div class="explorer-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>در حال دریافت لیست...</span>
              </div>
            </div>
            <p class="hint-text explorer-hint">
              <i class="fa-solid fa-circle-info"></i>
              کانال یا پوشه را بکشید روی پوشه · نگه دارید برای منو · لمس کوتاه برای باز کردن
            </p>
          </article>

          <article class="glass-card panel-card panel-card--explorer panel-card--explorer-bots">
            <header class="explorer-section-head">
              <span class="explorer-section-head__icon" aria-hidden="true"><i class="fa-solid fa-robot"></i></span>
              <div>
                <h3 class="explorer-section-head__title">ربات‌های من</h3>
                <p id="botsCount" class="explorer-section-head__sub">در حال بارگذاری...</p>
              </div>
            </header>
            <div class="explorer-bar">
              <button id="btnBotsUp" class="explorer-bar__up" type="button" hidden aria-label="بازگشت">
                <i class="fa-solid fa-arrow-right"></i>
              </button>
              <p id="botsBreadcrumb" class="explorer-bar__path">صفحه اصلی</p>
              <div class="explorer-bar__actions">
                <button id="btnNewBotFolder" class="explorer-bar__action" type="button">
                  <i class="fa-solid fa-folder-plus"></i>
                  پوشه جدید
                </button>
                <button id="btnAddBot" class="explorer-bar__action explorer-bar__action--primary" type="button">
                  <i class="fa-solid fa-plus"></i>
                  افزودن ربات
                </button>
              </div>
            </div>
            <div id="botsList" class="explorer-grid">
              <div class="explorer-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>در حال دریافت لیست...</span>
              </div>
            </div>
            <p class="hint-text explorer-hint">
              <i class="fa-solid fa-circle-info"></i>
              ربات را بکشید روی پوشه · لمس کوتاه برای آمار · پوشه را نگه دارید برای منو
            </p>
          </article>

          <article class="glass-card panel-card panel-card--explorer panel-card--explorer-groups">
            <header class="explorer-section-head">
              <span class="explorer-section-head__icon explorer-section-head__icon--groups" aria-hidden="true"><i class="fa-solid fa-users-rectangle"></i></span>
              <div>
                <h3 class="explorer-section-head__title">گروه‌های محتوا</h3>
                <p id="contentGroupsCount" class="explorer-section-head__sub">در حال بارگذاری...</p>
              </div>
            </header>
            <div class="explorer-bar">
              <button id="btnContentGroupsUp" class="explorer-bar__up" type="button" hidden aria-label="بازگشت">
                <i class="fa-solid fa-arrow-right"></i>
              </button>
              <p id="contentGroupsBreadcrumb" class="explorer-bar__path">صفحه اصلی</p>
              <button id="btnNewContentGroupFolder" class="explorer-bar__action" type="button">
                <i class="fa-solid fa-folder-plus"></i>
                پوشه جدید
              </button>
            </div>
            <div id="contentGroupsList" class="explorer-grid">
              <div class="explorer-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>در حال دریافت لیست...</span>
              </div>
            </div>
            <p class="hint-text explorer-hint">
              <i class="fa-solid fa-circle-info"></i>
              گروه‌های خصوصی/عمومی که ربات manage در آن‌ها ادمین است · خودکار شناسایی می‌شوند
            </p>
          </article>

          <article class="glass-card panel-card panel-card--explorer panel-card--explorer-autopost">
            <header class="explorer-section-head">
              <span class="explorer-section-head__icon explorer-section-head__icon--autopost" aria-hidden="true"><i class="fa-solid fa-calendar-check"></i></span>
              <div>
                <h3 class="explorer-section-head__title">پست خودکار</h3>
                <p id="autoPostCount" class="explorer-section-head__sub">در حال بارگذاری...</p>
              </div>
            </header>
            <div class="explorer-bar">
              <button id="btnAutoPostUp" class="explorer-bar__up" type="button" hidden aria-label="بازگشت">
                <i class="fa-solid fa-arrow-right"></i>
              </button>
              <p id="autoPostBreadcrumb" class="explorer-bar__path">صفحه اصلی</p>
              <div class="explorer-bar__actions">
                <button id="btnNewAutoPostFolder" class="explorer-bar__action" type="button">
                  <i class="fa-solid fa-folder-plus"></i>
                  پوشه جدید
                </button>
                <button id="btnNewPostSession" class="explorer-bar__action explorer-bar__action--primary" type="button">
                  <i class="fa-solid fa-layer-group"></i>
                  سشن پست
                </button>
              </div>
            </div>
            <div id="autoPostList" class="explorer-grid">
              <div class="explorer-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>در حال دریافت لیست...</span>
              </div>
            </div>
            <p class="hint-text explorer-hint">
              <i class="fa-solid fa-circle-info"></i>
              سشن پست = پوشه کانال + پوشه ربات · لمس کوتاه برای آمار · نگه دارید برای منو
            </p>
          </article>

          <article class="glass-card panel-card panel-card--explorer panel-card--explorer-tools">
            <header class="explorer-section-head">
              <span class="explorer-section-head__icon explorer-section-head__icon--tools" aria-hidden="true"><i class="fa-solid fa-screwdriver-wrench"></i></span>
              <div>
                <h3 class="explorer-section-head__title">ابزار</h3>
                <p id="toolsExplorerCount" class="explorer-section-head__sub">در حال بارگذاری...</p>
              </div>
            </header>
            <div id="hashtagToolsList" class="explorer-grid">
              <div class="explorer-loading">
                <i class="fa-solid fa-spinner fa-spin"></i>
                <span>در حال دریافت لیست...</span>
              </div>
            </div>
            <p class="hint-text explorer-hint">
              <i class="fa-solid fa-circle-info"></i>
              افزونه‌های کاربردی · لمس کوتاه برای مدیریت · هشتگ و بنر در پست خودکار
            </p>
          </article>
        </section>

        <div id="folderDetailsModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-folder-details></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="folderDetailsTitle">
            <h3 id="folderDetailsTitle" class="modal__title">جزئیات پوشه</h3>
            <div class="folder-details">
              <div class="folder-details__head">
                <span id="folderDetailsIcon" class="folder-details__icon" aria-hidden="true"><i class="fa-solid fa-folder"></i></span>
                <p id="folderDetailsName" class="folder-details__name">—</p>
              </div>
              <dl class="folder-details__list">
                <div class="folder-details__row">
                  <dt>تعداد کانال‌ها</dt>
                  <dd id="folderDetailsChannels">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>مجموع اعضا</dt>
                  <dd id="folderDetailsMembers">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>تاریخ ساخت</dt>
                  <dd id="folderDetailsCreated">—</dd>
                </div>
              </dl>
            </div>
            <div class="modal__actions">
              <button type="button" class="btn btn--primary btn--block" data-close-folder-details>بستن</button>
            </div>
          </div>
        </div>

        <div id="contentGroupFolderModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-content-group-folder-modal></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="contentGroupFolderModalTitle">
            <h3 id="contentGroupFolderModalTitle" class="modal__title">پوشه جدید</h3>
            <label class="field">
              <span class="field__label">نام پوشه</span>
              <input id="contentGroupFolderNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً آرشیو فیلم" />
            </label>
            <p class="field__label">آیکون</p>
            <div id="contentGroupFolderIconPicker" class="icon-picker"></div>
            <div class="modal__actions">
              <button type="button" class="btn btn--ghost" data-close-content-group-folder-modal>انصراف</button>
              <button id="btnSaveContentGroupFolder" type="button" class="btn btn--primary">ذخیره</button>
            </div>
          </div>
        </div>

        <div id="contentGroupDetailsModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-content-group-details></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="contentGroupDetailsTitle">
            <h3 id="contentGroupDetailsTitle" class="modal__title">جزئیات گروه</h3>
            <div class="folder-details">
              <div class="folder-details__head">
                <span id="contentGroupDetailsIcon" class="folder-details__icon explorer-tile__icon" aria-hidden="true">G</span>
                <div class="folder-details__head-text">
                  <p id="contentGroupDetailsName" class="folder-details__name">—</p>
                  <p id="contentGroupDetailsMeta" class="folder-details__meta">—</p>
                </div>
              </div>
              <dl class="folder-details__list">
                <div class="folder-details__row">
                  <dt>تعداد پیام</dt>
                  <dd id="contentGroupDetailsMessages">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>تعداد عکس</dt>
                  <dd id="contentGroupDetailsPhotos">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>تعداد ویدیو</dt>
                  <dd id="contentGroupDetailsVideos">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>اعضا</dt>
                  <dd id="contentGroupDetailsMembers">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>آخرین پیام</dt>
                  <dd id="contentGroupDetailsLastMessage">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>تاریخ ثبت</dt>
                  <dd id="contentGroupDetailsAdded">—</dd>
                </div>
              </dl>
            </div>
            <div class="modal__actions">
              <button type="button" class="btn btn--primary btn--block" data-close-content-group-details>بستن</button>
            </div>
          </div>
        </div>

        <div id="autoPostFolderModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-auto-post-folder-modal></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="autoPostFolderModalTitle">
            <h3 id="autoPostFolderModalTitle" class="modal__title">پوشه جدید</h3>
            <label class="field">
              <span class="field__label">نام پوشه</span>
              <input id="autoPostFolderNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً کمپین هفتگی" />
            </label>
            <p class="field__label">آیکون</p>
            <div id="autoPostFolderIconPicker" class="icon-picker"></div>
            <div class="modal__actions">
              <button type="button" class="btn btn--ghost" data-close-auto-post-folder-modal>انصراف</button>
              <button id="btnSaveAutoPostFolder" type="button" class="btn btn--primary">ذخیره</button>
            </div>
          </div>
        </div>

        <div id="postSessionRenameModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-post-session-rename></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="postSessionRenameModalTitle">
            <h3 id="postSessionRenameModalTitle" class="modal__title">تغییر نام سشن</h3>
            <label class="field">
              <span class="field__label">نام سشن</span>
              <input id="postSessionRenameInput" class="field__input" type="text" maxlength="160" placeholder="نام سشن پست" />
            </label>
            <div class="modal__actions">
              <button type="button" class="btn btn--ghost" data-close-post-session-rename>انصراف</button>
              <button id="btnSavePostSessionRename" type="button" class="btn btn--primary">ذخیره</button>
            </div>
          </div>
        </div>

        <div id="postSessionModal" class="modal modal--scroll" hidden>
          <div class="modal__backdrop" data-close-post-session-modal></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="postSessionModalTitle">
            <h3 id="postSessionModalTitle" class="modal__title">سشن پست جدید</h3>
            <p class="hint-text">پوشه کانال (فقط اخلاقی / غیراخلاقی) و پوشه ربات کانال را انتخاب کنید.</p>
            <label class="field">
              <span class="field__label">نام سشن (اختیاری)</span>
              <input id="postSessionNameInput" class="field__input" type="text" maxlength="120" placeholder="خودکار از نام پوشه‌ها ساخته می‌شود" />
            </label>
            <label class="field">
              <span class="field__label">پوشه کانال (الزامی)</span>
              <button id="postSessionChannelFolderBtn" type="button" class="folder-pick-btn">
                <i class="fa-solid fa-folder-tree"></i>
                <span id="postSessionChannelFolderLabel">— انتخاب پوشه کانال —</span>
              </button>
              <input id="postSessionChannelFolderInput" type="hidden" value="" />
            </label>
            <label class="field">
              <span class="field__label">پوشه ربات (الزامی)</span>
              <button id="postSessionBotFolderBtn" type="button" class="folder-pick-btn">
                <i class="fa-solid fa-robot"></i>
                <span id="postSessionBotFolderLabel">— انتخاب پوشه ربات —</span>
              </button>
              <input id="postSessionBotFolderInput" type="hidden" value="" />
            </label>
            <div class="modal__actions">
              <button type="button" class="btn btn--ghost" data-close-post-session-modal>انصراف</button>
              <button id="btnSavePostSession" type="button" class="btn btn--primary">ایجاد سشن</button>
            </div>
          </div>
        </div>

        <div id="postSessionStatsModal" class="modal modal--scroll" hidden>
          <div class="modal__backdrop" data-close-post-session-stats></div>
          <div class="modal__card glass-card modal__card--wide" role="dialog" aria-labelledby="postSessionStatsTitle">
            <div class="post-session-stats-head">
              <span class="post-session-stats-head__icon" aria-hidden="true"><i class="fa-solid fa-layer-group"></i></span>
              <div>
                <h3 id="postSessionStatsTitle" class="modal__title">آمار سشن</h3>
                <p id="postSessionStatsSubtitle" class="post-session-stats-head__sub">—</p>
              </div>
            </div>
            <div id="postSessionStatsKpis" class="post-session-kpis"></div>
            <div class="post-session-stats-grid">
              <section class="post-session-stats-block">
                <h4 class="post-session-stats-block__title"><i class="fa-solid fa-tower-broadcast"></i> کانال‌ها</h4>
                <dl class="folder-details__list">
                  <div class="folder-details__row">
                    <dt>پوشه کانال</dt>
                    <dd id="postSessionStatsChannelPath">—</dd>
                  </div>
                  <div class="folder-details__row">
                    <dt>تعداد کانال</dt>
                    <dd id="postSessionStatsChannelCount">—</dd>
                  </div>
                  <div class="folder-details__row">
                    <dt>مجموع اعضا</dt>
                    <dd id="postSessionStatsChannelMembers">—</dd>
                  </div>
                  <div class="folder-details__row">
                    <dt>رشد ۲۴ ساعت</dt>
                    <dd id="postSessionStatsChannelGrowth">—</dd>
                  </div>
                </dl>
              </section>
              <section class="post-session-stats-block">
                <h4 class="post-session-stats-block__title"><i class="fa-solid fa-robot"></i> ربات‌ها</h4>
                <dl class="folder-details__list">
                  <div class="folder-details__row">
                    <dt>پوشه ربات</dt>
                    <dd id="postSessionStatsBotPath">—</dd>
                  </div>
                  <div class="folder-details__row">
                    <dt>آپلودر / محافظ</dt>
                    <dd id="postSessionStatsBotTypes">—</dd>
                  </div>
                  <div class="folder-details__row">
                    <dt>کاربران یکتا</dt>
                    <dd id="postSessionStatsBotUsers">—</dd>
                  </div>
                  <div class="folder-details__row">
                    <dt>کاربر جدید ۲۴س</dt>
                    <dd id="postSessionStatsBotGrowth">—</dd>
                  </div>
                </dl>
              </section>
            </div>
            <div id="postSessionStatsLists" class="post-session-stats-lists"></div>
            <div class="modal__actions">
              <button type="button" class="btn btn--primary btn--block" data-close-post-session-stats>بستن</button>
            </div>
          </div>
        </div>

        <div id="botFolderModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-bot-folder-modal></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="botFolderModalTitle">
            <h3 id="botFolderModalTitle" class="modal__title">پوشه جدید</h3>
            <label class="field">
              <span class="field__label">نام پوشه</span>
              <input id="botFolderNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً ربات‌های آپلود" />
            </label>
            <p class="field__label">آیکون</p>
            <div id="botFolderIconPicker" class="icon-picker"></div>
            <div class="modal__actions">
              <button type="button" class="btn btn--ghost" data-close-bot-folder-modal>انصراف</button>
              <button id="btnSaveBotFolder" type="button" class="btn btn--primary">ذخیره</button>
            </div>
          </div>
        </div>

        <div id="botFolderDetailsModal" class="modal modal--scroll" hidden>
          <div class="modal__backdrop" data-close-bot-folder-details></div>
          <div class="modal__card glass-card modal__card--wide" role="dialog" aria-labelledby="botFolderDetailsTitle">
            <h3 id="botFolderDetailsTitle" class="modal__title">جزئیات پوشه</h3>
            <div class="folder-details">
              <div class="folder-details__head">
                <span id="botFolderDetailsIcon" class="folder-details__icon" aria-hidden="true"><i class="fa-solid fa-folder"></i></span>
                <p id="botFolderDetailsName" class="folder-details__name">—</p>
              </div>
              <dl class="folder-details__list">
                <div class="folder-details__row">
                  <dt>تعداد ربات‌ها</dt>
                  <dd id="botFolderDetailsBots">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>مجموع کاربران</dt>
                  <dd id="botFolderDetailsUsers">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>رشد ۲۴ ساعت</dt>
                  <dd id="botFolderDetailsGrowth">—</dd>
                </div>
                <div class="folder-details__row">
                  <dt>تاریخ ساخت</dt>
                  <dd id="botFolderDetailsCreated">—</dd>
                </div>
              </dl>
            </div>
            <section class="folder-details__section">
              <h4 class="folder-details__section-title">ربات‌های پوشه</h4>
              <div id="botFolderDetailsBotsList" class="folder-details__bots-list">
                <p class="folder-details__empty">در حال بارگذاری...</p>
              </div>
            </section>
            <div class="modal__actions">
              <button type="button" class="btn btn--primary btn--block" data-close-bot-folder-details>بستن</button>
            </div>
          </div>
        </div>

        <div id="addBotModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-add-bot></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="addBotModalTitle">
            <h3 id="addBotModalTitle" class="modal__title">افزودن ربات</h3>
            <label class="field">
              <span class="field__label">توکن ربات</span>
              <input id="addBotTokenInput" class="field__input" type="text" autocomplete="off" placeholder="123456789:ABCdefGHI..." />
              <p class="field__hint">نام ربات خودکار از تلگرام (BotFather) خوانده می‌شود.</p>
            </label>
            <div class="field">
              <span class="field__label">پوشه کانال (الزامی)</span>
              <input id="addBotChannelFolderInput" type="hidden" value="" />
              <button id="addBotChannelFolderBtn" type="button" class="folder-pick-btn" aria-haspopup="dialog">
                <span class="folder-pick-btn__main">
                  <i class="fa-solid fa-folder-tree folder-pick-btn__icon" aria-hidden="true"></i>
                  <span id="addBotChannelFolderLabel" class="folder-pick-btn__label">— انتخاب پوشه کانال —</span>
                </span>
                <i class="fa-solid fa-chevron-down folder-pick-btn__chev" aria-hidden="true"></i>
              </button>
              <p id="addBotChannelFolderHint" class="field__hint" hidden>ابتدا در بخش بالا یک «پوشه جدید» برای کانال‌ها بسازید.</p>
            </div>
            <div class="field">
              <span class="field__label">نوع ربات</span>
              <div id="addBotTypeTabs" class="bot-type-tabs bot-type-tabs--dual" role="tablist" aria-label="نوع ربات">
                <button type="button" class="bot-type-tab is-active" data-bot-type="uploader" role="tab" aria-selected="true">مستراپلودر v1.0</button>
                <button type="button" class="bot-type-tab" data-bot-type="guardian" role="tab" aria-selected="false">مسترمحافظ v1.0</button>
              </div>
            </div>
            <div class="modal__actions">
              <button type="button" class="btn btn--ghost" data-close-add-bot>انصراف</button>
              <button id="btnSaveAddBot" type="button" class="btn btn--primary">افزودن</button>
            </div>
          </div>
        </div>

        <div id="folderModal" class="modal" hidden>
          <div class="modal__backdrop" data-close-modal></div>
          <div class="modal__card glass-card" role="dialog" aria-labelledby="folderModalTitle">
            <h3 id="folderModalTitle" class="modal__title">پوشه جدید</h3>
            <label class="field">
              <span class="field__label">نام پوشه</span>
              <input id="folderNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً کانال‌های تبلیغاتی" />
            </label>
            <p class="field__label">آیکون</p>
            <div id="folderIconPicker" class="icon-picker"></div>
            <div class="modal__actions">
              <button type="button" class="btn btn--ghost" data-close-modal>انصراف</button>
              <button id="btnSaveFolder" type="button" class="btn btn--primary">ذخیره</button>
            </div>
          </div>
        </div>

        <!-- جزئیات کانال -->
        <section id="screenChannelDetail" class="screen screen--detail" aria-label="آمار کانال" hidden>
          <header class="topbar topbar--detail">
            <button id="btnChannelBack" class="topbar__back" type="button" aria-label="بازگشت">
              <i class="fa-solid fa-arrow-right"></i>
            </button>
            <div class="topbar__brand">
              <div id="detailAvatar" class="channel-avatar channel-avatar--sm">C</div>
              <div>
                <h2 id="detailTitle" class="topbar__title">کانال</h2>
                <p id="detailMeta" class="topbar__sub">—</p>
              </div>
            </div>
          </header>

          <nav class="channel-subnav" aria-label="بخش‌های مدیریت کانال">
            <button type="button" class="channel-subnav__btn is-active" data-channel-tab="overview">
              <i class="fa-solid fa-gauge-high"></i>
              <span>نگاه کلی</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-channel-tab="stats">
              <i class="fa-solid fa-chart-line"></i>
              <span>آمار</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-channel-tab="invites">
              <i class="fa-solid fa-link"></i>
              <span>لینک عضویت</span>
            </button>
          </nav>

          <div id="channelPanelOverview" class="channel-panel is-active">
          <div class="stats-grid">
            <article class="stat-card">
              <span class="stat-card__label">اعضا</span>
              <strong id="detailMembers" class="stat-card__value">—</strong>
            </article>
            <article class="stat-card">
              <span class="stat-card__label">رشد</span>
              <strong id="detailGrowth" class="stat-card__value">—</strong>
            </article>
            <article class="stat-card">
              <span class="stat-card__label">میانگین ویو</span>
              <strong id="detailAvgViews" class="stat-card__value">—</strong>
            </article>
            <article class="stat-card">
              <span class="stat-card__label">پست‌های ثبت‌شده</span>
              <strong id="detailPosts" class="stat-card__value">—</strong>
            </article>
          </div>

          <article class="glass-card panel-card">
            <h3 class="panel-card__title"><i class="fa-solid fa-pen-to-square"></i> پروفایل کانال</h3>
            <div id="channelProfileEditor" class="bot-profile-editor">
              <div class="bot-profile-photo-row">
                <div id="channelProfilePhotoPreview" class="bot-profile-photo">
                  <span id="channelProfilePhotoPlaceholder">C</span>
                </div>
                <div class="bot-profile-photo-actions">
                  <button id="btnChannelProfilePickPhoto" type="button" class="btn btn--ghost btn--sm">
                    <i class="fa-solid fa-camera"></i>
                    تغییر عکس
                  </button>
                  <button id="btnChannelProfileRemovePhoto" type="button" class="btn btn--ghost btn--sm">
                    <i class="fa-solid fa-trash"></i>
                    حذف عکس
                  </button>
                  <input id="channelProfilePhotoInput" type="file" accept="image/jpeg,image/png,image/webp" hidden />
                </div>
              </div>
              <label class="field">
                <span class="field__label">نام کانال</span>
                <input id="channelProfileTitleInput" class="field__input" type="text" maxlength="128" placeholder="نام نمایشی کانال" />
              </label>
              <p id="channelProfileStatus" class="hint-text" hidden></p>
              <button id="btnSaveChannelProfile" type="button" class="btn btn--primary btn--block">
                <i class="fa-solid fa-floppy-disk"></i>
                ذخیره پروفایل کانال
              </button>
            </div>
          </article>

          <article class="glass-card panel-card">
            <h3 class="panel-card__title"><i class="fa-solid fa-link"></i> اطلاعات کانال</h3>
            <div class="profile-grid profile-grid--single">
              <div class="profile-item">
                <span class="profile-item__label">شناسه کانال</span>
                <span id="detailChatId" class="profile-item__value">—</span>
              </div>
              <div class="profile-item">
                <span class="profile-item__label">لینک</span>
                <span id="detailLink" class="profile-item__value">—</span>
              </div>
            </div>
            <button id="btnCopyChannelLink" class="btn btn--primary btn--block panel-card__action" type="button" hidden>
              <i class="fa-solid fa-copy"></i>
              کپی لینک کانال
            </button>
          </article>
          </div>

          <div id="channelPanelStats" class="channel-panel" hidden>
            <div class="dash-kpis dash-kpis--channel">
              <article class="dash-kpi">
                <span class="dash-kpi__label">عضویت · ۱ ساعت</span>
                <strong id="channelKpiJoins1h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi">
                <span class="dash-kpi__label">عضویت · ۲۴ ساعت</span>
                <strong id="channelKpiJoins24h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi dash-kpi--accent">
                <span class="dash-kpi__label">اعضای فعلی</span>
                <strong id="channelKpiMembers" class="dash-kpi__value">—</strong>
              </article>
            </div>
            <div class="dash-charts dash-charts--channel">
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند عضویت اخیر</h4>
                  <span id="channelJoinsRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartChannelJoins" aria-label="نمودار ساعتی عضویت کانال"></canvas>
                </div>
              </section>
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند تعداد اعضا</h4>
                  <span id="channelMembersRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartMembers" aria-label="نمودار رشد اعضا"></canvas>
                </div>
              </section>
              <section class="dash-chart-card">
                <header class="dash-chart-card__head">
                  <h4>ویو پست‌های اخیر</h4>
                  <span class="dash-chart-card__sub">تا ۲۰ پست آخر</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-bar">
                  <canvas id="chartViews" aria-label="نمودار ویو پست‌ها"></canvas>
                </div>
              </section>
              <section class="dash-chart-card">
                <header class="dash-chart-card__head">
                  <h4>منبع عضویت</h4>
                  <span class="dash-chart-card__sub">بر اساس رویداد join</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-donut">
                  <canvas id="chartSources" aria-label="نمودار منابع ورود"></canvas>
                </div>
                <p id="detailSourcesHint" class="hint-text dash-chart-card__hint"></p>
              </section>
            </div>
          </div>

          <div id="channelPanelInvites" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-plus"></i> ساخت لینک عضویت جدید</h3>
              <label class="field">
                <span class="field__label">نام لینک (برای آمار)</span>
                <input id="inviteLinkName" class="field__input" type="text" maxlength="32" placeholder="مثلاً اینستاگرام، تبلیغ ۱" />
              </label>
              <label class="field">
                <span class="field__label">سقف عضو (اختیاری)</span>
                <input id="inviteLinkLimit" class="field__input" type="number" min="1" placeholder="بدون محدودیت" />
              </label>
              <button id="btnCreateInviteLink" class="btn btn--primary btn--block" type="button">
                <i class="fa-solid fa-wand-magic-sparkles"></i>
                ساخت لینک توسط ربات
              </button>
            </article>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-list"></i> لینک‌های ساخته‌شده</h3>
              <div id="inviteLinksList" class="invite-links-list">
                <p class="channel-empty__sub">هنوز لینکی ساخته نشده</p>
              </div>
            </article>

            <article class="glass-card panel-card">
              <p class="hint-text">
                <i class="fa-solid fa-circle-info"></i>
                تعداد join و leave از رویدادهای تلگرام ثبت می‌شود. اگر تلگرام لینک را اعلام نکند، سیستم لینک را حدس می‌زند یا «نامشخص» می‌زند.
              </p>
            </article>
          </div>
        </section>

        <!-- جزئیات ربات -->
        <section id="screenBotDetail" class="screen screen--detail" aria-label="آمار ربات" hidden>
          <header class="topbar topbar--detail">
            <button id="btnBotBack" class="topbar__back" type="button" aria-label="بازگشت">
              <i class="fa-solid fa-arrow-right"></i>
            </button>
            <div class="topbar__brand">
              <div id="botDetailAvatar" class="channel-avatar channel-avatar--sm channel-avatar--bot">B</div>
              <div class="topbar__text">
                <h2 id="botDetailTitle" class="topbar__title">ربات</h2>
                <div id="botDetailMeta" class="topbar__sub topbar__sub--bot">
                  <span id="botDetailVersionBadge" class="topbar__badge-type">—</span>
                  <span id="botDetailHandle" class="topbar__handle">—</span>
                </div>
              </div>
            </div>
          </header>

          <nav class="channel-subnav channel-subnav--three" aria-label="بخش‌های ربات">
            <button type="button" class="channel-subnav__btn is-active" data-bot-tab="overview">
              <i class="fa-solid fa-gauge-high"></i>
              <span>نگاه کلی</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-bot-tab="stats">
              <i class="fa-solid fa-chart-line"></i>
              <span>آمار</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-bot-tab="settings">
              <i class="fa-solid fa-sliders"></i>
              <span>تنظیمات</span>
            </button>
          </nav>

          <div id="botPanelOverview" class="channel-panel is-active">
            <div class="stats-grid">
              <article class="stat-card">
                <span class="stat-card__label">کاربران</span>
                <strong id="botDetailUsers" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">رشد ۲۴س</span>
                <strong id="botDetailGrowth" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">فعال ۲۴س</span>
                <strong id="botDetailActive" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">آپلودها</span>
                <strong id="botDetailUploads" class="stat-card__value">—</strong>
              </article>
            </div>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-robot"></i> مشخصات ربات</h3>
              <div class="profile-grid profile-grid--single">
                <div class="profile-item">
                  <span class="profile-item__label">شناسه ربات (داخلی)</span>
                  <span id="botDetailId" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">شناسه تلگرام ربات</span>
                  <span id="botDetailTelegramId" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">نوع / نسخه</span>
                  <span id="botDetailVersion" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">پوشه کانال</span>
                  <span id="botDetailChannelFolder" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">تاریخ ساخت</span>
                  <span id="botDetailCreated" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">لینک</span>
                  <span id="botDetailLink" class="profile-item__value">—</span>
                </div>
              </div>
              <button id="btnCopyBotLink" class="btn btn--primary btn--block panel-card__action" type="button" hidden>
                <i class="fa-solid fa-copy"></i>
                کپی لینک ربات
              </button>
            </article>
          </div>

          <div id="botPanelStats" class="channel-panel" hidden>
            <div class="dash-kpis dash-kpis--channel">
              <article class="dash-kpi">
                <span class="dash-kpi__label">کاربر جدید · ۱ ساعت</span>
                <strong id="botKpiJoins1h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi">
                <span class="dash-kpi__label">کاربر جدید · ۲۴ ساعت</span>
                <strong id="botKpiJoins24h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi dash-kpi--accent">
                <span class="dash-kpi__label">کل کاربران</span>
                <strong id="botKpiUsers" class="dash-kpi__value">—</strong>
              </article>
            </div>
            <div class="dash-charts dash-charts--channel">
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند عضویت اخیر</h4>
                  <span id="botJoinsRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartBotJoins" aria-label="نمودار ساعتی کاربران جدید"></canvas>
                </div>
              </section>
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند مجموع کاربران</h4>
                  <span id="botUsersRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartBotUsers" aria-label="نمودار مجموع کاربران"></canvas>
                </div>
              </section>
            </div>
            <p class="hint-text dash-chart-card__hint">
              <i class="fa-solid fa-circle-info"></i>
              کاربران جذب‌شده وقتی ثبت می‌شوند که برای اولین بار با ربات تعامل کنند (ارسال /start یا پیام).
            </p>
          </div>

          <div id="botPanelSettings" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-id-card"></i> پروفایل ربات</h3>
              <p class="hint-text">نام، بیو و عکس پروفایل را مستقیم در تلگرام به‌روزرسانی کنید — بدون BotFather.</p>
              <div id="botProfileEditor" class="bot-profile-editor">
                <div class="bot-profile-editor__head">
                  <div class="bot-profile-editor__photo-wrap">
                    <img id="botProfilePhotoPreview" class="bot-profile-editor__photo" alt="" hidden />
                    <div id="botProfilePhotoPlaceholder" class="bot-profile-editor__photo bot-profile-editor__photo--placeholder" aria-hidden="true">
                      <i class="fa-solid fa-robot"></i>
                    </div>
                  </div>
                  <div class="bot-profile-editor__photo-actions">
                    <input id="botProfilePhotoInput" type="file" accept="image/jpeg,image/png,image/webp" hidden />
                    <button id="btnBotProfilePhotoPick" type="button" class="btn btn--ghost btn--sm">
                      <i class="fa-solid fa-camera"></i>
                      تغییر عکس
                    </button>
                    <button id="btnBotProfilePhotoDelete" type="button" class="btn btn--ghost btn--sm btn--danger" hidden>
                      <i class="fa-solid fa-trash"></i>
                      حذف عکس
                    </button>
                  </div>
                </div>

                <label class="form-field">
                  <span class="form-field__label">نام نمایشی ربات</span>
                  <input id="botProfileNameInput" class="form-field__input" type="text" maxlength="64" placeholder="مثلاً تست" />
                </label>

                <label class="form-field">
                  <span class="form-field__label">بیو / About (حداکثر ۵۱۲ کاراکتر)</span>
                  <textarea id="botProfileDescInput" class="form-field__input form-field__textarea" maxlength="512" rows="4" placeholder="توضیحات پروفایل ربات در تلگرام"></textarea>
                  <span id="botProfileDescCount" class="form-field__hint">۰ / ۵۱۲</span>
                </label>

                <label class="form-field">
                  <span class="form-field__label">توضیح کوتاه (لینک اشتراک · حداکثر ۱۲۰ کاراکتر)</span>
                  <textarea id="botProfileShortDescInput" class="form-field__input form-field__textarea" maxlength="120" rows="2" placeholder="متن کوتاه هنگام اشتراک‌گذاری ربات"></textarea>
                  <span id="botProfileShortDescCount" class="form-field__hint">۰ / ۱۲۰</span>
                </label>

                <button id="btnSaveBotProfile" type="button" class="btn btn--primary btn--block panel-card__action">
                  <i class="fa-solid fa-floppy-disk"></i>
                  ذخیره پروفایل
                </button>
                <p id="botProfileStatus" class="hint-text bot-profile-editor__status" hidden></p>
              </div>
            </article>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-user-lock"></i> جوین اجباری</h3>
              <p class="hint-text">کاربر باید عضو کانال/گروه واقعی شود تا از ربات استفاده کند.</p>
              <div id="botForcedJoinsList" class="join-rules-list">
                <p class="channel-empty__sub">در حال بارگذاری...</p>
              </div>
              <button id="btnAddBotForcedJoin" type="button" class="btn btn--ghost btn--sm panel-card__action">
                <i class="fa-solid fa-plus"></i>
                افزودن جوین اجباری
              </button>
            </article>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-mask"></i> جوین فیک</h3>
              <p class="hint-text">کاربر فقط روی دکمه می‌زند؛ عضویت واقعی بررسی نمی‌شود.</p>
              <div id="botFakeJoinsList" class="join-rules-list">
                <p class="channel-empty__sub">در حال بارگذاری...</p>
              </div>
              <button id="btnAddBotFakeJoin" type="button" class="btn btn--ghost btn--sm panel-card__action">
                <i class="fa-solid fa-plus"></i>
                افزودن جوین فیک
              </button>
            </article>
          </div>
        </section>

        <!-- جزئیات افزونه هشتگ پست -->
        <section id="screenHashtagSetDetail" class="screen screen--detail" aria-label="جزئیات هشتگ پست" hidden>
          <header class="topbar topbar--detail">
            <button id="btnHashtagSetBack" class="topbar__back" type="button" aria-label="بازگشت">
              <i class="fa-solid fa-arrow-right"></i>
            </button>
            <div class="topbar__brand">
              <div id="hashtagSetDetailAvatar" class="channel-avatar channel-avatar--sm channel-avatar--hashtag">
                <i class="fa-solid fa-hashtag"></i>
              </div>
              <div class="topbar__text">
                <h2 id="hashtagSetDetailTitle" class="topbar__title">هشتگ پست</h2>
                <p id="hashtagSetDetailMeta" class="topbar__sub">—</p>
              </div>
            </div>
          </header>

          <nav class="channel-subnav channel-subnav--three" aria-label="بخش‌های هشتگ">
            <button type="button" class="channel-subnav__btn is-active" data-hashtag-set-tab="overview">
              <i class="fa-solid fa-gauge-high"></i>
              <span>نگاه کلی</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-hashtag-set-tab="tags">
              <i class="fa-solid fa-hashtag"></i>
              <span>هشتگ‌ها</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-hashtag-set-tab="settings">
              <i class="fa-solid fa-sliders"></i>
              <span>تنظیمات</span>
            </button>
          </nav>

          <div id="hashtagSetPanelOverview" class="channel-panel is-active">
            <div class="stats-grid">
              <article class="stat-card">
                <span class="stat-card__label">پیکربندی‌ها</span>
                <strong id="hashtagSetDetailConfigCount" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">هشتگ‌ها</span>
                <strong id="hashtagSetDetailTagCount" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">اجباری</span>
                <strong id="hashtagSetDetailRequiredCount" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">حالت انتخاب</span>
                <strong id="hashtagSetDetailModeLabel" class="stat-card__value">—</strong>
              </article>
            </div>

            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-folder-tree"></i> پوشه کانال فعال</h3>
                <button id="btnHashtagAddChannelFolder" type="button" class="btn btn--ghost btn--sm panel-card__action">
                  <i class="fa-solid fa-plus"></i>
                  افزودن
                </button>
              </div>
              <p id="hashtagActiveConfigLabel" class="hashtag-active-config">—</p>
              <div id="hashtagPluginConfigsList" class="hashtag-configs-list"></div>
            </article>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-eye"></i> پیش‌نمایش هشتگ‌ها</h3>
              <div id="hashtagSetDetailPreview" class="hashtag-tags-preview"></div>
              <p class="hint-text">
                <i class="fa-solid fa-circle-info"></i>
                هنگام پست خودکار، هشتگ‌های انتخاب‌شده با دو خط فاصله زیر کپشن کانال اضافه می‌شوند.
              </p>
            </article>
          </div>

          <div id="hashtagSetPanelTags" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <p id="hashtagTagsConfigBanner" class="hashtag-active-config">پیکربندی: —</p>
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-hashtag"></i> مدیریت هشتگ‌ها</h3>
                <button id="btnHashtagTagsAddTag" type="button" class="btn btn--ghost btn--sm panel-card__action">
                  <i class="fa-solid fa-plus"></i>
                  افزودن هشتگ
                </button>
              </div>
              <p class="hint-text">برای حالت هوشمند: کلیدواژه‌ها را با کاما جدا کنید.</p>
              <div id="hashtagTagsEditorList" class="hashtag-tags-list"></div>
              <button id="btnSaveHashtagTags" type="button" class="btn btn--primary btn--block panel-card__action">
                <i class="fa-solid fa-floppy-disk"></i>
                ذخیره هشتگ‌ها
              </button>
              <p id="hashtagTagsSaveStatus" class="hint-text" hidden></p>
            </article>
          </div>

          <div id="hashtagSetPanelSettings" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-pen-to-square"></i> تنظیمات پیکربندی</h3>
              <label class="field">
                <span class="field__label">نام پیکربندی</span>
                <input id="hashtagSetSettingsNameInput" class="field__input" type="text" maxlength="160" placeholder="مثلاً هشتگ‌های غیراخلاقی" />
              </label>
              <label class="field">
                <span class="field__label">پوشه کانال مرتبط</span>
                <button id="hashtagSetSettingsChannelFolderBtn" type="button" class="folder-pick-btn">
                  <i class="fa-solid fa-folder-tree"></i>
                  <span id="hashtagSetSettingsChannelFolderLabel">— انتخاب پوشه کانال —</span>
                </button>
                <input id="hashtagSetSettingsChannelFolderInput" type="hidden" value="" />
              </label>
              <p class="field__label">حالت انتخاب هشتگ</p>
              <div class="radio-row">
                <label class="radio-pill">
                  <input type="radio" name="hashtagSetSettingsMode" value="random" checked />
                  <span>تصادفی</span>
                </label>
                <label class="radio-pill">
                  <input type="radio" name="hashtagSetSettingsMode" value="all" />
                  <span>همه</span>
                </label>
                <label class="radio-pill">
                  <input type="radio" name="hashtagSetSettingsMode" value="smart" />
                  <span>هوشمند</span>
                </label>
              </div>
              <label class="field">
                <span class="field__label">تعداد تصادفی</span>
                <input id="hashtagSetSettingsRandomCountInput" class="field__input" type="number" min="1" max="50" value="5" dir="ltr" />
              </label>
              <button id="btnSaveHashtagSetSettings" type="button" class="btn btn--primary btn--block panel-card__action">
                <i class="fa-solid fa-floppy-disk"></i>
                ذخیره تنظیمات
              </button>
              <p id="hashtagSetSettingsStatus" class="hint-text" hidden></p>
            </article>
          </div>
        </section>

        <!-- جزئیات افزونه عکس بنر -->
        <section id="screenBannerDetail" class="screen screen--detail" aria-label="عکس بنر" hidden>
          <header class="topbar topbar--detail">
            <button id="btnBannerBack" class="topbar__back" type="button" aria-label="بازگشت">
              <i class="fa-solid fa-arrow-right"></i>
            </button>
            <div class="topbar__brand">
              <div class="channel-avatar channel-avatar--sm channel-avatar--banner">
                <i class="fa-solid fa-panorama"></i>
              </div>
              <div class="topbar__text">
                <h2 id="bannerDetailTitle" class="topbar__title">عکس بنر</h2>
                <p id="bannerDetailMeta" class="topbar__sub">—</p>
              </div>
            </div>
          </header>

          <nav class="channel-subnav channel-subnav--three" aria-label="بخش‌های بنر">
            <button type="button" class="channel-subnav__btn is-active" data-banner-tab="overview">
              <i class="fa-solid fa-gauge-high"></i>
              <span>نگاه کلی</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-banner-tab="groups">
              <i class="fa-solid fa-users"></i>
              <span>گروه‌های بنر</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-banner-tab="settings">
              <i class="fa-solid fa-sliders"></i>
              <span>تنظیمات</span>
            </button>
          </nav>

          <div id="bannerPanelOverview" class="channel-panel is-active">
            <div class="stats-grid">
              <article class="stat-card">
                <span class="stat-card__label">گروه‌های بنر</span>
                <strong id="bannerStatGroupCount" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">عکس‌های بنر</span>
                <strong id="bannerStatPhotoCount" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">پوشه فعال</span>
                <strong id="bannerStatBindingCount" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">بیو گروه</span>
                <strong id="bannerStatBioMarker" class="stat-card__value">02</strong>
              </article>
            </div>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-circle-info"></i> نحوه کار</h3>
              <p class="hint-text">
                گروه‌هایی که بیو (توضیحات) آن‌ها <strong>02</strong> است، منبع عکس بنر هستند.
                وقتی پوشه کانال در تنظیمات فعال باشد و سشن پست خودکار داشته باشد،
                در کانال به‌جای محتوای اصلی، یک عکس بنر تصادفی + کپشن محتوا + دکمه دریافت ارسال می‌شود.
              </p>
              <button id="btnBannerSyncGroups" type="button" class="btn btn--ghost btn--sm panel-card__action">
                <i class="fa-solid fa-rotate"></i>
                همگام‌سازی بیو گروه‌ها
              </button>
            </article>
          </div>

          <div id="bannerPanelGroups" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-users"></i> گروه‌های با بیو 02</h3>
                <button id="btnBannerSyncGroups2" type="button" class="btn btn--ghost btn--sm panel-card__action">
                  <i class="fa-solid fa-rotate"></i>
                  بروزرسانی
                </button>
              </div>
              <div id="bannerGroupsList" class="hashtag-configs-list">
                <p class="channel-empty__sub">در حال بارگذاری...</p>
              </div>
            </article>
          </div>

          <div id="bannerPanelSettings" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-folder-tree"></i> پوشه‌های کانال</h3>
                <button id="btnBannerAddFolder" type="button" class="btn btn--ghost btn--sm panel-card__action">
                  <i class="fa-solid fa-plus"></i>
                  افزودن
                </button>
              </div>
              <p class="hint-text">فقط پوشه‌هایی که سشن پست خودکار دارند، بنر تصادفی دریافت می‌کنند.</p>
              <div id="bannerBindingsList" class="hashtag-configs-list">
                <p class="channel-empty__sub">در حال بارگذاری...</p>
              </div>
            </article>
          </div>
        </section>

        <!-- افزونه دکمه شیشه‌ای -->
        <section id="screenGlassButtonDetail" class="screen screen--detail" aria-label="دکمه شیشه‌ای" hidden>
          <header class="topbar topbar--detail">
            <button id="btnGlassButtonBack" class="topbar__back" type="button" aria-label="بازگشت">
              <i class="fa-solid fa-arrow-right"></i>
            </button>
            <div class="topbar__brand">
              <div class="channel-avatar channel-avatar--sm channel-avatar--glass">
                <i class="fa-solid fa-up-right-from-square"></i>
              </div>
              <div class="topbar__text">
                <h2 id="glassButtonDetailTitle" class="topbar__title">دکمه شیشه‌ای</h2>
                <p id="glassButtonDetailMeta" class="topbar__sub">—</p>
              </div>
            </div>
          </header>

          <nav class="channel-subnav channel-subnav--three" aria-label="بخش‌های دکمه شیشه‌ای">
            <button type="button" class="channel-subnav__btn is-active" data-glass-button-tab="overview">
              <i class="fa-solid fa-gauge-high"></i>
              <span>نگاه کلی</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-glass-button-tab="folders">
              <i class="fa-solid fa-folder-tree"></i>
              <span>پوشه‌ها</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-glass-button-tab="settings">
              <i class="fa-solid fa-sliders"></i>
              <span>تنظیمات</span>
            </button>
          </nav>

          <div id="glassButtonPanelOverview" class="channel-panel is-active">
            <div class="stats-grid">
              <article class="stat-card">
                <span class="stat-card__label">فعال</span>
                <strong id="glassButtonStatActive" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">کل تنظیمات</span>
                <strong id="glassButtonStatTotal" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">دکمه شیشه‌ای</span>
                <strong id="glassButtonStatInline" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">لینک در کپشن</span>
                <strong id="glassButtonStatCaption" class="stat-card__value">—</strong>
              </article>
            </div>

            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-folder-tree"></i> پوشه‌های تنظیم‌شده</h3>
                <button id="btnGlassButtonAddFolder" type="button" class="btn btn--ghost btn--sm panel-card__action">
                  <i class="fa-solid fa-plus"></i>
                  افزودن
                </button>
              </div>
              <p id="glassButtonActiveConfigLabel" class="hashtag-active-config glass-active-config">—</p>
              <div id="glassButtonSettingsList" class="glass-configs-list"></div>
            </article>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-eye"></i> پیش‌نمایش</h3>
              <div id="glassButtonPreview" class="glass-button-preview">
                <p class="glass-button-preview__caption">کپشن نمونه پست کانال</p>
                <div id="glassButtonPreviewBody" class="glass-button-preview__body"></div>
              </div>
              <p class="hint-text">
                <i class="fa-solid fa-circle-info"></i>
                در پست خودکار و ربات محافظ، دکمه یا لینک دریافت محتوا با همین تنظیمات نمایش داده می‌شود.
              </p>
            </article>
          </div>

          <div id="glassButtonPanelFolders" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-list"></i> همه پوشه‌ها</h3>
                <button id="btnGlassButtonAddFolder2" type="button" class="btn btn--ghost btn--sm panel-card__action">
                  <i class="fa-solid fa-plus"></i>
                  افزودن
                </button>
              </div>
              <div id="glassButtonFoldersList" class="glass-configs-list"></div>
            </article>
          </div>

          <div id="glassButtonPanelSettings" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <p id="glassButtonSettingsBanner" class="hashtag-active-config glass-active-config">پیکربندی: —</p>
              <h3 class="panel-card__title"><i class="fa-solid fa-pen-to-square"></i> تنظیمات پوشه</h3>
              <label class="field">
                <span class="field__label">پوشه کانال</span>
                <button id="glassButtonFolderBtn" type="button" class="folder-pick-btn">
                  <i class="fa-solid fa-folder-tree"></i>
                  <span id="glassButtonFolderLabel">— انتخاب پوشه کانال —</span>
                </button>
                <input id="glassButtonFolderInput" type="hidden" value="" />
              </label>
              <label class="field">
                <span class="field__label">متن دکمه</span>
                <input id="glassButtonText" class="field__input" type="text" value="دریافت محتوا" placeholder="دریافت محتوا" maxlength="120" />
              </label>
              <label class="field">
                <span class="field__label">متن لینک در کپشن</span>
                <input id="glassButtonLineText" class="field__input" type="text" value="📥 مشاهده کردن" maxlength="120" />
              </label>
              <p class="field__label">حالت نمایش</p>
              <div class="radio-row">
                <label class="radio-pill">
                  <input type="radio" name="glassButtonDisplayMode" value="inline_buttons" checked />
                  <span>دکمه شیشه‌ای (سبز)</span>
                </label>
                <label class="radio-pill">
                  <input type="radio" name="glassButtonDisplayMode" value="caption_links" />
                  <span>لینک در کپشن</span>
                </label>
              </div>
              <p class="field__label">تعداد ردیف</p>
              <div class="radio-row">
                <label class="radio-pill">
                  <input type="radio" name="glassButtonRows" value="1" checked />
                  <span>یک ردیف</span>
                </label>
                <label class="radio-pill">
                  <input type="radio" name="glassButtonRows" value="2" />
                  <span>دو ردیف</span>
                </label>
              </div>
              <label class="field field--checkbox">
                <input id="glassButtonEnabled" type="checkbox" checked />
                <span>فعال برای این پوشه</span>
              </label>
              <button id="btnSaveGlassButton" type="button" class="btn btn--primary btn--block panel-card__action">
                <i class="fa-solid fa-floppy-disk"></i>
                ذخیره تنظیمات
              </button>
              <button id="btnDeleteGlassButton" type="button" class="btn btn--ghost btn--block btn--danger panel-card__action" hidden>
                <i class="fa-solid fa-trash"></i>
                حذف تنظیمات این پوشه
              </button>
              <p id="glassButtonSaveStatus" class="hint-text" hidden></p>
            </article>
          </div>
        </section>

        <!-- افزونه زاپاس -->
        <section id="screenZapasDetail" class="screen screen--detail" aria-label="زاپاس" hidden>
          <header class="topbar topbar--detail">
            <button id="btnZapasBack" class="topbar__back" type="button" aria-label="بازگشت">
              <i class="fa-solid fa-arrow-right"></i>
            </button>
            <div class="topbar__brand">
              <div class="channel-avatar channel-avatar--sm channel-avatar--zapas">
                <i class="fa-solid fa-shield-halved"></i>
              </div>
              <div class="topbar__text">
                <h2 class="topbar__title">زاپاس</h2>
                <p id="zapasDetailMeta" class="topbar__sub">—</p>
              </div>
            </div>
          </header>

          <nav class="channel-subnav channel-subnav--four" aria-label="بخش‌های زاپاس">
            <button type="button" class="channel-subnav__btn is-active" data-zapas-tab="overview">
              <i class="fa-solid fa-gauge-high"></i>
              <span>نگاه کلی</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-zapas-tab="bots">
              <i class="fa-solid fa-robot"></i>
              <span>ربات‌ها</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-zapas-tab="folders">
              <i class="fa-solid fa-folder-tree"></i>
              <span>پوشه‌ها</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-zapas-tab="history">
              <i class="fa-solid fa-clock-rotate-left"></i>
              <span>تاریخچه</span>
            </button>
          </nav>

          <div id="zapasPanelOverview" class="channel-panel is-active">
            <div class="stats-grid">
              <article class="stat-card">
                <span class="stat-card__label">آماده</span>
                <strong id="zapasStatStandby" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">جایگزین‌شده</span>
                <strong id="zapasStatReplacements" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">پست ویرایش‌شده</span>
                <strong id="zapasStatPosts" class="stat-card__value">—</strong>
              </article>
            </div>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-circle-info"></i> نحوه کار زاپاس</h3>
              <p class="hint-text">
                ربات‌های زاپاس در استخر آماده نگه داشته می‌شوند. وقتی ربات محافظ از کار بیفتد یا حذف شود،
                یک ربات زاپاس جایگزین می‌شود و لینک‌های پست‌های قبلی کانال به‌روزرسانی می‌شوند.
              </p>
              <p class="hint-text">
                <i class="fa-solid fa-folder-tree"></i>
                اگر پوشه خاصی انتخاب نشود، زاپاس برای همه پوشه‌های کانال فعال است.
              </p>
              <button id="btnZapasRunCheckOverview" type="button" class="btn btn--ghost btn--sm panel-card__action">
                <i class="fa-solid fa-rotate"></i>
                بررسی جایگزینی
              </button>
            </article>
          </div>

          <div id="zapasPanelBots" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-plus"></i> افزودن ربات زاپاس</h3>
              <p class="hint-text">فقط توکن ربات را وارد کنید — نوع ربات خودکار هنگام جایگزینی تعیین می‌شود.</p>
              <label class="field">
                <span class="field__label">توکن ربات</span>
                <input id="zapasBotToken" class="field__input" type="text" dir="ltr" placeholder="123456:ABC..." />
              </label>
              <button id="btnAddZapasBot" type="button" class="btn btn--primary btn--block">
                <i class="fa-solid fa-robot"></i> افزودن به استخر زاپاس
              </button>
            </article>
            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-robot"></i> ربات‌های زاپاس</h3>
                <button id="btnZapasRunCheck" type="button" class="btn btn--ghost btn--sm">
                  <i class="fa-solid fa-rotate"></i> بررسی جایگزینی
                </button>
              </div>
              <div id="zapasBotsList" class="hashtag-configs-list"></div>
            </article>
          </div>

          <div id="zapasPanelFolders" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-folder-tree"></i> پوشه‌های فعال</h3>
                <button id="btnZapasAddFolder" type="button" class="btn btn--ghost btn--sm">
                  <i class="fa-solid fa-plus"></i> افزودن
                </button>
              </div>
              <p class="hint-text">اگر پوشه‌ای انتخاب نشود، زاپاس برای همه پوشه‌ها فعال است.</p>
              <div id="zapasBindingsList" class="hashtag-configs-list"></div>
            </article>
          </div>

          <div id="zapasPanelHistory" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-clock-rotate-left"></i> تاریخچه جایگزینی</h3>
              <div id="zapasReplacementsList" class="hashtag-configs-list"></div>
            </article>
          </div>
        </section>

        <!-- جزئیات سشن پست -->
        <section id="screenPostSessionDetail" class="screen screen--detail" aria-label="آمار سشن پست" hidden>
          <header class="topbar topbar--detail">
            <button id="btnPostSessionBack" class="topbar__back" type="button" aria-label="بازگشت">
              <i class="fa-solid fa-arrow-right"></i>
            </button>
            <div class="topbar__brand">
              <div id="postSessionDetailAvatar" class="channel-avatar channel-avatar--sm channel-avatar--session">
                <i class="fa-solid fa-layer-group"></i>
              </div>
              <div class="topbar__text">
                <h2 id="postSessionDetailTitle" class="topbar__title">سشن پست</h2>
                <p id="postSessionDetailMeta" class="topbar__sub">—</p>
              </div>
            </div>
          </header>

          <nav class="channel-subnav channel-subnav--four" aria-label="بخش‌های سشن">
            <button type="button" class="channel-subnav__btn is-active" data-post-session-tab="overview">
              <i class="fa-solid fa-gauge-high"></i>
              <span>کلی</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-post-session-tab="channels">
              <i class="fa-solid fa-tower-broadcast"></i>
              <span>کانال‌ها</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-post-session-tab="bots">
              <i class="fa-solid fa-robot"></i>
              <span>ربات‌ها</span>
            </button>
            <button type="button" class="channel-subnav__btn" data-post-session-tab="send">
              <i class="fa-solid fa-paper-plane"></i>
              <span>ارسال</span>
            </button>
          </nav>

          <div id="postSessionPanelOverview" class="channel-panel is-active">
            <div class="stats-grid">
              <article class="stat-card">
                <span class="stat-card__label">کانال</span>
                <strong id="psOverviewChannels" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">اعضای کانال</span>
                <strong id="psOverviewChannelMembers" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">ربات</span>
                <strong id="psOverviewBots" class="stat-card__value">—</strong>
              </article>
              <article class="stat-card">
                <span class="stat-card__label">کاربران ربات</span>
                <strong id="psOverviewBotUsers" class="stat-card__value">—</strong>
              </article>
            </div>

            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-circle-info"></i> اطلاعات سشن</h3>
              <div class="profile-grid profile-grid--single">
                <div class="profile-item">
                  <span class="profile-item__label">پوشه کانال</span>
                  <span id="psOverviewChannelPath" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">پوشه ربات</span>
                  <span id="psOverviewBotPath" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">دسترسی ترکیبی</span>
                  <span id="psOverviewCombined" class="profile-item__value">—</span>
                </div>
                <div class="profile-item">
                  <span class="profile-item__label">تاریخ ساخت</span>
                  <span id="psOverviewCreated" class="profile-item__value">—</span>
                </div>
              </div>
            </article>

            <div class="dash-kpis dash-kpis--channel">
              <article class="dash-kpi dash-kpi--accent">
                <span class="dash-kpi__label">دسترسی ترکیبی</span>
                <strong id="psStatsKpiCombined" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi">
                <span class="dash-kpi__label">کاربر جدید یکتا · ۲۴س</span>
                <strong id="psStatsKpiNewUnique" class="dash-kpi__value">—</strong>
              </article>
            </div>
            <div class="dash-charts dash-charts--channel">
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند مجموع کل (کانال + ربات)</h4>
                  <span id="psChartCombinedReachRange" class="dash-chart-card__sub">کاربران یکتا · ۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartPsCombinedReach" aria-label="نمودار دسترسی ترکیبی"></canvas>
                </div>
              </section>
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند کاربر جدید یکتا</h4>
                  <span id="psChartCombinedNewRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartPsCombinedNew" aria-label="نمودار کاربر جدید یکتا"></canvas>
                </div>
              </section>
            </div>
            <p class="hint-text dash-chart-card__hint">
              <i class="fa-solid fa-circle-info"></i>
              دسترسی ترکیبی = مجموع اعضای کانال‌ها + کاربران یکتای ربات‌های سالم (بدون تکرار بین ربات‌ها).
            </p>
          </div>

          <div id="postSessionPanelChannels" class="channel-panel" hidden>
            <div class="dash-kpis dash-kpis--channel">
              <article class="dash-kpi">
                <span class="dash-kpi__label">عضویت · ۱ ساعت</span>
                <strong id="psChannelKpiJoins1h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi">
                <span class="dash-kpi__label">عضویت · ۲۴ ساعت</span>
                <strong id="psChannelKpiJoins24h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi dash-kpi--accent">
                <span class="dash-kpi__label">مجموع اعضا</span>
                <strong id="psChannelKpiMembers" class="dash-kpi__value">—</strong>
              </article>
            </div>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-list"></i> کانال‌های سشن</h3>
              <div id="psChannelList" class="folder-details__bots-list">
                <p class="folder-details__empty">در حال بارگذاری...</p>
              </div>
            </article>
            <div class="dash-charts dash-charts--channel">
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند عضویت کانال‌ها</h4>
                  <span id="psChartChannelJoinsRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartPsChannelJoins" aria-label="نمودار عضویت کانال"></canvas>
                </div>
              </section>
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند مجموع اعضای کانال</h4>
                  <span id="psChartChannelMembersRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartPsChannelMembers" aria-label="نمودار اعضای کانال"></canvas>
                </div>
              </section>
            </div>
          </div>

          <div id="postSessionPanelBots" class="channel-panel" hidden>
            <div class="dash-kpis dash-kpis--channel">
              <article class="dash-kpi">
                <span class="dash-kpi__label">کاربر جدید · ۱س</span>
                <strong id="psBotKpiJoins1h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi">
                <span class="dash-kpi__label">کاربر جدید · ۲۴س</span>
                <strong id="psBotKpiJoins24h" class="dash-kpi__value">—</strong>
              </article>
              <article class="dash-kpi dash-kpi--accent">
                <span class="dash-kpi__label">کاربران یکتا</span>
                <strong id="psBotKpiUsers" class="dash-kpi__value">—</strong>
              </article>
            </div>
            <article class="glass-card panel-card">
              <h3 class="panel-card__title"><i class="fa-solid fa-list"></i> ربات‌های سشن</h3>
              <div id="psBotList" class="folder-details__bots-list">
                <p class="folder-details__empty">در حال بارگذاری...</p>
              </div>
            </article>
            <div class="dash-charts dash-charts--channel">
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند کاربران جدید ربات</h4>
                  <span id="psChartBotJoinsRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartPsBotJoins" aria-label="نمودار کاربران ربات"></canvas>
                </div>
              </section>
              <section class="dash-chart-card dash-chart-card--wide">
                <header class="dash-chart-card__head">
                  <h4>روند مجموع کاربران ربات</h4>
                  <span id="psChartBotUsersRange" class="dash-chart-card__sub">۲۴ ساعت گذشته</span>
                </header>
                <div class="dash-chart-card__canvas chart-wrap chart-wrap--dash-line">
                  <canvas id="chartPsBotUsers" aria-label="نمودار مجموع کاربران"></canvas>
                </div>
              </section>
            </div>
          </div>

          <div id="postSessionPanelSend" class="channel-panel" hidden>
            <article class="glass-card panel-card">
              <div class="panel-card__head-row">
                <h3 class="panel-card__title"><i class="fa-solid fa-clock-rotate-left"></i> ارسال خودکار</h3>
                <button id="btnAddAutoPostSchedule" type="button" class="btn btn--primary btn--sm">
                  <i class="fa-solid fa-plus"></i>
                  افزودن پست خودکار
                </button>
              </div>
              <p class="hint-text">پست‌های زمان‌بندی‌شده از گروه محتوا به کانال‌های سشن — با لینک محافظ، آپلودر و تنظیمات افزونه دکمه شیشه‌ای هر پوشه.</p>
              <div id="autoPostScheduleList" class="auto-post-schedule-list">
                <p class="folder-details__empty">در حال بارگذاری...</p>
              </div>
            </article>
          </div>

        </section>

        <!-- تبلیغات -->
        <section id="screenAds" class="screen" aria-label="تبلیغات">
          <header class="topbar">
            <div class="topbar__brand">
              <span class="topbar__icon" aria-hidden="true"><i class="fa-solid fa-rectangle-ad"></i></span>
              <div>
                <h2 class="topbar__title">تبلیغات</h2>
                <p class="topbar__sub">سفارش ممبر و توزیع بین پوشه‌ها</p>
              </div>
            </div>
          </header>

          <article class="glass-card panel-card">
            <h3 class="panel-card__title"><i class="fa-solid fa-plus"></i> ثبت سفارش تبلیغ</h3>
            <p class="hint-text" style="margin-bottom:0.75rem">
              کانال هدف در پوشه <strong>تبلیغات</strong> در تب کانال‌ها قرار می‌گیرد (اگر بدون پوشه بود، منتقل می‌شود).
            </p>
            <label class="field">
              <span class="field__label">آدرس کانال</span>
              <input id="adChannelInput" class="field__input" type="text" dir="ltr" placeholder="@channel یا لینک t.me" />
            </label>
            <label class="field">
              <span class="field__label">تعداد ممبر سفارش</span>
              <input id="adMemberOrderInput" class="field__input" type="number" min="1" step="1" placeholder="مثلاً 5000" />
            </label>
            <div class="field">
              <span class="field__label">پوشه‌های اجرای تبلیغ (با درصد اختیاری)</span>
              <div id="adFolderSplits" class="ad-folder-splits"></div>
              <button id="btnAdAddFolder" type="button" class="btn btn--ghost btn--sm" style="margin-top:0.5rem">
                <i class="fa-solid fa-folder-plus"></i>
                افزودن پوشه
              </button>
              <p id="adPercentSumHint" class="hint-text" style="margin-top:0.5rem">مجموع درصدها باید دقیقاً <strong>۱۰۰</strong> باشد (بیشتر از ۱۰۰ مجاز نیست).</p>
            </div>
            <button id="btnAdSubmit" type="button" class="btn btn--primary btn--block">
              <i class="fa-solid fa-check"></i>
              ثبت در جدول تبلیغات
            </button>
          </article>

          <article class="glass-card panel-card">
            <h3 class="panel-card__title"><i class="fa-solid fa-list"></i> سفارش‌های اخیر</h3>
            <div id="adCampaignsList" class="ad-campaigns-list">
              <p class="channel-empty__sub">در حال بارگذاری...</p>
            </div>
          </article>
        </section>

        <!-- وضعیت سرور -->
        <section id="screenServer" class="screen" aria-label="وضعیت سرور">
          <header class="topbar">
            <div class="topbar__brand">
              <span class="topbar__icon" aria-hidden="true"><i class="fa-solid fa-server"></i></span>
              <div>
                <h2 class="topbar__title">وضعیت سرور</h2>
                <p id="serverCheckedAt" class="topbar__sub">—</p>
              </div>
            </div>
          </header>

          <article class="glass-card panel-card">
            <div id="serverStatus" class="status-list">
              <div class="status-item">
                <span>برای مشاهده وضعیت، تب سرور را باز کنید</span>
              </div>
            </div>
            <button id="btnRefreshServer" class="btn btn--ghost btn--block btn--sm" type="button" style="margin-top:0.65rem">
              <i class="fa-solid fa-stethoscope"></i>
              بررسی مجدد کانال‌ها و ربات‌ها (API تلگرام)
            </button>
          </article>

          <article class="glass-card panel-card">
            <h3 class="panel-card__title"><i class="fa-solid fa-triangle-exclamation"></i> کانال‌های مسدود یا بدون دسترسی</h3>
            <div id="serverChannelProblems" class="server-problems">
              <p class="channel-empty__sub">با باز کردن این تب، لیست از دیتابیس نمایش داده می‌شود.</p>
            </div>
          </article>

          <article class="glass-card panel-card">
            <h3 class="panel-card__title"><i class="fa-solid fa-robot"></i> ربات‌های مسدود یا بدون دسترسی</h3>
            <div id="serverBotProblems" class="server-problems">
              <p class="channel-empty__sub">با باز کردن این تب، وضعیت ربات‌های آپلودر از دیتابیس نمایش داده می‌شود.</p>
            </div>
          </article>

          <article id="globalBotOwnersCard" class="glass-card panel-card" hidden>
            <div class="panel-card__headrow">
              <h3 class="panel-card__title"><i class="fa-solid fa-user-shield"></i> مالک‌های کل ربات‌ها</h3>
              <button id="btnAddGlobalBotOwner" type="button" class="btn btn--ghost btn--sm">
                <i class="fa-solid fa-plus"></i>
                افزودن مالک
              </button>
            </div>
            <p class="hint-text">این کاربران با دستور <code>/admin</code> در همه ربات‌های آپلودر وارد پنل مدیریت می‌شوند.</p>
            <div id="globalBotOwnersList" class="uploader-versions-list">
              <p class="channel-empty__sub">در حال بارگذاری...</p>
            </div>
          </article>

          <article id="manageBotsCard" class="glass-card panel-card" hidden>
            <div class="panel-card__headrow">
              <h3 class="panel-card__title"><i class="fa-solid fa-robot"></i> ربات‌های مدیریت کمکی</h3>
              <button id="btnAddManageBot" type="button" class="btn btn--ghost btn--sm">
                <i class="fa-solid fa-plus"></i>
                ثبت با پنجره
              </button>
            </div>
            <p class="hint-text">
              ربات اصلی <strong>@gpro100_bot</strong> برای پنل می‌ماند. ربات‌های کمکی فقط برای API کانال‌ها (اتوپست، جوین، زاپاس) هستند — حداکثر ~۵۰۰ کانال per ربات.
            </p>

            <div id="manageBotRegisterPanel" class="manage-bot-register">
              <p class="manage-bot-register__title"><i class="fa-solid fa-key"></i> ثبت ربات کمکی جدید</p>
              <p class="manage-bot-register__hint">توکن را از BotFather کپی کنید و ثبت کنید. وب‌هوک به‌صورت خودکار تنظیم می‌شود.</p>
              <label class="field">
                <span class="field__label">توکن ربات</span>
                <input id="manageBotTokenInline" class="field__input" type="text" dir="ltr" placeholder="123456789:AAH..." autocomplete="off" />
              </label>
              <button id="btnSaveManageBotInline" type="button" class="btn btn--primary btn--block">
                <i class="fa-solid fa-cloud-arrow-up"></i>
                ثبت و فعال‌سازی ربات کمکی
              </button>
            </div>

            <p id="manageBotsStatsLine" class="hashtag-active-config glass-active-config">—</p>
            <div id="manageBotsList" class="uploader-versions-list manage-bot-status-grid">
              <p class="channel-empty__sub">در حال بارگذاری وضعیت ربات‌ها...</p>
            </div>
            <button id="btnRefreshManageBotsHealth" type="button" class="btn btn--ghost btn--block btn--sm" style="margin-top:0.65rem">
              <i class="fa-solid fa-stethoscope"></i>
              بررسی سلامت همه ربات‌های مدیریت
            </button>
          </article>

          <article id="uploaderVersionsCard" class="glass-card panel-card" hidden>
            <div class="panel-card__headrow">
              <h3 class="panel-card__title"><i class="fa-solid fa-code-branch"></i> نسخه‌های اپلودر ساز</h3>
              <button id="btnAddUploaderVersion" type="button" class="btn btn--ghost btn--sm">
                <i class="fa-solid fa-plus"></i>
                افزودن نسخه
              </button>
            </div>
            <p class="hint-text">هر نسخه مسیر پروژه PHP آپلودر را مشخص می‌کند. ربات‌های جدید روی نسخه پیش‌فرض ساخته می‌شوند.</p>
            <div id="uploaderVersionsList" class="uploader-versions-list">
              <p class="channel-empty__sub">در حال بارگذاری...</p>
            </div>
          </article>

          <article class="glass-card panel-card">
            <p class="hint-text">
              <i class="fa-solid fa-bolt"></i>
              <strong>اعلان سریع:</strong> وقتی ربات از کانال حذف شود، تلگرام فوراً رویداد <code>my_chat_member</code> می‌فرستد و به ادمین‌های ربات پیام تلگرام می‌رسد.
            </p>
            <p class="hint-text">
              <i class="fa-solid fa-circle-info"></i>
              بن یا حذف کانال/ربات ممکن است فقط با بررسی API مشخص شود؛ دکمه «بررسی مجدد کانال‌ها و ربات‌ها» را بزنید یا هر چند ساعت یک‌بار این تب را باز کنید.
            </p>
          </article>
        </section>
      </main>

      <nav id="bottomNav" class="bottom-nav bottom-nav--main" aria-label="ناوبری" hidden>
        <button class="nav-btn is-active" type="button" data-nav="home">
          <i class="fa-solid fa-gauge-high"></i>
          <span>داشبورد</span>
        </button>
        <button class="nav-btn" type="button" data-nav="channels">
          <i class="fa-solid fa-bullhorn"></i>
          <span>کانال‌ها</span>
        </button>
        <button class="nav-btn" type="button" data-nav="ads">
          <i class="fa-solid fa-rectangle-ad"></i>
          <span>تبلیغات</span>
        </button>
        <button class="nav-btn" type="button" data-nav="server">
          <i class="fa-solid fa-server"></i>
          <span>سرور</span>
        </button>
      </nav>

      <div id="manageBotModal" class="modal" hidden>
        <div class="modal__backdrop" data-close-manage-bot></div>
        <div class="modal__card glass-card" role="dialog" aria-labelledby="manageBotModalTitle">
          <h3 id="manageBotModalTitle" class="modal__title">افزودن ربات مدیریت کمکی</h3>
          <p class="hint-text">توکن را از BotFather بگیرید. این ربات فقط برای API کانال‌هاست — مینی‌اپ از ربات اصلی باز می‌شود.</p>
          <label class="field">
            <span class="field__label">توکن ربات</span>
            <input id="manageBotTokenInput" class="field__input" type="text" dir="ltr" placeholder="123456:ABC..." />
          </label>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-manage-bot>انصراف</button>
            <button id="btnSaveManageBot" type="button" class="btn btn--primary">افزودن و تنظیم وب‌هوک</button>
          </div>
        </div>
      </div>

      <div id="globalBotOwnerModal" class="modal" hidden>
        <div class="modal__backdrop" data-close-global-owner></div>
        <div class="modal__card glass-card" role="dialog" aria-labelledby="globalBotOwnerModalTitle">
          <h3 id="globalBotOwnerModalTitle" class="modal__title">افزودن مالک کل ربات‌ها</h3>
          <label class="field">
            <span class="field__label">شناسه عددی یا @username</span>
            <input id="globalBotOwnerInput" class="field__input" type="text" maxlength="64" placeholder="مثلاً 123456789 یا @MR_HOOSEIN" dir="ltr" />
          </label>
          <p class="hint-text">اگر username می‌زنید، کاربر باید حداقل یک‌بار با ربات اصلی تعامل کرده باشد.</p>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-global-owner>انصراف</button>
            <button id="btnSaveGlobalBotOwner" type="button" class="btn btn--primary">ذخیره</button>
          </div>
        </div>
      </div>

      <div id="uploaderVersionModal" class="modal" hidden>
        <div class="modal__backdrop" data-close-uploader-version></div>
        <div class="modal__card glass-card" role="dialog" aria-labelledby="uploaderVersionModalTitle">
          <h3 id="uploaderVersionModalTitle" class="modal__title">افزودن نسخه اپلودر</h3>
          <label class="field">
            <span class="field__label">نام نسخه</span>
            <input id="uploaderVersionNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً مستراپلودر v1.0" />
          </label>
          <label class="field">
            <span class="field__label">مسیر پروژه روی سرور</span>
            <input id="uploaderVersionPathInput" class="field__input" type="text" placeholder="/home/shombols/public_html/great/uploader" />
          </label>
          <label class="field">
            <span class="field__label">فایل webhook</span>
            <input id="uploaderVersionWebhookInput" class="field__input" type="text" value="index.php" placeholder="index.php" />
          </label>
          <label class="field field--checkbox">
            <input id="uploaderVersionDefaultInput" type="checkbox" />
            <span>نسخه پیش‌فرض برای ساخت ربات جدید</span>
          </label>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-uploader-version>انصراف</button>
            <button id="btnSaveUploaderVersion" type="button" class="btn btn--primary">ذخیره</button>
          </div>
        </div>
      </div>

      <div id="toastRoot" class="toast-root" aria-live="polite" aria-atomic="true"></div>

      <div id="folderContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-folder-context-close></div>
        <div id="folderContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی پوشه"></div>
      </div>

      <div id="channelContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-channel-context-close></div>
        <div id="channelContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی کانال"></div>
      </div>

      <div id="botFolderContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-bot-folder-context-close></div>
        <div id="botFolderContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی پوشه ربات"></div>
      </div>

      <div id="botContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-bot-context-close></div>
        <div id="botContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی ربات"></div>
      </div>

      <div id="contentGroupFolderContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-content-group-folder-context-close></div>
        <div id="contentGroupFolderContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی پوشه گروه"></div>
      </div>

      <div id="contentGroupContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-content-group-context-close></div>
        <div id="contentGroupContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی گروه محتوا"></div>
      </div>

      <div id="autoPostFolderContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-auto-post-folder-context-close></div>
        <div id="autoPostFolderContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی پوشه پست خودکار"></div>
      </div>

      <div id="postSessionContextMenu" class="context-menu" hidden>
        <div class="context-menu__backdrop" data-post-session-context-close></div>
        <div id="postSessionContextPanel" class="context-menu__panel glass-card" role="menu" aria-label="منوی سشن پست"></div>
      </div>

      <div id="renameBotModal" class="modal" hidden>
        <div class="modal__backdrop" data-close-rename-bot></div>
        <div class="modal__card glass-card" role="dialog" aria-labelledby="renameBotModalTitle">
          <h3 id="renameBotModalTitle" class="modal__title">تغییر نام ربات</h3>
          <label class="field">
            <span class="field__label">نام نمایشی</span>
            <input id="renameBotNameInput" class="field__input" type="text" maxlength="64" placeholder="نام ربات" />
          </label>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-rename-bot>انصراف</button>
            <button id="btnSaveRenameBot" type="button" class="btn btn--primary">ذخیره</button>
          </div>
        </div>
      </div>

      <div id="botJoinModal" class="modal" hidden>
        <div class="modal__backdrop" data-close-bot-join></div>
        <div class="modal__card glass-card" role="dialog" aria-labelledby="botJoinModalTitle">
          <h3 id="botJoinModalTitle" class="modal__title">افزودن جوین</h3>
          <input id="botJoinModalType" type="hidden" value="forced" />
          <label id="botJoinChannelIdField" class="field">
            <span class="field__label">آیدی عددی کانال/گروه</span>
            <input id="botJoinChannelIdInput" class="field__input" type="text" dir="ltr" placeholder="-1001234567890" />
          </label>
          <label class="field">
            <span class="field__label">لینک عضویت</span>
            <div id="botJoinLinkFullWrap" class="field__input-wrap">
              <input id="botJoinLinkInput" class="field__input" type="url" dir="ltr" placeholder="https://t.me/..." />
            </div>
            <div id="botJoinLinkPrefixWrap" class="field__input-group" dir="ltr" hidden>
              <span class="field__input-prefix">https://t.me/</span>
              <input
                id="botJoinLinkSuffixInput"
                class="field__input field__input--suffix"
                type="text"
                dir="ltr"
                placeholder="channel یا username"
                autocomplete="off"
                spellcheck="false"
              />
            </div>
          </label>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-bot-join>انصراف</button>
            <button id="btnSaveBotJoin" type="button" class="btn btn--primary">ذخیره</button>
          </div>
        </div>
      </div>

      <div id="botFolderJoinManageModal" class="modal modal--scroll" hidden>
        <div class="modal__backdrop" data-close-bot-folder-join-manage></div>
        <div class="modal__card glass-card modal__card--wide" role="dialog" aria-labelledby="botFolderJoinManageTitle">
          <h3 id="botFolderJoinManageTitle" class="modal__title">جوین گروهی · <span id="botFolderJoinManageName"></span></h3>
          <p id="botFolderJoinManageHint" class="hint-text">لیست جوین‌های فعال برای همه ربات‌های این پوشه</p>

          <div class="join-manage-section">
            <div class="join-manage-section__head">
              <h4 class="join-manage-section__title">جوین‌های اجباری</h4>
              <button id="btnFolderAddForcedJoin" type="button" class="btn btn--ghost btn--sm">افزودن</button>
            </div>
            <div id="botFolderForcedJoinsList" class="join-rules-list"></div>
          </div>

          <div class="join-manage-section">
            <div class="join-manage-section__head">
              <h4 class="join-manage-section__title">جوین‌های فیک</h4>
              <button id="btnFolderAddFakeJoin" type="button" class="btn btn--ghost btn--sm">افزودن</button>
            </div>
            <div id="botFolderFakeJoinsList" class="join-rules-list"></div>
          </div>

          <div class="modal__actions">
            <button type="button" class="btn btn--ghost btn--block" data-close-bot-folder-join-manage>بستن</button>
          </div>
        </div>
      </div>

      <div id="botFolderJoinModal" class="modal modal--stack" hidden>
        <div class="modal__backdrop" data-close-bot-folder-join></div>
        <div class="modal__card glass-card" role="dialog" aria-labelledby="botFolderJoinModalTitle">
          <h3 id="botFolderJoinModalTitle" class="modal__title">جوین گروهی پوشه</h3>
          <input id="botFolderJoinModalType" type="hidden" value="forced" />
          <p id="botFolderJoinHint" class="hint-text">این تنظیم برای همه ربات‌های داخل پوشه اعمال می‌شود.</p>
          <label id="botFolderJoinChannelIdField" class="field">
            <span class="field__label">آیدی عددی کانال/گروه</span>
            <input id="botFolderJoinChannelIdInput" class="field__input" type="text" dir="ltr" placeholder="-1001234567890" />
          </label>
          <label class="field">
            <span class="field__label">لینک عضویت</span>
            <div id="botFolderJoinLinkFullWrap" class="field__input-wrap">
              <input id="botFolderJoinLinkInput" class="field__input" type="url" dir="ltr" placeholder="https://t.me/..." />
            </div>
            <div id="botFolderJoinLinkPrefixWrap" class="field__input-group" dir="ltr" hidden>
              <span class="field__input-prefix">https://t.me/</span>
              <input
                id="botFolderJoinLinkSuffixInput"
                class="field__input field__input--suffix"
                type="text"
                dir="ltr"
                placeholder="channel یا username"
                autocomplete="off"
                spellcheck="false"
              />
            </div>
          </label>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-bot-folder-join>انصراف</button>
            <button id="btnSaveBotFolderJoin" type="button" class="btn btn--primary">اعمال گروهی</button>
          </div>
        </div>
      </div>

      <div id="folderTreePickerModal" class="modal modal--scroll" hidden>
        <div class="modal__backdrop" data-close-folder-tree-picker></div>
        <div class="modal__card glass-card modal__card--wide" role="dialog" aria-labelledby="folderTreePickerTitle">
          <h3 id="folderTreePickerTitle" class="modal__title">انتقال به پوشه</h3>
          <p id="folderTreePickerHint" class="hint-text">پوشه مقصد را انتخاب کنید</p>
          <div id="folderTreePickerSearchWrap" class="folder-tree-search" hidden>
            <i class="fa-solid fa-magnifying-glass folder-tree-search__icon" aria-hidden="true"></i>
            <input id="folderTreePickerSearch" class="folder-tree-search__input" type="search" inputmode="search" autocomplete="off" placeholder="جستجوی پوشه..." />
          </div>
          <div id="folderTreePickerList" class="folder-tree-picker"></div>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost btn--block" data-close-folder-tree-picker>انصراف</button>
          </div>
        </div>
      </div>

      <!-- ویزارد پست خودکار -->
      <div id="autoPostScheduleWizard" class="modal modal--scroll modal--stack" hidden>
        <div class="modal__backdrop" data-close-auto-post-wizard></div>
        <div class="modal__card glass-card modal__card--wide" role="dialog" aria-labelledby="autoPostWizardTitle">
          <h3 id="autoPostWizardTitle" class="modal__title">افزودن پست خودکار</h3>

          <div id="apWizardStep1" class="ap-wizard-step">
            <p class="hint-text">گروه پست (از گروه‌های محتوا) را انتخاب کنید:</p>
            <div id="apContentGroupList" class="ap-content-group-list"></div>
          </div>

          <div id="apWizardStep2" class="ap-wizard-step" hidden>
            <p class="field__label">نوع ارسال</p>
            <div id="apMediaTypeChips" class="ap-type-chips">
              <button type="button" class="ap-type-chip is-active" data-ap-media-type="photo"><i class="fa-solid fa-image"></i> عکس</button>
              <button type="button" class="ap-type-chip" data-ap-media-type="video"><i class="fa-solid fa-film"></i> فیلم</button>
              <button type="button" class="ap-type-chip" data-ap-media-type="voice"><i class="fa-solid fa-microphone"></i> ویس</button>
              <button type="button" class="ap-type-chip" data-ap-media-type="file"><i class="fa-solid fa-file"></i> فایل</button>
            </div>
            <div id="apMediaPreviewStrip" class="ap-media-preview-strip"></div>
            <button id="btnApOpenMediaPicker" type="button" class="btn btn--ghost btn--block btn--sm" style="margin-top:0.65rem">
              <i class="fa-solid fa-grid-2"></i>
              انتخاب دقیق‌تر محتوا
            </button>
          </div>

          <div id="apWizardStep3" class="ap-wizard-step" hidden>
            <label class="field">
              <span class="field__label">زمان ارسال (به وقت ایران)</span>
              <input id="apStartTimeInput" class="field__input" type="time" value="17:30" />
            </label>
            <p class="field__label">حالت زمان‌بندی</p>
            <div class="ap-schedule-mode">
              <label class="ap-radio-card">
                <input type="radio" name="apScheduleMode" value="daily_fixed" checked />
                <span>هر روز همین ساعت</span>
              </label>
              <label class="ap-radio-card">
                <input type="radio" name="apScheduleMode" value="daily_rotate" />
                <span>چرخشی — هر روز دیرتر</span>
              </label>
            </div>
            <label id="apRotateHoursField" class="field" hidden>
              <span class="field__label">افزایش ساعت چرخشی</span>
              <input id="apRotateHoursInput" class="field__input" type="number" min="0.5" max="24" step="0.5" value="1" dir="ltr" />
              <p class="hint-text">مثلاً امروز ۱۷:۳۰ → فردا ۱۸:۳۰ (با ۱ ساعت)</p>
            </label>
            <label class="field">
              <span class="field__label">نام (اختیاری)</span>
              <input id="apScheduleNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً عکس روزانه" />
            </label>
          </div>

          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-auto-post-wizard>انصراف</button>
            <button id="btnApWizardBack" type="button" class="btn btn--ghost" hidden>برگشت</button>
            <button id="btnApWizardNext" type="button" class="btn btn--primary">ادامه</button>
            <button id="btnApWizardSave" type="button" class="btn btn--primary" hidden>ذخیره</button>
          </div>
        </div>
      </div>

      <!-- ویزارد هشتگ پست -->
      <div id="hashtagSetWizard" class="modal modal--scroll modal--stack" hidden>
        <div class="modal__backdrop" data-close-hashtag-wizard></div>
        <div class="modal__card glass-card modal__card--wide" role="dialog" aria-labelledby="hashtagWizardTitle">
          <h3 id="hashtagWizardTitle" class="modal__title">ایجاد هشتگ پست</h3>
          <label class="field">
            <span class="field__label">نام مجموعه</span>
            <input id="hashtagSetNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً هشتگ‌های غیراخلاقی" />
          </label>
          <label class="field">
            <span class="field__label">پوشه کانال (الزامی)</span>
            <button id="hashtagChannelFolderBtn" type="button" class="folder-pick-btn">
              <i class="fa-solid fa-folder-tree"></i>
              <span id="hashtagChannelFolderLabel">— انتخاب پوشه کانال —</span>
            </button>
            <input id="hashtagChannelFolderInput" type="hidden" value="" />
          </label>
          <p class="field__label">حالت انتخاب هشتگ</p>
          <div class="ap-schedule-mode">
            <label class="ap-radio-card">
              <input type="radio" name="hashtagSelectionMode" value="random" checked />
              <span>تصادفی — N عدد از لیست</span>
            </label>
            <label class="ap-radio-card">
              <input type="radio" name="hashtagSelectionMode" value="all" />
              <span>همه هشتگ‌ها</span>
            </label>
            <label class="ap-radio-card">
              <input type="radio" name="hashtagSelectionMode" value="smart" />
              <span>هوشمند — کلیدواژه + تصادفی</span>
            </label>
          </div>
          <label class="field">
            <span class="field__label">تعداد تصادفی (اگر حالت تصادفی/هوشمند)</span>
            <input id="hashtagRandomCountInput" class="field__input" type="number" min="1" max="50" value="5" dir="ltr" />
          </label>
          <div class="panel-card__head-row" style="margin-top:0.5rem">
            <p class="field__label" style="margin:0">لیست هشتگ‌ها</p>
            <button id="btnAddHashtagTag" type="button" class="btn btn--ghost btn--sm">
              <i class="fa-solid fa-plus"></i>
              افزودن
            </button>
          </div>
          <p class="hint-text">برای حالت هوشمند: کلیدواژه‌ها را با کاما جدا کنید — اگر در کپشن پست بود، آن هشتگ حتماً اضافه می‌شود.</p>
          <div id="hashtagTagsList" class="hashtag-tags-list"></div>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-hashtag-wizard>انصراف</button>
            <button id="btnSaveHashtagSet" type="button" class="btn btn--primary">ذخیره</button>
          </div>
        </div>
      </div>

      <!-- جزئیات مجموعه هشتگ -->
      <div id="hashtagSetDetailsModal" class="modal modal--scroll" hidden>
        <div class="modal__backdrop" data-close-hashtag-details></div>
        <div class="modal__card glass-card modal__card--wide" role="dialog">
          <h3 id="hashtagSetDetailsTitle" class="modal__title">جزئیات هشتگ</h3>
          <p id="hashtagSetDetailsMeta" class="hint-text">—</p>
          <div id="hashtagSetDetailsTags" class="hashtag-tags-preview"></div>
          <div class="modal__actions">
            <button id="btnEditHashtagSet" type="button" class="btn btn--ghost">ویرایش</button>
            <button type="button" class="btn btn--primary" data-close-hashtag-details>بستن</button>
          </div>
        </div>
      </div>

      <div id="hashtagFolderModal" class="modal" hidden>
        <div class="modal__backdrop" data-close-hashtag-folder-modal></div>
        <div class="modal__card glass-card" role="dialog">
          <h3 class="modal__title">پوشه جدید</h3>
          <label class="field">
            <span class="field__label">نام پوشه</span>
            <input id="hashtagFolderNameInput" class="field__input" type="text" maxlength="120" placeholder="مثلاً کمپین‌ها" />
          </label>
          <div class="modal__actions">
            <button type="button" class="btn btn--ghost" data-close-hashtag-folder-modal>انصراف</button>
            <button id="btnSaveHashtagFolder" type="button" class="btn btn--primary">ذخیره</button>
          </div>
        </div>
      </div>

      <!-- انتخابگر محتوای گروه -->
      <div id="apMediaPickerScreen" class="screen screen--overlay" hidden>
        <header class="topbar topbar--detail">
          <button id="btnApMediaPickerBack" class="topbar__back" type="button" aria-label="بازگشت">
            <i class="fa-solid fa-arrow-right"></i>
          </button>
          <div class="topbar__brand">
            <div class="topbar__text">
              <h2 class="topbar__title">انتخاب محتوا</h2>
              <p id="apMediaPickerMeta" class="topbar__sub">—</p>
            </div>
          </div>
        </header>
        <div id="apMediaPickerGrid" class="ap-media-grid"></div>
        <div class="ap-media-picker-footer">
          <span id="apMediaPickerCount" class="hint-text">۰ مورد انتخاب شده</span>
          <button id="btnApMediaPickerConfirm" type="button" class="btn btn--primary">تأیید انتخاب</button>
        </div>
      </div>

      <div id="actionSheet" class="action-sheet" hidden>
        <div class="action-sheet__backdrop" data-action-sheet-close></div>
        <div class="action-sheet__panel glass-card">
          <h4 id="actionSheetTitle" class="action-sheet__title">انتخاب</h4>
          <div id="actionSheetList" class="action-sheet__list"></div>
          <button type="button" class="btn btn--ghost btn--block" data-action-sheet-close>انصراف</button>
        </div>
      </div>
    </div>
  </body>
</html>
