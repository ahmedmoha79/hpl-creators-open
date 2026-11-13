<?php
require __DIR__ . '/_boot.php';
if ((int)val('SELECT COUNT(*) FROM shop_admins', [], 0) === 0) redirect('admin/setup.php');
$err = '';
$next = (string)($_GET['next'] ?? '/admin/');
if (!preg_match('~^/admin/[A-Za-z0-9_./?=&%-]*$~', $next)) $next = '/admin/';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!rate_ok('admin_login', 8, 900)) {
        $err = 'Too many attempts. Wait 15 minutes and try again.';
    } else {
        $u = row('SELECT id, pass_hash, active FROM shop_admins WHERE email = ? LIMIT 1', [strtolower(post('email'))]);
        if ($u && (int)$u['active'] === 1 && password_verify((string)($_POST['password'] ?? ''), (string)$u['pass_hash'])) {
            session_regenerate_id(true);
            $_SESSION['shop_admin'] = (int)$u['id'];
            q('UPDATE shop_admins SET last_login = NOW() WHERE id = ?', [$u['id']]);
            header('Location: ' . $next);
            exit;
        }
        $err = 'Wrong email or password.';
    }
}
admin_bare_head('Sign in');
?>
<form class="card form a-auth" method="post">
    <img src="<?= e(asset('img/logo-mark.svg')) ?>" width="72" height="72" alt="HPL">
    <h1>Team sign in</h1>
    <?php if ($err): ?><div class="alert alert--err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <div class="field"><label for="em">Email</label><input class="input" id="em" type="email" name="email" required autocomplete="username" value="<?= e(post('email')) ?>"></div>
    <div class="field"><label for="pw">Password</label><input class="input" id="pw" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn--gold btn--lg btn--block" type="submit">Sign in</button>
</form>
</body></html>
