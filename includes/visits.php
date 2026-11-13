<?php
/**
 * Visitors (2026-11-10): how many people came to the site each day, where from, and on what.
 * One small row per visitor per day (site_visits). A visitor is a browser, known by a random id in a cookie
 * (no name, no personal data); signed-in creators are linked to their account so "creators active today" is exact.
 * Robots (Google, link previews, monitors) are left out by their name. Internet addresses are not stored.
 * Counted on real pages only (the public pages and the creator pages), never on the background calls a page makes.
 */

/** Known places, by a piece of their web address => the name shown in Analytics. */
function visit_source_names(): array
{
    return ['tiktok' => 'TikTok', 'whatsapp' => 'WhatsApp', 'wa.me' => 'WhatsApp', 'facebook' => 'Facebook', 'fb.' => 'Facebook', 'instagram' => 'Instagram', 'google' => 'Google',
        'bing' => 'Bing', 'youtube' => 'YouTube', 't.me' => 'Telegram', 'telegram' => 'Telegram', 'twitter' => 'X', 'x.com' => 'X', 't.co' => 'X'];
}

/** Short name of where a visitor came from. */
function visit_source(string $ref, string $selfHost): string
{
    $h = strtolower((string)parse_url($ref, PHP_URL_HOST));
    if ($h === '' || $h === strtolower($selfHost)) {
        return 'direct';
    }
    foreach (visit_source_names() as $needle => $name) {
        if (strpos($h, $needle) !== false) {
            return $name;
        }
    }
    return mb_substr(preg_replace('/^www\./', '', $h), 0, 40);
}

/** Count this page view. Call once per real page, before any output. Never throws, never slows a page noticeably. */
function visit_track(?array $user = null): void
{
    static $done = false;
    if ($done || PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' || headers_sent()) {
        return;
    }
    $done = true;
    try {
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp\/|monitor|uptime|curl|wget|python|headless|lighthouse|pingdom|scan/i', $ua)) {
            return;
        }
        if ($user && ($user['role'] ?? '') === 'admin') {
            return; // the team's own visits would only blur the numbers
        }
        $vid = (string)($_COOKIE['hpl_v'] ?? '');
        $new = !preg_match('/^[a-f0-9]{16}$/', $vid);
        if ($new) {
            $vid = bin2hex(random_bytes(8));
            setcookie('hpl_v', $vid, ['expires' => time() + 365 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        }
        $src = visit_source((string)($_SERVER['HTTP_REFERER'] ?? ''), (string)($_SERVER['HTTP_HOST'] ?? ''));
        if (isset($_GET['utm_source']) && preg_match('/^[A-Za-z0-9_.-]{2,30}$/', (string)$_GET['utm_source'])) {
            $src = (string)$_GET['utm_source']; // links we share ourselves can say where they were posted
            foreach (visit_source_names() as $needle => $name) {
                if (stripos($src, rtrim($needle, '.')) === 0) { $src = $name; break; } // "whatsapp" and a WhatsApp referrer count together
            }
        }
        $page = mb_substr((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), 0, 80);
        $step = preg_match('~/register\.php$~', $page) ? 1 : 0; // opened the sign-up page
        $inapp = visit_inapp($ua);
        try {
            q('INSERT INTO site_visits (day, vid, user_id, hits, source, landing, is_mobile, is_new, step, inapp) VALUES (CURDATE(), ?, ?, 1, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = NOW(), user_id = COALESCE(VALUES(user_id), user_id), step = GREATEST(step, VALUES(step)),
               inapp = IF(VALUES(inapp) <> "", VALUES(inapp), inapp)',
                [$vid, $user ? (int)$user['id'] : null, $src, $page, preg_match('/Mobi|Android|iPhone|iPad/i', $ua) ? 1 : 0, $new ? 1 : 0, $step, $inapp]);
        } catch (Throwable $e) { // before the step/inapp columns exist
            q('INSERT INTO site_visits (day, vid, user_id, hits, source, landing, is_mobile, is_new) VALUES (CURDATE(), ?, ?, 1, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = NOW(), user_id = COALESCE(VALUES(user_id), user_id)',
                [$vid, $user ? (int)$user['id'] : null, $src, $page, preg_match('/Mobi|Android|iPhone|iPad/i', $ua) ? 1 : 0, $new ? 1 : 0]);
        }
    } catch (Throwable $e) {
        // Counting visitors must never break a page (for example before the table exists).
    }
}

/** The app a page was opened inside (Facebook, TikTok...), or "" for a normal browser. */
function visit_inapp(string $ua): string
{
    foreach (['TikTok' => '/musical_ly|BytedanceWebview|TikTok/i', 'Facebook' => '/FBAN|FBAV|FB_IAB|FBIOS/i', 'Instagram' => '/Instagram/i',
        'Snapchat' => '/Snapchat/i', 'LinkedIn' => '/LinkedInApp/i'] as $name => $re) {
        if (preg_match($re, $ua)) {
            return $name;
        }
    }
    return '';
}

/** This visitor finished signing up (any way: email or Google). */
function visit_signed_up(int $userId = 0): void
{
    try {
        $vid = (string)($_COOKIE['hpl_v'] ?? '');
        if (preg_match('/^[a-f0-9]{16}$/', $vid)) {
            q('UPDATE site_visits SET step = 2, user_id = COALESCE(user_id, ?) WHERE day = CURDATE() AND vid = ?', [$userId ?: null, $vid]);
        }
    } catch (Throwable $e) {
    }
}

/** Old rows go after 120 days. Run by the background sync. */
function visit_prune(): void
{
    try {
        if ((int)setting('visits_pruned_at', '0') < time() - 86400) {
            set_setting('visits_pruned_at', (string)time());
            q('DELETE FROM site_visits WHERE day < CURDATE() - INTERVAL 120 DAY');
        }
    } catch (Throwable $e) {
    }
}
