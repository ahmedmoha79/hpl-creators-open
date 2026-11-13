<?php
require __DIR__ . '/_boot.php';
$me = require_admin_page();
$id = (int)get('id');
$statuses = ['new' => 'New', 'details_sent' => 'Payment details sent', 'paid' => 'Paid', 'packed' => 'Tested and packed', 'shipped' => 'Shipped', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $o = row('SELECT * FROM shop_orders WHERE id = ?', [(int)post('id')]);
    if ($o && post('action') === 'status' && isset($statuses[post('status')])) {
        $st = post('status');
        $note = mb_substr(post('note'), 0, 300);
        q('UPDATE shop_orders SET status = ? WHERE id = ?', [$st, $o['id']]);
        q('INSERT INTO shop_order_events (order_id, status, note, by_admin) VALUES (?,?,?,?)', [$o['id'], $st, $note, $me['id']]);
        if ($o['chat_id'] && post('tell') === '1' && ($chat = chat_by_id((int)$o['chat_id']))) {
            $l = $o['lang'];
            $msg = t_lang($l, 'order.update_msg', ['ref' => $o['ref'], 'status' => t_lang($l, 'ost.' . $st)]) . ($note !== '' ? "\n" . $note : '') . "\n" . abs_url('track/' . $o['ref'] . '?k=' . $o['secret']);
            chat_add($chat, 'admin', $msg, null, (int)$me['id']);
        }
        flash('ok', 'Order ' . $o['ref'] . ' is now "' . $statuses[$st] . '".');
    }
    if ($o && post('action') === 'price') {
        $unit = (float)post('unit_price');
        q('UPDATE shop_orders SET unit_price = ?, total = ?, qty = ? WHERE id = ?', [$unit ?: null, $unit ? $unit * max(1, (int)post('qty')) : null, max(1, (int)post('qty')), $o['id']]);
        flash('ok', 'Price updated.');
    }
    redirect('admin/orders.php?id=' . (int)post('id'));
}

/** Text in a given language (for messages to a buyer who chose French or Swahili). */
function t_lang(string $l, string $key, array $vars = []): string
{
    $pack = is_file(STORE_ROOT . "/lang/$l.php") ? (array)require STORE_ROOT . "/lang/$l.php" : [];
    $en = (array)require STORE_ROOT . '/lang/en.php';
    $s = $pack[$key] ?? $en[$key] ?? $key;
    foreach ($vars as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

admin_head('Orders', 'orders.php');
if ($id && ($o = row('SELECT * FROM shop_orders WHERE id = ?', [$id]))):
    $events = rows('SELECT e.*, u.name AS full_name FROM shop_order_events e LEFT JOIN shop_admins u ON u.id = e.by_admin WHERE order_id = ? ORDER BY e.id', [$id]);
?>
<p><a href="<?= e(url('admin/orders.php')) ?>">&larr; All orders</a></p>
<h1><?= e($o['ref']) ?> <span class="a-st a-st--<?= e($o['status']) ?>"><?= e($statuses[$o['status']]) ?></span></h1>
<div class="a-cols">
    <section class="card">
        <dl class="facts">
            <dt>Product</dt><dd><?= e($o['product_name']) ?> × <?= (int)$o['qty'] ?></dd>
            <dt>Price</dt><dd><?= $o['total'] ? e(money($o['total'], $o['currency'] ?? 'USD')) : 'not set' ?> <span class="muted small">(delivery is quoted in the chat)</span></dd>
            <dt>Customer</dt><dd><?= e($o['customer_name']) ?></dd>
            <dt>Phone</dt><dd><a href="tel:<?= e($o['phone']) ?>"><?= e($o['phone']) ?></a> · <a href="https://wa.me/<?= e(preg_replace('~\D~', '', $o['phone'])) ?>" target="_blank" rel="noopener">WhatsApp</a></dd>
            <?php if ($o['email']): ?><dt>Email</dt><dd><a href="mailto:<?= e($o['email']) ?>"><?= e($o['email']) ?></a></dd><?php endif; ?>
            <dt>Where</dt><dd><?= e(trim($o['city'] . ', ' . $o['country'], ', ')) ?></dd>
            <dt>Delivery</dt><dd><?= e(['ship' => 'Deliver to their town', 'pickup' => 'Collects in Lusaka', 'agent' => 'Agent collects'][$o['delivery']]) ?></dd>
            <dt>Payment</dt><dd><?= e(pay_methods()[$o['pay_method']] ?? '') ?><?php if ($o['pay_method'] === 'bank' && $o['total']): ?> <span class="muted small">(<?= e(zmw($o['total'])) ?> to the ZMW account, or <?= e(usd(zmw_to_usd($o['total']))) ?> to the USD account)</span><?php endif; ?></dd>
            <?php if (!empty($o['proof'])): ?><dt>Bank slip</dt><dd><a class="btn btn--line btn--sm" href="<?= e(url('admin/proof.php?id=' . $o['id'])) ?>" target="_blank" rel="noopener">Open the buyer's slip</a> <span class="muted small">sent <?= e(date('j M H:i', strtotime((string)$o['proof_at']))) ?></span></dd><?php endif; ?>
            <dt>Language</dt><dd><?= e(STORE_LANGS[$o['lang']] ?? $o['lang']) ?></dd>
            <?php if ($o['note']): ?><dt>Note</dt><dd><?= nl2br(e($o['note'])) ?></dd><?php endif; ?>
            <dt>Placed</dt><dd><?= e(date('j M Y H:i', strtotime($o['created_at']))) ?></dd>
        </dl>
        <p style="margin-top:16px;display:flex;gap:8px;flex-wrap:wrap">
            <?php if ($o['chat_id']): ?><a class="btn btn--chat" href="<?= e(url('admin/chat.php?id=' . $o['chat_id'])) ?>">Open the chat</a><?php endif; ?>
            <a class="btn btn--line" href="<?= e(url('track/' . $o['ref'] . '?k=' . $o['secret'])) ?>" target="_blank" rel="noopener">Buyer's tracking page</a>
        </p>
        <form method="post" class="form" style="margin-top:18px;grid-template-columns:1fr 1fr auto;align-items:end;display:grid;gap:10px">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="price">
            <div class="field"><label>Unit price (<?= e(($o['currency'] ?? 'ZMW') === 'USD' ? 'USD' : 'kwacha') ?>)</label><input class="input" name="unit_price" type="number" step="0.01" value="<?= e((string)$o['unit_price']) ?>"></div>
            <div class="field"><label>Quantity</label><input class="input" name="qty" type="number" min="1" value="<?= (int)$o['qty'] ?>"></div>
            <button class="btn btn--line" type="submit">Save price</button>
        </form>
    </section>
    <section class="card">
        <h2>Move the order forward</h2>
        <?php if ($o['pay_method'] === 'bank' && in_array($o['status'], ['new', 'details_sent'], true)): ?>
            <form method="post" style="margin-bottom:16px">
                <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="status"><input type="hidden" name="status" value="paid"><input type="hidden" name="tell" value="1">
                <p class="small muted">Check your Absa account first. Only tap this when the money is really in the account<?= !empty($o['proof']) ? ' (a slip alone is not proof: slips can be faked)' : '' ?>.</p>
                <button class="btn btn--buy" type="submit">Payment received</button>
            </form>
        <?php endif; ?>
        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><input type="hidden" name="action" value="status">
            <div class="field"><label for="st">New status</label><select class="input" id="st" name="status"><?php foreach ($statuses as $k => $n): ?><option value="<?= $k ?>"<?= $k === $o['status'] ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label for="nt">Note for the buyer (optional, e.g. tracking number)</label><input class="input" id="nt" name="note" maxlength="300"></div>
            <label class="choice"><input type="checkbox" name="tell" value="1" checked><span>Tell the buyer in the chat (in their language, with their tracking link)</span></label>
            <button class="btn btn--dark" type="submit">Save status</button>
        </form>
        <h3 style="margin-top:22px">History</h3>
        <ol class="timeline">
            <?php foreach ($events as $ev): ?><li class="done"><strong><?= e($statuses[$ev['status']] ?? ($ev['status'] === 'slip' ? 'Buyer sent a bank slip' : $ev['status'])) ?></strong><small><?= e(date('j M H:i', strtotime($ev['created_at']))) ?><?= $ev['full_name'] ? ' · ' . e($ev['full_name']) : '' ?><?= $ev['note'] ? ' · ' . e($ev['note']) : '' ?></small></li><?php endforeach; ?>
        </ol>
    </section>
</div>
<?php else:
    $f = get('status');
    $list = rows('SELECT * FROM shop_orders' . (isset($statuses[$f]) ? ' WHERE status = ?' : '') . ' ORDER BY id DESC LIMIT 300', isset($statuses[$f]) ? [$f] : []);
?>
<h1>Orders</h1>
<div class="filters">
    <a class="chip" href="<?= e(url('admin/orders.php')) ?>"<?= $f === '' ? ' aria-current="true"' : '' ?>>All</a>
    <?php foreach ($statuses as $k => $n): ?><a class="chip" href="<?= e(url('admin/orders.php?status=' . $k)) ?>"<?= $f === $k ? ' aria-current="true"' : '' ?>><?= e($n) ?></a><?php endforeach; ?>
</div>
<div class="a-table-wrap"><table class="a-table">
    <thead><tr><th>Order</th><th>Product</th><th>Customer</th><th>Country</th><th>Payment</th><th>Total</th><th>Status</th><th>Placed</th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="8" class="muted">No orders here yet.</td></tr><?php endif; ?>
    <?php foreach ($list as $o): ?>
        <tr><td><a href="<?= e(url('admin/orders.php?id=' . $o['id'])) ?>"><strong><?= e($o['ref']) ?></strong></a></td><td><?= e($o['product_name']) ?><?= $o['qty'] > 1 ? ' × ' . (int)$o['qty'] : '' ?></td>
            <td><?= e($o['customer_name']) ?><br><span class="muted small"><?= e($o['phone']) ?></span></td><td><?= e($o['country']) ?></td>
            <td><?= e(pay_methods()[$o['pay_method']] ?? '') ?></td><td><?= $o['total'] ? e(money($o['total'], $o['currency'] ?? 'USD')) : '–' ?></td>
            <td><span class="a-st a-st--<?= e($o['status']) ?>"><?= e($statuses[$o['status']]) ?></span></td><td class="small"><?= e(date('j M H:i', strtotime($o['created_at']))) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php endif;
admin_foot();
