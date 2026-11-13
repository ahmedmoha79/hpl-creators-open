<?php
/**
 * Shared helper functions.
 */

/** Escape for HTML output. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Build an absolute URL within the app. */
function u(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

/** Redirect to an app path and stop. */
function redirect(string $path = ''): void
{
    header('Location: ' . (preg_match('~^https?://~', $path) ? $path : u($path)));
    exit;
}

/** Current request path relative to the app base (e.g. /creator/index.php). */
function current_path(): string
{
    $uri = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    if (BASE_URL !== '' && strpos($path, BASE_URL) === 0) {
        $path = substr($path, strlen(BASE_URL));
    }
    return '/' . ltrim($path, '/');
}

/** Store a one-time flash message. */
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
    if ($type === 'error' || $type === 'warning') { // the Brain counts every "no" said to a creator
        try { require_once __DIR__ . '/brain.php'; brain_refusal($message); } catch (Throwable $e) {}
    }
}

/** Render and clear flash messages. */
function render_flashes(): string
{
    if (empty($_SESSION['flash'])) {
        return '';
    }
    $html = '';
    $seen = [];
    foreach ($_SESSION['flash'] as $f) {
        $type = in_array($f['type'], ['success', 'error', 'warning', 'info'], true) ? $f['type'] : 'info';
        // The same message twice (a double tap, a retried request) is shown once.
        $key = $type . '|' . $f['message'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $html .= '<div class="flash flash--' . $type . '">' . e($f['message']) . '</div>';
    }
    unset($_SESSION['flash']);
    return $html;
}

/** CSRF token for the session. */
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

/** Hidden CSRF input. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">';
}

/** Validate a submitted CSRF token. */
function csrf_check(): void
{
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid or expired request token. Please go back and try again.');
    }
}

/**
 * Secret settings (API keys, tokens, passwords) are never printed back into a page.
 * The field stays empty; leaving it empty keeps the saved value, the "Remove" box clears it.
 */
function secret_setting_keys(): array
{
    return ['tiktok_client_secret', 'youtube_api_key', 'facebook_access_token', 'instagram_access_token', 'tiktok_access_token',
        'google_client_secret', 'meta_app_secret', 'meta_access_token', 'smtp_password', 'imap_password',
        'gateway_webhook_secret', 'mtn_client_secret', 'mtn_collection_key', 'airtel_client_secret', 'airtel_collection_key',
        'zamtel_api_key', 'paynow_integration_key', 'push_vapid_private', 'rc_link_salt', 'rc_upload_key_hash',
        'elevenlabs_api_key', 'twilio_auth_token'];
}

function secret_setting_input(string $key, string $id = ''): string
{
    $saved = trim((string)setting($key, '')) !== '';
    $id = $id !== '' ? $id : $key;
    return '<input class="input" type="password" id="' . e($id) . '" name="' . e($key) . '" value="" autocomplete="new-password" placeholder="'
        . ($saved ? 'Saved. Leave empty to keep it' : 'Not set yet') . '">'
        . ($saved ? '<label class="small muted" style="display:flex;gap:6px;align-items:center;margin-top:6px"><input type="checkbox" name="' . e($key) . '__clear" value="1"> Remove the saved one</label>' : '');
}

/** What to save for a secret field: null keeps the saved value, '' clears it. */
function secret_setting_posted(string $key): ?string
{
    if (!empty($_POST[$key . '__clear'])) {
        return '';
    }
    $v = isset($_POST[$key]) && is_string($_POST[$key]) ? trim($_POST[$key]) : '';
    return $v === '' ? null : $v;
}

/** Read a setting (cached per request). */
function setting(string $key, $default = null)
{
    // Loaded once per request; set_setting() keeps this copy in step.
    if (!isset($GLOBALS['hpl_settings_cache'])) {
        $GLOBALS['hpl_settings_cache'] = [];
        try {
            foreach (fetch_all('SELECT setting_key, setting_value FROM settings') as $row) {
                $GLOBALS['hpl_settings_cache'][$row['setting_key']] = $row['setting_value'];
            }
        } catch (Throwable $e) {
            $GLOBALS['hpl_settings_cache'] = [];
        }
    }
    return array_key_exists($key, $GLOBALS['hpl_settings_cache']) ? $GLOBALS['hpl_settings_cache'][$key] : $default;
}

/** Bunny.net promo embed URL, extracted from an iframe snippet or direct URL. */
function promo_embed_url(): string
{
    $raw = trim((string)setting('promo_embed_code', ''));
    if ($raw === '') {
        return '';
    }
    if (preg_match('~<iframe[^>]+src\s*=\s*["\']([^"\']+)["\']~i', $raw, $m)) {
        $raw = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }
    $raw = trim($raw);
    if (strpos($raw, '//') === 0) {
        $raw = 'https:' . $raw;
    }
    return preg_match('~^https?://~i', $raw) ? $raw : '';
}
/** Update (or insert) a setting. */
function set_setting(string $key, $value): void
{
    // The Brain remembers rule changes and judges their effect 3 days later (includes/brain_mind.php).
    static $tracked = ['ready_clips_per_day', 'ready_clip_cooldown_mins', 'ready_warmup_days', 'ready_min_following', 'clip_trust_on', 'clip_trust_min_views',
        'rate_per_1000', 'payout_min', 'auto_approve_ready', 'live_platforms', 'landing_headline', 'sync_batch', 'autosync_minutes', 'inactivity_on'];
    if (in_array($key, $tracked, true)) {
        $old = setting($key, null);
        if ($old !== null && (string)$old !== (string)$value) {
            try {
                require_once __DIR__ . '/brain_mind.php';
                $name = mind_tracked_settings()[$key] ?? $key;
                mind_remember('setting', $name . ': ' . mb_substr((string)$old, 0, 40) . ' → ' . mb_substr((string)$value, 0, 40), mind_signals_for($name));
            } catch (Throwable $e) {
            }
        }
    }
    q(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        [$key, (string)$value]
    );
    if (isset($GLOBALS['hpl_settings_cache'])) {
        $GLOBALS['hpl_settings_cache'][$key] = (string)$value;
    }
}

/** Currency symbol from settings. */
function currency(): string
{
    return setting('currency_symbol', 'K');
}

/** Format an amount with the configured currency symbol. */
function money($amount, bool $symbol = true): string
{
    $amount = round((float)$amount, 2);
    $formatted = number_format(abs($amount), 2);
    $sign = $amount < 0 ? '-' : '';
    return $sign . ($symbol ? currency() : '') . $formatted;
}

/** Format a whole number with thousands separators. */
function num($value): string
{
    return number_format((float)$value);
}

/** Human friendly "x ago" timestamps. */
function time_ago(?string $datetime): string
{
    if (!$datetime) {
        return '—';
    }
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 60) {
        return 'just now';
    }
    $units = [
        31536000 => 'year',
        2592000  => 'month',
        604800   => 'week',
        86400    => 'day',
        3600     => 'hour',
        60       => 'minute',
    ];
    foreach ($units as $secs => $label) {
        if ($diff >= $secs) {
            $n = (int)floor($diff / $secs);
            return $n . ' ' . $label . ($n > 1 ? 's' : '') . ' ago';
        }
    }
    return 'just now';
}

/** Supported social/content platforms. */
function platforms(): array
{
    return [
        'facebook'   => 'Facebook',
        'instagram'  => 'Instagram',
        'tiktok'     => 'TikTok',
        'youtube'    => 'YouTube',
        'google_ads' => 'Google Ads',
        'twitter'    => 'X (Twitter)',
        'snapchat'   => 'Snapchat',
        'other'      => 'Other',
    ];
}

/** Human label for a platform key. */
function platform_label(?string $key): string
{
    $all = platforms();
    return $all[$key] ?? ucfirst((string)$key);
}

/**
 * Platforms creators can post on right now. Owner decision 2026-11-07: TikTok only for now;
 * Instagram, Facebook, YouTube and X show as "Coming soon". To open one later, add it to the
 * live_platforms setting (comma list, e.g. "tiktok,instagram").
 */
function live_platforms(): array
{
    $l = array_values(array_filter(array_map('trim', explode(',', strtolower((string)setting('live_platforms', 'tiktok'))))));
    return $l ?: ['tiktok'];
}

function platform_is_live(?string $key): bool
{
    $key = strtolower((string)$key);
    $live = live_platforms();
    return in_array($key, $live, true) || ($key === 'twitter' && in_array('x', $live, true)) || ($key === 'x' && in_array('twitter', $live, true));
}

/** Names of the platforms that are not open yet, for a "Coming soon" line. */
function coming_soon_platforms(): array
{
    $out = [];
    foreach (['instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'x' => 'X'] as $k => $name) {
        if (!platform_is_live($k)) { $out[$k] = $name; }
    }
    return $out;
}

/** Small "Coming soon" chips for the platforms that are not open yet. */
function coming_soon_html(string $lead = 'Coming soon:'): string
{
    $soon = coming_soon_platforms();
    if (!$soon) { return ''; }
    $h = '<div class="soon-row"><span class="soon-row__lead">' . e($lead) . '</span>';
    foreach ($soon as $k => $name) {
        $h .= '<span class="soon-chip">' . (function_exists('icon') && $k !== 'x' ? icon($k) : '𝕏') . e($name) . '</span>';
    }
    return $h . '</div>';
}

/** Nice badge class for a status string. */
function status_class(string $status): string
{
    $map = [
        'approved' => 'ok', 'active' => 'ok', 'paid' => 'ok', 'passed' => 'ok',
        'pending'  => 'warn', 'pending_review' => 'warn', 'none' => 'muted',
        'rejected' => 'bad', 'failed' => 'bad', 'suspended' => 'bad', 'flagged' => 'bad',
    ];
    return $map[$status] ?? 'muted';
}

/** Supported mobile money providers for payouts. */
function mobile_providers(): array
{
    return [
        'mtn'    => ['label' => 'MTN Mobile Money', 'icon' => '&#128241;'],
        'airtel' => ['label' => 'Airtel Money', 'icon' => '&#128241;'],
        'zamtel' => ['label' => 'Zamtel Kwacha', 'icon' => '&#128241;'],
        'cash'   => ['label' => 'Cash / Pickup', 'icon' => '&#128181;'],
        'bank'   => ['label' => 'Bank transfer', 'icon' => '&#127974;'],
    ];
}

/** Normalise a local mobile number: keeps leading +, strips spaces/dashes. */
function normalize_phone(string $phone): string
{
    return preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
}

/**
 * A Zambian mobile number in local form (0 + 9 digits), or null if it is not one.
 * Accepts 0971234567, 971234567, +260971234567, 260 97 123 4567.
 */
function zm_phone_local(string $phone): ?string
{
    $d = preg_replace('/\D/', '', $phone) ?? '';
    if (strlen($d) === 12 && strpos($d, '260') === 0) {
        $d = '0' . substr($d, 3);
    } elseif (strlen($d) === 9) {
        $d = '0' . $d;
    }
    return preg_match('/^0[579]\d{8}$/', $d) ? $d : null;
}

/** Which network a Zambian mobile number belongs to: mtn, airtel, zamtel, zed or null. */
function zm_phone_network(string $phone): ?string
{
    $local = zm_phone_local($phone);
    if ($local === null) {
        return null;
    }
    $map = [
        '096' => 'mtn', '076' => 'mtn', '056' => 'mtn',
        '097' => 'airtel', '077' => 'airtel', '057' => 'airtel',
        '095' => 'zamtel', '075' => 'zamtel', '055' => 'zamtel',
        '098' => 'zed', '078' => 'zed', '058' => 'zed',
    ];
    return $map[substr($local, 0, 3)] ?? null;
}

/** 0971234567 -> 097 123 4567 */
function zm_phone_pretty(string $phone): string
{
    $l = zm_phone_local($phone);
    return $l ? substr($l, 0, 3) . ' ' . substr($l, 3, 3) . ' ' . substr($l, 6) : $phone;
}

/** Public URL for a mobile payment provider logo. */
function provider_logo(?string $key): string
{
    $logos = [
        'mtn'    => 'assets/media/payments/mtn.svg',
        'airtel' => 'assets/media/payments/airtel.svg',
        'zamtel' => 'assets/media/payments/zamtel.svg',
    ];
    return isset($logos[$key ?? '']) ? u($logos[$key]) : '';
}

/** Display a payment provider label (with icon) from its key. */
function provider_badge(?string $key): string
{
    $providers = mobile_providers();
    $p = $providers[$key ?? ''] ?? null;
    if (!$p) {
        return '&mdash;';
    }
    return e($p['label']);
}

/** Validate a URL and normalise it (adds https:// if the scheme is missing). */
function normalize_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
}

/** Read a POST string. */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Read a GET string. */
function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

/** Simple JSON response helper. */
function json_response(array $data, int $code = 200): void
{
    if (isset($data['ok']) && $data['ok'] === false && !empty($data['error']) && is_string($data['error'])) {
        try { require_once __DIR__ . '/brain.php'; brain_refusal($data['error']); } catch (Throwable $e) {}
    }
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/** Render a small inline SVG platform icon. */
function platform_icon(string $platform): string
{
    $icons = [
        'facebook'   => '<path d="M13 10h3l.5-3H13V5.5c0-.9.3-1.5 1.6-1.5H17V1.2C16.6 1.1 15.5 1 14.3 1 11.8 1 10 2.5 10 5.2V7H7v3h3v9h3v-9z"/>',
        'instagram'  => '<path d="M12 2c2.7 0 3 0 4.1.1 1 0 1.6.2 2 .4.5.2.9.5 1.3.9.4.4.7.8.9 1.3.2.4.4 1 .4 2C20.8 7.8 21 8.1 21 12s0 4.2-.1 5.3c0 1-.2 1.6-.4 2-.2.5-.5.9-.9 1.3-.4.4-.8.7-1.3.9-.4.2-1 .4-2 .4-1.1.1-1.4.1-4.1.1s-3 0-4.1-.1c-1 0-1.6-.2-2-.4-.5-.2-.9-.5-1.3-.9-.4-.4-.7-.8-.9-1.3-.2-.4-.4-1-.4-2C2.2 16.2 2 15.9 2 12s0-4.2.1-5.3c0-1 .2-1.6.4-2 .2-.5.5-.9.9-1.3.4-.4.8-.7 1.3-.9.4-.2 1-.4 2-.4C9 2 9.3 2 12 2zm0 4.9a5.1 5.1 0 100 10.2 5.1 5.1 0 000-10.2zm0 8.4a3.3 3.3 0 110-6.6 3.3 3.3 0 010 6.6zM18 7.6a1.2 1.2 0 11-2.4 0 1.2 1.2 0 012.4 0z"/>',
        'tiktok'     => '<path d="M16.5 2c.3 2.1 1.6 3.6 3.7 3.9v2.5c-1.4.1-2.7-.3-3.9-1v6.9c0 4.3-3.4 6.7-6.8 6.1-2.5-.4-4.4-2.6-4.4-5.3 0-3 2.5-5.4 5.6-5.1v2.6c-.4-.1-.8-.2-1.3-.1-1.3.1-2.3 1.2-2.3 2.6 0 1.4 1.2 2.6 2.6 2.5 1.4 0 2.5-1.1 2.5-2.6V2h2.3z"/>',
        'youtube'    => '<path d="M22 8.2s-.2-1.5-.9-2.1c-.8-.9-1.7-.9-2.1-.9C16.1 5 12 5 12 5s-4.1 0-7 .2c-.4 0-1.3 0-2.1.9C2.2 6.7 2 8.2 2 8.2S1.8 10 1.8 11.8v1.3c0 1.8.2 3.6.2 3.6s.2 1.5.9 2.1c.8.9 1.9.9 2.4 1 1.7.2 6.7.2 6.7.2s4.1 0 7-.2c.4 0 1.3-.1 2.1-.9.7-.7.9-2.1.9-2.1s.2-1.8.2-3.6v-1.3c0-1.9-.2-3.7-.2-3.7zM9.8 15V9l5.4 3-5.4 3z"/>',
        'google_ads' => '<path d="M3.3 17.5l4-6.9 3.4 2-4 6.9-3.4-2zM12 2L6.7 11.1l3.4 2L17.5 4.2 12 2zm1 13.5a2.5 2.5 0 105 0 2.5 2.5 0 00-5 0z"/>',
        'twitter'    => '<path d="M17.7 3h3.3l-7.2 8.2L22 21h-6.6l-5.2-6.7L4.3 21H1l7.7-8.8L1.3 3h6.8l4.7 6.2L17.7 3zm-1.2 16h1.8L7.6 4.9H5.7L16.5 19z"/>',
        'snapchat'   => '<path d="M12 2c2.5 0 4.3 1.9 4.4 4.5.1.8 0 1.5 0 2.1.4.2.9 0 1.3-.1.6-.1 1.2.2 1.2.8 0 .7-.9.9-1.5 1.1-.3.1-.8.3-.7.7.4 1.4 1.7 2.7 3.1 3.1.4.1.5.5.3.8-.4.7-1.5 1-2.3 1.2-.2.1-.4.3-.4.6-.1.4-.2.8-.6.8-.5 0-1.1-.3-1.8-.3-.5 0-.8.2-1.2.5-.6.5-1.3 1-2.4 1s-1.8-.5-2.4-1c-.4-.3-.7-.5-1.2-.5-.7 0-1.3.3-1.8.3-.4 0-.5-.4-.6-.8-.1-.3-.3-.5-.4-.6-.8-.2-1.9-.5-2.3-1.2-.2-.3-.1-.7.3-.8 1.4-.4 2.7-1.7 3.1-3.1.1-.4-.4-.6-.7-.7-.6-.2-1.5-.4-1.5-1.1 0-.6.6-.9 1.2-.8.4.1.9.3 1.3.1 0-.6-.1-1.3 0-2.1C7.7 3.9 9.5 2 12 2z"/>',
        'other'      => '<path d="M10 13a5 5 0 007.1 0l2.4-2.4a5 5 0 00-7.1-7.1L11 4.9a1 1 0 001.4 1.4l1.4-1.4a3 3 0 014.3 4.3L15.7 12a3 3 0 01-4.3 0 1 1 0 00-1.4 1.4zm4-2a1 1 0 00-1.4-1.4l-2.1 2.1a3 3 0 01-4.3 0 3 3 0 010-4.3l2.4-2.4a3 3 0 014.3 0 1 1 0 001.4-1.4 5 5 0 00-7.1 0L4.9 6.9a5 5 0 007.1 7.1l2-2z"/>',
    ];
    $path = $icons[$platform] ?? $icons['other'];
    return '<svg class="icon icon--' . e($platform) . '" viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true">' . $path . '</svg>';
}

/* ---------------------------------------------------------------------------
 * Community chat + presence
 * ------------------------------------------------------------------------ */

/** How long (minutes) a user counts as "active" after their last page view. */
function presence_window_minutes(): int
{
    return 5;
}

/** Mark a user as seen right now (powers the "active now" list). */
function touch_presence(int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    // At most one write per user per minute: chat polling every few seconds must not
    // turn into a database write every few seconds for everyone online.
    try {
        q('UPDATE users SET last_seen = NOW() WHERE id = ? AND (last_seen IS NULL OR last_seen < NOW() - INTERVAL 60 SECOND)', [$userId]);
    } catch (Throwable $e) {
        // Presence is best-effort; never break the page over it.
    }
}

/**
 * Validate + store a community message.
 * Returns null on success, or an error string.
 */
function community_post(int $userId, string $text, ?int &$newId = null, string $channel = 'general'): ?string
{
    $text = trim($text);
    if ($text === '') {
        return 'Write something first.';
    }
    if (mb_strlen($text) > 1000) {
        return 'Keep messages under 1,000 characters.';
    }
    try {
        // Burst limit (normal back-and-forth is fine) + no identical repeats.
        $recent = (int)fetch_val('SELECT COUNT(*) FROM community_messages WHERE user_id = ? AND created_at > NOW() - INTERVAL 20 SECOND', [$userId], 0);
        if ($recent >= 6) {
            return 'You are sending messages very fast. Wait a few seconds.';
        }
        $last = fetch_one('SELECT message, created_at FROM community_messages WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        if ($last && $last['message'] === $text && (time() - strtotime($last['created_at'])) < 120) {
            return 'You just sent that message.';
        }
        if ($channel !== 'general' && function_exists('chat_has_rooms') && chat_has_rooms()) {
            q('INSERT INTO community_messages (user_id, message, channel) VALUES (?, ?, ?)', [$userId, $text, $channel]);
        } else {
            q('INSERT INTO community_messages (user_id, message) VALUES (?, ?)', [$userId, $text]);
        }
        $newId = (int)db()->lastInsertId();
        require_once __DIR__ . '/chat_cache.php';
        chat_bump();
        // Owner, 2026-11-13: people in a group are told about new messages (bell, phone with sound), after the answer is sent.
        $nfRoom = $channel;
        register_shutdown_function(function () use ($userId, $text, $nfRoom) {
            try { require_once __DIR__ . '/community.php'; chat_notify_room($nfRoom, $userId, $text); } catch (Throwable $e) {}
        });
    } catch (Throwable $e) {
        return 'Your message could not be sent. Please try again.';
    }
    return null;
}

/**
 * Latest community messages (oldest first), with author name + role. Deleted ones are hidden.
 * $channel = one chat room ('' = every room, for the team's moderation page).
 */
function community_messages(int $limit = 100, int $sinceId = 0, int $beforeId = 0, string $channel = ''): array
{
    $limit = max(1, min(200, $limit));
    $rooms = function_exists('chat_has_rooms') && chat_has_rooms();
    $cols = 'm.id, m.user_id, m.message, m.created_at, u.full_name, u.role, u.avatar_url' . ($rooms ? ', m.channel, m.kind, m.mentions' : '');
    $where = [];
    $args = [];
    if (community_has_soft_delete()) {
        $where[] = 'm.deleted_at IS NULL';
    }
    if ($rooms && $channel !== '') {
        $where[] = 'm.channel = ?';
        $args[] = $channel;
    }
    if ($sinceId > 0) {
        $where[] = 'm.id > ?';
        $args[] = $sinceId;
        return fetch_all("SELECT $cols FROM community_messages m JOIN users u ON u.id = m.user_id
             WHERE " . implode(' AND ', $where) . ' ORDER BY m.id ASC LIMIT ' . $limit, $args);
    }
    if ($beforeId > 0) {
        $where[] = 'm.id < ?';
        $args[] = $beforeId;
    }
    $rows = fetch_all("SELECT $cols FROM community_messages m JOIN users u ON u.id = m.user_id
         " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY m.id DESC LIMIT ' . $limit, $args);
    return array_reverse($rows);
}

/** Has the soft-delete column been added yet? (cached per request) */
function community_has_soft_delete(): bool
{
    static $has = null;
    if ($has === null) {
        try {
            $has = (bool)fetch_one("SHOW COLUMNS FROM community_messages LIKE 'deleted_at'");
        } catch (Throwable $e) {
            $has = false;
        }
    }
    return $has;
}

/** One chat message as JSON for the browser (the browser formats time and grouping). */
function community_message_json(array $m, int $viewerId): array
{
    $name = (string)($m['full_name'] ?? 'Member');
    return [
        'id'      => (int)$m['id'],
        'uid'     => (int)$m['user_id'],
        'name'    => $name,
        'role'    => (string)($m['role'] ?? 'creator'),
        'initial' => strtoupper(mb_substr($name !== '' ? $name : '?', 0, 1)),
        'avatar'  => (string)($m['avatar_url'] ?? ''),
        'text'    => (string)$m['message'],
        'ts'      => strtotime((string)$m['created_at']) ?: time(),
        'mine'    => (int)$m['user_id'] === $viewerId,
        'ch'      => (string)($m['channel'] ?? 'general'),
        'kind'    => (string)($m['kind'] ?? 'msg'),
    ];
}

/** Users seen within the presence window, creators first then admins. */
function active_users(int $minutes = 0): array
{
    if ($minutes <= 0) {
        $minutes = presence_window_minutes();
    }
    try {
        return fetch_all(
            'SELECT id, full_name, role, last_seen FROM users
             WHERE status = "active" AND last_seen >= (NOW() - INTERVAL ? MINUTE)
             ORDER BY (role = "creator") DESC, full_name ASC LIMIT 100',
            [$minutes]
        );
    } catch (Throwable $e) {
        return [];
    }
}

/* ---------------------------------------------------------------------------
 * Direct messages (1-on-1, Whop-style inbox + suggested contacts)
 * ------------------------------------------------------------------------ */

/** Find the 1-on-1 thread between two users, or create it. Returns thread id (0 on failure/self). */
function dm_thread_between(int $a, int $b): int
{
    if ($a <= 0 || $b <= 0 || $a === $b) {
        return 0;
    }
    try {
        $row = fetch_one(
            'SELECT t.id FROM dm_threads t
             JOIN dm_participants p1 ON p1.thread_id = t.id AND p1.user_id = ?
             JOIN dm_participants p2 ON p2.thread_id = t.id AND p2.user_id = ?
             LEFT JOIN dm_participants p3 ON p3.thread_id = t.id AND p3.user_id NOT IN (?, ?)
             WHERE p3.thread_id IS NULL LIMIT 1',
            [$a, $b, $a, $b]
        );
        if ($row) {
            return (int)$row['id'];
        }
        q('INSERT INTO dm_threads () VALUES ()');
        $id = (int)db()->lastInsertId();
        q('INSERT INTO dm_participants (thread_id, user_id) VALUES (?, ?), (?, ?)', [$id, $a, $id, $b]);
        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Is this user a participant (or an admin) of the thread? */
function dm_can_view(int $threadId, array $user): bool
{
    if (($user['role'] ?? '') === 'admin') {
        return true;
    }
    $row = fetch_one(
        'SELECT thread_id FROM dm_participants WHERE thread_id = ? AND user_id = ?',
        [$threadId, (int)$user['id']]
    );
    return $row !== null;
}

/** Other participant of a 1-on-1 thread (id, name, role, active flag). */
function dm_other_party(int $threadId, int $me): ?array
{
    return fetch_one(
        'SELECT u.id, u.full_name, u.role,
                (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active
         FROM dm_participants p JOIN users u ON u.id = p.user_id
         WHERE p.thread_id = ? AND p.user_id <> ? LIMIT 1',
        [presence_window_minutes(), $threadId, $me]
    );
}

/**
 * Validate + store a DM. Returns null on success, or an error string.
 * Touching updated_at keeps the inbox sorted.
 */
function dm_send(int $threadId, int $senderId, string $text): ?string
{
    $text = trim($text);
    if ($text === '') {
        return 'Write something first.';
    }
    if (mb_strlen($text) > 1000) {
        return 'Keep messages under 1,000 characters.';
    }
    try {
        $part = fetch_one(
            'SELECT thread_id FROM dm_participants WHERE thread_id = ? AND user_id = ?',
            [$threadId, $senderId]
        );
        if (!$part) {
            return 'You are not part of this conversation.';
        }
        $last = fetch_one(
            'SELECT created_at FROM dm_messages WHERE thread_id = ? AND sender_id = ? ORDER BY id DESC LIMIT 1',
            [$threadId, $senderId]
        );
        if ($last && (time() - strtotime($last['created_at'])) < 3) {
            return 'Slow down a little — wait a few seconds between messages.';
        }
        // Creators: at most 40 messages in 10 minutes across all chats (stops spam to many people).
        if (($GLOBALS['hpl_dm_sender_role'][$senderId] ??= (string)fetch_val('SELECT role FROM users WHERE id = ?', [$senderId], '')) !== 'admin'
            && (int)fetch_val('SELECT COUNT(*) FROM dm_messages WHERE sender_id = ? AND is_bot = 0 AND created_at > NOW() - INTERVAL 10 MINUTE', [$senderId], 0) >= 40) {
            return 'You have sent a lot of messages. Wait a few minutes, then try again.';
        }
        q('INSERT INTO dm_messages (thread_id, sender_id, message) VALUES (?, ?, ?)', [$threadId, $senderId, $text]); require_once __DIR__ . '/fastlane.php'; fl_bump('dm');
        q('UPDATE dm_threads SET updated_at = NOW() WHERE id = ?', [$threadId]);
    } catch (Throwable $e) {
        return 'Your message could not be sent. Please try again.';
    }
    return null;
}

/** Messages in a thread (oldest first). */
function dm_messages(int $threadId, int $limit = 100, int $sinceId = 0): array
{
    $limit = max(1, min(200, $limit));
    if ($sinceId > 0) {
        return fetch_all(
            'SELECT m.*, u.full_name, u.role FROM dm_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.thread_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT ' . $limit,
            [$threadId, $sinceId]
        );
    }
    $rows = fetch_all(
        'SELECT m.*, u.full_name, u.role FROM dm_messages m
         JOIN users u ON u.id = m.sender_id
         WHERE m.thread_id = ? ORDER BY m.id DESC LIMIT ' . $limit,
        [$threadId]
    );
    return array_reverse($rows);
}

/** Inbox: threads for a user with preview, unread count + other-party info. */
function dm_inbox(int $userId): array
{
    try {
        $threads = fetch_all(
            'SELECT t.id, t.updated_at,
                    (SELECT m.message FROM dm_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS preview,
                    (SELECT m.created_at FROM dm_messages m WHERE m.thread_id = t.id ORDER BY m.id DESC LIMIT 1) AS preview_at,
                    (SELECT COUNT(*) FROM dm_messages m WHERE m.thread_id = t.id AND m.sender_id <> ?
                     AND m.is_bot = 0
                     AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)) AS unread
             FROM dm_threads t
             JOIN dm_participants p ON p.thread_id = t.id AND p.user_id = ?
             ORDER BY t.updated_at DESC LIMIT 50',
            [$userId, $userId]
        );
    } catch (Throwable $e) {
        return [];
    }
    $win = presence_window_minutes();
    foreach ($threads as &$t) {
        $t['other'] = fetch_one(
            'SELECT u.id, u.full_name, u.role,
                    (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active
             FROM dm_participants p JOIN users u ON u.id = p.user_id
             WHERE p.thread_id = ? AND p.user_id <> ? LIMIT 1',
            [$win, (int)$t['id'], $userId]
        );
    }
    return $threads;
}

/** The admin persona that runs the support bot (lowest-id admin). */
function support_admin_id(): int
{
    static $id = null;
    if ($id === null) {
        $row = fetch_one('SELECT id FROM users WHERE role = "admin" AND status = "active" ORDER BY id ASC LIMIT 1');
        $id = $row ? (int)$row['id'] : 0;
    }
    return $id;
}

/**
 * Find (or create) the support thread for a user: a 1-on-1 thread with the
 * support admin flagged is_support=1, so the bot can auto-answer inside it
 * and a real admin can jump in later.
 */
function support_thread_for(int $userId): int
{
    $admin = support_admin_id();
    if ($admin <= 0 || $userId <= 0 || $admin === $userId) {
        return 0;
    }
    try {
        $row = fetch_one(
            'SELECT t.id FROM dm_threads t
             JOIN dm_participants p ON p.thread_id = t.id AND p.user_id = ?
             WHERE t.is_support = 1
               AND EXISTS (SELECT 1 FROM dm_participants p2 WHERE p2.thread_id = t.id AND p2.user_id = ?)
             ORDER BY t.id DESC LIMIT 1',
            [$userId, $admin]
        );
        if ($row) {
            return (int)$row['id'];
        }
        $id = dm_thread_between($userId, $admin);
        if ($id > 0) {
            q('UPDATE dm_threads SET is_support = 1 WHERE id = ?', [$id]);
        }
        return $id;
    } catch (Throwable $e) {
        return 0;
    }
}

/** Store an automated answer in a thread (from the support bot persona). */
function dm_send_bot(int $threadId, string $text): ?array
{
    $admin = support_admin_id();
    if ($admin <= 0) {
        return null;
    }
    try {
        q('INSERT INTO dm_messages (thread_id, sender_id, message, is_bot) VALUES (?, ?, ?, 1)', [$threadId, $admin, $text]); require_once __DIR__ . '/fastlane.php'; fl_bump('dm');
        q('UPDATE dm_threads SET updated_at = NOW() WHERE id = ?', [$threadId]);
        return fetch_one(
            'SELECT m.*, u.full_name, u.role FROM dm_messages m JOIN users u ON u.id = m.sender_id
             WHERE m.thread_id = ? ORDER BY m.id DESC LIMIT 1',
            [$threadId]
        );
    } catch (Throwable $e) {
        return null;
    }
}

/** Total unread DM count for the sidebar badge ($openThread: the conversation on screen, never counted). */
function dm_unread_total(int $userId, int $openThread = 0): int
{
    try {
        return (int)fetch_val(
            'SELECT COUNT(*) FROM dm_messages m
             JOIN dm_participants p ON p.thread_id = m.thread_id AND p.user_id = ?
             WHERE m.sender_id <> ? AND m.is_bot = 0 AND m.thread_id <> ? AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)',
            [$userId, $userId, $openThread],
            0
        );
    } catch (Throwable $e) {
        return 0;
    }
}

/** Mark a thread as read for a user, together with the bell notifications that point to it. */
function dm_mark_read(int $threadId, int $userId): void
{
    try {
        q('UPDATE dm_participants SET last_read_at = NOW() WHERE thread_id = ? AND user_id = ?', [$threadId, $userId]);
        q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0 AND url IN (?, ?)',
          [$userId, 'admin/messages.php?thread=' . $threadId, 'creator/messages.php?thread=' . $threadId]);
    } catch (Throwable $e) {
        // Best-effort.
    }
}

/** Is this person looking at the conversation right now? (The open chat refreshes every few seconds.) */
function dm_is_viewing(int $threadId, int $userId): bool
{
    try {
        return (int)fetch_val('SELECT COUNT(*) FROM dm_participants WHERE thread_id = ? AND user_id = ? AND last_read_at >= (NOW() - INTERVAL 20 SECOND)', [$threadId, $userId], 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/** Is any admin looking at this conversation right now? */
function dm_team_viewing(int $threadId): bool
{
    try {
        return (int)fetch_val('SELECT COUNT(*) FROM dm_participants p JOIN users u ON u.id = p.user_id
                               WHERE p.thread_id = ? AND u.role = "admin" AND p.last_read_at >= (NOW() - INTERVAL 20 SECOND)', [$threadId], 0) > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Suggested contacts, Whop-style: active creators + admins first,
 * then other creators — excluding self and existing conversations.
 */
function dm_suggested(int $userId, int $limit = 8): array
{
    $limit = max(1, min(20, $limit));
    try {
        return fetch_all(
            'SELECT u.id, u.full_name, u.role,
                    (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active
             FROM users u
             WHERE u.id <> ? AND u.role IN ("creator", "admin") AND u.status = "active"
               AND NOT EXISTS (
                   SELECT 1 FROM dm_participants mine
                   JOIN dm_participants theirs ON theirs.thread_id = mine.thread_id
                   WHERE mine.user_id = ? AND theirs.user_id = u.id
                     AND (SELECT COUNT(*) FROM dm_participants c WHERE c.thread_id = mine.thread_id) = 2
               )
             ORDER BY is_active DESC, (u.role = "admin") DESC, u.full_name ASC
             LIMIT ' . $limit,
            [presence_window_minutes(), $userId, $userId]
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** Server-rendered HTML for one DM bubble (mine right-aligned). */
function render_dm_message(array $m, int $me): string
{
    $senderId = (int)($m['sender_id'] ?? 0);
    $mine = $senderId === $me && $me > 0;
    $isBot = (int)($m['is_bot'] ?? 0) === 1;
    $isAdmin = !$isBot && ($m['role'] ?? '') === 'admin';
    $name = $isBot ? 'HPL Help' : ($m['full_name'] ?? 'Unknown');
    $initial = $isBot ? 'H' : strtoupper(mb_substr($m['full_name'] ?? '?', 0, 1));
    return '<div class="dm-row' . ($mine ? ' dm-row--mine' : '') . '" data-id="' . (int)$m['id'] . '">'
        . '<div class="chat-msg' . ($isBot ? ' chat-msg--bot' : '') . ($isAdmin ? ' chat-msg--admin' : '') . '" style="flex:1;max-width:85%">'
        . '<div class="avatar avatar--sm">' . e($initial) . '</div>'
        . '<div class="chat-msg__body">'
        . '<div class="chat-msg__head"><strong>' . e($name) . '</strong>'
        . ($isBot ? ' <span class="badge">Bot</span>' : '')
        . ($isAdmin ? ' <span class="badge ok">Admin</span>' : '')
        . ' <span class="muted small">' . e(time_ago($m['created_at'] ?? null)) . '</span></div>'
        . '<div class="chat-msg__text">' . ($isBot ? ($m['message'] ?? '') : nl2br(e($m['message'] ?? ''))) . '</div>'
        . '</div></div></div>';
}

/** Server-rendered HTML for one chat message (initial feed + admin view). */
function render_community_message(array $m): string
{
    $isAdmin = ($m['role'] ?? '') === 'admin';
    $initial = strtoupper(mb_substr($m['full_name'] ?? '?', 0, 1));
    return '<div class="chat-msg' . ($isAdmin ? ' chat-msg--admin' : '') . '" data-id="' . (int)$m['id'] . '">'
        . '<div class="avatar avatar--sm">' . e($initial) . '</div>'
        . '<div class="chat-msg__body">'
        . '<div class="chat-msg__head"><strong>' . e($m['full_name'] ?? 'Unknown') . '</strong>'
        . ($isAdmin ? ' <span class="badge ok">Admin</span>' : ' <span class="badge">Creator</span>')
        . ' <span class="muted small">' . e(time_ago($m['created_at'] ?? null)) . '</span></div>'
        . '<div class="chat-msg__text">' . nl2br(e($m['message'] ?? '')) . '</div>'
        . '</div></div>';
}

/**
 * Simple failure counter kept in the database (survives new sessions), for logins and bonus codes.
 * throttle_blocked(): true while $key has had $max failures within $window seconds.
 */
function throttle_blocked(string $key, int $max, int $window): bool
{
    try {
        $r = fetch_one('SELECT fails, first_at FROM auth_throttle WHERE k = ?', [substr($key, 0, 120)]);
    } catch (Throwable $e) {
        return false; // table not created yet
    }
    return $r && (int)$r['first_at'] > time() - $window && (int)$r['fails'] >= $max;
}

function throttle_fail(string $key, int $window): void
{
    $now = time();
    try {
        q('INSERT INTO auth_throttle (k, fails, first_at) VALUES (?, 1, ?)
           ON DUPLICATE KEY UPDATE fails = IF(first_at < ? , 1, fails + 1), first_at = IF(first_at < ?, VALUES(first_at), first_at)',
          [substr($key, 0, 120), $now, $now - $window, $now - $window]);
    } catch (Throwable $e) {
    }
}

function throttle_clear(string $key): void
{
    try {
        q('DELETE FROM auth_throttle WHERE k = ?', [substr($key, 0, 120)]);
    } catch (Throwable $e) {
    }
}

/**
 * Tell a creator the team's decision on their approval, in the site and by email,
 * so people who don't open the site still learn the result.
 */
/** Smallest withdrawal a creator can ask for (one default used everywhere). */
function payout_min(): float
{
    return (float)setting('min_payout', 50);
}

/** Largest single withdrawal (one default used everywhere). */
function payout_max(): float
{
    return (float)setting('max_payout', 500);
}

/**
 * After the team rejects a work video. A creator who is already approved keeps their
 * approval (removing it is only done with the Revoke button). A creator who is not
 * approved goes back to "rejected" so they can send a new video, and is told why.
 * Returns true when the creator was told.
 */
function portfolio_after_reject(int $uid, string $note): bool
{
    $qualified = (int)fetch_val('SELECT qualified FROM users WHERE id = ?', [$uid], 0) === 1;
    if ($qualified) {
        return false;
    }
    $note = trim($note) !== '' ? trim($note) : 'Please send a short video (15 to 60 seconds) that you edited yourself, showing the most exciting moment.';
    q('UPDATE users SET portfolio_status = "rejected", rejection_note = ? WHERE id = ? AND qualified = 0', [$note, $uid]);
    creator_decision_notify($uid, false, $note);
    return true;
}

function creator_decision_notify(int $uid, bool $approved, string $note = ''): void
{
    $u = fetch_one('SELECT full_name, email FROM users WHERE id = ?', [$uid]);
    if (!$u) {
        return;
    }
    if ($approved) {
        $title = 'You are approved! Start earning';
        $body = 'Your video was approved. Open Campaigns, join one, download the footage, post your clip and send us the link.';
        $path = 'creator/index.php?tab=campaigns';
        $btn = 'Open campaigns';
    } else {
        $title = 'Your video was not approved yet';
        $body = ($note !== '' ? $note . ' ' : '') . 'Send us a better video and we will check it again.';
        $path = 'creator/portfolio.php';
        $btn = 'Send a new video';
    }
    notif_add($uid, 'portfolio', $title, $body, $path);
    if (function_exists('app_mail') && !empty($u['email'])) {
        $first = explode(' ', trim((string)$u['full_name']))[0] ?: 'there';
        $html = '<p>Hi ' . e($first) . ',</p><p>' . e($body) . '</p>'
            . '<p style="margin:20px 0"><a href="' . e(abs_url($path)) . '" style="display:inline-block;background:#d4af37;color:#071022;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700">' . e($btn) . '</a></p>';
        @app_mail((string)$u['email'], $title . ' - ' . APP_NAME, $html, $body . "\n\n" . abs_url($path));
    }
}

/** Full https:// link to a page on this site, for emails and other places outside the site. */
function abs_url(string $path = ''): string
{
    $url = u($path);
    if (preg_match('~^https?://~i', $url)) {
        return $url;
    }
    $host = preg_replace('/[^a-z0-9.:-]/i', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?: 'golddetectorinzambia.com';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $host !== 'localhost' && strpos($host, '127.0.0.1') !== 0;
    return ($https ? 'https://' : 'http://') . $host . '/' . ltrim($url, '/');
}

// ---------------------------------------------------------------------------
// Auto-load optional feature libraries.
// The live server's config.php predates these modules, so we pull them in here
// (functions.php is always loaded). require_once keeps single inclusion.
// ---------------------------------------------------------------------------
foreach (['smtp_mail.php', 'imap_mail.php', 'support_faq.php', 'oauth.php', 'mailer.php'] as $__lib) {
    if (is_file(__DIR__ . '/' . $__lib)) {
        require_once __DIR__ . '/' . $__lib;
    }
}
unset($__lib);

// ---------------------------------------------------------------------------
// Tutorial catalogue (shared by the public tutorial page and the admin page).
// Each clip maps to its own MP4 file under assets/media/tutorials/.
// ---------------------------------------------------------------------------
function tutorials_catalogue(): array
{
    return [
        ['id' => 'welcome', 'title' => 'Welcome to the HPL Creator Program', 'desc' => 'Who HPL is, how the program works and what your job as a clipper is.', 'file' => 'lesson-01-welcome.mp4', 'tag' => 'Lesson 01', 'link' => 'register.php', 'link_label' => 'Create account'],
        ['id' => 'what-is-clipping', 'title' => 'What is clipping?', 'desc' => 'How one long video becomes many short moments people actually watch.', 'file' => 'lesson-02-what-is-clipping.mp4', 'tag' => 'Lesson 02', 'link' => 'creator/library.php', 'link_label' => 'Footage library'],
        ['id' => 'get-approved', 'title' => 'Getting approved', 'desc' => 'Create your account, submit a work sample and link your social page.', 'file' => 'lesson-03-get-approved.mp4', 'tag' => 'Lesson 03', 'link' => 'creator/portfolio.php', 'link_label' => 'Submit your work'],
        ['id' => 'three-things', 'title' => '3 things every clip needs', 'desc' => 'The hook, the context and the payoff, and how to test your clip before posting.', 'file' => 'lesson-04-three-things.mp4', 'tag' => 'Lesson 04', 'link' => 'creator/library.php', 'link_label' => 'Footage library'],
        ['id' => 'what-to-clip', 'title' => 'What to clip from HPL footage', 'desc' => 'Challenges, field action, before and after, expert advice and real people.', 'file' => 'lesson-05-what-to-clip.mp4', 'tag' => 'Lesson 05', 'link' => 'creator/library.php', 'link_label' => 'Footage library'],
        ['id' => 'first-clip', 'title' => 'Your first clip: start simple', 'desc' => 'Set up your HPL account and cut your first clean, clear clip.', 'file' => 'lesson-06-first-clip.mp4', 'tag' => 'Lesson 06', 'link' => 'creator/library.php', 'link_label' => 'Footage library'],
        ['id' => 'phone-editing', 'title' => 'Edit on your phone', 'desc' => 'A simple CapCut routine: 9:16, trim, frame, captions, sound and export.', 'file' => 'lesson-07-phone-editing.mp4', 'tag' => 'Lesson 07', 'link' => 'creator/library.php', 'link_label' => 'Footage library'],
        ['id' => 'posting-basics', 'title' => 'Posting basics', 'desc' => 'Tags, bio, links and the rules to follow every time you post.', 'file' => 'lesson-08-posting-basics.mp4', 'tag' => 'Lesson 08', 'link' => 'creator/submit_links.php', 'link_label' => 'Submit links'],
        ['id' => 'get-paid', 'title' => 'Submit links and get paid', 'desc' => 'Submit your links, track your views and withdraw to mobile money.', 'file' => 'lesson-09-get-paid.mp4', 'tag' => 'Lesson 09', 'link' => 'creator/withdraw.php', 'link_label' => 'Withdraw'],
    ];
}

/**
 * Resolve a tutorial to its source URL + whether media is available.
 * Priority: uploaded local file, then a Google Drive link set in admin.
 */
function tutorial_video(array $t): array
{
    $drive = (string)setting('tutorial_drive_' . $t['id'], '');
    $driveSrc = $drive !== '' ? google_drive_url($drive) : null;

    $rel = 'assets/media/tutorials/' . ($t['file'] ?? '');
    $abs = $t['file'] ? BASE_PATH . '/' . $rel : null;
    $hasFile = $abs !== null && is_file($abs);

    if ($hasFile) {
        return [
            'src'         => u($rel),
            'has_video'   => true,
            'size'        => (int)filesize($abs),
            'drive'       => $drive,
            'drive_src'   => $driveSrc,
        ];
    }
    if ($driveSrc !== null) {
        return [
            'src'         => $driveSrc,
            'has_video'   => true,
            'size'        => 0,
            'drive'       => $drive,
            'drive_src'   => $driveSrc,
        ];
    }
    return [
        'src'         => null,
        'has_video'   => false,
        'size'        => 0,
        'drive'       => $drive,
        'drive_src'   => null,
    ];
}

/**
 * Write an in-app notification for a user.
 * Safe to call even before the notifications table has been installed.
 */
function notif_add(int $userId, string $type, string $title, string $body = '', string $url = ''): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        q(
            'INSERT INTO notifications (user_id, type, title, body, url) VALUES (?,?,?,?,?)',
            [$userId, mb_substr($type, 0, 40), mb_substr($title, 0, 190), mb_substr($body, 0, 255), mb_substr($url, 0, 500)]
        );
        // Also to the phone's notification bar, for people who turned that on (includes/push.php).
        require_once __DIR__ . '/push.php';
        push_mark([$userId]);
    } catch (Throwable $e) {
        // Table not migrated yet: notifications are non-critical.
    }
}

/** Notify every admin (used for new work / payout requests). */
function notif_admins(string $type, string $title, string $body = '', string $url = '', array $ref = []): void
{
    try {
        $ids = fetch_all('SELECT id FROM users WHERE role = "admin" AND status = "active"');
    } catch (Throwable $e) {
        return;
    }
    foreach ($ids as $row) {
        notif_add((int)$row['id'], $type, $title, $body, $url);
    }
    // Also to the owner's Telegram (with buttons when $ref says what it is about).
    try {
        if (setting('tg_owner_chat_id', '') !== '') {
            require_once __DIR__ . '/telegram.php';
            tg_from_notification($type, $title, $body, $url, $ref);
        }
    } catch (Throwable $e) {
    }
}

/** Notify every active creator (used for announcements). */
function notif_creators(string $type, string $title, string $body = '', string $url = ''): void
{
    try {
        $ids = fetch_all('SELECT id FROM users WHERE role = "creator" AND status = "active"');
    } catch (Throwable $e) {
        return;
    }
    // One insert per 300 people and one push mark, so 1,000 creators do not mean 1,000 queries (2026-11-13).
    $all = array_map(fn($r) => (int)$r['id'], $ids);
    foreach (array_chunk($all, 300) as $ch) {
        try {
            $args = [];
            foreach ($ch as $i) { array_push($args, $i, mb_substr($type, 0, 40), mb_substr($title, 0, 190), mb_substr($body, 0, 255), mb_substr($url, 0, 500)); }
            q('INSERT INTO notifications (user_id, type, title, body, url) VALUES ' . implode(',', array_fill(0, count($ch), '(?,?,?,?,?)')), $args);
            require_once __DIR__ . '/push.php';
            push_mark($ch);
        } catch (Throwable $e) {
        }
    }
}

/** Unread notification count for the top bar badge. */
function notif_unread_count(int $userId): int
{
    // The badge counts what arrived since the bell was last opened; items stay
    // highlighted in the list until they are opened or marked as read.
    try {
        return (int)fetch_val(
            'SELECT COUNT(*) FROM notifications n JOIN users u ON u.id = n.user_id
              WHERE n.user_id = ? AND n.is_read = 0 AND (u.notif_seen_at IS NULL OR n.created_at > u.notif_seen_at)',
            [$userId], 0
        );
    } catch (Throwable $e) {
        try {
            return (int)fetch_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$userId], 0);
        } catch (Throwable $e2) {
            return 0;
        }
    }
}

/** The bell was opened: clear the badge (items keep their unread highlight). */
function notif_mark_seen(int $userId): void
{
    try {
        q('UPDATE users SET notif_seen_at = NOW() WHERE id = ?', [$userId]);
    } catch (Throwable $e) {
        notif_mark_all_read($userId);
    }
}

/** Mark one notification read; returns its row (for the redirect) or null. */
function notif_open(int $userId, int $id): ?array
{
    try {
        $n = fetch_one('SELECT * FROM notifications WHERE id = ? AND user_id = ?', [$id, $userId]);
        if ($n) {
            q('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?', [$id, $userId]);
        }
        return $n;
    } catch (Throwable $e) {
        return null;
    }
}

/** Most recent notifications for the top bar panel. */
function notif_recent(int $userId, int $limit = 8): array
{
    try {
        return fetch_all(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(20, $limit)),
            [$userId]
        );
    } catch (Throwable $e) {
        return [];
    }
}

/** Mark every notification for a user as read. */
function notif_mark_all_read(int $userId): void
{
    try {
        q('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0', [$userId]);
    } catch (Throwable $e) {
        // Non-critical.
    }
}

/**
 * Normalise a Google Drive share link to an embeddable/streamable URL.
 * Accepts the common share formats and returns a uc direct file link.
 */
function google_drive_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    // https://drive.google.com/file/d/<ID>/view?usp=sharing
    if (preg_match('#/file/d/([a-zA-Z0-9_\-]+)#', $url, $m)) {
        return 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }
    // https://drive.google.com/open?id=xxx or ?id=xxx
    if (preg_match('#[?&]id=([a-zA-Z0-9_\-]+)#', $url, $m)) {
        return 'https://drive.google.com/uc?export=download&id=' . $m[1];
    }
    // https://drive.google.com/uc?export=download&id=xxx
    if (preg_match('#^https?://[^/]+\.googleusercontent\.com/.*#i', $url)) {
        return $url;
    }
    return $url;
}
/** Words/domains that may never appear in a submitted link (Admin > Settings > Blocked links). */
function hpl_blocked_link_words(): array
{
    $raw = (string)setting('blocked_link_words', 'pornhub, xvideos, xnxx, xhamster, redtube, youporn, brazzers, onlyfans, chaturbate, stripchat, spankbang, porn, xxx');
    $out = [];
    foreach (preg_split('/[\s,]+/', strtolower($raw)) as $w) {
        if ($w !== '') { $out[] = $w; }
    }
    return array_values(array_unique($out));
}

/** True when a link points at a blocked site. */
function hpl_link_blocked(string $url): bool
{
    $u = strtolower(rawurldecode(trim($url)));
    if ($u === '') {
        return false;
    }
    // Only the site name is checked, so a creator called "@xxxgamer" on TikTok is never caught.
    $host = (string)parse_url(preg_match('~^[a-z][a-z0-9+.-]*://~', $u) ? $u : 'http://' . $u, PHP_URL_HOST);
    foreach (hpl_blocked_link_words() as $w) {
        if (strpos($host, $w) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * A submitted link as safe HTML: blocked or non-web links are shown as plain
 * text that cannot be clicked, everything else opens in a new tab.
 */
function safe_link(string $url, ?string $text = null, string $class = ''): string
{
    $cls = $class !== '' ? ' class="' . e($class) . '"' : '';
    if (hpl_link_blocked($url)) {
        return '<span' . $cls . ' style="color:#b42318;font-weight:600" title="This link points to a blocked site and cannot be opened.">Blocked link (unsafe site)</span>';
    }
    if (!preg_match('~^https?://~i', trim($url))) {
        return '<span' . $cls . '>' . e($text ?? $url) . '</span>';
    }
    return '<a href="' . e($url) . '" target="_blank" rel="noopener noreferrer nofollow"' . $cls . '>' . e($text ?? $url) . '</a>';
}

require_once __DIR__ . '/icons.php';
require_once __DIR__ . '/migrations.php';

/** A person's name must be a name: no web links, no < > tags, at most 80 characters (spam sign-ups, 2026-11-11). */
function name_is_ok(string $name): bool
{
    $n = trim($name);
    return mb_strlen($n) >= 2 && mb_strlen($n) <= 80 && !preg_match('~[<>]|https?:|://|www\.|\.(com|org|net|xyz|ru|top|info)\b~i', $n);
}
