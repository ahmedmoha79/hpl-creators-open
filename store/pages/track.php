<?php
require STORE_ROOT . '/inc/layout.php';
require_once STORE_ROOT . '/inc/chat.php';
$ref = strtoupper(preg_replace('~[^A-Za-z0-9-]~', '', (string)($ARGS[0] ?? get('ref'))));
if ($ref !== '') {
    $ref = 'HPL-' . strtr(preg_replace('~^HPL-?~', '', $ref), ['O' => '0', 'I' => '1', 'L' => '1']); // people type 0 for O
}
$o = null;
$err = '';
if ($ref !== '') {
    $cand = row('SELECT * FROM shop_orders WHERE ref = ?', [$ref]);
    $k = get('k');
    $phone = preg_replace('~\D~', '', get('phone'));
    if ($cand && $k !== '' && hash_equals($cand['secret'], $k)) {
        $o = $cand;
    } elseif ($phone !== '') {
        // Guessing protection: per connection and per order number, checked before comparing.
        if (!rate_ok('track', 15, 3600) || !rate_ok('track_ref_' . $ref, 8, 3600)) {
            $err = t('order.too_many');
        } elseif ($cand && strlen($phone) >= 6 && substr(preg_replace('~\D~', '', $cand['phone']), -6) === substr($phone, -6)) {
            $o = $cand;
        } else {
            $err = t('track.not_found');
        }
    } elseif ($k !== '') {
        $err = t('track.not_found');
    }
}
$proofMsg = '';
if ($o && $_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'proof') {
    if (!csrf_ok() || !hash_equals($o['secret'], post('k'))) {
        $proofMsg = 'err:' . t('order.expired');
    } elseif (!rate_ok('proof', 10, 3600)) {
        $proofMsg = 'err:' . t('order.too_many');
    } elseif (!($file = save_proof($_FILES['slip'] ?? []))) {
        $proofMsg = 'err:' . t('bank.slip_bad');
    } else {
        q('UPDATE shop_orders SET proof = ?, proof_at = NOW() WHERE id = ?', [$file, $o['id']]);
        q('INSERT INTO shop_order_events (order_id, status, note) VALUES (?, "slip", "")', [$o['id']]);
        $amount = $o['total'] ? zmw($o['total']) . ' (or ' . usd(zmw_to_usd($o['total'])) . ')' : 'price not set';
        team_alert("PAYMENT SLIP for order {$o['ref']}\n{$o['product_name']}, $amount\n{$o['customer_name']}, {$o['phone']}, {$o['country']}\n\nCheck the Absa account, then open the order and tap Payment received:\n" . abs_url('admin/orders.php?id=' . $o['id']));
        if ($o['chat_id'] && function_exists('chat_by_id') && ($chat = chat_by_id((int)$o['chat_id']))) {
            chat_add($chat, 'visitor', t('bank.slip_chat', ['ref' => $o['ref']]), null, null, false);
        }
        redirect('track/' . $o['ref'] . '?k=' . $o['secret'] . '&slip=1#bank');
    }
}
if ($o && get('slip') === '1') $proofMsg = 'ok:' . t('bank.slip_ok');
page_head($o ? t('order.done_h', ['ref' => $o['ref']]) : t('track.h1'), '', ['nav' => 'track', 'noindex' => true]);
if (!$o): ?>
<div class="phead"><div class="wrap"><h1><?= e(t('track.h1')) ?></h1><p><?= e(t('track.sub')) ?></p></div></div>
<section class="sec sec--tight"><div class="wrap" style="max-width:560px">
    <?php if ($err): ?><div class="alert alert--err" role="alert"><?= e($err) ?></div><?php endif; ?>
    <form class="form card" method="get" action="<?= e(url('track')) ?>">
        <div class="field"><label for="t-ref"><?= e(t('track.ref')) ?></label><input class="input" id="t-ref" name="ref" required value="<?= e($ref) ?>" autocapitalize="characters"></div>
        <div class="field"><label for="t-phone"><?= e(t('track.phone')) ?></label><input class="input" id="t-phone" name="phone" required inputmode="tel"></div>
        <button class="btn btn--dark btn--lg" type="submit"><?= e(t('track.go')) ?></button>
    </form>
</div></section>
<?php else:
    $steps = order_steps();
    $events = rows('SELECT status, note, created_at FROM shop_order_events WHERE order_id = ? ORDER BY id', [$o['id']]);
    $at = [];
    foreach ($events as $ev) { $at[$ev['status']] = $ev; }
    $keys = array_keys($steps);
    $cur = array_search($o['status'], $keys, true);
    if ($o['pay_method'] === 'showroom') { unset($steps['details_sent']); $keys = array_keys($steps); $cur = array_search($o['status'], $keys, true); }
?>
<div class="phead"><div class="wrap">
    <?php if (get('new') === '1'): ?><div class="alert alert--ok" role="status"><?= e(t('order.done_d', ['name' => explode(' ', $o['customer_name'])[0]])) ?></div><?php endif; ?>
    <p class="crumbs"><?= e(t('track.h1')) ?></p>
    <p class="refbox"><?= e($o['ref']) ?></p>
    <p><?= e(t('track.placed', ['date' => date('j M Y, H:i', strtotime($o['created_at']))])) ?></p>
</div></div>
<?php
$bank = bank_details();
$showBank = $o['pay_method'] === 'bank' && $bank['acc_zmw'] !== '' && in_array($o['status'], ['new', 'details_sent'], true);
if ($showBank):
    $usdAmt = $o['total'] ? zmw_to_usd($o['total']) : 0;
    $copy = fn($v) => '<button class="btn btn--line btn--sm" type="button" data-copy="' . e($v) . '" data-copied="' . e(t('verify.copied')) . '">' . e(t('bank.copy')) . '</button>';
?>
<section class="sec sec--tight" id="bank">
    <div class="wrap">
        <div class="card bankcard">
            <h2><?= e(t('bank.h')) ?></h2>
            <p><?= e(t('bank.intro')) ?></p>
            <?php if ($proofMsg !== ''): [$kind, $msg] = explode(':', $proofMsg, 2); ?><div class="alert alert--<?= $kind === 'ok' ? 'ok' : 'err' ?>" role="status"><?= e($msg) ?></div><?php endif; ?>
            <div class="bankcard__ref"><span><?= e(t('bank.ref')) ?></span><strong><?= e($o['ref']) ?></strong><?= $copy($o['ref']) ?></div>
            <div class="bankcard__accs">
                <div class="bankacc">
                    <h3><?= e(t('bank.zmw_h')) ?></h3>
                    <?php if ($o['total']): ?><p class="bankacc__amt"><?= e(zmw($o['total'])) ?></p><?php endif; ?>
                    <dl class="facts">
                        <dt><?= e(t('bank.beneficiary')) ?></dt><dd><?= e($bank['beneficiary']) ?></dd>
                        <dt><?= e(t('bank.bank')) ?></dt><dd><?= e($bank['name']) ?></dd>
                        <dt><?= e(t('bank.acc')) ?></dt><dd><?= e($bank['acc_zmw']) ?> <?= $copy($bank['acc_zmw']) ?></dd>
                        <dt><?= e(t('bank.branch')) ?></dt><dd><?= e($bank['branch']) ?></dd>
                        <dt><?= e(t('bank.sort')) ?></dt><dd><?= e($bank['sort']) ?></dd>
                        <dt>SWIFT</dt><dd><?= e($bank['swift']) ?></dd>
                    </dl>
                </div>
                <?php if ($bank['acc_usd'] !== ''): ?>
                <div class="bankacc">
                    <h3><?= e(t('bank.usd_h')) ?></h3>
                    <?php if ($usdAmt): ?><p class="bankacc__amt">US<?= e(usd($usdAmt)) ?></p><?php endif; ?>
                    <dl class="facts">
                        <dt><?= e(t('bank.beneficiary')) ?></dt><dd><?= e($bank['beneficiary']) ?></dd>
                        <dt><?= e(t('bank.bank')) ?></dt><dd><?= e($bank['name']) ?></dd>
                        <dt><?= e(t('bank.acc')) ?></dt><dd><?= e($bank['acc_usd']) ?> <?= $copy($bank['acc_usd']) ?></dd>
                        <dt><?= e(t('bank.branch')) ?></dt><dd><?= e($bank['branch']) ?></dd>
                        <dt><?= e(t('bank.sort')) ?></dt><dd><?= e($bank['sort']) ?></dd>
                        <dt>SWIFT</dt><dd><?= e($bank['swift']) ?> <?= $copy($bank['swift']) ?></dd>
                        <?php if ($bank['corr_bank'] !== ''): ?><dt><?= e(t('bank.corr')) ?></dt><dd><?= e($bank['corr_bank']) ?><?= $bank['corr_swift'] !== '' ? ', SWIFT ' . e($bank['corr_swift']) : '' ?></dd><?php endif; ?>
                    </dl>
                    <p class="small muted"><?= e(t('bank.usd_note')) ?></p>
                </div>
                <?php endif; ?>
            </div>
            <ul class="bankcard__notes small">
                <?php if ($o['delivery'] === 'ship'): ?><li><?= e(t('bank.note_delivery')) ?></li><?php endif; ?>
                <li><?= e(t('bank.note_safe', ['name' => $bank['beneficiary']])) ?></li>
            </ul>
            <?php if (!empty($o['proof'])): ?>
                <p class="alert alert--ok"><?= e(t('bank.slip_have', ['date' => date('j M, H:i', strtotime((string)$o['proof_at']))])) ?></p>
            <?php endif; ?>
            <form class="form bankcard__slip" method="post" enctype="multipart/form-data" action="<?= e(url('track/' . $o['ref'])) ?>?k=<?= e($o['secret']) ?>">
                <?= csrf_field() ?><input type="hidden" name="action" value="proof"><input type="hidden" name="k" value="<?= e($o['secret']) ?>">
                <div class="field"><label for="slip"><?= e(t(!empty($o['proof']) ? 'bank.slip_again' : 'bank.slip_label')) ?></label>
                    <input class="input" id="slip" name="slip" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" required></div>
                <button class="btn btn--buy btn--lg" type="submit"><?= e(t('bank.slip_send')) ?></button>
            </form>
        </div>
    </div>
</section>
<?php endif; ?>
<section class="sec sec--tight">
    <div class="wrap split">
        <div class="card">
            <?php if ($o['status'] === 'cancelled'): ?>
                <p class="alert alert--err"><?= e(t('ost.cancelled')) ?></p>
            <?php else: ?>
            <ol class="timeline">
                <?php foreach ($keys as $i => $k): $done = $cur !== false && $i <= $cur; ?>
                    <li class="<?= $done ? 'done' : '' ?><?= $i === $cur ? ' now' : '' ?>">
                        <strong><?= e($steps[$k]) ?></strong>
                        <?php if (isset($at[$k])): ?><small><?= e(date('j M, H:i', strtotime($at[$k]['created_at']))) ?><?= $at[$k]['note'] !== '' ? ' · ' . e($at[$k]['note']) : '' ?></small><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ol>
            <?php endif; ?>
        </div>
        <div class="card">
            <dl class="facts">
                <dt><?= e(t('order.product')) ?></dt><dd><?= e($o['product_name']) ?><?= $o['qty'] > 1 ? ' × ' . (int)$o['qty'] : '' ?></dd>
                <?php if ($o['total']): ?><dt><?= e(t('order.total')) ?></dt><dd><?= e(money($o['total'], $o['currency'] ?? 'USD')) ?></dd><?php endif; ?>
                <dt><?= e(t('order.pay')) ?></dt><dd><?= e(pay_methods()[$o['pay_method']] ?? $o['pay_method']) ?></dd>
                <dt><?= e(t('order.delivery')) ?></dt><dd><?= e(t('del.' . $o['delivery'])) ?><?= $o['delivery'] === 'ship' ? ', ' . e(trim($o['city'] . ', ' . $o['country'], ', ')) : '' ?></dd>
            </dl>
            <p style="margin-top:18px"><button class="btn btn--chat btn--block" type="button" data-chat-open><?= e(t('track.chat')) ?></button></p>
            <p class="small muted"><?= e(t('how.safe_1')) ?></p>
        </div>
    </div>
</section>
<?php endif;
page_foot();
