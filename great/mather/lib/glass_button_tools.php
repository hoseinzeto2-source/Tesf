<?php

require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/channel_folders.php';
require_once __DIR__ . '/auto_post_schedules.php';

function ensureGlassButtonToolTables(): void
{
    ensureChannelFolderTables();

    $db = getDb();
    $db->query(
        <<<SQL
CREATE TABLE IF NOT EXISTS glass_button_folder_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    owner_telegram_id BIGINT NOT NULL,
    channel_folder_id INT UNSIGNED NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    button_text VARCHAR(120) NOT NULL DEFAULT 'دریافت محتوا',
    line_text VARCHAR(120) NOT NULL DEFAULT '📥 مشاهده کردن',
    display_mode VARCHAR(20) NOT NULL DEFAULT 'inline_buttons',
    button_rows TINYINT UNSIGNED NOT NULL DEFAULT 1,
    button_style VARCHAR(20) NOT NULL DEFAULT 'green',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_owner_folder (owner_telegram_id, channel_folder_id),
    KEY idx_owner (owner_telegram_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

/**
 * @return array<string, mixed>
 */
function defaultGlassButtonSettings(): array
{
    return [
        'is_enabled' => false,
        'button_text' => 'دریافت محتوا',
        'line_text' => '📥 مشاهده کردن',
        'display_mode' => 'inline_buttons',
        'button_rows' => 1,
        'button_style' => 'green',
    ];
}

/**
 * @return array<string, mixed>
 */
function getGlassButtonSettingsForFolder(int $ownerTelegramId, int $channelFolderId): array
{
    ensureGlassButtonToolTables();
    $defaults = defaultGlassButtonSettings();

    if ($channelFolderId <= 0) {
        return $defaults;
    }

    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM glass_button_folder_settings
         WHERE owner_telegram_id = ? AND channel_folder_id = ?
         LIMIT 1'
    );
    $stmt->bind_param('ii', $ownerTelegramId, $channelFolderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return $defaults;
    }

    return [
        'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
        'button_text' => (string) ($row['button_text'] ?? $defaults['button_text']),
        'line_text' => (string) ($row['line_text'] ?? $defaults['line_text']),
        'display_mode' => (string) ($row['display_mode'] ?? $defaults['display_mode']),
        'button_rows' => max(1, min(2, (int) ($row['button_rows'] ?? 1))),
        'button_style' => (string) ($row['button_style'] ?? $defaults['button_style']),
        'channel_folder_id' => $channelFolderId,
        'channel_folder_path' => getChannelFolderPathLabel($channelFolderId),
    ];
}

function getActiveGlassButtonSettingsForOwner(int $ownerTelegramId): array
{
    ensureGlassButtonToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT * FROM glass_button_folder_settings
         WHERE owner_telegram_id = ? AND is_enabled = 1
         ORDER BY updated_at DESC
         LIMIT 1'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return defaultGlassButtonSettings();
    }

    return [
        'is_enabled' => true,
        'button_text' => (string) ($row['button_text'] ?? 'دریافت محتوا'),
        'line_text' => (string) ($row['line_text'] ?? '📥 مشاهده کردن'),
        'display_mode' => (string) ($row['display_mode'] ?? 'inline_buttons'),
        'button_rows' => max(1, min(2, (int) ($row['button_rows'] ?? 1))),
        'button_style' => (string) ($row['button_style'] ?? 'green'),
    ];
}

/**
 * @return array{reply_markup:?string,text:string,parse_mode:?string,use_inline:bool}
 */
function buildGuardianGlassButtonDelivery(int $ownerTelegramId, string $url, string $mediaType, string $text): array
{
    $settings = getActiveGlassButtonSettingsForOwner($ownerTelegramId);
    if (empty($settings['is_enabled'])) {
        $typeLabel = autoPostMediaTypeLabel($mediaType);

        return [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => "دریافت {$typeLabel}", 'url' => $url]]],
            ], JSON_UNESCAPED_UNICODE),
            'text' => $text,
            'parse_mode' => null,
            'use_inline' => true,
        ];
    }

    return buildGlassButtonDelivery($ownerTelegramId, 0, $url, $mediaType, $text);
}

function resolveGlassButtonLabel(string $template, string $mediaType): string
{
    $typeLabel = autoPostMediaTypeLabel($mediaType);
    $label = str_replace(['{type}', '{نوع}'], $typeLabel, $template);
    $label = trim($label);
    if ($label === '') {
        return 'دریافت محتوا';
    }

    return $label;
}

function formatGlassInlineButtonText(string $text, string $style): string
{
    if ($style === 'green' && !str_contains($text, '🟢')) {
        return '🟢 ' . $text;
    }

    return $text;
}

/**
 * @return array{reply_markup:?string,text:string,parse_mode:?string,use_inline:bool}
 */
function buildGlassButtonDelivery(
    int $ownerTelegramId,
    int $channelFolderId,
    string $url,
    string $mediaType,
    string $baseCaption
): array {
    $settings = $channelFolderId > 0
        ? getGlassButtonSettingsForFolder($ownerTelegramId, $channelFolderId)
        : getActiveGlassButtonSettingsForOwner($ownerTelegramId);
    if (empty($settings['is_enabled'])) {
        $typeLabel = autoPostMediaTypeLabel($mediaType);
        $buttonText = "دریافت {$typeLabel}";

        return [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[['text' => $buttonText, 'url' => $url]]],
            ], JSON_UNESCAPED_UNICODE),
            'text' => $baseCaption,
            'parse_mode' => null,
            'use_inline' => true,
        ];
    }

    $buttonLabel = resolveGlassButtonLabel((string) $settings['button_text'], $mediaType);
    $lineLabel = trim((string) $settings['line_text']);
    if ($lineLabel === '') {
        $lineLabel = '📥 مشاهده کردن';
    }
    $rows = max(1, min(2, (int) ($settings['button_rows'] ?? 1)));
    $style = (string) ($settings['button_style'] ?? 'green');
    $mode = (string) ($settings['display_mode'] ?? 'inline_buttons');

    if ($mode === 'caption_links') {
        $lines = [];
        for ($i = 0; $i < $rows; $i++) {
            $lines[] = '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                . htmlspecialchars($lineLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a>';
        }
        $captionBlock = trim($baseCaption);
        if ($captionBlock !== '') {
            $captionBlock .= "\n\n" . implode("\n", $lines);
        } else {
            $captionBlock = implode("\n", $lines);
        }

        return [
            'reply_markup' => null,
            'text' => $captionBlock,
            'parse_mode' => 'HTML',
            'use_inline' => false,
        ];
    }

    $inlineText = formatGlassInlineButtonText($buttonLabel, $style);
    $keyboard = [];
    for ($i = 0; $i < $rows; $i++) {
        $keyboard[] = [['text' => $inlineText, 'url' => $url]];
    }

    return [
        'reply_markup' => json_encode(['inline_keyboard' => $keyboard], JSON_UNESCAPED_UNICODE),
        'text' => $baseCaption,
        'parse_mode' => null,
        'use_inline' => true,
    ];
}

/**
 * Pick the best channel-folder id for glass-button settings when posting to a channel.
 */
function resolveGlassButtonFolderForAutoPostChannel(int $ownerTelegramId, int $channelChatId, int $sessionRootFolderId): int
{
    if (!function_exists('getChannelFolderIdsForChat')) {
        require_once __DIR__ . '/hashtag_tools.php';
    }

    $channelFolderIds = getChannelFolderIdsForChat($channelChatId);
    if ($channelFolderIds === []) {
        return $sessionRootFolderId;
    }

    $sessionScope = function_exists('getChannelFolderTreeIds')
        ? getChannelFolderTreeIds($sessionRootFolderId)
        : [$sessionRootFolderId];
    $scopeMap = array_flip($sessionScope);

    $enabledMatch = 0;
    foreach ($channelFolderIds as $folderId) {
        if (!isset($scopeMap[$folderId])) {
            continue;
        }
        $settings = getGlassButtonSettingsForFolder($ownerTelegramId, $folderId);
        if (!empty($settings['is_enabled'])) {
            $enabledMatch = $folderId;
        }
    }
    if ($enabledMatch > 0) {
        return $enabledMatch;
    }

    foreach ($channelFolderIds as $folderId) {
        if (isset($scopeMap[$folderId])) {
            return $folderId;
        }
    }

    return $sessionRootFolderId;
}

/**
 * @return array<string, mixed>
 */
function saveGlassButtonFolderSettings(int $ownerTelegramId, int $channelFolderId, array $payload): array
{
    ensureGlassButtonToolTables();
    if ($channelFolderId <= 0 || !channelFolderExists($channelFolderId)) {
        throw new InvalidArgumentException('invalid_channel_folder');
    }

    $enabled = !empty($payload['is_enabled']) ? 1 : 0;
    $buttonText = trim((string) ($payload['button_text'] ?? 'دریافت محتوا'));
    if ($buttonText === '') {
        $buttonText = 'دریافت محتوا';
    }
    $lineText = trim((string) ($payload['line_text'] ?? '📥 مشاهده کردن'));
    if ($lineText === '') {
        $lineText = '📥 مشاهده کردن';
    }
    $displayMode = (string) ($payload['display_mode'] ?? 'inline_buttons');
    if (!in_array($displayMode, ['inline_buttons', 'caption_links'], true)) {
        $displayMode = 'inline_buttons';
    }
    $buttonRows = max(1, min(2, (int) ($payload['button_rows'] ?? 1)));
    $buttonStyle = (string) ($payload['button_style'] ?? 'green');
    if (!in_array($buttonStyle, ['green', 'default'], true)) {
        $buttonStyle = 'green';
    }

    $db = getDb();
    $stmt = $db->prepare(
        'INSERT INTO glass_button_folder_settings
         (owner_telegram_id, channel_folder_id, is_enabled, button_text, line_text, display_mode, button_rows, button_style)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
           is_enabled = VALUES(is_enabled),
           button_text = VALUES(button_text),
           line_text = VALUES(line_text),
           display_mode = VALUES(display_mode),
           button_rows = VALUES(button_rows),
           button_style = VALUES(button_style),
           updated_at = NOW()'
    );
    $stmt->bind_param(
        'iiisssis',
        $ownerTelegramId,
        $channelFolderId,
        $enabled,
        $buttonText,
        $lineText,
        $displayMode,
        $buttonRows,
        $buttonStyle
    );
    $stmt->execute();
    $stmt->close();

    return getGlassButtonSettingsForFolder($ownerTelegramId, $channelFolderId);
}

/**
 * @return list<array<string, mixed>>
 */
function listGlassButtonFolderSettings(int $ownerTelegramId): array
{
    ensureGlassButtonToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'SELECT channel_folder_id, is_enabled, button_text, line_text, display_mode, button_rows, button_style, updated_at
         FROM glass_button_folder_settings
         WHERE owner_telegram_id = ?
         ORDER BY id DESC'
    );
    $stmt->bind_param('i', $ownerTelegramId);
    $stmt->execute();
    $result = $stmt->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $folderId = (int) ($row['channel_folder_id'] ?? 0);
        $rows[] = [
            'channel_folder_id' => $folderId,
            'channel_folder_path' => getChannelFolderPathLabel($folderId),
            'is_enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'button_text' => (string) ($row['button_text'] ?? 'دریافت محتوا'),
            'line_text' => (string) ($row['line_text'] ?? '📥 مشاهده کردن'),
            'display_mode' => (string) ($row['display_mode'] ?? 'inline_buttons'),
            'button_rows' => (int) ($row['button_rows'] ?? 1),
            'button_style' => (string) ($row['button_style'] ?? 'green'),
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }
    $stmt->close();

    return $rows;
}

function deleteGlassButtonFolderSettings(int $ownerTelegramId, int $channelFolderId): bool
{
    ensureGlassButtonToolTables();
    $db = getDb();
    $stmt = $db->prepare(
        'DELETE FROM glass_button_folder_settings WHERE owner_telegram_id = ? AND channel_folder_id = ?'
    );
    $stmt->bind_param('ii', $ownerTelegramId, $channelFolderId);
    $stmt->execute();
    $deleted = $stmt->affected_rows > 0;
    $stmt->close();

    return $deleted;
}

/**
 * @return array<string, mixed>
 */
function getGlassButtonToolsOverview(int $ownerTelegramId): array
{
    $settings = listGlassButtonFolderSettings($ownerTelegramId);
    $active = array_filter($settings, static fn (array $row): bool => !empty($row['is_enabled']));

    return [
        'settings' => $settings,
        'stats' => [
            'settings_count' => count($settings),
            'active_count' => count($active),
        ],
    ];
}
