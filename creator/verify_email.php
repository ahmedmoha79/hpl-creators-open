<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/email_verify.php';
$user = require_creator();

if (email_is_verified($user)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        redirect('creator/index.php');
    }
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'resend') {
        $error = email_send_code($user);
        if ($error === null) {
            flash('success', 'We sent a new code to ' . $user['email'] . '.');
            redirect('creator/verify_email.php');
        }
    } else {
        $error = email_check_code($user, post('code'));
        if ($error === null) {
            flash('success', 'Your email is confirmed. Thank you!');
            redirect('creator/index.php');
        }
    }
    $user = fetch_one('SELECT * FROM users WHERE id = ?', [(int)$user['id']]) ?: $user;
}

$verified = email_is_verified($user);
$wait = email_code_wait($user);
$neverSent = empty($user['email_code_sent_at']);
$pageTitle = 'Confirm your email';
require __DIR__ . '/../includes/creator_header.php';
?>
<div class="ui-narrow">
    <div class="ui-card ui-card--center">
        <span class="ui-iconbubble"><?= icon($verified ? 'mail-check' : 'mail', 'ico ico--lg') ?></span>
        <?php if ($verified): ?>
            <h1 class="ui-title">Your email is confirmed</h1>
            <p class="ui-sub"><?= e($user['email']) ?> is verified<?= ($user['auth_provider'] ?? '') === 'google' || !empty($user['google_id']) ? ' through your Google account' : '' ?>.</p>
            <a class="btn btn--primary" href="<?= e(u('creator/index.php')) ?>">Go to your dashboard</a>
        <?php else: ?>
            <h1 class="ui-title">Confirm your email</h1>
            <p class="ui-sub">
                <?php if ($neverSent): ?>
                    We need to confirm that <strong><?= e($user['email']) ?></strong> is yours. Tap "Send code" and we will email you a 6-digit code.
                <?php else: ?>
                    We sent a 6-digit code to <strong><?= e($user['email']) ?></strong>. Enter it below. Check your spam or promotions folder if you do not see it.
                <?php endif; ?>
            </p>
            <?php if ($error): ?><div class="ui-alert ui-alert--error"><?= icon('alert') ?><span><?= e($error) ?></span></div><?php endif; ?>

            <?php if (!$neverSent): ?>
            <form method="post" class="ui-otp" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="verify">
                <label class="sr-only" for="code">6-digit code</label>
                <input class="ui-otp__input" id="code" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" placeholder="000000" autocomplete="one-time-code" required autofocus>
                <button class="btn btn--primary btn--block" type="submit">Confirm email</button>
            </form>
            <div role="note" style="margin:14px 0 4px;padding:12px 14px;border-radius:12px;background:var(--ui-warn-soft);border:1px solid var(--ui-line-strong);font-size:.9rem;line-height:1.5;color:var(--ui-text-2);text-align:left">
                <strong>&#128233; Can't find the code?</strong> Look in your <strong>Spam</strong> or <strong>Promotions</strong> folder. If it's there, open it and tap <strong>"Not spam"</strong> so our emails reach your inbox next time.
            </div>
            <?php endif; ?>

            <form method="post" class="ui-otp__resend">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend">
                <?php if ($neverSent): ?>
                    <button class="btn btn--primary btn--block" type="submit">Send code</button>
                <?php else: ?>
                    <button class="ui-linkbtn" type="submit" data-wait="<?= (int)$wait ?>" <?= $wait > 0 ? 'disabled' : '' ?>>Send a new code<?= $wait > 0 ? ' in <span>' . (int)$wait . '</span>s' : '' ?></button>
                <?php endif; ?>
            </form>
            <p class="ui-foot">Wrong email address? <a href="<?= e(u('creator/support.php')) ?>">Contact support</a> and we will fix it.</p>
            <p class="ui-foot"><a href="<?= e(u('creator/index.php')) ?>">Do this later</a> &middot; you only need it before your first withdrawal.</p>
        <?php endif; ?>
    </div>
    <p class="ui-note"><?= icon('info') ?> Needed to withdraw. We send your receipts there.</p>
</div>
<script>
(function(){
    var b = document.querySelector('.ui-linkbtn[data-wait]'); if (!b) return;
    var s = parseInt(b.dataset.wait, 10) || 0; if (s <= 0) return;
    var t = setInterval(function(){ s--; var sp = b.querySelector('span'); if (sp) sp.textContent = s;
        if (s <= 0) { clearInterval(t); b.disabled = false; b.textContent = 'Send a new code'; } }, 1000);
})();
</script>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
