<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/ready_clips.php';
require_once __DIR__ . '/../includes/email_verify.php';
$user = require_creator();
$pageTitle = 'Withdraw';

$uid = (int)$user['id'];
$stats = creator_stats($uid);
$minPayout = payout_min();
$maxPayout = payout_max();
$balance = round((float)$stats['balance'], 2);
$pendingRow = fetch_one('SELECT * FROM withdrawals WHERE user_id = ? AND status = "pending" ORDER BY requested_at DESC LIMIT 1', [$uid]);
$hasPending = (bool)$pendingRow;
$mobileProviders = array_intersect_key(mobile_providers(), array_flip(['mtn', 'airtel', 'zamtel']));
$accountName = (string)$user['full_name'];
$accountNumber = zm_phone_local((string)($user['phone'] ?? '')) ?? '';
$emailOk = email_is_verified($user);
$pageOk = hpl_has_verified_page($uid);
$defaultProvider = zm_phone_network($accountNumber);
$defaultProvider = isset($mobileProviders[$defaultProvider]) ? $defaultProvider : 'mtn';
$networkNames = ['mtn' => 'MTN', 'airtel' => 'Airtel', 'zamtel' => 'Zamtel', 'zed' => 'Zed Mobile'];
$networkA = ['mtn' => 'an MTN', 'airtel' => 'an Airtel', 'zamtel' => 'a Zamtel', 'zed' => 'a Zed Mobile'];

require_once __DIR__ . '/../includes/payproof.php';
// The proof picture of one of MY payouts.
if (get('proof') !== '') {
    $pw = fetch_one('SELECT * FROM withdrawals WHERE id = ? AND user_id = ? AND status = "paid"', [(int)get('proof'), $uid]);
    if ($pw) { payproof_send($pw); }
    http_response_code(404); exit('Not found.');
}
// "I got it" / "I did not get it" for a paid payout (2026-11-12).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'receipt') {
    csrf_check();
    $rw = fetch_one('SELECT * FROM withdrawals WHERE id = ? AND user_id = ? AND status = "paid" AND receipt = "waiting"', [(int)post('id'), $uid]);
    $ans = post('answer') === 'received' ? 'received' : 'not_received';
    $why = mb_substr(trim(post('note')), 0, 250);
    if ($rw && q('UPDATE withdrawals SET receipt = ?, receipt_at = NOW(), receipt_note = ? WHERE id = ? AND receipt = "waiting"', [$ans, $why !== '' ? $why : null, (int)$rw['id']])->rowCount() === 1) {
        if ($ans === 'received') {
            flash('success', 'Thank you. Your payout is confirmed.');
        } else {
            notif_admins('payout_dispute', $user['full_name'] . ' says a payout did NOT arrive',
                money($rw['amount']) . ', ref ' . $rw['txn_reference'] . ', to ' . zm_phone_pretty((string)$rw['paid_to_number']) . ($why !== '' ? '. They wrote: ' . $why : ''), 'admin/withdrawals.php?filter=paid');
            flash('warning', 'Thank you for telling us. The HPL team will check with the mobile money company using the reference and reply to you.');
        }
    }
    redirect('creator/withdraw.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $amount = round((float)post('amount'), 2);
    $providerKey = post('provider');
    $methodInfo = $mobileProviders[$providerKey] ?? null;
    $accountName = trim(mb_substr(post('account_name'), 0, 120));
    $localNumber = zm_phone_local(post('account_number'));
    $network = $localNumber ? zm_phone_network($localNumber) : null;
    $details = trim($accountName . ($localNumber ? ' — ' . zm_phone_pretty($localNumber) : ''));

    if ((int)($user['is_demo'] ?? 0) === 1) {
        flash('error', 'This is the demo account: it cannot withdraw. Nothing was sent.');
        redirect('creator/withdraw.php');
    }
    if (!creator_can_post_ready($user)) {
        // Owner rule: approved creators withdraw; since 2026-11-05 passing the test is enough,
        // because ready-clip posters (test only) earn from their approved clips too.
        flash('error', 'You can withdraw once you pass the short HPL test.');
        redirect(onboarding_url($user));
    } elseif (!$emailOk) {
        flash('error', 'Confirm your email address before withdrawing. It only takes a minute.');
        redirect('creator/verify_email.php');
    } elseif (!hpl_has_verified_page((int)$user['id'])) {
        flash('error', 'Connect your TikTok before withdrawing. It proves the page is yours and takes one minute: open Connect TikTok from your Home page.');
    } elseif ($hasPending) {
        flash('warning', 'You already have a pending withdrawal. Please wait for it to be processed.');
    } elseif ($balance < $minPayout) {
        flash('error', 'Your balance is below the minimum payout of ' . money($minPayout) . '.');
    } elseif ($amount < $minPayout) {
        flash('error', 'The minimum withdrawal is ' . money($minPayout) . '.');
    } elseif ($amount > $maxPayout) {
        flash('error', 'The maximum per request is ' . money($maxPayout) . '.');
    } elseif ($amount > $balance) {
        flash('error', 'You cannot withdraw more than your available balance.');
    } elseif (!$methodInfo) {
        flash('error', 'Please choose a mobile payment method.');
    } elseif ($accountName === '') {
        flash('error', 'Add the name registered on the mobile money account.');
    } elseif ($localNumber === null) {
        flash('error', 'Enter a Zambian mobile number, for example 097 123 4567.');
    } elseif ($network === null || !isset($mobileProviders[$network])) {
        // We pay MTN, Airtel and Zamtel wallets only (not Zed Mobile or unknown numbers).
        flash('error', 'We can only pay MTN, Airtel or Zamtel mobile money. ' . ($network === 'zed' ? 'That is a Zed Mobile number. ' : '') . 'Enter a number on one of those networks.');
    } elseif ($network !== $providerKey) {
        // A mobile money wallet lives on its network's SIM: an MTN payout cannot reach an Airtel number.
        flash('error', 'That number is on ' . ($networkNames[$network] ?? 'another network') . ', but you chose ' . $methodInfo['label'] . '. Pick ' . ($networkNames[$network] ?? 'the matching network') . ' above, or use ' . ($networkA[$providerKey] ?? 'a matching') . ' number.');
    } else {
        // Re-check balance and pending requests under a per-creator lock, so two
        // requests sent at the same moment cannot both pass.
        $placed = hpl_tx(function () use ($uid, $amount, $methodInfo, $details, $providerKey, $localNumber) {
            q('SELECT id FROM users WHERE id = ? FOR UPDATE', [$uid]);
            if (fetch_one('SELECT id FROM withdrawals WHERE user_id = ? AND status = "pending"', [$uid])) {
                return 'pending';
            }
            if ($amount > (float)creator_stats($uid)['balance']) {
                return 'balance';
            }
            q(
                'INSERT INTO withdrawals (user_id, amount, method, account_details, payment_provider, paid_to_number, status) VALUES (?,?,?,?,?,?, "pending")',
                [$uid, $amount, $methodInfo['label'], $details, $providerKey, $localNumber]
            );
            return 'ok';
        });
        if ($placed === 'pending') {
            flash('warning', 'You already have a pending withdrawal. Please wait for it to be processed.');
        } elseif ($placed === 'balance') {
            flash('error', 'You cannot withdraw more than your available balance.');
        } else {
            $newWid = (int)fetch_val('SELECT id FROM withdrawals WHERE user_id = ? AND status = "pending" ORDER BY id DESC LIMIT 1', [$uid], 0);
            notif_admins('withdrawal', 'New withdrawal request', $user['full_name'] . ' requested ' . money($amount) . '.', 'admin/withdrawals.php', ['type' => 'withdrawal', 'id' => $newWid]);
            flash('success', 'Request sent. ' . money($amount) . ' will be paid to ' . $accountName . ' (' . zm_phone_pretty((string)$localNumber) . ') on ' . $methodInfo['label'] . '. You will get a notification when it is paid.');
        }
        redirect('creator/withdraw.php');
    }
    redirect('creator/withdraw.php');
}

$withdrawals = fetch_all('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY requested_at DESC, id DESC LIMIT 50', [$uid]);
$processing = (float)$stats['reserved'] - (float)$stats['paid'];
$approvedOk = creator_can_post_ready($user);
$checks = [
    ['ok' => $approvedOk, 'title' => 'Pass the test', 'text' => '5 short questions.', 'href' => 'creator/assessment.php', 'cta' => 'Take the test'],
    ['ok' => $emailOk, 'title' => 'Confirm your email', 'text' => 'For your receipts.', 'href' => 'creator/verify_email.php', 'cta' => 'Confirm email'],
    ['ok' => $pageOk, 'title' => 'Connect your TikTok', 'text' => 'Sign in with TikTok.', 'href' => 'creator/connect_tiktok.php', 'cta' => 'Connect'],
    ['ok' => $balance >= $minPayout, 'title' => 'Reach ' . money($minPayout), 'text' => $balance >= $minPayout ? 'You have ' . money($balance) . ' available.' : money(max(0, $minPayout - $balance)) . ' to go.' . ($stats['in_review'] > 0 ? ' ' . money($stats['in_review']) . ' waiting for a check.' : ''), 'href' => clip_submit_url(), 'cta' => general_clips_enabled() ? 'My clips' : 'Campaigns'],
];
$canWithdraw = !$hasPending && $approvedOk && $emailOk && $pageOk && $balance >= $minPayout;
$statusLabel = ['pending' => 'Processing', 'paid' => 'Paid', 'rejected' => 'Not paid'];
$statusTone = ['pending' => 'warn', 'paid' => 'ok', 'rejected' => 'bad'];

require __DIR__ . '/../includes/creator_header.php';
?>

<style>
.payout-methods { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin-top:6px; }
.payout-method { position:relative; display:block; cursor:pointer; }
.payout-method input { position:absolute; opacity:0; pointer-events:none; }
.payout-method__card {
    position:relative; display:flex; flex-direction:column; align-items:flex-start; gap:5px;
    min-height:118px; padding:14px; border:1.5px solid var(--line); border-radius:14px;
    background:#fff; transition:border-color .18s ease, box-shadow .18s ease, background .18s ease;
}
.payout-method__card:hover { border-color:#d8c89b; }
.payout-method__logo { display:flex; align-items:center; height:34px; margin-bottom:5px; }
.payout-method__logo img { display:block; height:28px; width:auto; max-width:112px; object-fit:contain; }
.payout-method__name { font-weight:700; font-size:.9rem; line-height:1.25; }
.payout-method__hint { color:var(--muted); font-size:.8125rem; }
.payout-method__check {
    position:absolute; top:10px; right:10px; width:22px; height:22px; border-radius:50%;
    display:grid; place-items:center; background:var(--primary); color:#fff; font-size:.8125rem;
    opacity:0; transform:scale(.7); transition:opacity .18s ease, transform .18s ease;
}
.payout-method input:checked + .payout-method__card {
    border-color:var(--primary); background:#fffdf5; box-shadow:0 0 0 3px rgba(184,146,42,.13);
}
.payout-method input:checked + .payout-method__card .payout-method__check { opacity:1; transform:scale(1); }
.payout-method input:focus-visible + .payout-method__card { outline:2px solid var(--primary); outline-offset:2px; }
.payout-fields { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.payout-modal[hidden] { display:none; }
.payout-modal { position:fixed; inset:0; z-index:1300; display:grid; place-items:center; padding:18px; }
.payout-modal__backdrop { position:absolute; inset:0; background:rgba(18,16,11,.58); backdrop-filter:blur(4px); }
.payout-modal__card {
    position:relative; width:min(470px,100%); max-height:calc(100vh - 36px); overflow:auto;
    background:#fff; border:1px solid var(--line); border-radius:20px;
    box-shadow:0 30px 80px rgba(0,0,0,.3); animation:payoutPop .18s ease-out;
}
@keyframes payoutPop { from { opacity:0; transform:translateY(8px) scale(.98); } to { opacity:1; transform:none; } }
.payout-modal__head { display:flex; align-items:flex-start; justify-content:space-between; gap:12px; padding:20px 22px 0; }
.payout-modal__head h2 { margin:0; font-size:1.22rem; }
.payout-modal__head p { margin:4px 0 0; color:var(--muted); font-size:.84rem; }
.payout-modal__close { width:34px; height:34px; border:1px solid var(--line); border-radius:50%; background:#fff; color:var(--muted); font-size:1.15rem; cursor:pointer; }
.payout-confirm { padding:18px 22px 4px; }
.payout-confirm__brand {
    display:flex; align-items:center; gap:14px; padding:14px; margin-bottom:14px;
    border:1px solid #eee7d4; border-radius:14px; background:#fcfaf4;
}
.payout-confirm__brand img { display:block; max-width:130px; max-height:40px; object-fit:contain; }
.payout-confirm__brand strong { display:block; font-size:.95rem; }
.payout-confirm__brand small { display:block; color:var(--muted); font-size:.8125rem; margin-top:2px; }
.payout-confirm__rows { margin:0; }
.payout-confirm__rows > div { display:flex; justify-content:space-between; gap:18px; padding:10px 0; border-bottom:1px solid #f0ece1; }
.payout-confirm__rows > div:last-child { border-bottom:0; }
.payout-confirm__rows dt { color:var(--muted); font-size:.84rem; font-weight:500; }
.payout-confirm__rows dd { margin:0; text-align:right; font-size:.92rem; font-weight:700; overflow-wrap:anywhere; }
.payout-confirm__note { margin:12px 0 0; padding:11px 13px; border-radius:11px; background:#fcf8ea; color:#7a6424; font-size:.8125rem; line-height:1.5; }
.payout-modal__actions { display:flex; gap:10px; padding:16px 22px 22px; }
.payout-modal__actions .btn { flex:1; }
.wd-row__proof { display:inline-flex; gap:6px; align-items:center; margin-top:6px; font-size:.8125rem; font-weight:700; color:var(--ui-accent, #a8642c); }
.wd-row__proof .ico { width:15px; height:15px; }
.wd-row__ok { margin-top:6px; font-size:.8125rem; color:#1f7a4d; font-weight:600; }
.wd-row { flex-wrap:wrap; }
.wd-row__ask { margin-top:6px; font-size:.8125rem; font-weight:700; color:#a15c07; }
.wd-confirm { flex-basis:100%; margin-top:4px; padding:12px; border-radius:12px; background:#fdf1df; display:flex; flex-direction:column; gap:8px; }
.wd-confirm p { margin:0; font-size:.875rem; color:#4a3a2e; }
.wd-confirm__btns { display:flex; gap:8px; flex-wrap:wrap; }
.wd-confirm__no { display:none; gap:8px; flex-direction:column; }
.wd-confirm.is-no .wd-confirm__no { display:flex; }
.wd-confirm.is-no .wd-confirm__btns { display:none; }
.wd-confirm small { font-size:.75rem; }
.wd-grid { display:grid; grid-template-columns:minmax(0,1.15fr) minmax(0,1fr); gap:18px; align-items:start; }
.wd-amount { display:flex; align-items:stretch; border:1px solid var(--ui-line, #e7dfd2); border-radius:12px; overflow:hidden; background:#fff; }
.wd-amount:focus-within { border-color:var(--ui-accent, #a8642c); box-shadow:0 0 0 3px rgba(168,100,44,.12); }
.wd-amount__cur { display:grid; place-items:center; padding:0 12px; font-weight:700; color:var(--ui-muted, #8a8273); background:var(--ui-surface-2, #faf7f2); border-right:1px solid var(--ui-line, #e7dfd2); }
body .wd-amount .input, body .wd-amount .input:focus { border:0 !important; box-shadow:none !important; outline:0; background:transparent; border-radius:0; font-size:1.15rem; font-weight:700; flex:1; min-width:0; }
.wd-amount__max { border:0; border-left:1px solid var(--ui-line, #e7dfd2); background:var(--ui-surface-2, #faf7f2); padding:0 14px; font:inherit; font-size:.8125rem; font-weight:700; color:var(--ui-accent, #a8642c); cursor:pointer; }
#numberHint.is-bad { color:var(--ui-bad, #b91c1c); }
#numberHint.is-ok { color:var(--ui-ok, #15803d); }
.wd-lead { margin:0 0 10px; color:var(--ui-text-2); font-size:.9rem; }
.wd-checks { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:10px; }
.wd-checks li { display:flex; align-items:center; flex-wrap:wrap; gap:12px; padding:12px 14px; border:1px solid var(--ui-line, #e7dfd2); border-radius:12px; }
.wd-checks li.is-ok { background:#f6fbf7; border-color:#d3ecdc; }
.wd-checks__mark { width:24px; height:24px; flex:none; border-radius:50%; border:2px solid var(--ui-line-strong, #d8cdbb); display:grid; place-items:center; color:#fff; }
.wd-checks li.is-ok .wd-checks__mark { background:#2fb36d; border-color:#2fb36d; }
.wd-checks__mark .ico { width:13px; height:13px; stroke-width:3; }
.wd-checks__text { flex:1 1 150px; min-width:0; display:flex; flex-direction:column; gap:2px; }
.wd-checks__text small { color:var(--ui-muted); font-size:.8125rem; }
.wd-goal { height:6px; border-radius:999px; background:var(--ui-sunken, #efe9df); overflow:hidden; margin-top:14px; }
.wd-goal span { display:block; height:100%; background:#2fb36d; }
.wd-pending__amt { font-size:2rem; font-weight:750; letter-spacing:-.01em; }
.wd-pending__to { display:flex; align-items:center; gap:10px; color:var(--ui-text-2); font-size:.88rem; margin:4px 0 16px; }
.wd-pending__to img { max-height:20px; max-width:60px; }
.wd-track { list-style:none; margin:0; padding:0 0 0 18px; border-left:2px solid var(--ui-line, #e7dfd2); display:flex; flex-direction:column; gap:14px; }
.wd-track li { position:relative; display:flex; flex-direction:column; gap:2px; font-size:.84rem; color:var(--ui-muted); }
.wd-track li b { color:var(--ui-text); font-size:.9rem; }
.wd-track li::before { content:""; position:absolute; left:-25px; top:3px; width:12px; height:12px; border-radius:50%; background:#fff; border:2px solid var(--ui-line-strong, #d8cdbb); }
.wd-track li.is-done::before { background:#2fb36d; border-color:#2fb36d; }
.wd-track li.is-now::before { background:#f0b44c; border-color:#f0b44c; box-shadow:0 0 0 4px rgba(240,180,76,.2); }
.wd-note { margin:16px 0 0; font-size:.8125rem; color:var(--ui-muted); }
.wd-row__logo { width:40px; height:40px; flex:none; border-radius:10px; background:var(--ui-surface-2, #faf7f2); border:1px solid var(--ui-line, #e7dfd2); display:grid; place-items:center; }
.wd-row__logo img { max-width:32px; max-height:18px; }
.wd-row__to { font-weight:500; color:var(--ui-muted); font-size:.82rem; margin-left:4px; }
.wd-row__note { margin-top:4px; font-size:.8125rem; color:var(--ui-bad, #b91c1c); }
.wd-stats { grid-template-columns:repeat(3,minmax(0,1fr)); }
@media (max-width:1000px) { .wd-grid { grid-template-columns:1fr; } }
@media (max-width:720px) { .wd-stats { grid-template-columns:1fr; gap:10px; } }
.provider-mini { display:inline-block; max-width:34px; max-height:16px; margin-right:6px; vertical-align:-2px; object-fit:contain; }
@media (max-width:720px) {
    .payout-methods { grid-template-columns:1fr; gap:8px; }
    .payout-method__card { min-height:0; flex-direction:row; align-items:center; gap:10px; padding:11px 12px; }
    .payout-method__logo { height:30px; width:78px; margin:0; flex:none; }
    .payout-method__logo img { max-width:78px; max-height:28px; }
    .payout-method__hint { display:none; }
    .payout-method__check { position:static; margin-left:auto; flex:none; }
    .payout-fields { grid-template-columns:1fr; gap:0; }
    .payout-modal { padding:12px; }
    .payout-modal__actions { flex-direction:column-reverse; }
}
</style>

<div class="ui-page">
<div class="ui-page-head">
    <div>
        <h1>Withdraw</h1>
        <p class="ui-page-head__sub">To MTN, Airtel or Zamtel. <?= money($minPayout) ?> to <?= money($maxPayout) ?>.</p>
    </div>
</div>

<div class="ui-stats wd-stats">
    <div class="ui-stat ui-stat--dark">
        <div class="ui-stat__label"><?= icon('wallet') ?>Available to withdraw</div>
        <div class="ui-stat__value"><?= money($balance) ?></div>
        <div class="ui-stat__meta"><?= $hasPending ? 'Request more once your current payout is paid' : ($balance >= $minPayout ? 'Up to ' . money(min($balance, $maxPayout)) . ' in this request' : money(max(0, $minPayout - $balance)) . ' to the ' . money($minPayout) . ' minimum') ?></div>
    </div>
    <div class="ui-stat">
        <div class="ui-stat__label"><?= icon('clock') ?>Processing</div>
        <div class="ui-stat__value"><?= money(max(0, $processing)) ?></div>
        <div class="ui-stat__meta"><?= $hasPending ? 'Requested ' . e(time_ago($pendingRow['requested_at'])) : 'No request waiting' ?></div>
    </div>
    <div class="ui-stat">
        <div class="ui-stat__label"><?= icon('banknote') ?>Paid out</div>
        <div class="ui-stat__value"><?= money($stats['paid']) ?></div>
        <div class="ui-stat__meta">Lifetime earned <?= money($stats['earned']) ?></div>
    </div>
</div>

<div class="wd-grid">
    <section class="ui-card">
        <div class="ui-card__head"><h2><?= icon('send') ?>Request a payout</h2></div>
        <div class="ui-card__body">
            <?php if ($hasPending): ?>
                <div class="wd-pending">
                    <div class="wd-pending__amt"><?= money($pendingRow['amount']) ?></div>
                    <div class="wd-pending__to">
                        <?php $pl = provider_logo($pendingRow['payment_provider']); ?><?php if ($pl): ?><img src="<?= e($pl) ?>" alt=""><?php endif; ?>
                        <span><?= e($pendingRow['account_details'] ?: provider_badge($pendingRow['payment_provider'])) ?></span>
                    </div>
                    <ol class="wd-track">
                        <li class="is-done"><b>Requested</b><span><?= e(date('j M, H:i', strtotime((string)$pendingRow['requested_at']))) ?></span></li>
                        <li class="is-now"><b>Being sent</b><span>The HPL payments team sends it to your mobile money.</span></li>
                        <li><b>Paid</b><span>You get a notification with the transaction reference.</span></li>
                    </ol>
                    <p class="wd-note">You can request again once this one is paid.</p>
                </div>
            <?php elseif (!$canWithdraw): ?>
                <p class="wd-lead"><?= (float)$stats['paid'] > 0 ? 'Before you can withdraw again:' : 'Before your first payout:' ?></p>
                <ul class="wd-checks">
                    <?php foreach ($checks as $c): ?>
                        <li class="<?= $c['ok'] ? 'is-ok' : '' ?>">
                            <span class="wd-checks__mark"><?= $c['ok'] ? icon('check') : '' ?></span>
                            <span class="wd-checks__text"><b><?= e($c['title']) ?></b><small><?= e($c['text']) ?></small></span>
                            <?php if (!$c['ok']): ?><a class="btn btn--ghost btn--sm" href="<?= e(u($c['href'])) ?>"><?= e($c['cta']) ?></a><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ($balance < $minPayout && $minPayout > 0): ?>
                    <div class="wd-goal"><span style="width:<?= min(100, round($balance / $minPayout * 100)) ?>%"></span></div>
                <?php endif; ?>
            <?php else: ?>
                <form method="post" id="payoutForm" novalidate>
                    <?= csrf_field() ?>
                    <div class="field">
                        <label class="label" for="amount">Amount</label>
                        <div class="wd-amount">
                            <span class="wd-amount__cur"><?= e(currency()) ?></span>
                            <input class="input" type="number" id="amount" name="amount" min="<?= e($minPayout) ?>" max="<?= e(min($maxPayout, $balance)) ?>" step="0.01" value="<?= e(number_format(min($balance, $maxPayout), 2, '.', '')) ?>" required>
                            <button type="button" class="wd-amount__max" data-max="<?= e(number_format(min($balance, $maxPayout), 2, '.', '')) ?>">Max</button>
                        </div>
                        <div class="field__hint"><?= money($balance) ?> available. Minimum <?= money($minPayout) ?>, up to <?= money($maxPayout) ?> per request.<?php if ($stats['in_review'] > 0): ?> <?= money($stats['in_review']) ?> more is waiting for our team to check your clips.<?php endif; ?></div>
                    </div>
                    <div class="field">
                        <span class="label">Mobile money network</span>
                        <div class="payout-methods" role="radiogroup" aria-label="Mobile money network">
                            <?php foreach ($mobileProviders as $key => $provider): ?>
                                <label class="payout-method">
                                    <input type="radio" name="provider" value="<?= e($key) ?>" data-label="<?= e($provider['label']) ?>" data-logo="<?= e(provider_logo($key)) ?>" <?= $key === $defaultProvider ? 'checked' : '' ?> required>
                                    <span class="payout-method__card">
                                        <span class="payout-method__logo"><img src="<?= e(provider_logo($key)) ?>" alt="<?= e($provider['label']) ?> logo"></span>
                                        <span class="payout-method__name"><?= e($provider['label']) ?></span>
                                        <span class="payout-method__check" aria-hidden="true">&#10003;</span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="payout-fields">
                        <div class="field">
                            <label class="label" for="account_name">Name on the account</label>
                            <input class="input" type="text" id="account_name" name="account_name" maxlength="120" value="<?= e($accountName) ?>" placeholder="As registered with the network" autocomplete="name" required>
                        </div>
                        <div class="field">
                            <label class="label" for="account_number">Mobile money number</label>
                            <input class="input" type="tel" id="account_number" name="account_number" inputmode="tel" maxlength="20" value="<?= e($accountNumber ? zm_phone_pretty($accountNumber) : '') ?>" placeholder="097 123 4567" autocomplete="tel" required>
                            <div class="field__hint" id="numberHint" aria-live="polite"></div>
                        </div>
                    </div>
                    <button class="btn btn--primary btn--block" type="submit" id="payoutOpen">Review payout</button>
                </form>
            <?php endif; ?>
        </div>
    </section>

    <section class="ui-card">
        <div class="ui-card__head"><h2><?= icon('banknote') ?>History</h2><span class="ui-badge ui-badge--neutral"><?= e(num(count($withdrawals))) ?></span></div>
        <?php if (!$withdrawals): ?>
            <div class="ui-empty"><?= icon('banknote') ?><p>No payouts yet. Your first one shows up here with its transaction reference.</p></div>
        <?php else: ?>
            <ul class="ui-rows">
                <?php foreach ($withdrawals as $w):
                    $st = (string)$w['status'];
                    $logo = provider_logo($w['payment_provider']);
                    $to = $w['paid_to_number'] ? zm_phone_pretty((string)$w['paid_to_number']) : '';
                ?>
                    <li class="ui-row wd-row">
                        <span class="wd-row__logo"><?php if ($logo): ?><img src="<?= e($logo) ?>" alt=""><?php else: ?><?= icon('wallet') ?><?php endif; ?></span>
                        <div class="ui-row__main">
                            <div class="ui-row__title"><?= money($w['amount']) ?> <span class="wd-row__to"><?= provider_badge($w['payment_provider']) ?><?= $to ? ' &middot; ' . e($to) : '' ?></span></div>
                            <div class="ui-row__sub">
                                Requested <?= e(date('j M Y', strtotime((string)$w['requested_at']))) ?>
                                <?php if ($st === 'paid' && $w['resolved_at']): ?> &middot; paid <?= e(date('j M', strtotime((string)$w['resolved_at']))) ?><?php endif; ?>
                                <?php if (!empty($w['txn_reference'])): ?> &middot; Ref <?= e($w['txn_reference']) ?><?php endif; ?>
                            </div>
                            <?php if ($st === 'rejected' && !empty($w['admin_note'])): ?><div class="wd-row__note"><?= e($w['admin_note']) ?> The amount is back in your balance.</div><?php endif; ?>
                            <?php if ($st === 'paid' && !empty($w['proof_file'])): ?><a class="wd-row__proof" href="<?= e(u('creator/withdraw.php?proof=' . (int)$w['id'])) ?>" target="_blank" rel="noopener"><?= icon('image') ?>See payment proof</a><?php endif; ?>
                            <?php if ($st === 'paid' && ($w['receipt'] ?? '') === 'waiting'): ?><div class="wd-row__ask">Please confirm below.</div>
                            <?php elseif ($st === 'paid' && ($w['receipt'] ?? '') === 'received'): ?><div class="wd-row__ok">You confirmed you got it.</div>
                            <?php elseif ($st === 'paid' && ($w['receipt'] ?? '') === 'not_received'): ?><div class="wd-row__note">You told us it did not arrive. The HPL team is checking.</div>
                            <?php endif; ?>
                        </div>
                        <div class="ui-row__end"><span class="ui-badge ui-badge--<?= e($statusTone[$st] ?? 'neutral') ?>"><?= e($statusLabel[$st] ?? ucfirst($st)) ?></span></div>
                            <?php if ($st === 'paid' && ($w['receipt'] ?? '') === 'waiting'): ?>
                                <form method="post" class="wd-confirm">
                                    <?= csrf_field() ?><input type="hidden" name="action" value="receipt"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                                    <p>Did <?= money($w['amount']) ?> arrive on your mobile money? Check your messages for ref <?= e((string)$w['txn_reference']) ?>.</p>
                                    <div class="wd-confirm__btns">
                                        <button class="btn btn--primary btn--sm" type="submit" name="answer" value="received">I got it</button>
                                        <button class="btn btn--ghost btn--sm" type="button" onclick="this.closest('form').classList.add('is-no')">I did not get it</button>
                                    </div>
                                    <div class="wd-confirm__no">
                                        <input class="input" name="note" maxlength="250" placeholder="What happened? (optional)">
                                        <button class="btn btn--danger btn--sm" type="submit" name="answer" value="not_received">Send: I did not get it</button>
                                    </div>
                                    <small class="muted">No answer by <?= e(date('D j M', strtotime((string)$w['resolved_at']) + payproof_days() * 86400)) ?> counts as received.</small>
                                </form>
                            <?php endif; ?>

                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
</div>

<?php if ($canWithdraw): ?>
<div class="payout-modal" id="payoutModal" hidden>
    <div class="payout-modal__backdrop" data-payout-close></div>
    <div class="payout-modal__card" role="dialog" aria-modal="true" aria-labelledby="payoutModalTitle">
        <div class="payout-modal__head">
            <div>
                <h2 id="payoutModalTitle">Confirm withdrawal</h2>
                <p>Check the details. Money sent to a wrong number cannot be pulled back.</p>
            </div>
            <button class="payout-modal__close" type="button" data-payout-close aria-label="Close">&times;</button>
        </div>
        <div class="payout-confirm">
            <div class="payout-confirm__brand">
                <img id="payoutConfirmLogo" src="" alt="">
                <div>
                    <strong id="payoutConfirmMethod"></strong>
                    <small>Mobile money payout</small>
                </div>
            </div>
            <dl class="payout-confirm__rows">
                <div><dt>Name</dt><dd id="payoutConfirmName"></dd></div>
                <div><dt>Number</dt><dd id="payoutConfirmNumber"></dd></div>
                <div><dt>Amount</dt><dd id="payoutConfirmAmount"></dd></div>
            </dl>
            <p class="payout-confirm__note">When you confirm, your request is submitted to the HPL payments team for processing.</p>
        </div>
        <div class="payout-modal__actions">
            <button class="btn btn--ghost" type="button" data-payout-close>Back</button>
            <button class="btn btn--primary" type="button" id="payoutConfirmBtn">Confirm &amp; request payment</button>
        </div>
    </div>
</div>
<script>
(function(){
    var form = document.getElementById('payoutForm');
    var modal = document.getElementById('payoutModal');
    if (!form || !modal) return;
    var confirmBtn = document.getElementById('payoutConfirmBtn');
    var currency = '<?= e(currency()) ?>';
    var amountInput = form.querySelector('[name="amount"]');
    var nameInput = form.querySelector('[name="account_name"]');
    var numberInput = form.querySelector('[name="account_number"]');

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
    }

    function openModal() {
        var provider = form.querySelector('input[name="provider"]:checked');
        if (!provider) return;
        var amount = parseFloat(amountInput.value) || 0;
        document.getElementById('payoutConfirmLogo').src = provider.getAttribute('data-logo');
        document.getElementById('payoutConfirmLogo').alt = provider.getAttribute('data-label') + ' logo';
        document.getElementById('payoutConfirmMethod').textContent = provider.getAttribute('data-label');
        document.getElementById('payoutConfirmName').textContent = nameInput.value;
        var ln = localNumber(numberInput.value) || numberInput.value;
        document.getElementById('payoutConfirmNumber').textContent = ln.length === 10 ? ln.slice(0, 3) + ' ' + ln.slice(3, 6) + ' ' + ln.slice(6) : ln;
        document.getElementById('payoutConfirmAmount').textContent = currency + amount.toLocaleString('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Confirm & request payment';
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        confirmBtn.focus();
    }

    // Zambian numbers: the network is in the prefix (MTN 096/076/056, Airtel 097/077/057, Zamtel 095/075/055).
    var nets = { '96':'mtn','76':'mtn','56':'mtn','97':'airtel','77':'airtel','57':'airtel','95':'zamtel','75':'zamtel','55':'zamtel','98':'zed','78':'zed','58':'zed' };
    var netName = { mtn:'MTN', airtel:'Airtel', zamtel:'Zamtel', zed:'Zed Mobile' };
    var netA = { mtn:'an MTN', airtel:'an Airtel', zamtel:'a Zamtel', zed:'a Zed Mobile' };
    var hint = document.getElementById('numberHint');
    function localNumber(v) {
        var d = String(v || '').replace(/\D/g, '');
        if (d.length === 12 && d.indexOf('260') === 0) d = '0' + d.slice(3);
        else if (d.length === 9) d = '0' + d;
        return /^0[579]\d{8}$/.test(d) ? d : null;
    }
    function checkNumber(showEmpty) {
        var chosen = form.querySelector('input[name="provider"]:checked');
        var n = localNumber(numberInput.value);
        var msg = '', ok = true;
        if (!n) {
            ok = false;
            if (numberInput.value.trim() !== '' || showEmpty) msg = 'Enter a Zambian mobile number, for example 097 123 4567.';
        } else {
            var net = nets[n.slice(1, 3)];
            if (!net || net === 'zed') {
                ok = false;
                msg = (net === 'zed' ? 'That is a Zed Mobile number. ' : '') + 'We can only pay MTN, Airtel or Zamtel mobile money. Use a number on one of those networks.';
            } else if (chosen && net !== chosen.value) {
                ok = false;
                msg = 'This is ' + (netA[net] || 'another network\'s') + ' number. Choose ' + (netName[net] || 'its network') + ' above, or use ' + netA[chosen.value] + ' number.';
            } else {
                msg = netName[net] + ' number ✓';
            }
        }
        if (hint) { hint.textContent = msg; hint.classList.toggle('is-bad', !ok && msg !== ''); hint.classList.toggle('is-ok', ok); }
        numberInput.setCustomValidity(ok ? '' : (msg || 'Enter your mobile money number.'));
        return ok;
    }
    numberInput.addEventListener('input', function () { checkNumber(false); });
    form.querySelectorAll('input[name="provider"]').forEach(function (r) { r.addEventListener('change', function () { checkNumber(false); }); });
    checkNumber(false);
    var maxBtn = form.querySelector('.wd-amount__max');
    if (maxBtn) maxBtn.addEventListener('click', function () { amountInput.value = maxBtn.getAttribute('data-max'); amountInput.focus(); });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        checkNumber(true);
        if (!form.reportValidity()) return;
        openModal();
    });

    confirmBtn.addEventListener('click', function() {
        if (confirmBtn.disabled) return;
        confirmBtn.disabled = true;
        confirmBtn.textContent = 'Submitting request...';
        form.submit();
    });

    modal.querySelectorAll('[data-payout-close]').forEach(function(el) {
        el.addEventListener('click', closeModal);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !modal.hidden) closeModal();
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
