<?php
/**
 * TikTok accounts (owner, 2026-11-12: "imagine 1000 people, am I going to scroll 1000 times?"). Every connected TikTok,
 * searchable, filtered, 50 a page. Admin > Settings > TikTok only shows the ones that need something.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/tiktok.php';
$user = require_admin();
$pageTitle = 'TikTok accounts';

$show = (string)get('show', 'problem');
$shows = [
    'problem' => ['Must reconnect', 't.problem IS NOT NULL AND t.problem <> ""'],
    'nobio'   => ['Bio tag missing', 't.bio_missing_since IS NOT NULL'],
    'old'     => ['Not read for 2 days', '(t.problem IS NULL OR t.problem = "") AND (t.last_sync_at IS NULL OR t.last_sync_at < NOW() - INTERVAL 2 DAY)'],
    'all'     => ['All', '1 = 1'],
];
if (!isset($shows[$show])) $show = 'problem';
$q = trim((string)get('q'));
$where = [$shows[$show][1]];
$params = [];
if ($q !== '') {
    $where[] = '(u.full_name LIKE ? OR t.username LIKE ? OR t.display_name LIKE ?)';
    $like = '%' . ltrim($q, '@') . '%';
    array_push($params, $like, $like, $like);
}
$w = implode(' AND ', $where);
$counts = [];
foreach ($shows as $k => [$label, $sql]) {
    $counts[$k] = (int)fetch_val('SELECT COUNT(*) FROM tiktok_accounts t WHERE ' . $sql, [], 0);
}
$perPage = 50;
$total = (int)fetch_val('SELECT COUNT(*) FROM tiktok_accounts t JOIN users u ON u.id = t.user_id WHERE ' . $w, $params, 0);
$pages = max(1, (int)ceil($total / $perPage));
$pageNo = max(1, min($pages, (int)get('page', 1)));
$rows = fetch_all('SELECT t.id, t.user_id, t.username, t.display_name, t.follower_count, t.following_count, t.video_count, t.problem, t.last_sync_at,
                          t.connected_at, t.bio_missing_since, u.full_name
                   FROM tiktok_accounts t JOIN users u ON u.id = t.user_id WHERE ' . $w . '
                   ORDER BY t.problem IS NULL, t.connected_at DESC LIMIT ' . $perPage . ' OFFSET ' . (($pageNo - 1) * $perPage), $params);
$link = fn(array $over) => u('admin/tiktok_accounts.php?' . http_build_query(array_filter(array_merge(['show' => $show, 'q' => $q], $over), fn($v) => $v !== '' && $v !== null && $v !== 1)));

require __DIR__ . '/../includes/admin_header.php';
?>
<h1>TikTok accounts</h1>
<p class="muted"><?= num($counts['all']) ?> connected. Settings &gt; TikTok shows only the ones that need something; this page has them all.</p>

<div class="flex flex-wrap" style="gap:8px;margin-bottom:12px">
    <?php foreach ($shows as $k => [$label, $sql]): ?>
        <a class="btn btn--sm <?= $show === $k ? 'btn--primary' : 'btn--ghost' ?>" href="<?= e(u('admin/tiktok_accounts.php?show=' . $k)) ?>"><?= e($label) ?> (<?= num($counts[$k]) ?>)</a>
    <?php endforeach; ?>
</div>
<form method="get" class="filter-bar" style="margin-bottom:12px">
    <input type="hidden" name="show" value="<?= e($show) ?>">
    <input class="input" type="text" name="q" value="<?= e($q) ?>" placeholder="Search a name or @username">
    <button class="btn btn--ghost" type="submit">Search</button>
</form>

<div class="panel"><div class="panel__body panel__body--flush">
<?php if (!$rows): ?>
    <div class="empty"><p>Nothing here.</p></div>
<?php else: ?>
    <div class="table-wrap"><table class="table">
        <thead><tr><th>Creator</th><th>TikTok</th><th class="num">Followers</th><th>State</th><th>Last read</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$r['user_id'])) ?>"><?= e($r['full_name']) ?></a></td>
                <td><a href="https://www.tiktok.com/@<?= e((string)$r['username']) ?>" target="_blank" rel="noopener">@<?= e((string)$r['username']) ?></a></td>
                <td class="num"><?= num((int)$r['follower_count']) ?></td>
                <td><?php if ((string)$r['problem'] !== ''): ?><span class="badge bad">Must reconnect (<?= e(tiktok_problem_label((string)$r['problem'])) ?>)</span>
                    <?php elseif (!empty($r['bio_missing_since'])): ?><span class="badge warn">Bio tag missing</span>
                    <?php else: ?><span class="badge ok">Working</span><?php endif; ?></td>
                <td class="small"><?= $r['last_sync_at'] ? e(time_ago((string)$r['last_sync_at'])) : 'never' ?></td>
                <td><a class="btn btn--ghost btn--sm" href="<?= e(u('admin/messages.php?user=' . (int)$r['user_id'])) ?>">Message</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <?php if ($pages > 1): ?>
        <div class="flex" style="gap:8px;justify-content:center;padding:12px">
            <?php if ($pageNo > 1): ?><a class="btn btn--ghost btn--sm" href="<?= e($link(['page' => $pageNo - 1])) ?>">Previous</a><?php endif; ?>
            <span class="small muted" style="align-self:center">Page <?= $pageNo ?> of <?= $pages ?> (<?= num($total) ?>)</span>
            <?php if ($pageNo < $pages): ?><a class="btn btn--ghost btn--sm" href="<?= e($link(['page' => $pageNo + 1])) ?>">Next</a><?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
</div></div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
