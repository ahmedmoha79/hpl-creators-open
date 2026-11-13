<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Home';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'sync') {
    csrf_check();
    require_once __DIR__ . '/../includes/view_fetcher.php';
    $s = sync_views();
    $c = sync_campaign_submissions();
    flash('success', 'Views read: ' . ((int)$s['processed'] + (int)$c['processed']) . ' clip(s) updated, ' . money((float)$s['credited'] + (float)$c['credited']) . ' credited.');
    redirect('admin/index.php');
}

$totals = fetch_one(
    'SELECT
        (SELECT COUNT(*) FROM users WHERE role = "creator") AS creators,
        (SELECT COUNT(*) FROM users WHERE role = "creator" AND qualified = 1) AS qualified,
        (SELECT COUNT(*) FROM portfolio_links WHERE status = "pending") AS pending_work,
        (SELECT COUNT(*) FROM withdrawals WHERE status = "pending") AS pending_payouts,
        (SELECT COUNT(*) FROM content_links) AS links,
        (SELECT COALESCE(SUM(views),0) FROM content_links) + (SELECT COALESCE(SUM(views),0) FROM campaign_submissions) AS views,
        (SELECT COALESCE(SUM(earned),0) FROM content_links) + (SELECT COALESCE(SUM(earned),0) FROM campaign_submissions) AS earned,
        (SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE status = "paid") AS paid'
);

require_once __DIR__ . '/../includes/creator_stages.php';
$stages = creator_stages();
$stuck = [];
foreach (['approved', 'no_work', 'no_test', 'failed', 'rejected'] as $k) {
    $stuck[$k] = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND ' . $stages[$k]['sql'], [], 0);
}
$todoPages = (int)fetch_val('SELECT COUNT(*) FROM social_pages WHERE status = "pending"', [], 0);
$todoFeedback = (int)fetch_val('SELECT COUNT(*) FROM feedback WHERE status = "open"', [], 0);
$todoQuestions = 0;
try { $todoQuestions = (int)fetch_val('SELECT COUNT(*) FROM lesson_comments c JOIN users u ON u.id = c.user_id WHERE u.role <> "admin" AND c.deleted_at IS NULL AND c.parent_id IS NULL AND NOT EXISTS (SELECT 1 FROM lesson_comments r JOIN users ru ON ru.id = r.user_id WHERE r.parent_id = c.id AND ru.role = "admin" AND r.deleted_at IS NULL)', [], 0); } catch (Throwable $e) {}
$todoDms = dm_unread_total((int)$user['id']);
$todoClips = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE status = "pending"', [], 0);
// Things only you can do, most urgent first. [count, what, why, button, link]
$todo = [
    [(int)$totals['pending_payouts'], 'withdrawal(s) to pay', 'Creators are waiting for their money.', 'Pay now', 'admin/withdrawals.php'],
    [(int)$totals['pending_work'], 'new creator(s) to approve', 'They sent a work sample and cannot earn until you approve.', 'Approve', 'admin/portfolio.php'],
    [$todoClips, 'campaign clip(s) to check', 'Their money stays locked until you approve the clip. Reject fakes and re-uploads.', 'Check clips', 'admin/campaigns.php?review=1#submissions'],
    [$todoPages, 'social page(s) to check', 'Creators want their TikTok / Facebook page verified.', 'Check', 'admin/social_pages.php'],
    [$todoDms, 'unread private message(s)', 'Creators wrote to you.', 'Read', 'admin/messages.php'],
    [$todoQuestions, 'Academy question(s) to answer', 'Questions left under lessons.', 'Answer', 'admin/lesson_comments.php'],
    [$todoFeedback, 'feedback message(s)', 'Ideas and problems sent by creators.', 'Open', 'admin/feedback.php'],
];
$todoOpen = array_values(array_filter($todo, fn($t) => $t[0] > 0));

$recentCreators = fetch_all('SELECT users.*, (SELECT COUNT(*) FROM content_links WHERE user_id = users.id) + (SELECT COUNT(*) FROM campaign_submissions WHERE user_id = users.id) AS links FROM users WHERE role = "creator" ORDER BY created_at DESC LIMIT 5');
$topLinks = fetch_all('SELECT cl.*, u.full_name FROM content_links cl JOIN users u ON u.id = cl.user_id ORDER BY cl.views DESC LIMIT 5');

require __DIR__ . '/../includes/admin_header.php';
?>

<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Home</h1>
        <p class="muted mb-0">Start here. What needs you is at the top; everything else runs by itself.</p>
    </div>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="sync">
        <button class="btn btn--ghost" type="submit">&#8635; Update views now</button>
    </form>
</div>

<section class="todo mb-3">
    <h2 class="todo__h"><?= $todoOpen ? 'To do now' : 'All done' ?></h2>
    <?php if (!$todoOpen): ?>
        <p class="todo__done"><?= icon('check-circle', 'ico') ?> Nothing is waiting for you. Payouts, approvals and messages are all handled.</p>
    <?php else: ?>
        <ul class="todo__list">
            <?php foreach ($todoOpen as $t): ?>
                <li>
                    <b class="todo__n"><?= (int)$t[0] ?></b>
                    <span class="todo__txt"><strong><?= e($t[1]) ?></strong><small><?= e($t[2]) ?></small></span>
                    <a class="btn btn--primary btn--sm" href="<?= e(u($t[4])) ?>"><?= e($t[3]) ?> &rarr;</a>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>

<section class="todo todo--soft mb-3">
    <h2 class="todo__h">Creators who need a push</h2>
    <ul class="todo__list">
        <?php foreach ($stuck as $k => $n): if ($n === 0) continue; ?>
            <li>
                <b class="todo__n"><?= $n ?></b>
                <span class="todo__txt"><strong><?= e($stages[$k]['label']) ?></strong><small><?= e($stages[$k]['hint']) ?></small></span>
                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/creators.php?filter=' . $k)) ?>">See who</a>
            </li>
        <?php endforeach; ?>
    </ul>
    <a class="btn btn--primary mt-2" href="<?= e(u('admin/send.php')) ?>"><?= icon('send', 'ico ico--sm') ?> Send reminders</a>
</section>

<style>
.todo { padding: 18px; border-radius: 16px; background: #fff; border: 1px solid var(--ui-line, #eadfce); }
.todo--soft { background: #fbf4ea; }
.todo__h { font-size: 18px; margin: 0 0 10px; }
.todo__list { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
.todo__list li { display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 12px; background: var(--ui-sunken, #f7f1e8); }
.todo--soft .todo__list li { background: #fff; }
.todo__n { flex: none; min-width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: #1e140f; color: #e2ad7b; font-size: 18px; }
.todo__txt { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.todo__txt small { color: var(--ui-muted, #7a6a5a); }
.todo__list .btn { flex: none; min-height: 40px; }
.todo__done { display: flex; align-items: center; gap: 8px; margin: 0; color: #2f8f5b; font-weight: 600; }
@media (max-width: 520px) { .todo__list li { flex-wrap: wrap; } .todo__list .btn { width: 100%; justify-content: center; } }
</style>

<div class="stats-grid mb-3">
    <div class="stat-card">
        <div class="stat-card__label">Creators</div>
        <div class="stat-card__value"><?= e(num($totals['creators'])) ?></div>
        <div class="stat-card__meta"><?= e(num($totals['qualified'])) ?> qualified</div>
    </div>
    <div class="stat-card stat-card--accent">
        <div class="stat-card__label">Total views tracked</div>
        <div class="stat-card__value"><?= e(num($totals['views'])) ?></div>
        <div class="stat-card__meta"><?= e(num($totals['links'])) ?> submitted links</div>
    </div>
    <div class="stat-card">
        <div class="stat-card__label">Earnings issued</div>
        <div class="stat-card__value"><?= money($totals['earned']) ?></div>
        <div class="stat-card__meta">lifetime credited</div>
    </div>
    <div class="stat-card">
        <div class="stat-card__label">Paid out</div>
        <div class="stat-card__value"><?= money($totals['paid']) ?></div>
        <div class="stat-card__meta"><?= e(num($totals['pending_payouts'])) ?> pending request(s)</div>
    </div>
</div>

<div class="grid-2" style="margin-top:22px">
    <div class="panel">
        <div class="panel__head"><h3>Newest creators</h3><a class="small" href="<?= e(u('admin/creators.php')) ?>">All</a></div>
        <div class="panel__body panel__body--flush">
            <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($recentCreators as $c): ?>
                <tr>
                    <td><div class="avatar" style="width:32px;height:32px;font-size:.8rem"><?= e(strtoupper(mb_substr($c['full_name'], 0, 1))) ?></div></td>
                    <td><strong class="small"><?= e($c['full_name']) ?></strong><div class="small muted"><?= e($c['email']) ?></div></td>
                    <td><?php $cst = $stages[creator_stage_of($c)] ?? $stages['no_test']; ?><span class="badge <?= e($cst['tone']) ?>"><?= e($cst['label']) ?></span></td>
                    <td class="small muted"><?= e(time_ago($c['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Top performing links</h3><a class="small" href="<?= e(u('admin/links.php')) ?>">All</a></div>
        <div class="panel__body panel__body--flush">
            <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($topLinks as $l): ?>
                <tr>
                    <td><span class="flex"><?= platform_icon($l['platform']) ?><span class="small"><?= e($l['full_name']) ?></span></span></td>
                    <td class="num"><?= e(num($l['views'])) ?> views</td>
                    <td class="num"><?= money($l['earned']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$topLinks): ?><tr><td class="muted small">No links yet.</td></tr><?php endif; ?>
            </tbody></table></div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
