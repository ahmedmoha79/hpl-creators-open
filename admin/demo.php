<?php
/**
 * Admin > Demo account: open the demo creator (includes/demo.php) to film screen recordings and tutorials.
 * ?leave=1 is the way back from the demo creator, so it runs before require_admin().
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/demo.php';

if (isset($_GET['leave'])) {
    if (demo_leave()) {
        flash('success', 'You are back in the admin account.');
        redirect('admin/demo.php');
    }
    redirect('login.php');
}

$user = require_admin();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'open') {
        if (demo_enter($user)) {
            redirect('creator/index.php');
        }
        flash('error', 'The demo account could not be opened.');
    } elseif ($action === 'reset') {
        [$freed, $removed] = demo_reset();
        flash('success', 'Demo account reset: ' . $freed . ' ready clip' . ($freed === 1 ? '' : 's') . ' given back, ' . $removed . ' clip link' . ($removed === 1 ? '' : 's') . ' removed.');
    } elseif ($action === 'password') {
        // The owner sets a sign-in password for filming the sign-in screen. The demo never earns,
        // so a known password cannot move money; whoever knows it can still write in the chat as the demo.
        $pw = (string)($_POST['password'] ?? '');
        $d = demo_user();
        if (!$d) {
            flash('error', 'The demo account could not be found.');
        } elseif (mb_strlen($pw) < 8) {
            flash('error', 'Use at least 8 characters.');
        } else {
            q('UPDATE users SET password = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), (int)$d['id']]);
            flash('success', 'Password saved. Sign in with ' . DEMO_EMAIL . ' and the password you just typed.');
        }
    } elseif ($action === 'rename') {
        $name = mb_substr(trim(post('name')), 0, 80);
        $d = demo_user();
        if ($d && $name !== '') {
            q('UPDATE users SET full_name = ? WHERE id = ?', [$name, (int)$d['id']]);
            flash('success', 'The demo creator is now called ' . $name . '.');
        }
    }
    redirect('admin/demo.php');
}

$d = demo_user();
$tt = $d ? fetch_one('SELECT * FROM tiktok_accounts WHERE user_id = ? ORDER BY id DESC LIMIT 1', [(int)$d['id']]) : null;
$held = $d ? (int)fetch_val('SELECT COUNT(*) FROM ready_clips WHERE claimed_by = ?', [(int)$d['id']], 0) : 0;
$links = $d ? (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ?', [(int)$d['id']], 0) : 0;
$pageTitle = 'Demo account';
require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Demo account</h1>
<p class="muted">A ready-made creator for screen recordings and tutorials, so you never have to film a real creator's account. It has passed the test and its email is confirmed.</p>

<div class="panel">
    <div class="panel__head"><h3><?= e($d['full_name'] ?? 'Demo creator') ?></h3><span class="badge ok">Ready</span></div>
    <div class="panel__body">
        <ul class="small" style="margin:0 0 14px;padding-left:18px;display:grid;gap:4px">
            <li>TikTok: <?= $tt ? 'connected as <strong>@' . e((string)($tt['username'] ?? $tt['display_name'] ?? '')) . '</strong>' : 'not connected yet. Open the account, go to Profile and tap Connect TikTok, using a TikTok account you made for demos.' ?></li>
            <li>Ready clips it is holding: <?= $held ?>. Clip links it has sent: <?= $links ?>.</li>
        </ul>
        <form method="post" class="flex" style="gap:10px;flex-wrap:wrap">
            <?= csrf_field() ?><input type="hidden" name="action" value="open">
            <button class="btn btn--primary" type="submit">Open the demo account</button>
        </form>
        <p class="small muted" style="margin-top:10px">To come back, open the menu (your picture at the top right) and tap <strong>Back to admin</strong>.</p>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>Sign in like a creator (for filming)</h3></div>
    <div class="panel__body">
        <p class="small" style="margin-top:0">Email: <strong><?= e($d['email'] ?? DEMO_EMAIL) ?></strong>. Choose a password below, then sign out of the admin (or use another browser or your phone) and sign in on the normal sign-in page with this email and that password.</p>
        <form method="post" class="flex" style="gap:8px;align-items:flex-end;flex-wrap:wrap" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="password">
            <label class="field" style="margin:0">New password for the demo<input class="input" type="password" name="password" minlength="8" autocomplete="new-password" required></label>
            <button class="btn btn--primary" type="submit">Save password</button></form>
        <p class="small muted" style="margin-bottom:0">Anyone who knows this password can sign in as the demo and write in the chat groups, but cannot earn or withdraw anything. Change it after filming.</p>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>What is different from a real creator</h3></div>
    <div class="panel__body">
        <ul class="small" style="margin:0;padding-left:18px;display:grid;gap:4px">
            <li>It never earns money: its clips record views but pay nothing and use none of a campaign's budget.</li>
            <li>It cannot withdraw or receive a bonus, and is never warned or paused for being inactive.</li>
            <li>Taking a ready clip with it takes that clip from the free list. Use <strong>Reset</strong> after filming to give clips back.</li>
            <li>Anything it writes in the chat groups is seen by every creator.</li>
        </ul>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>After filming</h3></div>
    <div class="panel__body flex" style="gap:14px;flex-wrap:wrap;align-items:flex-end">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><button class="btn btn--ghost" type="submit" data-confirm="Give the demo's ready clips back and remove its clip links?">Reset the demo account</button></form>
        <form method="post" class="flex" style="gap:8px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="rename">
            <label class="field" style="margin:0">Name shown on screen<input class="input" name="name" value="<?= e($d['full_name'] ?? '') ?>" maxlength="80"></label>
            <button class="btn btn--ghost" type="submit">Save name</button></form>
    </div>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
