<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Clips sent by creators';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    require_once __DIR__ . '/../includes/earnings.php';
    $action = post('action');
    $id = (int)post('id');
    $back = 'admin/links.php' . ($action === 'update' || $action === 'adjust' ? '?edit=' . $id : '');
    if ($action === 'sync') {
        require_once __DIR__ . '/../includes/view_fetcher.php';
        $active = (int)fetch_val('SELECT COUNT(*) FROM content_links WHERE status = "active"', [], 0);
        $s = sync_views();
        $c = sync_campaign_submissions();
        if (!empty($s['paused'])) {
            $msg = 'My clips are paused (campaigns only), so they were not read. Campaign clips read: ' . num($c['processed']);
        } else {
            $msg = 'Read live views on ' . num($s['processed']) . ' of ' . num($active) . ' active clips';
            if ($active > $s['processed']) {
                $msg .= ' (the rest are on platforms or accounts we cannot read automatically yet)';
            }
            $msg .= '. Campaign clips read: ' . num($c['processed']);
        }
        $msg .= '. ' . money($s['credited'] + $c['credited']) . ' newly credited.';
        flash('success', $msg);
        $back = 'admin/links.php';
    } elseif ($action === 'update') {
        $link = $id > 0 ? fetch_one('SELECT * FROM content_links WHERE id = ?', [$id]) : null;
        $views = (int)post('views');
        if (!$link) {
            flash('error', 'Clip not found.');
        } elseif ($views < (int)$link['views']) {
            flash('error', 'Views cannot go down (they are ' . num($link['views']) . '). If the views were faked, flag the clip or remove its earnings with an adjustment.');
        } else {
            $title = mb_substr(trim(post('title')), 0, 200);
            $status = post('status') === 'flagged' ? 'flagged' : 'active';
            q('UPDATE content_links SET title = ?, status = ? WHERE id = ?', [$title === '' ? null : $title, $status, $id]);
            $paid = 0.0;
            if ($views > (int)$link['views']) {
                // Extra views are paid by the normal rule, never typed in as money.
                $paid = credit_link_earnings($id, $views, 'manual');
            }
            flash('success', 'Clip saved.' . ($views > (int)$link['views'] ? ' Views set to ' . num($views) . ', ' . money($paid) . ' credited.' : ''));
        }
    } elseif ($action === 'adjust') {
        $err = earnings_adjust((int)$user['id'], $id, (float)post('amount'), (string)post('reason'));
        flash($err ? 'error' : 'success', $err ?: 'Earnings adjusted. The creator has been notified.');
    } elseif ($action === 'clip_status') {
        // Same rule as Campaigns > submissions (campaign_submission_set_status): approving unlocks the money,
        // rejecting takes the clip's earnings back into the campaign budget.
        $sid = (int)post('submission_id');
        $st = (string)post('submission_status');
        if ($sid > 0 && in_array($st, ['approved', 'rejected'], true) && campaign_submission_set_status($sid, $st)) {
            flash('success', $st === 'rejected' ? 'Clip rejected. Its earnings went back to the campaign budget.' . campaign_debt_note($sid) : 'Clip approved.');
        }
        $q = (string)post('back');
        $back = 'admin/links.php' . ($q !== '' && preg_match('/^[\w=&%+.,-]*$/', $q) ? '?' . $q : '');
    } elseif ($action === 'flag' || $action === 'unflag') {
        q('UPDATE content_links SET status = ? WHERE id = ?', [$action === 'flag' ? 'flagged' : 'active', $id]);
        flash('success', $action === 'flag' ? 'Clip flagged. It keeps what it earned but earns nothing more until unflagged.' : 'Clip unflagged. It earns again from its next view read.');
    } elseif ($action === 'delete') {
        $link = fetch_one('SELECT earned FROM content_links WHERE id = ?', [$id]);
        if ($link && (float)$link['earned'] > 0) {
            flash('error', 'This clip has earned ' . money($link['earned']) . '. Deleting it would silently take that money from the creator. Flag it instead, or remove the earnings with an adjustment first.');
        } else {
            q('DELETE FROM content_links WHERE id = ?', [$id]);
            flash('warning', 'Clip deleted.');
        }
    }
    redirect($back);
}

// ---- Campaign clips (every clip creators send now: ready clips and own edits) ----
$f = [
    'show' => in_array(get('show'), ['waiting', 'approved', 'rejected', 'held'], true) ? get('show') : '',
    'campaign' => max(0, (int)get('campaign')),
    'q' => mb_substr(trim((string)get('q')), 0, 60),
    'sort' => in_array(get('sort'), ['views', 'eng_high', 'eng_low', 'earned'], true) ? get('sort') : '',
];
$hasStats = hpl_table_exists('clip_stats');
$hasRisk = hpl_column_exists('campaign_submissions', 'held_by_system');
$w = [];
$pr = [];
if ($f['campaign'] > 0) { $w[] = 's.campaign_id = ?'; $pr[] = $f['campaign']; }
if ($f['q'] !== '') { $w[] = '(u.full_name LIKE ? OR s.url LIKE ?)'; $pr[] = '%' . $f['q'] . '%'; $pr[] = '%' . $f['q'] . '%'; }
$baseWhere = $w;
$basePr = $pr;
if ($f['show'] === 'waiting') { $w[] = 's.status = "pending"'; }
if ($f['show'] === 'approved') { $w[] = 's.status = "approved"'; }
if ($f['show'] === 'rejected') { $w[] = 's.status = "rejected"'; }
if ($f['show'] === 'held') { $w[] = $hasRisk ? 's.held_by_system = 1 AND s.status = "pending"' : '0'; }
$from = ' FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id'
    . ($hasStats ? ' LEFT JOIN (SELECT submission_id, MAX(id) mid FROM clip_stats GROUP BY submission_id) lx ON lx.submission_id = s.id LEFT JOIN clip_stats st ON st.id = lx.mid' : '');
$whereSql = $w ? ' WHERE ' . implode(' AND ', $w) : '';
$engSql = $hasStats ? '(CASE WHEN st.views >= 50 THEN (st.likes + st.comments + st.shares) / st.views END)' : 'NULL';
$order = [
    '' => 's.submitted_at DESC',
    'views' => '(s.views - COALESCE(s.start_views, s.views)) DESC, s.id DESC',
    'eng_high' => "($engSql IS NULL), $engSql DESC, s.id DESC",
    'eng_low' => "($engSql IS NULL), $engSql ASC, s.id DESC",
    'earned' => 's.earned DESC, s.id DESC',
][$f['sort']];
$perPage = 36;
$total = (int)fetch_val('SELECT COUNT(*)' . $from . $whereSql, $pr, 0);
$pages = max(1, (int)ceil($total / $perPage));
$page = min($pages, max(1, (int)get('page')));
$clips = fetch_all('SELECT s.*, u.full_name, c.title AS campaign_title'
    . ($hasStats ? ', st.views AS st_views, st.likes, st.comments, st.shares, st.taken_at AS st_at' : '')
    . ', ' . $engSql . ' AS eng' . $from . $whereSql . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $pr);

// Counts for the choice buttons (same campaign / search, every status).
$bw = $baseWhere ? ' WHERE ' . implode(' AND ', $baseWhere) : '';
$cnt = fetch_one('SELECT COUNT(*) total, SUM(s.status = "pending") waiting, SUM(s.status = "approved") approved, SUM(s.status = "rejected") rejected'
    . ($hasRisk ? ', SUM(s.held_by_system = 1 AND s.status = "pending") held' : ', 0 held')
    . ', COUNT(DISTINCT s.user_id) creators, COALESCE(SUM(GREATEST(0, CAST(s.views AS SIGNED) - CAST(COALESCE(s.start_views, s.views) AS SIGNED))), 0) counted, COALESCE(SUM(s.earned), 0) earned'
    . ' FROM campaign_submissions s JOIN users u ON u.id = s.user_id' . $bw, $basePr) ?: [];
$avgEng = null;
if ($hasStats) {
    $ae = fetch_one('SELECT SUM(st.likes + st.comments + st.shares) r, SUM(st.views) v' . $from . $bw . ($bw ? ' AND' : ' WHERE') . ' st.views >= 50', $basePr);
    if ($ae && (float)$ae['v'] > 0) {
        $avgEng = (float)$ae['r'] / (float)$ae['v'];
    }
}
$campaignList = fetch_all('SELECT c.id, c.title, COUNT(s.id) n FROM campaigns c JOIN campaign_submissions s ON s.campaign_id = c.id GROUP BY c.id, c.title ORDER BY MAX(s.submitted_at) DESC');

// Views counted per day: the last 14 days for the page chart and for each clip on this page.
$days = [];
for ($i = 13; $i >= 0; $i--) {
    $days[] = date('Y-m-d', strtotime("-$i days"));
}
$dayTotals = array_fill_keys($days, 0);
$spark = [];
try {
    $dw = $f['campaign'] > 0 ? ' AND ref_id IN (SELECT id FROM campaign_submissions WHERE campaign_id = ' . (int)$f['campaign'] . ')' : '';
    foreach (fetch_all('SELECT day, SUM(views_gained) v FROM earning_days WHERE src = "campaign" AND day >= ?' . $dw . ' GROUP BY day', [$days[0]]) as $r) {
        if (isset($dayTotals[$r['day']])) { $dayTotals[$r['day']] = (int)$r['v']; }
    }
    $ids = array_map(fn($r) => (int)$r['id'], $clips);
    if ($ids) {
        foreach (fetch_all('SELECT ref_id, day, views_gained FROM earning_days WHERE src = "campaign" AND day >= ? AND ref_id IN (' . implode(',', $ids) . ')', [$days[0]]) as $r) {
            $spark[(int)$r['ref_id']][(string)$r['day']] = (int)$r['views_gained'];
        }
    }
} catch (Throwable $e) {
}

/** 12,400 -> "12.4K". */
$short = function ($n): string {
    $n = (float)$n;
    if ($n >= 1e6) { return rtrim(rtrim(number_format($n / 1e6, 1), '0'), '.') . 'M'; }
    if ($n >= 1e4) { return number_format($n / 1e3, 0) . 'K'; }
    if ($n >= 1e3) { return rtrim(rtrim(number_format($n / 1e3, 1), '0'), '.') . 'K'; }
    return number_format($n);
};
/** Colour of an engagement rate (TikTok normal is about 4 in 100). */
$tone = fn(?float $r): string => $r === null ? 'none' : ($r >= 0.06 ? 'great' : ($r >= 0.03 ? 'ok' : 'low'));
/** A small circle that fills up to $r (full at 10%). */
$ring = function (?float $r, string $cls = '') use ($tone): string {
    $c = 2 * M_PI * 20;
    $fill = $r === null ? 0 : min(1, $r / 0.10);
    $label = $r === null ? '–' : (($r * 100) >= 10 ? number_format($r * 100, 0) : number_format($r * 100, 1)) . '%';
    return '<span class="ck-ring ck-ring--' . $tone($r) . ' ' . $cls . '" role="img" aria-label="Engagement ' . e($label) . '">'
        . '<svg viewBox="0 0 48 48" aria-hidden="true"><circle class="ck-ring__track" cx="24" cy="24" r="20"/>'
        . '<circle class="ck-ring__fill" cx="24" cy="24" r="20" style="stroke-dasharray:' . round($c, 2) . ';--from:' . round($c, 2) . ';--to:' . round($c * (1 - $fill), 2) . '"/></svg>'
        . '<b>' . e($label) . '</b></span>';
};
/** Views-per-day line for one clip: an SVG area that draws itself. */
$sparkSvg = function (array $byDay) use ($days): string {
    $vals = array_map(fn($d) => (int)($byDay[$d] ?? 0), $days);
    $max = max($vals);
    if ($max <= 0) {
        return '<div class="ck-spark ck-spark--empty">No new views in the last 14 days</div>';
    }
    $pts = [];
    foreach ($vals as $i => $v) {
        $pts[] = round($i * (200 / 13), 1) . ',' . round(38 - ($v / $max) * 34, 1);
    }
    $line = implode(' ', $pts);
    return '<svg class="ck-spark" viewBox="0 0 200 40" preserveAspectRatio="none" aria-hidden="true">'
        . '<polygon class="ck-spark__area" points="0,40 ' . $line . ' 200,40"/>'
        . '<polyline class="ck-spark__line" pathLength="100" points="' . $line . '"/></svg>';
};
$qs = function (array $over) use ($f): string {
    $a = array_filter(array_merge($f, ['page' => ''], $over), fn($v) => $v !== '' && $v !== 0 && $v !== null);
    return $a ? '?' . http_build_query($a) : '';
};
$backQs = ltrim($qs(['page' => $page > 1 ? $page : '']), '?');

// ---- Older "My clips" links (before campaigns; the feature is paused) ----
$platform = get('platform');
$status = get('status');
$where = [];
$params = [];
if ($platform !== '') { $where[] = 'cl.platform = ?'; $params[] = $platform; }
if ($status !== '') { $where[] = 'cl.status = ?'; $params[] = $status; }
$sql = 'SELECT cl.*, u.full_name FROM content_links cl JOIN users u ON u.id = cl.user_id'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
    . ' ORDER BY cl.submitted_at DESC LIMIT 300';
$rows = fetch_all($sql, $params);
$stats = fetch_one('SELECT COUNT(*) links, COUNT(DISTINCT user_id) creators, COALESCE(SUM(views),0) views, COALESCE(SUM(earned),0) earned FROM content_links');
$adjustments = [];

$editId = (int)get('edit');
$editLink = $editId > 0 ? fetch_one('SELECT * FROM content_links WHERE id = ?', [$editId]) : null;
$editLinkOwner = $editLink ? fetch_val('SELECT full_name FROM users WHERE id = ?', [$editLink['user_id']], '') : '';
if ($editLink) {
    try {
        $adjustments = fetch_all('SELECT a.*, u.full_name AS admin_name FROM earning_adjustments a LEFT JOIN users u ON u.id = a.admin_id WHERE a.link_id = ? ORDER BY a.created_at DESC', [$editId]);
    } catch (Throwable $e) {
    }
}
$oldOpen = $editLink || $platform !== '' || $status !== '';

require __DIR__ . '/../includes/admin_header.php';
?>

<link rel="stylesheet" href="<?= e(u('assets/css/admin-clips.css?v=20261113b')) ?>">
<div class="ck" id="ck">
<div class="ck-head">
    <div>
        <h1>Clips sent by creators</h1>
        <p class="muted">Every clip sent to a campaign, newest first. Tap a picture to open the clip on TikTok.</p>
    </div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn btn--primary" type="submit" data-busy="Reading views...">&#8635; Read all views now</button></form>
</div>

<div class="ck-top">
    <div class="ck-tile"><span>Clips</span><b data-ck-count="<?= (int)($cnt['total'] ?? 0) ?>"><?= e(num($cnt['total'] ?? 0)) ?></b><small>from <?= e(num($cnt['creators'] ?? 0)) ?> creator<?= (int)($cnt['creators'] ?? 0) === 1 ? '' : 's' ?></small></div>
    <div class="ck-tile"><span>Views counted</span><b><?= e($short($cnt['counted'] ?? 0)) ?></b><small>views since each clip was sent</small></div>
    <div class="ck-tile ck-tile--ring"><?= $ring($avgEng, 'ck-ring--lg') ?><div><span>Engagement</span><small>likes, comments and shares per 100 views, all clips together. Normal on TikTok is about 4.</small></div></div>
    <div class="ck-tile ck-tile--wide"><span>Earned by creators</span><b><?= money($cnt['earned'] ?? 0) ?></b><small><?= e(num($cnt['waiting'] ?? 0)) ?> clip<?= (int)($cnt['waiting'] ?? 0) === 1 ? '' : 's' ?> waiting for you</small></div>
</div>

<?php $dayMax = max(1, max($dayTotals)); ?>
<section class="ck-chart" aria-label="Views counted per day">
    <div class="ck-chart__head"><h2>Views counted per day</h2><span class="muted small">last 14 days<?= $f['campaign'] ? ', this campaign' : '' ?></span></div>
    <div class="ck-bars">
        <?php foreach ($dayTotals as $d => $v): ?>
            <div class="ck-bar<?= $d === date('Y-m-d') ? ' is-today' : '' ?>" title="<?= e(date('D j M', strtotime($d)) . ': ' . num($v) . ' views') ?>">
                <em><?= $v > 0 ? e($short($v)) : '' ?></em>
                <i style="--h:<?= round($v / $dayMax * 100, 1) ?>"></i>
                <span><?= $d === date('Y-m-d') ? 'Today' : e(date('j', strtotime($d))) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<form method="get" class="ck-filters" id="ckFilters">
    <div class="ck-chips" role="group" aria-label="Show">
        <?php foreach (['' => ['All', $cnt['total'] ?? 0], 'waiting' => ['Waiting for you', $cnt['waiting'] ?? 0], 'held' => ['Held by checks', $cnt['held'] ?? 0], 'approved' => ['Approved', $cnt['approved'] ?? 0], 'rejected' => ['Rejected', $cnt['rejected'] ?? 0]] as $k => [$lbl, $n]): ?>
            <?php if ($k === 'held' && (int)$n === 0 && $f['show'] !== 'held') { continue; } ?>
            <a class="ck-chip<?= $f['show'] === $k ? ' is-on' : '' ?>" href="<?= e(u('admin/links.php' . $qs(['show' => $k]))) ?>"><?= e($lbl) ?> <b><?= e(num($n)) ?></b></a>
        <?php endforeach; ?>
    </div>
    <input type="hidden" name="show" value="<?= e($f['show']) ?>">
    <div class="ck-pick">
        <select class="select" name="campaign" aria-label="Campaign" onchange="this.form.submit()">
            <option value="">All campaigns</option>
            <?php foreach ($campaignList as $cmp): ?>
                <option value="<?= (int)$cmp['id'] ?>" <?= $f['campaign'] === (int)$cmp['id'] ? 'selected' : '' ?>><?= e($cmp['title']) ?> (<?= (int)$cmp['n'] ?>)</option>
            <?php endforeach; ?>
        </select>
        <select class="select" name="sort" aria-label="Order" onchange="this.form.submit()">
            <?php foreach (['' => 'Newest first', 'views' => 'Most views', 'eng_high' => 'Best engagement', 'eng_low' => 'Lowest engagement', 'earned' => 'Most earned'] as $k => $lbl): ?>
                <option value="<?= e($k) ?>" <?= $f['sort'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
        <input class="input" type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search a creator or link" aria-label="Search">
        <button class="btn btn--ghost" type="submit">Search</button>
    </div>
</form>

<?php if (!$clips): ?>
    <div class="panel"><div class="empty"><p class="muted mb-0">No clips match. <a href="<?= e(u('admin/links.php')) ?>">Show all clips</a></p></div></div>
<?php else: ?>
<p class="small muted ck-count">Showing <?= e(num(($page - 1) * $perPage + 1)) ?>–<?= e(num(($page - 1) * $perPage + count($clips))) ?> of <?= e(num($total)) ?></p>
<div class="ck-grid">
    <?php foreach ($clips as $s):
        $sid = (int)$s['id'];
        $pic = (!empty($s['cover_url']) && !empty($s['cover_at']) && strtotime((string)$s['cover_at']) > time() - 5 * 3600) ? (string)$s['cover_url'] : (!empty($s['ready_clip_id']) ? u('admin/ready_clips.php?poster=' . (int)$s['ready_clip_id']) : '');
        $counted = max(0, (int)$s['views'] - (int)($s['start_views'] ?? $s['views']));
        $eng = $s['eng'] !== null ? (float)$s['eng'] : null;
        $held = !empty($s['held_by_system']) && $s['status'] === 'pending';
    ?>
    <article class="ck-card ck-card--<?= e($s['status']) ?>">
        <a class="ck-pic" href="<?= e((string)$s['url']) ?>" target="_blank" rel="noopener" aria-label="Open the clip">
            <?php if ($pic !== ''): ?><img src="<?= e($pic) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?>
            <span class="ck-pic__play"><?= icon('play') ?></span>
        </a>
        <div class="ck-body">
            <div class="ck-who">
                <a href="<?= e(u('admin/creator_detail.php?id=' . (int)$s['user_id'])) ?>"><?= e($s['full_name']) ?></a>
                <span class="badge <?= $held ? 'warn' : status_class($s['status']) ?>"><?= $held ? 'Held by checks' : e(['pending' => 'Waiting', 'approved' => 'Approved', 'rejected' => 'Rejected'][$s['status']] ?? $s['status']) ?></span>
            </div>
            <div class="ck-meta small muted"><?= e($s['campaign_title']) ?> · sent <?= e(time_ago($s['submitted_at'])) ?><?= !empty($s['ready_clip_id']) ? ' · ready clip' : ' · own edit' ?></div>
            <?php if (!empty($s['ready_clip_id']) && ($s['status'] ?? '') === 'pending'): ?><div class="ck-note small"><?= icon('alert') ?><span>Before approving, play it with sound: no music or other sound may be added over the clip.</span></div>
            <?php endif; ?>
            <div class="ck-nums">
                <div class="ck-views"><b><?= e($short($counted)) ?></b><span>views counted</span></div>
                <?= $ring($eng) ?>
            </div>
            <div class="ck-react small">
                <?php if (isset($s['st_views']) && $s['st_views'] !== null): ?>
                    <span title="Likes"><?= icon('heart') ?><?= e($short($s['likes'])) ?></span>
                    <span title="Comments"><?= icon('chat') ?><?= e($short($s['comments'])) ?></span>
                    <span title="Shares"><?= icon('send') ?><?= e($short($s['shares'])) ?></span>
                    <span title="All views on TikTok, also before it was sent"><?= icon('eye') ?><?= e($short($s['st_views'])) ?></span>
                <?php else: ?>
                    <span class="muted">Likes and comments not read yet</span>
                <?php endif; ?>
                <strong class="ck-money"><?= money($s['earned']) ?></strong>
            </div>
            <?= $sparkSvg($spark[$sid] ?? []) ?>
            <?php if (!empty($s['review_verdict'])): $rv = (string)$s['review_verdict']; ?>
                <div class="ck-note small <?= $rv === 'approve' ? 'is-pass' : ($rv === 'reject_recommended' ? 'is-bad' : '') ?>"><?= icon($rv === 'approve' ? 'check-circle' : 'alert') ?><span><strong>Brain: <?= $rv === 'approve' ? (!empty($s['review_by_brain']) ? 'approved it' : 'looks right') : ($rv === 'rejected' ? 'rejected it' : ($rv === 'reject_recommended' ? 'looks wrong, reject?' : 'needs your eyes')) ?></strong> <?= e((string)($s['review_note'] ?? '')) ?> <span class="muted">(look <?= (int)($s['review_stage'] ?? 1) ?>, <?= e(time_ago((string)$s['review_at'])) ?>)</span></span></div>
            <?php endif; ?>
            <?php if (!empty($s['auto_check']) && $s['status'] === 'pending' && empty($s['review_verdict'])): ?>
                <div class="ck-note small <?= $s['auto_check'] === 'pass' ? 'is-pass' : '' ?>"><?= $s['auto_check'] === 'pass' ? icon('check-circle') . ' System check passed' : icon('alert') . ' Check by hand: ' . e((string)($s['auto_note'] ?? '')) ?></div>
            <?php endif; ?>
            <div class="ck-actions">
                <?php if ($s['status'] !== 'approved'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clip_status"><input type="hidden" name="submission_id" value="<?= $sid ?>"><input type="hidden" name="submission_status" value="approved"><input type="hidden" name="back" value="<?= e($backQs) ?>"><button class="btn btn--ok btn--sm" type="submit">Approve</button></form>
                <?php endif; ?>
                <?php if ($s['status'] !== 'rejected'): ?>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clip_status"><input type="hidden" name="submission_id" value="<?= $sid ?>"><input type="hidden" name="submission_status" value="rejected"><input type="hidden" name="back" value="<?= e($backQs) ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Reject this clip? Its earnings go back to the campaign budget.">Reject</button></form>
                <?php endif; ?>
                <?php if ($held): ?><a class="btn btn--ghost btn--sm" href="<?= e(u('admin/risk.php')) ?>">See checks</a><?php endif; ?>
                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/campaigns.php#submissions')) ?>" title="Edit views or money, or delete, on the Campaigns page">More</a>
            </div>
        </div>
    </article>
    <?php endforeach; ?>
</div>
<?php if ($pages > 1): ?>
<nav class="ck-pages" aria-label="Pages">
    <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e(u('admin/links.php' . $qs(['page' => $page - 1]))) ?>">Newer</a><?php endif; ?>
    <span class="small muted">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= e(u('admin/links.php' . $qs(['page' => $page + 1]))) ?>">More clips</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
</div>

<details class="ck-old" <?= $oldOpen ? 'open' : '' ?>>
<summary>Older clips from before campaigns (<?= e(num($stats['links'])) ?>)</summary>
<p class="small muted">The old "My clips" links, sent before campaigns started. That route is paused, so no new ones arrive here.</p>
<form method="get" class="flex flex-wrap mb-3 mt-2">
    <select class="select" name="platform" style="max-width:200px">
        <option value="">All platforms</option>
        <?php foreach (platforms() as $k => $label): ?>
            <option value="<?= e($k) ?>" <?= $platform === $k ? 'selected' : '' ?>><?= e($label) ?></option>
        <?php endforeach; ?>
    </select>
    <select class="select" name="status" style="max-width:160px">
        <option value="">All statuses</option>
        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Active</option>
        <option value="flagged" <?= $status === 'flagged' ? 'selected' : '' ?>>Flagged</option>
    </select>
    <button class="btn btn--ghost" type="submit">Filter</button>
</form>

<?php if ($editLink): ?>
<div class="panel mb-3">
    <div class="panel__head"><h3>Edit clip</h3><span class="small muted"><?= e($editLinkOwner) ?> &middot; <?= e(platform_label($editLink['platform'])) ?></span></div>
    <div class="panel__body">
        <p class="small muted truncate mb-2"><?= safe_link((string)$editLink['url']) ?></p>
        <div class="adm-clip-sum">
            <div><span>Views</span><b><?= e(num($editLink['views'])) ?></b></div>
            <div><span>Earned</span><b><?= money($editLink['earned']) ?></b></div>
            <div><span>Last read</span><b><?= $editLink['last_fetched_at'] ? e(time_ago($editLink['last_fetched_at'])) : 'Never' ?></b></div>
        </div>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$editLink['id'] ?>">
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="edit_title">Title</label>
                <input class="input" type="text" id="edit_title" name="title" value="<?= e($editLink['title'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="label" for="edit_views">Views</label>
                <input class="input" type="number" id="edit_views" name="views" min="<?= (int)$editLink['views'] ?>" value="<?= (int)$editLink['views'] ?>">
                <div class="field__hint">Only if the platform cannot be read. Extra views are paid at the normal rate.</div>
            </div>
            <div class="field">
                <label class="label" for="edit_status">Status</label>
                <select class="select" id="edit_status" name="status">
                    <option value="active" <?= $editLink['status'] === 'active' ? 'selected' : '' ?>>Active (earning)</option>
                    <option value="flagged" <?= $editLink['status'] === 'flagged' ? 'selected' : '' ?>>Flagged (stops earning)</option>
                </select>
            </div>
            <div class="form-actions" style="grid-column:1/-1">
                <button class="btn btn--primary" type="submit">Save clip</button>
                <a class="btn btn--ghost" href="<?= e(u('admin/links.php')) ?>">Close</a>
            </div>
        </form>

        <h4 class="adm-sub">Adjust earnings</h4>
        <p class="small muted">For bonuses or corrections. The creator sees the amount and your reason. Money already withdrawn cannot be removed.</p>
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust">
            <input type="hidden" name="id" value="<?= (int)$editLink['id'] ?>">
            <div class="field">
                <label class="label" for="adj_amount">Amount (<?= e(currency()) ?>)</label>
                <input class="input" type="number" id="adj_amount" name="amount" step="0.01" placeholder="20 or -20" required>
            </div>
            <div class="field">
                <label class="label" for="adj_reason">Reason</label>
                <input class="input" type="text" id="adj_reason" name="reason" maxlength="255" placeholder="e.g. Bonus for the best clip of the week" required>
            </div>
            <div class="form-actions" style="grid-column:1/-1">
                <button class="btn btn--ghost" type="submit" data-confirm="Change this creator's earnings? They will be notified.">Apply adjustment</button>
            </div>
        </form>
        <?php if ($adjustments): ?>
            <ul class="adm-adj">
                <?php foreach ($adjustments as $a): ?>
                    <li><b class="<?= (float)$a['amount'] < 0 ? 'neg' : 'pos' ?>"><?= ((float)$a['amount'] > 0 ? '+' : '') . money($a['amount']) ?></b> <?= e($a['reason']) ?> <span class="muted small">&middot; <?= e($a['admin_name'] ?: 'Team') ?> &middot; <?= e(time_ago($a['created_at'])) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<style>
.adm-clip-sum { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:10px; margin:6px 0 16px; }
.adm-clip-sum div { border:1px solid var(--ui-line, #e7dfd2); border-radius:10px; padding:10px 12px; display:flex; flex-direction:column; gap:2px; }
.adm-clip-sum span { font-size:.72rem; text-transform: none; letter-spacing:.05em; color:var(--ui-muted, #8a8273); }
.adm-sub { margin:22px 0 4px; font-size:1rem; }
.adm-adj { list-style:none; margin:12px 0 0; padding:0; display:flex; flex-direction:column; gap:6px; font-size:.88rem; }
.adm-adj b.pos { color:#15803d; } .adm-adj b.neg { color:#b91c1c; }
</style>
<?php endif; ?>

<div class="panel">
    <div class="panel__body panel__body--flush">
        <?php if (!$rows): ?>
            <div class="empty"><p class="muted mb-0">No links match.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Creator</th><th>Platform</th><th>Link</th><th class="num">Views</th><th class="num">Earned</th><th>Status</th><th>Submitted</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $l): ?>
                    <tr>
                        <td><a href="<?= e(u('admin/creator_detail.php?id=' . $l['user_id'])) ?>" class="small"><?= e($l['full_name']) ?></a></td>
                        <td><span class="flex"><?= platform_icon($l['platform']) ?> <span class="small"><?= e(platform_label($l['platform'])) ?></span></span></td>
                        <td class="truncate"><?= safe_link((string)$l['url'], (string)($l['title'] ?: $l['url'])) ?></td>
                        <td class="num"><?= e(num($l['views'])) ?></td>
                        <td class="num"><?= money($l['earned']) ?></td>
                        <td><span class="badge <?= status_class($l['status']) ?>"><?= e($l['status']) ?></span></td>
                        <td class="small muted"><?= e(time_ago($l['submitted_at'])) ?></td>
                        <td>
                            <div class="flex" style="gap:6px">
                                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/links.php?edit=' . (int)$l['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="action" value="<?= $l['status'] === 'flagged' ? 'unflag' : 'flag' ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $l['status'] === 'flagged' ? 'Unflag' : 'Flag' ?></button></form>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Delete this link?">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

</details>
<script>
(function () {
    var root = document.getElementById('ck');
    if (!root) return;
    if (!('IntersectionObserver' in window)) { root.classList.add('is-on'); return; }
    var io = new IntersectionObserver(function (es) {
        es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-on'); io.unobserve(en.target); } });
    }, { rootMargin: '0px 0px -40px 0px' });
    root.querySelectorAll('.ck-top, .ck-chart, .ck-card').forEach(function (el) { io.observe(el); });
})();
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
