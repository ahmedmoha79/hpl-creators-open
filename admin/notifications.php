<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Notifications';

$newUsers = fetch_all('SELECT id, full_name, email, created_at FROM users WHERE role = "creator" ORDER BY created_at DESC LIMIT 8');
$pendingPort = fetch_all('SELECT p.id, u.full_name, p.url, p.created_at FROM portfolio_links p JOIN users u ON u.id = p.user_id WHERE p.status = "pending" ORDER BY p.created_at DESC LIMIT 8');
$pendingWdl = fetch_all('SELECT w.id, u.full_name, w.amount, w.requested_at FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = "pending" ORDER BY w.requested_at DESC LIMIT 8');
$openFb = fetch_all('SELECT f.id, u.full_name, f.subject, f.created_at FROM feedback f JOIN users u ON u.id = f.user_id WHERE f.status = "open" ORDER BY f.created_at DESC LIMIT 8');
$dmRows = fetch_all('SELECT t.id, u.full_name, t.updated_at FROM dm_threads t JOIN dm_participants p ON p.thread_id = t.id AND p.user_id <> ? JOIN users u ON u.id = p.user_id ORDER BY t.updated_at DESC LIMIT 8', [(int)$user['id']]);

$counts = [
    'Withdrawals' => count($pendingWdl),
    'Work review' => count($pendingPort),
    'Open feedback' => count($openFb),
    'Unread DMs' => count($dmRows),
];
$attentionCount = array_sum($counts);

require __DIR__ . '/../includes/admin_header.php';
?>

<h1>Notifications</h1>

<?php if ($attentionCount > 0): ?>
    <div class="grid-4 mb-3">
        <?php foreach ($counts as $label => $n): ?>
            <div class="card<?= $n === 0 ? ' is-muted' : '' ?>">
                <div class="card__num" style="<?= $n > 0 ? 'color:var(--primary-2)' : '' ?>"><?= (int)$n ?></div>
                <div class="card__label"><?= e($label) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
function notify_section(string $title, string $empty, array $rows, callable $href): void
{
    ?>
    <div class="panel">
        <div class="panel__head">
            <h3><?= e($title) ?></h3>
            <span class="badge <?= $rows ? 'warn' : 'muted' ?>"><?= count($rows) ?></span>
        </div>
        <div class="panel__body--flush">
            <?php if (!$rows): ?>
                <p class="muted" style="padding:18px 22px"><?= e($empty) ?></p>
            <?php else: ?>
                <div class="table">
                    <?php foreach ($rows as $i => $r): ?>
                        <?php if ($i > 0): ?><div class="table__sep"></div><?php endif; ?>
                        <a class="table-row" href="<?= e($href($r)) ?>">
                            <div class="table-row__main"><?= $r['icon'] ?? '' ?> <span><?= $r['main'] ?></span></div>
                            <div class="table-row__meta"><?= time_ago($r['when']) ?></div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<?php if ($attentionCount === 0): ?>
    <div class="alert alert--ok" style="display:flex;gap:12px;align-items:center">
        <span style="font-size:1.3rem">&#128076;</span>
        <div>
            <strong>All caught up!</strong>
            <div class="small muted">No pending withdrawals, reviews, feedback, or unread messages.</div>
        </div>
    </div>
<?php endif; ?>

<?php
notify_section(
    'Withdrawal requests',
    'No pending withdrawal requests.',
    array_map(fn($r) => ['icon' => '&#127974;', 'main' => '<strong>' . e($r['full_name']) . '</strong> requested <strong>' . money($r['amount']) . '</strong>', 'when' => $r['requested_at']], $pendingWdl),
    fn() => u('admin/withdrawals.php')
);

notify_section(
    'Work to review',
    'No work waiting for review.',
    array_map(fn($r) => ['icon' => '&#9989;', 'main' => '<strong>' . e($r['full_name']) . '</strong> submitted work', 'when' => $r['created_at']], $pendingPort),
    fn() => u('admin/portfolio.php')
);

notify_section(
    'Open feedback',
    'No open feedback.',
    array_map(fn($r) => ['icon' => '&#128221;', 'main' => '<strong>' . e($r['full_name']) . '</strong>: ' . e($r['subject']), 'when' => $r['created_at']], $openFb),
    fn() => u('admin/feedback.php')
);

notify_section(
    'Messages',
    'No recent messages.',
    array_map(fn($r) => ['icon' => '&#9993;', 'main' => 'From <strong>' . e($r['full_name']) . '</strong>', 'when' => $r['updated_at'] ?? ''], $dmRows),
    fn() => u('admin/messages.php')
);

notify_section(
    'New creators',
    'No recent signups.',
    array_map(fn($r) => ['icon' => '&#127881;', 'main' => '<strong>' . e($r['full_name']) . '</strong> <span class="muted">' . e($r['email']) . '</span> joined', 'when' => $r['created_at']], $newUsers),
    fn() => u('admin/creators.php')
);

require __DIR__ . '/../includes/admin_footer.php'; ?>