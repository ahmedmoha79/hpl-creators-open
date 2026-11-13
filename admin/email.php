<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/mailer.php';
$user = require_admin();
$pageTitle = 'Email';

if (post('action') === 'test_send') {
    csrf_check();
    $to = post('test_to');
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        flash('error', 'Enter a valid test recipient email.');
    } else {
        $html = '<p>This is a <strong>test email</strong> from ' . e(APP_NAME) . '. If it landed in your inbox (not spam) within a minute, verification codes will too.</p>';
        $ok = app_mail($to, 'HPL Creators test email', $html);
        if ($ok) {
            flash('success', 'Test email sent to ' . e($to) . ' (' . (setting('smtp_host', '') !== '' ? 'SMTP' : 'server mail') . '). It should arrive within a minute; check spam too.');
        } else {
            $extra = function_exists('app_smtp_last_error') && app_smtp_last_error() ? ' Reason: ' . app_smtp_last_error() : '';
            flash('error', 'The email could not be sent.' . $extra . (setting('smtp_host', '') !== '' ? ' Check the SMTP settings (host, port, credentials).' : ' The hosting mail server refused it; contact Porkbun support or add SMTP in Settings.'));
        }
    }
    redirect('admin/email.php');
}

$messages = [];
$error    = '';
if (get('view')) {
    $msg = imap_read_message((int) get('view'));
    if (!empty($msg['error'])) {
        $error = $msg['error'];
        $msg = null;
    }
} else {
    $messages = imap_fetch_inbox(30);
    if (!empty($messages['error'])) {
        $error = $messages['error'];
        $messages = [];
    }
}

require __DIR__ . '/../includes/admin_header.php';
$smtpConfigured = setting('smtp_host', '') !== '';
$imapConfigured = imap_settings_configured();
$imapLoaded     = extension_loaded('imap');
?>

<h1><?= e($pageTitle) ?></h1>

<div class="grid-2 mb-3">
    <div class="card">
            <h3 style="display:flex;align-items:center;gap:10px">Send
                <?php if ($smtpConfigured): ?><span class="badge ok">SMTP</span>
                <?php else: ?><span class="badge warn">mail()</span><?php endif; ?>
            </h3>
            <p class="small muted">Configure SMTP in <a href="<?= e(u('admin/settings.php')) ?>">Settings</a>. Leave it blank to use PHP mail()/log.</p>
    </div>
    <div class="card">
            <h3 style="display:flex;align-items:center;gap:10px">Receive
                <?php if (!$imapLoaded): ?><span class="badge bad">IMAP ext missing</span>
                <?php elseif ($imapConfigured): ?><span class="badge ok">IMAP</span>
                <?php else: ?><span class="badge warn">not configured</span><?php endif; ?>
            </h3>
            <p class="small muted">Read a mailbox in the inbox below. Configure IMAP in <a href="<?= e(u('admin/settings.php')) ?>">Settings</a>.</p>
    </div>
</div>

<?php $health = app_mail_health(); $allOk = !in_array(false, array_column($health['rows'], 1), true); ?>
<div class="panel mb-3">
    <div class="panel__head"><h3>Delivery health</h3><span class="badge <?= $allOk ? 'ok' : 'warn' ?>"><?= $allOk ? 'All good' : 'Needs DNS records' ?></span></div>
    <div class="panel__body">
        <p class="small muted">Emails are sent from <strong><?= e($health['from']) ?></strong>. Gmail and Yahoo deliver codes instantly only when these DNS records exist for <strong><?= e($health['domain']) ?></strong> (Porkbun &rarr; Domain &rarr; DNS). Changes can take up to an hour to show here.</p>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Record</th><th>Status</th><th>Found</th><th>Should be</th></tr></thead>
            <tbody>
            <?php foreach ($health['rows'] as [$label, $ok, $found, $want]): ?>
                <tr><td class="small"><strong><?= e($label) ?></strong></td>
                    <td><span class="badge <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? 'OK' : 'Missing' ?></span></td>
                    <td class="small muted" style="max-width:260px;overflow-wrap:anywhere"><?= e(mb_strimwidth((string)$found, 0, 120, '…')) ?></td>
                    <td class="small" style="max-width:260px;overflow-wrap:anywhere"><code><?= e($want) ?></code></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if (setting('mail_last_status', '') !== ''): ?><p class="small muted mb-0">Last email: <?= e(setting('mail_last_status', '')) ?></p><?php endif; ?>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel__head"><h3>Send a test email</h3></div>
    <div class="panel__body">
        <form method="post" class="form-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="test_send">
            <input class="input" style="max-width:320px" type="email" name="test_to" placeholder="you@example.com" value="<?= e(setting('payout_contact_email', '')) ?>" required>
            <button class="btn btn--primary" type="submit">Send test</button>
        </form>
        <?php if (function_exists('app_smtp_last_error') && app_smtp_last_error()): ?>
            <p class="small muted mt-2">Last SMTP error: <?= e(app_smtp_last_error()) ?></p>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>Inbox</h3></div>
    <div class="panel__body">
        <?php if ($error): ?>
            <div class="flash flash--warning"><?= e($error) ?></div>
        <?php elseif ($msg): ?>
            <p><a href="<?= e(u('admin/email.php')) ?>">&larr; Back to inbox</a></p>
            <div class="card">
                    <div class="msg-subject"><?= e($msg['subject']) ?></div>
                    <p class="small muted">
                        From <strong><?= e($msg['from']) ?></strong>
                        <?php if ($msg['to']): ?>to <strong><?= e($msg['to']) ?></strong><?php endif; ?>
                        &middot; <?= $msg['date'] ? date('d M Y H:i', $msg['date']) : '' ?>
                    </p>
                    <hr>
                    <div class="msg-body"><?= nl2br(e($msg['body'])) ?></div>
            </div>
        <?php elseif (!$imapConfigured): ?>
            <p class="muted">IMAP isn't configured. Go to <a href="<?= e(u('admin/settings.php')) ?>">Settings › Email — receive</a> to connect a mailbox.</p>
        <?php elseif (!$imapLoaded): ?>
            <p class="muted">The PHP IMAP extension isn't enabled on this server. Enable it in php.ini (and restart Apache) to read the inbox.</p>
        <?php elseif (count($messages) === 0): ?>
            <p class="muted">No messages in the inbox.</p>
        <?php else: ?>
            <table class="table">
                <thead>
                <tr><th>From</th><th>Subject</th><th>When</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($messages as $m): ?>
                    <tr>
                        <td><?= $m['seen'] ? '' : '<span class="badge warn">unread</span> ' ?><strong><?= e($m['from']) ?></strong></td>
                        <td><a href="<?= e(u('admin/email.php?view=' . $m['seqno'])) ?>"<?= $m['seen'] ? '' : ' class="strong"' ?>><?= e($m['subject']) ?></a></td>
                        <td class="muted small"><?= $m['date'] ? date('d M H:i', $m['date']) : '-' ?></td>
                        <td>&rarr;</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>