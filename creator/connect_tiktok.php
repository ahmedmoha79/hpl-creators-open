<?php
/**
 * The one place to connect TikTok (2026-11-06, owner: "it just has to be flawless").
 * Every "Connect TikTok" button on the site leads here, and TikTok always sends people back here,
 * so they see one clear result with one button: never the busy accounts section of the Home page.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/tiktok.php';
$user = require_creator();
$pageTitle = 'Connect your TikTok';
$hideAppBar = true; // keep this page to one job

// What just happened on TikTok (set by tiktok_auth.php), shown once.
$result = $_SESSION['tt_result'] ?? null;
unset($_SESSION['tt_result']);
if (!is_array($result) || time() - (int)($result['at'] ?? 0) > 600) {
    $result = null;
}

$on = tiktok_configured();
$status = $on ? tiktok_creator_status((int)$user['id']) : ['accounts' => [], 'clips' => 0, 'unreadable' => 0, 'needs' => [], 'state' => 'none'];
$working = [];
$broken = [];
foreach ($status['accounts'] as $x) {
    if ($x['problem'] === null) {
        $working[] = $x['row'];
    } else {
        $broken[] = $x['row'];
    }
}
$allGood = $working && !$broken && (int)$status['unreadable'] === 0;
$another = isset($_GET['another']);

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$inAppBrowser = (bool)preg_match('/FBAN|FBAV|Instagram|musical_ly|BytedanceWebview|WhatsApp/i', $ua);
$isIphone = (bool)preg_match('/iPhone|iPad|iPod/i', $ua);

require __DIR__ . '/../includes/creator_header.php';

/** The big black button that opens TikTok. */
function tt_connect_button(string $label = 'Connect my TikTok', bool $another = false): void
{
    ?>
    <form method="post" action="<?= e(u('tiktok_auth.php')) ?>" class="ttc-go" onsubmit="var b=this.querySelector('button');b.disabled=true;b.lastChild.textContent=' Opening TikTok...';">
        <?= csrf_field() ?><input type="hidden" name="action" value="connect"><?php if ($another): ?><input type="hidden" name="another" value="1"><?php endif; ?>
        <button class="btn btn--tiktok btn--block" type="submit"><?= icon('tiktok') ?><span> <?= e($label) ?></span></button>
    </form>
    <?php
}
?>
<style>
.ttc { max-width: 520px; margin: 0 auto; }
.ttc h1 { margin: 0 0 6px; }
.ttc-lead { color: #5c574e; margin: 0 0 20px; font-size: 1.02rem; }
.ttc-go .btn { font-size: 1.12rem; padding: 17px 18px; border-radius: 14px; gap: 10px; }
.ttc-go .btn[disabled] { opacity: .75; }
.ttc-result { text-align: center; padding: 30px 22px 24px; border-radius: 18px; background: #fff; border: 1px solid #e7e1d6; margin-bottom: 18px; }
.ttc-result__mark { width: 64px; height: 64px; border-radius: 50%; display: grid; place-items: center; margin: 0 auto 14px; font-size: 34px; line-height: 1; color: #fff; }
.ttc-result--ok .ttc-result__mark { background: #1f9d55; }
.ttc-result--retry .ttc-result__mark, .ttc-result--videos .ttc-result__mark, .ttc-result--same .ttc-result__mark { background: #d98a00; }
.ttc-result--error .ttc-result__mark { background: #b42318; }
.ttc-result h2 { margin: 0 0 6px; font-size: 1.4rem; }
.ttc-result p { margin: 0 auto 20px; color: #4a463f; max-width: 380px; }
.ttc-result .btn { margin-top: 10px; }
.ttc-steps { list-style: none; margin: 0 0 20px; padding: 0; }
.ttc-steps li { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; }
.ttc-steps li + li { border-top: 1px solid #efeae0; }
.ttc-steps b.n { flex: 0 0 28px; height: 28px; border-radius: 50%; background: #111; color: #fff; display: grid; place-items: center; font-size: .9rem; }
.ttc-steps span { padding-top: 3px; line-height: 1.45; }
/* A simple drawing of TikTok's screen, so people know what to tap when they get there. */
.ttc-peek { border: 1px solid #e7e1d6; border-radius: 16px; background: #faf8f4; padding: 16px; margin: 0 0 20px; }
.ttc-peek__cap { font-size: .9rem; color: #5c574e; margin: 0 0 10px; }
.ttc-phone { background: #fff; border-radius: 14px; border: 1px solid #ddd6c9; padding: 16px 16px 14px; max-width: 290px; margin: 0 auto; font-size: .88rem; color: #222; }
.ttc-phone__title { font-weight: 700; text-align: center; margin-bottom: 10px; }
.ttc-phone__acct { display: flex; align-items: center; gap: 8px; background: #f3f3f3; border-radius: 10px; padding: 8px 10px; margin-bottom: 10px; }
.ttc-phone__acct i { width: 26px; height: 26px; border-radius: 50%; background: #cfcfcf; display: block; }
.ttc-phone ul { list-style: none; margin: 0 0 12px; padding: 0; }
.ttc-phone li { display: flex; gap: 8px; align-items: center; padding: 3px 0; }
.ttc-phone li::before { content: "\2713"; width: 16px; height: 16px; border-radius: 4px; background: #111; color: #fff; font-size: 11px; display: grid; place-items: center; flex: 0 0 16px; }
.ttc-phone__btn { position: relative; background: #fe2c55; color: #fff; text-align: center; font-weight: 700; border-radius: 6px; padding: 9px; }
.ttc-phone__btn::after { content: ""; position: absolute; inset: -5px; border: 2px dashed #1f9d55; border-radius: 9px; }
.ttc-phone__tap { text-align: center; color: #1f7a45; font-weight: 700; margin-top: 8px; }
.ttc-help { font-size: .92rem; color: #5c574e; margin-top: 16px; }
.ttc-help summary { cursor: pointer; font-weight: 600; color: #222; }
.ttc-help ul { margin: 8px 0 0 18px; padding: 0; line-height: 1.6; }
.ttc-acct { display: flex; align-items: center; gap: 10px; justify-content: center; margin: 0 0 6px; font-weight: 700; font-size: 1.1rem; }
.ttc-acct img { width: 36px; height: 36px; border-radius: 50%; object-fit: cover; background: #eee; }
</style>

<div class="ttc">
<?php if (!$on): ?>
    <h1>Connect your TikTok</h1>
    <div class="flash flash--info">Connecting TikTok is switched off for now. Verify your page with the bio code on your <a href="<?= e(u('creator/profile.php#socialPage')) ?>">Home page</a> instead.</div>

<?php elseif ($result !== null): ?>
    <?php $k = (string)$result['kind']; ?>
    <div class="ttc-result ttc-result--<?= e($k) ?>">
        <?php if ($k === 'ok'): ?>
            <div class="ttc-result__mark">&#10003;</div>
            <h2>TikTok connected</h2>
            <?php $acc = null; foreach ($working as $w) { if ('@' . $w['username'] === $result['name']) { $acc = $w; } } ?>
            <div class="ttc-acct"><?php if ($acc && !empty($acc['avatar_url'])): ?><img src="<?= e($acc['avatar_url']) ?>" alt="" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?><?= e($result['name']) ?></div>
            <p><?= e($result['msg']) ?></p>
            <a class="btn btn--primary btn--block" href="<?= e(u('creator/tiktok_ready.php?recheck=1')) ?>">Next: get your TikTok ready</a>
            <a class="btn btn--ghost btn--block" href="<?= e(u('creator/index.php')) ?>">Back to Home</a>
        <?php elseif ($k === 'same'): ?>
            <div class="ttc-result__mark">&#8635;</div>
            <h2>That is the same account</h2>
            <div class="ttc-acct"><?= e($result['name']) ?></div>
            <p><?= e($result['msg']) ?></p>
            <ol class="ttc-steps" style="text-align:left">
                <li><b class="n">1</b><span>Open the <strong>TikTok app</strong> and go to <strong>Profile</strong>.</span></li>
                <li><b class="n">2</b><span>Tap your name at the top and choose the other account (or <strong>Add account</strong> and log in to it).</span></li>
                <li><b class="n">3</b><span>Come back here and tap the button below. Check that TikTok shows the other account before you tap Continue.</span></li>
            </ol>
            <?php tt_connect_button('Connect the other account', true); ?>
            <a class="btn btn--ghost btn--block" href="<?= e(u('creator/index.php')) ?>">Back to Home</a>
        <?php elseif ($k === 'error'): ?>
            <div class="ttc-result__mark">!</div>
            <h2>TikTok did not connect</h2>
            <p><?= e($result['msg']) ?></p>
            <?php tt_connect_button('Try again'); ?>
            <a class="btn btn--ghost btn--block" href="<?= e(u('creator/index.php')) ?>">Back to Home</a>
        <?php else: ?>
            <div class="ttc-result__mark">&#8635;</div>
            <h2><?= $k === 'videos' ? 'Almost done' : 'Not connected yet' ?></h2>
            <p><?= e($result['msg']) ?></p>
            <?php tt_connect_button('Try again'); ?>
        <?php endif; ?>
    </div>
    <?php if ($k !== 'ok'): ?>
        <p class="ttc-help">Still not working? Take a screenshot of what you see and send it to us in <a href="<?= e(u('creator/messages.php')) ?>">Messages</a>.</p>
    <?php endif; ?>

<?php elseif ($allGood && !$another): ?>
    <div class="ttc-result ttc-result--ok">
        <div class="ttc-result__mark">&#10003;</div>
        <h2>Your TikTok is connected</h2>
        <div class="ttc-acct"><?= e(implode(', ', array_map(fn($a) => '@' . $a['username'], $working))) ?></div>
        <p>Your views update by themselves.</p>
        <a class="btn btn--primary btn--block" href="<?= e(u('creator/tiktok_ready.php')) ?>">Get your TikTok ready for ready clips</a>
        <a class="btn btn--ghost btn--block" href="<?= e(u('creator/index.php')) ?>">Back to Home</a>
    </div>
    <p class="ttc-help" style="text-align:center">Post HPL clips on another TikTok account too? <a href="<?= e(u('creator/connect_tiktok.php?another=1')) ?>">Connect that one</a></p>

<?php else: ?>
    <h1><?= $broken ? 'Connect your TikTok again' : ($another ? 'Connect another TikTok' : 'Connect your TikTok') ?></h1>
    <p class="ttc-lead"><?= $broken
        ? 'TikTok stopped sharing your views with us. One tap fixes it, so your clips keep earning.'
        : 'So we can count the views on your clips and pay you. It takes one minute, and you only do it once.' ?></p>

    <?php if ($inAppBrowser): ?>
        <div class="flash flash--warning"><strong>Open this page in <?= $isIphone ? 'Safari' : 'Chrome' ?> first.</strong> TikTok cannot connect inside WhatsApp, Facebook or TikTok. Tap the <strong>&#8942;</strong> or <strong>&#8943;</strong> menu and choose <strong>Open in browser</strong>.</div>
    <?php endif; ?>

    <?php if ($another && !$broken): ?>
        <div class="flash flash--info" style="margin-bottom:14px"><strong>First switch account in the TikTok app.</strong> TikTok connects whichever account is open on this phone. Open TikTok, go to Profile, tap your name at the top and pick the other account. Then come back and tap the button.</div>
    <?php endif; ?>
    <?php tt_connect_button($broken ? 'Reconnect my TikTok' : ($another ? 'Connect the other account' : 'Connect my TikTok'), $another && !$broken); ?>

    <h2 style="font-size:1.1rem;margin:26px 0 4px">What happens next</h2>
    <ol class="ttc-steps">
        <li><b class="n">1</b><span>TikTok opens. If it asks you to log in, log in with the account you post HPL clips on.</span></li>
        <li><b class="n">2</b><span>Leave every box ticked and tap the red <strong>Continue</strong> (or <strong>Authorize</strong>) button.</span></li>
        <li><b class="n">3</b><span>You come straight back here and see <strong>TikTok connected</strong>. Done.</span></li>
    </ol>

    <div class="ttc-peek" aria-hidden="true">
        <p class="ttc-peek__cap">On TikTok you will see something like this:</p>
        <div class="ttc-phone">
            <div class="ttc-phone__title">HPL Creators would like to</div>
            <div class="ttc-phone__acct"><i></i><span>Your TikTok account</span></div>
            <ul><li>Read your profile info</li><li>Read your public videos</li></ul>
            <div class="ttc-phone__btn">Continue</div>
            <div class="ttc-phone__tap">Tap this</div>
        </div>
    </div>

    <details class="ttc-help">
        <summary>Wrong account showing on TikTok?</summary>
        <ul>
            <li>TikTok connects the account that is open in the TikTok app (or logged in on this browser).</li>
            <li>Tap the <strong>X</strong> on TikTok's screen, open the TikTok app, go to Profile, tap your name at the top and switch to the right account. Then come back and tap the button again.</li>
            <li>Still stuck? Send a screenshot in <a href="<?= e(u('creator/messages.php')) ?>">Messages</a>.</li>
        </ul>
    </details>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
