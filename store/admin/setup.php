<?php
/** First store admin account. Needs the one-time setup code given to the owner; closes itself after. */
require __DIR__ . '/_boot.php';
if ((int)val('SELECT COUNT(*) FROM shop_admins', [], 0) > 0) redirect('admin/login.php');
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $code = strtoupper(preg_replace('~\s+~', '', post('code')));
    $email = strtolower(post('email'));
    $pw = (string)($_POST['password'] ?? '');
    if (!rate_ok('admin_setup', 6, 3600)) $err = 'Too many attempts. Wait an hour and try again.';
    elseif (!hash_equals((string)cfg('admin_setup_hash', ''), hash('sha256', $code))) $err = 'That setup code is not right.';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'Type a valid email.';
    elseif (mb_strlen(post('name')) < 2) $err = 'Type your name.';
    elseif (strlen($pw) < 10) $err = 'Use a password of at least 10 characters.';
    elseif ($pw !== (string)($_POST['password2'] ?? '')) $err = 'The two passwords are not the same.';
    else {
        q('INSERT INTO shop_admins (email, name, pass_hash) VALUES (?,?,?)', [$email, mb_substr(post('name'), 0, 80), password_hash($pw, PASSWORD_DEFAULT)]);
        $newId = (int)db()->lastInsertId();
        q('DELETE FROM shop_settings WHERE k = "admin_setup_hash"');
        session_regenerate_id(true);
        $_SESSION['shop_admin'] = $newId;
        flash('ok', 'Your admin account is ready. Add your team in Team.');
        redirect('admin/');
    }
}
admin_bare_head('Set up');
?>
<form class="card form a-auth" method="post">
    <img src="<?= e(asset('img/logo-mark.svg')) ?>" width="72" height="72" alt="HPL">
    <h1>Create the owner account</h1>
    <p class="muted small">This is the admin for HPL Gold Detectors only. Use the setup code you were given.</p>
    <?php if ($err): ?><div class="alert alert--err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field"><label for="cd">Setup code</label><input class="input" id="cd" name="code" required autocomplete="off" placeholder="XXXX-XXXX-XXXX" value="<?= e(post('code')) ?>"></div>
    <div class="field"><label for="nm">Your name</label><input class="input" id="nm" name="name" required value="<?= e(post('name')) ?>"></div>
    <div class="field"><label for="em">Email</label><input class="input" id="em" type="email" name="email" required autocomplete="username" value="<?= e(post('email')) ?>"></div>
    <div class="field"><label for="pw">Password (10 characters or more)</label><input class="input" id="pw" type="password" name="password" required minlength="10" autocomplete="new-password"></div>
    <div class="field"><label for="pw2">Password again</label><input class="input" id="pw2" type="password" name="password2" required minlength="10" autocomplete="new-password"></div>
    <button class="btn btn--gold btn--lg btn--block" type="submit">Create account</button>
</form>
</body></html>
