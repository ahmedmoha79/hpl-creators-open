<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Support';

$supportEmail = setting('payout_contact_email', '');
$supportPhone = setting('support_phone', '');

require __DIR__ . '/../includes/admin_header.php';
?>

<h1>Support</h1>

<div class="grid-2 mb-3">
    <div class="card">
        <h3>Contact us</h3>
        <p class="small muted">Reach the platform team any time.</p>
        <ul style="margin:12px 0 0;padding-left:0;list-style:none;display:flex;flex-direction:column;gap:8px">
            <?php if ($supportEmail): ?><li>&#9993; <a href="mailto:<?= e($supportEmail) ?>"><?= e($supportEmail) ?></a></li><?php endif; ?>
            <?php if ($supportPhone): ?><li>&#128222; <a href="tel:<?= e(preg_replace('/\s+/', '', $supportPhone)) ?>"><?= e($supportPhone) ?></a></li><?php endif; ?>
            <?php if (!$supportEmail && !$supportPhone): ?><li class="muted">Add a support email/phone under <a href="<?= e(u('admin/settings.php')) ?>">Settings</a>.</li><?php endif; ?>
            <li>&#128172; <a href="<?= e(u('admin/messages.php')) ?>">Message the team in DMs</a></li>
            <li>&#128221; <a href="<?= e(u('admin/feedback.php')) ?>">Read creator feedback</a></li>
        </ul>
    </div>
    <div class="card">
        <h3>Platform status</h3>
        <p class="small muted">Current system health.</p>
        <?php
        $checks = [];
        $dbOk = true;
        try { db()->query('SELECT 1'); } catch (Throwable $e) { $dbOk = false; }
        $checks[] = ['Database', $dbOk];
        $checks[] = ['View sync cron', setting('cron_key', '') !== ''];
        $checks[] = ['Email sending', setting('smtp_host', '') !== '' ? 'SMTP configured' : 'mail() fallback'];
        $checks[] = ['IMAP inbox', imap_settings_configured() ? 'configured' : 'not configured'];
        ?>
        <ul style="margin:12px 0 0;padding:0;list-style:none;display:flex;flex-direction:column;gap:8px">
            <?php foreach ($checks as $c): ?>
                <li style="display:flex;justify-content:space-between">
                    <span><?= e($c[0]) ?></span>
                    <span class="badge <?= is_bool($c[1]) ? ($c[1] ? 'ok' : 'bad') : 'muted' ?>"><?= is_bool($c[1]) ? ($c[1] ? 'OK' : 'Offline') : e($c[1]) ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>How things work</h3></div>
    <div class="panel__body">
        <div class="grid-2">
            <ol style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:8px">
                <li><strong>Creators sign up</strong> and pass the assessment, then submit work.</li>
                <li><strong>You review</strong> submissions in <a href="<?= e(u('admin/portfolio.php')) ?>">Work review</a>.</li>
                <li><strong>Views are fetched</strong> automatically by the sync cron and credited to earnings.</li>
            </ol>
            <ol start="4" style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:8px">
                <li><strong>Withdrawals</strong> appear in <a href="<?= e(u('admin/withdrawals.php')) ?>">Withdrawals</a> for you to pay (provider + txn reference).</li>
                <li><strong>Email</strong> (reset links, replies) is handled under <a href="<?= e(u('admin/settings.php')) ?>">Settings</a> and read in <a href="<?= e(u('admin/email.php')) ?>">Email</a>.</li>
                <li><strong>Payouts</strong> need min/max thresholds set in Settings (K200–K2000 at K10 per 1,000 views).</li>
            </ol>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>