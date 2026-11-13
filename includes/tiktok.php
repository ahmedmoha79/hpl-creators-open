<?php
/**
 * TikTok Login Kit + Display API (2026-10-27).
 *
 * What it does:
 *  1. "Connect TikTok": the creator signs in to TikTok and approves access.
 *     This proves the TikTok account is theirs, so the page is verified
 *     automatically (no bio code needed).
 *  2. Reads the real view count of every TikTok video they submit, using the
 *     official API (video.query), so earnings follow real views.
 *  3. Lists their recent TikTok videos so they can submit them in one tap.
 *
 * Setup (Admin > Settings > TikTok): Client key + Client secret from
 * developers.tiktok.com, and this redirect URI registered in Login Kit:
 *     https://<your domain>/tiktok_auth.php
 *
 * Docs: https://developers.tiktok.com/doc/login-kit-web
 *       https://developers.tiktok.com/doc/oauth-user-access-token-management
 *       https://developers.tiktok.com/doc/tiktok-api-v2-video-query
 */

defined('TIKTOK_AUTH_URL')  || define('TIKTOK_AUTH_URL', 'https://www.tiktok.com/v2/auth/authorize/');
defined('TIKTOK_TOKEN_URL') || define('TIKTOK_TOKEN_URL', 'https://open.tiktokapis.com/v2/oauth/token/');
defined('TIKTOK_API')       || define('TIKTOK_API', 'https://open.tiktokapis.com/v2/');

function tiktok_client_key(): string
{
    return trim((string)setting('tiktok_client_key', ''));
}

function tiktok_client_secret(): string
{
    return trim((string)setting('tiktok_client_secret', ''));
}

function tiktok_configured(): bool
{
    return tiktok_client_key() !== '' && tiktok_client_secret() !== '' && function_exists('curl_init');
}

function tiktok_scopes(): string
{
    $s = trim((string)setting('tiktok_scopes', ''));
    return $s !== '' ? $s : 'user.info.basic,user.info.profile,user.info.stats,video.list';
}

/** Sandbox (test) app keys start with "sb". Only test users can sign in with them. */
function tiktok_key_is_sandbox(?string $key = null): bool
{
    $key = $key ?? tiktok_client_key();
    return $key !== '' && strncasecmp($key, 'sb', 2) === 0;
}

/*
 * Connection health
 * -----------------
 * A connection is either WORKING or MUST RECONNECT. The reason is one code, stored in
 * tiktok_accounts.problem, and every page reads it through the functions below, so the
 * creator, the admin and the reminders always say the same thing:
 *   old_app   made with the old test (sandbox) app; TikTok gives each app its own ids
 *   expired   the sign-in ran out (TikTok keeps it for about a year)
 *   revoked   TikTok stopped it (HPL removed in TikTok settings, password change, ...)
 *   no_videos the creator unticked "videos" on TikTok's screen, so views cannot be read
 * Temporary trouble (TikTok down, too many requests) never asks anyone to reconnect;
 * it is only noted for the admin in last_error and tried again on the next read.
 */

/** True when a connection was made with a different app key than the one saved now. */
function tiktok_account_stale(array $acc): bool
{
    return array_key_exists('client_key', $acc) && $acc['client_key'] !== null && $acc['client_key'] !== ''
        && $acc['client_key'] !== tiktok_client_key();
}

/** True when the creator allowed TikTok to share their videos (needed to read views). */
function tiktok_account_has_videos(array $acc): bool
{
    $granted = array_map('trim', explode(',', (string)($acc['scope'] ?? '')));
    return (string)($acc['scope'] ?? '') === '' || in_array('video.list', $granted, true);
}

/** The reason code why this connection must be made again, or null when it works. */
function tiktok_account_problem(?array $acc): ?string
{
    if (!$acc) {
        return null;
    }
    if (tiktok_account_stale($acc)) {
        return 'old_app';
    }
    if (!empty($acc['problem'])) {
        return (string)$acc['problem'];
    }
    if (!tiktok_account_has_videos($acc)) {
        return 'no_videos';
    }
    return null;
}

/** What the creator is told, in plain words. */
function tiktok_problem_text(string $code): string
{
    switch ($code) {
        case 'old_app':
            return 'HPL moved its TikTok link from testing to the official live version. Please tap Reconnect once so we can keep reading your views.';
        case 'no_videos':
            return 'You did not allow HPL to see your videos, so we cannot read your views. Tap Reconnect and leave every box ticked.';
        case 'revoked':
            return 'TikTok stopped the connection. This happens if you remove HPL in your TikTok settings or change your password. Please tap Reconnect.';
        default:
            return 'Your TikTok connection has run out. Please tap Reconnect.';
    }
}

/** Short label for the admin. */
function tiktok_problem_label(string $code): string
{
    return ['old_app' => 'made with the test app', 'no_videos' => 'videos not allowed', 'revoked' => 'stopped by TikTok', 'expired' => 'expired'][$code] ?? $code;
}

/**
 * Record that a connection works (null) or must be made again ($code). The first time it
 * breaks, the creator gets one app message telling them what to do.
 */
function tiktok_set_problem(array &$acc, ?string $code, string $detail = ''): void
{
    $was = $acc['problem'] ?? null;
    try {
        q('UPDATE tiktok_accounts SET problem = ?, last_error = ? WHERE id = ?',
            [$code, $code === null ? null : mb_substr($detail !== '' ? $detail : tiktok_problem_label($code), 0, 250), (int)$acc['id']]);
    } catch (Throwable $e) {
        return; // column not migrated yet
    }
    $acc['problem'] = $code;
    if ($code !== null && empty($was)) {
        notif_add((int)$acc['user_id'], 'tiktok', 'Reconnect TikTok', tiktok_problem_text($code), 'creator/connect_tiktok.php');
    }
}

/** Note a temporary failure for the admin only; the creator is not asked to do anything. */
function tiktok_note_temporary(array $acc, string $detail): void
{
    try {
        q('UPDATE tiktok_accounts SET last_error = ? WHERE id = ? AND problem IS NULL', [mb_substr('Temporary: ' . $detail, 0, 250), (int)$acc['id']]);
    } catch (Throwable $e) {
    }
}

/**
 * Decide what a failed TikTok answer means: a reason code when the creator must reconnect,
 * or null for temporary trouble (network, TikTok busy) that is simply tried again later.
 */
function tiktok_failure_code(?array $json, int $status, bool $tokenCall = false): ?string
{
    if ($status === 0 || $status >= 500 || $status === 429) {
        return null;
    }
    $code = '';
    if (isset($json['error']) && is_array($json['error'])) {
        $code = (string)($json['error']['code'] ?? '');
    } elseif (isset($json['error']) && is_string($json['error'])) {
        $code = $json['error'];
    }
    if (in_array($code, ['rate_limit_exceeded', 'internal_error', 'ok', ''], true) && $status === 200) {
        return null;
    }
    if (in_array($code, ['scope_not_authorized', 'scope_permission_missed'], true)) {
        return 'no_videos';
    }
    if ($tokenCall) {
        // The refresh token was refused: the sign-in can no longer be renewed.
        return ($status >= 400 && $status < 500) || $code !== '' ? 'expired' : null;
    }
    if ($status === 401 || in_array($code, ['access_token_invalid', 'invalid_token', 'token_expired'], true)) {
        return 'revoked';
    }
    return null;
}

/*
 * A creator can connect several TikTok accounts (one per TikTok page they post on).
 * A clip's views are read through the connected account whose @name is in the clip's link.
 */

/** The @name in a TikTok link, without any network call (null for short vt./vm. links). */
function tiktok_link_handle(string $url): ?string
{
    return preg_match('~tiktok\.com/@([a-z0-9._]{2,24})~i', $url, $m) ? strtolower($m[1]) : null;
}

/** TikTok clip links that can still earn, per creator: [user_id => [url, ...]]. */
function tiktok_clip_urls(?int $userId = null): array
{
    $parts = ['SELECT user_id, url FROM campaign_submissions WHERE platform = "tiktok" AND status IN ("pending","approved")'];
    if (!function_exists('general_clips_enabled') || general_clips_enabled()) {
        $parts[] = 'SELECT user_id, url FROM content_links WHERE platform = "tiktok" AND status = "active"';
    }
    $sql = 'SELECT user_id, url FROM (' . implode(' UNION ALL ', $parts) . ') x';
    $params = [];
    if ($userId !== null) {
        $sql .= ' WHERE user_id = ?';
        $params[] = $userId;
    }
    $out = [];
    try {
        foreach (fetch_all($sql, $params) as $r) {
            $out[(int)$r['user_id']][] = (string)$r['url'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Clip counts per creator for TikTok clips that can still earn. */
function tiktok_clip_counts(?int $userId = null): array
{
    return array_map('count', tiktok_clip_urls($userId));
}

/** All TikTok connections of a creator, newest first. */
function tiktok_accounts_for_user(int $userId): array
{
    try {
        return fetch_all('SELECT * FROM tiktok_accounts WHERE user_id = ? ORDER BY id DESC', [$userId]);
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Everything about one creator's TikTok in one place:
 *   accounts   each connection with its problem code (null = working)
 *   clips      TikTok clips that can still earn
 *   unreadable how many of them cannot be read now
 *   needs      [@name => ['clips' => n, 'problem' => code|null, 'connected' => bool]]  ('' = short link, account unknown)
 *   state      'action' (clips cannot be read) | 'broken' (a connection must be redone) | 'working' | 'none'
 */
function tiktok_creator_status(int $userId, ?array $urls = null, ?array $accs = null): array
{
    $urls = $urls ?? (tiktok_clip_urls($userId)[$userId] ?? []);
    $accs = $accs ?? tiktok_accounts_for_user($userId);
    $accounts = [];
    $byName = [];
    $anyWorking = false;
    foreach ($accs as $a) {
        $code = tiktok_account_problem($a);
        $accounts[] = ['row' => $a, 'problem' => $code];
        $name = strtolower((string)$a['username']);
        if ($name !== '' && (!isset($byName[$name]) || $code === null)) {
            $byName[$name] = $code;
        }
        $anyWorking = $anyWorking || $code === null;
    }
    $needs = [];
    $unreadable = 0;
    foreach ($urls as $url) {
        $h = tiktok_link_handle($url);
        $ok = $h === null ? $anyWorking : (array_key_exists($h, $byName) && $byName[$h] === null);
        if ($ok) {
            continue;
        }
        $unreadable++;
        $k = $h ?? '';
        if (!isset($needs[$k])) {
            $needs[$k] = ['clips' => 0, 'connected' => $h !== null && array_key_exists($h, $byName), 'problem' => $h !== null ? ($byName[$h] ?? null) : null];
        }
        $needs[$k]['clips']++;
    }
    $broken = count(array_filter($accounts, function ($x) { return $x['problem'] !== null; }));
    $state = $unreadable > 0 ? 'action' : ($broken > 0 ? 'broken' : ($accounts ? 'working' : 'none'));
    return ['accounts' => $accounts, 'clips' => count($urls), 'unreadable' => $unreadable, 'needs' => $needs, 'state' => $state];
}

/** One plain sentence per thing the creator must do, e.g. 'Connect @hplclips (2 clips).' */
function tiktok_needs_text(array $needs): array
{
    $out = [];
    foreach ($needs as $h => $n) {
        $clips = $n['clips'] . ' clip' . ($n['clips'] === 1 ? '' : 's');
        if ($h === '') {
            $out[] = 'Connect the TikTok account you posted ' . $clips . ' on.';
        } elseif ($n['connected']) {
            $out[] = 'Reconnect @' . $h . ' (' . $clips . '). ' . tiktok_problem_text((string)$n['problem']);
        } else {
            $out[] = 'Connect @' . $h . ' (' . $clips . ').';
        }
    }
    return $out;
}

/** Can the views of this TikTok link be read through the creator's connections right now? */
function tiktok_link_readable(int $userId, string $url): bool
{
    return tiktok_creator_status($userId, [$url])['unreadable'] === 0;
}

/** Admin overview: one row per creator with a TikTok connection or TikTok clips that can earn. */
function tiktok_overview(): array
{
    $urls = tiktok_clip_urls();
    $accsBy = [];
    try {
        foreach (fetch_all('SELECT t.* FROM tiktok_accounts t JOIN users u ON u.id = t.user_id WHERE u.status = "active" ORDER BY t.id DESC') as $a) {
            $accsBy[(int)$a['user_id']][] = $a;
        }
    } catch (Throwable $e) {
    }
    $ids = array_unique(array_merge(array_keys($urls), array_keys($accsBy)));
    if (!$ids) {
        return [];
    }
    $users = [];
    foreach (fetch_all('SELECT id, full_name, email, tiktok_nudged_at FROM users WHERE status = "active" AND id IN (' . implode(',', array_map('intval', $ids)) . ')') as $u) {
        $users[(int)$u['id']] = $u;
    }
    $rows = [];
    foreach ($users as $uid => $u) {
        $st = tiktok_creator_status($uid, $urls[$uid] ?? [], $accsBy[$uid] ?? []);
        $rows[] = $u + $st;
    }
    usort($rows, function ($a, $b) {
        $w = ['action' => 0, 'broken' => 1, 'none' => 2, 'working' => 3];
        return [$w[$a['state']], -$a['unreadable'], -$a['clips']] <=> [$w[$b['state']], -$b['unreadable'], -$b['clips']];
    });
    return $rows;
}

/** Creators who have TikTok clips whose views cannot be read now. */
function tiktok_creators_to_connect(): array
{
    return array_values(array_filter(tiktok_overview(), function ($r) {
        return $r['state'] === 'action';
    }));
}

/**
 * Test one connection right now (admin "Check connections"). Returns 'working', a reason
 * code when it must be made again, or 'temporary' when TikTok could not be reached.
 */
function tiktok_check_account(array &$acc): string
{
    if (tiktok_account_stale($acc)) {
        tiktok_set_problem($acc, 'old_app');
        return 'old_app';
    }
    $token = tiktok_access_token($acc, true); // test it even if it was marked before
    if ($token === null) {
        return !empty($acc['problem']) ? (string)$acc['problem'] : 'temporary';
    }
    [$status, $json] = tiktok_http('GET', TIKTOK_API . 'user/info/?fields=open_id', ['Authorization: Bearer ' . $token]);
    if ($status === 200 && !empty($json['data']['user']['open_id'])) {
        $code = tiktok_account_has_videos($acc) ? null : 'no_videos';
        tiktok_set_problem($acc, $code);
        return $code ?? 'working';
    }
    $code = tiktok_failure_code($json, $status);
    if ($code === null) {
        tiktok_note_temporary($acc, tiktok_error_text($json, $status));
        return 'temporary';
    }
    tiktok_set_problem($acc, $code, tiktok_error_text($json, $status));
    return $code;
}

/** Tell the admins (at most every 6 hours) that TikTok refused the app's setup. */
function tiktok_alert_setup_problem(string $detail): void
{
    error_log('TikTok setup problem: ' . $detail);
    set_setting('tiktok_last_setup_error', mb_substr(date('Y-m-d H:i') . ' ' . $detail, 0, 300));
    if ((int)setting('tiktok_setup_alert_at', '0') > time() - 6 * 3600) {
        return;
    }
    set_setting('tiktok_setup_alert_at', (string)time());
    notif_admins('system', 'TikTok connect failed',
        'A creator could not connect TikTok: ' . mb_substr($detail, 0, 160) . '. Check the client key, secret, redirect address and scopes in Settings.',
        'admin/settings.php#tiktok');
}

/** The exact redirect URI to register in the TikTok developer portal. */
function tiktok_redirect_uri(): string
{
    $custom = trim((string)setting('tiktok_redirect_uri', ''));
    if ($custom !== '') {
        return $custom;
    }
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com');
    return 'https://' . $host . u('tiktok_auth.php');
}

function tiktok_auth_url(string $state): string
{
    return TIKTOK_AUTH_URL . '?' . http_build_query([
        'client_key'    => tiktok_client_key(),
        'response_type' => 'code',
        'scope'         => tiktok_scopes(),
        'redirect_uri'  => tiktok_redirect_uri(),
        'state'         => $state,
        // Always show TikTok's own screen (with the account it is about to connect), never a silent
        // auto-approve: otherwise "Connect another TikTok" silently reconnects the same account (2026-11-08).
        'disable_auto_auth' => 1,
    ]);
}

/** Small HTTP helper. Returns [status, decoded json|null, raw body]. */
function tiktok_http(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        // Page views use a short wait (set by the caller) so a slow TikTok never holds many page loads.
        CURLOPT_TIMEOUT        => (int)($GLOBALS['hpl_tt_timeout'] ?? 15),
        CURLOPT_CONNECTTIMEOUT => min(6, (int)($GLOBALS['hpl_tt_timeout'] ?? 15)),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => $method,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        error_log('TikTok HTTP error: ' . $err);
        return [0, null, ''];
    }
    $json = json_decode((string)$raw, true);
    return [$status, is_array($json) ? $json : null, (string)$raw];
}

/** Turn an API error into a short readable message. */
function tiktok_error_text(?array $json, int $status): string
{
    if (!$json) {
        return 'TikTok did not respond (HTTP ' . $status . ').';
    }
    if (!empty($json['error_description'])) {
        return (string)$json['error_description'];
    }
    if (isset($json['error']) && is_array($json['error'])) {
        return trim(($json['error']['code'] ?? '') . ': ' . ($json['error']['message'] ?? ''), ': ');
    }
    if (isset($json['error']) && is_string($json['error'])) {
        return $json['error'];
    }
    return 'Unexpected response from TikTok (HTTP ' . $status . ').';
}

/** Exchange an authorization code for tokens. Returns token array or error string. */
function tiktok_exchange_code(string $code)
{
    [$status, $json] = tiktok_http('POST', TIKTOK_TOKEN_URL,
        ['Content-Type: application/x-www-form-urlencoded', 'Cache-Control: no-cache'],
        http_build_query([
            'client_key'    => tiktok_client_key(),
            'client_secret' => tiktok_client_secret(),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => tiktok_redirect_uri(),
        ]));
    if ($status !== 200 || empty($json['access_token'])) {
        return tiktok_error_text($json, $status);
    }
    return $json;
}

/** Get user profile with an access token. Returns user array or error string. */
function tiktok_user_info(string $token)
{
    $fields = 'open_id,union_id,avatar_url,display_name';
    $scopes = tiktok_scopes();
    if (strpos($scopes, 'user.info.profile') !== false) { $fields .= ',username,profile_deep_link,is_verified'; }
    if (strpos($scopes, 'user.info.stats') !== false) { $fields .= ',follower_count,video_count'; }
    [$status, $json] = tiktok_http('GET', TIKTOK_API . 'user/info/?fields=' . $fields, ['Authorization: Bearer ' . $token]);
    if ($status !== 200 || empty($json['data']['user']['open_id'])) {
        return tiktok_error_text($json, $status);
    }
    return $json['data']['user'];
}

function tiktok_account_for_user(int $userId): ?array
{
    try {
        return fetch_one('SELECT * FROM tiktok_accounts WHERE user_id = ? ORDER BY (client_key <=> ? AND problem IS NULL) DESC, id DESC LIMIT 1', [$userId, tiktok_client_key()]);
    } catch (Throwable $e) {
        try {
            return fetch_one('SELECT * FROM tiktok_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$userId]);
        } catch (Throwable $e2) {
            return null;
        }
    }
}

function tiktok_account_by_username(string $username): ?array
{
    try {
        return fetch_one('SELECT * FROM tiktok_accounts WHERE username = ? ORDER BY (client_key <=> ? AND problem IS NULL) DESC, id DESC LIMIT 1', [strtolower($username), tiktok_client_key()]);
    } catch (Throwable $e) {
        try {
            return fetch_one('SELECT * FROM tiktok_accounts WHERE username = ? ORDER BY id DESC LIMIT 1', [strtolower($username)]);
        } catch (Throwable $e2) {
            return null;
        }
    }
}

/** A valid access token for an account (renews it when it is about to run out), or null. */
function tiktok_access_token(array &$acc, bool $retest = false): ?string
{
    if (tiktok_account_stale($acc)) {
        if (($acc['problem'] ?? null) !== 'old_app') {
            tiktok_set_problem($acc, 'old_app');
        }
        return null;
    }
    if (!$retest && !empty($acc['problem'])) {
        return null; // must reconnect first; asking TikTok again would only fail
    }
    if (strtotime((string)$acc['access_expires_at']) > time() + 300) {
        return (string)$acc['access_token'];
    }
    if (strtotime((string)$acc['refresh_expires_at']) < time()) {
        tiktok_set_problem($acc, 'expired', 'Sign-in older than TikTok allows');
        return null;
    }
    [$status, $json] = tiktok_http('POST', TIKTOK_TOKEN_URL,
        ['Content-Type: application/x-www-form-urlencoded', 'Cache-Control: no-cache'],
        http_build_query([
            'client_key'    => tiktok_client_key(),
            'client_secret' => tiktok_client_secret(),
            'grant_type'    => 'refresh_token',
            'refresh_token' => (string)$acc['refresh_token'],
        ]));
    if ($status !== 200 || empty($json['access_token'])) {
        $code = tiktok_failure_code($json, $status, true);
        if ($code === null) {
            tiktok_note_temporary($acc, 'renewing the sign-in: ' . tiktok_error_text($json, $status));
        } else {
            tiktok_set_problem($acc, $code, 'Renewal refused: ' . tiktok_error_text($json, $status));
        }
        return null;
    }
    q('UPDATE tiktok_accounts SET access_token = ?, refresh_token = ?, access_expires_at = FROM_UNIXTIME(?), refresh_expires_at = FROM_UNIXTIME(?) WHERE id = ?', [
        $json['access_token'], $json['refresh_token'] ?? $acc['refresh_token'],
        time() + (int)($json['expires_in'] ?? 86400), time() + (int)($json['refresh_expires_in'] ?? 31536000), (int)$acc['id'],
    ]);
    $acc['access_token'] = $json['access_token'];
    $acc['access_expires_at'] = date('Y-m-d H:i:s', time() + (int)($json['expires_in'] ?? 86400));
    return (string)$json['access_token'];
}

/** After a failed API call: mark the connection broken, or note a temporary failure. */
function tiktok_api_failed(array &$acc, ?array $json, int $status, string $what): void
{
    $code = tiktok_failure_code($json, $status);
    if ($code === null) {
        tiktok_note_temporary($acc, $what . ': ' . tiktok_error_text($json, $status));
    } else {
        tiktok_set_problem($acc, $code, $what . ': ' . tiktok_error_text($json, $status));
    }
}

/** After a successful API call: the connection works, clear any old note. */
function tiktok_api_ok(array &$acc): void
{
    if (!empty($acc['problem']) || !empty($acc['last_error'])) {
        tiktok_set_problem($acc, null);
        $acc['last_error'] = null;
    }
    q('UPDATE tiktok_accounts SET last_sync_at = NOW() WHERE id = ?', [(int)$acc['id']]);
}

/** Numeric TikTok video id from a video link (short links are resolved first). */
function tiktok_video_id(string $url): ?string
{
    if (!preg_match('~/(?:video|photo)/(\d{8,25})~', $url, $m)) {
        if (function_exists('hpl_resolve_url')) {
            $url = hpl_resolve_url($url);
        }
        if (!preg_match('~/(?:video|photo)/(\d{8,25})~', $url, $m)) {
            return null;
        }
    }
    return $m[1];
}

/** View counts for up to 20 video ids of one account. Returns [id => views]. */
function tiktok_query_views(array &$acc, array $ids): array
{
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) {
        return [];
    }
    $token = tiktok_access_token($acc);
    if ($token === null) {
        return [];
    }
    $out = [];
    $failed = false;
    foreach (array_chunk($ids, 20) as $chunk) {
        [$status, $json] = tiktok_http('POST', TIKTOK_API . 'video/query/?fields=id,view_count,like_count,comment_count,share_count,cover_image_url',
            ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
            json_encode(['filters' => ['video_ids' => array_map('strval', $chunk)]]));
        if ($status !== 200 || !isset($json['data']['videos'])) {
            tiktok_api_failed($acc, $json, $status, 'Reading views');
            $failed = true;
            if (!empty($acc['problem'])) {
                break;
            }
            continue;
        }
        $got = [];
        foreach ($json['data']['videos'] as $v) {
            $out[(string)$v['id']] = (int)($v['view_count'] ?? 0);
            $got[(string)$v['id']] = true;
            // Likes, comments and shares come with the same answer: kept for the integrity checks (includes/integrity.php).
            $GLOBALS['hpl_tt_stats'][(string)$v['id']] = ['views' => (int)($v['view_count'] ?? 0), 'likes' => (int)($v['like_count'] ?? 0),
                'comments' => (int)($v['comment_count'] ?? 0), 'shares' => (int)($v['share_count'] ?? 0),
                'cover' => (string)($v['cover_image_url'] ?? '')];
        }
        // TikTok answered for this account and these videos were not in the answer: deleted, private or moved.
        foreach ($chunk as $asked) {
            if (!isset($got[(string)$asked])) {
                $GLOBALS['hpl_tt_missing'][(string)$asked] = true;
            }
        }
    }
    if (!$failed) {
        tiktok_api_ok($acc);
    }
    return $out;
}

/** Most recent videos of a connected account (for one-tap submitting). */
function tiktok_recent_videos(array &$acc, int $max = 20): array
{
    $token = tiktok_access_token($acc);
    if ($token === null) {
        return [];
    }
    [$status, $json] = tiktok_http('POST', TIKTOK_API . 'video/list/?fields=id,title,video_description,cover_image_url,share_url,view_count,like_count,comment_count,share_count,create_time',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['max_count' => max(1, min(20, $max))]));
    if ($status !== 200 || !isset($json['data']['videos'])) {
        tiktok_api_failed($acc, $json, $status, 'Listing videos');
        return [];
    }
    tiktok_api_ok($acc);
    return $json['data']['videos'];
}

/**
 * Views for one TikTok link, via the connected account that posted it.
 * Returns null when no connected account can read it.
 */
function tiktok_views_for_link(string $url, ?int $userId = null): ?int
{
    static $memo = [];
    if (!tiktok_configured()) {
        return null;
    }
    $id = tiktok_video_id($url);
    if ($id === null) {
        return null;
    }
    $acc = null;
    $handle = function_exists('hpl_handle_from_url') ? hpl_handle_from_url('tiktok', $url) : null;
    if ($handle !== null) {
        $acc = tiktok_account_by_username($handle);
    }
    if (!$acc && $userId !== null && $handle === null) {
        $acc = tiktok_account_for_user($userId); // short link: the @name is not in it
    }
    if (!$acc) {
        return null;
    }
    $key = $acc['id'] . ':' . $id;
    if (isset($GLOBALS['hpl_tt_prefetch']) && array_key_exists($key, $GLOBALS['hpl_tt_prefetch'])) {
        return $GLOBALS['hpl_tt_prefetch'][$key];
    }
    if (!array_key_exists($key, $memo)) {
        $res = tiktok_query_views($acc, [$id]);
        $memo[$key] = $res[$id] ?? null;
    }
    return $memo[$key];
}

/**
 * Read the views of many TikTok links in as few API calls as possible
 * (up to 20 videos per call, grouped by the connected account that posted them).
 * Results are kept for this request, so tiktok_views_for_link() answers from memory.
 */
function tiktok_prefetch_views(array $rows): void
{
    if (!tiktok_configured()) {
        return;
    }
    $GLOBALS['hpl_tt_prefetch'] = $GLOBALS['hpl_tt_prefetch'] ?? [];
    $byAcc = [];
    $accs = [];
    foreach ($rows as $r) {
        if (($r['platform'] ?? '') !== 'tiktok') {
            continue;
        }
        $id = tiktok_video_id((string)$r['url']);
        if ($id === null) {
            continue;
        }
        $acc = null;
        $handle = function_exists('hpl_handle_from_url') ? hpl_handle_from_url('tiktok', (string)$r['url']) : null;
        if ($handle !== null) {
            $acc = tiktok_account_by_username($handle);
        }
        if (!$acc && !empty($r['user_id']) && $handle === null) {
            $acc = tiktok_account_for_user((int)$r['user_id']); // short link: the @name is not in it
        }
        if (!$acc) {
            continue;
        }
        $accs[(int)$acc['id']] = $acc;
        $byAcc[(int)$acc['id']][$id] = true;
    }
    foreach ($byAcc as $accId => $ids) {
        $acc = $accs[$accId];
        $found = tiktok_query_views($acc, array_keys($ids));
        foreach (array_keys($ids) as $id) {
            // Not returned by TikTok = not readable now; remember that too, so we do not ask again.
            $GLOBALS['hpl_tt_prefetch'][$accId . ':' . $id] = $found[$id] ?? null;
        }
    }
}

/**
 * Save a newly connected account and verify the matching TikTok page.
 * Returns null on success or an error message.
 */
function tiktok_save_connection(int $userId, array $tokens, array $info): ?string
{
    $openId = (string)$info['open_id'];
    $taken = fetch_one('SELECT user_id FROM tiktok_accounts WHERE open_id = ?', [$openId]);
    if ($taken && (int)$taken['user_id'] !== $userId) {
        return 'This TikTok account is already connected to another HPL account. If it is yours, contact support.';
    }
    $username = strtolower(trim((string)($info['username'] ?? '')));
    $params = [
        $userId, $openId, $username ?: null, mb_substr((string)($info['display_name'] ?? ''), 0, 190),
        mb_substr((string)($info['avatar_url'] ?? ''), 0, 600), isset($info['follower_count']) ? (int)$info['follower_count'] : null,
        (string)$tokens['access_token'], (string)$tokens['refresh_token'], (string)($tokens['scope'] ?? ''),
        time() + (int)($tokens['expires_in'] ?? 86400), time() + (int)($tokens['refresh_expires_in'] ?? 31536000),
    ];
    $params[] = tiktok_client_key();
    q('INSERT INTO tiktok_accounts (user_id, open_id, username, display_name, avatar_url, follower_count, access_token, refresh_token, scope, access_expires_at, refresh_expires_at, client_key)
       VALUES (?,?,?,?,?,?,?,?,?,FROM_UNIXTIME(?),FROM_UNIXTIME(?),?)
       ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), username = VALUES(username), display_name = VALUES(display_name), avatar_url = VALUES(avatar_url),
         follower_count = VALUES(follower_count), access_token = VALUES(access_token), refresh_token = VALUES(refresh_token), scope = VALUES(scope),
         access_expires_at = VALUES(access_expires_at), refresh_expires_at = VALUES(refresh_expires_at), client_key = VALUES(client_key),
         problem = NULL, last_error = NULL', $params);
    // A creator may connect several TikTok accounts. TikTok gives each app its own user id
    // (open_id), so an older connection of this same TikTok account (e.g. made with the test
    // app) is a separate row: remove it. Other accounts of the creator stay connected.
    if ($username !== '') {
        q('DELETE FROM tiktok_accounts WHERE open_id <> ? AND username = ?', [$openId, $username]);
    }
    set_setting('tiktok_last_setup_error', '');

    if ($username === '') {
        return null; // Connected, but the username scope was not granted; the page stays on manual review.
    }

    // Logging in to TikTok proves ownership, so the page is verified now.
    $url = 'https://www.tiktok.com/@' . $username;
    $mine = null;
    foreach (fetch_all('SELECT * FROM social_pages WHERE user_id = ? AND platform = "tiktok"', [$userId]) as $p) {
        if (function_exists('hpl_handle_from_url') && hpl_handle_from_url('tiktok', (string)$p['url']) === $username) {
            $mine = $p;
            break;
        }
    }
    if ($mine) {
        q('UPDATE social_pages SET status = "approved", admin_note = ? WHERE id = ?', ['Verified automatically: signed in with TikTok', (int)$mine['id']]);
    } else {
        q('INSERT INTO social_pages (user_id, platform, url, page_name, status, admin_note) VALUES (?, "tiktok", ?, ?, "approved", ?)',
            [$userId, $url, '@' . $username, 'Verified automatically: signed in with TikTok']);
    }
    // Anyone else who claimed this handle without proof loses the claim.
    foreach (fetch_all('SELECT id, user_id, url FROM social_pages WHERE platform = "tiktok" AND user_id <> ? AND status IN ("pending","approved")', [$userId]) as $p) {
        if (function_exists('hpl_handle_from_url') && hpl_handle_from_url('tiktok', (string)$p['url']) === $username) {
            q('UPDATE social_pages SET status = "rejected", admin_note = ? WHERE id = ?', ['The real owner of @' . $username . ' signed in with TikTok', (int)$p['id']]);
            notif_add((int)$p['user_id'], 'verification', 'TikTok page removed', '@' . $username . ' was verified by its owner through TikTok sign-in.', 'creator/profile.php#socialPage');
            notif_admins('verification', 'TikTok ownership conflict', '@' . $username . ' was claimed by user #' . $p['user_id'] . ' but verified by user #' . $userId . ' via TikTok login.', 'admin/social_pages.php');
        }
    }
    return null;
}

/**
 * Ownership and age of one video on a connected account.
 * Returns ['views' => int, 'create_time' => int] when TikTok lists it on that account,
 * [] when TikTok answered and the video is not on the account, and null when TikTok
 * could not be reached (callers then fall back to the other checks).
 */
function tiktok_video_info(array &$acc, string $videoId): ?array
{
    $token = tiktok_access_token($acc);
    if ($token === null) {
        return null;
    }
    [$status, $json] = tiktok_http('POST', TIKTOK_API . 'video/query/?fields=id,create_time,view_count,duration,height,width,cover_image_url',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['filters' => ['video_ids' => [$videoId]]]));
    if ($status !== 200 || !isset($json['data']) || !is_array($json['data'])) {
        tiktok_api_failed($acc, $json, $status, 'Checking a video');
        return null;
    }
    tiktok_api_ok($acc);
    foreach ((array)($json['data']['videos'] ?? []) as $v) {
        if ((string)($v['id'] ?? '') === $videoId) {
            return ['views' => (int)($v['view_count'] ?? 0), 'create_time' => (int)($v['create_time'] ?? 0), 'duration' => isset($v['duration']) ? (float)$v['duration'] : null,
                    'height' => (int)($v['height'] ?? 0), 'width' => (int)($v['width'] ?? 0), 'cover' => (string)($v['cover_image_url'] ?? '')];
        }
    }
    return [];
}

/**
 * The account's TikTok bio (needs the user.info.profile scope).
 * Returns the bio text ('' when empty), or null when TikTok could not be asked.
 */
function tiktok_user_bio(array &$acc): ?string
{
    // The bio needs the user.info.profile permission. Without it we cannot read it, and a failed
    // bio read must never mark the account as broken (that would stop its view counting).
    $scope = (string)($acc['scope'] ?? '');
    if ($scope !== '' && !in_array('user.info.profile', array_map('trim', explode(',', $scope)), true)) {
        return null;
    }
    $token = tiktok_access_token($acc);
    if ($token === null) {
        return null;
    }
    [$status, $json] = tiktok_http('GET', TIKTOK_API . 'user/info/?fields=open_id,bio_description', ['Authorization: Bearer ' . $token]);
    if ($status !== 200 || !isset($json['data']['user']) || !is_array($json['data']['user'])) {
        error_log('TikTok bio read failed for account ' . (int)($acc['id'] ?? 0) . ': HTTP ' . $status);
        return null;
    }
    return (string)($json['data']['user']['bio_description'] ?? '');
}

/**
 * The account's bio and numbers in one TikTok call (2026-11-07, for the "TikTok ready" check).
 * Returns ['bio' => ?string, 'follower_count' => ?int, 'following_count' => ?int, 'video_count' => ?int,
 * 'likes_count' => ?int] where null means "not shared with us" (scope missing), or null when TikTok
 * could not be asked. Like the bio read, a failure never marks the connection as broken.
 */
function tiktok_user_profile(array &$acc): ?array
{
    $scope = (string)($acc['scope'] ?? '');
    $granted = array_map('trim', explode(',', $scope));
    $hasProfile = $scope === '' || in_array('user.info.profile', $granted, true);
    $hasStats = $scope === '' || in_array('user.info.stats', $granted, true);
    if (!$hasProfile && !$hasStats) {
        return null;
    }
    $token = tiktok_access_token($acc);
    if ($token === null) {
        return null;
    }
    $fields = 'open_id' . ($hasProfile ? ',bio_description' : '') . ($hasStats ? ',follower_count,following_count,likes_count,video_count' : '');
    [$status, $json] = tiktok_http('GET', TIKTOK_API . 'user/info/?fields=' . $fields, ['Authorization: Bearer ' . $token]);
    if ($status !== 200 || !isset($json['data']['user']) || !is_array($json['data']['user'])) {
        error_log('TikTok profile read failed for account ' . (int)($acc['id'] ?? 0) . ': HTTP ' . $status);
        return null;
    }
    $u = $json['data']['user'];
    $n = function (string $k) use ($u, $hasStats): ?int { return $hasStats && isset($u[$k]) && is_numeric($u[$k]) ? (int)$u[$k] : null; };
    return [
        'bio' => $hasProfile ? (string)($u['bio_description'] ?? '') : null,
        'follower_count' => $n('follower_count'), 'following_count' => $n('following_count'),
        'video_count' => $n('video_count'), 'likes_count' => $n('likes_count'),
    ];
}

/**
 * The caption of one video on a connected account. Returns the text, '' when the video
 * is not on that account, or null when TikTok could not be asked.
 */
function tiktok_video_caption(array &$acc, string $videoId): ?string
{
    $token = tiktok_access_token($acc);
    if ($token === null) {
        return null;
    }
    [$status, $json] = tiktok_http('POST', TIKTOK_API . 'video/query/?fields=id,title,video_description',
        ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        json_encode(['filters' => ['video_ids' => [$videoId]]]));
    if ($status !== 200 || !isset($json['data']) || !is_array($json['data'])) {
        tiktok_api_failed($acc, $json, $status, 'Reading a caption');
        return null;
    }
    tiktok_api_ok($acc);
    foreach ((array)($json['data']['videos'] ?? []) as $v) {
        if ((string)($v['id'] ?? '') === $videoId) {
            return trim((string)($v['video_description'] ?? '') . ' ' . (string)($v['title'] ?? ''));
        }
    }
    return '';
}

/**
 * Send a creator an inbox message (from HPL Help) with a big "Connect my TikTok" button,
 * plus a notification that opens the same page. Used by admins when a creator's TikTok clips cannot be read.
 */
function tiktok_send_connect_help(int $userId): bool
{
    $u = fetch_one('SELECT id, full_name FROM users WHERE id = ? AND role = "creator"', [$userId]);
    $admin = support_admin_id();
    if (!$u || $admin <= 0) {
        return false;
    }
    $tid = dm_thread_between($admin, $userId);
    if ($tid <= 0) {
        return false;
    }
    $first = e(explode(' ', trim((string)$u['full_name']))[0] ?: 'there');
    $url = e(abs_url('creator/connect_tiktok.php'));
    $html = '<p>Hi ' . $first . ', your TikTok clips cannot be counted yet, so you are not being paid for their views.</p>'
        . '<p>Fix it in 1 minute: tap the button, then tap <b>Authorize</b> in TikTok.</p>'
        . '<p><a href="' . $url . '" style="display:inline-block;background:#111;color:#fff;padding:12px 20px;border-radius:10px;font-weight:700;text-decoration:none">Connect my TikTok</a></p>'
        . '<p style="font-size:13px">Open it in Chrome, not inside WhatsApp. Stuck? Reply here with a screenshot.</p>';
    $sent = dm_send_bot($tid, $html);
    if (!$sent) {
        return false;
    }
    // Mark it as a message from the team, so admins see this conversation in Messages
    // (the admin list hides conversations where only the automatic assistant wrote).
    try {
        q('UPDATE dm_messages SET source = "tt_help" WHERE id = ?', [(int)$sent['id']]);
    } catch (Throwable $e) {
    }
    notif_add($userId, 'warning', 'Connect your TikTok so your views count', 'Tap here, then tap "Connect my TikTok". It takes 1 minute.', 'creator/connect_tiktok.php');
    return true;
}
