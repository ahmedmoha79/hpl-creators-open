<?php
/** My password (2026-11-11): admins change their own sign-in password here. Other devices are signed out. */
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'My password';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $current = (string)($_POST['current_password'] ?? '');
    $new = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');
    $fresh = fetch_one('SELECT password FROM users WHERE id = ?', [(int)$user['id']]);
    if (!$fresh || !password_verify($current, (string)$fresh['password'])) {
        flash('error', 'Your current password is not right.');
    } elseif (mb_strlen($new) < 12) {
        flash('error', 'Use at least 12 characters. A short sentence works well, for example: Gold sluice in Mumbwa 2026!');
    } elseif ($new !== $confirm) {
        flash('error', 'The two new passwords are not the same.');
    } elseif (password_verify($new, (string)$fresh['password'])) {
        flash('error', 'That is the password you have now. Choose a new one.');
    } else {
        $newHash = password_hash($new, PASSWORD_DEFAULT);
        q('UPDATE users SET password = ? WHERE id = ?', [$newHash, (int)$user['id']]);
        session_keep_after_password_change((int)$user['id'], $newHash);
        flash('success', 'Password changed. Any other phone or computer that was signed in to this admin account is now signed out.');
    }
    redirect('admin/account.php');
}

require __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-head">
    <h1>My password</h1>
    <p class="muted">Signed in as <?= e($user['email']) ?>. This password opens the whole admin side, so make it long and use it nowhere else.</p>
</div>

<div class="panel" style="max-width:520px">
    <div class="panel__body">
        <form method="post">
            <?= csrf_field() ?>
            <div class="field">
                <label class="label" for="current_password">Current password</label>
                <input class="input" type="password" id="current_password" name="current_password" autocomplete="current-password" required>
            </div>
            <div class="field">
                <label class="label" for="new_password">New password</label>
                <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="12" required>
                <div class="field__hint">At least 12 characters. A short sentence is easy to remember and hard to guess.</div>
            </div>
            <div class="field">
                <label class="label" for="confirm_password">New password again</label>
                <input class="input" type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="12" required>
            </div>
            <button class="btn btn--primary" type="submit">Change password</button>
        </form>
    </div>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
