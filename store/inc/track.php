<?php
/**
 * Visitor analytics, kept on our own server (no outside tracker).
 * We record: pages viewed and time on each, how the visitor found us (Google, TikTok,
 * WhatsApp, a link...), device, browser, language, time zone, approximate location from the
 * IP address, and what they did (WhatsApp/call taps, chat, videos, orders). The privacy page says so.
 * Search engines and bots are not counted.
 */

const TRACK_VID = 'hplv';   // visitor, 1 year
const TRACK_SID = 'hpls';   // visit, ends after 30 minutes without activity

function track_is_bot(string $ua): bool
{
    return $ua === '' || (bool)preg_match('~bot|crawl|spider|slurp|facebookexternalhit|whatsapp/|preview|lighthouse|headless|curl|wget|python|java/|go-http|okhttp|monitor|uptime|scan~i', $ua);
}

function track_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/** Device, system and browser from the browser's description of itself. */
function track_ua(string $ua): array
{
    $dev = preg_match('~iPad|Tablet|SM-T|Tab~i', $ua) ? 'Tablet' : (preg_match('~Mobi|Android|iPhone|iPod|Opera Mini|KaiOS~i', $ua) ? 'Phone' : 'Computer');
    $os = 'Other';
    foreach (['Android' => '~Android ([\d.]+)~', 'iPhone' => '~iPhone OS ([\d_]+)~', 'iPad' => '~iPad.*OS ([\d_]+)~', 'Windows' => '~Windows NT ([\d.]+)~', 'Mac' => '~Mac OS X ([\d_]+)~', 'KaiOS' => '~KAIOS/([\d.]+)~i', 'Linux' => '~Linux~'] as $n => $re) {
        if (preg_match($re, $ua, $m)) { $os = $n . (isset($m[1]) ? ' ' . str_replace('_', '.', explode('.', str_replace('_', '.', $m[1]))[0]) : ''); break; }
    }
    $br = 'Other';
    foreach (['Opera Mini' => '~Opera Mini~', 'Opera' => '~OPR/|Opera~', 'Edge' => '~Edg/~', 'Samsung Internet' => '~SamsungBrowser~', 'UC Browser' => '~UCBrowser~', 'Facebook app' => '~FBAN|FBAV~', 'Instagram app' => '~Instagram~', 'TikTok app' => '~musical_ly|TikTok|BytedanceWebview~', 'Chrome' => '~Chrome/|CriOS~', 'Firefox' => '~Firefox/|FxiOS~', 'Safari' => '~Safari/~'] as $n => $re) {
        if (preg_match($re, $ua)) { $br = $n; break; }
    }
    $model = '';
    if (preg_match('~Android [\d.]+; ([^;)]+?)(?: Build|\))~', $ua, $m)) $model = trim($m[1]);
    elseif (str_contains($ua, 'iPhone')) $model = 'iPhone';
    return ['device' => $dev, 'os' => $os, 'browser' => $br, 'model' => mb_substr($model, 0, 60)];
}

/** Where the visit came from, in plain words. */
function track_source(string $ref, array $utm): string
{
    if (!empty($utm['utm_source'])) return mb_substr(strtolower($utm['utm_source']), 0, 40);
    $h = strtolower((string)parse_url($ref, PHP_URL_HOST));
    if ($h === '' ) return 'direct';
    if (str_contains($h, site_host()) || str_contains($h, 'magnetor9000') || str_contains($h, 'gpz7000africa')) return 'internal';
    foreach (['google' => 'google', 'bing' => 'bing', 'yahoo' => 'yahoo', 'duckduckgo' => 'duckduckgo', 'facebook' => 'facebook', 'fb.' => 'facebook', 'instagram' => 'instagram', 'tiktok' => 'tiktok', 'whatsapp' => 'whatsapp', 'wa.me' => 'whatsapp', 'youtube' => 'youtube', 'twitter' => 'x', 't.co' => 'x', 'linkedin' => 'linkedin', 'hayatiprospectors' => 'hayatiprospectors.com', 'chatgpt' => 'chatgpt', 'openai' => 'chatgpt'] as $k => $v) {
        if (str_contains($h, $k)) return $v;
    }
    return preg_replace('~^www\.~', '', $h);
}

/** Time zone → country, for when the IP lookup has nothing (phones in Africa almost always report their real zone). */
function track_tz_country(string $tz): string
{
    static $m = ['Africa/Lusaka' => 'Zambia', 'Africa/Lubumbashi' => 'DR Congo', 'Africa/Kinshasa' => 'DR Congo', 'Indian/Antananarivo' => 'Madagascar',
        'Africa/Monrovia' => 'Liberia', 'Africa/Harare' => 'Zimbabwe', 'Africa/Blantyre' => 'Malawi', 'Africa/Dar_es_Salaam' => 'Tanzania', 'Africa/Nairobi' => 'Kenya',
        'Africa/Kampala' => 'Uganda', 'Africa/Kigali' => 'Rwanda', 'Africa/Bujumbura' => 'Burundi', 'Africa/Maputo' => 'Mozambique', 'Africa/Johannesburg' => 'South Africa',
        'Africa/Gaborone' => 'Botswana', 'Africa/Windhoek' => 'Namibia', 'Africa/Luanda' => 'Angola', 'Africa/Brazzaville' => 'Congo', 'Africa/Accra' => 'Ghana',
        'Africa/Lagos' => 'Nigeria', 'Africa/Abidjan' => "Côte d'Ivoire", 'Africa/Conakry' => 'Guinea', 'Africa/Freetown' => 'Sierra Leone', 'Africa/Bamako' => 'Mali',
        'Africa/Ouagadougou' => 'Burkina Faso', 'Africa/Niamey' => 'Niger', 'Africa/Douala' => 'Cameroon', 'Africa/Bangui' => 'Central African Republic',
        'Africa/Ndjamena' => 'Chad', 'Africa/Khartoum' => 'Sudan', 'Africa/Juba' => 'South Sudan', 'Africa/Addis_Ababa' => 'Ethiopia', 'Africa/Cairo' => 'Egypt',
        'Africa/Dakar' => 'Senegal', 'Africa/Libreville' => 'Gabon', 'Africa/Mbabane' => 'Eswatini', 'Africa/Maseru' => 'Lesotho', 'Europe/London' => 'United Kingdom',
        'Asia/Dubai' => 'United Arab Emirates', 'Asia/Shanghai' => 'China', 'Asia/Kolkata' => 'India', 'Asia/Calcutta' => 'India'];
    return $m[$tz] ?? '';
}

function track_cookie(string $name, string $value, int $ttl): void
{
    setcookie($name, $value, ['expires' => $ttl ? time() + $ttl : 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax',
        'secure' => !empty($_SERVER['HTTPS']) || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https']);
    $_COOKIE[$name] = $value;
}

/** The current visitor row (made on first page view). */
function track_visitor(): ?array
{
    $vid = (string)($_COOKIE[TRACK_VID] ?? '');
    return preg_match('~^[a-f0-9]{32}$~', $vid) ? row('SELECT * FROM shop_visitors WHERE vid = ?', [$vid]) : null;
}

function track_session(): ?array
{
    $sid = (string)($_COOKIE[TRACK_SID] ?? '');
    return preg_match('~^[a-f0-9]{24}$~', $sid) ? row('SELECT * FROM shop_sessions WHERE sid = ? AND last_at > NOW() - INTERVAL 30 MINUTE', [$sid]) : null;
}

/** Record a page view. $d: path, title, ref, tz, screen, lang, utm_* , product. Returns the page view id. */
function track_pageview(array $d): int
{
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300);
    if (track_is_bot($ua)) return 0;
    $u = track_ua($ua);
    $now = date('Y-m-d H:i:s');
    $tz = mb_substr((string)($d['tz'] ?? ''), 0, 40);
    $v = track_visitor();
    if (!$v) {
        $vid = bin2hex(random_bytes(16));
        q('INSERT INTO shop_visitors (vid, first_at, last_at, ua, device, os, browser, model, lang, tz, screen, country) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$vid, $now, $now, $ua, $u['device'], $u['os'], $u['browser'], $u['model'], mb_substr((string)($d['blang'] ?? ''), 0, 20), $tz, mb_substr((string)($d['screen'] ?? ''), 0, 20), track_tz_country($tz)]);
        track_cookie(TRACK_VID, $vid, 400 * 86400);
        $v = row('SELECT * FROM shop_visitors WHERE vid = ?', [$vid]);
    }
    $s = track_session();
    if (!$s || (int)$s['visitor_id'] !== (int)$v['id']) {
        $ref = mb_substr((string)($d['ref'] ?? ''), 0, 500);
        $utm = array_map(fn($x) => mb_substr((string)$x, 0, 80), array_intersect_key($d, array_flip(['utm_source', 'utm_medium', 'utm_campaign'])));
        $src = track_source($ref, $utm);
        $sid = bin2hex(random_bytes(12));
        q('INSERT INTO shop_sessions (sid, visitor_id, started_at, last_at, ip, ref, source, utm_source, utm_medium, utm_campaign, landing, lang) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
            [$sid, $v['id'], $now, $now, track_ip(), $ref, $src, $utm['utm_source'] ?? '', $utm['utm_medium'] ?? '', $utm['utm_campaign'] ?? '', mb_substr((string)$d['path'], 0, 300), lang()]);
        $s = row('SELECT * FROM shop_sessions WHERE sid = ?', [$sid]);
        q('UPDATE shop_visitors SET visits = visits + 1, last_at = ?, ip = ?, first_source = IF(first_source = "", ?, first_source), first_landing = IF(first_landing = "", ?, first_landing) WHERE id = ?',
            [$now, track_ip(), $src, mb_substr((string)$d['path'], 0, 300), $v['id']]);
        track_geo_later($s['id'], $v['id'], track_ip(), $tz);
    }
    track_cookie(TRACK_SID, $s['sid'], 1800);
    q('INSERT INTO shop_pageviews (session_id, visitor_id, path, title, product_id, at) VALUES (?,?,?,?,?,?)',
        [$s['id'], $v['id'], mb_substr((string)$d['path'], 0, 300), mb_substr((string)($d['title'] ?? ''), 0, 200), (int)($d['product'] ?? 0) ?: null, $now]);
    $pv = (int)db()->lastInsertId();
    q('UPDATE shop_sessions SET last_at = ?, pages = pages + 1 WHERE id = ?', [$now, $s['id']]);
    q('UPDATE shop_visitors SET last_at = ?, pages = pages + 1 WHERE id = ?', [$now, $v['id']]);
    return $pv;
}

/** Something the visitor did: whatsapp, call, chat_open, video, consult, request, order... */
function track_event(string $type, string $label = ''): void
{
    $s = track_session();
    if (!$s) return;
    q('INSERT INTO shop_events (session_id, visitor_id, type, label, at) VALUES (?,?,?,?,NOW())', [$s['id'], $s['visitor_id'], mb_substr($type, 0, 30), mb_substr($label, 0, 200)]);
    q('UPDATE shop_sessions SET last_at = NOW() WHERE id = ?', [$s['id']]);
}

/** Tie the visitor to a chat or an order, so the team sees who they are talking to. */
function track_link(string $what, $value, string $name = '', string $phone = ''): void
{
    try {
        $v = track_visitor();
        if (!$v) return;
        if ($what === 'chat') q('UPDATE shop_visitors SET chat_id = ?, name = IF(? <> "", ?, name), phone = IF(? <> "", ?, phone) WHERE id = ?', [(int)$value, $name, $name, $phone, $phone, $v['id']]);
        if ($what === 'order') q('UPDATE shop_visitors SET order_ref = ?, name = IF(? <> "", ?, name), phone = IF(? <> "", ?, phone) WHERE id = ?', [(string)$value, $name, $name, $phone, $phone, $v['id']]);
        track_event($what, (string)$value);
    } catch (Throwable $e) {
    }
}

/** Approximate location from the IP address, looked up after the page is sent (never slows the visitor). */
function track_geo_later(int $sessionId, int $visitorId, string $ip, string $tz): void
{
    register_shutdown_function(function () use ($sessionId, $visitorId, $ip, $tz) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
        try {
            $g = row('SELECT * FROM shop_ipgeo WHERE ip = ? AND at > NOW() - INTERVAL 30 DAY', [$ip]);
            if (!$g && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) && function_exists('curl_init')) {
                $ch = curl_init('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country,region,city,latitude,longitude,connection');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_USERAGENT => 'HPLStore/1.0']);
                $j = json_decode((string)curl_exec($ch), true);
                curl_close($ch);
                if (!empty($j['success'])) {
                    q('INSERT INTO shop_ipgeo (ip, country, region, city, isp, lat, lng, at) VALUES (?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE country=VALUES(country), region=VALUES(region), city=VALUES(city), isp=VALUES(isp), lat=VALUES(lat), lng=VALUES(lng), at=NOW()',
                        [$ip, mb_substr((string)($j['country'] ?? ''), 0, 60), mb_substr((string)($j['region'] ?? ''), 0, 80), mb_substr((string)($j['city'] ?? ''), 0, 80),
                         mb_substr((string)($j['connection']['isp'] ?? $j['connection']['org'] ?? ''), 0, 120), $j['latitude'] ?? null, $j['longitude'] ?? null]);
                    $g = row('SELECT * FROM shop_ipgeo WHERE ip = ?', [$ip]);
                }
            }
            $country = $g['country'] ?? '' ?: track_tz_country($tz);
            q('UPDATE shop_sessions SET country = ?, region = ?, city = ?, isp = ? WHERE id = ?', [$country, $g['region'] ?? '', $g['city'] ?? '', $g['isp'] ?? '', $sessionId]);
            if ($country !== '') q('UPDATE shop_visitors SET country = ?, region = ?, city = ?, isp = ? WHERE id = ?', [$country, $g['region'] ?? '', $g['city'] ?? '', $g['isp'] ?? '', $visitorId]);
        } catch (Throwable $e) {
        }
    });
}
