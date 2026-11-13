<?php
require STORE_ROOT . '/inc/layout.php';
require STORE_ROOT . '/inc/chat.php';
$co = company();
$p = product_by_slug(get('product', post('product')));
$err = '';
$v = ['name' => '', 'phone' => '', 'email' => '', 'country' => '', 'city' => '', 'delivery' => 'ship', 'pay' => 'bank', 'note' => '', 'qty' => '1'];
$chat = chat_from_cookie();
if ($chat) {
    $v['name'] = $chat['name'];
    $v['phone'] = $chat['phone'];
    $v['country'] = $chat['country'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($v as $k => $_) $v[$k] = mb_substr(post($k), 0, $k === 'note' ? 1000 : 120);
    $expired = !csrf_ok();
    $qty = max(1, min(20, (int)$v['qty']));
    if (post('website') !== '') { // bot trap
        redirect('');
    }
    if ($expired) {
        $err = t('order.expired'); // the page sat open too long; the form keeps what they typed
    } elseif ($p && $p['stock'] === 'out') {
        $err = t('order.sold_out');
    } elseif (!$p || $v['name'] === '' || strlen(preg_replace('~\D~', '', $v['phone'])) < 7 || $v['country'] === '') {
        $err = t('order.err');
    } elseif (!array_key_exists($v['pay'], pay_methods()) || !in_array($v['delivery'], ['ship', 'pickup', 'agent'], true)) {
        $err = t('order.err');
    } elseif (!rate_ok('order', 20, 3600)) {
        $err = t('order.too_many');
    } else {
        $ref = new_order_ref();
        $secret = bin2hex(random_bytes(12));
        $unit = (float)$p['price_zmw'] > 0 ? (float)$p['price_zmw'] : null;
        if (!$chat) {
            $chat = chat_create($v['name'], $v['phone'], $v['country'], '/product/' . $p['slug'], (int)$p['id']);
        }
        q('INSERT INTO shop_orders (ref, secret, product_id, product_name, qty, unit_price, total, currency, customer_name, phone, email, country, city, pay_method, delivery, note, lang, chat_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$ref, $secret, $p['id'], product_title($p), $qty, $unit, $unit ? $unit * $qty : null, 'ZMW', $v['name'], $v['phone'], $v['email'], $v['country'], $v['city'],
             $v['pay'], $v['delivery'], $v['note'], lang(), $chat['id']]);
        $oid = (int)db()->lastInsertId();
        track_link('order', $ref, $v['name'], $v['phone']);
        q('INSERT INTO shop_order_events (order_id, status, note) VALUES (?, "new", "")', [$oid]);
        if ($v['pay'] === 'bank' && bank_details()['acc_zmw'] !== '') { // the bank details show on the order page straight away
            q('UPDATE shop_orders SET status = "details_sent" WHERE id = ?', [$oid]);
            q('INSERT INTO shop_order_events (order_id, status, note) VALUES (?, "details_sent", ?)', [$oid, t('bank.shown_note')]);
        }
        $summary = t('order.chat_msg', [
            'ref' => $ref, 'product' => product_title($p) . ($qty > 1 ? ' x' . $qty : ''),
            'pay' => pay_methods()[$v['pay']], 'to' => trim($v['city'] . ', ' . $v['country'], ', '),
        ]);
        chat_add($chat, 'visitor', $summary . ($v['note'] !== '' ? "\n\n" . $v['note'] : ''), null, null, false);
        team_alert("New ORDER $ref on the HPL website\n" . product_title($p) . ($qty > 1 ? " x$qty" : '') . ($unit ? ' (' . zmw($unit * $qty) . ')' : '') .
            "\n{$v['name']}, {$v['phone']}\n" . trim($v['city'] . ', ' . $v['country'], ', ') . "\nPayment: " . pay_methods()[$v['pay']] .
            ($v['pay'] === 'bank' ? ' - the buyer sees the Absa details on the website' : ($v['pay'] !== 'showroom' ? ' - send the payment details' : '')) . "\n\nOpen: " . abs_url('admin/orders.php?id=' . $oid));
        redirect('track/' . $ref . '?k=' . $secret . '&new=1#chat');
    }
}

page_head(t('order.h1') . ($p ? ': ' . $p['name'] : ''), t('order.sub'), ['nav' => '', 'noindex' => true]);
?>
<div class="phead"><div class="wrap"><h1><?= e(t('order.h1')) ?></h1><p><?= e(t('order.sub')) ?></p></div></div>
<section class="sec">
    <div class="wrap split">
        <div class="card">
            <?php if (!$p): ?>
                <p><?= e(t('order.pick')) ?></p>
                <a class="btn btn--dark" href="<?= e(url('shop')) ?>"><?= e(t('shop.h1')) ?></a>
            <?php else: ?>
                <?php if ($err): ?><div class="alert alert--err" role="alert"><?= e($err) ?></div><?php endif; ?>
                <form class="form" method="post" data-order-form novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="product" value="<?= e($p['slug']) ?>">
                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px">
                    <div class="ordersum">
                        <img src="<?= e(product_image($p, 200)) ?>" alt="">
                        <div><strong><?= e(product_title($p)) ?></strong><br><span class="price" style="font-size:20px"><?= e(price_parts($p)['main'] ?? t('price.ask')) ?></span> <span class="muted small"><?= e(t('order.delivery_tbc')) ?></span></div>
                    </div>
                    <div class="field" style="max-width:140px"><label for="o-qty"><?= e(t('prod.qty')) ?></label><input class="input" id="o-qty" type="number" name="qty" min="1" max="20" value="<?= e($v['qty']) ?>"></div>
                    <div class="field"><label for="o-name"><?= e(t('order.name')) ?></label><input class="input" id="o-name" name="name" required autocomplete="name" value="<?= e($v['name']) ?>"></div>
                    <div class="row2">
                        <div class="field"><label for="o-phone"><?= e(t('order.phone')) ?></label><input class="input" id="o-phone" name="phone" required inputmode="tel" autocomplete="tel" placeholder="+243 …" value="<?= e($v['phone']) ?>"></div>
                        <div class="field"><label for="o-email"><?= e(t('order.email')) ?></label><input class="input" id="o-email" name="email" type="email" autocomplete="email" value="<?= e($v['email']) ?>"></div>
                    </div>
                    <div class="row2">
                        <div class="field"><label for="o-country"><?= e(t('order.country')) ?></label>
                            <select class="input" id="o-country" name="country" required>
                                <option value=""></option>
                                <?php foreach (countries() as $c): ?><option<?= $v['country'] === $c ? ' selected' : '' ?>><?= e($c) ?></option><?php endforeach; ?>
                            </select></div>
                        <div class="field"><label for="o-city"><?= e(t('order.city')) ?></label><input class="input" id="o-city" name="city" autocomplete="address-level2" value="<?= e($v['city']) ?>"></div>
                    </div>
                    <fieldset class="field" style="border:0;padding:0;margin:0">
                        <legend class="label"><?= e(t('order.delivery')) ?></legend>
                        <div class="choices">
                            <?php foreach (['ship', 'pickup', 'agent'] as $d): ?>
                                <label class="choice"><input type="radio" name="delivery" value="<?= $d ?>"<?= $v['delivery'] === $d ? ' checked' : '' ?>><span><?= e(t('del.' . $d)) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                    <fieldset class="field" style="border:0;padding:0;margin:0">
                        <legend class="label"><?= e(t('order.pay')) ?></legend>
                        <div class="choices">
                            <?php foreach (pay_methods() as $k => $label): ?>
                                <label class="choice"><input type="radio" name="pay" value="<?= $k ?>"<?= $v['pay'] === $k ? ' checked' : '' ?>><span><?= e($label) ?></span></label>
                            <?php endforeach; ?>
                        </div>
                        <p class="small muted" data-pay-hint="western_union moneygram"><?= e(t('order.wu_hint')) ?></p>
                        <p class="small muted" data-pay-hint="bank"><?= e(t('order.bank_hint')) ?></p>
                        <p class="small muted" data-pay-hint="showroom"><?= e(t('order.showroom_hint')) ?></p>
                    </fieldset>
                    <div class="field"><label for="o-note"><?= e(t('order.note')) ?></label><textarea class="input" id="o-note" name="note"><?= e($v['note']) ?></textarea></div>
                    <button class="btn btn--buy btn--lg btn--block" type="submit" data-order-submit data-t-send="<?= e(t('order.submit')) ?>" data-t-request="<?= e(t('order.request_details')) ?>"><?= e(t('order.request_details')) ?></button>
                    <p class="small muted"><?= e(t('order.after')) ?></p>
                </form>
            <?php endif; ?>
        </div>
        <div>
            <div class="card">
                <h3><?= e(t('order.who_h')) ?></h3>
                <dl class="facts">
                    <dt><?= e(t('order.company')) ?></dt><dd><?= e($co['legal']) ?></dd>
                    <dt>PACRA</dt><dd><?= e($co['pacra']) ?></dd>
                    <dt>TPIN</dt><dd><?= e($co['tpin']) ?></dd>
                    <dt><?= e(t('ledger.showroom')) ?></dt><dd><?= e(trim($co['address'] . ', ' . $co['city'], ', ')) ?></dd>
                </dl>
                <p class="small muted" style="margin-top:14px"><?= e(t('how.safe_1')) ?></p>
                <p><a href="<?= e(url('verify')) ?>"><?= e(t('nav.verify')) ?></a></p>
            </div>
        </div>
    </div>
</section>
<?php page_foot();
