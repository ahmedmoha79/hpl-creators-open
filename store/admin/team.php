<?php
require __DIR__ . '/_boot.php';
$me = require_admin_page();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $a = post('action');
    if ($a === 'add') {
        $email = strtolower(post('email'));
        $pw = (string)($_POST['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen(post('name')) < 2) flash('err', 'Type a name and a valid email.');
        elseif (strlen($pw) < 10) flash('err', 'Give them a password of at least 10 characters. They can change it after signing in.');
        elseif (val('SELECT id FROM shop_admins WHERE email = ?', [$email])) flash('err', 'That email already has an account.');
        else { q('INSERT INTO shop_admins (email, name, pass_hash) VALUES (?,?,?)', [$email, mb_substr(post('name'), 0, 80), password_hash($pw, PASSWORD_DEFAULT)]); flash('ok', 'Team member added.'); }
    } elseif ($a === 'toggle') {
        $id = (int)post('id');
        if ($id === (int)$me['id']) flash('err', 'You cannot switch off your own account.');
        else { q('UPDATE shop_admins SET active = 1 - active WHERE id = ?', [$id]); flash('ok', 'Saved.'); }
    } elseif ($a === 'password') {
        $cur = (string)val('SELECT pass_hash FROM shop_admins WHERE id = ?', [$me['id']], '');
        $pw = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['current'] ?? ''), $cur)) flash('err', 'Your current password is not right.');
        elseif (strlen($pw) < 10) flash('err', 'Use at least 10 characters.');
        else { q('UPDATE shop_admins SET pass_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $me['id']]); flash('ok', 'Password changed.'); }
    }
    redirect('admin/team.php');
}
$team = rows('SELECT id, name, email, active, last_login FROM shop_admins ORDER BY id');
admin_head('Team', 'team.php');
?>
<h1>Team</h1>
<p class="muted">Everyone here can answer chats, update orders and change the website. These accounts are only for HPL Gold Detectors.</p>
<div class="card" style="margin:16px 0">
    <table class="a-table"><thead><tr><th>Name</th><th>Email</th><th>Last sign in</th><th></th></tr></thead><tbody>
    <?php foreach ($team as $t): ?>
        <tr><td><?= e($t['name']) ?><?= (int)$t['id'] === (int)$me['id'] ? ' <span class="muted small">(you)</span>' : '' ?></td><td><?= e($t['email']) ?></td><td><?= $t['last_login'] ? e(date('j M Y H:i', strtotime($t['last_login']))) : '–' ?></td>
        <td><?php if ((int)$t['id'] !== (int)$me['id']): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn--line btn--sm" name="action" value="toggle"><?= (int)$t['active'] ? 'Switch off' : 'Switch on' ?></button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
</div>
<div class="row2">
    <form class="card form" method="post"><?= csrf_field() ?>
        <h2>Add a team member</h2>
        <div class="field"><label>Name</label><input class="input" name="name" required></div>
        <div class="field"><label>Email</label><input class="input" type="email" name="email" required></div>
        <div class="field"><label>First password (they change it later)</label><input class="input" type="text" name="password" required minlength="10" autocomplete="off"></div>
        <button class="btn btn--gold" name="action" value="add">Add</button>
    </form>
    <form class="card form" method="post"><?= csrf_field() ?>
        <h2>Change your password</h2>
        <div class="field"><label>Current password</label><input class="input" type="password" name="current" required autocomplete="current-password"></div>
        <div class="field"><label>New password (10 characters or more)</label><input class="input" type="password" name="new" required minlength="10" autocomplete="new-password"></div>
        <button class="btn btn--dark" name="action" value="password">Change password</button>
    </form>
</div>
<?php admin_foot();
