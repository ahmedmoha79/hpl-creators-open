<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/index.php' : 'creator/index.php');
}

$pageTitle = 'Reset your password';
$error = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email = strtolower(post('email'));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } else {
        $user = fetch_one('SELECT * FROM users WHERE email = ?', [$email]);
        // At most 3 reset emails per address and 10 per network each hour (stops email flooding).
        // The answer is the same either way, so nobody learns which emails have accounts.
        $tkMail = 'reset:email:' . hash('sha256', $email);
        $tkIp = 'reset:ip:' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
        $limited = throttle_blocked($tkMail, 3, 3600) || throttle_blocked($tkIp, 10, 3600);
        if (!$limited) {
            throttle_fail($tkMail, 3600);
            throttle_fail($tkIp, 3600);
        }
        // Always show the same success message (don't leak which emails exist).
        if (!$limited && $user && (string)$user['auth_provider'] !== 'google') {
            $token = bin2hex(random_bytes(32));
            // Only a fingerprint of the link is stored, so a copy of the database cannot be used to reset passwords.
            q('UPDATE users SET password_reset_token = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id = ?', [hash('sha256', $token), (int)$user['id']]);
            $resetUrl = abs_url('reset_password.php?token=' . urlencode($token));
            $html = '<h2 style="color:#f2f5ff">Reset your password</h2>'
                . '<p>Hi ' . e(explode(' ', $user['full_name'])[0]) . ',</p>'
                . '<p>We received a request to reset your password. The link below is valid for <strong>one hour</strong>:</p>'
                . '<p style="text-align:center;margin:20px 0"><a href="' . e($resetUrl) . '" style="display:inline-block;background:#d4af37;color:#071022;padding:12px 22px;border-radius:10px;text-decoration:none;font-weight:700">Reset password</a></p>'
                . '<p>If you didn\'t request this, you can ignore this email.</p>';
            app_mail($email, 'Reset your ' . APP_NAME . ' password', $html);
        }
        $sent = true;
        flash('success', 'If an account exists for that email, a reset link has been sent.');
        redirect('forgot_password.php');
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <aside class="auth-aside">
        <a class="brand mb-3" href="<?= e(u('index.php')) ?>"><span class="brand__mark">HPL</span><span><?= e(APP_NAME) ?></span></a>
        <h2>Forgot your password?</h2>
        <p class="muted">No problem. Enter your email and we'll send you a link to set a new one.</p>
    </aside>
    <div class="auth-main">
        <div class="auth-card">
            <h1>Reset password</h1>
            <p class="muted">Already remember it? <a href="<?= e(u('login.php')) ?>">Back to sign in</a></p>
            <?php if ($error): ?><div class="flash flash--error"><?= e($error) ?></div><?php endif; ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="email">Email address</label>
                    <input class="input" type="email" id="email" name="email" required autofocus>
                </div>
                <button class="btn btn--primary btn--block" type="submit">Send reset link</button>
            </form>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>