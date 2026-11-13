<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Work review';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid = (int)post('portfolio_id');
    $action = post('action');
    $note = mb_substr(post('admin_note'), 0, 250);
    $link = fetch_one('SELECT * FROM portfolio_links WHERE id = ?', [$pid]);
    if (!$link) {
        flash('error', 'Submission not found.');
    } elseif ($action === 'approve') {
        q('UPDATE portfolio_links SET status = "approved", admin_note = ? WHERE id = ?', [$note, $pid]);
        q('UPDATE users SET qualified = 1, portfolio_status = "approved", qualified_at = NOW(), rejection_note = NULL WHERE id = ?', [(int)$link['user_id']]);
        creator_decision_notify((int)$link['user_id'], true);
        flash('success', 'Work approved and creator qualified.');
    } elseif ($action === 'delete') {
        q('DELETE FROM portfolio_links WHERE id = ?', [$pid]);
        if ($link['status'] === 'approved'
            && (int)fetch_val('SELECT COUNT(*) FROM portfolio_links WHERE user_id = ? AND status = "approved"', [(int)$link['user_id']], 0) === 0) {
            q('UPDATE users SET qualified = 0, portfolio_status = "rejected", rejection_note = ? WHERE id = ?', ['Your work sample was removed. Please submit a link to your own video.', (int)$link['user_id']]);
        }
        flash('warning', 'Submission deleted.');
    } elseif ($action === 'reject') {
        q('UPDATE portfolio_links SET status = "rejected", admin_note = ? WHERE id = ?', [$note, $pid]);
        portfolio_after_reject((int)$link['user_id'], (string)$note);
        flash('warning', 'Submission rejected.');
    }
    redirect('admin/portfolio.php?filter=' . urlencode(get('filter', 'pending')));
}

$filter = get('filter', 'pending');
$where = $filter !== 'all' ? 'WHERE p.status = ?' : '';
$params = $filter !== 'all' ? [$filter] : [];
$rows = fetch_all(
    'SELECT p.*, u.full_name, u.email, u.phone, u.qualified FROM portfolio_links p JOIN users u ON u.id = p.user_id ' . $where . ' ORDER BY p.created_at ASC LIMIT 200', // oldest first, 200 at a time (2026-11-12)
    $params
);

require __DIR__ . '/../includes/admin_header.php';
?>

<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Work review</h1>
        <p class="muted mb-0">Approve a creator's work to qualify their account and unlock the library.</p>
    </div>
</div>

<div class="tabs">
    <?php foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $k => $label): ?>
        <a href="<?= e(u('admin/portfolio.php?filter=' . $k)) ?>" class="<?= $filter === $k ? 'is-active' : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
    <div class="panel"><div class="panel__body empty"><div class="empty__icon">&#9989;</div><p>Nothing here. All caught up!</p></div></div>
<?php else: ?>
    <div class="grid-2">
        <?php foreach ($rows as $p): ?>
            <div class="panel">
                <div class="panel__head">
                    <div class="flex">
                        <div class="avatar" style="width:34px;height:34px;font-size:.82rem"><?= e(strtoupper(mb_substr($p['full_name'], 0, 1))) ?></div>
                        <div>
                            <strong class="small"><?= e($p['full_name']) ?></strong>
                            <div class="small muted"><?= e($p['email']) ?> &middot; <?= e($p['phone']) ?></div>
                        </div>
                    </div>
                    <span class="badge <?= status_class($p['status']) ?>"><?= e($p['status']) ?></span>
                </div>
                <div class="panel__body">
                    <p class="flex mb-2"><?= platform_icon($p['platform']) ?> <?= safe_link((string)$p['url'], null, 'truncate') ?></p>
                    <?php if ($p['note']): ?><p class="small muted"><?= e($p['note']) ?></p><?php endif; ?>
                    <?php if ($p['admin_note']): ?><p class="small" style="color:#92400e">Admin note: <?= e($p['admin_note']) ?></p><?php endif; ?>
                    <p class="small muted">Submitted <?= e(time_ago($p['created_at'])) ?></p>
                    <form method="post" class="mb-2" data-confirm="Delete this submission for good? It cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="portfolio_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit">Delete submission</button></form>
                    <?php if ($p['status'] === 'pending'): ?>
                        <div class="flex" style="gap:8px;align-items:stretch">
                            <form method="post" style="flex:1"><?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="portfolio_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ok btn--block btn--sm" type="submit">Approve &amp; qualify</button></form>
                            <form method="post" style="flex:1" class="flex"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="portfolio_id" value="<?= (int)$p['id'] ?>"><input class="input" name="admin_note" placeholder="Reason (optional)" style="padding:6px 10px"><button class="btn btn--danger btn--sm" type="submit">Reject</button></form>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
