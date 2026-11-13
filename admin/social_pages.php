<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
require_once __DIR__ . '/../includes/social_pages.php';
$pageTitle = 'Social pages';
$adminId = (int)$user['id'];

$tab = in_array(get('tab'), ['pending', 'approved', 'rejected', 'all'], true) ? get('tab') : 'pending';
$search = trim((string)get('q'));
$back = 'admin/social_pages.php?tab=' . urlencode((string)(post('tab') ?: $tab)) . (post('q') !== '' && post('q') !== null ? '&q=' . urlencode((string)post('q')) : '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id = (int)post('id');
    if ($action === 'checkbio') {
        $pg = fetch_one('SELECT * FROM social_pages WHERE id = ?', [$id]);
        if ($pg) {
            $code = hpl_social_code($pg);
            $r = hpl_bio_check($pg, $code);
            $via = $r['how'] !== '' ? ' (checked via ' . $r['how'] . ')' : '';
            if ($r['found'] === true) { flash('success', sp_label($pg) . ': code ' . $code . ' is in the bio' . $via . '. Safe to approve.'); }
            elseif ($r['found'] === false) { flash('warning', sp_label($pg) . ': code ' . $code . ' is not in the bio yet' . $via . '. Bios can take a minute to update after saving.'); }
            else { flash('info', ($r['tip'] !== '' ? $r['tip'] . ' ' : (!empty($r['detail']) ? $r['detail'] . '. ' : 'This platform does not let websites read profiles. ')) . 'Open the page and look for ' . $code . ' in the bio yourself.'); }
        } else {
            flash('info', 'That submission no longer exists. The creator may have cancelled it.');
        }
    } elseif ($action === 'approve') {
        $r = sp_approve($id, $adminId, 'Verified by the HPL team', ['pending', 'rejected']);
        flash($r['ok'] ? 'success' : 'warning', $r['msg']);
    } elseif ($action === 'reject') {
        $r = sp_reject($id, $adminId, (string)post('reason'), (string)post('note'), ['pending']);
        flash($r['ok'] ? 'success' : 'warning', $r['msg']);
    } elseif ($action === 'revoke') {
        $r = sp_reject($id, $adminId, (string)post('reason'), (string)post('note'), ['approved']);
        flash($r['ok'] ? 'success' : 'warning', $r['msg']);
    } elseif ($action === 'update') {
        $pg = fetch_one('SELECT * FROM social_pages WHERE id = ?', [$id]);
        $platform = post('platform');
        $url = normalize_url((string)post('url'));
        $pageName = mb_substr(trim((string)post('page_name')), 0, 200);
        if (!$pg) {
            flash('info', 'That submission no longer exists.');
        } elseif (!$url || !array_key_exists($platform, sp_platforms())) {
            flash('error', 'Choose a platform and enter a valid profile link.');
        } elseif (($err = hpl_url_platform_error($platform, $url)) !== null) {
            flash('error', $err);
        } else {
            $taken = false;
            foreach (sp_same_page($platform, sp_identity($platform, $url), $id) as $o) {
                if ((int)$o['user_id'] !== (int)$pg['user_id'] && in_array($o['status'], ['pending', 'approved'], true)) { $taken = true; }
            }
            if ($taken) {
                flash('error', 'That page is already linked to another creator. Revoke or delete that one first.');
            } else {
                q('UPDATE social_pages SET platform = ?, url = ?, page_name = ? WHERE id = ?',
                    [$platform, $url, $pageName !== '' ? $pageName : sp_label(['platform' => $platform, 'url' => $url, 'page_name' => null]), $id]);
                flash('success', 'Page details saved. Status is changed with Approve, Reject or Revoke.');
            }
        }
    } elseif ($action === 'delete') {
        $n = q('DELETE FROM social_pages WHERE id = ?', [$id])->rowCount();
        flash($n ? 'warning' : 'info', $n ? 'Submission deleted.' : 'That submission no longer exists.');
    }
    redirect($back);
}

$counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
foreach (fetch_all('SELECT status, COUNT(*) AS n FROM social_pages GROUP BY status') as $c) {
    $counts[$c['status']] = (int)$c['n'];
}
$counts['all'] = array_sum($counts);

$where = [];
$params = [];
if ($tab !== 'all') { $where[] = 'sp.status = ?'; $params[] = $tab; }
if ($search !== '') {
    $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR sp.url LIKE ? OR sp.page_name LIKE ?)';
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
$order = $tab === 'pending' ? 'sp.submitted_at ASC' : 'COALESCE(sp.reviewed_at, sp.submitted_at) DESC';
$limit = 150;
$pages = fetch_all(
    'SELECT sp.*, u.full_name, u.email, r.full_name AS reviewer
     FROM social_pages sp JOIN users u ON u.id = sp.user_id LEFT JOIN users r ON r.id = sp.reviewed_by'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $order . ' LIMIT ' . ($limit + 1),
    $params
);
$more = count($pages) > $limit;
$pages = array_slice($pages, 0, $limit);

// Same page claimed by more than one account? Warn on the card.
$conflicts = [];
foreach ($pages as $p) {
    $others = array_filter(sp_same_page((string)$p['platform'], sp_identity((string)$p['platform'], (string)$p['url']), (int)$p['id']), function ($o) use ($p) {
        return (int)$o['user_id'] !== (int)$p['user_id'] && in_array($o['status'], ['pending', 'approved'], true);
    });
    if ($others) {
        $o = array_values($others)[0];
        $conflicts[(int)$p['id']] = ((string)fetch_val('SELECT full_name FROM users WHERE id = ?', [(int)$o['user_id']], 'another creator')) . ' (' . $o['status'] . ')';
    }
}

$editId = (int)get('edit');
$editPage = $editId > 0 ? fetch_one('SELECT * FROM social_pages WHERE id = ?', [$editId]) : null;
$reasons = sp_reject_reasons();
$reasonLabels = ['code' => 'Code not in the bio', 'personal' => 'Personal Instagram (needs Professional)', 'private' => 'Page private or unreachable',
    'not_owner' => 'Not their own page', 'link' => 'Wrong link (not a profile)', 'other' => 'Other (write below)'];
$platformIcon = function (string $pl): string {
    $known = ['tiktok', 'instagram', 'facebook', 'youtube'];
    return '<span class="ui-dot ui-dot--' . (in_array($pl, $known, true) ? $pl : 'neutral') . '">' . icon(in_array($pl, $known, true) ? $pl : 'link') . '</span>';
};
$hidden = function () use ($tab, $search): string {
    return '<input type="hidden" name="tab" value="' . e($tab) . '"><input type="hidden" name="q" value="' . e($search) . '">';
};

require __DIR__ . '/../includes/admin_header.php';
?>
<link rel="stylesheet" href="<?= e(u('assets/css/sp.css?v=20261108a')) ?>">
<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Social pages</h1>
        <p class="muted mb-0">Confirm each creator owns the page they post from. Oldest requests are at the top.</p>
    </div>
</div>

<?php if ($editPage): ?>
<div class="panel mb-3">
    <div class="panel__head"><h3>Edit <?= e(sp_label($editPage)) ?></h3></div>
    <div class="panel__body">
        <form method="post" class="form-grid">
            <?= csrf_field() ?><?= $hidden() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="id" value="<?= (int)$editPage['id'] ?>">
            <div class="field">
                <label class="label" for="edit_platform">Platform</label>
                <select class="select" id="edit_platform" name="platform">
                    <?php foreach (sp_platforms() as $k => [$label]): ?>
                        <option value="<?= e($k) ?>" <?= $editPage['platform'] === $k ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label class="label" for="edit_page_name">Display name</label>
                <input class="input" type="text" id="edit_page_name" name="page_name" value="<?= e($editPage['page_name'] ?? '') ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="edit_url">Profile link</label>
                <input class="input" type="url" id="edit_url" name="url" value="<?= e($editPage['url']) ?>" required>
                <div class="field__hint">Changing the link keeps the same bio code. Use Approve, Reject or Revoke to change the status, so the creator is told.</div>
            </div>
            <div class="form-actions" style="grid-column:1/-1">
                <button class="btn btn--primary" type="submit">Save</button>
                <a class="btn btn--ghost" href="<?= e(u('admin/social_pages.php?tab=' . $tab)) ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<nav class="spa-tabs" aria-label="Filter by status">
    <?php foreach (['pending' => 'Waiting', 'approved' => 'Verified', 'rejected' => 'Not approved', 'all' => 'All'] as $k => $lbl): ?>
        <a href="<?= e(u('admin/social_pages.php?tab=' . $k . ($search !== '' ? '&q=' . urlencode($search) : ''))) ?>" class="<?= $tab === $k ? 'is-on' : '' ?>"><?= e($lbl) ?> <b><?= (int)$counts[$k] ?></b></a>
    <?php endforeach; ?>
</nav>
<form class="spa-search" method="get">
    <input type="hidden" name="tab" value="<?= e($tab) ?>">
    <input class="input" type="search" name="q" value="<?= e($search) ?>" placeholder="Search creator, email or page">
    <button class="btn btn--ghost" type="submit">Search</button>
</form>

<?php if (!$pages): ?>
    <div class="spa-empty"><?= $search !== '' ? 'Nothing matches "' . e($search) . '".' : ($tab === 'pending' ? 'All caught up. No pages waiting for review.' : 'Nothing here yet.') ?></div>
<?php else: ?>
    <ul class="spa-list">
        <?php foreach ($pages as $p): $code = hpl_social_code($p); $label = sp_label($p); $pid = (int)$p['id']; ?>
            <li class="spa-card">
                <div class="spa-card__top">
                    <?= $platformIcon((string)$p['platform']) ?>
                    <div class="spa-card__main">
                        <div class="spa-card__page">
                            <?= safe_link((string)$p['url'], (string)$label) ?>
                            <?php if ($p['status'] === 'pending'): ?><span class="sp-pill sp-pill--wait">Waiting <?= e(time_ago($p['submitted_at'])) ?></span>
                            <?php elseif ($p['status'] === 'approved'): ?><span class="sp-pill sp-pill--ok"><?= icon('check') ?>Verified</span>
                            <?php else: ?><span class="sp-pill sp-pill--bad">Not approved</span><?php endif; ?>
                        </div>
                        <div class="spa-card__meta">
                            <a href="<?= e(u('admin/creators.php?q=' . urlencode((string)$p['email']))) ?>"><?= e($p['full_name']) ?></a> &middot; <?= e($p['email']) ?>
                            &middot; <?= e(platform_label((string)$p['platform'])) ?> &middot; code <strong><?= e($code) ?></strong>
                            <?php if ($p['status'] !== 'pending'): ?>
                                <br><?= $p['status'] === 'approved' ? 'Approved' : 'Turned down' ?>
                                <?= $p['reviewer'] ? 'by ' . e($p['reviewer']) : (strpos((string)$p['admin_note'], 'Verified automatically') === 0 ? 'automatically' : '') ?>
                                <?= $p['reviewed_at'] ? e(time_ago($p['reviewed_at'])) : '' ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <?php if (isset($conflicts[$pid])): ?>
                    <div class="spa-card__warn">Also claimed by <?= e($conflicts[$pid]) ?>. Only one account can own a page.</div>
                <?php endif; ?>
                <?php if ($p['status'] === 'rejected' && $p['admin_note']): ?>
                    <div class="spa-card__note spa-card__note--bad">Creator was told: <?= e($p['admin_note']) ?></div>
                <?php elseif ($p['status'] === 'approved' && $p['admin_note'] && !in_array($p['admin_note'], ['Approved', 'Verified by the HPL team'], true)): ?>
                    <div class="spa-card__note"><?= e($p['admin_note']) ?></div>
                <?php endif; ?>

                <div class="spa-actions">
                    <?php if ($p['status'] !== 'approved'): ?>
                        <form method="post"><?= csrf_field() ?><?= $hidden() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $pid ?>">
                            <button class="btn btn--primary btn--sm" type="submit" data-busy="Approving…">Approve</button></form>
                    <?php endif; ?>
                    <form method="post"><?= csrf_field() ?><?= $hidden() ?><input type="hidden" name="action" value="checkbio"><input type="hidden" name="id" value="<?= $pid ?>">
                        <button class="btn btn--ghost btn--sm" type="submit" data-busy="Checking…">Check bio</button></form>
                <?php if ($p['status'] !== 'rejected'): $act = $p['status'] === 'approved' ? 'revoke' : 'reject'; ?>
                    <details class="spa-reject">
                        <summary class="btn btn--ghost btn--sm"><?= $act === 'revoke' ? 'Revoke verification…' : 'Reject…' ?></summary>
                        <form method="post">
                            <?= csrf_field() ?><?= $hidden() ?>
                            <input type="hidden" name="action" value="<?= $act ?>"><input type="hidden" name="id" value="<?= $pid ?>">
                            <strong class="small">Tell the creator why</strong>
                            <?php foreach ($reasonLabels as $rk => $rl): if ($rk === 'personal' && $p['platform'] !== 'instagram') { continue; } ?>
                                <label><input type="radio" name="reason" value="<?= e($rk) ?>" required> <span><?= e($rl) ?></span></label>
                            <?php endforeach; ?>
                            <input class="input" type="text" name="note" maxlength="200" placeholder="Extra note for the creator (optional, required for Other)">
                            <div><button class="btn btn--danger btn--sm" type="submit" data-busy="Saving…"><?= $act === 'revoke' ? 'Revoke and notify' : 'Reject and notify' ?></button></div>
                        </form>
                    </details>
                <?php endif; ?>
                    <span class="spa-more">
                        <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/social_pages.php?tab=' . $tab . '&edit=' . $pid . ($search !== '' ? '&q=' . urlencode($search) : ''))) ?>">Edit</a>
                        <form method="post" data-confirm="Delete this submission for <?= e($label) ?>? The creator is not told. Use Reject or Revoke if they should know why.">
                            <?= csrf_field() ?><?= $hidden() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $pid ?>">
                            <button class="btn btn--ghost btn--sm" type="submit">Delete</button></form>
                    </span>
                </div>

            </li>
        <?php endforeach; ?>
    </ul>
    <?php if ($more): ?><p class="muted small mt-2">Showing the first <?= $limit ?>. Use search to find others.</p><?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
