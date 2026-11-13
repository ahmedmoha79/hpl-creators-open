<?php
/**
 * Inactive creators (2026-11-11): the rules that warn and then pause creators who do nothing.
 * See includes/inactivity.php for exactly what counts as "doing something" and who is never paused.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/inactivity.php';
$user = require_admin();
$pageTitle = 'Inactive creators';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'settings') {
        set_setting('inactivity_on', post('on') === '1' ? '1' : '0');
        $a = max(2, min(60, (int)post('d1')));
        $b = max($a + 2, min(90, (int)post('d2')));
        $c = max($b + 3, min(120, (int)post('d3')));
        set_setting('inactivity_warn1_days', (string)$a);
        set_setting('inactivity_warn2_days', (string)$b);
        set_setting('inactivity_pause_days', (string)$c);
        flash('success', 'Saved: reminder after ' . $a . ' days, last warning after ' . $b . ', paused after ' . $c . '.');
    } elseif ($action === 'run') {
        $r = inactivity_run(true);
        flash('success', $r['skipped'] !== '' ? 'Not run: ' . $r['skipped'] . '.' : 'Done. ' . $r['warn1'] . ' reminders, ' . $r['warn2'] . ' last warnings, ' . $r['paused'] . ' paused, ' . $r['reset'] . ' back to normal.');
    } elseif ($action === 'back' && (int)post('id') > 0) {
        // Open a paused account again: a fresh start (its clock begins today).
        $id = (int)post('id');
        q('UPDATE users SET status = "active", suspend_reason = NULL, inactive_stage = 0, inactive_stage_at = NULL, active_reset_at = NOW() WHERE id = ? AND role = "creator"', [$id]);
        notif_add($id, 'info', 'Welcome back', 'Your HPL account is open again. Take a ready clip and post it in the next few days to keep it open.', 'creator/index.php?tab=campaigns');
        flash('success', 'Account opened again. The creator gets a fresh start from today.');
    } elseif ($action === 'clear' && (int)post('id') > 0) {
        q('UPDATE users SET inactive_stage = 0, inactive_stage_at = NULL, active_reset_at = NOW() WHERE id = ? AND role = "creator"', [(int)post('id')]);
        flash('success', 'Warning removed. Their clock starts again from today.');
    }
    redirect('admin/inactivity.php');
}

[$d1, $d2, $d3] = inactivity_days();
$start = (int)setting('inactivity_start', '0');
$rows = [];
try {
    $rows = fetch_all('SELECT u.id, u.full_name, u.email, u.status, u.suspend_reason, u.inactive_stage, u.inactive_stage_at, u.assessment_status, u.qualified, ' . inactivity_last_sql() . ' AS last_act
        FROM users u WHERE u.role = "creator" AND (u.inactive_stage > 0 OR (u.status = "suspended" AND u.suspend_reason = "inactive")) ORDER BY u.inactive_stage DESC, u.inactive_stage_at DESC LIMIT 400');
} catch (Throwable $e) {
}
$by = [1 => [], 2 => [], 3 => []];
foreach ($rows as $r) {
    $st = $r['status'] === 'suspended' ? 3 : (int)$r['inactive_stage'];
    if ($st >= 1 && $st <= 3) { $by[$st][] = $r; }
}
$soon = 0;
try {
    $soon = (int)fetch_val('SELECT COUNT(*) FROM users u WHERE u.role = "creator" AND u.status = "active" AND u.inactive_stage = 0 AND GREATEST(' . inactivity_last_sql() . ', ?) < ?', [$start ?: time(), time() - ($d1 - 2) * 86400], 0);
} catch (Throwable $e) {
}
$last = json_decode((string)setting('inactivity_last_result', ''), true) ?: null;
$isReady = function ($r) { return ($r['assessment_status'] ?? '') === 'passed' || (int)($r['qualified'] ?? 0) === 1; };
$daysIdle = function ($r) use ($start) { return (int)floor((time() - max((int)$r['last_act'], $start)) / 86400); };

require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Inactive creators</h1>
<p class="muted">Do nothing = 2 warnings, then paused. Money is never touched.</p>

<div class="an-tiles" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin:14px 0">
    <?php foreach ([['Get a reminder within 2 days', $soon], ['Reminded', count($by[1])], ['Last warning', count($by[2])], ['Paused', count($by[3])]] as $t): ?>
        <div class="panel" style="margin:0"><div class="panel__body"><div class="small muted"><?= e($t[0]) ?></div><strong style="font-size:1.4rem"><?= e(num($t[1])) ?></strong></div></div>
    <?php endforeach; ?>
</div>

<div class="panel"><div class="panel__head"><h3>The rules</h3><span class="badge <?= inactivity_on() ? 'ok' : '' ?>"><?= inactivity_on() ? 'On' : 'Off' ?></span></div><div class="panel__body">
    <form method="post" class="flex" style="gap:14px;flex-wrap:wrap;align-items:flex-end">
        <?= csrf_field() ?><input type="hidden" name="action" value="settings">
        <label class="field" style="margin:0;display:flex;gap:10px;align-items:center;flex-basis:100%"><input type="checkbox" name="on" value="1" <?= inactivity_on() ? 'checked' : '' ?> style="width:22px;height:22px"> <strong>Warn and pause inactive creators</strong></label>
        <label class="field" style="margin:0">Reminder after (days)<input class="input" type="number" name="d1" min="2" max="60" value="<?= $d1 ?>" style="width:110px"></label>
        <label class="field" style="margin:0">Last warning after<input class="input" type="number" name="d2" min="4" max="90" value="<?= $d2 ?>" style="width:110px"></label>
        <label class="field" style="margin:0">Paused after<input class="input" type="number" name="d3" min="7" max="120" value="<?= $d3 ?>" style="width:110px"></label>
        <button class="btn btn--primary btn--sm" type="submit">Save</button>
    </form>
    <details style="margin:12px 0 0"><summary class="small" style="cursor:pointer;font-weight:600">How it works</summary>
    <ul class="small" style="margin:8px 0 0;padding-left:18px;display:grid;gap:4px">
        <li>Each step goes to the creator's bell, their phone (if notifications are on) and their email. The last warning names the exact day of the pause, at least 3 days ahead.</li>
        <li>Doing something at any step takes them back to normal at once.</li>
        <li><strong>Never paused automatically:</strong> anyone with money to withdraw, a withdrawal waiting, or a clip waiting for approval. They stay at the last warning for you to decide.</li>
        <li>While there is no ready clip to take, creators who passed the test are not moved to a next step.</li>
        <li>The clock started on <?= $start ? e(date('D j M Y', $start)) : 'the first run' ?>, so nobody was punished for the time before.</li>
        <li>Paused creators see on the sign-in page why, and your WhatsApp number. "Open again" gives them a fresh start.</li>
    </ul></details>
    <form method="post" style="margin-top:12px" class="flex" ><?= csrf_field() ?><input type="hidden" name="action" value="run"><button class="btn btn--ghost btn--sm" type="submit">Run the check now</button>
        <span class="small muted" style="margin-left:10px;align-self:center"><?= $last ? 'Last run ' . e(time_ago(date('Y-m-d H:i:s', (int)$last['at']))) . ': ' . (int)$last['checked'] . ' checked, ' . (int)$last['warn1'] . ' reminded, ' . (int)$last['warn2'] . ' last warnings, ' . (int)$last['paused'] . ' paused' . ((int)$last['protected'] ? ', ' . (int)$last['protected'] . ' kept because of money' : '') : 'Runs by itself every 20 minutes or so (up to 25 creators each time).' ?></span></form>
</div></div>

<?php foreach ([2 => 'Last warning', 1 => 'Reminded', 3 => 'Paused for being inactive'] as $st => $title): if (!$by[$st]) { continue; } ?>
<div class="panel"><div class="panel__head"><h3><?= e($title) ?></h3><span class="badge"><?= count($by[$st]) ?></span></div><div class="panel__body">
    <div style="overflow-x:auto"><table class="table"><thead><tr><th>Creator</th><th>Stage</th><th>Doing nothing for</th><th>Since</th><th></th></tr></thead><tbody>
    <?php foreach ($by[$st] as $r): $why = $st === 2 ? inactivity_protected($r) : ''; ?>
        <tr><td><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$r['id'])) ?>"><?= e($r['full_name']) ?></a><div class="small muted"><?= e($r['email']) ?></div></td>
            <td class="small"><?= $isReady($r) ? 'Passed the test' : 'Not started' ?><?php if ($why !== ''): ?><div><span class="badge warn">Not paused: <?= e($why) ?></span></div><?php endif; ?></td>
            <td><?= $daysIdle($r) ?> days</td>
            <td class="small"><?= !empty($r['inactive_stage_at']) ? e(time_ago((string)$r['inactive_stage_at'])) : '' ?></td>
            <td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <?php if ($st === 3): ?><input type="hidden" name="action" value="back"><button class="btn btn--primary btn--sm" type="submit">Open again</button>
                <?php else: ?><input type="hidden" name="action" value="clear"><button class="btn btn--ghost btn--sm" type="submit">Remove warning</button><?php endif; ?>
            </form></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
</div></div>
<?php endforeach; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
