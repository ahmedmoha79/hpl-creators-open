<?php
/**
 * Session & authentication helpers.
 */
// Error details are never shown to visitors (they go to the error log instead).
// Only a local test copy shows them, by defining HPL_DEV in its own config.php.
if (PHP_SAPI !== 'cli' && !(defined('HPL_DEV') && HPL_DEV)) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        session_name('hplsid');
        // Session cookie: not readable by scripts, not sent on cross-site posts, HTTPS-only on HTTPS.
        $secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        // Stay signed in for 30 days. Sessions live in the site's own folder, so the
        // host's shared clean-up (which deletes idle sessions after ~24 minutes) cannot end them.
        $keepSignedIn = 30 * 86400;
        $sessDir = dirname(__DIR__) . '/storage/sessions';
        if (!is_dir($sessDir)) {
            @mkdir($sessDir, 0700, true);
            @file_put_contents($sessDir . '/.htaccess', "Require all denied\n");
        }
        if (is_dir($sessDir) && is_writable($sessDir)) {
            session_save_path($sessDir);
            ini_set('session.gc_maxlifetime', (string)$keepSignedIn);
        }
        session_set_cookie_params([
            'lifetime' => $keepSignedIn,
            'path'     => '/',
            'secure'   => $secureCookie,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
    if (!headers_sent()) {
        session_start();
    }
}

/**
 * Currently logged-in user row.
 * Cached per session user id so a fresh login (new id) is re-read immediately.
 */
function current_user(): ?array
{
    static $cache = [];
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    $id = (int)$_SESSION['user_id'];
    if (array_key_exists($id, $cache)) {
        return $cache[$id];
    }
    $user = fetch_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$user || $user['status'] === 'suspended') {
        return $cache[$id] = null;
    }
    // A new password signs out every other device: the session remembers which password it was
    // signed in with, and a session from before a password change no longer counts.
    $pwTag = $id . ':' . substr(hash('sha256', (string)$user['password']), 0, 24);
    $had = (string)($_SESSION['pw_tag'] ?? '');
    if ($had !== '' && strpos($had, $id . ':') === 0 && !hash_equals($had, $pwTag)) {
        unset($_SESSION['user_id'], $_SESSION['role'], $_SESSION['pw_tag']);
        return $cache[$id] = null;
    }
    $_SESSION['pw_tag'] = $pwTag;
    return $cache[$id] = $user;
}

/** After someone changes their own password, keep this device signed in. */
function session_keep_after_password_change(int $userId, string $newHash): void
{
    $_SESSION['pw_tag'] = $userId . ':' . substr(hash('sha256', $newHash), 0, 24);
}

/** Is someone logged in? */
function is_logged_in(): bool
{
    return current_user() !== null;
}

/** Is the current user an admin? */
function is_admin(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'admin';
}

/** Is the current user a qualified (approved) creator? */
function is_qualified(): bool
{
    $u = current_user();
    return $u && $u['role'] === 'creator' && (int)$u['qualified'] === 1;
}

/** Require any logged-in user. */
function require_login(): array
{
    $u = current_user();
    if (!$u) {
        // Remember the page from the link, so signing in opens it (2026-11-11). Only our own pages, only GET.
        $want = (string)($_SERVER['REQUEST_URI'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && preg_match('~^/(creator|admin)/[A-Za-z0-9_./?=&%#-]*$~', $want) && strpos($want, '//') === false) {
            $_SESSION['after_login'] = $want;
        }
        flash('warning', 'Please sign in to continue.');
        redirect('login.php');
    }
    return $u;
}

/** Require a creator account. */
function require_creator(): array
{
    $u = require_login();
    if ($u['role'] !== 'creator') {
        redirect('admin/index.php');
    }
    return $u;
}

/** Require an admin account. */
function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        flash('error', 'Administrator access only.');
        redirect('creator/index.php');
    }
    return $u;
}

/** Has this creator joined at least one campaign? */
function creator_has_joined_campaign(int $userId): bool
{
    return (int)fetch_val('SELECT COUNT(*) FROM campaign_joins WHERE user_id = ?', [$userId], 0) > 0;
}

/** Require a qualified creator (library / submissions). */
function require_qualified(): array
{
    $u = require_creator();
    if ((int)$u['qualified'] !== 1) {
        flash('warning', 'Your account is pending approval. Submit a work sample from your dashboard to unlock this area.');
        redirect('creator/index.php');
    }
    return $u;
}

/**
 * Onboarding step for a creator.
 * Returns 'assessment' (must pass quiz first), 'portfolio' (must submit work),
 * or 'done' (dashboard unlocked).
 */
function onboarding_step(?array $user): string
{
    if (!$user || ($user['role'] ?? '') !== 'creator') {
        return 'done';
    }
    if ((int)($user['qualified'] ?? 0) === 1) {
        return 'done';
    }
    if (($user['assessment_status'] ?? 'not_taken') !== 'passed') {
        return 'assessment';
    }
    if (in_array($user['portfolio_status'] ?? 'none', ['none', 'rejected'], true)) {
        return 'portfolio';
    }
    return 'done';
}

/** Where a creator should go next (onboarding step or dashboard). */
function onboarding_url(?array $user): string
{
    $step = onboarding_step($user);
    if ($step === 'assessment') {
        return 'creator/assessment.php';
    }
    if ($step === 'portfolio') {
        return 'creator/portfolio.php';
    }
    return 'creator/index.php';
}

/** Attempt to log a user in. */
function attempt_login(string $email, string $password): bool
{
    $user = fetch_one('SELECT * FROM users WHERE email = ?', [strtolower(trim($email))]);
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }
    if ($user['status'] === 'suspended') {
        // login.php shows one clear message with a contact number (and why, when the website paused it).
        $GLOBALS['hpl_login_suspended'] = (string)($user['suspend_reason'] ?? '') !== '' ? (string)$user['suspend_reason'] : true;
        return false;
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['role'] = $user['role'];
    return true;
}

/** Log the current user out. */
function logout(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

// Downtime ("maintenance") gate. Loaded here because every page includes auth.php through
// config.php, and this file is deployed (config.php on the server is not). It does nothing
// unless an admin turns downtime on, lets admins and the store through, and never runs on CLI.
require_once __DIR__ . '/maintenance.php';
hpl_maintenance_gate();

// The Brain's speed sensor (2026-11-12): a real page that took over 3 seconds on the server is counted (page only, nobody's
// data). Runs before the background jobs (registered first), so their work is not counted as the page's time.
if (PHP_SAPI !== 'cli') {
    register_shutdown_function(function () {
        $t = microtime(true) - (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        if ($t < 3.0) return;
        $page = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        if ($page === '' || preg_match('~/(api|cron)/|fetch_views~', $page)) return;
        try { require_once __DIR__ . '/brain.php'; brain_count('slow', round($t, 1) >= 10 ? 'over 10 s' : 'over 3 s', $page); } catch (Throwable $e) {}
    });
}
