<?php
/**
 * Home-screen notifications (Web Push, 2026-11-10).
 *
 * A creator who taps "Turn on notifications" gets HPL notifications on the phone's own notification
 * bar, even when the site is closed (Android: Chrome and others; iPhone: after "Add to Home Screen").
 *
 * How it works, kept deliberately simple so there is little to break:
 *  - The phone's browser gives us an address (endpoint) at Google/Apple/Mozilla. We keep it (push_subs).
 *  - To notify someone we send an EMPTY, signed "wake up" to that address. No text travels through
 *    Google or Apple: the phone wakes our service worker (sw.js), which asks OUR site for the person's
 *    newest unread notification (api/push.php?latest=1, with their own sign-in) and shows it.
 *  - The signature is the VAPID standard (an ES256 token made with our own key pair, kept in settings).
 *  - notif_add() only marks the person as "has something to push". The sending happens after the page
 *    has been delivered (push_flush), a few people per request, so nobody waits for it.
 */

function b64u(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

/** Our VAPID key pair: ['public' => base64url of the 65-byte key for the browser, 'pem' => private key]. Made once. */
function push_keys(): ?array
{
    static $k = null;
    if ($k !== null) {
        return $k ?: null;
    }
    $pub = (string)setting('push_vapid_public', '');
    $pem = (string)setting('push_vapid_private', '');
    if ($pub === '' || $pem === '') {
        if (!function_exists('openssl_pkey_new')) {
            $k = false;
            return null;
        }
        $res = @openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = $res ? openssl_pkey_get_details($res) : null;
        if (!$res || !$d || empty($d['ec']['x']) || !openssl_pkey_export($res, $pem)) {
            $k = false;
            return null;
        }
        $pub = b64u("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
        // Two requests may make keys at the same moment: the first one saved wins, everybody reads it back.
        q('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ("push_vapid_private", ?)', [$pem]);
        $pem = (string)fetch_val('SELECT setting_value FROM settings WHERE setting_key = "push_vapid_private"', [], $pem);
        $d2 = openssl_pkey_get_details(openssl_pkey_get_private($pem));
        $pub = b64u("\x04" . str_pad($d2['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d2['ec']['y'], 32, "\0", STR_PAD_LEFT));
        set_setting('push_vapid_public', $pub);
        set_setting('push_vapid_private', $pem);
    }
    $k = ['public' => $pub, 'pem' => $pem];
    return $k;
}

/** Can this site send pushes at all? */
function push_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (string)setting('schema_version', '') >= '2026111003' && push_keys() !== null;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Is anybody waiting for a push? If so, send after this request. Called from the page-status poll every open page makes. */
function push_flush_if_pending(): void
{
    try {
        if (fetch_val('SELECT 1 FROM users WHERE push_pending_at IS NOT NULL LIMIT 1', [], 0)) {
            push_flush_later();
        }
    } catch (Throwable $e) {
    }
}

/** A DER ECDSA signature as the plain 64 bytes (r|s) that JWT wants. */
function push_der_to_raw(string $der): ?string
{
    $pos = 0;
    if (!isset($der[1]) || ord($der[$pos++]) !== 0x30) {
        return null;
    }
    $len = ord($der[$pos++]);
    if ($len & 0x80) {
        $pos += $len & 0x7f;
    }
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        if (!isset($der[$pos + 1]) || ord($der[$pos++]) !== 0x02) {
            return null;
        }
        $l = ord($der[$pos++]);
        $v = ltrim(substr($der, $pos, $l), "\0");
        $pos += $l;
        if (strlen($v) > 32) {
            return null;
        }
        $out .= str_pad($v, 32, "\0", STR_PAD_LEFT);
    }
    return $out;
}

/** The Authorization header value for one push service (one token per service, reused for 6 hours). */
function push_auth_header(string $endpoint): ?string
{
    static $cache = [];
    $keys = push_keys();
    $p = parse_url($endpoint);
    if (!$keys || empty($p['scheme']) || empty($p['host']) || $p['scheme'] !== 'https') {
        return null;
    }
    $aud = 'https://' . $p['host'];
    if (!isset($cache[$aud])) {
        $head = b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $body = b64u(json_encode(['aud' => $aud, 'exp' => time() + 6 * 3600, 'sub' => 'mailto:' . (string)setting('support_email', 'support@golddetectorinzambia.com')]));
        $sig = '';
        if (!openssl_sign($head . '.' . $body, $sig, $keys['pem'], OPENSSL_ALGO_SHA256)) {
            return null;
        }
        $raw = push_der_to_raw($sig);
        if ($raw === null) {
            return null;
        }
        $cache[$aud] = 'vapid t=' . $head . '.' . $body . '.' . b64u($raw) . ', k=' . $keys['public'];
    }
    return $cache[$aud];
}

/** Only real push services are ever contacted (the address comes from the browser, so it is checked). */
function push_endpoint_ok(string $endpoint): bool
{
    $p = parse_url($endpoint);
    if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || strlen($endpoint) > 1000) {
        return false;
    }
    $h = strtolower($p['host']);
    foreach (['.googleapis.com', '.push.apple.com', '.mozilla.com', '.mozaws.net', '.notify.windows.com', '.push.services.mozilla.com'] as $ok) {
        if (substr($h, -strlen($ok)) === $ok) {
            return true;
        }
    }
    return defined('HPL_DEV') && $h === 'push.test';
}

/** Save a phone's push address for this user. */
function push_subscribe(int $userId, string $endpoint): bool
{
    if ($userId <= 0 || !push_endpoint_ok($endpoint) || !push_ready()) {
        return false;
    }
    q('INSERT INTO push_subs (user_id, endpoint, endpoint_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), fails = 0',
        [$userId, $endpoint, hash('sha256', $endpoint)]);
    // A person keeps at most 5 phones/browsers; the oldest go.
    $ids = array_column(fetch_all('SELECT id FROM push_subs WHERE user_id = ? ORDER BY id DESC', [$userId]), 'id');
    if (count($ids) > 5) {
        q('DELETE FROM push_subs WHERE id IN (' . implode(',', array_map('intval', array_slice($ids, 5))) . ')');
    }
    q('UPDATE users SET push_on = 1 WHERE id = ?', [$userId]);
    return true;
}

function push_unsubscribe(int $userId, string $endpoint): void
{
    q('DELETE FROM push_subs WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
    if (!fetch_val('SELECT 1 FROM push_subs WHERE user_id = ? LIMIT 1', [$userId], 0)) {
        q('UPDATE users SET push_on = 0, push_pending_at = NULL WHERE id = ?', [$userId]);
    }
}

/** Mark people as "something new to push". Cheap: one UPDATE, and only for people who turned it on. */
function push_mark(array $userIds): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if (!$ids) {
        return;
    }
    try {
        q('UPDATE users SET push_pending_at = NOW() WHERE push_on = 1 AND id IN (' . implode(',', $ids) . ')');
        push_flush_later();
    } catch (Throwable $e) {
        // Column not there yet (before the migration): nothing to push.
    }
}

/** Run push_flush() once, after this page has been sent to the visitor. */
function push_flush_later(): void
{
    static $armed = false;
    if ($armed) {
        return;
    }
    $armed = true;
    register_shutdown_function(function () {
        try {
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                @litespeed_finish_request();
            }
            @ignore_user_abort(true);
            push_flush();
        } catch (Throwable $e) {
            error_log('push flush: ' . $e->getMessage());
        }
    });
}

/**
 * Send the waiting "wake ups": up to $maxUsers people in this run, all at the same time, 4 seconds at most.
 * Each person is claimed first, so two requests never notify the same person twice.
 * Addresses the push service says are gone (404/410) are deleted.
 */
function push_flush(int $maxUsers = 40): int
{
    if (!push_ready()) {
        return 0;
    }
    $ids = [];
    foreach (fetch_all('SELECT id FROM users WHERE push_pending_at IS NOT NULL ORDER BY push_pending_at LIMIT ' . max(1, min(200, $maxUsers))) as $r) {
        if (q('UPDATE users SET push_pending_at = NULL WHERE id = ? AND push_pending_at IS NOT NULL', [(int)$r['id']])->rowCount() === 1) {
            $ids[] = (int)$r['id'];
        }
    }
    if (!$ids) {
        return 0;
    }
    $subs = fetch_all('SELECT id, endpoint FROM push_subs WHERE user_id IN (' . implode(',', $ids) . ') AND fails < 5');
    if (!$subs || !function_exists('curl_multi_init')) {
        return 0;
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($subs as $s) {
        $auth = push_auth_header((string)$s['endpoint']);
        if ($auth === null || !push_endpoint_ok((string)$s['endpoint'])) {
            continue;
        }
        $ch = curl_init((string)$s['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => '', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: ' . $auth, 'TTL: 43200', 'Urgency: high', 'Content-Length: 0'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$s['id']] = $ch;
    }
    $sent = 0;
    if ($handles) {
        $end = microtime(true) + 6;
        do {
            $st = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 0.5);
            }
        } while ($running && $st === CURLM_OK && microtime(true) < $end);
        foreach ($handles as $sid => $ch) {
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($code >= 200 && $code < 300) {
                $sent++;
                q('UPDATE push_subs SET last_ok_at = NOW(), fails = 0 WHERE id = ?', [$sid]);
            } elseif ($code === 404 || $code === 410) {
                q('DELETE FROM push_subs WHERE id = ?', [$sid]);
            } else {
                q('UPDATE push_subs SET fails = fails + 1 WHERE id = ?', [$sid]);
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
    }
    curl_multi_close($mh);
    // People whose last address is gone no longer count as "on".
    q('UPDATE users u SET u.push_on = 0 WHERE u.push_on = 1 AND u.id IN (' . implode(',', $ids) . ') AND NOT EXISTS (SELECT 1 FROM push_subs p WHERE p.user_id = u.id)');
    return $sent;
}

/** What the phone shows: this user's newest unread notification, or a general line. */
function push_latest_for(array $user): array
{
    $n = fetch_one('SELECT id, title, body, url FROM notifications WHERE user_id = ? AND is_read = 0 ORDER BY id DESC LIMIT 1', [(int)$user['id']]);
    $count = (int)fetch_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [(int)$user['id']], 0);
    if (!$n) {
        return ['title' => 'HPL Creators', 'body' => 'Open the app to see what is new.', 'url' => u('creator/index.php'), 'tag' => 'hpl-0'];
    }
    return ['title' => (string)$n['title'], 'body' => (string)$n['body'] . ($count > 1 ? ' (+' . ($count - 1) . ' more)' : ''),
        'url' => (string)$n['url'] !== '' ? u((string)$n['url']) : u('creator/notifications.php'), 'tag' => 'hpl-' . (int)$n['id']];
}
