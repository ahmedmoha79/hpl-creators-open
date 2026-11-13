<?php
/** Admin > Brain (2026-11-12): what the HPL Brain found, ranked, with the proof, the likely cause and what to fix. */
require_once __DIR__ . '/../config.php';
$user = require_admin();
require_once __DIR__ . '/../includes/brain.php';
$pageTitle = 'Brain';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('action');
    if ($act === 'review_one') { // look at one link now and show what the Brain thinks, changing nothing
        require_once __DIR__ . '/../includes/clip_review.php';
        @set_time_limit(90);
        $one = fetch_one('SELECT s.*, c.title FROM campaign_submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE s.id = ?', [(int)post('sub_id')]);
        if ($one) {
            $ev1 = clip_review_evidence($one);
            [$v1, $r1] = clip_review_decide($ev1, 3);
            $lk1 = $ev1['kind'] === 'own edit' ? clip_review_look($ev1, (string)$one['title']) : [];
            if ($lk1 && $lk1['gold'] === 'no') { $v1 = 'reject_recommended'; array_unshift($r1, 'Not HPL gold footage: ' . $lk1['shows']); } elseif ($lk1) { $r1[] = 'It shows: ' . $lk1['shows'] . ' (gold footage: ' . $lk1['gold'] . ')'; }
            flash($v1 === 'approve' ? 'success' : 'warning', 'Clip #' . (int)$one['id'] . ': ' . ['approve' => 'looks right', 'hold' => 'needs your eyes', 'reject_recommended' => 'looks wrong', 'retry' => 'could not check now'][$v1] . '. ' . implode('. ', $r1 ?: $ev1['ok']));
        }
        redirect('admin/brain.php');
    }
    if ($act === 'review_now') { // look at the clip links again now (at most 3 a press: each asks TikTok and Claude)
        require_once __DIR__ . '/../includes/clip_review.php';
        @set_time_limit(170);
        q('UPDATE campaign_submissions SET review_due_at = NOW() WHERE platform = "tiktok" AND status IN ("pending", "approved") AND submitted_at > NOW() - INTERVAL 30 DAY
            AND (review_at IS NULL OR review_at < NOW() - INTERVAL 10 MINUTE) ORDER BY (review_at IS NULL) DESC, review_at ASC LIMIT 3');
        $n = clip_review_work(25.0, 8);
        flash('success', 'The Brain looked at ' . $n . ' clip link' . ($n === 1 ? '' : 's') . '. See the list below.');
        redirect('admin/brain.php');
    }
    if ($act === 'run') {
        $n = count(brain_run(true));
        require_once __DIR__ . '/../includes/brain_mind.php';
        mind_think(true);
        flash('success', 'The Brain looked at everything again: ' . $n . ' thing' . ($n === 1 ? '' : 's') . ' found.');
    } elseif (in_array($act, ['solved', 'muted', 'open'], true)) {
        q('UPDATE brain_findings SET status = ?, resolved_at = IF(? = "open", NULL, NOW()) WHERE id = ?', [$act, $act, (int)post('id')]);
        if ($act === 'solved') { // remembered, and judged in 3 days: did the numbers move?
            require_once __DIR__ . '/../includes/brain_mind.php';
            $ft = (string)fetch_val('SELECT title FROM brain_findings WHERE id = ?', [(int)post('id')], '');
            mind_remember('fixed', 'Marked fixed: ' . $ft, mind_signals_for($ft));
        }
        flash('success', $act === 'muted' ? 'Hidden. The Brain will not bring this one up again.' : ($act === 'solved' ? 'Marked as fixed. If it comes back, the Brain shows it again.' : 'Shown again.'));
    }
    redirect('admin/brain.php' . (get('show') ? '?show=' . urlencode(get('show')) : ''));
}

if (brain_ready() && !fetch_val('SELECT 1 FROM brain_findings LIMIT 1') && (int)setting('brain_last_run', '0') === 0) {
    brain_run(true); // first visit: think once straight away
}
$show = in_array(get('show'), ['solved', 'muted'], true) ? get('show') : 'open';
$rows = brain_ready() ? fetch_all('SELECT * FROM brain_findings WHERE status = ? ORDER BY ' . ($show === 'open' ? 'score DESC' : 'resolved_at DESC') . ' LIMIT 60', [$show]) : [];
$counts = brain_ready() ? fetch_all('SELECT status, COUNT(*) n FROM brain_findings GROUP BY status') : [];
$cnt = array_column($counts, 'n', 'status');
$lastRun = (int)setting('brain_last_run', '0');
$repairs = brain_ready() ? fetch_all('SELECT label, last_at FROM brain_counts WHERE kind = "repair" ORDER BY last_at DESC LIMIT 5') : [];
$areaIcon = ['Bug' => 'alert', 'Confusing step' => 'support', 'People stop' => 'users', 'What people say' => 'chats', 'Clips running out' => 'video', 'Waiting for you' => 'clock', 'Sign-ups' => 'trending', 'Money' => 'banknote'];

require __DIR__ . '/../includes/admin_header.php';
?>
<style>
.br-head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: flex-start; margin-bottom: 14px; }
.br-head h1 { margin: 0 0 4px; }
.br-tabs { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.br-tabs a { padding: 8px 14px; border-radius: 999px; border: 1px solid var(--ui-line-strong, #dccdb9); font-weight: 600; font-size: .88rem; text-decoration: none; color: var(--ui-text, #1f1510); background: var(--ui-surface, #fff); }
.br-tabs a.is-on { background: var(--ui-espresso, #1e140f); border-color: var(--ui-espresso, #1e140f); color: #fff; }
.br-list { display: flex; flex-direction: column; gap: 10px; }
.br-item { background: var(--ui-surface, #fff); border: 1px solid var(--ui-line, #e9dfd1); border-radius: 14px; padding: 14px 16px; display: grid; grid-template-columns: 34px minmax(0, 1fr); gap: 12px; }
.br-rank { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; background: var(--ui-sunken, #f1ebe2); color: var(--ui-text, #1f1510); }
.br-item:nth-child(-n+3) .br-rank { background: #1e140f; color: #e2ad7b; }
.br-meta { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; font-size: .78rem; color: var(--ui-muted, #7a6858); margin-bottom: 4px; }
.br-meta .ico { width: 14px; height: 14px; }
.br-trend { font-weight: 700; } .br-trend--worse, .br-trend--new { color: #b42318; } .br-trend--better { color: #1f7a4d; }
.br-item h3 { font-size: 1rem; margin: 0 0 8px; line-height: 1.35; }
.br-ev { margin: 0 0 8px; padding-left: 18px; font-size: .85rem; color: var(--ui-text-2, #4a3a2e); }
.br-why { font-size: .85rem; margin: 0 0 6px; color: var(--ui-text-2, #4a3a2e); }
.br-fix { font-size: .88rem; margin: 0 0 10px; padding: 8px 10px; border-radius: 10px; background: #e8f3ec; color: #1f5f3d; }
.br-acts { display: flex; gap: 6px; flex-wrap: wrap; } .br-acts form { margin: 0; }
@media (max-width: 520px) { .br-item { grid-template-columns: minmax(0, 1fr); } .br-rank { width: 28px; height: 28px; font-size: .85rem; } }
</style>
<div class="br-head">
    <div>
        <h1>Brain</h1>
        <p class="muted" style="margin:0;max-width:640px">It watches the app like its developer would: what breaks on phones, where people get stuck, what they complain about in the community chat, what runs out, and what waits for you. The most important thing is first. It only reads and suggests; it never changes anything by itself.</p>
    </div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run"><button class="btn btn--ghost btn--sm" type="submit"><?= icon('refresh') ?> Look again now</button><div class="small muted" style="margin-top:4px">Last look: <?= $lastRun ? e(time_ago(date('Y-m-d H:i:s', $lastRun))) : 'never' ?></div></form>
</div>

<?php
// The mind (includes/brain_mind.php): its own words, today against normal, what is coming, what changes did.
require_once __DIR__ . '/../includes/brain_mind.php';
$thought = json_decode((string)setting('mind_thought', ''), true) ?: [];
$sigToday = mind_today(isset($_GET['fresh']));
$ahead = mind_forecast();
$mem = [];
try { $mem = fetch_all('SELECT at, kind, what, verdict FROM brain_changes ORDER BY id DESC LIMIT 8'); } catch (Throwable $e) {}
?>
<?php if ($show === 'open'): ?>
<div class="panel" style="margin-bottom:12px"><div class="panel__body">
    <div class="flex-between flex-wrap" style="gap:8px"><h3 style="font-size:1.05rem;margin:0">What the Brain thinks</h3>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="run"><button class="btn btn--primary btn--sm" type="submit">Think it through now</button></form></div>
    <?php if (!empty($thought['text'])): ?>
        <div style="white-space:pre-line;line-height:1.55;margin-top:8px"><?= e((string)$thought['text']) ?></div>
        <p class="small muted" style="margin:8px 0 0">Written <?= e(time_ago(date('Y-m-d H:i:s', (int)$thought['at']))) ?> from everything below. It thinks again every morning and evening, and sends it to your Telegram.</p>
    <?php else: ?>
        <p class="small muted" style="margin:8px 0 0"><?= trim((string)setting('anthropic_api_key', '')) === '' ? 'Add the Anthropic key in Admin > Telegram and AI help so the Brain can think in words.' : 'Not written yet. Press "Think it through now".' ?><?= setting('mind_thought_error', '') !== '' ? ' Last try: ' . e((string)setting('mind_thought_error')) . '.' : '' ?></p>
    <?php endif; ?>
</div></div>

<div class="panel" style="margin-bottom:12px"><div class="panel__body">
    <h3 style="font-size:1rem;margin:0 0 6px">Today so far against normal</h3>
    <p class="small muted" style="margin:0 0 8px">Normal is learned from the same hours of the last 14 days on this site, not picked by hand.</p>
    <ul style="list-style:none;margin:0;padding:0">
    <?php foreach ($sigToday as $sk => $sv): $bad = $sv['verdict'] !== '' && (($sv['verdict'] === 'low' && $sv['good'] > 0) || ($sv['verdict'] === 'high' && $sv['good'] < 0)); ?>
        <li style="display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-top:1px solid var(--ui-line,#eee)">
            <span><?= e($sv['name']) ?></span>
            <span style="text-align:right;white-space:nowrap"><strong><?= num((int)$sv['today']) ?></strong> <span class="small muted"><?= (int)($sv['days'] ?? 14) < 5 ? '/ still learning (' . (int)($sv['days'] ?? 0) . ' days)' : '/ normal ' . e(rtrim(rtrim(number_format((float)$sv['usual'], 1), '0'), '.')) ?></span>
            <?= $sv['verdict'] === '' ? '' : ' <span class="badge ' . ($bad ? 'bad' : 'ok') . '">' . ($sv['verdict'] === 'high' ? 'high' : 'low') . '</span>' ?></span>
        </li>
    <?php endforeach; ?>
    </ul>
</div></div>

<div class="panel" style="margin-bottom:12px"><div class="panel__body">
    <h3 style="font-size:1rem;margin:0 0 6px">Looking ahead</h3>
    <ul class="small" style="margin:0;padding-left:18px">
        <?php if (isset($ahead['cash_next_7_days'])): $c7 = $ahead['cash_next_7_days']; ?><li>Mobile money to have ready this week: about <strong><?= e(money((float)$c7['already_asked'] + (float)$c7['likely_more'])) ?></strong> (<?= e(money((float)$c7['already_asked'])) ?> already asked, about <?= e(money((float)$c7['likely_more'])) ?> more from <?= (int)$c7['people_likely'] ?> creator<?= (int)$c7['people_likely'] === 1 ? '' : 's' ?> close to the minimum).</li><?php endif; ?>
        <?php if (isset($ahead['clips'])): $cl = $ahead['clips']; ?><li>Free clips: <?= (int)$cl['free'] ?>, taken about <?= e((string)$cl['taken_per_day']) ?> a day<?= $cl['days_left'] !== null ? ' (enough for about ' . e((string)$cl['days_left']) . ' days)' : '' ?>.</li><?php endif; ?>
        <?php if (isset($ahead['about_to_leave'])): ?><li><?= (int)$ahead['about_to_leave'] ?> creator<?= (int)$ahead['about_to_leave'] === 1 ? '' : 's' ?> posted before but nothing for 4 to 10 days: likely to give up soon.</li><?php endif; ?>
    </ul>
</div></div>

<?php if ($mem): ?>
<div class="panel" style="margin-bottom:12px"><div class="panel__body">
    <h3 style="font-size:1rem;margin:0 0 6px">What changed, and what it did</h3>
    <p class="small muted" style="margin:0 0 8px">Every rule change, site update and "I fixed it" is remembered. Three days later the Brain compares the numbers it should move, before and after.</p>
    <ul class="small" style="margin:0;padding-left:18px">
    <?php foreach ($mem as $m): ?><li><strong><?= e((string)$m['what']) ?></strong> <span class="muted">(<?= e(time_ago((string)$m['at'])) ?>)</span> &mdash; <?= $m['verdict'] !== null ? e((string)$m['verdict']) : '<span class="muted">judged ' . e(date('D j M', strtotime((string)$m['at']) + 3 * 86400)) . '</span>' ?></li><?php endforeach; ?>
    </ul>
</div></div>
<?php endif; ?>
<?php endif; ?>

<div class="br-tabs">
    <a href="<?= e(u('admin/brain.php')) ?>" class="<?= $show === 'open' ? 'is-on' : '' ?>">To look at (<?= (int)($cnt['open'] ?? 0) ?>)</a>
    <a href="<?= e(u('admin/brain.php?show=solved')) ?>" class="<?= $show === 'solved' ? 'is-on' : '' ?>">Fixed (<?= (int)($cnt['solved'] ?? 0) ?>)</a>
    <a href="<?= e(u('admin/brain.php?show=muted')) ?>" class="<?= $show === 'muted' ? 'is-on' : '' ?>">Hidden (<?= (int)($cnt['muted'] ?? 0) ?>)</a>
</div>

<?php // Clip reviews (2026-11-13, includes/clip_review.php): what the Brain decided about the latest clip links.
$brReviews = hpl_column_exists('campaign_submissions', 'review_verdict') ? fetch_all('SELECT s.id, s.status, s.url, s.review_verdict, s.review_note, s.review_at, s.review_stage, s.review_by_brain, s.ready_clip_id, u.full_name, c.title
    FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id WHERE s.review_at IS NOT NULL ORDER BY s.review_at DESC LIMIT 12') : [];
$brRevErr = (string)setting('clip_review_last_error', '');
if (hpl_column_exists('campaign_submissions', 'review_verdict')): ?>
    <div class="panel" style="margin-bottom:12px"><div class="panel__body"><h3 style="font-size:1rem;margin:0 0 6px">Clip links the Brain reviewed</h3>
        <?php $brQ = json_decode((string)setting('clip_review_queue', ''), true) ?: []; ?>
        <p class="small muted" style="margin:0 0 8px">It checks what TikTok says about each post (whose account, posted after taking our clip, exactly our clip's length, quality, caption and bio tag, its own sound, not sent before) and approves a ready clip when everything is right. Covers are not used. It never rejects and never takes back an approval you gave. It looks again after 6 hours, 1 day, 3 days and 7 days.<?php if ($brQ): ?> Last run <?= e(time_ago(date('Y-m-d H:i:s', (int)$brQ['at']))) ?>: <?= (int)$brQ['done'] ?> reviewed in <?= e(number_format(((int)$brQ['ms']) / 1000, 1)) ?> s, <?= (int)$brQ['waiting'] ?> waiting.<?php endif; ?></p>
        <?php if (hpl_column_exists('campaign_submissions', 'review_action')): require_once __DIR__ . '/../includes/clip_review.php';
            $brT = fetch_one('SELECT SUM(review_action = "approved") a, SUM(review_action = "rejected") r, SUM(review_action = "told") t, SUM(review_action = "waiting" AND status = "pending") w FROM campaign_submissions WHERE review_at > NOW() - INTERVAL 1 DAY') ?: [];
            $brS = hpl_table_exists('clip_strikes') ? (int)fetch_val('SELECT COUNT(*) FROM clip_strikes WHERE at > NOW() - INTERVAL 1 DAY', [], 0) : 0; ?>
            <p class="small" style="margin:0 0 8px"><strong>Last 24 hours:</strong> <?= (int)($brT['a'] ?? 0) ?> approved, <?= (int)($brT['r'] ?? 0) ?> rejected, <?= (int)($brT['t'] ?? 0) ?> told to fix (24 hours), <?= (int)($brT['w'] ?? 0) ?> waiting for you, <?= $brS ?> strike<?= $brS === 1 ? '' : 's' ?>.<?= (int)setting('brain_enforce_off_until', '0') > time() ? ' <span class="badge bad">Rejecting is stopped until ' . e(date('H:i', (int)setting('brain_enforce_off_until', '0'))) . '</span>' : '' ?></p>
            <details class="small" style="margin:0 0 10px"><summary style="cursor:pointer;font-weight:700">The rulebook: every problem and what the Brain does</summary>
                <table class="table" style="margin-top:6px"><tr><th>Problem</th><th>What happens</th></tr>
                <?php $brProc = ['cheat' => 'Rejected at once, creator told why, 1 strike', 'redo' => 'Rejected at once: "delete it and post it again as it is". Second time in 7 days = 1 strike (never for low quality)', 'fix' => 'Creator told what to fix, 24 hours, then looked at again: fixed = approved, not fixed = rejected', 'person' => 'Waits for you (Telegram summary, no ping)'];
                foreach (clip_rules() as $rr): ?><tr><td><?= e($rr[0]) ?></td><td><?= e($brProc[$rr[1]]) ?></td></tr><?php endforeach; ?>
                <tr><td><strong>Strikes in 30 days</strong></td><td>1 = written warning. 2 = paused from clips 3 days. 3 = account suspended (you are told at once and can open it again).</td></tr>
                <tr><td><strong>Safety valve</strong></td><td>8 or more rejections in an hour, and more than half of what it reviewed: it stops rejecting for 6 hours and tells you.</td></tr></table></details>
        <?php endif; ?>
        <?php foreach ($brReviews as $rv): $v = (string)$rv['review_verdict']; ?>
            <p class="small" style="margin:0 0 6px"><span class="badge <?= $v === 'approve' ? 'ok' : ($v === 'reject_recommended' ? 'bad' : 'warn') ?>"><?= $v === 'approve' ? (!empty($rv['review_by_brain']) ? 'Approved by Brain' : 'Looks right') : ($v === 'rejected' ? 'Rejected by Brain' : ($v === 'reject_recommended' ? 'Looks wrong' : 'Needs you')) ?></span>
                <strong><?= e($rv['full_name']) ?></strong> · <?= e($rv['title']) ?> · <?= !empty($rv['ready_clip_id']) ? 'ready clip' : 'own edit' ?> · now <?= e($rv['status']) ?> · <?= e(time_ago((string)$rv['review_at'])) ?> (look <?= (int)$rv['review_stage'] ?>)<br>
                <span class="muted"><?= e((string)$rv['review_note']) ?></span> <?= safe_link((string)$rv['url'], 'Open post', '') ?></p>
        <?php endforeach; ?>
        <?php if ($brRevErr !== ''): ?><p class="small muted" style="margin:6px 0 0">Last time Claude did not answer: <?= e($brRevErr) ?></p><?php endif; ?>
        <form method="post" style="margin:8px 0 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="review_now"><button class="btn btn--ghost btn--sm" type="submit">Review waiting links now</button></form><form method="post" style="margin:8px 0 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap"><?= csrf_field() ?><input type="hidden" name="action" value="review_one"><label class="small">Ask about clip #<input class="input" name="sub_id" type="number" min="1" style="width:90px;display:inline-block"></label><button class="btn btn--ghost btn--sm" type="submit">Ask the Brain</button><a class="small" href="<?= e(u('admin/links.php?status=pending')) ?>">Clips waiting for you</a></form>
    </div></div>
<?php endif; ?>
<?php if ($repairs && $show === 'open'): ?>
    <div class="panel" style="margin-bottom:12px"><div class="panel__body"><h3 style="font-size:1rem;margin:0 0 6px">What it fixed by itself</h3>
        <ul class="small" style="margin:0;padding-left:18px"><?php foreach ($repairs as $rp): ?><li><?= e($rp['label']) ?> <span class="muted">(<?= e(time_ago((string)$rp['last_at'])) ?>)</span></li><?php endforeach; ?></ul>
    </div></div>
<?php endif; ?>
<?php if (!$rows): ?>
    <div class="panel"><div class="panel__body empty"><div class="empty__icon">&#129504;</div><p><?= $show === 'open' ? 'Nothing needs you right now. The Brain looks again every hour.' : 'Nothing here.' ?></p></div></div>
<?php else: ?>
<div class="br-list">
    <?php foreach ($rows as $i => $f): $ev = json_decode((string)$f['evidence'], true) ?: []; $tr = (string)$f['trend']; ?>
        <article class="br-item">
            <span class="br-rank"><?= $i + 1 ?></span>
            <div>
                <div class="br-meta"><?= icon($areaIcon[$f['area']] ?? 'info') ?><span><?= e($f['area']) ?></span>
                    <?php if ($tr !== '' && $tr !== 'about the same'): ?><span class="br-trend br-trend--<?= e(explode(' ', $tr)[0] === 'getting' ? explode(' ', $tr)[1] : $tr) ?>"><?= e(ucfirst($tr)) ?></span><?php endif; ?>
                    <span>first seen <?= e(time_ago((string)$f['first_seen'])) ?></span></div>
                <h3><?= e($f['title']) ?></h3>
                <?php if ($ev): ?><ul class="br-ev"><?php foreach ($ev as $line): ?><li><?= e((string)$line) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <p class="br-why"><strong>Likely cause:</strong> <?= e((string)$f['cause']) ?></p>
                <p class="br-fix"><strong>What to do:</strong> <?= e((string)$f['fix']) ?></p>
                <div class="br-acts">
                    <?php if ($show === 'open'): ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="solved"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn--ok btn--sm" type="submit">I fixed it</button></form>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="muted"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">Not a problem, hide it</button></form>
                    <?php else: ?>
                        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="open"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">Show again</button></form>
                    <?php endif; ?>
                </div>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// What the Brain watches (includes/brain_watch.php): one line each, green when fine, with its own cost.
require_once __DIR__ . '/../includes/brain_watch.php';
$wState = json_decode((string)setting('brain_watch_state', '{}'), true) ?: [];
$pCount = json_decode((string)setting('brain_people_counts', '{}'), true) ?: [];
$runMs = (int)setting('brain_run_ms', '0');
?>
<details class="panel" style="margin-top:14px" <?= $show === 'open' ? 'open' : '' ?>>
    <summary class="panel__head" style="cursor:pointer"><h3>What the Brain watches</h3><span class="badge"><?= count(brain_watch_list()) ?> things<?= $runMs ? ' &middot; last look took ' . e(number_format($runMs / 1000, 1)) . ' s' : '' ?></span></summary>
    <div class="panel__body">
        <ul style="list-style:none;margin:0;padding:0">
        <?php foreach (brain_watch_list() as $k => [$label, $fn, $every]): $st = $wState[$k] ?? null; ?>
            <li style="display:flex;gap:10px;align-items:flex-start;padding:8px 0;border-top:1px solid var(--ui-line,#eee)">
                <span style="width:10px;height:10px;border-radius:50%;margin-top:6px;flex:none;background:<?= $st === null ? '#bbb' : ($st['ok'] ? '#2e9d5b' : '#d9822b') ?>"></span>
                <span style="flex:1;min-width:0"><strong><?= e($label) ?></strong><br><span class="small muted"><?= $st === null ? 'Not checked yet' : e((string)$st['note']) . ' &middot; checked ' . e(time_ago(date('Y-m-d H:i:s', (int)$st['at']))) . ', every ' . e($every >= 3600 ? ($every / 3600) . ' h' : ($every / 60) . ' min') . ', ' . (int)$st['ms'] . ' ms' ?></span></span>
            </li>
        <?php endforeach; ?>
        </ul>
        <?php if ($pCount): $labels = brain_tag_labels(); ?>
            <p class="small" style="margin:12px 0 4px"><strong>People it knows</strong> (labels only the team sees):</p>
            <div class="flex flex-wrap" style="gap:6px">
                <?php foreach ($labels as $tag => [$name, $tone]): if (empty($pCount[$tag])) continue; ?>
                    <a class="badge <?= e($tone) ?>" href="<?= e(u('admin/creators.php?brain=' . $tag)) ?>"><?= e($name) ?>: <?= (int)$pCount[$tag] ?></a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="small muted" style="margin:12px 0 0">Each check runs on its own schedule and the whole look stops after a few seconds, so the Brain never slows the site. It tunes the background view reader by itself (now <?= (int)setting('sync_batch', '40') ?> clips every <?= (int)setting('autosync_minutes', '30') ?> minutes).</p>
    </div>
</details>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
