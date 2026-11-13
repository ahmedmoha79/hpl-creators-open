<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
$user = require_admin();
require_once __DIR__ . '/../includes/payproof.php';
if (get('proof') !== '') {
    $pw = fetch_one('SELECT * FROM withdrawals WHERE id = ?', [(int)get('proof')]);
    if ($pw) { payproof_send($pw); }
    http_response_code(404); exit;
}
$pageTitle = 'Withdrawals';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'paid_many') {
    // One bulk payment (e.g. a bulk-payment list) for every ticked request: one reference for all.
    csrf_check();
    $okN = 0; $bad = [];
    foreach (array_slice(array_map('intval', (array)($_POST['ids'] ?? [])), 0, 300) as $wid) {
        [$ok, $msg] = payout_mark_paid($wid, '', (string)post('txn_reference'), '', 'Paid in one bulk payment');
        if ($ok) { $okN++; } else { $bad[] = $msg; }
    }
    flash($bad ? 'warning' : 'success', $okN . ' marked as paid.' . ($bad ? ' Not marked: ' . implode(' ', array_slice($bad, 0, 5)) : ''));
    redirect('admin/withdrawals.php');
}
if (get('csv') === '1') {
    // The waiting requests as a list for a bulk-payment service or a spreadsheet (one network or all).
    $net = (string)get('net');
    $list = fetch_all('SELECT w.id, w.amount, w.payment_provider, w.paid_to_number, w.account_details, u.full_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = "pending"'
        . ($net !== '' ? ' AND w.payment_provider = ?' : '') . ' ORDER BY w.requested_at', $net !== '' ? [$net] : []);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="hpl-payouts-' . ($net ?: 'all') . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Name', 'Number', 'Amount (ZMW)', 'Network', 'Reference']);
    foreach ($list as $r) {
        $nm = trim((string)explode(' — ', (string)$r['account_details'])[0]) ?: $r['full_name'];
        $num = preg_replace('/\D/', '', (string)$r['paid_to_number']);
        fputcsv($out, [$nm, $num !== '' ? '260' . ltrim($num, '0') : '', number_format((float)$r['amount'], 2, '.', ''), mobile_providers()[$r['payment_provider']]['label'] ?? $r['payment_provider'], 'HPL-W' . (int)$r['id']]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)post('id');
    $action = post('action');
    $note = mb_substr(post('admin_note'), 0, 250);
    $w = fetch_one('SELECT * FROM withdrawals WHERE id = ?', [$id]);
    if (!$w) {
        flash('error', 'Withdrawal not found.');
    } elseif (in_array($action, ['paid', 'reject'], true) && $w['status'] !== 'pending') {
        flash('error', 'This request is already ' . $w['status'] . '. Only pending requests can be paid or rejected.');
    } elseif ($action === 'paid') {
        $proofFile = null;
        if (!empty($_FILES['proof']) && ($_FILES['proof']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            [$proofFile, $proofErr] = payproof_save($_FILES['proof']);
            if ($proofErr !== null) {
                flash('error', $proofErr . ' Nothing was marked as paid.');
                redirect('admin/withdrawals.php?filter=' . urlencode(get('filter', 'pending')));
            }
        }
        [$ok, $msg] = payout_mark_paid($id, (string)post('payment_provider', ''), (string)post('txn_reference'), normalize_phone((string)post('paid_to_number')), $note, $proofFile);
        if (post('fast') === '1') {
            json_response(['ok' => $ok, 'msg' => $msg]);
        }
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'add_proof' && $w['status'] === 'paid') {
        [$proofFile, $proofErr] = payproof_save($_FILES['proof'] ?? null);
        if ($proofErr !== null) {
            flash('error', $proofErr);
        } else {
            q('UPDATE withdrawals SET proof_file = ? WHERE id = ?', [$proofFile, $id]);
            flash('success', 'Proof saved. The creator can see it in Wallet.');
        }
    } elseif ($action === 'update') {
        if ($w['status'] !== 'pending') {
            flash('error', 'Only pending requests can be edited.');
        } else {
            $amount = round((float)post('amount'), 2);
            $method = mb_substr(trim(post('method')), 0, 80);
            $details = mb_substr(trim(post('account_details')), 0, 300);
            $numRaw = trim((string)post('paid_to_number'));
            $newNumber = $numRaw === '' ? (string)($w['paid_to_number'] ?? '') : (zm_phone_local($numRaw) ?? '');
            if ($amount <= 0 || $method === '' || $details === '') {
                flash('error', 'Amount, method and payment details are required.');
            } elseif ($numRaw !== '' && $newNumber === '') {
                flash('error', 'Enter a Zambian mobile number, for example 097 123 4567.');
            } elseif ($amount < payout_min() || (payout_max() > 0 && $amount > payout_max())) {
                // Same limits creators have when they ask (Settings > Minimum / Maximum payout).
                flash('error', 'The amount must be between ' . money(payout_min()) . ' and ' . money(payout_max()) . '. You can change these limits in Settings.');
            } else {
                // Checked under a lock on the creator, like "Mark as paid", so two changes at once cannot overspend.
                $done = hpl_tx(function () use ($w, $id, $amount, $method, $details, $newNumber) {
                    q('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int)$w['user_id']]);
                    $cur = fetch_one('SELECT amount, status FROM withdrawals WHERE id = ? FOR UPDATE', [$id]);
                    if (!$cur || $cur['status'] !== 'pending') {
                        return 0;
                    }
                    $st = creator_stats((int)$w['user_id']);
                    $canHave = round($st['available'] + (float)$cur['amount'], 2);
                    if ($amount > $canHave + 0.001) {
                        return -$canHave - 1;
                    }
                    // The number it is paid to follows the edit, and so does its network.
                    $net = $newNumber !== '' ? zm_phone_network($newNumber) : null;
                    return q('UPDATE withdrawals SET amount = ?, method = ?, account_details = ?, paid_to_number = ?, payment_provider = COALESCE(?, payment_provider) WHERE id = ? AND status = "pending"',
                        [$amount, $method, $details, $newNumber !== '' ? $newNumber : null, ($net && array_key_exists($net, mobile_providers())) ? $net : null, $id])->rowCount();
                });
                if ($done < 0) {
                    flash('error', 'This creator can withdraw at most ' . money(max(0, -$done - 1)) . ' right now.');
                } else {
                    flash($done ? 'success' : 'error', $done ? 'Withdrawal request updated.' : 'This request was already handled by someone else.');
                }
            }
        }
    } elseif ($action === 'reject') {
        $done = q('UPDATE withdrawals SET status = "rejected", admin_note = ?, resolved_at = NOW() WHERE id = ? AND status = "pending"', [$note ?: 'Rejected', $id])->rowCount();
        if ($done !== 1) {
            flash('error', 'This request was already handled by someone else.');
            redirect('admin/withdrawals.php?filter=' . urlencode(get('filter', 'pending')));
        }
        notif_add((int)$w['user_id'], 'withdrawal', 'Withdrawal not approved', ($note ?: 'Your withdrawal request was rejected.') . ' The amount is back in your balance.', 'creator/withdraw.php');
        flash('warning', 'Withdrawal rejected. The amount returns to the creator\'s balance.');
    }
    redirect('admin/withdrawals.php?filter=' . urlencode(get('filter', 'pending')));
}

$filter = get('filter', 'pending');
$where = $filter !== 'all' ? 'WHERE w.status = ?' : '';
$params = $filter !== 'all' ? [$filter] : [];
$rows = fetch_all(
    'SELECT w.*, u.full_name, u.email, u.phone FROM withdrawals w JOIN users u ON u.id = w.user_id ' . $where . ' ORDER BY w.requested_at ASC',
    $params
);
$contactEmail = setting('payout_contact_email', '');

require __DIR__ . '/../includes/admin_header.php';
?>

<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Withdrawals</h1>
        <p class="muted mb-0">Send the money to the creator's mobile money number, then mark it as paid with the transaction reference.</p>
    </div>
</div>

<div class="tabs">
    <?php foreach (['pending' => 'Pending', 'paid' => 'Paid', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
        <a href="<?= e(u('admin/withdrawals.php?filter=' . $k)) ?>" class="<?= $filter === $k ? 'is-active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
    <div class="panel"><div class="panel__body empty"><div class="empty__icon">&#127974;</div><p>No withdrawals in this view.</p></div></div>
<?php else: ?>
    <?php if ($filter === 'pending'):
        require_once __DIR__ . '/../includes/integrity.php';
        $byNet = [];
        foreach ($rows as $r) { $byNet[(string)$r['payment_provider']] = ($byNet[(string)$r['payment_provider']] ?? 0) + (float)$r['amount']; }
    ?>
    <div class="panel fp">
        <div class="panel__head"><h3>Fast pay list</h3><span class="badge"><?= count($rows) ?> waiting &middot; <?= e(money(array_sum($byNet))) ?></span></div>
        <div class="panel__body">
            <p class="small muted" style="margin:0 0 10px">For each person: tap the number to copy it, send the money, paste the transaction ID, tap Paid. The creator is asked to confirm "I got it". A screenshot is only needed if someone says the money did not arrive.</p>
            <div class="flex flex-wrap" style="gap:8px;margin-bottom:12px">
                <?php foreach ($byNet as $net => $sum): ?><a class="btn btn--ghost btn--sm" href="<?= e(u('admin/withdrawals.php?csv=1&net=' . urlencode($net))) ?>">Download <?= e(mobile_providers()[$net]['label'] ?? $net) ?> list (<?= e(money($sum)) ?>)</a><?php endforeach; ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/withdrawals.php?csv=1')) ?>">Download all</a>
            </div>
            <form method="post" id="fpMany"><?= csrf_field() ?><input type="hidden" name="action" value="paid_many"></form>
            <div class="table-wrap"><table class="table fp__table">
                <thead><tr><th><input type="checkbox" id="fpAll" aria-label="Tick all"></th><th>Person</th><th>Number</th><th>Amount</th><th>Transaction ID</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $fRisk = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND risk_level = "high" AND risk_ok = 0 AND status <> "rejected"', [(int)$r['user_id']], 0) > 0 || integrity_linked_accounts((int)$r['user_id']);
                    $num = (string)$r['paid_to_number']; ?>
                    <tr data-wid="<?= (int)$r['id'] ?>">
                        <td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" form="fpMany" aria-label="Tick <?= e($r['full_name']) ?>"></td>
                        <?php $mmName = trim((string)explode(' — ', (string)$r['account_details'])[0]); ?><td><strong><?= e($r['full_name']) ?></strong><div class="small">Name on mobile money: <strong><?= e($mmName !== '' ? $mmName : '—') ?></strong></div><div class="small muted"><?= provider_badge($r['payment_provider']) ?></div><?php if ($fRisk): ?><a class="badge warn" href="#w<?= (int)$r['id'] ?>">Check first</a><?php endif; ?></td>
                        <td><button type="button" class="fp__copy" data-copy="<?= e($num) ?>"><?= e($num !== '' ? zm_phone_pretty($num) : '—') ?></button></td>
                        <td><button type="button" class="fp__copy" data-copy="<?= e(number_format((float)$r['amount'], 2, '.', '')) ?>"><?= e(money($r['amount'])) ?></button></td>
                        <td><input class="input input--sm fp__ref" placeholder="Paste the ID" aria-label="Transaction ID for <?= e($r['full_name']) ?>"></td>
                        <td><button type="button" class="btn btn--ok btn--sm fp__paid">Paid</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
            <div class="flex flex-wrap" style="gap:8px;align-items:center;margin-top:12px">
                <span class="small muted">Paid everyone ticked in one bulk payment?</span>
                <input class="input input--sm" name="txn_reference" form="fpMany" placeholder="Bulk payment reference" required style="max-width:220px">
                <button class="btn btn--primary btn--sm" type="submit" form="fpMany" data-confirm="Mark every ticked request as paid with this one reference? Each creator will be asked to confirm.">Mark ticked as paid</button>
            </div>
        </div>
    </div>
    <style>
    .fp__table td { vertical-align: middle; }
    .fp__copy { border: 1px dashed var(--ui-line-strong, #d8cdbb); background: #fff; border-radius: 8px; padding: 6px 10px; font: inherit; font-weight: 700; cursor: pointer; white-space: nowrap; }
    .fp__copy.is-copied { border-style: solid; border-color: #2fb36d; color: #1f7a4d; }
    .fp__ref { min-width: 150px; }
    tr.is-paid { opacity: .5; } tr.is-paid .fp__paid { pointer-events: none; }
    </style>
    <script>
    (function(){
        var csrf = <?= json_encode(csrf_token()) ?>, url = <?= json_encode(u('admin/withdrawals.php')) ?>;
        document.querySelectorAll('.fp__copy').forEach(function(b){ b.addEventListener('click', function(){
            var t = b.getAttribute('data-copy'); if (!t) return;
            (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).catch(function(){ var i = document.createElement('input'); i.value = t; document.body.appendChild(i); i.select(); document.execCommand('copy'); i.remove(); });
            b.classList.add('is-copied'); setTimeout(function(){ b.classList.remove('is-copied'); }, 1500);
        }); });
        var all = document.getElementById('fpAll');
        if (all) all.addEventListener('change', function(){ document.querySelectorAll('input[name="ids[]"]').forEach(function(c){ if (!c.closest('tr').classList.contains('is-paid')) c.checked = all.checked; }); });
        document.querySelectorAll('.fp__paid').forEach(function(b){ b.addEventListener('click', function(){
            var tr = b.closest('tr'), ref = tr.querySelector('.fp__ref');
            if (!ref.value.trim()) { ref.focus(); ref.placeholder = 'Paste the ID first'; return; }
            b.disabled = true; b.textContent = 'Saving...';
            var fd = new FormData(); fd.append('csrf', csrf); fd.append('action', 'paid'); fd.append('fast', '1'); fd.append('id', tr.getAttribute('data-wid')); fd.append('txn_reference', ref.value.trim());
            fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); }).then(function(d){
                if (d && d.ok) { tr.classList.add('is-paid'); b.textContent = 'Paid'; var c = tr.querySelector('input[type=checkbox]'); if (c) { c.checked = false; c.disabled = true; }
                    var next = tr.nextElementSibling; while (next && next.classList.contains('is-paid')) next = next.nextElementSibling; if (next) { var nb = next.querySelector('.fp__copy'); if (nb) nb.focus(); } }
                else { b.disabled = false; b.textContent = 'Paid'; alertBox(tr, d && d.msg ? d.msg : 'Not saved. Try again.'); }
            }).catch(function(){ b.disabled = false; b.textContent = 'Paid'; alertBox(tr, 'Network problem. Try again.'); });
        }); });
        function alertBox(tr, msg){ var x = tr.querySelector('.fp__err'); if (!x) { x = document.createElement('div'); x.className = 'fp__err small'; x.style.color = '#b42318'; tr.children[1].appendChild(x); } x.textContent = msg; }
    })();
    </script>
    <h3 style="margin:18px 0 8px">Each request in detail</h3>
    <?php endif; ?>
    <?php foreach ($rows as $w): ?>
        <div class="panel" id="w<?= (int)$w['id'] ?>">
            <div class="panel__head">
                <div class="flex">
                    <div class="avatar" style="width:34px;height:34px;font-size:.82rem"><?= e(strtoupper(mb_substr($w['full_name'], 0, 1))) ?></div>
                    <div>
                        <strong><?= e($w['full_name']) ?></strong>
                        <div class="small muted"><?= e($w['email']) ?> &middot; <?= e($w['phone']) ?></div>
                    </div>
                </div>
                <span class="badge <?= status_class($w['status']) ?>"><?= e($w['status']) ?></span>
            </div>
            <div class="panel__body">
                <div class="grid-3 mb-3">
                    <div><div class="muted small">Amount requested</div><strong style="font-size:1.3rem"><?= money($w['amount']) ?></strong></div>
                    <div><div class="muted small">Method</div><strong><?= e($w['method']) ?></strong></div>
                    <div><div class="muted small">Requested</div><strong><?= e(date('M j, Y g:ia', strtotime($w['requested_at']))) ?></strong></div>
                </div>
                <p class="small mb-2"><span class="muted">Payment details:</span> <?= e($w['account_details']) ?></p>
                <?php if ($w['status'] === 'pending'):
                    // What the automatic checks found about this creator, before any money leaves (Admin > Checks).
                    $wRisk = 0; $wLinked = [];
                    try {
                        $wRisk = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND risk_level = "high" AND risk_ok = 0 AND status <> "rejected"', [(int)$w['user_id']], 0);
                        require_once __DIR__ . '/../includes/integrity.php';
                        $wLinked = integrity_linked_accounts((int)$w['user_id']);
                    } catch (Throwable $e) {
                    }
                    if ($wRisk > 0 || $wLinked): ?>
                    <div class="flash flash--warning" style="margin:8px 0">
                        <?php if ($wRisk > 0): ?><div><strong><?= $wRisk ?> clip<?= $wRisk === 1 ? '' : 's' ?> of this creator look<?= $wRisk === 1 ? 's' : '' ?> like bought views.</strong> <a href="<?= e(u('admin/risk.php')) ?>">Look before paying</a></div><?php endif; ?>
                        <?php if ($wLinked): ?><div>Same phone as: <?= e(implode(', ', array_column($wLinked, 'full_name'))) ?>. <a href="<?= e(u('admin/risk.php?tab=phones')) ?>">See</a></div><?php endif; ?>
                    </div>
                <?php endif; endif; ?>
                <?php if ($w['status'] === 'pending' && !empty($w['paid_to_number'])):
                    $others = fetch_all('SELECT DISTINCT u.id, u.full_name FROM withdrawals x JOIN users u ON u.id = x.user_id WHERE x.paid_to_number = ? AND x.user_id <> ? AND x.status <> "rejected" LIMIT 5', [(string)$w['paid_to_number'], (int)$w['user_id']]);
                    if ($others): ?>
                    <p class="flash flash--warning small" style="margin:0 0 10px">This mobile number was also used for payouts by: <?php foreach ($others as $i => $o): ?><?= $i ? ', ' : '' ?><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$o['id'])) ?>"><?= e($o['full_name']) ?></a><?php endforeach; ?>. Check it is not one person with several accounts.</p>
                <?php endif; endif; ?>
                <?php if ($w['admin_note']): ?><p class="small" style="color:#92400e">Note: <?= e($w['admin_note']) ?></p><?php endif; ?>
                <?php if ($w['status'] === 'paid'): ?>
                    <div class="grid-3 mb-2">
                        <div><div class="muted small">Provider</div><strong class="small"><?= provider_badge($w['payment_provider']) ?></strong></div>
                        <div><div class="muted small">Txn ref</div><strong class="small"><?= e($w['txn_reference']) ?></strong></div>
                        <div><div class="muted small">Sent to</div><strong class="small"><?= e($w['paid_to_number'] ?: '—') ?></strong></div>
                    </div>
                    <?php $rcpt = payproof_receipt_text($w); $rcBad = ($w['receipt'] ?? '') === 'not_received'; ?>
                    <?php if ($rcpt !== ''): ?><p class="flash flash--<?= $rcBad ? 'error' : (in_array($w['receipt'] ?? '', ['received', 'auto'], true) ? 'success' : 'info') ?> small" style="margin:0 0 8px"><?= e($rcpt) ?></p><?php endif; ?>
                    <?php if (!empty($w['proof_file'])): ?>
                        <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/withdrawals.php?proof=' . (int)$w['id'])) ?>" target="_blank" rel="noopener">See payment proof</a>
                    <?php elseif (!empty($w['receipt'])): ?>
                        <form method="post" enctype="multipart/form-data" class="flex flex-wrap" style="gap:8px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="add_proof"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                            <span class="small muted">No proof yet:</span><input class="input" type="file" name="proof" accept="image/*" required style="max-width:240px"><button class="btn btn--ghost btn--sm" type="submit">Add the screenshot</button></form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($w['status'] === 'pending'): ?>
                    <form method="post" class="mt-2" enctype="multipart/form-data">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="paid">
                        <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                        <div class="grid-3 mb-2">
                            <div class="field">
                                <label class="label">Provider</label>
                                <select class="select" name="payment_provider" required>
                                        <option value="">Select...</option>
                                        <?php foreach (mobile_providers() as $key => $p): ?>
                                            <option value="<?= e($key) ?>" <?= $w['payment_provider'] === $key ? 'selected' : '' ?>><?= e($p['label']) ?></option>
                                        <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="field">
                                <label class="label">Transaction reference</label>
                                <input class="input" name="txn_reference" placeholder="e.g. MTN-9F2C41A" required>
                            </div>
                            <div class="field">
                                <label class="label">Sent to number</label>
                                <input class="input" name="paid_to_number" value="<?= e($w['paid_to_number'] ? zm_phone_pretty((string)$w['paid_to_number']) : '') ?>" placeholder="097 123 4567">
                            </div>
                        </div>
                        <div class="field mb-2">
                            <label class="label">Screenshot of the "sent" message (optional)</label>
                            <input class="input" type="file" name="proof" accept="image/*">
                            <div class="field__hint">Not needed every time. The creator confirms "I got it". Add a screenshot when someone says the money did not arrive.</div>
                        </div>
                        <div class="field mb-2">
                            <label class="label">Payment note (optional)</label>
                            <input class="input" name="admin_note" placeholder="Internal note for the record">
                        </div>
                        <div class="flex flex-wrap" style="gap:10px;align-items:center">
                            <button class="btn btn--ok btn--sm" type="submit">&#10003; Record payment</button>
                            <a class="btn btn--ghost btn--sm" href="mailto:<?= e($w['email']) ?>?subject=Your%20HPL%20Creators%20payout">&#9993; Email creator</a>
                            <span class="muted small">Creator: <?= e($w['phone']) ?></span>
                        </div>
                    </form>
                    <form method="post" class="flex flex-wrap mt-2" style="gap:8px"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$w['id'] ?>"><input class="input" name="admin_note" placeholder="Reason (optional)" style="flex:1;min-width:130px"><button class="btn btn--danger btn--sm" type="submit">Reject</button></form>
                    <details class="mt-2">
                        <summary class="small">Edit request</summary>
                        <form method="post" class="mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update">
                            <input type="hidden" name="id" value="<?= (int)$w['id'] ?>">
                            <div class="grid-3 mb-2">
                                <div class="field">
                                    <label class="label">Amount (K)</label>
                                    <input class="input" type="number" name="amount" min="1" step="0.01" value="<?= e(number_format((float)$w['amount'], 2, '.', '')) ?>" required>
                                </div>
                                <div class="field">
                                    <label class="label">Method</label>
                                    <input class="input" name="method" value="<?= e($w['method']) ?>" required>
                                </div>
                                <div class="field">
                                    <label class="label">Payment details</label>
                                    <input class="input" name="account_details" value="<?= e($w['account_details']) ?>" required>
                                </div>
                                <div class="field">
                                    <label class="label">Mobile number to pay</label>
                                    <input class="input" name="paid_to_number" value="<?= e($w['paid_to_number'] ? zm_phone_pretty((string)$w['paid_to_number']) : '') ?>" placeholder="097 123 4567">
                                </div>
                            </div>
                            <button class="btn btn--ghost btn--sm" type="submit">Save changes</button>
                        </form>
                    </details>
                <?php else: ?>
                    <p class="small muted mb-0">Resolved <?= e(time_ago($w['resolved_at'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
