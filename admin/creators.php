<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/account_delete.php';
require_once __DIR__ . '/../includes/creator_stages.php';
$user = require_admin();
$pageTitle = 'Creators';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id = (int)post('id');
    $action = post('action');
    if ($action === 'remind') {
        $stage = (string)post('stage');
        $sent = creators_send_reminders($stage);
        if ($sent === null) {
            flash('warning', 'That group was already reminded in the last 24 hours. Try again tomorrow.');
        } else {
            flash('success', 'Reminder sent to ' . $sent . ' creator(s), in their notifications and by email.');
        }
        redirect('admin/creators.php?filter=' . urlencode($stage));
    }
    $target = fetch_one('SELECT * FROM users WHERE id = ? AND role = "creator"', [$id]);
    if (!$target) {
        flash('error', 'Creator not found.');
    } else {
        switch ($action) {
            case 'qualify':
                q('UPDATE users SET qualified = 1, portfolio_status = "approved", qualified_at = NOW(), rejection_note = NULL WHERE id = ?', [$id]);
                creator_decision_notify($id, true);
                flash('success', $target['full_name'] . ' is now qualified.');
                break;
            case 'unqualify':
                q('UPDATE users SET qualified = 0, portfolio_status = "rejected", rejection_note = ? WHERE id = ?', ['Your approval was removed by our team.', $id]);
                creator_decision_notify($id, false, 'Your approval was removed by our team.');
                flash('warning', $target['full_name'] . "'s qualification was revoked. They can send a new video to be checked again.");
                break;
            case 'suspend':
                q('UPDATE users SET status = "suspended" WHERE id = ?', [$id]);
                flash('warning', $target['full_name'] . ' has been suspended.');
                break;
            case 'activate':
                q('UPDATE users SET status = "active" WHERE id = ?', [$id]);
                flash('success', $target['full_name'] . ' has been reactivated.');
                break;
            case 'reset_assessment':
                q('UPDATE users SET assessment_status = "not_taken", assessment_score = NULL WHERE id = ?', [$id]);
                flash('success', 'Assessment reset for ' . $target['full_name'] . '.');
                break;
            case 'delete':
                $err = hpl_delete_account($id);
                if ($err === null) {
                    flash('warning', mb_strimwidth((string)$target['full_name'], 0, 40, '…') . ' and everything they created was deleted.');
                } else {
                    flash('error', $err);
                }
                break;
        }
    }
    redirect('admin/creators.php' . (get('q') ? '?q=' . urlencode(get('q')) : ''));
}

$filter = get('filter', 'all');
if ($filter === 'pending') { $filter = 'no_test'; }   // old link
if ($filter === 'qualified') { $filter = 'approved'; } // old link
$search = get('q');
$stages = creator_stages();

$where = ['role = "creator"'];
$params = [];
if (isset($stages[$filter])) { $where[] = $stages[$filter]['sql']; }
$counts = [];
foreach ($stages as $k => $st) {
    $counts[$k] = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND ' . $st['sql'], [], 0);
}
$counts['all'] = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator"', [], 0);
// The Brain's label for each creator (includes/brain_watch.php): star, steady, volume chaser, clip grabber, stuck, quiet, new.
require_once __DIR__ . '/../includes/brain_watch.php';
$brainTag = (string)get('brain');
if (isset(brain_tag_labels()[$brainTag])) {
    $where[] = 'brain_tag = ?';
    $params[] = $brainTag;
} else {
    $brainTag = '';
}
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ? OR phone LIKE ?)';
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like);
}
$sql = 'SELECT users.*,
        (SELECT COALESCE(SUM(earned),0) FROM content_links WHERE user_id = users.id) + (SELECT COALESCE(SUM(earned),0) FROM campaign_submissions WHERE user_id = users.id)
        + (SELECT COALESCE(SUM(amount),0) FROM user_bonuses WHERE user_id = users.id AND status = "claimed") AS earned,
        (SELECT COALESCE(SUM(views),0) FROM content_links WHERE user_id = users.id) + (SELECT COALESCE(SUM(views),0) FROM campaign_submissions WHERE user_id = users.id) AS views,
        (SELECT COUNT(*) FROM content_links WHERE user_id = users.id) + (SELECT COUNT(*) FROM campaign_submissions WHERE user_id = users.id) AS links
        FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY users.created_at DESC';
// 50 a page (2026-11-12): with thousands of creators the page must not load and add up everyone at once.
$perPage = 50;
$total = (int)fetch_val('SELECT COUNT(*) FROM users WHERE ' . implode(' AND ', $where), $params, 0);
$pages = max(1, (int)ceil($total / $perPage));
$pageNo = max(1, min($pages, (int)get('page', 1)));
$creators = fetch_all($sql . ' LIMIT ' . $perPage . ' OFFSET ' . (($pageNo - 1) * $perPage), $params);
$pageUrl = function (int $p) use ($filter, $search, $brainTag) {
    return u('admin/creators.php?' . http_build_query(array_filter(['filter' => $filter !== 'all' ? $filter : '', 'q' => $search, 'brain' => $brainTag, 'page' => $p > 1 ? $p : ''])));
};
// Where sign-ups come from: their own answers, and which app they signed up in (last 60 days).
require_once __DIR__ . '/../includes/signup_source.php';
$heardRows = fetch_all('SELECT COALESCE(heard_from, "") k, COUNT(*) n FROM users WHERE role = "creator" AND created_at >= NOW() - INTERVAL 60 DAY GROUP BY k ORDER BY n DESC');
$appRows = fetch_all('SELECT COALESCE(NULLIF(signup_app, ""), IF(signup_ref IS NULL OR signup_ref = "", "", CONCAT("link from ", signup_ref))) k, COUNT(*) n
    FROM users WHERE role = "creator" AND created_at >= NOW() - INTERVAL 60 DAY AND (signup_app IS NOT NULL OR signup_ref IS NOT NULL OR heard_at IS NOT NULL OR signup_landing IS NOT NULL) GROUP BY k ORDER BY n DESC LIMIT 8');

require __DIR__ . '/../includes/admin_header.php';
?>

<details class="panel mb-3">
    <summary class="panel__head" style="cursor:pointer"><h3>Where new creators come from (last 60 days)</h3></summary>
    <div class="panel__body small">
        <p style="margin:0 0 6px"><strong>They told us:</strong>
        <?php foreach ($heardRows as $i => $r): ?><?= $i ? ' &middot; ' : '' ?><?= e($r['k'] === '' ? 'not answered yet' : (heard_options()[$r['k']] ?? $r['k'])) ?> <strong><?= (int)$r['n'] ?></strong><?php endforeach; ?></p>
        <?php if ($appRows): ?><p style="margin:0" class="muted"><strong>Signed up from:</strong>
        <?php foreach ($appRows as $i => $r): ?><?= $i ? ' &middot; ' : '' ?><?= e($r['k'] === '' ? 'browser, typed or unknown' : (str_starts_with($r['k'], 'link from') ? $r['k'] : $r['k'] . ' app')) ?> <strong><?= (int)$r['n'] ?></strong><?php endforeach; ?></p><?php endif; ?>
        <p class="muted" style="margin:6px 0 0">New creators answer on the sign-up form. Older accounts and Google sign-ups are asked once on their dashboard.</p>
    </div>
</details>

<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Creators</h1>
        <p class="muted mb-0"><?= e(num($total)) ?> account(s)<?= $brainTag !== '' ? ' labelled "' . e(brain_tag_labels()[$brainTag][0]) . '" by the Brain' : '' ?><?= $pages > 1 ? ', page ' . $pageNo . ' of ' . $pages : '' ?>.</p>
    </div>
    <form method="get" class="filter-bar">
        <input class="input" type="text" name="q" value="<?= e($search) ?>" placeholder="Search name, email, phone">
        <select class="input" name="brain" aria-label="Brain label" style="width:auto"><option value="">Any Brain label</option><?php foreach (brain_tag_labels() as $bk => [$bn, $bt]): ?><option value="<?= e($bk) ?>"<?= $brainTag === $bk ? ' selected' : '' ?>><?= e($bn) ?></option><?php endforeach; ?></select>
        <button class="btn btn--ghost" type="submit">Search</button>
    </form>
</div>

<p class="muted small mb-2">Every creator goes through these steps, left to right. Tap a box to see who is stuck there.</p>
<div class="cfun">
    <a class="cfun__item<?= $filter === 'all' ? ' is-active' : '' ?>" href="<?= e(u('admin/creators.php')) ?>">
        <b><?= e(num($counts['all'])) ?></b><span>Everyone</span><small>All accounts</small>
    </a>
    <?php $i = 0; foreach ($stages as $k => $st): if ($k === 'suspended') continue; $i++; ?>
        <a class="cfun__item cfun__item--<?= e($st['tone']) ?><?= $filter === $k ? ' is-active' : '' ?>" href="<?= e(u('admin/creators.php?filter=' . $k)) ?>">
            <b><?= e(num($counts[$k])) ?></b><span><?= $i ?>. <?= e($st['label']) ?></span><small><?= e($st['hint']) ?></small>
        </a>
    <?php endforeach; ?>
    <a class="cfun__item cfun__item--bad<?= $filter === 'suspended' ? ' is-active' : '' ?>" href="<?= e(u('admin/creators.php?filter=suspended')) ?>">
        <b><?= e(num($counts['suspended'])) ?></b><span>Suspended</span><small>Blocked</small>
    </a>
</div>

<?php if (isset($stages[$filter]) && $filter !== 'all'): $st = $stages[$filter]; ?>
<div class="cfun__bar">
    <div><strong><?= e(num($counts[$filter])) ?> creator(s): <?= e($st['label']) ?></strong> <span class="muted">&middot; <?= e($st['hint']) ?></span></div>
    <?php if ($filter === 'review'): ?>
        <a class="btn btn--primary" href="<?= e(u('admin/portfolio.php?filter=pending')) ?>"><?= icon('check-circle', 'ico ico--sm') ?> Open Work review to approve them</a>
    <?php elseif (!empty($st['next']) && $counts[$filter] > 0 && (int)setting('remind_' . $filter . '_at', 0) > time() - 86400): ?>
        <span class="muted small">Reminded <?= e(date('M j, H:i', (int)setting('remind_' . $filter . '_at', 0))) ?>. You can remind this group again after 24 hours.</span>
    <?php elseif (!empty($st['next']) && $counts[$filter] > 0): ?>
        <form method="post" data-confirm="Send a reminder to all <?= (int)$counts[$filter] ?> creator(s) in this group? They get a notification and an email: &quot;<?= e($st['next'][0]) ?>&quot;.">
            <?= csrf_field() ?><input type="hidden" name="action" value="remind"><input type="hidden" name="stage" value="<?= e($filter) ?>">
            <button class="btn btn--primary" type="submit"><?= icon('send', 'ico ico--sm') ?> Remind all <?= (int)$counts[$filter] ?>: &ldquo;<?= e($st['next'][0]) ?>&rdquo;</button>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<style>
.cfun { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; margin-bottom: 16px; }
.cfun__item { display: flex; flex-direction: column; gap: 2px; padding: 12px 14px; border-radius: 14px; border: 1px solid var(--ui-line, #e7dccd); background: var(--ui-surface, #fff); color: inherit; text-decoration: none; border-top: 4px solid #bbb; }
.cfun__item b { font-size: 24px; line-height: 1.1; }
.cfun__item span { font-weight: 700; }
.cfun__item small { color: var(--ui-muted, #7a6a5a); }
.cfun__item--warn { border-top-color: #e0a23b; }
.cfun__item--ok { border-top-color: #2f8f5b; }
.cfun__item--bad { border-top-color: #c9483b; }
.cfun__item.is-active { outline: 2px solid #c98a4b; background: #fbf4ea; }
.cfun__bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 10px; padding: 12px 14px; margin-bottom: 14px; border-radius: 14px; background: #fbf4ea; border: 1px dashed #c98a4b; }
.cfun__bar form { margin: 0; }
.cfun__bar .btn { min-height: 44px; }
</style>

<div class="panel">
    <div class="panel__body panel__body--flush">
        <?php if (!$creators): ?>
            <div class="empty"><div class="empty__icon">&#128101;</div><p>No creators match.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead>
                    <tr><th>Creator</th><th>Contact</th><th>Step</th><th class="num">Views</th><th class="num">Earned</th><th class="num">Links</th><th>Joined</th><th>Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($creators as $c): ?>
                    <tr>
                        <td>
                            <a href="<?= e(u('admin/creator_detail.php?id=' . $c['id'])) ?>" class="flex">
                                <div class="avatar" style="width:34px;height:34px;font-size:.82rem"><?= e(strtoupper(mb_substr($c['full_name'], 0, 1))) ?></div>
                                <div><strong class="small"><?= e($c['full_name']) ?></strong><div class="small muted"><?= e($c['country'] ?: '—') ?></div>
                                    <?php if (!empty($c['brain_tag']) && isset(brain_tag_labels()[$c['brain_tag']])): [$bn, $bt] = brain_tag_labels()[$c['brain_tag']]; ?><span class="badge <?= e($bt) ?>" title="<?= e((string)$c['brain_tag_why']) ?>"><?= e($bn) ?></span><?php endif; ?></div>
                            </a>
                        </td>
                        <td class="small"><div><?= e($c['email']) ?></div><div class="muted"><?= e($c['phone']) ?></div></td>
                        <td>
                            <?php $cs = creator_stage_of($c); $cst = $stages[$cs]; ?>
                            <span class="badge <?= e($cst['tone']) ?>"><?= e($cst['label']) ?></span>
                            <div class="small muted"><?= e($cst['hint']) ?><?= $c['assessment_score'] !== null ? ' &middot; test ' . (int)$c['assessment_score'] . '%' : '' ?></div>
                            <?php if ($cs === 'review'): ?><a class="small" href="<?= e(u('admin/portfolio.php?filter=pending')) ?>">Review work &rarr;</a><?php endif; ?>
                        </td>
                        <td class="num small"><?= e(num($c['views'])) ?></td>
                        <td class="num small"><?= money($c['earned']) ?></td>
                        <td class="num small"><?= e(num($c['links'])) ?></td>
                        <td class="small muted"><?= e(date('M j, Y', strtotime($c['created_at']))) ?><?php $srcLine = signup_source_line($c); if ($srcLine !== ''): ?><div title="How they heard about us"><?= e($srcLine) ?></div><?php endif; ?></td>
                        <td>
                            <div class="flex" style="gap:6px">
                                <a class="btn btn--primary btn--sm" href="<?= e(u('admin/messages.php?user=' . (int)$c['id'])) ?>">Message</a>
                                <?php if ((int)$c['qualified'] !== 1): ?>
                                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="qualify"><button class="btn btn--ok btn--sm" type="submit">Qualify</button></form>
                                <?php else: ?>
                                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="unqualify"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Revoke qualification for <?= e($c['full_name']) ?>?">Revoke</button></form>
                                <?php endif; ?>
                                <?php if ($c['status'] === 'suspended'): ?>
                                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="activate"><button class="btn btn--sm" type="submit">Activate</button></form>
                                    <?php if (hpl_account_delete_blocker($c) === null): ?>
                                    <form method="post" style="display:inline" data-confirm="Delete this account and everything it created? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn--danger btn--sm" type="submit">Delete</button></form>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="action" value="suspend"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Suspend <?= e($c['full_name']) ?>?">Suspend</button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($pages > 1): ?>
            <div class="flex" style="gap:8px;justify-content:center;padding:12px">
                <?php if ($pageNo > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e($pageUrl($pageNo - 1)) ?>">Previous</a><?php endif; ?>
                <span class="small muted" style="align-self:center">Page <?= $pageNo ?> of <?= $pages ?></span>
                <?php if ($pageNo < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= e($pageUrl($pageNo + 1)) ?>">Next</a><?php endif; ?>
            </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
