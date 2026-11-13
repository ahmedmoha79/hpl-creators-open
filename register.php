<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/signup_guard.php';
require_once __DIR__ . '/includes/signup_source.php';
signup_source_capture();

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/index.php' : 'creator/index.php');
}

$pageTitle = 'Create your account';
$errors = [];
$old = ['full_name' => '', 'email' => '', 'phone' => ''];
// Opened inside Facebook, TikTok, Instagram...: Google does not allow its sign-in there, so its button is hidden.
require_once __DIR__ . '/includes/visits.php';
$inApp = visit_inapp((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    // A failed robot check keeps what the person typed (before 2026-11-12 it reloaded an empty form).
    $guardError = signup_guard_check();
    $old['full_name'] = post('full_name');
    $old['email'] = strtolower(post('email'));
    $old['phone'] = post('phone');
    $password = (string)($_POST['password'] ?? '');

    if ($old['full_name'] === '' || mb_strlen($old['full_name']) < 2) {
        $errors['full_name'] = 'Please enter your full name.';
    } elseif (!name_is_ok($old['full_name'])) {
        $errors['full_name'] = 'Please enter your real name only, with no web links.';
    }
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    } elseif (fetch_one('SELECT id FROM users WHERE email = ?', [$old['email']])) {
        $errors['email'] = 'An account with that email already exists.';
    }
    if (strlen(preg_replace('/\D/', '', $old['phone'])) < 7) {
        $errors['phone'] = 'Please enter a valid phone number.';
    }
    if (empty($errors['phone'])) {
        // One account per phone number: compare the last 9 digits so 097..., +260 97... and 260 97... all match.
        $phoneDigits = preg_replace('/\D/', '', $old['phone']);
        $phoneTail = substr($phoneDigits, -9);
        $dupPhone = fetch_one(
            "SELECT id FROM users WHERE RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), '(', ''), ')', ''), '.', ''), 9) = ? LIMIT 1",
            [$phoneTail]
        );
        if ($dupPhone) {
            $errors['phone'] = 'An account with that phone number already exists. Please sign in instead.';
        }
    }
    if (strlen($password) < 8) {
        $errors['password'] = 'Use at least 8 letters or numbers for your password.';
    }

    require_once __DIR__ . '/includes/towns.php';
    $regTown = zm_town_clean((string)($_POST['town'] ?? ''));
    if (!$errors && !signup_limit('created', signup_creation_limit())) { $errors['security'] = 'Signup limit reached. Try again in an hour or contact support.'; }
    if ($guardError !== null) {
        $errors['security'] = $guardError;
    }
    if ($errors && function_exists('brain_count')) {
        foreach ($errors as $msg) { brain_count('refusal_pub', $msg, '/register.php'); } // the Brain sees which box stops people
    }
    if (!$errors) {
        try {
            q(
                'INSERT INTO users (full_name, email, phone, password, role, status, country, social_handle, town) VALUES (?,?,?,?, "creator", "active", ?, ?, ?)',
                [$old['full_name'], $old['email'], $old['phone'], password_hash($password, PASSWORD_DEFAULT), '', '', $regTown !== '' ? $regTown : null]
            );
        } catch (PDOException $ex) {
            // Unique email index caught a duplicate (e.g. two submissions at once).
            flash('error', 'An account with that email already exists. Please sign in instead.');
            redirect('login.php');
        }
        $newId = (int)db()->lastInsertId();
        signup_source_save($newId); // "how did you hear about us" is asked on Home later
        visit_signed_up($newId);
        require_once __DIR__ . '/includes/bonus.php';
        bonus_grant_signup($newId);
        if (setting('tg_owner_chat_id', '') !== '') {
            try { require_once __DIR__ . '/includes/telegram.php'; tg_alert_signup((int)$newId); } catch (Throwable $e) {}
        }
        session_regenerate_id(true);
        $_SESSION['user_id'] = $newId;
        $_SESSION['role'] = 'creator';
        // Confirm the email address with a 6-digit code.
        require_once __DIR__ . '/includes/email_verify.php';
        $newUser = fetch_one('SELECT * FROM users WHERE id = ?', [$newId]);
        if ($newUser && ($mailErr = email_send_code($newUser)) !== null) {
            flash('warning', $mailErr);
        }
        // Straight to the dashboard: "Your next step" shows what to do. Email is confirmed later, before the first withdrawal.
        flash('success', 'Welcome to HPL Creators! Your first step is below.');
        redirect('creator/index.php');
    }
}

require_once __DIR__ . '/includes/social_proof.php';
$proof = sp_signup_proof(10);
$pageCss = ['assets/css/signup.css?v=20261112c'];
require __DIR__ . '/includes/header.php';
$googleIcon = '<svg viewBox="0 0 48 48" width="18" height="18" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3c-1.6 4.7-6.1 8-11.3 8-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3l5.7-5.7C34.5 6.1 29.6 4 24 4 13 4 4 13 4 24s9 20 20 20 20-9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.9 1.2 8 3l5.7-5.7C34.5 6.1 29.6 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C36.9 39.2 40 34.5 40 29c0-1.3-.1-2.6-.4-3.9z"/></svg>';
?>
<div class="su">
    <header class="su-top">
        <h1>Post our ready clips on TikTok. Get paid for the views.</h1>
        <p>HPL edits the clips. You post them on your own TikTok and get paid for every 1,000 views, to MTN, Airtel or Zamtel. Free to join.</p>
    </header>


    <div class="su-card">
        <h2>Apply to join</h2>
        <?php if ($inApp !== ''): ?>
            <p class="su-note">You opened this inside <?= e($inApp) ?>. Sign up with your email below; it takes 30 seconds.</p>
        <?php else: ?>
            <a data-google class="btn btn--google btn--block" href="<?= e(u('google_auth.php?action=start&intent=signup')) ?>"><?= $googleIcon ?> Sign up with Google</a>
            <div class="auth-divider"><span>or with your email</span></div>
        <?php endif; ?>
        <?php if ($errors): ?><div class="flash flash--error">Please fix the boxes marked below.</div><?php endif; ?>
        <form method="post" novalidate class="su-form">
            <?= csrf_field() ?>
            <?php signup_guard_fields(); ?>
            <?php if (!empty($errors['security'])): ?><div class="flash flash--error"><?= e($errors['security']) ?></div><?php endif; ?>
            <div class="field">
                <label class="label" for="full_name">Your name</label>
                <input class="input" type="text" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" autocomplete="name" required>
                <?php if (!empty($errors['full_name'])): ?><div class="field__error"><?= e($errors['full_name']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="phone">Mobile money number</label>
                <input class="input" type="tel" id="phone" name="phone" value="<?= e($old['phone']) ?>" inputmode="tel" autocomplete="tel" placeholder="097 123 4567" required>
                <?php if (!empty($errors['phone'])): ?><div class="field__error"><?= e($errors['phone']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="email">Email</label>
                <input class="input" type="email" id="email" name="email" value="<?= e($old['email']) ?>" inputmode="email" autocomplete="email" autocapitalize="off" required>
                <?php if (!empty($errors['email'])): ?><div class="field__error"><?= e($errors['email']) ?></div><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="password">Make a password</label>
                <div class="su-pw">
                    <input class="input" type="password" id="password" name="password" autocomplete="new-password" minlength="8" required>
                    <button type="button" class="su-pw__show" data-pw-show aria-controls="password">Show</button>
                </div>
                <?php if (!empty($errors['password'])): ?><div class="field__error"><?= e($errors['password']) ?></div><?php else: ?><small class="muted">At least 8 letters or numbers.</small><?php endif; ?>
            </div>
            <button class="btn btn--primary btn--block su-go" type="submit">Create my account</button>
        </form>
        <p class="su-small">Already have an account? <a href="<?= e(u('login.php')) ?>">Sign in</a></p>
        <p class="su-small muted">By joining you agree to our <a href="<?= e(u('terms.php')) ?>">Terms</a> and <a href="<?= e(u('privacy.php')) ?>">Privacy Policy</a>.</p>
    </div>

    <?php if ($proof): ?>
    <div class="su-toast" id="suToast" role="status" aria-live="polite" hidden>
        <span class="su-toast__face" data-t-face></span>
        <span class="su-toast__txt"><strong data-t-name></strong> <span data-t-text></span> <b data-t-amt></b><small data-t-ago></small></span>
        <button type="button" class="su-toast__x" aria-label="Close" data-t-close>&times;</button>
    </div>
    <script type="application/json" id="suProofData"><?= json_encode(array_map(fn($p) => ['n' => $p['name'], 't' => $p['kind'] === 'paid' ? 'was paid' : 'earned', 'a' => money($p['amount']),
        'g' => ($p['kind'] === 'paid' ? preg_replace('~^was paid ~', '', $p['text']) : 'from clip views') . ' · ' . $p['ago']], $proof), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
    <?php endif; ?>

    <ol class="su-steps" aria-label="What happens next">
        <li><strong>Pass a short test</strong> about how clipping works.</li>
        <li><strong>Connect your TikTok</strong> and add our tag to your bio.</li>
        <li><strong>Take a ready clip</strong> the same day, post it, send us the link.</li>
    </ol>
</div>
<script>
(function () {
    /* Real payouts as a small note at the bottom (owner, 2026-11-12): one every few seconds, never while the person is
       typing (the keyboard would push it over the form), closed for good with the cross. */
    var toast = document.getElementById('suToast'), dataEl = document.getElementById('suProofData');
    if (toast && dataEl) {
        var items = []; try { items = JSON.parse(dataEl.textContent) || []; } catch (e) {}
        var k = 0, shown = 0, typing = false, closed = false, timer = null;
        var q = function (sel) { return toast.querySelector(sel); };
        function hide() { toast.classList.remove('is-on'); setTimeout(function () { if (!toast.classList.contains('is-on')) toast.hidden = true; }, 300); }
        function next() {
            if (closed || !items.length || shown >= 30) return;
            if (typing) { timer = setTimeout(next, 2000); return; }
            var it = items[k++ % items.length]; shown++;
            q('[data-t-face]').textContent = (it.n || '?').charAt(0);
            q('[data-t-name]').textContent = it.n; q('[data-t-text]').textContent = it.t;
            q('[data-t-amt]').textContent = it.a; q('[data-t-ago]').textContent = it.g;
            toast.hidden = false; void toast.offsetWidth; toast.classList.add('is-on');
            setTimeout(hide, 3800);
            timer = setTimeout(next, 5200);
        }
        document.addEventListener('focusin', function (e) { if (e.target.matches('input')) { typing = true; hide(); } });
        document.addEventListener('focusout', function () { typing = false; });
        q('[data-t-close]').addEventListener('click', function () { closed = true; clearTimeout(timer); hide(); });
        timer = setTimeout(next, 900);
    }
    /* Show / hide the password. */
    document.querySelectorAll('[data-pw-show]').forEach(function (b) {
        b.addEventListener('click', function () {
            var f = document.getElementById(b.getAttribute('aria-controls'));
            var show = f.type === 'password';
            f.type = show ? 'text' : 'password';
            b.textContent = show ? 'Hide' : 'Show';
        });
    });
    /* Inside an app the server did not recognise: hide Google too. */
    if (/FBAN|FBAV|FB_IAB|Instagram|musical_ly|BytedanceWebview|TikTok|Snapchat/i.test(navigator.userAgent)) {
        document.querySelectorAll('[data-google]').forEach(function (a) { a.hidden = true; var d = a.nextElementSibling; if (d && d.classList.contains('auth-divider')) d.hidden = true; });
    }
    /* Show instant feedback while Google opens, and block double taps. */
    document.querySelectorAll('[data-google]').forEach(function (a) {
        a.addEventListener('click', function (e) {
            if (a.classList.contains('is-busy')) { e.preventDefault(); return; }
            a.classList.add('is-busy'); a.style.opacity = '.75';
            var t = a.lastChild; if (t && t.nodeType === 3) { t.textContent = ' Opening Google…'; }
        });
    });
    window.addEventListener('pageshow', function () { document.querySelectorAll('[data-google]').forEach(function (a) { a.classList.remove('is-busy'); a.style.opacity = ''; }); });
    /* Stop a double tap creating two requests. */
    var form = document.querySelector('.su-form');
    if (form) form.addEventListener('submit', function () { var b = form.querySelector('.su-go'); setTimeout(function () { b.disabled = true; b.textContent = 'Creating your account…'; }, 0); });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
