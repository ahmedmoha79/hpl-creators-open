<?php
/**
 * One campaign clip (2026-11-08): views and money, views per day, and where it stands.
 * Only the creator who sent the clip can open it.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/home_hooks.php';
$user = require_creator();
$id = (int)get('id');
$clip = $id > 0 ? fetch_one(
    'SELECT s.*, c.title AS camp_title, c.platforms AS camp_platforms, c.status AS camp_status, c.id AS camp_id
       FROM campaign_submissions s JOIN campaigns c ON c.id = s.campaign_id
      WHERE s.id = ? AND s.user_id = ?',
    [$id, (int)$user['id']]
) : null;
if (!$clip) {
    flash('error', 'That clip could not be found.');
    redirect('creator/index.php');
}
$pageTitle = 'Your clip';
$counted = max(0, (int)$clip['views'] - (int)($clip['start_views'] ?? $clip['views']));
$terms = campaign_terms((string)$clip['camp_platforms'], (string)$clip['platform']);
$thumbs = clip_thumbs((int)$user['id']);
$thumb = $thumbs[$id] ?? null;
$ms = clip_milestone_reached($counted);
$nextMs = 0;
foreach (clip_milestones() as $m) { if ($m > $counted) { $nextMs = $m; break; } }

// Views and money per day, last 14 days.
$days = [];
$byDay = [];
try {
    foreach (fetch_all('SELECT day, views_gained v, amount a FROM earning_days WHERE src = "campaign" AND ref_id = ? AND user_id = ? AND day >= CURDATE() - INTERVAL 13 DAY', [$id, (int)$user['id']]) as $r) {
        $byDay[(string)$r['day']] = ['v' => (int)$r['v'], 'a' => (float)$r['a']];
    }
} catch (Throwable $e) {
}
$maxV = 1;
for ($i = 13; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime('-' . $i . ' day'));
    $v = $byDay[$d]['v'] ?? 0;
    $maxV = max($maxV, $v);
    $days[] = ['d' => $d, 'v' => $v, 'a' => $byDay[$d]['a'] ?? 0.0, 'label' => date('D j', strtotime($d)), 'letter' => substr(date('D', strtotime($d)), 0, 1), 'today' => $i === 0];
}
$todayV = $days[13]['v'];
$status = (string)$clip['status'];
$steps = [
    ['done' => true, 'title' => 'Sent', 'text' => date('j M, H:i', strtotime((string)$clip['submitted_at']))],
    ['done' => $status === 'approved', 'bad' => $status === 'rejected',
     'title' => $status === 'approved' ? 'Approved by the HPL team' : ($status === 'rejected' ? 'Not approved' : 'The team is checking it'),
     'text' => $status === 'approved' ? 'Its money is in your balance' : ($status === 'rejected' ? 'This clip does not earn' : 'Its money is locked until then, usually within 24 hours')],
    ['done' => $status === 'approved' && ($clip['camp_status'] ?? '') === 'active', 'title' => ($clip['camp_status'] ?? '') === 'active' ? 'Still earning' : 'Campaign ended',
     'text' => ($clip['camp_status'] ?? '') === 'active' ? 'New views add money. Views are read every ' . (int)max(10, (int)setting('autosync_minutes', 30)) . ' minutes' : 'Views after the end are not paid'],
];
require __DIR__ . '/../includes/creator_header.php';
?>
<link rel="stylesheet" href="<?= e(u('assets/css/home.css?v=20261109b')) ?>">
<div class="cl">
    <a class="cl-back" href="<?= e(u('creator/index.php')) ?>"><?= icon('chevron-left') ?>Home</a>
    <section class="cl-top">
        <div class="cl-pic">
            <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" onerror="this.remove()"><?php endif; ?>
            <span class="cl-pic__name"><?= e($clip['camp_title']) ?></span>
            <?php if ($ms > 0): ?><span class="hk-tile__tag hk-tile__tag--ms"><?= e(short_count($ms)) ?>+ views</span><?php endif; ?>
        </div>
        <div class="cl-nums">
            <span class="cl-nums__k"><?= e(platform_label((string)$clip['platform'])) ?> &middot; <a href="<?= e(u('creator/campaign.php?id=' . (int)$clip['camp_id'])) ?>"><?= e($clip['camp_title']) ?></a></span>
            <div class="cl-nums__row">
                <div><strong><?= e(num($counted)) ?></strong><small>views counted</small></div>
                <div><strong class="cl-money"><?= money((float)$clip['earned']) ?></strong><small>earned<?= $terms['rate'] > 0 ? ' at ' . money($terms['rate']) . ' per 1,000' : '' ?></small></div>
            </div>
            <?php if ($nextMs > 0 && $status !== 'rejected'): ?>
                <div class="cl-ms">
                    <div class="cl-ms__bar"><span style="width:<?= (int)floor(min(100, $counted / $nextMs * 100)) ?>%"></span></div>
                    <small><?= e(num($nextMs - $counted)) ?> views to <?= e(short_count($nextMs)) ?></small>
                </div>
            <?php endif; ?>
            <div class="cl-actions">
                <a class="hk-btn hk-btn--ink" href="<?= e((string)$clip['url']) ?>" target="_blank" rel="noopener"><?= icon('external') ?>Open on <?= e(platform_label((string)$clip['platform'])) ?></a>
                <button type="button" class="hk-btn hk-btn--light" data-share="<?= e((string)$clip['url']) ?>"><?= icon('send') ?>Share</button>
            </div>
        </div>
    </section>

    <section class="cl-card" aria-labelledby="clDays">
        <div class="hk-head"><h2 id="clDays">Views per day</h2><span class="hk-head__meta"><?= $todayV > 0 ? '+' . e(num($todayV)) . ' today' : 'Last 14 days' ?></span></div>
        <ol class="cl-bars" aria-label="Views per day, last 14 days">
            <?php foreach ($days as $d): ?>
                <li class="<?= $d['today'] ? 'is-today' : '' ?>" title="<?= e($d['label']) ?>: <?= e(num($d['v'])) ?> views<?= $d['a'] > 0 ? ', ' . e(money($d['a'])) : '' ?>">
                    <span style="height:<?= max(3, (int)round($d['v'] / $maxV * 100)) ?>%"></span><small><?= e($d['letter']) ?></small>
                </li>
            <?php endforeach; ?>
        </ol>
    </section>

    <section class="cl-card" aria-label="Where this clip stands">
        <ol class="cl-steps">
            <?php foreach ($steps as $s): ?>
                <li class="<?= $s['done'] ? 'is-done' : '' ?><?= !empty($s['bad']) ? ' is-bad' : '' ?>"><span><?= $s['done'] ? icon('check') : (!empty($s['bad']) ? icon('x') : icon('clock')) ?></span><div><strong><?= e($s['title']) ?></strong><small><?= e($s['text']) ?></small></div></li>
            <?php endforeach; ?>
        </ol>
    </section>

    <p class="cl-tip"><?= icon('trending') ?>Reply to comments in the first hour. It helps TikTok show your clip to more people.</p>
</div>
<script>
document.querySelectorAll('[data-share]').forEach(function (b) {
    b.addEventListener('click', function () {
        var url = b.getAttribute('data-share');
        if (navigator.share) { navigator.share({ url: url }).catch(function () {}); return; }
        if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function () { if (window.hplToast) window.hplToast('Link copied', 'ok'); }); }
    });
});
</script>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
