<?php
require __DIR__ . '/_boot.php';
require_admin_page();
$days = in_array((int)get('d', '7'), [1, 7, 30, 90], true) ? (int)get('d', '7') : 7;
$since = date('Y-m-d H:i:s', $days === 1 ? strtotime('today') : time() - $days * 86400);
$vid = (int)get('v');
$evNames = ['whatsapp' => 'WhatsApp taps', 'call' => 'Phone calls', 'chat_open' => 'Chat opened', 'chat' => 'Chats started', 'order' => 'Orders placed',
    'consult' => '"Help me choose" taps', 'request' => '"Request a machine" taps', 'video' => 'Videos played', 'map' => 'Map opened', 'email' => 'Email taps', 'social' => 'TikTok/Facebook taps'];
$flag = function (string $c): string { return e($c !== '' ? $c : 'Unknown'); };
$ago = function (?string $t): string { return $t ? time_ago($t) : ''; };

admin_head('Visitors', 'visitors.php');

if ($vid):
    $v = row('SELECT * FROM shop_visitors WHERE id = ?', [$vid]);
    if (!$v) { echo '<p>Visitor not found.</p>'; admin_foot(); exit; }
    $sess = rows('SELECT * FROM shop_sessions WHERE visitor_id = ? ORDER BY id DESC LIMIT 30', [$vid]);
?>
<p><a href="<?= e(url('admin/visitors.php?d=' . $days)) ?>">&larr; All visitors</a></p>
<h1><?= e($v['name'] !== '' ? $v['name'] : 'Visitor #' . $v['id']) ?></h1>
<div class="a-cols">
    <section class="card">
        <dl class="facts">
            <dt>Where</dt><dd><?= e(trim($v['city'] . ', ' . $v['region'] . ', ' . $v['country'], ', ') ?: 'Unknown') ?></dd>
            <dt>Internet provider</dt><dd><?= e($v['isp'] ?: '–') ?></dd>
            <dt>Device</dt><dd><?= e(trim($v['device'] . ' · ' . $v['model'] . ' · ' . $v['os'] . ' · ' . $v['browser'], ' ·')) ?></dd>
            <dt>Screen / language</dt><dd><?= e($v['screen']) ?> · <?= e($v['lang']) ?> · <?= e($v['tz']) ?></dd>
            <dt>First came from</dt><dd><?= e($v['first_source'] ?: 'direct') ?> → <?= e($v['first_landing']) ?></dd>
            <dt>First / last seen</dt><dd><?= e(date('j M Y H:i', strtotime($v['first_at']))) ?> / <?= e($ago($v['last_at'])) ?></dd>
            <dt>Visits / pages</dt><dd><?= (int)$v['visits'] ?> / <?= (int)$v['pages'] ?></dd>
            <?php if ($v['phone'] !== ''): ?><dt>Phone</dt><dd><a href="https://wa.me/<?= e(preg_replace('~\D~', '', $v['phone'])) ?>" target="_blank" rel="noopener"><?= e($v['phone']) ?></a></dd><?php endif; ?>
            <?php if ($v['chat_id']): ?><dt>Chat</dt><dd><a href="<?= e(url('admin/chat.php?id=' . (int)$v['chat_id'])) ?>">Open the chat</a></dd><?php endif; ?>
            <?php if ($v['order_ref']): ?><dt>Order</dt><dd><?= e($v['order_ref']) ?></dd><?php endif; ?>
            <dt>IP address</dt><dd><?= e($v['ip']) ?></dd>
        </dl>
    </section>
    <section class="card">
        <h2>What they did</h2>
        <?php $evs = rows('SELECT type, label, at FROM shop_events WHERE visitor_id = ? ORDER BY id DESC LIMIT 40', [$vid]); ?>
        <?php if (!$evs): ?><p class="muted">Only looked around so far.</p><?php else: ?>
            <ul class="a-list"><?php foreach ($evs as $ev): ?><li style="padding:8px 0"><b><?= e($evNames[$ev['type']] ?? $ev['type']) ?></b> <span class="muted small"><?= e($ev['label']) ?> · <?= e(date('j M H:i', strtotime($ev['at']))) ?></span></li><?php endforeach; ?></ul>
        <?php endif; ?>
    </section>
</div>
<?php foreach ($sess as $s): $pvs = rows('SELECT path, title, secs, at FROM shop_pageviews WHERE session_id = ? ORDER BY id', [$s['id']]); ?>
    <section class="card" style="margin-bottom:14px">
        <div class="a-h"><h2>Visit on <?= e(date('j M Y, H:i', strtotime($s['started_at']))) ?></h2><span class="muted small">from <?= e($s['source'] ?: 'direct') ?><?= $s['utm_campaign'] !== '' ? ' (' . e($s['utm_campaign']) . ')' : '' ?> · <?= e(trim($s['city'] . ', ' . $s['country'], ', ')) ?> · <?= (int)$s['pages'] ?> pages</span></div>
        <?php if ($s['ref'] !== ''): ?><p class="muted small">Link they came from: <?= e(mb_substr($s['ref'], 0, 160)) ?></p><?php endif; ?>
        <table class="a-table"><tbody><?php foreach ($pvs as $pv): ?><tr><td style="width:70px"><?= e(date('H:i', strtotime($pv['at']))) ?></td><td><a href="<?= e($pv['path']) ?>" target="_blank" rel="noopener"><?= e($pv['title'] ?: $pv['path']) ?></a></td><td class="muted small" style="width:90px"><?= $pv['secs'] ? (int)$pv['secs'] . ' s' : '' ?></td></tr><?php endforeach; ?></tbody></table>
    </section>
<?php endforeach; ?>
<?php admin_foot(); exit; endif;

// ---------- overview ----------
$n = fn(string $sql, array $p = []) => (int)val($sql, $p, 0);
$visitors = $n('SELECT COUNT(DISTINCT visitor_id) FROM shop_sessions WHERE started_at >= ?', [$since]);
$newVis = $n('SELECT COUNT(*) FROM shop_visitors WHERE first_at >= ?', [$since]);
$visits = $n('SELECT COUNT(*) FROM shop_sessions WHERE started_at >= ?', [$since]);
$views = $n('SELECT COUNT(*) FROM shop_pageviews WHERE at >= ?', [$since]);
$avg = $n('SELECT COALESCE(ROUND(AVG(t)),0) FROM (SELECT SUM(secs) t FROM shop_pageviews WHERE at >= ? GROUP BY session_id) x', [$since]);
$live = rows('SELECT s.*, v.name, v.device, v.model, (SELECT title FROM shop_pageviews p WHERE p.session_id = s.id ORDER BY p.id DESC LIMIT 1) AS now_title FROM shop_sessions s JOIN shop_visitors v ON v.id = s.visitor_id WHERE s.last_at > NOW() - INTERVAL 5 MINUTE ORDER BY s.last_at DESC');
$ev = [];
foreach (rows('SELECT type, COUNT(*) n FROM shop_events WHERE at >= ? GROUP BY type', [$since]) as $r) $ev[$r['type']] = (int)$r['n'];
$top = function (string $sql) use ($since) { return rows($sql, [$since]); };
$countries = $top('SELECT IF(country = "", "Unknown", country) k, COUNT(*) n FROM shop_sessions WHERE started_at >= ? GROUP BY k ORDER BY n DESC LIMIT 12');
$cities = $top('SELECT CONCAT(city, ", ", country) k, COUNT(*) n FROM shop_sessions WHERE started_at >= ? AND city <> "" GROUP BY k ORDER BY n DESC LIMIT 12');
$sources = $top('SELECT IF(source = "", "direct", source) k, COUNT(*) n FROM shop_sessions WHERE started_at >= ? GROUP BY k ORDER BY n DESC LIMIT 12');
$pages = $top('SELECT IF(title = "", path, title) k, COUNT(*) n, ROUND(AVG(NULLIF(secs,0))) s FROM shop_pageviews WHERE at >= ? GROUP BY k ORDER BY n DESC LIMIT 15');
$prods = $top('SELECT p.name k, COUNT(*) n FROM shop_pageviews v JOIN shop_products p ON p.id = v.product_id WHERE v.at >= ? GROUP BY p.id ORDER BY n DESC LIMIT 10');
$devices = $top('SELECT CONCAT(v.device, " · ", v.browser) k, COUNT(*) n FROM shop_sessions s JOIN shop_visitors v ON v.id = s.visitor_id WHERE s.started_at >= ? GROUP BY k ORDER BY n DESC LIMIT 10');
$langs = $top('SELECT UPPER(lang) k, COUNT(*) n FROM shop_sessions WHERE started_at >= ? GROUP BY k ORDER BY n DESC');
$recent = rows('SELECT v.*, (SELECT COUNT(*) FROM shop_events e WHERE e.visitor_id = v.id) evs FROM shop_visitors v WHERE v.last_at >= ? ORDER BY v.last_at DESC LIMIT 100', [$since]);
$bar = function (array $list) {
    if (!$list) return '<p class="muted small">Nothing yet.</p>';
    $max = max(array_map(fn($r) => (int)$r['n'], $list)) ?: 1;
    $h = '<ul class="a-bars">';
    foreach ($list as $r) $h .= '<li><span>' . e((string)$r['k']) . (isset($r['s']) && $r['s'] ? ' <small class="muted">' . (int)$r['s'] . ' s</small>' : '') . '</span><b>' . (int)$r['n'] . '</b><i style="width:' . round((int)$r['n'] / $max * 100) . '%"></i></li>';
    return $h . '</ul>';
};
?>
<div class="a-h"><h1>Visitors</h1>
    <div class="chips" style="margin:0"><?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l): ?><a class="chip" href="<?= e(url('admin/visitors.php?d=' . $d)) ?>"<?= $d === $days ? ' aria-current="true"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></div>
</div>
<div class="a-stats">
    <div class="a-stat"><b><?= $visitors ?></b><span>People (<?= $newVis ?> new)</span></div>
    <div class="a-stat"><b><?= $visits ?></b><span>Visits</span></div>
    <div class="a-stat"><b><?= $views ?></b><span>Pages viewed</span></div>
    <div class="a-stat"><b><?= $avg >= 60 ? floor($avg / 60) . 'm ' . ($avg % 60) . 's' : $avg . 's' ?></b><span>Average time per visit</span></div>
</div>
<div class="a-stats">
    <div class="a-stat"><b><?= $ev['whatsapp'] ?? 0 ?></b><span>WhatsApp taps</span></div>
    <div class="a-stat"><b><?= ($ev['chat'] ?? 0) ?></b><span>Chats started (<?= $ev['chat_open'] ?? 0 ?> opened)</span></div>
    <div class="a-stat"><b><?= $ev['order'] ?? 0 ?></b><span>Orders</span></div>
    <div class="a-stat"><b><?= ($ev['call'] ?? 0) + ($ev['consult'] ?? 0) + ($ev['request'] ?? 0) ?></b><span>Calls, "Help me choose", requests</span></div>
</div>
<section class="card" style="margin-bottom:18px">
    <div class="a-h"><h2>On the website now (<?= count($live) ?>)</h2><span class="muted small">Active in the last 5 minutes. Reload to refresh.</span></div>
    <?php if (!$live): ?><p class="muted">Nobody right now.</p><?php else: ?>
    <table class="a-table"><tbody><?php foreach ($live as $s): ?><tr>
        <td><a href="<?= e(url('admin/visitors.php?v=' . (int)$s['visitor_id'])) ?>"><?= e($s['name'] ?: trim($s['city'] . ', ' . $s['country'], ', ') ?: 'Visitor') ?></a></td>
        <td class="muted small"><?= e($s['device'] . ' ' . $s['model']) ?></td><td><?= e($s['now_title'] ?? '') ?></td><td class="muted small">from <?= e($s['source'] ?: 'direct') ?></td></tr><?php endforeach; ?></tbody></table>
    <?php endif; ?>
</section>
<div class="a-cols">
    <section class="card"><h2>Countries</h2><?= $bar($countries) ?></section>
    <section class="card"><h2>Towns</h2><?= $bar($cities) ?></section>
    <section class="card"><h2>How they found us</h2><?= $bar($sources) ?></section>
    <section class="card"><h2>Machines they looked at</h2><?= $bar($prods) ?></section>
    <section class="card"><h2>Pages <small class="muted">(average time)</small></h2><?= $bar($pages) ?></section>
    <section class="card"><h2>Devices</h2><?= $bar($devices) ?><h2 style="margin-top:18px">Language</h2><?= $bar($langs) ?></section>
    <section class="card"><h2>Actions</h2><?= $bar(array_map(fn($k, $v) => ['k' => $evNames[$k] ?? $k, 'n' => $v], array_keys($ev), $ev)) ?></section>
</div>
<section class="card">
    <div class="a-h"><h2>Recent visitors</h2><span class="muted small">Tap a visitor to see every page they opened.</span></div>
    <div style="overflow-x:auto"><table class="a-table">
        <thead><tr><th>Who</th><th>Where</th><th>Device</th><th>Came from</th><th>Visits</th><th>Pages</th><th>Did</th><th>Last seen</th></tr></thead>
        <tbody><?php foreach ($recent as $v): ?><tr>
            <td><a href="<?= e(url('admin/visitors.php?d=' . $days . '&v=' . (int)$v['id'])) ?>"><?= e($v['name'] !== '' ? $v['name'] : 'Visitor #' . $v['id']) ?></a><?= $v['phone'] !== '' ? '<br><small class="muted">' . e($v['phone']) . '</small>' : '' ?></td>
            <td><?= e(trim($v['city'] . ', ' . $v['country'], ', ') ?: 'Unknown') ?></td>
            <td class="small"><?= e($v['device'] . ' ' . $v['model']) ?><br><span class="muted"><?= e($v['os'] . ' · ' . $v['browser']) ?></span></td>
            <td class="small"><?= e($v['first_source'] ?: 'direct') ?></td>
            <td><?= (int)$v['visits'] ?></td><td><?= (int)$v['pages'] ?></td><td><?= (int)$v['evs'] ?: '' ?></td>
            <td class="small muted"><?= e($ago($v['last_at'])) ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
</section>
<?php admin_foot();
