<?php
/**
 * Analytics (2026-11-10): the whole programme at a glance, with graphs.
 *  - The path every creator walks (signed up -> passed the test -> connected TikTok -> joined a campaign ->
 *    took a clip -> sent a link -> approved -> paid), so the team sees where people get stuck.
 *  - Day by day: new creators, clips sent, views counted, money earned, money paid out.
 *  - Where creators are: towns and provinces of Zambia (the reach of the programme).
 *  - Best creators and campaigns in the chosen period.
 * Read-only. Every number comes straight from the same tables the rest of the site uses.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/charts.php';
require_once __DIR__ . '/../includes/towns.php';
$user = require_admin();
$pageTitle = 'Analytics';

$days = in_array((int)get('days'), [7, 30, 90], true) ? (int)get('days') : 30;
$from = date('Y-m-d', strtotime('-' . ($days - 1) . ' days'));

/** [label => value] for every day of the period, zero where nothing happened. */
$perDay = function (string $sql, array $args = []) use ($days, $from): array {
    $got = [];
    try {
        foreach (fetch_all($sql, array_merge([$from], $args)) as $r) {
            $got[(string)$r['d']] = (float)$r['n'];
        }
    } catch (Throwable $e) {
    }
    $out = [];
    for ($i = 0; $i < $days; $i++) {
        $d = date('Y-m-d', strtotime($from . ' +' . $i . ' days'));
        $out[date('j M', strtotime($d))] = $got[$d] ?? 0;
    }
    return $out;
};
$val = function (string $sql, array $args = []) { try { return (float)fetch_val($sql, $args, 0); } catch (Throwable $e) { return 0.0; } };

$signups = $perDay('SELECT DATE(created_at) d, COUNT(*) n FROM users WHERE role = "creator" AND created_at >= ? GROUP BY d');
$clips   = $perDay('SELECT DATE(submitted_at) d, COUNT(*) n FROM campaign_submissions WHERE submitted_at >= ? GROUP BY d');
$views   = $perDay('SELECT day d, SUM(views_gained) n FROM earning_days WHERE day >= ? GROUP BY day');
$earned  = $perDay('SELECT day d, SUM(amount) n FROM earning_days WHERE day >= ? GROUP BY day');
$visitors = $perDay('SELECT day d, COUNT(*) n FROM site_visits WHERE day >= ? GROUP BY day');
$activeCr = $perDay('SELECT day d, COUNT(DISTINCT user_id) n FROM site_visits WHERE day >= ? AND user_id IS NOT NULL GROUP BY day');
$paid    = $perDay('SELECT DATE(resolved_at) d, SUM(amount) n FROM withdrawals WHERE status = "paid" AND resolved_at >= ? GROUP BY d');

// The path, for creators who signed up in the period. Each step counts people, not events.
$w = 'u.role = "creator" AND u.created_at >= ?';
$funnel = [
    ['Signed up', $val("SELECT COUNT(*) FROM users u WHERE $w", [$from]), 'admin/creators.php'],
    ['Passed the test', $val("SELECT COUNT(*) FROM users u WHERE $w AND (u.assessment_status = 'passed' OR u.qualified = 1)", [$from]), 'admin/creators.php?step=no_work'],
    ['Connected TikTok', $val("SELECT COUNT(*) FROM users u WHERE $w AND EXISTS (SELECT 1 FROM tiktok_accounts t WHERE t.user_id = u.id)", [$from]), 'admin/creators.php'],
    ['Joined a campaign', $val("SELECT COUNT(*) FROM users u WHERE $w AND EXISTS (SELECT 1 FROM campaign_joins j WHERE j.user_id = u.id)", [$from]), 'admin/campaigns.php'],
    ['Took a ready clip', $val("SELECT COUNT(*) FROM users u WHERE $w AND (EXISTS (SELECT 1 FROM ready_clips r WHERE r.claimed_by = u.id) OR EXISTS (SELECT 1 FROM campaign_submissions s WHERE s.user_id = u.id AND s.ready_clip_id IS NOT NULL))", [$from]), 'admin/ready_clips.php'],
    ['Sent a link', $val("SELECT COUNT(*) FROM users u WHERE $w AND EXISTS (SELECT 1 FROM campaign_submissions s WHERE s.user_id = u.id)", [$from]), 'admin/campaigns.php'],
    ['Had a clip approved', $val("SELECT COUNT(*) FROM users u WHERE $w AND EXISTS (SELECT 1 FROM campaign_submissions s WHERE s.user_id = u.id AND s.status = 'approved')", [$from]), 'admin/campaigns.php'],
    ['Got paid', $val("SELECT COUNT(*) FROM users u WHERE $w AND EXISTS (SELECT 1 FROM withdrawals x WHERE x.user_id = u.id AND x.status = 'paid')", [$from]), 'admin/withdrawals.php?filter=paid'],
];

// Waiting on the team right now.
$todo = [
    ['Clips waiting for approval', (int)$val('SELECT COUNT(*) FROM campaign_submissions WHERE status = "pending"'), 'admin/campaigns.php'],
    ['Clips the website is holding', (int)$val('SELECT COUNT(*) FROM campaign_submissions WHERE risk_level = "high" AND risk_ok = 0 AND status <> "rejected"'), 'admin/risk.php'],
    ['Withdrawals to pay', (int)$val('SELECT COUNT(*) FROM withdrawals WHERE status = "pending"'), 'admin/withdrawals.php'],
    ['Work videos to review', (int)$val('SELECT COUNT(*) FROM users WHERE role = "creator" AND portfolio_status = "pending"'), 'admin/portfolio.php'],
    ['Ready clips free to take', (int)$val('SELECT COUNT(*) FROM ready_clips r JOIN campaigns c ON c.id = r.campaign_id WHERE r.is_active = 1 AND r.claimed_by IS NULL AND c.status = "active"'), 'admin/ready_clips.php'],
];

// Visitors: today, and where the period's visitors came from.
$today = ['visitors' => (int)$val('SELECT COUNT(*) FROM site_visits WHERE day = CURDATE()'), 'new' => (int)$val('SELECT COUNT(*) FROM site_visits WHERE day = CURDATE() AND is_new = 1'),
    'creators' => (int)$val('SELECT COUNT(DISTINCT user_id) FROM site_visits WHERE day = CURDATE() AND user_id IS NOT NULL'), 'pages' => (int)$val('SELECT COALESCE(SUM(hits),0) FROM site_visits WHERE day = CURDATE()'),
    'signups' => (int)$val('SELECT COUNT(*) FROM users WHERE role = "creator" AND DATE(created_at) = CURDATE()'),
    'online' => (int)$val('SELECT COUNT(*) FROM users WHERE role = "creator" AND last_seen > NOW() - INTERVAL 5 MINUTE'),
    'yesterday' => (int)$val('SELECT COUNT(*) FROM site_visits WHERE day = CURDATE() - INTERVAL 1 DAY')];
$sources = []; $landings = []; $phones = [0, 0];
try {
    foreach (fetch_all('SELECT source, COUNT(*) n, SUM(is_new) fresh FROM site_visits WHERE day >= ? GROUP BY source ORDER BY n DESC LIMIT 10', [$from]) as $r) {
        $sources[] = [$r['source'] === 'direct' ? 'Typed or app icon' : $r['source'], (int)$r['n'], (int)$r['fresh'] . ' first time'];
    }
    foreach (fetch_all('SELECT landing, COUNT(*) n FROM site_visits WHERE day >= ? AND user_id IS NULL GROUP BY landing ORDER BY n DESC LIMIT 8', [$from]) as $r) {
        $landings[] = [$r['landing'] === '/' ? 'Front page' : $r['landing'], (int)$r['n']];
    }
    $pm = fetch_one('SELECT SUM(is_mobile) m, COUNT(*) n FROM site_visits WHERE day >= ?', [$from]);
    $phones = [(int)($pm['m'] ?? 0), (int)($pm['n'] ?? 0)];
} catch (Throwable $e) {
}
$newVisitors = (int)$val('SELECT COUNT(*) FROM site_visits WHERE day >= ? AND is_new = 1', [$from]);
// When people arrive (hour of the day of each visitor's first page), and creators who keep coming back.
$hours = array_fill(0, 24, 0);
try {
    foreach (fetch_all('SELECT HOUR(first_at) h, COUNT(*) n FROM site_visits WHERE day >= ? GROUP BY h', [$from]) as $r) { $hours[(int)$r['h']] = (int)$r['n']; }
} catch (Throwable $e) {
}
$hourSeries = [];
foreach ($hours as $h => $n) { $hourSeries[sprintf('%02d:00', $h)] = $n; }
$busiest = array_sum($hours) > 0 ? sprintf('%02d:00', array_search(max($hours), $hours)) : '';
$week = [
    'active' => (int)$val('SELECT COUNT(DISTINCT user_id) FROM site_visits WHERE day >= CURDATE() - INTERVAL 6 DAY AND user_id IS NOT NULL'),
    'loyal' => (int)$val('SELECT COUNT(*) FROM (SELECT user_id FROM site_visits WHERE day >= CURDATE() - INTERVAL 6 DAY AND user_id IS NOT NULL GROUP BY user_id HAVING COUNT(*) >= 3) x'),
    'all' => (int)$val('SELECT COUNT(*) FROM users WHERE role = "creator" AND status = "active"'),
    'posted' => (int)$val('SELECT COUNT(DISTINCT user_id) FROM campaign_submissions WHERE submitted_at >= NOW() - INTERVAL 7 DAY'),
];

// TikTok page scores (includes/page_score.php).
$scoreBands = ['Strong (80+)' => 0, 'Good (60-79)' => 0, 'Building (40-59)' => 0, 'Needs work (under 40)' => 0];
$scoreLow = []; $scoreAvg = 0; $scoreN = 0;
try {
    foreach (fetch_all('SELECT page_score s FROM users WHERE role = "creator" AND page_score IS NOT NULL') as $r) {
        $x = (int)$r['s']; $scoreN++; $scoreAvg += $x;
        $scoreBands[$x >= 80 ? 'Strong (80+)' : ($x >= 60 ? 'Good (60-79)' : ($x >= 40 ? 'Building (40-59)' : 'Needs work (under 40)'))]++;
    }
    $scoreAvg = $scoreN ? round($scoreAvg / $scoreN) : 0;
    foreach (fetch_all('SELECT id, full_name, page_score, page_score_json FROM users WHERE role = "creator" AND page_score IS NOT NULL ORDER BY page_score ASC LIMIT 8') as $r) {
        $j = json_decode((string)$r['page_score_json'], true) ?: [];
        $scoreLow[] = [$r['full_name'], (int)$r['page_score'], mb_strimwidth((string)($j['summary'] ?? ''), 0, 110, '…'), (int)$r['id']];
    }
} catch (Throwable $e) {
}

// Towns.
$towns = [];
$prov = [];
$noTown = 0;
try {
    foreach (fetch_all('SELECT COALESCE(NULLIF(town, ""), "") t, COUNT(*) n,
            SUM(EXISTS (SELECT 1 FROM campaign_submissions s WHERE s.user_id = users.id)) posting
            FROM users WHERE role = "creator" AND status = "active" GROUP BY t ORDER BY n DESC') as $r) {
        if ($r['t'] === '') { $noTown = (int)$r['n']; continue; }
        $towns[] = [$r['t'], (int)$r['n'], (int)$r['posting'] . ' posting'];
        $p = zm_province_of((string)$r['t']) ?: 'Other';
        $prov[$p] = ($prov[$p] ?? 0) + (int)$r['n'];
    }
} catch (Throwable $e) {
}
arsort($prov);
$allTowns = array_merge(...array_values(zm_towns()));
$reached = count(array_intersect($allTowns, array_column($towns, 0)));

// Best in the period.
$topCreators = [];
$topCamps = [];
try {
    foreach (fetch_all('SELECT u.full_name, SUM(e.views_gained) v, SUM(e.amount) a FROM earning_days e JOIN users u ON u.id = e.user_id WHERE e.day >= ? GROUP BY u.id, u.full_name HAVING v > 0 ORDER BY v DESC LIMIT 10', [$from]) as $r) {
        $topCreators[] = [$r['full_name'], (int)$r['v'], money((float)$r['a'])];
    }
    foreach (fetch_all('SELECT c.title, COUNT(s.id) clips, COALESCE(SUM(GREATEST(0, CAST(s.views AS SIGNED) - CAST(COALESCE(s.start_views, s.views) AS SIGNED))),0) v, COALESCE(SUM(s.earned),0) a
                         FROM campaigns c LEFT JOIN campaign_submissions s ON s.campaign_id = c.id AND s.status <> "rejected" AND s.submitted_at >= ? GROUP BY c.id, c.title HAVING clips > 0 ORDER BY v DESC LIMIT 10', [$from]) as $r) {
        $topCamps[] = [$r['title'], (int)$r['v'], (int)$r['clips'] . ' clips, ' . money((float)$r['a'])];
    }
} catch (Throwable $e) {
}

$sum = function (array $s) { return array_sum($s); };
$tiles = [
    ['New creators', num($sum($signups))],
    ['Clips sent', num($sum($clips))],
    ['Views counted', num($sum($views))],
    ['Earned by creators', money($sum($earned))],
    ['Paid out', money($sum($paid))],
    ['Cost per 1,000 views', $sum($views) > 0 ? money($sum($earned) / $sum($views) * 1000) : '–'],
];

require __DIR__ . '/../includes/admin_header.php';
?>
<style>
.an-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin: 14px 0; }
.an-tile { padding: 14px; border: 1px solid var(--ui-line); border-radius: 14px; background: var(--ui-surface); }
.an-tile small { display: block; color: var(--ui-muted); font-size: .8125rem; }
.an-tile strong { font-size: 1.35rem; }
.an-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 14px; }
.an-grid .panel { margin: 0; }
.funnel__row { display: grid; grid-template-columns: minmax(120px, 190px) minmax(0, 1fr) 96px; gap: 10px; align-items: center; padding: 7px 0; color: inherit; text-decoration: none; }
.funnel__bar { height: 22px; border-radius: 6px; background: var(--ui-sunken); overflow: hidden; }
.funnel__bar i { display: block; height: 100%; background: var(--ui-accent); border-radius: 6px; }
.funnel__n { text-align: right; font-weight: 700; }
.funnel__n small { display: block; font-weight: 400; color: var(--ui-muted); font-size: .75rem; }
.rank__row { display: grid; grid-template-columns: minmax(90px, 150px) minmax(0, 1fr) auto; gap: 4px 10px; align-items: center; padding: 6px 0; }
.rank__bar { height: 10px; border-radius: 5px; background: var(--ui-sunken); overflow: hidden; }
.rank__bar i { display: block; height: 100%; background: var(--ui-accent); }
.rank__label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rank__note { grid-column: 1 / -1; color: var(--ui-muted); font-size: .75rem; margin-top: -4px; }
.todo { display: flex; justify-content: space-between; gap: 10px; padding: 9px 0; border-bottom: 1px solid var(--ui-line); color: inherit; text-decoration: none; }
.todo:last-child { border-bottom: 0; }
@media (max-width: 600px) { .funnel__row { grid-template-columns: 110px minmax(0, 1fr) 70px; } }
</style>
<div class="flex-between flex-wrap" style="gap:10px">
    <div><h1 style="margin-bottom:4px">Analytics</h1><p class="muted" style="margin:0">The last <?= $days ?> days.</p></div>
    <div class="flex" style="gap:6px"><?php foreach ([7, 30, 90] as $d): ?><a class="btn btn--sm <?= $d === $days ? 'btn--primary' : 'btn--ghost' ?>" href="<?= e(u('admin/analytics.php?days=' . $d)) ?>"><?= $d ?> days</a><?php endforeach; ?></div>
</div>

<div class="panel" style="margin-top:14px"><div class="panel__head"><h3>Today</h3><span class="badge"><?= e(date('D j M')) ?></span></div><div class="panel__body">
    <div class="an-tiles" style="margin:0">
        <div class="an-tile"><small>Visitors today</small><strong><?= e(num($today['visitors'])) ?></strong><small>yesterday <?= e(num($today['yesterday'])) ?></small></div>
        <div class="an-tile"><small>First time here</small><strong><?= e(num($today['new'])) ?></strong></div>
        <?php // Counted only since the step was recorded (12 Nov, 12:35): divide by the new visitors from that moment, not the whole day.
        $openedSu = 0; $newSince = 0; $suSince = max(strtotime('today'), (int)setting('funnel_since', '0'));
        try {
            $openedSu = (int)fetch_val('SELECT COUNT(*) FROM site_visits WHERE day = CURDATE() AND is_new = 1 AND step >= 1 AND first_at >= FROM_UNIXTIME(?)', [$suSince], 0);
            $newSince = (int)fetch_val('SELECT COUNT(*) FROM site_visits WHERE day = CURDATE() AND is_new = 1 AND first_at >= FROM_UNIXTIME(?)', [$suSince], 0);
        } catch (Throwable $e) {} ?>
        <div class="an-tile"><small>Opened the sign-up page</small><strong><?= e(num($openedSu)) ?></strong><small><?= $newSince > 0 ? 'of ' . num($newSince) . ' new visitors' . ($suSince > strtotime('today') ? ' since ' . date('H:i', $suSince) : '') : '' ?></small></div>
        <div class="an-tile"><small>Signed up today</small><strong><?= e(num($today['signups'])) ?></strong><small><?= $today['new'] > 0 ? round($today['signups'] / $today['new'] * 100) . '% of new visitors' : '' ?></small></div>
        <div class="an-tile"><small>Creators who opened the app</small><strong><?= e(num($today['creators'])) ?></strong></div>
        <div class="an-tile"><small>Online right now</small><strong><?= e(num($today['online'])) ?></strong></div>
        <div class="an-tile"><small>Pages opened</small><strong><?= e(num($today['pages'])) ?></strong></div>
    </div>
    <div class="an-tiles" style="margin:12px 0 0">
        <div class="an-tile"><small>Creators who opened the app this week</small><strong><?= e(num($week['active'])) ?></strong><small>of <?= e(num($week['all'])) ?> creators</small></div>
        <div class="an-tile"><small>Came back on 3 or more days</small><strong><?= e(num($week['loyal'])) ?></strong><small>this week</small></div>
        <div class="an-tile"><small>Sent a clip this week</small><strong><?= e(num($week['posted'])) ?></strong></div>
        <div class="an-tile"><small>Busiest hour</small><strong><?= $busiest !== '' ? e($busiest) : '–' ?></strong><small>best time for news and new clips</small></div>
    </div>
    <p class="small muted" style="margin:10px 0 0">Visitors are counted from 10 November 2026. A visitor is one phone or computer in a day; robots and the HPL team are left out.</p>
</div></div>

<div class="an-tiles"><?php foreach ($tiles as $t): ?><div class="an-tile"><small><?= e($t[0]) ?></small><strong><?= e($t[1]) ?></strong></div><?php endforeach; ?></div>

<div class="an-grid">
    <div class="panel"><div class="panel__head"><h3>Waiting for you now</h3></div><div class="panel__body">
        <?php foreach ($todo as $t): ?><a class="todo" href="<?= e(u($t[2])) ?>"><span><?= e($t[0]) ?></span><strong><?= e(num($t[1])) ?></strong></a><?php endforeach; ?>
    </div></div>
    <div class="panel"><div class="panel__head"><h3>The path of a new creator</h3></div><div class="panel__body">
        <p class="small muted" style="margin-top:0">Creators who signed up in these <?= $days ?> days, and how far they got. A big drop from one line to the next is where people get stuck.</p>
        <?php $top = max(1, $funnel[0][1]); $prev = null; foreach ($funnel as $f): ?>
            <a class="funnel__row" href="<?= e(u($f[2])) ?>"><span><?= e($f[0]) ?></span><span class="funnel__bar"><i style="width:<?= max(1, round($f[1] / $top * 100)) ?>%"></i></span>
                <span class="funnel__n"><?= e(num($f[1])) ?><small><?= $prev === null ? 'everyone' : round($f[1] / $top * 100) . '% of sign-ups' ?></small></span></a>
        <?php $prev = $f[1]; endforeach; ?>
    </div></div>
</div>

<div class="an-grid" style="margin-top:14px">
    <div class="panel"><div class="panel__head"><h3>Visitors per day</h3></div><div class="panel__body"><?= chart_bars($visitors) ?></div></div>
    <div class="panel"><div class="panel__head"><h3>Creators who opened the app per day</h3></div><div class="panel__body"><?= chart_bars($activeCr, '', 'green') ?></div></div>
    <div class="panel"><div class="panel__head"><h3>When people arrive (hour of the day)</h3></div><div class="panel__body"><?= chart_bars($hourSeries) ?></div></div>
    <div class="panel"><div class="panel__head"><h3>New creators per day</h3></div><div class="panel__body"><?= chart_bars($signups) ?></div></div>
    <div class="panel"><div class="panel__head"><h3>Clips sent per day</h3></div><div class="panel__body"><?= chart_bars($clips) ?></div></div>
    <div class="panel"><div class="panel__head"><h3>Views counted per day</h3></div><div class="panel__body"><?= chart_bars($views, '', 'green') ?></div></div>
    <div class="panel"><div class="panel__head"><h3>Money earned by creators per day</h3></div><div class="panel__body"><?= chart_bars($earned, 'K', 'gold') ?></div></div>
    <div class="panel"><div class="panel__head"><h3>Money paid out per day</h3></div><div class="panel__body"><?= chart_bars($paid, 'K', 'gold') ?></div></div>
</div>

<div class="an-grid" style="margin-top:14px">
    <div class="panel"><div class="panel__head"><h3>Where visitors come from</h3></div><div class="panel__body">
        <?= chart_rank($sources) ?>
        <p class="small muted" style="margin-bottom:0">Tip: when you share the link, add <code>?utm_source=whatsapp</code> (or tiktokbio, facebook...) to the end, and it is counted under that name. <?= $phones[1] > 0 ? round($phones[0] / $phones[1] * 100) . '% of visitors are on a phone.' : '' ?></p>
    </div></div>
    <div class="panel"><div class="panel__head"><h3>First page new people see</h3></div><div class="panel__body">
        <?= chart_rank($landings) ?>
        <p class="small muted" style="margin-bottom:0"><?= e(num($newVisitors)) ?> first-time visitors and <?= e(num(array_sum($signups))) ?> sign-ups in these <?= $days ?> days<?= $newVisitors > 0 ? ': ' . round(array_sum($signups) / $newVisitors * 100) . '% signed up' : '' ?>.</p>
    </div></div>
    <div class="panel"><div class="panel__head"><h3>TikTok page scores</h3><span class="badge"><?= $scoreN ? 'average ' . $scoreAvg : 'none yet' ?></span></div><div class="panel__body">
        <?php $sb = []; foreach ($scoreBands as $k => $n) { $sb[] = [$k, $n]; } echo $scoreN ? chart_rank($sb) : '<p class="muted small">Scores appear when creators with a connected TikTok have posted 3 videos.</p>'; ?>
        <p class="small muted" style="margin-bottom:0">Every creator sees their own score and what to fix on their Profile. A low score usually means posting many videos at once.</p>
    </div></div>
    <div class="panel"><div class="panel__head"><h3>Pages that need help</h3></div><div class="panel__body">
        <?php if (!$scoreLow): ?><p class="muted small">Nothing to show yet.</p><?php endif; ?>
        <?php foreach ($scoreLow as $r): ?>
            <a class="todo" href="<?= e(u('admin/creator_detail.php?id=' . $r[3])) ?>"><span><?= e($r[0]) ?><small style="display:block;color:var(--ui-muted)"><?= e($r[2]) ?></small></span><strong><?= $r[1] ?></strong></a>
        <?php endforeach; ?>
    </div></div>
</div>

<div class="an-grid" style="margin-top:14px">
    <div class="panel"><div class="panel__head"><h3>Where your creators are</h3><span class="badge"><?= $reached ?> of <?= count($allTowns) ?> towns</span></div><div class="panel__body">
        <?= chart_rank(array_slice($towns, 0, 15)) ?>
        <?php if ($noTown > 0): ?><p class="small muted" style="margin-bottom:0"><?= num($noTown) ?> creator<?= $noTown === 1 ? ' has' : 's have' ?> not chosen a town yet. They are asked on their Home page.</p><?php endif; ?>
    </div></div>
    <div class="panel"><div class="panel__head"><h3>By province</h3></div><div class="panel__body">
        <?php $pr = []; foreach ($prov as $k => $n) { $pr[] = [$k, $n]; } echo chart_rank($pr); ?>
        <?php $missingProv = array_diff(array_keys(zm_towns()), array_keys($prov)); if ($missingProv && $prov): ?><p class="small muted" style="margin-bottom:0">No creators yet in: <?= e(implode(', ', $missingProv)) ?>.</p><?php endif; ?>
    </div></div>
    <div class="panel"><div class="panel__head"><h3>Best creators (views counted)</h3></div><div class="panel__body"><?= chart_rank($topCreators) ?></div></div>
    <div class="panel"><div class="panel__head"><h3>Campaigns</h3></div><div class="panel__body"><?= chart_rank($topCamps) ?></div></div>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
