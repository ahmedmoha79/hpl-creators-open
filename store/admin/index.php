<?php
require __DIR__ . '/_boot.php';
$u = require_admin_page();
$c = admin_counts();
$stats = [
    'Open chats waiting for a reply' => $c['chats'],
    'New orders' => $c['orders'],
    'Orders this month' => (int)val('SELECT COUNT(*) FROM shop_orders WHERE created_at >= DATE_FORMAT(NOW(), "%Y-%m-01")', [], 0),
    'Chats this week' => (int)val('SELECT COUNT(*) FROM shop_chats WHERE created_at >= NOW() - INTERVAL 7 DAY', [], 0),
];
$chats = rows('SELECT * FROM shop_chats WHERE status = "open" AND last_msg_at IS NOT NULL ORDER BY unread_admin > 0 DESC, last_msg_at DESC LIMIT 8');
$orders = rows('SELECT * FROM shop_orders ORDER BY id DESC LIMIT 8');
$missing = [];
$co = company();
foreach (['phone' => 'Phone number', 'whatsapp' => 'WhatsApp number', 'address' => 'Showroom street address', 'hours' => 'Opening hours', 'email' => 'Email'] as $k => $n) {
    if ($co[$k] === '') $missing[] = $n;
}
$noVid = (int)val('SELECT COUNT(*) FROM shop_videos WHERE published = 1', [], 0) === 0;
$noImg = (int)val('SELECT COUNT(*) FROM shop_products WHERE published = 1 AND image IS NULL', [], 0);
admin_head('Overview', 'index.php');
?>
<h1>Good <?= (int)date('G') < 12 ? 'morning' : ((int)date('G') < 17 ? 'afternoon' : 'evening') ?>, <?= e(explode(' ', (string)$u['full_name'])[0]) ?></h1>
<?php if ($missing || $noVid || $noImg): ?>
<div class="a-todo">
    <strong>To finish the site</strong>
    <ul>
        <?php if ($missing): ?><li>Add in <a href="<?= e(url('admin/settings.php')) ?>">Settings</a>: <?= e(implode(', ', $missing)) ?>. Buyers trust a full address and a working number.</li><?php endif; ?>
        <?php if ($noImg): ?><li><?= $noImg ?> product<?= $noImg === 1 ? ' has' : 's have' ?> no photo yet. <a href="<?= e(url('admin/products.php')) ?>">Add your own photos</a>: real photos from your shop build more trust than catalogue pictures.</li><?php endif; ?>
        <?php if ($noVid): ?><li>No videos yet. <a href="<?= e(url('admin/videos.php')) ?>">Add videos</a> for the home page, field tests, customers and "how to buy".</li><?php endif; ?>
    </ul>
</div>
<?php endif; ?>
<div class="a-stats">
    <?php foreach ($stats as $k => $v): ?><div class="a-stat"><b><?= (int)$v ?></b><span><?= e($k) ?></span></div><?php endforeach; ?>
</div>
<div class="a-cols">
    <section class="card">
        <div class="a-h"><h2>Latest chats</h2><a href="<?= e(url('admin/chat.php')) ?>">Open inbox</a></div>
        <?php if (!$chats): ?><p class="muted">No chats yet. They appear here the moment a visitor writes.</p><?php endif; ?>
        <ul class="a-list">
            <?php foreach ($chats as $ch): ?>
                <li><a href="<?= e(url('admin/chat.php?id=' . $ch['id'])) ?>"><strong><?= e($ch['name'] ?: 'Visitor') ?></strong><?= $ch['country'] ? ' <span class="muted">· ' . e($ch['country']) . '</span>' : '' ?>
                    <?php if ($ch['unread_admin']): ?><span class="a-count"><?= (int)$ch['unread_admin'] ?></span><?php endif; ?>
                    <br><span class="muted small"><?= e($ch['last_preview']) ?> · <?= e(time_ago($ch['last_msg_at'])) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
    <section class="card">
        <div class="a-h"><h2>Latest orders</h2><a href="<?= e(url('admin/orders.php')) ?>">All orders</a></div>
        <?php if (!$orders): ?><p class="muted">No orders yet.</p><?php endif; ?>
        <ul class="a-list">
            <?php foreach ($orders as $o): ?>
                <li><a href="<?= e(url('admin/orders.php?id=' . $o['id'])) ?>"><strong><?= e($o['ref']) ?></strong> · <?= e($o['product_name']) ?> <span class="a-st a-st--<?= e($o['status']) ?>"><?= e(str_replace('_', ' ', $o['status'])) ?></span>
                    <br><span class="muted small"><?= e($o['customer_name']) ?>, <?= e($o['country']) ?> · <?= e(pay_methods()[$o['pay_method']] ?? '') ?> · <?= e(time_ago($o['created_at'])) ?></span></a></li>
            <?php endforeach; ?>
        </ul>
    </section>
</div>
<?php admin_foot();
