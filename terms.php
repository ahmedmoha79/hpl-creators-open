<?php
$pageTitle = 'Terms of Use';
require __DIR__ . '/includes/header.php';
$contact = setting('payout_contact_email', '') ?: ('support@' . preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com')));
$rate = (float)setting('rate_per_1000', 10);
$min = payout_min();
?>
<section class="section">
    <div class="narrow legal">
        <h1>Terms of Use</h1>
        <p class="muted">Last updated: 27 October 2026</p>

        <p>These terms apply to <?= e(APP_NAME) ?>, the creator program of Hayati Prospectors Limited (HPL Gold Detectors), Lusaka, Zambia. By creating an account you agree to them.</p>

        <h2>1. Who can join</h2>
        <p>You must be at least 18 years old, give true account details and keep only one account. Accounts may be approved after a short quiz and a work sample.</p>

        <h2>2. Your pages</h2>
        <p>You may only submit social media pages that you own and control. We verify pages with TikTok sign-in or a code in the page bio. A page can belong to only one account. Claiming someone else's page ends your account.</p>

        <h2>3. Clips and footage</h2>
        <ul>
            <li>HPL footage from the library may be used only to make clips that promote HPL, posted on your verified pages.</li>
            <li>Clips must be honest. Do not make false claims about HPL products, prices or results.</li>
            <li>Follow the rules of each platform and the law. Do not buy views, use bots or post the same clip on pages you do not own.</li>
        </ul>

        <h2>4. Earnings</h2>
        <ul>
            <li>You earn the rate shown on each campaign for every 1,000 real views on approved clips. Rates can change for future views; we will tell you before they do.</li>
            <li>Only real views count. Views we cannot confirm, or views from fake or bought traffic, are not paid, and earnings from them can be removed.</li>
            <li>You can withdraw once your balance reaches <?= e(money($min)) ?>. You need a confirmed email and at least one verified page.</li>
            <li>Payouts are sent to the mobile money number you give. Check it carefully; we cannot recover money sent to a wrong number you entered.</li>
        </ul>

        <h2>5. Ending your account</h2>
        <p>You can close your account at any time. We may suspend or close accounts that break these terms. Approved earnings from real views that you earned before closing will still be paid, unless they came from fraud.</p>

        <h2>6. Changes</h2>
        <p>We may update these terms. If the change is important we will tell you on your dashboard or by email.</p>

        <h2>7. Contact</h2>
        <p>Questions: <a href="mailto:<?= e($contact) ?>"><?= e($contact) ?></a>. See also our <a href="<?= e(u('privacy.php')) ?>">Privacy Policy</a>.</p>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
