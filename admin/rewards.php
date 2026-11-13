<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/rewards.php';
$user = require_admin();
$pageTitle = 'Rewards';
$tab = in_array(get('tab'), ['codes', 'achievements', 'activity'], true) ? get('tab') : 'codes';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'code_create') {
        $code = reward_code_normalize((string)post('code'));
        $amount = round((float)post('amount'), 2);
        $maxUses = max(0, (int)post('max_uses'));
        $expires = trim((string)post('expires_at'));
        $expiresAt = $expires !== '' ? date('Y-m-d H:i:s', strtotime($expires . (strlen($expires) <= 10 ? ' 23:59:59' : ''))) : null;
        $newOnly = post('new_only') === '1' ? 1 : 0;
        $note = mb_substr(trim((string)post('note')), 0, 190);
        if (strlen($code) < 4 || strlen($code) > 40) {
            flash('error', 'The code needs 4 to 40 letters, numbers or dashes.');
        } elseif ($amount <= 0) {
            flash('error', 'Enter the amount each creator gets, for example 10.');
        } elseif ($expiresAt !== null && strtotime($expiresAt) <= time()) {
            flash('error', 'The end date is in the past.');
        } elseif (fetch_one('SELECT id FROM reward_codes WHERE code = ?', [$code])) {
            flash('error', 'A code called ' . $code . ' already exists. Pick another name.');
        } else {
            q('INSERT INTO reward_codes (code, amount, max_uses, expires_at, new_only, note, created_by) VALUES (?,?,?,?,?,?,?)',
                [$code, $amount, $maxUses, $expiresAt, $newOnly, $note ?: null, (int)$user['id']]);
            if (post('announce') === '1') {
                $limitTxt = $maxUses > 0 ? ' First ' . $maxUses . ' creators only!' : '';
                notif_creators('bonus', 'New bonus code: ' . $code, 'Enter ' . $code . ' on your Rewards page to get ' . money($amount) . '.' . $limitTxt, 'creator/rewards.php');
                flash('success', 'Code ' . $code . ' created and all creators were notified.');
            } else {
                flash('success', 'Code ' . $code . ' created. Share it with your creators.');
            }
        }
        redirect('admin/rewards.php?tab=codes');
    } elseif ($action === 'code_toggle') {
        q('UPDATE reward_codes SET active = 1 - active WHERE id = ?', [(int)post('id')]);
        flash('success', 'Code updated.');
        redirect('admin/rewards.php?tab=codes');
    } elseif ($action === 'code_delete') {
        $c = fetch_one('SELECT * FROM reward_codes WHERE id = ?', [(int)post('id')]);
        if ($c && (int)$c['uses'] === 0) {
            q('DELETE FROM reward_codes WHERE id = ?', [(int)$c['id']]);
            flash('warning', 'Code deleted.');
        } else {
            flash('error', 'This code has already been used, so it is kept for your records. Turn it off instead.');
        }
        redirect('admin/rewards.php?tab=codes');
    } elseif ($action === 'achievements_save') {
        $rules = [];
        foreach (achievement_defs() as $k => $d) {
            $rules[$k] = [
                'on' => !empty($_POST['on'][$k]),
                'amount' => max(0.0, round((float)($_POST['amount'][$k] ?? 0), 2)),
            ];
        }
        set_setting('achievement_rules', json_encode($rules));
        flash('success', 'Achievements saved.');
        redirect('admin/rewards.php?tab=achievements');
    }
    redirect('admin/rewards.php');
}

$codes = fetch_all('SELECT * FROM reward_codes ORDER BY created_at DESC LIMIT 200');
$rules = achievement_rules();
$totals = fetch_one('SELECT
        COALESCE(SUM(CASE WHEN kind LIKE "code:%" THEN amount END),0) codes,
        COALESCE(SUM(CASE WHEN kind LIKE "ach:%" THEN amount END),0) ach,
        COALESCE(SUM(CASE WHEN kind = "signup" THEN amount END),0) signup,
        COUNT(*) n
    FROM user_bonuses WHERE status = "claimed"') ?: ['codes' => 0, 'ach' => 0, 'signup' => 0, 'n' => 0];
$activity = $tab === 'activity' ? fetch_all('SELECT b.*, u.full_name FROM user_bonuses b JOIN users u ON u.id = b.user_id WHERE b.status = "claimed" ORDER BY b.claimed_at DESC, b.id DESC LIMIT 150') : [];
$achCounts = [];
foreach (fetch_all('SELECT kind, COUNT(*) n FROM user_bonuses WHERE kind LIKE "ach:%" GROUP BY kind') as $r) {
    $achCounts[substr((string)$r['kind'], 4)] = (int)$r['n'];
}

require __DIR__ . '/../includes/admin_header.php';
?>
<style>
.rwa-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 14px}
.rwa-tabs a{display:inline-flex;align-items:center;height:36px;padding:0 14px;border-radius:999px;border:1px solid var(--ui-line);background:var(--ui-surface);font-weight:600;font-size:.88rem;color:var(--ui-text-2);text-decoration:none}
.rwa-tabs a.is-on{background:var(--ui-espresso);border-color:var(--ui-espresso);color:#fbf3e8}
.rwa-stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin-bottom:14px}
.rwa-stat{padding:12px 14px;border-radius:14px;background:var(--ui-surface);border:1px solid var(--ui-line)}
.rwa-stat b{display:block;font-size:1.15rem}
.rwa-stat small{color:var(--ui-muted)}
.rwa-code{font:700 .95rem/1 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.05em}
.rwa-ach{display:grid;grid-template-columns:auto 1fr auto auto;gap:10px 14px;align-items:center;padding:12px 20px;border-top:1px solid var(--ui-line)}
.rwa-ach:first-child{border-top:0}
.rwa-ach .ico{width:20px;height:20px;color:var(--ui-accent)}
.rwa-ach small{display:block;color:var(--ui-muted)}
.rwa-ach input[type=number]{width:110px}
@media (max-width:600px){.rwa-ach{grid-template-columns:auto 1fr;padding:12px 16px}.rwa-ach>label,.rwa-ach>.rwa-amt{grid-column:2}}
</style>

<h1>Rewards</h1>
<p class="muted">Give creators bonus codes and reward their milestones. Everything credited here goes straight into their wallet and can be withdrawn like normal earnings.</p>

<div class="rwa-stats">
    <div class="rwa-stat"><b><?= e(money((float)$totals['codes'])) ?></b><small>paid through bonus codes</small></div>
    <div class="rwa-stat"><b><?= e(money((float)$totals['ach'])) ?></b><small>paid for achievements</small></div>
    <div class="rwa-stat"><b><?= e(money((float)$totals['signup'])) ?></b><small>sign-up bonuses</small></div>
</div>

<nav class="rwa-tabs">
    <a href="<?= e(u('admin/rewards.php?tab=codes')) ?>" class="<?= $tab === 'codes' ? 'is-on' : '' ?>">Bonus codes</a>
    <a href="<?= e(u('admin/rewards.php?tab=achievements')) ?>" class="<?= $tab === 'achievements' ? 'is-on' : '' ?>">Achievements</a>
    <a href="<?= e(u('admin/rewards.php?tab=activity')) ?>" class="<?= $tab === 'activity' ? 'is-on' : '' ?>">Activity</a>
</nav>

<?php if ($tab === 'codes'): ?>
<div class="panel">
    <div class="panel__head"><h3>Create a bonus code</h3></div>
    <div class="panel__body">
        <form method="post" class="form-grid">
            <?= csrf_field() ?><input type="hidden" name="action" value="code_create">
            <div class="field">
                <label class="label" for="code">Code</label>
                <input class="input rwa-code" id="code" name="code" value="<?= e(reward_code_suggest()) ?>" maxlength="40" autocapitalize="characters" required>
                <div class="field__hint">Letters, numbers and dashes. Make it fun, like LUSAKA10 or WEEKEND50.</div>
            </div>
            <div class="field">
                <label class="label" for="amount">Each creator gets (<?= e(currency()) ?>)</label>
                <input class="input" type="number" step="0.01" min="0.01" id="amount" name="amount" placeholder="10.00" required>
            </div>
            <div class="field">
                <label class="label" for="max_uses">How many creators can use it</label>
                <input class="input" type="number" min="0" id="max_uses" name="max_uses" placeholder="0 = no limit">
                <div class="field__hint">e.g. 20 means the first 20 creators. Each creator can use a code once.</div>
            </div>
            <div class="field">
                <label class="label" for="expires_at">Ends on (optional)</label>
                <input class="input" type="date" id="expires_at" name="expires_at" min="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="note">Note for yourself (optional)</label>
                <input class="input" id="note" name="note" maxlength="190" placeholder="e.g. Posted in the Lusaka campaign announcement">
            </div>
            <div class="field" style="grid-column:1/-1;display:flex;gap:18px;flex-wrap:wrap">
                <label class="flex" style="gap:8px;cursor:pointer"><input type="checkbox" name="announce" value="1" checked> Notify all creators about this code</label>
                <label class="flex" style="gap:8px;cursor:pointer"><input type="checkbox" name="new_only" value="1"> Only for creators who sign up after today</label>
            </div>
            <div class="form-actions" style="grid-column:1/-1"><button class="btn btn--primary" type="submit"><?= icon('sparkles') ?> Create code</button></div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>Your codes</h3><span class="badge"><?= count($codes) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$codes): ?>
            <div class="empty"><p class="muted mb-0">No codes yet. Create your first one above.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Code</th><th>Gives</th><th>Used</th><th>Ends</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($codes as $c): $problem = reward_code_problem($c); ?>
                <tr>
                    <td><span class="rwa-code"><?= e($c['code']) ?></span><?php if ($c['note']): ?><div class="small muted"><?= e($c['note']) ?></div><?php endif; ?><?php if ((int)$c['new_only'] === 1): ?><div class="small muted">New creators only</div><?php endif; ?></td>
                    <td><strong><?= e(money((float)$c['amount'])) ?></strong></td>
                    <td class="small"><?= (int)$c['uses'] ?><?= (int)$c['max_uses'] > 0 ? ' / ' . (int)$c['max_uses'] : '' ?><div class="muted"><?= e(money((float)$c['amount'] * (int)$c['uses'])) ?> paid</div></td>
                    <td class="small muted"><?= $c['expires_at'] ? e(date('j M Y', strtotime((string)$c['expires_at']))) : 'Never' ?></td>
                    <td><?php if ($problem === null): ?><span class="badge ok">Live</span><?php else: ?><span class="badge muted" title="<?= e($problem) ?>"><?= (int)$c['active'] !== 1 ? 'Off' : ((int)$c['max_uses'] > 0 && (int)$c['uses'] >= (int)$c['max_uses'] ? 'Used up' : 'Ended') ?></span><?php endif; ?></td>
                    <td><div class="flex" style="gap:6px">
                        <button type="button" class="btn btn--ghost btn--sm" data-copy="<?= e($c['code']) ?>"><?= icon('copy') ?>Copy</button>
                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="code_toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= (int)$c['active'] === 1 ? 'Turn off' : 'Turn on' ?></button></form>
                        <?php if ((int)$c['uses'] === 0): ?><form method="post" style="display:inline" data-confirm="Delete code <?= e($c['code']) ?>?"><?= csrf_field() ?><input type="hidden" name="action" value="code_delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><button class="btn btn--danger btn--sm" type="submit">Delete</button></form><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
        var t = b.getAttribute('data-copy');
        (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { if (window.hplToast) hplToast('Copied ' + t, 'success'); }, function () { prompt('Copy this code', t); });
    });
});
</script>

<?php elseif ($tab === 'achievements'): ?>
<div class="panel">
    <div class="panel__head"><h3>Achievements</h3></div>
    <div class="panel__body" style="padding-bottom:0">
        <p class="small muted">Creators unlock these automatically as they grow. Set a cash reward to make one claimable (they tap <strong>Claim</strong> on their Rewards page), or leave it at 0 for a badge only. Creators who already reached a milestone can claim its reward too.</p>
    </div>
    <form method="post">
        <?= csrf_field() ?><input type="hidden" name="action" value="achievements_save">
        <?php foreach (achievement_defs() as $k => [$title, $desc, $icon]): $r = $rules[$k]; ?>
            <div class="rwa-ach">
                <?= icon($icon) ?>
                <div><strong><?= e($title) ?></strong><small><?= e($desc) ?><?= !empty($achCounts[$k]) ? ' · claimed by ' . (int)$achCounts[$k] : '' ?></small></div>
                <label class="flex small" style="gap:6px;cursor:pointer"><input type="checkbox" name="on[<?= e($k) ?>]" value="1" <?= $r['on'] ? 'checked' : '' ?>> Show</label>
                <div class="rwa-amt flex small" style="gap:6px"><?= e(currency()) ?><input class="input input--sm" type="number" step="0.01" min="0" name="amount[<?= e($k) ?>]" value="<?= e(number_format($r['amount'], 2, '.', '')) ?>" aria-label="Reward for <?= e($title) ?>"></div>
            </div>
        <?php endforeach; ?>
        <div class="panel__body"><button class="btn btn--primary" type="submit">Save achievements</button></div>
    </form>
</div>

<?php else: ?>
<div class="panel">
    <div class="panel__head"><h3>Latest rewards paid</h3><span class="badge"><?= (int)$totals['n'] ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$activity): ?>
            <div class="empty"><p class="muted mb-0">No rewards paid yet.</p></div>
        <?php else: ?>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>Creator</th><th>Reward</th><th class="num">Amount</th><th>When</th></tr></thead>
            <tbody>
            <?php foreach ($activity as $a): ?>
                <tr>
                    <td><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$a['user_id'])) ?>"><?= e($a['full_name']) ?></a></td>
                    <td class="small"><?= e(reward_label($a)) ?></td>
                    <td class="num"><strong><?= e(money((float)$a['amount'])) ?></strong></td>
                    <td class="small muted"><?= e(time_ago((string)$a['claimed_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
