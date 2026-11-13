<?php
/**
 * Maintenance ("downtime") mode for HPL Creators (2026-11-06).
 *
 * Driven by two settings so it works on the live server (which reads the same database):
 *   maintenance_until   unix timestamp the downtime is expected to end (0/empty = off)
 *   maintenance_message optional extra line shown to visitors
 *
 * While on, creators and visitors see a downtime page; admins still get in to keep working,
 * and the HPL Store (minelabzambia.com, served from /store) is never affected.
 */

/** Is downtime switched on right now? Returns the end timestamp, or 0. */
function maintenance_until(): int
{
    if (!function_exists('setting')) {
        return 0;
    }
    try {
        $t = (int)setting('maintenance_until', 0);
    } catch (Throwable $e) {
        return 0;
    }
    // An end time in the past means the window has elapsed: treat as off (the page can still
    // show "back shortly" for a short grace period handled in the view).
    return $t > 0 ? $t : 0;
}

/**
 * Decide whether to show the downtime page for this request, and show it if so.
 * Safe to call on every web page; does nothing on CLI, for the store, for admins,
 * or for the paths needed to sign in and administer.
 */
function hpl_maintenance_gate(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    $until = maintenance_until();
    if ($until <= 0) {
        return;
    }

    // Never gate the HPL Store: it shares this code but is a separate site.
    $script = (string)($_SERVER['SCRIPT_FILENAME'] ?? '');
    $real = $script !== '' ? (realpath($script) ?: $script) : '';
    $storeDir = realpath(BASE_PATH . '/store') ?: (BASE_PATH . '/store');
    if ($real !== '' && strpos($real, $storeDir) === 0) {
        return;
    }

    // The path being requested, relative to the site root.
    $path = strtolower(trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/'));

    // Always-allowed paths: signing in, the whole admin area, background jobs, assets, the app shell.
    $allowPrefix = ['admin/', 'assets/', 'cron/', 'uploads/', 'storage/', 'store/'];
    foreach ($allowPrefix as $pre) {
        if (strpos($path, $pre) === 0) {
            return;
        }
    }
    $allowExact = ['login.php', 'logout.php', 'google_auth.php', 'app_login.php',
        'favicon.ico', 'manifest.webmanifest', 'sw.js', 'offline.html', 'robots.txt', 'telegram_webhook.php'];
    if (in_array($path, $allowExact, true)) {
        return;
    }

    // Admins pass straight through so they can keep working during downtime.
    if (function_exists('current_user')) {
        $u = current_user();
        if ($u && ($u['role'] ?? '') === 'admin') {
            return;
        }
    }

    maintenance_render($until);
}

/** Render the downtime page and stop. */
function maintenance_render(int $until): void
{
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        header('Retry-After: ' . max(60, $until - time()));
        header('Cache-Control: no-store');
    }
    $msg = '';
    try {
        $msg = trim((string)setting('maintenance_message', ''));
    } catch (Throwable $e) {
    }
    $app = defined('APP_NAME') ? APP_NAME : 'HPL Creators';
    $logo = function_exists('u') ? u('assets/img/app-192.png') : '/assets/img/app-192.png';
    $endIso = date('c', $until);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>Back soon · <?= $e($app) ?></title>
<style>
    :root { --bg:#17120d; --card:#201810; --line:#3a2e20; --gold:#e2ad7b; --text:#f3ece2; --muted:#b6a892; }
    * { box-sizing:border-box; }
    body { margin:0; min-height:100vh; display:grid; place-items:center; padding:24px;
        background:radial-gradient(1200px 600px at 50% -10%, #2a2012 0%, var(--bg) 60%); color:var(--text);
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
    .card { width:min(520px,100%); background:var(--card); border:1px solid var(--line); border-radius:22px;
        padding:40px 30px; text-align:center; box-shadow:0 30px 80px rgba(0,0,0,.45); }
    img { width:76px; height:76px; border-radius:18px; margin-bottom:20px; }
    h1 { margin:0 0 10px; font-size:1.7rem; }
    p { margin:0 auto 18px; max-width:40ch; color:var(--muted); line-height:1.6; }
    .count { display:flex; gap:10px; justify-content:center; margin:24px 0 8px; }
    .count div { background:#2c2114; border:1px solid var(--line); border-radius:14px; padding:12px 6px; min-width:74px; }
    .count b { display:block; font-size:1.9rem; font-weight:750; color:var(--gold); line-height:1; }
    .count span { font-size:.8125rem; text-transform: none; letter-spacing:.06em; color:var(--muted); }
    .note { font-size:.85rem; color:var(--muted); margin-top:18px; }
    .extra { margin-top:16px; padding:12px 14px; background:#2c2114; border:1px solid var(--line); border-radius:12px; color:var(--text); font-size:.92rem; }
</style>
</head>
<body>
    <div class="card">
        <img src="<?= $e($logo) ?>" alt="" onerror="this.style.display='none'">
        <h1>We'll be right back</h1>
        <p><?= $e($app) ?> is down for a short upgrade. Your account, your clips and your balance are safe and waiting for you.</p>
        <div class="count" id="count" hidden>
            <div><b id="h">0</b><span>hours</span></div>
            <div><b id="m">0</b><span>minutes</span></div>
            <div><b id="s">0</b><span>seconds</span></div>
        </div>
        <p class="note" id="eta">Please check back in about 1 hour 30 minutes.</p>
        <?php if ($msg !== ''): ?><div class="extra"><?= $e($msg) ?></div><?php endif; ?>
    </div>
<script>
(function(){
    var end = new Date(<?= json_encode($endIso) ?>).getTime();
    var box = document.getElementById('count'), eta = document.getElementById('eta');
    function tick(){
        var left = Math.max(0, Math.floor((end - Date.now())/1000));
        if (left <= 0) { eta.textContent = 'We should be back now. Tap refresh.'; box.hidden = true; return; }
        box.hidden = false;
        document.getElementById('h').textContent = Math.floor(left/3600);
        document.getElementById('m').textContent = Math.floor((left%3600)/60);
        document.getElementById('s').textContent = left%60;
        eta.textContent = 'Back in about ' + (Math.floor(left/3600) ? Math.floor(left/3600)+'h ' : '') + Math.floor((left%3600)/60) + 'm.';
    }
    tick(); setInterval(tick, 1000);
    // Come back on its own once the window has passed.
    setInterval(function(){ if (Date.now() > end + 5000) location.reload(); }, 15000);
})();
</script>
</body>
</html><?php
    exit;
}
