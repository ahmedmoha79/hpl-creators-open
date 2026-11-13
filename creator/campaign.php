<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/campaign_help.php';
require_once __DIR__ . '/../includes/ready_clips.php';
$user = require_creator();
$id = (int)get('id');
$campaign = null;
if ($id > 0) {
    $campaign = fetch_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
}
if ($campaign) {
    // TikTok first (owner decision 2026-11-07): only platforms that are open now are shown.
    $platforms = array_values(array_filter(campaign_platform_list($campaign), fn($p) => platform_is_live($p['key'])));

    $refs = json_decode($campaign['reference_links'] ?: '[]', true) ?: [];
    $reqs = json_decode($campaign['requirements'] ?: '[]', true) ?: [];
} else {
    $platforms = []; $refs = []; $reqs = [];
}
$joined = false;
if ($campaign) {
    $j = fetch_one('SELECT id FROM campaign_joins WHERE campaign_id = ? AND user_id = ?', [$id, (int)$user['id']]);
    $joined = !empty($j);
}
$total = (float)($campaign['total_budget'] ?? 0);
$paid = (float)($campaign['paid_amount'] ?? 0);
$remaining = max(0, $total - $paid);
$progress = $total > 0 ? min(100, ($paid / $total) * 100) : 0;
$curreny = (string)($campaign['currency'] ?? 'K');
$accepting = ($campaign['status'] ?? 'active') === 'active' && ($campaign['camp_status'] ?? 'accepting') === 'accepting' && $remaining > 0;
$almostFull = $total > 0 && ($remaining / $total) < 0.1; // same "almost full" line as the Campaigns tab
// Passing the test is enough to join and post READY clips (owner decision 2026-11-05).
// The raw footage, and sending clips you edited yourself, still need full approval (test + one edited video).
$needApproval = (int)($user['qualified'] ?? 0) !== 1;
$canPost = creator_can_post_ready($user);
$needTest = !$canPost;
$canAct = $accepting && $canPost;
$approvalUrl = onboarding_url($user);
if ($approvalUrl === 'creator/index.php') {
    $approvalUrl = (($user['portfolio_status'] ?? '') === 'pending') ? 'creator/index.php' : 'creator/portfolio.php';
}
$approvalText = ($user['assessment_status'] ?? '') !== 'passed' ? 'Take the short test'
    : ((($user['portfolio_status'] ?? '') === 'pending') ? 'We are checking your video' : 'Send one video you made');
$empty = $remaining <= 0;

$mySubs = [];
if ($campaign) {
    try {
        $mySubs = fetch_all('SELECT * FROM campaign_submissions WHERE campaign_id = ? AND user_id = ? ORDER BY submitted_at DESC, id DESC', [$id, (int)$user['id']]);
    } catch (Throwable $e) {
    }
}
$myViews = 0; $myEarned = 0.0;
// Views counted = views gained since the clip was sent (rejected clips count nothing), the same number every page shows.
foreach ($mySubs as $s0) { if ($s0['status'] !== 'rejected') { $myViews += max(0, (int)$s0['views'] - (int)($s0['start_views'] ?? $s0['views'])); } $myEarned += (float)$s0['earned']; }
$capLimit = $campaign ? campaign_creator_cap($campaign) : 0.0;
$capReached = $capLimit > 0 && $myEarned >= $capLimit;
$capPct = $capLimit > 0 ? min(100, ($myEarned / $capLimit) * 100) : 0;
$maxPerWithdrawal = payout_max();
$syncMinutes = max(10, (int)setting('autosync_minutes', '30')); // same floor as the reader itself

// "Start here" checklist data
$footage = $campaign ? campaign_footage($id) : [];
$readyStyles = $campaign ? ready_clip_style_counts($id) : [];
$readyStyle = ready_clip_style_clean((string)get('style'));
if ($readyStyle !== '' && !isset($readyStyles[$readyStyle])) { $readyStyle = ''; }
$ready = $campaign ? ready_clips_for($id, (int)$user['id'], (int)get('clips'), 12, $readyStyle) : ['free' => [], 'mine' => [], 'free_total' => 0, 'page' => 0, 'pages' => 1];
$readyUnposted = array_values(array_filter($ready['mine'], fn($r) => ($r['submission_id'] === null || ($r['sub_status'] ?? '') === 'rejected') && (int)$r['is_active'] === 1));
$readyHeldAll = $campaign ? ready_clips_unposted((int)$user['id']) : 0; // across all campaigns, like the server
$readyHoldMax = ready_clip_hold_max();
$readyNextTake = ($campaign && $canPost) ? ready_clip_next_take_at((int)$user['id']) : 0; // 0 = can take now
$readyCooldown = $readyNextTake > time(); // spacing wait between posts is running
// TikTok rules for ready clips: connected TikTok + HPL in the bio (checked live, at most every 10 minutes).
$readyTT = ($campaign && $canPost) ? ready_tiktok_status((int)$user['id'], get('recheck') === '1') : ['acc' => null, 'bio' => null, 'bio_ok' => false, 'reason' => 'connect'];
$readyOk = $readyTT['reason'] === null;
// "TikTok ready" (2026-11-07): ready clips need a real, active account (follows people, warmed up).
$readyChk = ($campaign && $canPost) ? ready_account_check($user, $readyTT) : ['ok' => false, 'items' => [], 'next' => 'test', 'warm_until' => 0];
$readyAcctOk = $readyChk['ok'];
$readyDoneN = count(array_filter(array_column($readyChk['items'], 'done')));
$readyTotalN = count($readyChk['items']);
$hasReady = (bool)($ready['free'] || $ready['mine']);
$heldClip = null; // a ready clip the creator took here but has not sent a link for yet
foreach ($readyUnposted as $r0) { if ($r0['submission_id'] === null) { $heldClip = $r0; break; } }
$readyCanTake = $canAct && $readyAcctOk && $readyHeldAll < $readyHoldMax && !$readyCooldown;
$downloaded = false;
if ($footage) {
    $fph = implode(',', array_fill(0, count($footage), '?'));
    $downloaded = (int)fetch_val('SELECT COUNT(*) FROM footage_downloads WHERE user_id = ? AND footage_id IN (' . $fph . ')', array_merge([(int)$user['id']], array_map('intval', array_column($footage, 'id'))), 0) > 0;
}
$campPlatKeys = array_column($platforms, 'key');
$myVerified = creator_verified_platforms((int)$user['id']);
$hasVerified = (bool)array_intersect($campPlatKeys, $myVerified);
$platNames = implode(', ', array_map('platform_label', $campPlatKeys));
$platNames = preg_replace('/, ([^,]+)$/', ' or $1', $platNames);
$bestRate = $campaign ? campaign_best_rate($campaign) : rate_per_1000();
$minPayout = payout_min();
$justJoined = $joined && get('joined') === '1';
$startSteps = [
    'join'     => $joined,
    'footage'  => $downloaded || (bool)$ready['mine'],
    'verify'   => $hasVerified,
    'submit'   => (bool)$mySubs,
];
$currentStep = null;
foreach ($startSteps as $k => $done) { if (!$done) { $currentStep = $k; break; } }
$money = function (float $v) use (&$curreny): string { return $curreny . number_format($v, 2); };
// The one main button (top of the page and the bar at the bottom on phones): ready clips first.
if (!$joined) {
    $mainBtn = ['label' => 'Join Campaign', 'act' => 'join'];
} elseif ($heldClip) {
    $mainBtn = ['label' => 'Posted? Send link', 'act' => 'ready-submit', 'id' => (int)$heldClip['id']];
} elseif ($ready['free'] && $readyAcctOk) {
    $mainBtn = ['label' => 'Take a ready clip', 'act' => 'scroll', 'href' => '#campReady'];
} elseif (!$readyAcctOk && $hasReady && $canPost) {
    $mainBtn = ['label' => 'Get my TikTok ready', 'act' => 'link', 'href' => u('creator/tiktok_ready.php')];
} elseif (!$needApproval) {
    $mainBtn = ['label' => 'Submit your clip', 'act' => 'submit'];
} else {
    $mainBtn = ['label' => 'See ready clips', 'act' => 'scroll', 'href' => '#campReady'];
}
$mainBtnAttrs = 'data-main="' . e($mainBtn['act']) . '"' . (isset($mainBtn['id']) ? ' data-id="' . (int)$mainBtn['id'] . '"' : '') . (isset($mainBtn['href']) ? ' data-href="' . e($mainBtn['href']) . '"' : '');
$platformLabels = ['tiktok' => 'TikTok', 'instagram' => 'Instagram', 'youtube' => 'YouTube', 'facebook' => 'Facebook', 'x' => 'X'];
$subStatus = [
    'pending'  => ['Waiting for approval', 'warn'],
    'approved' => ['Approved', 'ok'],
    'rejected' => ['Rejected', 'bad'],
    'flagged'  => ['Under review', 'warn'],
];

$pageTitle = $campaign ? $campaign['title'] : 'Campaign';
require __DIR__ . '/../includes/creator_header.php';
?>

<?php if (!$campaign): ?>
<div class="panel"><div class="panel__body empty"><div class="empty__icon"><?= icon('folder') ?></div><p>Campaign not found.</p><a class="btn btn--primary btn--sm" href="<?= e(u('creator/index.php')) ?>">Back</a></div></div>
<?php else: ?>

<link rel="stylesheet" href="<?= e(u('assets/css/campaign.css?v=20261113d')) ?>">

<div class="camp-page">
    <!-- Hero -->
    <div class="camp-hero">
        <img class="camp-hero__img" onerror="this.onerror=null;this.src='<?= e(u('assets/media/campaign-default.svg')) ?>'" src="<?= e($campaign['banner_url'] ?: u('assets/media/campaign-default.svg')) ?>" alt="">
        <div class="camp-hero__overlay"></div>
        <button type="button" class="camp-back" aria-label="Back" onclick="window.location.href=<?= e(json_encode(u('creator/index.php?tab=campaigns'))) ?>">&#8592;</button>
        <div class="camp-hero__content">
            <div class="camp-creator">
                <?php if (!empty($campaign['owner_avatar_url'])): ?><img class="camp-creator__av" src="<?= e($campaign['owner_avatar_url']) ?>" alt=""><?php endif; ?>
                <span class="camp-creator__name"><?= e($campaign['owner_name']) ?></span>
                <?php if ($campaign['owner_verified']): ?><span class="camp-verified">&#10003;</span><?php endif; ?>
            </div>
            <div class="camp-hero__title-row">
                <h1 class="camp-hero__title"><?= e($campaign['title']) ?></h1>
                <?php if ($needTest && $accepting): ?>
                <a class="camp-joinpill" href="<?= e(u('creator/assessment.php')) ?>" style="text-decoration:none">Take the test first</a>
                <?php else: ?>
                <button type="button" class="camp-joinpill" id="campJoinBtn" <?= $mainBtnAttrs ?> <?= $canAct ? '' : 'disabled' ?>><?= e($mainBtn['label']) ?></button>
                <?php endif; ?>
            </div>
            <p class="camp-hero__pay"><b><?= e(money($bestRate)) ?></b> for every 1,000 views</p>
        </div>
    </div>

    <!-- Sticky top bar -->
    <div class="camp-topbar" id="campTopbar">
        <button type="button" class="camp-topbar__back" aria-label="Back" onclick="window.location.href=<?= e(json_encode(u('creator/index.php?tab=campaigns'))) ?>">&#8592;</button>
        <span class="camp-topbar__title"><?= e($campaign['title']) ?></span>
        <button type="button" class="camp-topbar__copy" id="campCopyBtn" aria-label="Copy link"><?= icon('link') ?></button>
    </div>

    <div class="camp-container">
        <div class="camp-main">
        <!-- Two ways to earn. Ready clips come first (owner decision 2026-11-07). -->
        <?php $cpPath = (string)($user['clip_path'] ?? ''); if ($cpPath !== 'ready'): ?>
        <div class="camp-routes">
            <a class="camp-route camp-route--ready" href="#campReady" data-scroll>
                <span class="camp-route__badge">Easiest</span>
                <span class="camp-route__ico"><?= icon('video') ?></span>
                <strong>Post ready clips</strong>
                <span>We edit. You post on TikTok.</span>
                <em><?= $ready['free'] ? num((int)$ready['free_total']) . ' ready now' : ($ready['mine'] ? 'Your clips' : 'New clips soon') ?></em>
            </a>
            <a class="camp-route" href="#campFootage" data-scroll>
                <span class="camp-route__ico"><?= icon('film') ?></span>
                <strong>Edit your own</strong>
                <span>Get raw footage. Make a clip.</span>
                <em><?= $needApproval ? 'Needs approval' : ($footage ? 'Footage ready' : 'Footage soon') ?></em>
            </a>
        </div>
        <?php endif; ?>

        <!-- Ready clips: finished videos, one per creator, posted on a TikTok that is ready -->
        <div class="camp-card camp-ready" id="campReady">
            <div class="camp-card__head"><span><?= icon('video') ?></span> Ready clips</div>
            <?php if (creator_clip_ban($user) > 0): ?>
                <div class="flash flash--error" role="alert"><?= creator_clip_ban_html($user) ?></div>
                <script>document.querySelectorAll('[data-countdown]').forEach(function(el){var at=parseInt(el.getAttribute('data-countdown'),10)*1000;(function tick(){var s=Math.max(0,Math.round((at-Date.now())/1000));if(s<=0){location.reload();return;}var h=Math.floor(s/3600),m=Math.floor(s%3600/60),x=s%60;el.textContent=(h?h+'h ':'')+(h||m?m+'m ':'')+x+'s';setTimeout(tick,1000);})();});</script>
            <?php elseif (!empty($readyTT['acc']['bio_missing_since']) && empty($readyTT['bio_ok'])): $bioBy = strtotime((string)$readyTT['acc']['bio_missing_since']) + ready_bio_grace_days() * 86400; ?>
                <div class="flash flash--warning" role="alert">Tag HPL in your bio<?= $bioBy > time() ? ' by ' . e(date('D j M, H:i', $bioBy)) : '' ?>: type @hpl, tap <?= e(ready_tag_name()) ?>. <a href="<?= e(u('creator/tiktok_ready.php?recheck=1')) ?>">Check</a></div>
            <?php elseif (!empty($readyTT['acc']) && isset($readyTT['bio']) && ready_tag_typed((string)$readyTT['bio'])): ?>
                <div class="flash flash--warning" role="alert"><?= e(ready_bio_hint((string)$readyTT['bio'])) ?> <a href="<?= e(u('creator/tiktok_ready.php?recheck=1')) ?>">Check</a></div>
            <?php endif; ?>
            <?php $rStage = $heldClip ? 3 : (($needTest || !$readyAcctOk) ? 1 : 2); ?>
            <ol class="camp-ready__track" aria-label="Ready clip steps">
                <li class="<?= $rStage > 1 ? 'is-done' : 'is-now' ?>"><b><?= $rStage > 1 ? icon('check') : '1' ?></b><span>TikTok ready</span></li>
                <li class="<?= $rStage === 2 ? 'is-now' : ($rStage > 2 ? 'is-done' : '') ?>"><b><?= $rStage > 2 ? icon('check') : '2' ?></b><span>Take a clip</span></li>
                <li class="<?= $rStage === 3 ? 'is-now' : '' ?>"><b>3</b><span>Post, send link</span></li>
            </ol>

            <?php if ($needTest): ?>
                <a class="camp-ready__step" href="<?= e(u('creator/assessment.php')) ?>"><b>1</b><span>Pass the short test</span><?= icon('chevron-right') ?></a>
            <?php elseif (!$readyAcctOk): $nx = $readyChk['next'] !== null ? $readyChk['items'][$readyChk['next']] : null; ?>
                <?php if ($nx): ?>
                    <a class="camp-ready__step" href="<?= e(u('creator/tiktok_ready.php')) ?>"><b><?= $readyDoneN ?>/<?= $readyTotalN ?></b><span>Get your TikTok ready<small>Next: <?= e($nx['title']) ?></small></span><?= icon('chevron-right') ?></a>
                <?php elseif (($readyTT['reason'] ?? null) !== null): ?>
                    <p class="muted small"><?= e($readyTT['reason']) ?></p>
                <?php endif; ?>
            <?php elseif (!$joined): ?>
                <button type="button" class="camp-ready__step" data-open-submit <?= $canAct ? '' : 'disabled' ?>><b>!</b><span>Join the campaign to take clips</span><?= icon('chevron-right') ?></button>
            <?php endif; ?>

            <?php if ($ready['mine']): ?>
                <h3 class="camp-ready__h">Your clips</h3>
                <ul class="camp-ready__mine">
                    <?php foreach ($ready['mine'] as $r): $rcU = ready_clip_urls($r);
                        $rcHeld = $r['submission_id'] === null || ((($r['sub_status'] ?? '') === 'rejected') && (int)$r['is_active'] === 1);
                        $rcDue = ready_clip_deadline($r['claimed_at']); ?>
                        <li class="rc-card<?= $rcHeld ? ' rc-card--todo' : '' ?>">
                            <div class="rc-card__top">
                                <div class="rc-card__thumb"><video <?= ready_clip_video_attrs($rcU) ?> muted playsinline controls controlsList="nodownload"></video></div>
                                <div class="rc-card__info">
                                    <?php if (($r['style'] ?? '') !== ''): ?><span class="camp-ready__style"><?= e($r['style']) ?></span><?php endif; ?>
                                    <b class="rc-card__title">Clip #<?= (int)$r['id'] ?><?= $r['title'] !== '' ? ': ' . e($r['title']) : '' ?></b>
                                    <?php if ($r['submission_id'] !== null && ($r['sub_status'] ?? '') === 'rejected' && (int)$r['is_active'] === 1): ?>
                                        <span class="camp-pill camp-pill--bad">Link not approved</span>
                                    <?php elseif ($r['submission_id'] !== null): ?>
                                        <span class="camp-pill camp-pill--<?= ($r['sub_status'] ?? '') === 'approved' ? 'ok' : 'warn' ?>"><?= ($r['sub_status'] ?? '') === 'approved' ? 'Approved' : 'Link sent, we are checking it' ?></span>
                                    <?php elseif ((int)$r['is_active'] !== 1): ?>
                                        <span class="camp-pill camp-pill--bad">Removed by HPL</span>
                                    <?php endif; ?>
                                    <?php if ($rcHeld && (int)$r['is_active'] === 1 && $rcDue > 0): ?>
                                        <span class="rc-card__due">Yours until <strong><?= e(date('D j M, H:i', $rcDue)) ?></strong> <small data-rc-left="<?= (int)$rcDue ?>"></small></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php if ($rcHeld && (int)$r['is_active'] === 1): ?>
                                <?php require_once __DIR__ . '/../includes/clip_deadline.php'; $rcAcct = clip_deadline_notice($user); ?>
                                <?php if ($rcAcct && $rcAcct < $rcDue): /* the 3-day account deadline comes first: say that date, not the clip's */ ?>
                                <p class="rc-card__why">Send the link by <strong><?= e(date('D j M, H:i', $rcAcct)) ?></strong> to keep your account.</p>
                                <?php else: ?>
                                <p class="rc-card__why">No link by then? It goes to someone else.</p>
                                <?php endif; ?>
                                <ol class="rc-steps">
                                    <li><span class="rc-steps__n">1</span><div><b>Download the clip</b>
                                        <a class="btn btn--primary rc-btn" href="<?= e(u('creator/ready_clip.php?id=' . (int)$r['id'] . '&dl=1')) ?>" download="HPL-clip-<?= (int)$r['id'] ?>.mp4"><?= icon('download') ?> Download</a></div></li>
                                    <li><span class="rc-steps__n">2</span><div><b>Post it on your TikTok exactly as it is</b>
                                        <small>No cuts, filters or music.</small>
                                        <div class="rc-tag">Caption in your own words + type <strong>@hpl</strong>, tap <strong><?= e(ready_tag_name()) ?></strong>.</div>
                                        <details class="rc-more"><summary>How to post in the best quality</summary><p><?= e(ready_quality_tip()) ?> Keep the HPL tag in your bio too.</p></details></div></li>
                                    <li><span class="rc-steps__n">3</span><div><b>Send us the link</b>
                                        <?php if ($canAct): ?><button type="button" class="btn btn--primary rc-btn" data-ready-submit="<?= (int)$r['id'] ?>"><?= icon('send') ?> <?= $r['submission_id'] !== null ? 'Send new link' : 'Posted? Send link' ?></button><?php endif; ?></div></li>
                                </ol>
                                <div class="rc-card__back">
                                    <span>Can't post it?</span>
                                    <button type="button" class="rc-backbtn" data-ready-back="<?= (int)$r['id'] ?>">Give it back</button>
                                    <small>No warning if you do it in time.</small>
                                </div>
                            <?php elseif ($r['submission_id'] !== null && ($r['sub_status'] ?? '') !== 'rejected'): ?>
                                <p class="camp-ready__cap camp-ready__cap--tip"><?= icon('reply') ?>Reply to comments. TikTok shows it to more people.</p>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <?php if ($ready['free']): ?>
                <h3 class="camp-ready__h"><?= num((int)$ready['free_total']) ?><?= $readyStyle !== '' ? ' ' . e(mb_strtolower($readyStyle)) : '' ?> clip<?= (int)$ready['free_total'] === 1 ? '' : 's' ?> to take</h3>
                <?php $readyNamed = array_filter(array_keys($readyStyles), fn($k) => $k !== ''); if (count($readyNamed) > 1 || ($readyNamed && isset($readyStyles['']))): ?>
                    <div class="camp-ready__styles" role="group" aria-label="Show clips of one style">
                        <a class="camp-ready__chip<?= $readyStyle === '' ? ' is-on' : '' ?>" href="<?= e(u('creator/campaign.php?id=' . $id . '#campReady')) ?>"<?= $readyStyle === '' ? ' aria-current="true"' : '' ?>>All <b><?= num(array_sum($readyStyles)) ?></b></a>
                        <?php foreach ($readyNamed as $st): ?>
                            <a class="camp-ready__chip<?= $readyStyle === $st ? ' is-on' : '' ?>" href="<?= e(u('creator/campaign.php?id=' . $id . '&style=' . rawurlencode($st) . '#campReady')) ?>"<?= $readyStyle === $st ? ' aria-current="true"' : '' ?>><?= e($st) ?> <b><?= num($readyStyles[$st]) ?></b></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <details class="camp-ready__rule"><summary><?= icon('alert') ?><span><b>Post it as it is.</b> Tag HPL.</span></summary><p><?= e(ready_sound_rule()) ?></p></details>
                <?php if ($readyCooldown): ?>
                    <?php if (ready_day_full((int)$user['id'])): ?>
                    <p class="camp-ready__wait" id="readyCooldown" data-until="<?= (int)$readyNextTake ?>"><?= icon('clock') ?><span><?= e(ready_day_full_text((int)$user['id'])) ?></span></p>
                    <?php else: ?>
                    <p class="camp-ready__wait" id="readyCooldown" data-until="<?= (int)$readyNextTake ?>"><?= icon('clock') ?><span>Next clip in <strong><?= e(ready_clip_wait_text($readyNextTake - time())) ?></strong>. Spacing posts gets more views.</span></p>
                    <?php endif; ?>
                <?php elseif ($readyAcctOk && $readyHeldAll >= $readyHoldMax): ?>
                    <p class="camp-ready__wait"><?= icon('send') ?><span>Post your clip first, then take the next.</span></p>
                <?php endif; ?>
                <div class="camp-ready__grid">
                    <?php foreach ($ready['free'] as $r): ?>
                        <div class="camp-ready__card">
                            <?php if (($r['style'] ?? '') !== ''): ?><span class="camp-ready__label"><?= e($r['style']) ?></span><?php endif; ?>
                            <?php if ($joined && $canPost): ?>
                                <?php $rcU = ready_clip_urls($r); ?><div class="camp-ready__media"><video class="camp-ready__vid" <?= ready_clip_video_attrs($rcU) ?> muted playsinline controls controlsList="nodownload" disablepictureinpicture oncontextmenu="return false"></video></div>
                            <?php else: ?>
                                <div class="camp-ready__media camp-ready__media--locked"><?= icon('lock') ?></div>
                            <?php endif; ?>
                            <div class="camp-ready__foot">
                                <span>Clip #<?= (int)$r['id'] ?></span>
                                <?php if (!$canPost): ?>
                                    <a class="btn btn--ghost btn--xs" href="<?= e(u('creator/assessment.php')) ?>">Pass the test</a>
                                <?php elseif (!$joined): ?>
                                    <button type="button" class="btn btn--ghost btn--xs" data-open-submit <?= $canAct ? '' : 'disabled' ?>>Join to take</button>
                                <?php elseif (!$readyAcctOk): ?>
                                    <a class="btn btn--ghost btn--xs" href="<?= e(u('creator/tiktok_ready.php')) ?>"><?= icon('lock') ?>Locked</a>
                                <?php else: ?>
                                    <button type="button" class="btn btn--primary btn--xs" data-ready-take="<?= (int)$r['id'] ?>" <?= $readyCanTake ? '' : 'disabled' ?>><?= $readyCooldown ? 'Wait' : 'Take' ?></button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if ((int)$ready['pages'] > 1): ?>
                    <a class="btn btn--ghost btn--block camp-ready__more" href="<?= e(u('creator/campaign.php?id=' . $id . ($readyStyle !== '' ? '&style=' . rawurlencode($readyStyle) : '') . '&clips=' . ((int)$ready['page'] + 1) . '#campReady')) ?>"><?= icon('refresh') ?> Show other clips</a>
                <?php endif; ?>
            <?php elseif ($ready['mine']): ?>
                <p class="muted small">All clips here are taken. We add more often. You will get a notification.</p>
            <?php else: ?>
                <div class="camp-ready__empty"><?= icon('video') ?><span>New ready clips are coming. You will get a notification.</span></div>
            <?php endif; ?>
        </div>

        <!-- Edit your own: raw footage, for creators approved to edit -->
        <?php if ($cpPath !== 'ready'): ?>
        <details class="camp-card camp-own" id="campFootage"<?= (!$hasReady && !$needApproval) ? ' open' : '' ?>>
            <summary class="camp-own__sum">
                <span class="camp-own__ico"><?= icon('film') ?></span>
                <span class="camp-own__txt"><b>Edit your own clips</b><small><?= $needApproval ? 'Unlock with one edited video' : 'Raw footage to download' ?></small></span>
                <?= icon('chevron-down') ?>
            </summary>
            <div class="camp-own__body">
                <?php if ($needApproval): ?>
                    <div class="camp-own__lock">
                        <?= icon('lock') ?>
                        <span><?= $needTest ? 'Pass the test, then send us one video you edited.' : 'Send us one video you edited. We check it within 24 hours.' ?></span>
                        <a class="btn btn--ghost btn--sm" href="<?= e(u($needTest ? 'creator/assessment.php' : $approvalUrl)) ?>"><?= e($needTest ? 'Take the test' : $approvalText) ?></a>
                    </div>
                <?php endif; ?>
                <?php if (!$footage): ?>
                    <p class="muted small mb-0">The footage is on its way. You will get a notification.</p>
                <?php else: ?>
                    <ul class="camp-footage__list">
                        <?php foreach ($footage as $f): ?>
                            <li class="camp-footage__row">
                                <span class="camp-footage__ico"><?= icon('video') ?></span>
                                <div class="camp-footage__main">
                                    <b><?= e($f['title']) ?></b>
                                    <?php if (!empty($f['description'])): ?><span><?= e($f['description']) ?></span><?php endif; ?>
                                </div>
                                <?php if ($joined && !$needApproval): ?>
                                    <a class="btn btn--primary btn--sm" href="<?= e(u('creator/library_download.php?id=' . (int)$f['id'])) ?>" target="_blank" rel="noopener"><?= icon('download') ?> Download</a>
                                <?php elseif ($needApproval): ?>
                                    <span class="camp-footage__lock"><?= icon('lock') ?></span>
                                <?php else: ?>
                                    <button type="button" class="btn btn--ghost btn--sm" data-open-submit>Join</button>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="muted small mb-0" style="margin-top:10px">Big videos open in Google Drive. Tap the download arrow there.</p>
                <?php endif; ?>
                <?php if ($joined && !$needApproval && $canAct && !$capReached): ?>
                    <button type="button" class="btn btn--primary btn--sm camp-own__send" data-open-own-submit><?= icon('send') ?> Posted your clip? Send link</button>
                <?php endif; ?>
            </div>
        </details>
        <?php endif; ?>

        <details class="camp-more"><summary>Pay, budget and rules of this campaign</summary>
        <div class="camp-section">
            <div class="camp-payouts">
                <?php foreach ($platforms as $pl):
                    $rate = (float)$pl['rate'];
                    $min = (float)($pl['minPayout'] ?? 0);
                    $max = (float)($pl['maxPayout'] ?? 0);
                ?>
                    <div class="camp-payout-card" data-platform-card="<?= e($pl['key']) ?>">
                        <div class="camp-payout-card__head">
                            <span class="camp-payout-card__icon"><?= $platformIcons[$pl['key']] ?? '•' ?></span>
                            <span class="camp-payout-card__name"><?= e($platformLabels[$pl['key']] ?? ucfirst($pl['key'])) ?></span>
                        </div>
                        <div class="camp-rate"><strong><?= e($money($rate)) ?></strong><span>per 1,000 views</span></div>
                        <dl class="camp-terms">
                            <div><dt>Minimum per clip</dt><dd><?= $min > 0 ? e($money($min)) : 'None' ?></dd></div>
                            <div><dt>Cap per clip</dt><dd><?= $max > 0 ? e($money($max)) : 'No cap' ?></dd></div>
                        </dl>
                        <?php if ($min > 0 && $rate > 0): ?><p class="camp-terms__note">Pays once a clip reaches <?= e(num((int)ceil($min / $rate * 1000))) ?> views.</p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="camp-paid"><?= icon('wallet') ?><span>Cash out from <strong><?= e(money($minPayout)) ?></strong> to MTN, Airtel or Zamtel.</span></p>
        </div>
        </details>

        <!-- My submissions -->
        <?php if ($mySubs || !$hasReady): ?>
        <div class="camp-card camp-mine" id="campMine">
            <div class="camp-card__head camp-mine__head">
                <span class="camp-mine__title"><span><?= icon('film') ?></span> Links you sent</span>
                <?php if ($mySubs): ?><span class="camp-mine__sum"><?= e(num($myViews)) ?> views counted &middot; <b><?= e($money($myEarned)) ?></b> earned</span><?php endif; ?>
            </div>
            <?php if ($joined && $capLimit > 0): ?>
                <div class="camp-cap<?= $capReached ? ' is-full' : '' ?>">
                    <div class="camp-cap__row"><span>Your limit in this campaign</span><b><?= e($money($myEarned)) ?> / <?= e($money($capLimit)) ?></b></div>
                    <div class="camp-progress" role="progressbar" aria-label="Campaign limit used" aria-valuenow="<?= (int)$capPct ?>" aria-valuemin="0" aria-valuemax="100"><div class="camp-progress__fill" style="width:<?= round($capPct, 1) ?>%"></div></div>
                    <small><?= $capReached ? 'Limit reached. New views here no longer earn. Join another campaign to keep earning.' : e($money(max(0, $capLimit - $myEarned))) . ' left to earn here.' ?></small>
                </div>
            <?php endif; ?>
            <?php if (!$mySubs): ?>
                <div class="camp-mine__empty">
                    <p><?= $joined ? 'You have not submitted a clip to this campaign yet.' : 'Join the campaign, post a clip, then submit the link here.' ?></p>
                    <?php if ($canAct): ?><button type="button" class="btn btn--primary btn--sm" data-open-submit><?= $joined ? 'Submit your first clip' : 'Join campaign' ?></button><?php endif; ?>
                </div>
            <?php else: ?>
                <ul class="camp-mine__list">
                    <?php foreach ($mySubs as $sub):
                        [$stLabel, $stTone] = $subStatus[$sub['status']] ?? [ucfirst((string)$sub['status']), 'neutral'];
                        $host = parse_url((string)$sub['url'], PHP_URL_PATH) ?: (string)$sub['url'];
                    ?>
                        <li class="camp-mine__row">
                            <span class="camp-mine__pl"><?= $platformIcons[$sub['platform']] ?? icon('link') ?></span>
                            <div class="camp-mine__main">
                                <?= safe_link((string)$sub['url'], ($platformLabels[$sub['platform']] ?? ucfirst((string)$sub['platform'])) . ' · ' . mb_strimwidth($host, 0, 46, '…'), 'camp-mine__url') ?>
                                <span class="camp-mine__meta"><span class="camp-pill camp-pill--<?= e($stTone) ?>"><?= e($stLabel) ?></span> Submitted <?= e(time_ago($sub['submitted_at'])) ?><?= $sub['last_fetched_at'] ? ' &middot; checked ' . e(time_ago($sub['last_fetched_at'])) : ' &middot; first check soon' ?></span>
                            </div>
                            <div class="camp-mine__nums"><b><?= e($money((float)$sub['earned'])) ?></b><span><?= $sub['status'] === 'rejected' ? 'not counted' : e(num(max(0, (int)$sub['views'] - (int)($sub['start_views'] ?? $sub['views'])))) . ' views counted' ?></span></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        </div>
        <details class="camp-sidebar camp-more"><summary>Budget, examples and requirements</summary>
        <!-- Budget card -->
        <div class="camp-card">
            <div class="camp-card__head"><span><?= icon('chart') ?></span> Budget</div>
            <div class="camp-budget">
                <div><span class="camp-budget__k"><?= e($money($paid)) ?></span><span class="camp-budget__l">earned by creators</span></div>
                <div class="camp-budget__r"><span class="camp-budget__k camp-budget__k--sm"><?= e($money($remaining)) ?></span><span class="camp-budget__l">left of <?= e($money($total)) ?></span></div>
            </div>
            <div class="camp-progress" role="progressbar" aria-label="Budget used" aria-valuenow="<?= (int)$progress ?>" aria-valuemin="0" aria-valuemax="100">
                <div class="camp-progress__fill" style="width:<?= round($progress, 1) ?>%"></div>
            </div>
            <p class="camp-budget__foot camp-budget__foot--<?= $empty ? 'bad' : ($almostFull ? 'warn' : 'ok') ?>">
                <?= $empty ? 'Budget fully used. New views no longer earn here.' : ($almostFull ? 'Almost gone. Clips submitted now still earn until it runs out.' : round($progress) . '% of the budget used.') ?>
            </p>
        </div>

        <!-- Reference materials -->
        <div class="camp-card">
            <div class="camp-card__head"><span><?= icon('folder') ?></span> Reference materials</div>
            <?php if (!empty($campaign['styled_videos_url'])): ?>
                <a class="camp-ref-row" href="<?= e($campaign['styled_videos_url']) ?>" target="_blank" rel="noopener noreferrer">
                    <span class="camp-ref-icon"><?= icon('video') ?></span>
                    <span class="camp-ref-label">Styled videos (company)</span>
                    <span class="camp-ref-ext">&#8599;</span>
                </a>
            <?php endif; ?>
            <?php foreach ($refs as $r): ?>
                <a class="camp-ref-row" href="<?= e($r['url']) ?>" target="_blank" rel="noopener noreferrer">
                    <span class="camp-ref-icon"><?= icon($r['provider']==='drive'?'folder':($r['provider']==='youtube'?'play':'link')) ?></span>
                    <span class="camp-ref-label"><?= e($r['label']) ?></span>
                    <span class="camp-ref-ext">&#8599;</span>
                </a>
            <?php endforeach; ?>
            <?php if (empty($campaign['styled_videos_url']) && !$refs): ?><p class="muted small">No reference materials.</p><?php endif; ?>
        </div>

        <!-- Content requirements -->
        <div class="camp-card">
            <div class="camp-card__head"><span><?= icon('thumbs-up') ?></span> Content requirements</div>
            <?php if ($reqs): ?><ul class="camp-reqs">
            <?php foreach ($reqs as $requirement): ?><li><span class="camp-req-bullet" aria-hidden="true">✓</span><span><?= e(is_array($requirement) ? ($requirement['text'] ?? $requirement['title'] ?? '') : $requirement) ?></span></li><?php endforeach; ?>
            </ul><?php elseif (empty($campaign['requirements_url'])): ?><p class="muted small">No additional requirements have been added.</p><?php endif; ?>
            <?php if (!empty($campaign['requirements_url'])): ?>
                <div style="margin-top:10px">
                    <a href="<?= e($campaign['requirements_url']) ?>" target="_blank" rel="noopener noreferrer" class="camp-ref-row" style="border-top:0;height:auto;padding:8px 0">
                        <span class="camp-ref-icon"><?= icon('book') ?></span>
                        <span class="camp-ref-label">Read full requirements (Google Docs)</span>
                        <span class="camp-ref-ext">&#8599;</span>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </details>

    </div>

    <!-- Floating support button -->
    <a class="camp-help-inline" href="<?= e(u('creator/support.php')) ?>"><?= icon('support') ?> Need help? Ask HPL</a>
    <a class="camp-fab" href="<?= e(u('creator/support.php')) ?>" aria-label="Support"><?= icon('support') ?></a>

    <!-- Sticky bottom bar (mobile) -->
    <div class="camp-bottombar" id="campBottomBar">
        <?php if ($needTest && $accepting): ?>
        <a class="camp-bottombar__btn" href="<?= e(u('creator/assessment.php')) ?>" style="text-decoration:none;text-align:center">Take the test first</a>
        <?php else: ?>
        <button type="button" class="camp-bottombar__btn" id="campBottomJoin" <?= $mainBtnAttrs ?> <?= $canAct ? '' : 'disabled' ?>><?= e($mainBtn['label']) ?></button>
        <?php endif; ?>
    </div>
</div>

<!-- Join confirmation popup -->
<div class="camp-join-modal" id="campJoinModal" aria-hidden="true">
    <div class="camp-join-modal__backdrop" data-join-close></div>
    <div class="camp-join-modal__card" role="dialog" aria-modal="true" aria-labelledby="campJoinTitle">
        <button type="button" class="camp-join-modal__x" data-join-close aria-label="Close">&times;</button>
        <div class="camp-join-modal__icon"><?= icon('award') ?></div>
        <h3 id="campJoinTitle"><?= $almostFull ? 'Budget almost gone!' : 'You\'re in!' ?></h3>
        <p class="camp-join-modal__lead"><?= $almostFull ? 'The budget is almost finished.' : 'You joined this campaign.' ?></p>
        <div class="camp-join-modal__amount"><?= e($curreny . number_format($remaining, 2)) ?> <span>remaining</span></div>
        <p class="camp-join-modal__sub"><?= e($curreny . number_format($paid, 2)) ?> paid of <?= e($curreny . number_format($total, 2)) ?> total</p>
        <div class="camp-progress camp-join-modal__bar"><div class="camp-progress__fill" style="width:<?= (float)$progress ?>%"></div></div>
        <p class="camp-join-modal__copy"><?= e($almostFull ? 'Post your clips now, before the budget runs out.' : ($hasReady ? 'Take a ready clip, post it on TikTok, send the link, get paid.' : 'Get the footage, post your clip on TikTok, send the link, get paid.')) ?></p>
        <?php if ($hasReady && $canPost && !$readyAcctOk): ?>
        <a class="camp-join-modal__cta" href="<?= e(u('creator/tiktok_ready.php')) ?>">Next: get your TikTok ready &rarr;</a>
        <?php elseif ($ready['free'] || $ready['mine']): ?>
        <a class="camp-join-modal__cta" href="#campReady" data-join-close data-scroll>Next: take a ready clip &rarr;</a>
        <?php elseif ($footage && !$needApproval): ?>
        <a class="camp-join-modal__cta" href="#campFootage" data-join-close data-scroll>Next: download the footage &rarr;</a>
        <?php else: ?>
        <button type="button" class="camp-join-modal__cta" data-join-close>See your next steps &rarr;</button>
        <?php endif; ?>
        <button type="button" class="camp-join-modal__later" data-join-close>Maybe later</button>
    </div>
</div>

<!-- Submit clip modal -->
<div class="camp-join-modal" id="campSubmitModal" aria-hidden="true">
    <div class="camp-join-modal__backdrop" data-submit-close></div>
    <div class="camp-join-modal__card" role="dialog" aria-modal="true" aria-labelledby="campSubmitTitle">
        <button type="button" class="camp-join-modal__x" data-submit-close aria-label="Close">&times;</button>
        <div class="camp-join-modal__icon"><?= icon('film') ?></div>
        <h3 id="campSubmitTitle">Send your clip link</h3>
        <p class="camp-join-modal__lead">On TikTok tap <strong>Share</strong>, then <strong>Copy link</strong>. Paste it here.</p>
        <form id="campSubmitForm" class="camp-submit-form" method="post" action="<?= e(u('creator/campaign_submit.php')) ?>">
            <input type="hidden" name="campaign_id" value="<?= (int)$campaign['id'] ?>">
            <input type="hidden" name="ready_clip_id" id="submitReadyId" value="">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <p class="camp-submit-context" id="submitContext" data-title="<?= e($campaign['title']) ?>"><?= e($campaign['title']) ?></p>
            <?php if (count($platforms) > 1): ?>
            <label class="camp-submit-label" for="submitPlatform">Platform</label>
            <select class="camp-submit-input" id="submitPlatform" name="platform" required>
                <?php foreach ($platforms as $submitPlatform): ?>
                <option value="<?= e($submitPlatform['key']) ?>"><?= e($platformLabels[$submitPlatform['key']] ?? ucfirst($submitPlatform['key'])) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="hidden" id="submitPlatform" name="platform" value="<?= e($platforms[0]['key'] ?? 'tiktok') ?>">
            <?php endif; ?>
            <label class="camp-submit-label" for="submitUrl">TikTok video link</label>
            <input class="camp-submit-input" id="submitUrl" name="url" type="url" inputmode="url" autocomplete="url" aria-describedby="submitUrlHelp submitError" placeholder="https://www.tiktok.com/@you/video/..." required>
            <small id="submitUrlHelp" class="muted">The link of the video, not your profile.</small>
            <label class="camp-submit-asis" id="submitAsIs" hidden><input type="checkbox" name="as_is" value="1"> <span>I posted the clip as it is: not cut, no filter, no music or other sound added.</span></label>
            <div class="camp-submit-error" id="submitError" role="alert" hidden></div>
            <button type="submit" class="camp-join-modal__cta" id="campSubmitBtn">Send link &rarr;</button>
            <button type="button" class="camp-join-modal__later" data-submit-close>Cancel</button>
        </form>
    </div>
</div>

<script>
(function(){
    // sticky topbar fade-in
    var hero = document.querySelector('.camp-hero');
    var topbar = document.getElementById('campTopbar');
    function onScroll(){
        if (!hero) return;
        var rect = hero.getBoundingClientRect();
        if (rect.bottom < 80) topbar.classList.add('is-visible');
        else topbar.classList.remove('is-visible');
    }
    window.addEventListener('scroll', onScroll, { passive:true });
    onScroll();

    // chip click -> scroll to platform card
    document.querySelectorAll('.camp-chip--icon[data-platform]').forEach(function(b){
        b.addEventListener('click', function(){
            var key = b.getAttribute('data-platform');
            var card = document.querySelector('.camp-payout-card[data-platform-card="' + key + '"]');
            if (card) { card.scrollIntoView({ behavior:'smooth', block:'center' }); card.style.boxShadow='0 0 0 2px #16c35c'; setTimeout(function(){ card.style.boxShadow=''; }, 1200); }
        });
    });
    // status chip
    var statusChip = document.querySelector('.camp-chip--status');
    if (statusChip) statusChip.addEventListener('click', function(){ hplToast('Status: ' + (statusChip.textContent.trim())); });

    // copy link
    var copyBtn = document.getElementById('campCopyBtn');
    if (copyBtn) copyBtn.addEventListener('click', function(){
        var url = window.location.href;
        if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function(){ showToast('Link copied'); }); }
        else { hplToast(url); }
    });
    function showToast(msg){
        var t = document.createElement('div');
        t.className = 'cs-toast'; t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function(){ t.classList.add('is-show'); });
        setTimeout(function(){ t.classList.remove('is-show'); setTimeout(function(){ t.remove(); },300); }, 2000);
    }

    // join button
    var csrf = <?= json_encode(csrf_token()) ?>;
    var campaignId = <?= (int)($campaign['id'] ?? 0) ?>;
    var isJoined = <?= $joined ? 'true' : 'false' ?>;
    var joinModal = document.getElementById('campJoinModal');
    function openJoinModal(){
        if (!joinModal) return;
        joinModal.classList.add('is-open');
        joinModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('camp-modal-open');
    }
    function closeJoinModal(){
        if (!joinModal) return;
        joinModal.classList.remove('is-open');
        joinModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('camp-modal-open');
    }
    if (joinModal) {
        joinModal.querySelectorAll('[data-join-close]').forEach(function(el){
            el.addEventListener('click', closeJoinModal);
        });
        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape') closeJoinModal();
        });
    }
    function handleJoin(){
        if (isJoined) { openSubmit(); return; }
        var fd = new FormData();
        fd.append('campaign_id', campaignId);
        fd.append('csrf', csrf);
        fetch('<?= e(u('creator/campaign_join.php')) ?>', { method:'POST', credentials:'same-origin', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.ok) {
                    isJoined = true;
                    window.location.href = <?= json_encode(u('creator/campaign.php?id=' . (int)$campaign['id'] . '&joined=1')) ?>;
                } else {
                    hplToast((d && d.error) ? d.error : 'Could not join.', 'error');
                }
            });
    }
    var submitModal = document.getElementById('campSubmitModal');
    function openSubmitModal(){
        if (!submitModal) return;
        submitModal.classList.add('is-open');
        submitModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('camp-modal-open');
        var err = document.getElementById('submitError');
        if (err) { err.hidden = true; err.textContent = ''; }
        var urlInput = document.getElementById('submitUrl');
        if (urlInput) { urlInput.value = ''; urlInput.focus(); }
    }
    function closeSubmitModal(){
        if (!submitModal) return;
        submitModal.classList.remove('is-open');
        submitModal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('camp-modal-open');
        var jbf = document.getElementById('campJoinBtn'); if (jbf) jbf.focus();
    }
    if (submitModal) {
        submitModal.querySelectorAll('[data-submit-close]').forEach(function(el){ el.addEventListener('click', closeSubmitModal); });
        document.addEventListener('keydown', function(e){ if (e.key === 'Escape') closeSubmitModal(); });
    }
    var submitForm = document.getElementById('campSubmitForm');
    if (submitForm) {
        submitForm.addEventListener('submit', function(e){
            e.preventDefault();
            var platform = document.getElementById('submitPlatform').value;
            var url = document.getElementById('submitUrl').value.trim();
            var err = document.getElementById('submitError');
            var btn = document.getElementById('campSubmitBtn');
            if (!url) { if (err) { err.textContent = 'Please enter a post URL.'; err.hidden = false; } return; }
            if (btn) { btn.disabled = true; btn.textContent = 'Submitting...'; }
            var fd = new URLSearchParams(new FormData(submitForm));
            fd.set('url', url);
            fetch('<?= e(u('creator/campaign_submit.php')) ?>', { method:'POST', credentials:'same-origin', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d && d.ok) {
                        closeSubmitModal();
                        showToast('Clip submitted. Views are checked automatically.');
                        setTimeout(function(){ window.location.hash = 'campMine'; window.location.reload(); }, 900);
                    } else {
                        if (err) {
                            err.textContent = (d && d.error) ? d.error : 'Could not submit.';
                            if (d && d.connect) { // a button straight to the Connect TikTok page
                                var a = document.createElement('a'); a.href = d.connect; a.className = 'btn btn--tiktok btn--sm'; a.style.marginTop = '8px'; a.style.display = 'inline-flex'; a.textContent = 'Connect my TikTok';
                                err.appendChild(document.createElement('br')); err.appendChild(a);
                            }
                            err.hidden = false;
                        }
                    }
                })
                .catch(function(){ if (err) { err.textContent = 'Network error. Try again.'; err.hidden = false; } })
                .finally(function(){ if (btn) { btn.disabled = false; btn.textContent = 'Send link \u2192'; } });
        });
    }
    var canEdit = <?= $needApproval ? 'false' : 'true' ?>;
    function openSubmit(readyId){
        var rid = document.getElementById('submitReadyId');
        var ctx = document.getElementById('submitContext');
        if (!readyId && !canEdit) {
            // Not approved for own edits yet: they send links of ready clips they took.
            var sec = document.getElementById('campReady');
            if (sec) { sec.scrollIntoView({ behavior:'smooth', block:'start' }); hplToast('Take a ready clip, post it on TikTok, then tap "Posted? Send link" on that clip.'); }
            else { hplToast('This campaign has no ready clips yet. We will notify you when they are added.'); }
            return;
        }
        if (rid) rid.value = readyId || '';
        var asIs = document.getElementById('submitAsIs');
        if (asIs) { asIs.hidden = !readyId; var cb = asIs.querySelector('input'); cb.required = !!readyId; cb.checked = false; }
        var ps = document.getElementById('submitPlatform');
        if (ps && readyId) { ps.value = 'tiktok'; }
        if (ctx) ctx.textContent = ctx.getAttribute('data-title') + (readyId ? ' · Ready clip #' + readyId : '');
        openSubmitModal();
    }
    document.querySelectorAll('[data-ready-submit]').forEach(function(b){
        b.addEventListener('click', function(){ openSubmit(b.getAttribute('data-ready-submit')); });
    });
    // Live countdown for the "space your posts out" wait; re-enables Take when it ends.
    (function(){
        var el = document.getElementById('readyCooldown');
        if (!el) return;
        var until = parseInt(el.getAttribute('data-until'), 10) * 1000;
        var strong = el.querySelector('strong');
        function fmt(ms){ var m = Math.max(1, Math.ceil(ms/60000)), h = Math.floor(m/60); m = m%60; return h>0 ? (h+'h'+(m?' '+m+'m':'')) : (m+' min'); }
        function tick(){ var left = until - Date.now(); if (left <= 0){ location.reload(); return; } if (strong) strong.textContent = fmt(left); }
        tick(); setInterval(tick, 30000);
    })();
    document.querySelectorAll('[data-ready-take]').forEach(function(b){
        b.addEventListener('click', function(){
            b.disabled = true; b.textContent = 'Taking...';
            var fd = new FormData(); fd.append('clip_id', b.getAttribute('data-ready-take')); fd.append('csrf', csrf);
            fetch('<?= e(u('creator/ready_clip.php')) ?>', { method:'POST', credentials:'same-origin', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (d && d.ok) { showToast(d.message || 'The clip is yours.'); setTimeout(function(){ window.location.hash = 'campReady'; window.location.reload(); }, 700); }
                    else { hplToast((d && d.error) ? d.error : 'Could not take the clip.', 'error'); b.disabled = false; b.textContent = 'Take'; if (d && d.error && /just took/.test(d.error)) { var gone = b.closest('.camp-ready__card'); if (gone) gone.remove(); } if (d && d.redirect) setTimeout(function(){ window.location.href = d.redirect; }, 1800); }
                })
                .catch(function(){ hplToast('Network error. Try again.', 'error'); b.disabled = false; b.textContent = 'Take'; });
        });
    });

    document.querySelectorAll('[data-rc-left]').forEach(function(el){
        var at = parseInt(el.getAttribute('data-rc-left'), 10) * 1000;
        function t(){ var m = Math.max(0, Math.round((at - Date.now()) / 60000)), d = Math.floor(m / 1440), h = Math.floor((m % 1440) / 60);
            el.textContent = m <= 0 ? '(time is up)' : '(' + (d ? d + (d === 1 ? ' day ' : ' days ') : '') + h + 'h ' + (d ? '' : (m % 60) + 'm ') + 'left)'; }
        t(); setInterval(t, 30000);
    });
    document.querySelectorAll('[data-ready-back]').forEach(function(b){
        b.addEventListener('click', function(){
            hplConfirm('Give this clip back? Another creator can then take it. You will not get a warning.').then(function(yes){
                if (!yes) return;
                b.disabled = true; b.textContent = 'Giving back...';
                var fd = new FormData(); fd.append('action', 'give_back'); fd.append('clip_id', b.getAttribute('data-ready-back')); fd.append('csrf', csrf);
                fetch('<?= e(u('creator/ready_clip.php')) ?>', { method:'POST', credentials:'same-origin', body: fd })
                    .then(function(r){ return r.json(); })
                    .then(function(d){
                        if (d && d.ok) { showToast(d.message || 'Clip given back.'); setTimeout(function(){ window.location.hash = 'campReady'; window.location.reload(); }, 700); }
                        else { hplToast((d && d.error) ? d.error : 'Could not give it back.', 'error'); b.disabled = false; b.textContent = 'Give it back'; }
                    })
                    .catch(function(){ hplToast('Network error. Try again.', 'error'); b.disabled = false; b.textContent = 'Give it back'; });
            });
        });
    });

    function goTo(sel){
        var t = sel ? document.querySelector(sel) : null;
        if (!t) return false;
        if (t.tagName === 'DETAILS') t.open = true;
        setTimeout(function(){ t.scrollIntoView({ behavior:'smooth', block:'start' }); t.classList.add('is-flash'); setTimeout(function(){ t.classList.remove('is-flash'); }, 1600); }, 60);
        return true;
    }
    document.querySelectorAll('[data-scroll]').forEach(function(a){
        a.addEventListener('click', function(e){ if (goTo(a.getAttribute('href'))) e.preventDefault(); });
    });
    if (location.hash === '#campFootage') { var f0 = document.getElementById('campFootage'); if (f0) f0.open = true; }
    <?php if ($justJoined): ?>
    if (history.replaceState) { history.replaceState(null, '', <?= json_encode(u('creator/campaign.php?id=' . (int)$campaign['id'])) ?>); }
    openJoinModal();
    <?php endif; ?>
    var modalSubmit = document.getElementById('campJoinModalSubmit');
    if (modalSubmit) modalSubmit.addEventListener('click', function(){ closeJoinModal(); openSubmit(); });
    function handleMain(e){
        var b = e.currentTarget, act = b.getAttribute('data-main');
        if (act === 'join' || !isJoined) { handleJoin(); return; }
        if (act === 'ready-submit') { openSubmit(b.getAttribute('data-id')); return; }
        if (act === 'link') { window.location.href = b.getAttribute('data-href'); return; }
        if (act === 'scroll') { goTo(b.getAttribute('data-href')); return; }
        openSubmit();
    }
    var jb = document.getElementById('campJoinBtn');
    if (jb) jb.addEventListener('click', handleMain);
    var bb = document.getElementById('campBottomJoin');
    if (bb) bb.addEventListener('click', handleMain);
    document.querySelectorAll('[data-open-own-submit]').forEach(function(b){ b.addEventListener('click', function(){ openSubmit(); }); });
    document.querySelectorAll('[data-open-submit]').forEach(function(b){ b.addEventListener('click', handleJoin); });
})();
</script>

<?php endif; ?>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
