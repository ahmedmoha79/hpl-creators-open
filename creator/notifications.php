<?php
require_once __DIR__ . '/../config.php';
$user = require_creator();
$pageTitle = 'Notifications';
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    notif_mark_all_read($uid);
    flash('success', 'All notifications marked as read.');
    redirect('creator/notifications.php');
}

// Visiting the page counts as opening the bell.
notif_mark_seen($uid);

$perPage = 30;
$page = max(1, (int)get('page', '1'));
$total = 0;
$rows = [];
try {
    $total = (int)fetch_val('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$uid], 0);
    $rows = fetch_all(
        'SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage),
        [$uid]
    );
} catch (Throwable $e) {
}
$unread = 0;
try {
    $unread = (int)fetch_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$uid], 0);
} catch (Throwable $e) {
}
$pages = max(1, (int)ceil($total / $perPage));

$typeIcon = function (string $type): string {
    $map = [
        'withdrawal' => 'wallet', 'payout' => 'wallet', 'earning' => 'banknote', 'verification' => 'shield',
        'social' => 'shield', 'portfolio' => 'check-circle', 'announcement' => 'megaphone', 'campaign' => 'target',
        'message' => 'chat', 'comment' => 'chats', 'tiktok' => 'tiktok', 'course' => 'graduation',
    ];
    return icon($map[$type] ?? 'bell');
};

// Group by day for scanning.
$groups = [];
foreach ($rows as $n) {
    $d = date('Y-m-d', strtotime((string)$n['created_at']));
    $label = $d === date('Y-m-d') ? 'Today' : ($d === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : date('j M Y', strtotime($d)));
    $groups[$label][] = $n;
}

require __DIR__ . '/../includes/creator_header.php';
?>
<div class="ui-page">
    <div class="ui-page-head">
        <div>
            <h1>Notifications</h1>
            <p class="ui-page-head__sub"><?= $unread > 0 ? e(num($unread)) . ' unread' : 'You are all caught up.' ?></p>
        </div>
        <?php if ($unread > 0): ?>
            <form method="post" class="ui-page-head__actions">
                <?= csrf_field() ?>
                <button class="btn btn--ghost" type="submit"><?= icon('check') ?>Mark all as read</button>
            </form>
        <?php endif; ?>
    </div>

    <section class="ui-card">
        <?php if (!$rows): ?>
            <div class="ui-empty"><?= icon('bell') ?><p>No notifications yet. Payments, approvals and replies will show up here.</p></div>
        <?php else: ?>
            <?php foreach ($groups as $label => $items): ?>
                <div class="ui-notif-day"><?= e($label) ?></div>
                <ul class="ui-rows">
                    <?php foreach ($items as $n): ?>
                        <li class="ui-row ui-notif<?= (int)$n['is_read'] === 0 ? ' is-unread' : '' ?>">
                            <span class="ui-dot ui-dot--neutral"><?= $typeIcon((string)$n['type']) ?></span>
                            <a class="ui-row__main ui-notif__link" href="<?= e(u('api/topbar.php?action=open&id=' . (int)$n['id'])) ?>">
                                <span class="ui-row__title"><?= e($n['title']) ?></span>
                                <?php if (!empty($n['body'])): ?><span class="ui-notif__body"><?= e($n['body']) ?></span><?php endif; ?>
                            </a>
                            <div class="ui-row__end"><small title="<?= e(date('j M Y, H:i', strtotime((string)$n['created_at']))) ?>"><?= e(time_ago($n['created_at'])) ?></small></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <?php if ($pages > 1): ?>
        <nav class="ui-pager" aria-label="Pages">
            <?php if ($page > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e(u('creator/notifications.php?page=' . ($page - 1))) ?>"><?= icon('arrow-left') ?>Newer</a><?php endif; ?>
            <span class="muted small">Page <?= $page ?> of <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= e(u('creator/notifications.php?page=' . ($page + 1))) ?>">Older<?= icon('arrow-right') ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<style>
.ui-notif-day { padding: 14px 20px 6px; font-size: .8125rem; font-weight: 600; letter-spacing: .06em; text-transform: none; color: var(--ui-muted); border-top: 1px solid var(--ui-line); }
.ui-notif-day:first-child { border-top: 0; }
.ui-notif { position: relative; }
@media (max-width: 480px) {
    .ui-notif { flex-wrap: wrap; padding-left: 16px; padding-right: 16px; }
    .ui-notif .ui-row__main { flex: 1 1 0; }
    .ui-notif .ui-row__end { flex-basis: 100%; text-align: left; padding-left: 46px; margin-top: -6px; }
}
.ui-notif.is-unread { background: #fdf8f1; }
.ui-notif.is-unread::before { content: ""; position: absolute; left: 8px; top: 50%; width: 6px; height: 6px; margin-top: -3px; border-radius: 50%; background: var(--ui-accent); }
.ui-notif__link { color: inherit; text-decoration: none; display: block; }
.ui-notif__link:hover .ui-row__title { color: var(--ui-accent); }
.ui-notif__body { display: block; color: var(--ui-text-2); font-size: .86rem; margin-top: 2px; }
.ui-notif .ui-row__title { white-space: normal; }
.ui-pager { display: flex; align-items: center; justify-content: center; gap: 14px; margin-top: 16px; }
</style>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
