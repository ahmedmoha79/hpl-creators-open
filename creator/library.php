<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/campaign_help.php';
$user = require_creator();
$uid = (int)$user['id'];
$pageTitle = 'Footage to download';

$items = fetch_all('SELECT * FROM footage WHERE is_active = 1 ORDER BY uploaded_at DESC');
$myDownloads = (int)fetch_val('SELECT COUNT(*) FROM footage_downloads WHERE user_id = ?', [$uid], 0);
$joins = [];
foreach (fetch_all('SELECT campaign_id FROM campaign_joins WHERE user_id = ?', [$uid]) as $r) { $joins[(int)$r['campaign_id']] = true; }

// Group footage by campaign so creators see which videos belong to which campaign.
$groups = [];
foreach ($items as $f) {
    $groups[(int)($f['campaign_id'] ?? 0)][] = $f;
}
$campRows = [];
$ids = array_filter(array_keys($groups));
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    foreach (fetch_all('SELECT id, title, status, camp_status, total_budget, paid_amount FROM campaigns WHERE id IN (' . $ph . ')', array_values($ids)) as $c) { $campRows[(int)$c['id']] = $c; }
}
// Joined campaigns first, then other campaigns, then general footage last.
uksort($groups, function ($a, $b) use ($joins) {
    $ka = $a === 0 ? 2 : (empty($joins[$a]) ? 1 : 0);
    $kb = $b === 0 ? 2 : (empty($joins[$b]) ? 1 : 0);
    return $ka <=> $kb ?: $b <=> $a;
});

require __DIR__ . '/../includes/creator_header.php';
?>
<div class="ui-page">
    <div class="ui-page-head">
        <div>
            <h1>Footage to download</h1>
            <p>Footage is the raw HPL video you turn into your own short clip. Every video from every campaign is here in one place.<?= $myDownloads ? ' You have downloaded ' . e(num($myDownloads)) . '.' : '' ?></p>
        </div>
    </div>

    <ol class="ez-strip" aria-label="What to do with footage">
        <li><span><?= icon('download') ?></span>Download</li>
        <li><span><?= icon('film') ?></span>Edit a short clip</li>
        <li><span><?= icon('video') ?></span>Post on your page</li>
        <li><span><?= icon('target') ?></span>Submit link in the campaign</li>
    </ol>

    <?php if (!$items): ?>
        <section class="ui-card"><div class="ui-empty"><?= icon('film') ?><p>No footage has been uploaded yet. When it is, you will get a notification and it will appear here and on the campaign page.</p>
            <a class="btn btn--primary btn--sm mt-2" href="<?= e(u('creator/index.php?tab=campaigns')) ?>">See campaigns</a></div></section>
    <?php endif; ?>

    <?php foreach ($groups as $cid => $list):
        $camp = $cid ? ($campRows[$cid] ?? null) : null;
        if ($cid && !$camp) { continue; }
        $joined = $cid && !empty($joins[$cid]);
        $open = $camp && $camp['status'] === 'active' && ($camp['camp_status'] ?? 'accepting') === 'accepting' && (float)$camp['total_budget'] - (float)$camp['paid_amount'] > 0;
    ?>
        <section class="ui-card ez-fgroup">
            <div class="ui-card__head">
                <div>
                    <h2><?= icon($cid ? 'target' : 'film') ?><?= e($cid ? $camp['title'] : 'Extra HPL footage') ?></h2>
                    <p class="ui-card__sub">
                        <?php if (!$cid): ?>General footage. Use it in any campaign you joined.
                        <?php elseif ($joined): ?><span class="ez-tag ez-tag--ok">You joined</span> Post your clip, then submit its link on the campaign page.
                        <?php elseif ($open): ?>Join this campaign to get paid for clips made from this footage.
                        <?php else: ?>This campaign is closed.<?php endif; ?>
                    </p>
                </div>
                <?php if ($cid): ?>
                    <a class="btn <?= $joined ? 'btn--primary' : 'btn--ghost' ?> btn--sm" href="<?= e(u('creator/campaign.php?id=' . $cid)) ?>"><?= $joined ? 'Submit clip' : ($open ? 'Join' : 'View') ?></a>
                <?php endif; ?>
            </div>
            <ul class="ez-flist">
                <?php foreach ($list as $f):
                    $drive = trim((string)($f['drive_url'] ?? '')); $hasFile = !empty($f['file_name']);
                    $can = footage_user_can_download($user, $f);
                ?>
                    <li class="ez-fitem">
                        <span class="ez-fitem__ico"><?= icon('play') ?></span>
                        <div class="ez-fitem__txt">
                            <strong><?= e($f['title']) ?></strong>
                            <small><?= e($f['description'] ?: 'Raw footage ready to edit.') ?><?php if ($hasFile): ?> &middot; <?= e(number_format((float)$f['file_size'] / 1048576, 1)) ?> MB<?php endif; ?></small>
                        </div>
                        <?php if (!$drive && !$hasFile): ?>
                            <span class="ez-tag">Coming soon</span>
                        <?php elseif (!$can && !$cid): ?>
                            <span class="ez-tag">After you are approved</span>
                        <?php elseif (!$can): ?>
                            <a class="btn btn--ghost btn--sm" href="<?= e(u('creator/campaign.php?id=' . $cid)) ?>">Join to download</a>
                        <?php else: ?>
                            <a class="btn btn--primary btn--sm" href="<?= e(u('creator/library_download.php?id=' . (int)$f['id'])) ?>"<?= $drive ? ' target="_blank" rel="noopener"' : '' ?>><?= icon('download') ?>Download</a>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
