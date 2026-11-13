<?php
$pageTitle = 'Privacy Policy';
require __DIR__ . '/includes/header.php';
$contact = setting('payout_contact_email', '') ?: ('support@' . preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com')));
?>
<section class="section">
    <div class="narrow legal">
        <h1>Privacy Policy</h1>
        <p class="muted">Last updated: 27 October 2026</p>

        <p><?= e(APP_NAME) ?> is the creator and clipping program of Hayati Prospectors Limited (HPL Gold Detectors), Lusaka, Zambia ("HPL", "we"). This policy explains what we collect when you use this website, why, and the choices you have.</p>

        <h2>What we collect</h2>
        <ul>
            <li><strong>Account details:</strong> your name, email address, phone number, country and password (stored only as a secure hash). If you sign in with Google we receive your name, email address and Google account ID.</li>
            <li><strong>Social pages and clips:</strong> the profile links you submit, the video links you submit, and their view counts.</li>
            <li><strong>TikTok (only if you choose "Connect TikTok"):</strong> your TikTok open ID, username, display name, profile picture, follower count, and the list and view counts of your public videos. We use the TikTok Login Kit and Display API with the permissions you approve on TikTok's own screen.</li>
            <li><strong>Payout details:</strong> the mobile money number and name you enter when you withdraw, and the history of your withdrawals.</li>
            <li><strong>Activity on the site:</strong> course progress, comments, messages to support and community posts.</li>
        </ul>

        <h2>How we use it</h2>
        <ul>
            <li>To run your account and show your dashboard.</li>
            <li>To confirm that the pages you submit are really yours, and that clips were posted on those pages.</li>
            <li>To count real views and calculate your earnings.</li>
            <li>To send your payouts and payout receipts.</li>
            <li>To send account emails such as verification codes and password resets.</li>
            <li>To prevent fraud, such as one person using many accounts or claiming someone else's page.</li>
        </ul>
        <p>We do not sell your personal data and we do not use it for advertising.</p>

        <h2>TikTok data</h2>
        <p>We only read the TikTok data listed above, only after you connect your account, and only to verify your page and count the views of the clips you submit. We do not post to TikTok for you. You can disconnect TikTok at any time from your dashboard, and you can also remove access in the TikTok app under Settings and privacy, Security, Apps and services. When you disconnect, we delete the stored TikTok access tokens.</p>

        <h2>Who we share it with</h2>
        <ul>
            <li>Mobile money providers (MTN, Airtel, Zamtel), only the details needed to send your payout.</li>
            <li>Google and TikTok, only when you choose to sign in or connect with them.</li>
            <li>Our hosting and email providers, who store and send data on our behalf.</li>
            <li>Authorities, when the law requires it.</li>
        </ul>

        <h2>How long we keep it</h2>
        <p>We keep your account data while your account is open. Payout records are kept for as long as the law requires for financial records. If you close your account we delete or anonymise the rest.</p>

        <h2>Your choices</h2>
        <p>You can update your details on your profile page. To get a copy of your data or to delete your account, email <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a> or message support from your dashboard.</p>

        <h2>Security</h2>
        <p>Passwords are hashed, sessions are protected, and access tokens from TikTok and Google are stored on our server only. No system is perfectly secure, so please use a strong password and never share verification codes.</p>

        <h2>Contact</h2>
        <p>Hayati Prospectors Limited, Lusaka, Zambia. Email: <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a></p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
