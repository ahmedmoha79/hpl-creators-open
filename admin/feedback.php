<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Feedback inbox';
$tab = get('tab') === 'resolved' ? 'resolved' : 'open';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'resolve' && (int)post('id') > 0) {
    csrf_check();
    $f = fetch_one('SELECT * FROM feedback WHERE id = ?', [(int)post('id')]);
    $reply = trim((string)post('reply'));
    if ($f) {
        $st = q('UPDATE feedback SET status = "resolved", resolved_at = NOW() WHERE id = ? AND status = "open"', [(int)$f['id']]);
        if ($st->rowCount() === 1 && (int)$f['user_id'] !== (int)$user['id']) {
            $body = $reply !== '' ? mb_substr($reply, 0, 300) : 'Thanks for helping us improve HPL Creators. Your feedback has been handled.';
            notif_add((int)$f['user_id'], 'feedback', 'Reply to your feedback: ' . mb_strimwidth((string)$f['subject'], 0, 60, '…'), $body, 'creator/feedback.php');
        }
        flash('success', 'Marked as handled' . ($reply !== '' ? ' and your reply was sent to the creator.' : '. The creator was notified.'));
    }
    redirect('admin/feedback.php');
}

$counts = fetch_one('SELECT SUM(status = "open") o, SUM(status = "resolved") r FROM feedback') ?: ['o' => 0, 'r' => 0];
$rows = fetch_all('SELECT f.*, u.full_name, u.email FROM feedback f JOIN users u ON u.id = f.user_id WHERE f.status = ? ORDER BY f.created_at DESC LIMIT 100', [$tab]);

require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Feedback inbox</h1>
<p class="muted">Ideas and problems sent by creators from <strong>Send feedback</strong> in their menu. Mark each one handled; you can add a short reply and the creator gets it as a notification.</p>

<nav class="spa-tabs" style="display:flex;gap:6px;margin:0 0 14px">
    <a class="btn btn--sm <?= $tab === 'open' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= e(u('admin/feedback.php')) ?>">New (<?= (int)$counts['o'] ?>)</a>
    <a class="btn btn--sm <?= $tab === 'resolved' ? 'btn--primary' : 'btn--ghost' ?>" href="<?= e(u('admin/feedback.php?tab=resolved')) ?>">Handled (<?= (int)$counts['r'] ?>)</a>
</nav>

<div class="panel">
    <div class="panel__body--flush">
        <?php if (!$rows): ?>
            <p class="muted" style="padding:22px;margin:0"><?= $tab === 'open' ? 'No new feedback. Creators can send ideas and bug reports from their menu.' : 'Nothing handled yet.' ?></p>
        <?php else: ?>
            <div class="feed">
                <?php foreach ($rows as $f): ?>
                    <div class="feed-item">
                        <span class="feed-item__icon">&#128221;</span>
                        <div class="grow" style="min-width:0">
                            <strong><?= e($f['subject']) ?></strong>
                            <p class="small muted" style="margin:4px 0 6px"><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$f['user_id'])) ?>"><?= e($f['full_name']) ?></a> &middot; <?= time_ago($f['created_at']) ?><?= $f['resolved_at'] ? ' &middot; handled ' . time_ago($f['resolved_at']) : '' ?></p>
                            <p style="margin:0 0 8px;overflow-wrap:anywhere"><?= nl2br(e($f['message'])) ?></p>
                            <?php if ($f['status'] === 'open'): ?>
                                <form method="post" class="flex" style="gap:6px;flex-wrap:wrap">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="resolve">
                                    <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                    <input class="input input--sm" name="reply" maxlength="300" placeholder="Short reply to the creator (optional)" style="flex:1;min-width:180px">
                                    <button class="btn btn--primary btn--sm" type="submit">Mark handled</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
