<?php
require_once __DIR__ . '/config.php';

if (is_logged_in()) {
    redirect(is_admin() ? 'admin/index.php' : 'creator/index.php');
}

$pageTitle = 'Set a new password';
$error = '';
$done = false;
$token = (string)get('token', '');

if ($token === '') {
    $error = 'This reset link is invalid. Please request a new one.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $token = (string)($_POST['token'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $confirm = (string)($_POST['password_confirm'] ?? '');

    if (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $user = $token === '' ? null : fetch_one('SELECT * FROM users WHERE password_reset_token = ? AND password_reset_expires > NOW()', [hash('sha256', $token)]);
        if (!$user) {
            $error = 'This reset link is invalid or has expired. Please request a new one.';
        } else {
            q(
                'UPDATE users SET password = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?',
                [password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]
            );
            $done = true;
            flash('success', 'Your password has been updated. Sign in with your new password.');
            redirect('login.php');
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
    <aside class="auth-aside">
        <a class="brand mb-3" href="<?= e(u('index.php')) ?>"><span class="brand__mark">HPL</span><span><?= e(APP_NAME) ?></span></a>
        <h2>Set a new password</h2>
        <p class="muted">Choose a strong password you haven't used before.</p>
    </aside>
    <div class="auth-main">
        <div class="auth-card">
            <h1><?= $done ? 'Password updated' : 'New password' ?></h1>
            <?php if ($error): ?>
                <div class="flash flash--error"><?= e($error) ?></div>
                <p class="auth-switch small"><a href="<?= e(u('forgot_password.php')) ?>">Request a new reset link &rarr;</a></p>
            <?php else: ?>
                <form method="post">
                    <?= csrf_field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <div class="field">
                        <label class="label" for="password">New password</label>
                        <input class="input" type="password" id="password" name="password" minlength="8" required autofocus>
                    </div>
                    <div class="field">
                        <label class="label" for="password_confirm">Confirm new password</label>
                        <input class="input" type="password" id="password_confirm" name="password_confirm" minlength="8" required>
                    </div>
                    <button class="btn btn--primary btn--block" type="submit">Update password</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>