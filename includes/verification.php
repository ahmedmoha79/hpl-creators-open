<?php
/**
 * HPL account ownership verification (2026-10-26)
 *
 * 1. Every submitted social page gets a verification code (e.g. HPL-7K3QF2).
 *    The creator adds it to the page bio; the admin (or "Check bio") confirms it
 *    before approving. The code is derived from the page record, so no schema change.
 * 2. A page/handle can only belong to one HPL account.
 * 3. Video links must come from one of the creator's own approved pages when the
 *    handle can be read from the link (TikTok, Instagram profile links, YouTube).
 * 4. Withdrawals need at least one approved page.
 */

/** Platforms whose profile links must contain a readable @handle / username. */
function hpl_handle_platforms(): array
{
    return ['tiktok', 'instagram', 'youtube', 'twitter'];
}

/** Verification code for a social page row (needs id + user_id). */
function hpl_social_code(array $page): string
{
    $n = crc32('hpl-verify|' . (int)$page['id'] . '|' . (int)$page['user_id']);
    $code = strtoupper(base_convert((string)$n, 10, 36));
    return 'HPL-' . str_pad(substr($code, 0, 6), 6, '0', STR_PAD_LEFT);
}

/** Follow redirects for share/short links (vm.tiktok.com, vt.tiktok.com, tiktok.com/t/...). */
function hpl_resolve_url(string $url): string
{
    // Only real TikTok short links: the host must be exactly one of these (no "user@host" tricks).
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $isShort = !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['port'])
        && (in_array($host, ['vm.tiktok.com', 'vt.tiktok.com'], true)
            || (in_array($host, ['tiktok.com', 'www.tiktok.com'], true) && strpos((string)($parts['path'] ?? ''), '/t/') === 0));
    if (!$isShort || !function_exists('curl_init')) {
        return $url;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Linux; Android 12) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Mobile Safari/537.36',
    ]);
    curl_exec($ch);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return $final !== '' ? $final : $url;
}

/** Read the account handle (lowercase, no @) from a profile or video link, or null. */
function hpl_handle_from_url(string $platform, string $url): ?string
{
    if (hpl_url_platform_error($platform, $url) !== null) {
        return null; // e.g. google.com/?q=tiktok.com/@someone is not a TikTok link
    }
    $u = strtolower(trim($url));
    $m = [];
    switch ($platform) {
        case 'tiktok':
            if (preg_match('~tiktok\.com/@([a-z0-9._]{2,24})~', $u, $m)) { return $m[1]; }
            break;
        case 'youtube':
            if (preg_match('~youtube\.com/@([a-z0-9._-]{3,30})~', $u, $m)) { return $m[1]; }
            break;
        case 'instagram':
            if (preg_match('~instagram\.com/([a-z0-9._]{1,30})/?(?:\?|$)~', $u, $m)
                && !in_array($m[1], ['p', 'reel', 'reels', 'stories', 'explore', 'tv'], true)) { return $m[1]; }
            break;
        case 'twitter':
            if (preg_match('~(?:twitter|x)\.com/([a-z0-9_]{1,15})(?:/|\?|$)~', $u, $m)
                && !in_array($m[1], ['i', 'home', 'search', 'intent', 'share'], true)) { return $m[1]; }
            break;
        case 'facebook':
            if (preg_match('~facebook\.com/(?!watch|reel|share|profile\.php|groups|story\.php|videos)([a-z0-9.]{3,50})~', $u, $m)) { return $m[1]; }
            break;
    }
    return null;
}

/** Channel handle of a YouTube video, read from its public watch page (null if unknown). */
function hpl_youtube_owner(string $url): ?string
{
    if (!preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{6,})~i', $url, $m) || !function_exists('curl_init')) {
        return null;
    }
    $ch = curl_init('https://www.youtube.com/watch?v=' . $m[1] . '&hl=en');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; HPLCreators/1.0)']);
    $html = (string)curl_exec($ch);
    curl_close($ch);
    if (preg_match('~"canonicalBaseUrl":"/@([A-Za-z0-9._-]+)"~', $html, $mm) || preg_match('~youtube\.com/@([A-Za-z0-9._-]+)~', $html, $mm)) {
        return strtolower($mm[1]);
    }
    return null;
}

/** Handles of a creator's pages on one platform (approved only by default). */
function hpl_user_handles(int $userId, string $platform, array $statuses = ['approved']): array
{
    $in = implode(',', array_fill(0, count($statuses), '?'));
    $rows = fetch_all("SELECT url, page_name FROM social_pages WHERE user_id = ? AND platform = ? AND status IN ($in)", array_merge([$userId, $platform], $statuses));
    $out = [];
    foreach ($rows as $r) {
        $h = hpl_handle_from_url($platform, (string)$r['url']);
        if ($h === null && $r['page_name']) {
            $h = strtolower(ltrim(trim((string)$r['page_name']), '@'));
        }
        if ($h) { $out[$h] = true; }
    }
    return array_keys($out);
}

/** Is this handle already claimed by a different HPL account (pending or approved)? */
function hpl_handle_taken(string $platform, string $handle, int $userId): bool
{
    $rows = fetch_all('SELECT user_id, url FROM social_pages WHERE platform = ? AND user_id <> ? AND status IN ("pending","approved")', [$platform, $userId]);
    foreach ($rows as $r) {
        if (hpl_handle_from_url($platform, (string)$r['url']) === $handle) {
            return true;
        }
    }
    return false;
}

/** Does the creator have at least one approved page? */
function hpl_has_verified_page(int $userId): bool
{
    return (int)fetch_val('SELECT COUNT(*) FROM social_pages WHERE user_id = ? AND status = "approved"', [$userId], 0) > 0;
}

/**
 * Check a submitted video link belongs to the creator. Returns an error message, or null when OK.
 * $url is passed by reference so resolved short links are stored in full.
 */
function hpl_link_ownership_error(int $userId, string $platform, string &$url): ?string
{
    $mine = hpl_user_handles($userId, $platform);
    if (in_array($platform, hpl_handle_platforms(), true) || $platform === 'facebook') {
        if (!$mine && !hpl_user_handles($userId, $platform, ['approved', 'pending'])) {
            return 'Add your ' . platform_label($platform) . ' page under "Your social pages" on your dashboard (Home) first. We can only pay for videos posted on your own verified page.';
        }
        if (!$mine) {
            return 'Your ' . platform_label($platform) . ' page is still waiting for verification. You can submit video links as soon as it is approved.';
        }
    }
    $url = hpl_resolve_url($url);
    $owner = hpl_handle_from_url($platform, $url);
    if ($owner === null && $platform === 'youtube') {
        $owner = hpl_youtube_owner($url);
    }
    if ($owner !== null && $mine && !in_array($owner, $mine, true)) {
        return 'This video was posted by @' . $owner . ', which is not one of your verified pages (@' . implode(', @', $mine) . '). Only videos from your own page can earn.';
    }
    return null;
}

/** Best-effort bio check: true = code found, false = page read but code missing, null = could not tell. */
function hpl_bio_has_code(string $url, string $code): ?bool
{
    $r = hpl_bio_check(['url' => $url, 'platform' => hpl_platform_of_url($url) ?? 'other'], $code);
    return $r['found'];
}

/** Does this text contain the code? Ignores case, spaces and dashes ("hpl dnen2k" counts). */
function hpl_text_has_code(string $text, string $code): bool
{
    $norm = function (string $s): string { return preg_replace('/[^A-Z0-9]/', '', strtoupper($s)); };
    return $text !== '' && strpos($norm($text), $norm($code)) !== false;
}

/**
 * Check a page's bio for its HPL code.
 * Returns ['found' => true|false|null, 'trusted' => bool, 'how' => text, 'tip' => text].
 *   found null  = we could not read the bio (the admin checks by eye)
 *   trusted     = the answer came from an official source (Meta API / TikTok profile data),
 *                 so a match can approve the page automatically
 */
function hpl_bio_check(array $page, string $code): array
{
    $platform = (string)($page['platform'] ?? 'other');
    $url = (string)($page['url'] ?? '');
    $handle = hpl_handle_from_url($platform, $url);

    if ($platform === 'instagram') {
        if (!function_exists('meta_ig_profile')) {
            require_once __DIR__ . '/meta.php';
        }
        if ($handle === null) {
            return ['found' => null, 'trusted' => false, 'how' => 'Instagram', 'tip' => 'The link is not an Instagram profile link (instagram.com/username).'];
        }
        $p = meta_ig_profile($handle);
        if ($p['ok']) {
            $bio = (string)($p['profile']['biography'] ?? '') . ' ' . (string)($p['profile']['website'] ?? '') . ' ' . (string)($p['profile']['name'] ?? '');
            return ['found' => hpl_text_has_code($bio, $code), 'trusted' => true, 'how' => 'Instagram (official Meta API)', 'tip' => ''];
        }
        if ($p['reason'] === 'personal') {
            return ['found' => null, 'trusted' => false, 'how' => 'Instagram',
                'tip' => '@' . $handle . ' is a personal Instagram account (or the username is wrong). Instagram only shares bios of Professional accounts. The creator should switch to a free Creator or Business account in Instagram settings; it is also needed for automatic view tracking.'];
        }
        return ['found' => null, 'trusted' => false, 'how' => 'Instagram', 'tip' => '', 'detail' => 'Meta did not answer: ' . $p['message']];
    }

    if (!function_exists('curl_init') || $url === '') {
        return ['found' => null, 'trusted' => false, 'how' => '', 'tip' => ''];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: en-US,en;q=0.9']]);
    $html = (string)curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($status >= 400 || strlen($html) < 500) {
        return ['found' => null, 'trusted' => false, 'how' => platform_label($platform), 'tip' => ''];
    }
    if (hpl_text_has_code($html, $code)) {
        return ['found' => true, 'trusted' => in_array($platform, ['tiktok', 'youtube'], true), 'how' => platform_label($platform) . ' public page', 'tip' => ''];
    }
    // Only say "missing" when we really read the profile's bio; login walls and blocked pages stay "unknown".
    if ($platform === 'tiktok' && preg_match('/"signature":"((?:[^"\\\\]|\\\\.)*)"/', $html)) {
        return ['found' => false, 'trusted' => true, 'how' => 'TikTok public profile', 'tip' => ''];
    }
    if ($platform === 'youtube' && stripos($html, '"channelId"') !== false) {
        return ['found' => false, 'trusted' => true, 'how' => 'YouTube public channel page', 'tip' => ''];
    }
    return ['found' => null, 'trusted' => false, 'how' => platform_label($platform), 'tip' => ''];
}

/* ------------------------------------------------------------------------
 * One video = one payout (2026-10-27)
 * ------------------------------------------------------------------------ */

/**
 * A stable key for "this exact video", whatever form the link takes
 * (tiktok:7412..., youtube:dQw4w9WgXcQ, instagram:Cx1AbC...). Never touches the network.
 */
function hpl_video_key(string $platform, string $url): ?string
{
    $u = trim($url);
    if ($u === '') {
        return null;
    }
    $m = [];
    switch ($platform) {
        case 'tiktok':
            if (preg_match('~/(?:video|photo)/(\d{8,25})~', $u, $m)) { return 'tiktok:' . $m[1]; }
            break;
        case 'youtube':
            if (preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|live/))([A-Za-z0-9_-]{6,})~i', $u, $m)) { return 'youtube:' . $m[1]; }
            break;
        case 'instagram':
            if (preg_match('~instagram\.com/(?:[A-Za-z0-9._]+/)?(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,})~i', $u, $m)) { return 'instagram:' . $m[1]; }
            break;
        case 'facebook':
            if (preg_match('~(?:/videos/|/reel/|[?&]v=|/watch/\?v=)(\d{6,})~i', $u, $m)) { return 'facebook:' . $m[1]; }
            break;
    }
    $p = parse_url($u);
    if (empty($p['host'])) {
        return null;
    }
    $host = preg_replace('/^(www\.|m\.|mobile\.)/', '', strtolower($p['host']));
    return 'url:' . $host . rtrim(strtolower($p['path'] ?? ''), '/');
}

/** Has this video already been submitted (as a clip or to any campaign)? Returns the owner's user id or null. */
function hpl_video_owner(string $key): ?int
{
    try {
        $row = fetch_one(
            '(SELECT user_id FROM content_links WHERE video_key = ?) UNION ALL (SELECT user_id FROM campaign_submissions WHERE video_key = ?) LIMIT 1',
            [$key, $key]
        );
    } catch (Throwable $e) {
        return null; // column not added yet; the URL checks still apply
    }
    return $row ? (int)$row['user_id'] : null;
}

/**
 * Every check a clip must pass before it can earn. Returns an error message, or null when
 * the clip is accepted. $url is replaced by the resolved link and $key by the video key.
 */
function hpl_clip_guard(int $userId, string $platform, string &$url, ?string &$key = null): ?string
{
    $url = hpl_resolve_url($url);
    $platformErr = hpl_url_platform_error($platform, $url);
    if ($platformErr !== null) {
        return $platformErr;
    }
    $key = hpl_video_key($platform, $url);
    if ($key === null || ($platform === 'tiktok' && strpos($key, 'tiktok:') !== 0)) {
        return 'That link does not open one video. On the video, tap Share, then "Copy link", and paste that link.';
    }

    $owner = hpl_video_owner($key);
    if ($owner !== null) {
        return $owner === $userId
            ? 'You have already submitted this video. Each video can earn once.'
            : 'This video has already been submitted by another creator. Only the person who posted it can earn from it.';
    }

    $ownErr = hpl_link_ownership_error($userId, $platform, $url);
    if ($ownErr !== null) {
        return $ownErr;
    }

    // TikTok: when the creator has connected the TikTok account in the link, ask TikTok itself
    // whether the video is on it (and when it was posted). Other verified pages of the creator
    // are not judged by a different connected account.
    if ($platform === 'tiktok' && function_exists('tiktok_configured') && tiktok_configured()) {
        $handle = hpl_handle_from_url('tiktok', $url);
        $acc = null;
        foreach (tiktok_accounts_for_user($userId) as $a) {
            if (tiktok_account_problem($a) !== null) {
                continue;
            }
            if ($handle === null || strtolower((string)$a['username']) === $handle) {
                $acc = $a;
                break;
            }
        }
        if ($acc) {
            $info = tiktok_video_info($acc, substr($key, 7));
            if ($info === [] && $handle !== null) {
                return 'This video is not on your TikTok account @' . $acc['username'] . '. Only videos you posted yourself can earn.';
            }
            if (is_array($info) && !empty($info['create_time']) && setting('only_new_videos', '1') === '1') {
                $joined = strtotime((string)fetch_val('SELECT created_at FROM users WHERE id = ?', [$userId], ''));
                if ($joined && (int)$info['create_time'] < $joined) {
                    return 'This video was posted on ' . date('j M Y', (int)$info['create_time']) . ', before you joined on ' . date('j M Y', $joined) . '. Only clips you post after joining can earn.';
                }
            }
        }
    }
    return null;
}

/** Web addresses each platform really uses (the link's domain must be one of these). */
function hpl_platform_domains(): array
{
    return [
        'tiktok'    => ['tiktok.com'],
        'instagram' => ['instagram.com', 'instagr.am'],
        'facebook'  => ['facebook.com', 'fb.com', 'fb.watch'],
        'youtube'   => ['youtube.com', 'youtu.be'],
        'twitter'   => ['x.com', 'twitter.com'],
        'snapchat'  => ['snapchat.com'],
    ];
}

/** Which platform a link belongs to, by its domain (null when unknown). */
function hpl_platform_of_url(string $url): ?string
{
    $host = strtolower((string)parse_url(trim($url), PHP_URL_HOST));
    if ($host === '') {
        return null;
    }
    foreach (hpl_platform_domains() as $platform => $domains) {
        foreach ($domains as $d) {
            if ($host === $d || substr($host, -strlen('.' . $d)) === '.' . $d) {
                return $platform;
            }
        }
    }
    return null;
}

/**
 * Does the pasted link belong to the platform chosen in the dropdown?
 * Returns an error message, or null when it matches (or the platform has no fixed domain).
 */
function hpl_url_platform_error(string $platform, string $url): ?string
{
    if (hpl_link_blocked($url)) {
        return 'That link is not allowed. Submit a link to your own social media video.';
    }
    $domains = hpl_platform_domains();
    if (!isset($domains[$platform])) {
        return null; // "Other", "Google Ads": any website is allowed
    }
    $actual = hpl_platform_of_url($url);
    if ($actual === $platform) {
        return null;
    }
    $chosen = platform_label($platform);
    $an = function (string $w): string { return (preg_match('/^[AEIOU]/i', $w) ? 'an ' : 'a ') . $w; };
    if ($actual !== null) {
        return 'That is ' . $an(platform_label($actual)) . ' link, but you chose ' . $chosen . '. Pick ' . platform_label($actual) . ' in the list, or paste your ' . $chosen . ' link.';
    }
    return 'That is not ' . $an($chosen) . ' link. Paste a link that starts with https://www.' . $domains[$platform][0] . '/';
}
