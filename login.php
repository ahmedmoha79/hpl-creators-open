<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/index.php' : 'creator/index.php');
}

$pageTitle = 'Sign in';
$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = post('email');
    $password = (string)($_POST['password'] ?? '');
    // At most 8 wrong passwords per email and 60 per network address in 15 minutes.
    $tkEmail = 'login:e:' . hash('sha256', strtolower(trim($email)));
    $tkIp = 'login:ip:' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (throttle_blocked($tkEmail, 8, 900) || throttle_blocked($tkIp, 60, 900)) {
        $error = 'Too many wrong tries. Please wait 15 minutes, or use "Forgot your password?" to set a new one.';
    } elseif (attempt_login($email, $password)) {
        throttle_clear($tkEmail);
        $user = current_user();
        flash('success', 'Welcome back, ' . $user['full_name'] . '!');
        $want = (string)($_SESSION['after_login'] ?? '');
        unset($_SESSION['after_login']);
        if ($want !== '' && strpos($want, '/' . ($user['role'] === 'admin' ? 'admin' : 'creator') . '/') === 0) {
            header('Location: ' . $want);
            exit;
        }
        redirect($user['role'] === 'admin' ? 'admin/index.php' : 'creator/index.php');
    }
    if ($error === '') {
        throttle_fail($tkEmail, 900);
        throttle_fail($tkIp, 900);
    }
    $known = $error !== '' ? null : fetch_one('SELECT auth_provider FROM users WHERE email = ?', [strtolower(trim($email))]);
    if ($error !== '') {
        // keep the "too many tries" message
    } elseif (!empty($GLOBALS['hpl_login_suspended'])) {
        $sp = trim((string)setting('support_phone', ''));
        $error = ($GLOBALS['hpl_login_suspended'] === 'inactive'
                ? 'Your account was paused because you did not post clips for a long time, even after two warnings.'
                : ($GLOBALS['hpl_login_suspended'] === 'no_tiktok'
                ? 'Your account is suspended because you did not connect a TikTok account by the date in your last warning.'
                : ($GLOBALS['hpl_login_suspended'] === 'rules'
                ? 'Your account is suspended because your clips broke the rules 3 times in 30 days.'
                : ($GLOBALS['hpl_login_suspended'] === 'no_clip'
                ? 'Your account was paused because you did not send a clip in the 3 days you were given, even after a reminder.'
                : 'Your account is paused by our team, so you cannot sign in right now.'))))
            . ($sp !== '' ? ' Call or WhatsApp us on ' . $sp . ' to talk about it.' : ' Please contact support.');
    } elseif ($known && ($known['auth_provider'] ?? '') === 'google') {
        $error = 'This email was registered with Google. Tap "Continue with Google" below to sign in.';
    } else {
        $error = 'Those credentials do not match our records.';
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <aside class="auth-aside">
        <a class="brand mb-3" href="<?= e(u('index.php')) ?>"><span class="brand__mark">HPL</span><span><?= e(APP_NAME) ?></span></a>
        <h2>Welcome back.</h2>
        <p class="muted">Sign in to track your views, earn per 1,000 views and request withdrawals.</p>
    </aside>
    <div class="auth-main">
        <div class="auth-card">
            <h1>Sign in</h1>
            <p class="muted">New here? <a href="<?= e(u('register.php')) ?>">Create an account</a></p>
            <?php if ($error): ?><div class="flash flash--error"><?= e($error) ?></div><?php elseif (isset($_GET['out'])): ?><div class="flash flash--info">You are signed out.</div><?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="email">Email address</label>
                    <input class="input" type="email" id="email" name="email" value="<?= e($email) ?>" required autofocus>
                </div>
                <div class="field">
                    <label class="label" for="password">Password</label>
                    <input class="input" type="password" id="password" name="password" required>
                </div>
                <button class="btn btn--primary btn--block" type="submit">Sign in</button>
                <p class="auth-switch small" style="text-align:right;margin-top:8px"><a href="<?= e(u('forgot_password.php')) ?>">Forgot your password?</a></p>
            </form>
            <div class="auth-divider"><span>or</span></div>
            <a data-google class="btn btn--google btn--block" href="<?= e(u('google_auth.php?action=start')) ?>"><svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3l5.7-5.7C34.5 6.1 29.6 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3l5.7-5.7C34.5 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C36.9 39.2 40 34.5 40 29c0-1.3-.1-2.6-.4-3.9z"/></svg> Continue with Google</a>
            <?php if (google_sandbox_enabled() && !google_configured()): ?>
                <p class="auth-switch small" style="text-align:center;margin-top:8px"><span class="badge warn">Sandbox mode on</span></p>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
/* Show instant feedback while Google opens, and block double taps. */
document.querySelectorAll('[data-google]').forEach(function (a) {
    a.addEventListener('click', function (e) {
        if (a.classList.contains('is-busy')) { e.preventDefault(); return; }
        a.classList.add('is-busy'); a.setAttribute('aria-busy', 'true'); a.style.opacity = '.75';
        var t = a.lastChild; if (t && t.nodeType === 3) { t.textContent = ' Opening Google\u2026'; }
    });
});
window.addEventListener('pageshow', function () { document.querySelectorAll('[data-google]').forEach(function (a) { a.classList.remove('is-busy'); a.style.opacity = ''; }); });
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
