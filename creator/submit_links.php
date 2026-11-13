<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/tiktok.php';
$user = require_qualified();
$pageTitle = 'My clips';

$plats = platforms();
$dailyMax = (int)setting('max_daily_links', 20);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !general_clips_enabled()) {
    csrf_check();
    flash('warning', 'Clips are now submitted inside a campaign. Open a campaign, then tap Submit clip.');
    redirect('creator/index.php?tab=campaigns');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (links_remaining_today((int)$user['id']) <= 0) {
        flash('error', 'Daily limit reached. You can submit up to ' . $dailyMax . ' links per day.');
        redirect('creator/submit_links.php');
    }
    $platform = post('platform');
    $url = normalize_url(post('url'));
    $title = mb_substr(post('title'), 0, 200);

    if (!array_key_exists($platform, $plats)) {
        flash('error', 'Please choose a valid platform.');
    } elseif (!platform_is_live($platform)) {
        flash('error', platform_label($platform) . ' is coming soon. For now, post your clip on TikTok.');
    } elseif (!$url) {
        flash('error', 'Please enter a valid link to your posted video.');
    } elseif (fetch_one('SELECT id FROM content_links WHERE url = ?', [$url])) {
        flash('warning', 'This video has already been submitted. Each video can earn once.');
    } elseif (($clipErr = hpl_clip_guard((int)$user['id'], $platform, $url, $videoKey)) !== null) {
        flash('error', $clipErr);
    } else {
        q(
            'INSERT INTO content_links (user_id, platform, url, video_key, title, views, credited_views, earned, last_fetched_at) VALUES (?,?,?,?,?,0,0,0,NOW())',
            [(int)$user['id'], $platform, $url, $videoKey, $title]
        );
        $newLinkId = (int)db()->lastInsertId();
        // Read the real view count straight away when the platform allows it.
        $liveNote = '';
        try {
            require_once __DIR__ . '/../includes/view_fetcher.php';
            $views = ViewFetcher::tryFetch($platform, $url, (int)$user['id']);
            if ($views !== null) {
                credit_link_earnings($newLinkId, (int)$views, 'api');
                $liveNote = ' Current views: ' . num($views) . '.';
            }
        } catch (Throwable $e) {
            error_log('first view read failed: ' . $e->getMessage());
        }
        $remaining = links_remaining_today((int)$user['id']);
        if ($liveNote === '' && $platform === 'tiktok' && tiktok_configured()) {
            if (!tiktok_link_readable((int)$user['id'], $url)) {
                $h = tiktok_link_handle($url);
                $liveNote = ' Important: connect ' . ($h ? 'your TikTok @' . $h : 'the TikTok account you posted it on') . ' on your Home page, or we cannot read its views.';
            }
        }
        flash('success', 'Link submitted!' . $liveNote . ' Views update automatically. ' . $remaining . ' submission(s) left today.');
        redirect('creator/submit_links.php');
    }
}

$uid = (int)$user['id'];
$todayCount = (int)fetch_val('SELECT COUNT(*) FROM content_links WHERE user_id = ? AND DATE(submitted_at) = CURDATE()', [$uid], 0);

// One list of every clip the creator has submitted: direct links and campaign clips.
$clips = [];
foreach (fetch_all('SELECT id, platform, url, title, views, credited_views, earned, status, last_fetched_at, submitted_at FROM content_links WHERE user_id = ? ORDER BY submitted_at DESC LIMIT 100', [$uid]) as $l) {
    $l['kind'] = 'link';
    $l['source'] = 'Direct';
    $clips[] = $l;
}
try {
    foreach (fetch_all('SELECT cs.id, cs.platform, cs.url, NULL AS title, cs.views, cs.credited_views, cs.earned, cs.status, cs.last_fetched_at, cs.submitted_at, c.title AS camp_title, c.id AS camp_id
                          FROM campaign_submissions cs JOIN campaigns c ON c.id = cs.campaign_id
                         WHERE cs.user_id = ? ORDER BY cs.submitted_at DESC LIMIT 100', [$uid]) as $l) {
        $l['kind'] = 'campaign';
        $l['source'] = (string)$l['camp_title'];
        $clips[] = $l;
    }
} catch (Throwable $e) {
}
usort($clips, function ($a, $b) { return strcmp((string)$b['submitted_at'], (string)$a['submitted_at']); });
$clipViews = 0; $clipEarned = 0.0;
foreach ($clips as $c0) { $clipViews += (int)$c0['views']; $clipEarned += (float)$c0['earned']; }
$clipState = [
    'active'   => general_clips_enabled() ? ['Earning', 'ok'] : ['Not earning (campaigns only)', 'muted'],
    'pending'  => ['Waiting for approval', 'warn'],
    'approved' => ['Earning', 'ok'],
    'flagged'  => ['Under review', 'warn'],
    'rejected' => ['Rejected', 'bad'],
];
// Only video platforms in the form; the creator's verified platforms first and pre-selected.
$formPlats = array_filter(['tiktok' => 'TikTok', 'instagram' => 'Instagram', 'facebook' => 'Facebook', 'youtube' => 'YouTube'], 'platform_is_live', ARRAY_FILTER_USE_KEY);
$formPlats = $formPlats ?: ['tiktok' => 'TikTok'];
$verifiedPlats = array_column(fetch_all('SELECT DISTINCT platform FROM social_pages WHERE user_id = ? AND status = "approved"', [(int)$user['id']]), 'platform');
uksort($formPlats, function ($a, $b) use ($verifiedPlats) {
    return (int)!in_array($a, $verifiedPlats, true) <=> (int)!in_array($b, $verifiedPlats, true);
});
$defaultPlatform = (string)array_key_first($formPlats);
$platformHints = [
    'tiktok'    => 'https://www.tiktok.com/@yourname/video/…',
    'instagram' => 'https://www.instagram.com/reel/…',
    'youtube'   => 'https://youtube.com/shorts/…',
    'facebook'  => 'https://www.facebook.com/reel/…',
];

// Recent videos from the creator's connected TikTok, for one-tap submitting.
$ttAcc = tiktok_configured() ? tiktok_account_for_user((int)$user['id']) : null;
$ttVideos = [];
if (general_clips_enabled() && $ttAcc && $ttAcc['username'] && tiktok_account_problem($ttAcc) === null) {
    $submitted = [];
    foreach (fetch_all('(SELECT url FROM content_links WHERE user_id = ? AND platform = "tiktok") UNION ALL (SELECT url FROM campaign_submissions WHERE user_id = ? AND platform = "tiktok")', [(int)$user['id'], (int)$user['id']]) as $l) {
        $submitted[(string)tiktok_video_id((string)$l['url'])] = true;
    }
    $joinedAt = strtotime((string)$user['created_at']) ?: 0;
    $onlyNew = setting('only_new_videos', '1') === '1';
    foreach (tiktok_recent_videos($ttAcc, 12) as $v) {
        $vid = (string)($v['id'] ?? '');
        if ($vid === '' || isset($submitted[$vid])) { continue; }
        $ttVideos[] = [
            'url'   => 'https://www.tiktok.com/@' . $ttAcc['username'] . '/video/' . $vid,
            'title' => mb_substr(trim((string)($v['title'] ?? '') ?: (string)($v['video_description'] ?? '')), 0, 120) ?: 'TikTok video',
            'cover' => (string)($v['cover_image_url'] ?? ''),
            'views' => (int)($v['view_count'] ?? 0),
            'when'  => isset($v['create_time']) ? time_ago(date('Y-m-d H:i:s', (int)$v['create_time'])) : '',
            'old'   => $onlyNew && isset($v['create_time']) && (int)$v['create_time'] < $joinedAt,
        ];
    }
}
$remaining = max(0, $dailyMax - $todayCount);

require __DIR__ . '/../includes/creator_header.php';
?>

<div class="ui-page">
<div class="ui-page-head">
    <div>
        <h1>My clips</h1>
        <p class="ui-page-head__sub"><?= general_clips_enabled() ? 'Post clips on your verified pages, submit the links, and we pay you on real views.' : 'Your clips and their views. New clips are sent from a campaign page.' ?></p>
    </div>
    <?php if (general_clips_enabled()): ?><span class="badge <?= $remaining > 0 ? 'ok' : 'bad' ?>" style="padding:6px 12px"><?= e(num($todayCount)) ?> / <?= e(num($dailyMax)) ?> submitted today</span><?php endif; ?>
</div>

<?php if (general_clips_enabled() && $ttAcc && $ttVideos && $remaining > 0): ?>
<section class="ui-card">
    <div class="ui-card__head">
        <div><h2><?= icon('tiktok') ?>From your TikTok @<?= e($ttAcc['username']) ?></h2><p class="ui-card__sub">Your latest posts that are not submitted yet. Submit only clips made from HPL footage.</p></div>
    </div>
    <ul class="ui-rows">
        <?php foreach ($ttVideos as $v): ?>
            <li class="ui-row">
                <?php if ($v['cover']): ?><img src="<?= e($v['cover']) ?>" alt="" width="40" height="54" style="width:40px;height:54px;object-fit:cover;border-radius:8px;flex:none" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="ui-dot ui-dot--tiktok"><?= icon('tiktok') ?></span><?php endif; ?>
                <div class="ui-row__main">
                    <div class="ui-row__title"><?= safe_link((string)$v['url'], (string)$v['title']) ?></div>
                    <div class="ui-row__sub"><?= e(num($v['views'])) ?> views<?= $v['when'] ? ' &middot; posted ' . e($v['when']) : '' ?></div>
                </div>
                <?php if ($v['old']): ?>
                <div class="ui-row__end"><small class="muted" title="Only clips posted after you joined can earn">Posted before you joined</small></div>
                <?php else: ?>
                <form method="post" class="ui-row__end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="platform" value="tiktok">
                    <input type="hidden" name="url" value="<?= e($v['url']) ?>">
                    <input type="hidden" name="title" value="<?= e($v['title']) ?>">
                    <button class="btn btn--primary btn--sm" type="submit"><?= icon('plus') ?>Submit</button>
                </form>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>

<?php if (!general_clips_enabled()): ?>
<section class="ui-card" style="border-color:var(--ui-warn-soft);background:var(--ui-surface-2)">
    <div class="ui-card__body">
        <h2 style="margin:0 0 6px;font-size:1.05rem"><?= icon('target') ?> Clips now go through campaigns</h2>
        <p class="muted" style="margin:0 0 12px">To get paid, join a campaign, download its footage, post your clip and submit the link on the campaign page. Money you already earned here stays in your balance.</p>
        <a class="btn btn--primary" href="<?= e(u('creator/index.php?tab=campaigns')) ?>"><?= icon('target') ?>Browse campaigns</a>
    </div>
</section>
<?php else: ?>
<section class="ui-card">
    <div class="ui-card__head">
        <div><h2>Add a posted video</h2><p class="ui-card__sub">Views update automatically. You never need to resubmit to get paid for new views.</p></div>
        <span class="muted small"><?= e(num($remaining)) ?> left today</span>
    </div>
    <div class="ui-card__body">
        <form method="post" class="sl-form">
            <?= csrf_field() ?>
            <div class="field mb-0">
                <label class="label" for="platform">Platform</label>
                <select class="select" id="platform" name="platform" <?= $remaining <= 0 ? 'disabled' : '' ?>>
                    <?php foreach ($formPlats as $k => $label): ?>
                        <option value="<?= e($k) ?>" data-hint="<?= e($platformHints[$k] ?? 'https://…') ?>" <?= $k === $defaultPlatform ? 'selected' : '' ?>><?= e($label) ?><?= $verifiedPlats && !in_array($k, $verifiedPlats, true) ? ' (verify a page first)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <?= coming_soon_html() ?>
            </div>
            <div class="field mb-0">
                <label class="label" for="url">Link to the video</label>
                <input class="input" type="text" inputmode="url" autocapitalize="off" spellcheck="false" id="url" name="url" placeholder="<?= e($platformHints[$defaultPlatform] ?? 'https://…') ?>" required <?= $remaining <= 0 ? 'disabled' : '' ?>>
            </div>
            <div class="field mb-0">
                <label class="label" for="title">Label <span class="muted">(optional)</span></label>
                <input class="input" type="text" id="title" name="title" maxlength="200" placeholder="e.g. River nugget find" <?= $remaining <= 0 ? 'disabled' : '' ?>>
            </div>
            <button class="btn btn--primary sl-form__btn" type="submit" <?= $remaining <= 0 ? 'disabled' : '' ?>><?= $remaining <= 0 ? 'Daily limit reached' : 'Submit link' ?></button>
        </form>
    </div>
</section>
<?php endif; ?>

<section class="ui-card">
    <div class="ui-card__head">
        <div><h2>All clips</h2><p class="ui-card__sub">Direct links and campaign clips in one place.</p></div>
        <?php if ($clips): ?><span class="sl-total"><?= e(num($clipViews)) ?> views &middot; <b><?= money($clipEarned) ?></b></span><?php endif; ?>
    </div>
    <?php if (!$clips): ?>
        <div class="ui-empty"><?= icon('film') ?><p>No clips yet. Post a clip made from HPL footage, then paste its link above.</p></div>
    <?php else: ?>
        <ul class="sl-list">
            <?php foreach ($clips as $l):
                [$stLabel, $stTone] = $clipState[$l['status']] ?? [ucfirst((string)$l['status']), 'neutral'];
                $isToday = date('Y-m-d', strtotime((string)$l['submitted_at'])) === date('Y-m-d');
                $name = trim((string)($l['title'] ?? '')) ?: (parse_url((string)$l['url'], PHP_URL_PATH) ?: (string)$l['url']);
                $prog = ($l['kind'] === 'link' && $stTone === 'ok') ? clip_progress((int)$l['views'], (int)$l['credited_views']) : null;
            ?>
                <li class="sl-row">
                    <span class="sl-row__pl"><?= platform_icon($l['platform']) ?></span>
                    <div class="sl-row__main">
                        <?= safe_link((string)$l['url'], mb_strimwidth($name, 0, 70, '…'), 'sl-row__title') ?>
                        <div class="sl-row__meta">
                            <span class="sl-pill sl-pill--<?= e($stTone) ?>"><?= e($stLabel) ?></span>
                            <?php if ($l['kind'] === 'campaign'): ?><a class="sl-src" href="<?= e(u('creator/campaign.php?id=' . (int)$l['camp_id'])) ?>"><?= icon('target') ?><?= e(mb_strimwidth($l['source'], 0, 34, '…')) ?></a><?php else: ?><span class="sl-src"><?= e(platform_label($l['platform'])) ?></span><?php endif; ?>
                            <span><?= $isToday ? 'Today ' . e(date('g:ia', strtotime((string)$l['submitted_at']))) : e(time_ago($l['submitted_at'])) ?></span>
                            <span class="muted"><?= $l['last_fetched_at'] ? 'checked ' . e(time_ago($l['last_fetched_at'])) : 'first check soon' ?></span>
                        </div>
                        <?php if ($prog && $prog['pct'] > 0 && $prog['next_amount'] > 0): ?>
                            <div class="sl-prog" title="<?= e(num($prog['to_next'])) ?> views until the next <?= e(money($prog['next_amount'])) ?>"><i style="width:<?= (int)$prog['pct'] ?>%"></i></div>
                        <?php endif; ?>
                    </div>
                    <div class="sl-row__nums"><b><?= money($l['earned']) ?></b><span><?= e(num($l['views'])) ?> views</span></div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
</div>

<style>
.sl-form { display: grid; grid-template-columns: 180px minmax(0, 1.4fr) minmax(0, 1fr) auto; gap: 12px; align-items: end; }
.sl-form__btn { height: 44px; }
.sl-form .select, .sl-form .input { height: 44px; padding-top: 0; padding-bottom: 0; line-height: 42px; }
.sl-total { font-size: .84rem; color: var(--ui-muted); white-space: nowrap; }
.sl-total b { color: #15803d; }
.sl-list { list-style: none; margin: 0; padding: 0; }
.sl-row { display: grid; grid-template-columns: 36px minmax(0, 1fr) auto; gap: 14px; align-items: center; padding: 14px 20px; border-top: 1px solid var(--ui-line); }
.sl-row:first-child { border-top: 0; }
.sl-row__pl { width: 36px; height: 36px; border-radius: 10px; background: var(--ui-sunken); display: grid; place-items: center; }
.sl-row__main { min-width: 0; display: flex; flex-direction: column; gap: 5px; }
.sl-row__title { font-weight: 600; color: var(--ui-text); text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sl-row__title:hover { color: var(--ui-accent); }
.sl-row__meta { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 10px; font-size: .8125rem; color: var(--ui-text-2); }
.sl-src { display: inline-flex; align-items: center; gap: 4px; color: var(--ui-text-2); text-decoration: none; }
a.sl-src:hover { color: var(--ui-accent); }
.sl-src .ico { width: 12px; height: 12px; }
.sl-pill { padding: 2px 8px; border-radius: 999px; font-weight: 650; font-size: .8125rem; }
.sl-pill--ok { background: #e7f6ec; color: #15803d; }
.sl-pill--warn { background: #fdf3e1; color: #b45309; }
.sl-pill--bad { background: #fdecec; color: #b91c1c; }
.sl-pill--neutral { background: var(--ui-sunken); color: var(--ui-muted); }
.sl-prog { height: 4px; max-width: 220px; border-radius: 999px; background: var(--ui-sunken); overflow: hidden; }
.sl-prog i { display: block; height: 100%; background: #2fb36d; }
.sl-row__nums { text-align: right; display: flex; flex-direction: column; gap: 2px; }
.sl-row__nums b { font-size: .98rem; }
.sl-row__nums span { font-size: .8125rem; color: var(--ui-muted); white-space: nowrap; }
@media (max-width: 900px) {
    .sl-form { grid-template-columns: 1fr; }
    .sl-row { padding: 12px 14px; gap: 10px; grid-template-columns: 32px minmax(0, 1fr) auto; }
}
</style>
<script>
(function () {
    var sel = document.getElementById('platform'), url = document.getElementById('url');
    if (!sel || !url) return;
    sel.addEventListener('change', function () { var o = sel.options[sel.selectedIndex]; if (o) url.placeholder = o.getAttribute('data-hint') || 'https://…'; });
})();
</script>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
