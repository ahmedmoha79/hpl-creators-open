<?php
/**
 * "Get your TikTok ready" (owner decision 2026-11-07).
 * Ready clips are finished for the creator; the only work left is posting them on a real, active
 * TikTok account. This page shows the creator's checklist (from ready_account_check) and how to keep
 * the account healthy: a short daily routine and what to do every time they post.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ready_clips.php';
$user = require_creator();
$pageTitle = 'Get your TikTok ready';
$uid = (int)$user['id'];

$tt = ready_tiktok_status($uid, get('recheck') === '1');
$chk = ready_account_check($user, $tt);
$items = $chk['items'];
$doneN = count(array_filter(array_column($items, 'done')));
$total = count($items);
$tag = '@' . ready_tag_handle();
$cooldownH = max(1, (int)round(ready_clip_cooldown_mins() / 60));

// Where to take a ready clip: a joined open campaign with free clips first, then any open one.
$counts = ready_clip_counts();
$goCamp = null;
try {
    $open = fetch_all('SELECT c.id, c.title, (j.id IS NOT NULL) AS joined FROM campaigns c
        LEFT JOIN campaign_joins j ON j.campaign_id = c.id AND j.user_id = ?
        WHERE c.status = "active" AND COALESCE(c.camp_status, "accepting") = "accepting" AND c.total_budget > c.paid_amount
        ORDER BY joined DESC, c.created_at DESC', [$uid]);
    foreach ($open as $c) {
        if (!empty($counts[(int)$c['id']]['free'])) { $goCamp = $c; break; }
    }
} catch (Throwable $e) {
}
$goHref = $goCamp ? 'creator/campaign.php?id=' . (int)$goCamp['id'] . '#campReady' : 'creator/index.php?tab=campaigns';

require __DIR__ . '/../includes/creator_header.php';
?>
<link rel="stylesheet" href="<?= e(u('assets/css/campaign.css?v=20261113d')) ?>">
<div class="ttr">
    <a class="ttr-back" href="<?= e(u('creator/index.php')) ?>"><?= icon('arrow-left') ?>Home</a>
    <h1>Get your TikTok ready</h1>
    <p class="ttr-lead">A real, active account gets more views.</p>
    <details class="ttr-why"><summary><?= icon('play') ?> Why these steps matter (40 seconds)</summary>
        <video controls playsinline preload="none" poster="<?= e(u('assets/media/home/same-clip.jpg')) ?>" src="<?= e(u('assets/media/home/same-clip.mp4?v=20261113a')) ?>"></video></details>
    <style>.ttr-why{margin:0 0 16px}.ttr-why summary{display:inline-flex;gap:8px;align-items:center;cursor:pointer;list-style:none;padding:9px 16px;border:1.5px solid #d7c3a8;border-radius:999px;font-weight:700;font-size:.92rem}.ttr-why summary::-webkit-details-marker{display:none}.ttr-why summary .ico{width:16px;height:16px}.ttr-why video{display:block;width:100%;max-width:320px;aspect-ratio:9/16;margin:12px auto 0;border-radius:14px;background:#000}</style>

    <?php if (($tt['reason'] ?? null) !== null && !in_array($tt['reason'], ['connect', 'bio'], true)): ?>
        <div class="flash flash--warning"><?= e($tt['reason']) ?></div>
    <?php endif; ?>

    <?php if ($chk['ok']): ?>
        <div class="ttr-done">
            <span class="ttr-done__mark"><?= icon('check') ?></span>
            <div>
                <strong>Your TikTok is ready</strong>
                <?php if (!empty($tt['acc']['username'])): ?><span>@<?= e($tt['acc']['username']) ?></span><?php endif; ?>
            </div>
        </div>
        <?php if (($tt['bio'] ?? null) !== null && ready_tag_typed((string)$tt['bio'])): ?>
            <div class="flash flash--warning" role="alert"><?= e(ready_bio_hint((string)$tt['bio'])) ?> <a href="<?= e(u('creator/tiktok_ready.php?recheck=1')) ?>">Check</a></div>
        <?php endif; ?>
        <a class="btn btn--primary btn--block ttr-go" href="<?= e(u($goHref)) ?>"><?= icon('video') ?><?= $goCamp ? 'Take a ready clip' : 'See campaigns' ?></a>
    <?php else: ?>
        <div class="ttr-card">
            <div class="ttr-progress" aria-label="<?= $doneN ?> of <?= $total ?> done">
                <span><?= $doneN ?> of <?= $total ?> done</span>
                <div class="ttr-bar"><i style="width:<?= (int)round($doneN / max(1, $total) * 100) ?>%"></i></div>
            </div>
            <ol class="ttr-list">
                <?php foreach ($items as $k => $it): $now = $k === $chk['next']; ?>
                    <li class="ttr-item<?= $it['done'] ? ' is-done' : ($now ? ' is-now' : '') ?>">
                        <span class="ttr-item__mark"><?= $it['done'] ? icon('check') : ($k === 'warm' && !empty($tt['acc']) ? icon('clock') : '') ?></span>
                        <div class="ttr-item__body">
                            <strong><?= e($it['title']) ?></strong>
                            <?php if (!$it['done']): ?><span><?= e($it['text']) ?></span><?php endif; ?>
                            <?php if ($k === 'bio' && ($tt['bio'] ?? null) !== null && !empty($tt['acc']) && (!$it['done'] || ready_tag_typed((string)$tt['bio']))): ?>
                                <span class="ttr-bio">What TikTok shows us for @<?= e((string)$tt['acc']['username']) ?>: <q><?= e(trim((string)$tt['bio']) !== '' ? mb_substr(trim((string)$tt['bio']), 0, 200) : 'an empty bio') ?></q></span>
                                <span class="ttr-bio-hint"><?= e(ready_bio_hint((string)$tt['bio'])) ?></span>
                            <?php endif; ?>
                            <?php if ($now && $k === 'bio'): ?>
                                <ol class="ttr-tagsteps">
                                    <li><b>Edit profile</b> &gt; <b>Bio</b></li>
                                    <li>Type <b>@hpl</b>, tap <b><?= e(ready_tag_name()) ?></b></li>
                                    <li><b>Save</b></li>
                                </ol>
                            <?php endif; ?>
                            <?php if ($now && $k !== 'warm'): ?>
                                <a class="btn btn--primary btn--sm" href="<?= e(u($it['href'])) ?>"><?= e($it['btn']) ?></a>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    <?php endif; ?>

    <h2 class="ttr-h">Every day</h2>
    <div class="ttr-tiles">
        <div class="ttr-tile"><?= icon('eye') ?><strong>Watch 20 min</strong><span>Gold, mining, Zambia</span></div>
        <div class="ttr-tile"><?= icon('thumbs-up') ?><strong>Like 10 videos</strong><span>Ones you enjoy</span></div>
        <div class="ttr-tile"><?= icon('chat') ?><strong>Comment on 5</strong><span>Real words, not only emojis</span></div>
        <div class="ttr-tile"><?= icon('users') ?><strong>Follow 5 pages</strong><span>In the same topic</span></div>
    </div>

    <h2 class="ttr-h">Every time you post</h2>
    <ol class="ttr-rules">
        <li><b>1</b><span>Own caption + <strong>@hpl</strong>, tap <strong><?= e(ready_tag_name()) ?></strong></span></li>
        <li><b>2</b><span>Reply to every comment</span></li>
        <li><b>3</b><span><?= $cooldownH ?> hours between clips<?= ready_clips_per_day_for((int)$user['id']) > 0 ? ', ' . ready_clips_per_day_for((int)$user['id']) . ' a day' : '' ?></span></li>
    </ol>

    <div class="ttr-avoid">
        <strong><?= icon('alert') ?>Avoid</strong>
        <span>Posting many clips at once</span>
        <span>Deleting clips</span>
        <span>Buying followers</span>
    </div>
</div>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
