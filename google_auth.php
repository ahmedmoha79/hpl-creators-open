<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/signup_guard.php';
require_once __DIR__ . '/includes/oauth.php';
if (is_logged_in()) { redirect(is_admin() ? 'admin/index.php' : 'creator/index.php'); }
$action = get('action', '');
if ($action === 'start') {
    if (!google_configured()) { flash('warning', 'Google sign-in is unavailable. Please use your email and password.'); redirect('login.php'); }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $_SESSION['google_intent'] = get('intent') === 'signup' ? 'signup' : 'login';
        // No extra security check switched on: go straight to Google's account picker.
        // (Google itself blocks bots, and new accounts are still limited per network in google_login_profile.)
        if (!signup_turnstile_enabled()) {
            $_SESSION['google_signup_checked'] = time();
            redirect(google_auth_url());
        }
    }
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        $error = signup_guard_check();
        if ($error === null) {
            $_SESSION['google_signup_checked'] = time();
            redirect(google_auth_url());
        }
    }
    $pageTitle = 'Continue with Google';
    require __DIR__ . '/includes/header.php';
    ?>
    <main class="container" style="max-width:480px;padding:40px 20px"><div class="auth-card">
        <h1>Continue with Google</h1><p class="muted">One quick security check, then Google opens by itself.</p>
        <?php if ($error): ?><div class="flash flash--error"><?= e($error) ?></div><?php endif; ?>
        <form method="post" id="gStart" action="<?= e(u('google_auth.php?action=start')) ?>"><?= csrf_field() ?><?php signup_guard_fields(); ?><button class="btn btn--primary btn--block" type="submit" id="gStartBtn" disabled>Checking&hellip;</button></form>
    </div></main>
    <script>
    (function () {
        // Submit automatically as soon as the security check passes (and the 3-second minimum is over).
        var t0 = Date.now(), form = document.getElementById('gStart'), btn = document.getElementById('gStartBtn'), sent = false;
        function go() { if (sent) return; var wait = Math.max(0, 3200 - (Date.now() - t0)); setTimeout(function () { sent = true; btn.textContent = 'Opening Google\u2026'; form.submit(); }, wait); }
        var poll = setInterval(function () {
            var tok = form.querySelector('[name="cf-turnstile-response"]');
            if (tok && tok.value) { clearInterval(poll); btn.disabled = false; btn.textContent = 'Opening Google\u2026'; go(); }
        }, 250);
        setTimeout(function () { btn.disabled = false; if (!sent) btn.textContent = 'Continue to Google'; }, 8000);
    })();
    </script>
    <?php require __DIR__ . '/includes/footer.php'; exit;
}
if (isset($_GET['code']) && google_configured()) {
    $checked = (int)($_SESSION['google_signup_checked'] ?? 0);
    unset($_SESSION['google_signup_checked']);
    if (!$checked || time() - $checked > 600 || get('state') === '' || !hash_equals($_SESSION['csrf'] ?? '', get('state'))) {
        flash('error', 'Sign-in expired. Please start again.'); redirect('login.php');
    }
    $profile = google_exchange_code(get('code'));
    if (!$profile) { flash('error', 'Google could not verify your account. Please try again.'); redirect('login.php'); }
    $intent = (string)($_SESSION['google_intent'] ?? 'login');
    unset($_SESSION['google_intent']);
    if ($intent === 'signup') {
        $existing = fetch_one('SELECT id FROM users WHERE email = ?', [strtolower(trim((string)($profile['email'] ?? '')))]);
        if ($existing) {
            flash('error', 'An account with this email already exists. Please sign in instead.');
            redirect('login.php');
        }
    }
    $result = google_login_profile($profile);
    if (is_array($result)) { redirect(is_admin() ? 'admin/index.php' : 'creator/index.php'); }
    flash('error', (string)$result); redirect('login.php');
}
flash('error', 'Please start Google sign-in from the sign-in page.'); redirect('login.php');
