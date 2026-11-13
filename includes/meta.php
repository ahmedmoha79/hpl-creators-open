<?php
/**
 * Meta (Instagram + Facebook) view reading through the official Graph API.
 *
 * Phase 1 - Instagram Business Discovery:
 *   HPL's own Instagram business account (linked to an HPL Facebook Page) asks
 *   Meta for the public numbers of another professional (business or creator)
 *   Instagram account: GET /{hpl-ig-id}?fields=business_discovery.username(x){media{...}}
 *   Creators do not log in. Their account must be a Professional account.
 *
 * Everything comes from Admin > Settings (nothing hardcoded):
 *   meta_app_id, meta_app_secret, meta_access_token, meta_ig_user_id, meta_graph_version
 */

function meta_graph_version(): string
{
    $v = trim((string)setting('meta_graph_version', ''));
    return preg_match('/^v\d{1,3}\.\d$/', $v) ? $v : 'v26.0';
}

function meta_configured(): bool
{
    return trim((string)setting('meta_access_token', '')) !== '' && trim((string)setting('meta_ig_user_id', '')) !== '';
}

/** Remember the last Graph API problem so the admin can see it in Settings. */
function meta_note_error(?string $msg): void
{
    $new = $msg === null ? '' : date('Y-m-d H:i') . ' ' . mb_substr($msg, 0, 300);
    if ((string)setting('meta_last_error', '') !== $new) {
        set_setting('meta_last_error', $new);
    }
}

/**
 * GET a Graph API path. Returns decoded JSON, or ['error' => ['message' => ...]].
 * Adds appsecret_proof when the app secret is saved (Meta's recommended protection).
 */
function meta_graph_get(string $path, array $params = [], ?string $token = null): array
{
    $token = $token ?? trim((string)setting('meta_access_token', ''));
    if ($token === '') {
        return ['error' => ['message' => 'No Meta access token saved.']];
    }
    $params['access_token'] = $token;
    $secret = trim((string)setting('meta_app_secret', ''));
    if ($secret !== '') {
        $params['appsecret_proof'] = hash_hmac('sha256', $token, $secret);
    }
    $url = 'https://graph.facebook.com/' . meta_graph_version() . '/' . ltrim($path, '/') . '?' . http_build_query($params);
    if (!function_exists('curl_init')) {
        return ['error' => ['message' => 'cURL is not available on this server.']];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_USERAGENT      => 'HPLCreators/1.0',
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($body) || $body === '') {
        return ['error' => ['message' => 'Could not reach Meta' . ($err ? ': ' . $err : '.')]];
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : ['error' => ['message' => 'Meta sent an unreadable reply.']];
}

/** Instagram shortcode of a post/reel link (instagram.com/reel/ABC123/ -> ABC123), or null. */
function meta_ig_shortcode(string $url): ?string
{
    return preg_match('~instagram\.com/(?:[A-Za-z0-9._]+/)?(?:p|reel|reels|tv)/([A-Za-z0-9_-]{5,})~i', $url, $m) ? $m[1] : null;
}

/**
 * Recent media of a professional Instagram account, keyed by shortcode.
 * Cached for this request so many clips from one creator cost one call.
 * Returns null when Meta refuses (personal account, not found, token problem).
 */
function meta_ig_media_by_handle(string $handle, int $pages = 0): ?array
{
    static $cache = [];
    $handle = strtolower(ltrim(trim($handle), '@'));
    if ($handle === '' || !preg_match('/^[a-z0-9._]{1,30}$/', $handle)) {
        return null;
    }
    if (array_key_exists($handle, $cache)) {
        return $cache[$handle];
    }
    $igId = trim((string)setting('meta_ig_user_id', ''));
    $pages = $pages > 0 ? $pages : max(1, min(10, (int)setting('meta_media_pages', 4)));
    $out = [];
    $after = null;
    for ($i = 0; $i < $pages; $i++) {
        $media = 'media.limit(50)' . ($after ? '.after(' . $after . ')' : '')
            . '{id,permalink,media_type,media_product_type,view_count,like_count,comments_count,timestamp}';
        $res = meta_graph_get($igId, ['fields' => 'business_discovery.username(' . $handle . '){username,' . $media . '}']);
        if (isset($res['error'])) {
            $msg = (string)($res['error']['message'] ?? 'Unknown error');
            meta_note_error('@' . $handle . ': ' . $msg);
            $cache[$handle] = $i === 0 ? null : $out;
            return $cache[$handle];
        }
        $bd = $res['business_discovery']['media'] ?? null;
        foreach (($bd['data'] ?? []) as $m) {
            $code = meta_ig_shortcode((string)($m['permalink'] ?? ''));
            if ($code !== null) {
                $out[$code] = $m;
            }
        }
        // Meta often omits paging.next here; a full page plus a cursor means there is more.
        $after = $bd['paging']['cursors']['after'] ?? null;
        if (!$after || count($bd['data'] ?? []) < 50) {
            break;
        }
    }
    meta_note_error(null);
    $cache[$handle] = $out;
    return $out;
}

/**
 * Real view count of an Instagram clip, read through HPL's business account.
 * Looks only at the creator's own verified Instagram pages, so a clip is only
 * ever matched on the account that posted it.
 */
function meta_instagram_views(string $url, ?int $userId): ?int
{
    if (!meta_configured() || $userId === null) {
        return null;
    }
    $code = meta_ig_shortcode($url);
    if ($code === null) {
        return null;
    }
    $handles = hpl_user_handles($userId, 'instagram');
    foreach ($handles as $h) {
        $media = meta_ig_media_by_handle($h);
        if ($media && isset($media[$code])) {
            $m = $media[$code];
            if (isset($m['view_count'])) {
                return (int)$m['view_count'];
            }
            return null; // Meta did not share a view count for this post (e.g. a photo)
        }
    }
    return null;
}

/** Swap a short-lived user token for a 60-day one (needs app id + secret). Returns [token|null, error|null]. */
function meta_exchange_long_lived(string $shortToken): array
{
    $appId = trim((string)setting('meta_app_id', ''));
    $secret = trim((string)setting('meta_app_secret', ''));
    if ($appId === '' || $secret === '') {
        return [null, 'Save the App ID and App secret first.'];
    }
    $url = 'https://graph.facebook.com/' . meta_graph_version() . '/oauth/access_token?' . http_build_query([
        'grant_type' => 'fb_exchange_token', 'client_id' => $appId, 'client_secret' => $secret, 'fb_exchange_token' => $shortToken,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    $j = json_decode($body, true);
    if (!empty($j['access_token'])) {
        return [(string)$j['access_token'], null];
    }
    return [null, (string)($j['error']['message'] ?? 'Meta did not return a token.')];
}

/** Instagram business accounts reachable with the saved token: [['page'=>..,'ig_id'=>..,'username'=>..], ...] */
function meta_find_ig_accounts(): array
{
    $res = meta_graph_get('me/accounts', ['fields' => 'name,instagram_business_account{id,username}', 'limit' => 50]);
    if (isset($res['error'])) {
        return ['error' => (string)($res['error']['message'] ?? 'Unknown error')];
    }
    $out = [];
    foreach (($res['data'] ?? []) as $p) {
        if (!empty($p['instagram_business_account']['id'])) {
            $out[] = ['page' => (string)$p['name'], 'ig_id' => (string)$p['instagram_business_account']['id'],
                'username' => (string)($p['instagram_business_account']['username'] ?? '')];
        }
    }
    return ['accounts' => $out, 'pages' => count($res['data'] ?? [])];
}

/**
 * Public profile of a professional Instagram account (bio, website, name), read through HPL's
 * business account. Returns ['ok' => true, 'profile' => [...]] or ['ok' => false, 'reason' => 'personal'|'error', 'message' => ...].
 */
function meta_ig_profile(string $handle): array
{
    $handle = strtolower(ltrim(trim($handle), '@'));
    if (!meta_configured()) {
        return ['ok' => false, 'reason' => 'error', 'message' => 'The Instagram connection is not set up in Settings.'];
    }
    if ($handle === '' || !preg_match('/^[a-z0-9._]{1,30}$/', $handle)) {
        return ['ok' => false, 'reason' => 'error', 'message' => 'That is not a valid Instagram username.'];
    }
    $res = meta_graph_get(trim((string)setting('meta_ig_user_id', '')), [
        'fields' => 'business_discovery.username(' . $handle . '){username,name,biography,website,followers_count,media_count}',
    ]);
    if (isset($res['error'])) {
        $msg = (string)($res['error']['message'] ?? '');
        $sub = (int)($res['error']['error_subcode'] ?? 0);
        // Meta answers "user not found" for personal accounts and for usernames that do not exist.
        if ($sub === 2207013 || stripos($msg, 'cannot be found') !== false || stripos($msg, 'Invalid user id') !== false || (int)($res['error']['code'] ?? 0) === 110) {
            return ['ok' => false, 'reason' => 'personal', 'message' => $msg];
        }
        meta_note_error('@' . $handle . ': ' . $msg);
        return ['ok' => false, 'reason' => 'error', 'message' => $msg];
    }
    $bd = $res['business_discovery'] ?? null;
    if (!$bd) {
        return ['ok' => false, 'reason' => 'error', 'message' => 'Meta sent no profile.'];
    }
    return ['ok' => true, 'profile' => $bd];
}
