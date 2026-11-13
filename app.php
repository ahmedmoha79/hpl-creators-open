<?php
/** "Get the app": put HPL Creators on the home screen (iPhone and Android). No app store, no download. */
$pageTitle = 'Get the HPL Creators app';
$metaDesc = 'Put HPL Creators on your phone\'s home screen. It opens like an app, on iPhone and Android.';
require __DIR__ . '/includes/header.php';
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
$isIphone = (bool)preg_match('/iPhone|iPad|iPod/i', $ua);
$iosNotSafari = $isIphone && preg_match('/CriOS|FxiOS|EdgiOS|FBAN|FBAV|Instagram|TikTok|musical_ly/i', $ua);
$inAppBrowser = (bool)preg_match('/FBAN|FBAV|Instagram|TikTok|musical_ly|BytedanceWebview|WhatsApp/i', $ua);
?>
<section class="section">
    <div class="narrow" style="max-width:640px">
        <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
            <img src="<?= e(u('assets/img/app-192.png')) ?>" alt="" width="72" height="72" style="border-radius:18px">
            <div>
                <h1 style="margin:0">HPL Creators app</h1>
                <p class="muted" style="margin:4px 0 0">Put HPL Creators on your home screen. It opens with one tap, full screen, like any app. Nothing to download from an app store.</p>
            </div>
        </div>

        <div class="flash flash--success" id="appAlready" style="display:none">You opened HPL Creators from your home screen. You are all set.</div>

        <?php if ($inAppBrowser): ?>
        <div class="flash flash--warning">
            <strong>First open this page in your phone's browser.</strong> You opened it inside TikTok, Facebook, Instagram or WhatsApp, which cannot add it to the home screen.
            Tap the <strong>&#8942;</strong> or <strong>&#8943;</strong> menu and choose <strong>Open in browser</strong><?= $isIphone ? ' (Safari)' : ' (Chrome)' ?>.
        </div>
        <?php endif; ?>

        <div id="appSteps">
            <div class="card" style="padding:22px;margin-bottom:18px" id="cardAndroid">
                <h2 style="margin-top:0">Android phone</h2>
                <button type="button" class="btn btn--primary btn--block" id="installBtn" style="display:none;font-size:1.05rem;padding:16px"><?= icon('download') ?> Add to home screen</button>
                <p id="installDone" class="flash flash--success" style="display:none;margin:0">Added. Find the HPL Creators icon on your home screen.</p>
                <ol style="margin:14px 0 0;padding-left:20px;line-height:1.7" id="androidManual">
                    <li>Open <strong>golddetectorinzambia.com</strong> in <strong>Chrome</strong>.</li>
                    <li>Tap the menu <strong>&#8942;</strong> (three dots, top right).</li>
                    <li>Tap <strong>Add to Home screen</strong> (or <strong>Install app</strong>), then <strong>Add</strong>.</li>
                </ol>
            </div>

            <div class="card" style="padding:22px" id="cardIphone">
                <h2 style="margin-top:0">iPhone</h2>
                <?php if ($iosNotSafari): ?><p class="flash flash--warning" style="margin:0 0 12px">Use <strong>Safari</strong> for this. Other browsers on iPhone cannot add it.</p><?php endif; ?>
                <ol style="margin:0;padding-left:20px;line-height:1.7">
                    <li>Open <strong>golddetectorinzambia.com</strong> in <strong>Safari</strong>.</li>
                    <li>Tap the <strong>Share</strong> button: the square with an arrow pointing up, at the bottom of the screen.</li>
                    <li>Scroll down and tap <strong>Add to Home Screen</strong>, then <strong>Add</strong>.</li>
                </ol>
            </div>

            <p class="muted small" style="margin-top:16px">Then open HPL Creators from the new icon. You stay signed in, and it works the same as the website: campaigns, ready clips, your views and withdrawals.</p>
        </div>
    </div>
</section>
<script>
(function () {
    var isIphone = <?= $isIphone ? 'true' : 'false' ?>;
    if (window.hplInApp) {
        document.getElementById('appAlready').style.display = 'block';
        document.getElementById('appSteps').style.display = 'none';
        return;
    }
    // Show the phone's own card first.
    var a = document.getElementById('cardAndroid'), i = document.getElementById('cardIphone');
    if (isIphone) { a.parentNode.insertBefore(i, a); i.style.marginBottom = '18px'; a.style.marginBottom = '0'; }
    var btn = document.getElementById('installBtn');
    function ready() { if (window.hplInstall) { btn.style.display = 'flex'; } }
    ready();
    window.addEventListener('beforeinstallprompt', function () { setTimeout(ready, 0); });
    btn.addEventListener('click', function () {
        if (!window.hplInstall) return;
        window.hplInstall.prompt();
        window.hplInstall.userChoice.then(function (r) {
            if (r && r.outcome === 'accepted') { btn.style.display = 'none'; document.getElementById('installDone').style.display = 'block'; }
            window.hplInstall = null;
        });
    });
    window.addEventListener('appinstalled', function () { btn.style.display = 'none'; document.getElementById('installDone').style.display = 'block'; });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
