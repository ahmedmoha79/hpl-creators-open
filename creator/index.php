<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/ready_clips.php';
$user = require_creator();
$pageTitle = 'Home';
if (get('tab') === 'chats') {
    redirect('creator/community.php'); // the community has one home now
}

$stats = creator_stats((int)$user['id']);
$qualified = (int)$user['qualified'] === 1;
$promoEmbed = promo_embed_url();
$minPayout = payout_min();

$platformEarnings = fetch_all(
    'SELECT platform, SUM(earned) AS earned, COUNT(*) AS links, SUM(views) AS views
     FROM content_links
     WHERE user_id = ?
     GROUP BY platform
     ORDER BY earned DESC',
    [(int)$user['id']]
);
// Campaign submission earnings by platform
$campPlatformEarnings = fetch_all(
    'SELECT platform, SUM(earned) AS earned, COUNT(*) AS links,
            SUM(GREATEST(CAST(views AS SIGNED) - CAST(COALESCE(start_views, views) AS SIGNED), 0)) AS views
     FROM campaign_submissions
     WHERE user_id = ? AND status <> "rejected"
     GROUP BY platform
     ORDER BY earned DESC',
    [(int)$user['id']]
);
// Merge into platformEarnings
$merged = [];
foreach (array_merge($platformEarnings, $campPlatformEarnings) as $p) {
    $k = $p['platform'];
    if (!isset($merged[$k])) { $merged[$k] = ['platform'=>$k,'earned'=>0,'links'=>0,'views'=>0]; }
    $merged[$k]['earned'] += (float)$p['earned'];
    $merged[$k]['links'] += (int)$p['links'];
    $merged[$k]['views'] += (int)$p['views'];
}
$platformEarnings = array_values($merged);
usort($platformEarnings, function($a,$b){ return $b['earned'] <=> $a['earned']; });

$dailyEarnings = fetch_all(
    'SELECT DATE(submitted_at) AS day, SUM(earned) AS earned
     FROM content_links
     WHERE user_id = ? AND submitted_at >= CURDATE() - INTERVAL 13 DAY
     GROUP BY DATE(submitted_at)
     ORDER BY day ASC',
    [(int)$user['id']]
);
$earnMax = 0;
foreach ($dailyEarnings as $d) { $earnMax = max($earnMax, (float)$d['earned']); }
$earnMaxBar = max($earnMax, 1);
$platformMax = 0;
foreach ($platformEarnings as $p) { $platformMax = max($platformMax, (float)$p['earned']); }
$platformMaxBar = max($platformMax, 1);

$recentPayouts = fetch_all(
    'SELECT u.full_name, w.amount, w.requested_at
     FROM withdrawals w
     JOIN users u ON u.id = w.user_id
     WHERE w.status IN ("pending","paid")
     ORDER BY w.requested_at DESC
     LIMIT 10'
);


$mySocials = fetch_all('SELECT * FROM social_pages WHERE user_id = ? ORDER BY submitted_at DESC', [(int)$user['id']]);

$supportThreadId = function_exists('support_thread_for') ? support_thread_for((int)$user['id']) : 0;

$annLimit = get('ann_all') ? 200 : 20;
$announcements = fetch_all('SELECT * FROM announcements WHERE is_active = 1 ORDER BY created_at DESC LIMIT ' . ($annLimit + 1));
$annHasMore = count($announcements) > $annLimit;
if ($annHasMore) { array_pop($announcements); }

// Announcements channel posts
$rawPosts = fetch_all(
    'SELECT p.*, u.full_name, u.role, u.email FROM ann_posts p JOIN users u ON u.id = p.author_id ORDER BY p.pinned DESC, p.created_at DESC'
);
$annTopLevel = [];
$annChildren = [];
foreach ($rawPosts as $rp) {
    if (!empty($rp['parent_id'])) {
        $annChildren[(int)$rp['parent_id']][] = $rp;
    } else {
        $annTopLevel[] = $rp;
    }
}
// liked post ids for current user
$likedIds = [];
if ($rawPosts) {
    $ids = array_column($rawPosts, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = fetch_all('SELECT post_id FROM ann_likes WHERE user_id = ? AND post_id IN (' . $ph . ')', array_merge([(int)$user['id']], $ids));
    foreach ($rows as $r) { $likedIds[(int)$r['post_id']] = true; }
}

$campaigns = fetch_all('SELECT * FROM campaigns WHERE status <> "draft" ORDER BY (status = "active" AND camp_status = "accepting" AND paid_amount < total_budget) DESC, created_at DESC LIMIT 40');
$myCampJoins = [];
$myCampClips = [];
try {
    foreach (fetch_all('SELECT campaign_id FROM campaign_joins WHERE user_id = ?', [(int)$user['id']]) as $r) { $myCampJoins[(int)$r['campaign_id']] = true; }
    foreach (fetch_all('SELECT campaign_id, SUM(status <> "rejected") n, COALESCE(SUM(earned),0) e FROM campaign_submissions WHERE user_id = ? GROUP BY campaign_id', [(int)$user['id']]) as $r) { $myCampClips[(int)$r['campaign_id']] = $r; }
} catch (Throwable $e) {
}

require __DIR__ . '/../includes/creator_header.php';
?>

<?php
// ---- Home data (2026-10-27) ------------------------------------------------
require_once __DIR__ . '/../includes/email_verify.php';
require_once __DIR__ . '/../includes/tiktok.php';
$emailOk = email_is_verified($user);
$hasVerifiedPage = hpl_has_verified_page((int)$user['id']);
$campSubs = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ?', [(int)$user['id']], 0);
// Campaigns-only mode: the first-clip step counts campaign clips, so older creators are guided to join a campaign.
$hasClip = (general_clips_enabled() ? (int)$stats['links'] + $campSubs : $campSubs) > 0;
$hasPayout = (float)$stats['paid'] > 0;
$ttOn = tiktok_configured();
// TikTok: every connected account, and which TikTok clips cannot be read yet (one place: includes/tiktok.php).
$ttStatus = $ttOn ? tiktok_creator_status((int)$user['id']) : ['accounts' => [], 'clips' => 0, 'unreadable' => 0, 'needs' => [], 'state' => 'none'];
$ttClips = (int)$ttStatus['unreadable'];
$firstName = explode(' ', trim((string)$user['full_name']))[0] ?: 'there';
$hour = (int)date('G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// Campaigns the creator can earn from right now, and the one to point them at.
$openCamps = array_values(array_filter($campaigns, function ($c) {
    return $c['status'] === 'active' && ($c['camp_status'] ?? 'accepting') === 'accepting' && (float)$c['total_budget'] - (float)$c['paid_amount'] > 0;
}));
$joinedOpen = null;
foreach ($openCamps as $c) { if (!empty($myCampJoins[(int)$c['id']])) { $joinedOpen = $c; break; } }
$focusCamp = $joinedOpen ?: ($openCamps[0] ?? null);
$focusHref = $focusCamp ? 'creator/campaign.php?id=' . (int)$focusCamp['id'] : 'creator/index.php?tab=campaigns';
$readyCounts = ready_clip_counts();
// Ready clips first (owner decision 2026-11-07): point at a campaign that has clips to take.
foreach ($openCamps as $c) {
    if (!empty($readyCounts[(int)$c['id']]['free']) && (!$joinedOpen || empty($readyCounts[(int)$joinedOpen['id']]['free']))) { $focusCamp = $c; break; }
}
$focusHref = $focusCamp ? 'creator/campaign.php?id=' . (int)$focusCamp['id'] : 'creator/index.php?tab=campaigns';
if ($focusCamp && !empty($readyCounts[(int)$focusCamp['id']]['free'])) {
    $clipStep = ['title' => 'Post your first ready clip', 'text' => 'No editing. Take a finished clip, post it on your TikTok, send the link.', 'href' => $focusHref . '#campReady', 'cta' => 'Get a clip', 'big' => 'Get a ready clip'];
} elseif ($joinedOpen) {
    $clipStep = ['title' => 'Post your first clip', 'text' => 'Post a clip from ' . $joinedOpen['title'] . ', send the link.', 'href' => $focusHref . '#campFootage', 'cta' => 'Get the footage', 'big' => 'Get the footage'];
} elseif ($focusCamp) {
    $clipStep = ['title' => 'Join a campaign', 'text' => 'Open ' . $focusCamp['title'] . ', tap Join.', 'href' => $focusHref, 'cta' => 'Join', 'big' => 'Open the campaign'];
} else {
    $clipStep = ['title' => 'Post and submit your first clip', 'text' => 'No campaign open. We will tell you.', 'href' => clip_submit_url(), 'cta' => general_clips_enabled() ? 'Submit' : '', 'big' => 'Submit a clip'];
}

// "TikTok ready" (2026-11-07): the account checklist for ready clips (bio read at most every 10 minutes).
$ttReady = false; $ttReadyNext = '';
if ($ttOn && creator_can_post_ready($user)) {
    $ttChk = ready_account_check($user, ready_tiktok_status((int)$user['id']));
    $ttReady = $ttChk['ok'];
    $ttReadyNext = $ttChk['next'] !== null ? $ttChk['items'][$ttChk['next']]['title'] : '';
}

// The path to a first payout. 'big' is the label on the one large button shown for the current step.
$pfStatus = (string)($user['portfolio_status'] ?? 'none');
$testPassed = ($user['assessment_status'] ?? '') === 'passed';
if (!$testPassed) {
    $approveStep = ['text' => '5 short questions. Then you can post clips.', 'cta' => 'Start', 'big' => 'Take the test'];
} elseif ($pfStatus === 'pending') {
    $approveStep = ['text' => 'We are checking your video (under 24 hours).', 'cta' => '', 'big' => ''];
} elseif ($pfStatus === 'rejected') {
    $approveStep = ['text' => 'Your last video was not approved. Why: ' . rtrim(trim((string)($user['rejection_note'] ?: 'please send a short video (15 to 60 seconds) that you edited yourself, showing the most exciting moment')), '.') . '. Send a better one.', 'cta' => 'Send', 'big' => 'Send a new video'];
} else {
    $approveStep = ['text' => 'Send us one video you edited.', 'cta' => 'Send', 'big' => 'Send my video'];
}
$steps = [
    // Passing the test is enough to post ready clips and earn (2026-11-05); the edited video only unlocks the raw footage.
    ['done' => $qualified || $testPassed, 'title' => 'Pass the short HPL test', 'href' => onboarding_url($user), 'icon' => 'award'] + $approveStep,
    $ttOn
        ? ['done' => $hasVerifiedPage, 'title' => 'Connect your TikTok', 'text' => 'Sign in with TikTok.', 'href' => 'creator/connect_tiktok.php', 'cta' => 'Connect', 'big' => 'Connect my TikTok', 'icon' => 'tiktok']
        : ['done' => $hasVerifiedPage, 'title' => 'Verify your TikTok page', 'text' => 'Add your page and its HPL code.', 'href' => 'creator/profile.php#socialPage', 'cta' => 'Verify', 'big' => 'Verify my page', 'icon' => 'shield'],
    ['done' => $hasClip || $ttReady, 'title' => 'Get your TikTok ready', 'text' => 'A real, active account gets more views. ' . ($ttReadyNext ? 'Next: ' . $ttReadyNext . '.' : ''), 'href' => 'creator/tiktok_ready.php', 'cta' => 'Open', 'big' => 'Get my TikTok ready', 'icon' => 'trending'],
    ['done' => $hasClip] + $clipStep + ['icon' => 'video'],
    ['done' => $emailOk || $hasPayout, 'title' => 'Confirm your email', 'text' => 'We send you a 6-digit code.', 'href' => 'creator/verify_email.php', 'cta' => 'Confirm', 'big' => 'Confirm my email', 'icon' => 'mail'],
    ['done' => $hasPayout, 'title' => 'Withdraw your first ' . money($minPayout), 'text' => 'From ' . money($minPayout) . ', to mobile money.', 'href' => 'creator/withdraw.php', 'cta' => 'Withdraw', 'big' => 'Withdraw to mobile money', 'icon' => 'wallet'],
];
$doneCount = count(array_filter(array_column($steps, 'done')));
$live = creator_live((int)$user['id']);
$liveJson = json_encode($live, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$currentStep = null;
foreach ($steps as $i => $s) { if (!$s['done']) { $currentStep = $i; break; } }

$paidPayouts = fetch_all(
    'SELECT u.full_name, w.amount, w.payment_provider, COALESCE(w.resolved_at, w.requested_at) AS paid_at
       FROM withdrawals w JOIN users u ON u.id = w.user_id
      WHERE w.status = "paid" ORDER BY paid_at DESC LIMIT 6'
);
$introSrc = null;
if (!$promoEmbed) {
    $introLesson = $qualified ? 'assets/media/tutorials/lesson-05-what-to-clip.mp4' : 'assets/media/tutorials/lesson-01-welcome.mp4';
    if (is_file(BASE_PATH . '/' . $introLesson)) {
        $introSrc = u($introLesson);
    }
}
$platformIcon = function (string $p): string {
    $known = ['tiktok', 'instagram', 'facebook', 'youtube'];
    return '<span class="ui-dot ui-dot--' . (in_array($p, $known, true) ? $p : 'neutral') . '">' . icon(in_array($p, $known, true) ? $p : 'link') . '</span>';
};
?>

<!-- HOME -->
<?php
// ---- "Your next step": one action at a time (fewer choices = faster, surer decisions) ----
$balanceNow = (float)$live['balance'];
// Same rules as the withdraw page: enough money, confirmed email and a verified page.
$canWithdraw = $balanceNow >= $minPayout && $emailOk && $hasVerifiedPage && creator_can_post_ready($user);
if ($currentStep !== null) {
    $ns = $steps[$currentStep];
    $next = ['kicker' => 'Step ' . ($currentStep + 1) . ' of ' . count($steps), 'title' => $ns['title'], 'text' => $ns['text'], 'href' => $ns['href'], 'btn' => $ns['cta'] !== '' ? $ns['big'] : '', 'icon' => $ns['icon']];
    if ($currentStep === count($steps) - 1 && !$canWithdraw) {
        // Last step but not enough money yet: the useful action is another clip.
        $next['text'] = money(max(0, $minPayout - $balanceNow)) . ' more and you can withdraw.';
        $next['href'] = $focusHref;
        $next['btn'] = $focusCamp ? 'Post another clip' : 'See campaigns';
        $next['icon'] = 'video';
    }
} elseif ($canWithdraw) {
    $next = ['kicker' => 'Ready', 'title' => 'Your money is ready', 'text' => money($balanceNow) . ' ready for mobile money.', 'href' => 'creator/withdraw.php', 'btn' => 'Withdraw to mobile money', 'icon' => 'wallet'];
} else {
    $next = ['kicker' => 'Keep going', 'title' => $focusCamp ? 'Post another clip' : 'Watch for the next campaign', 'text' => $focusCamp ? 'More clips, more money.' : 'We will tell you when one opens.', 'href' => $focusHref, 'btn' => $focusCamp ? 'Open ' . $focusCamp['title'] : 'See campaigns', 'icon' => 'video'];
}
$showEmailAlert = false; // email is its own step just before the first withdrawal
// Where the creator is in the earning loop (for the "How it works" strip).
$loopAt = !$hasClip ? ($ttReady ? 1 : 0) : ($canWithdraw ? 3 : 2);
$loop = [
    ['icon' => 'tiktok', 'title' => 'TikTok ready', 'text' => 'Real, active account.'],
    ['icon' => 'video', 'title' => 'Take a ready clip', 'text' => 'We already edited it.'],
    ['icon' => 'chat', 'title' => 'Post and reply', 'text' => 'Send the link. Answer comments.'],
    ['icon' => 'wallet', 'title' => 'Get paid', 'text' => 'Per 1,000 views, to mobile money.'],
];
?>
<?php
// ---- Home (2026-11-08 redesign): money, one next step, your clips, this week ----
require_once __DIR__ . '/../includes/home_hooks.php';
$since = home_since_last_visit($user, $live);
$week = creator_week($user);
$thumbs = clip_thumbs((int)$user['id']);
$homeClips = array_slice($live['clips'], 0, 10);
$freeReady = 0;
foreach ($openCamps as $c) { $freeReady += (int)($readyCounts[(int)$c['id']]['free'] ?? 0); }
$nextTakeAt = ($freeReady > 0 && creator_can_post_ready($user)) ? ready_clip_next_take_at((int)$user['id']) : 0;
$online = 0;
try { $online = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role IN ("creator","admin") AND status = "active" AND last_seen >= NOW() - INTERVAL ' . (int)presence_window_minutes() . ' MINUTE', [], 0); } catch (Throwable $e) {}
$lastPaid = $paidPayouts[0] ?? null;
$shortName = function (string $n): string { $p = preg_split('/\s+/', trim($n)) ?: ['Creator']; return $p[0] . (count($p) > 1 ? ' ' . mb_strtoupper(mb_substr(end($p), 0, 1)) . '.' : ''); };
$goal = (float)$week['goal'];
$goalPct = $goal > 0 ? min(100, (int)floor($week['money'] / $goal * 100)) : 0;
$goalOptions = [50, 100, 200, 500];
$ringC = 2 * M_PI * 26;
?>
<div class="tab-panel hk" data-panel="home">
    <script>if (location.hash === '#socialPage') { location.replace(<?= json_encode(u('creator/profile.php#socialPage')) ?>); }</script>
    <link rel="stylesheet" href="<?= e(u('assets/css/live.css?v=20261108d')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/home.css?v=20261113a')) ?>">

    <div class="hk-hello">
        <h1><?= e($greeting) ?>, <?= e($firstName) ?></h1>
        <?php if ($week['streak'] > 0): ?>
            <span class="hk-streakchip<?= $week['posted_today'] ? '' : ' is-cold' ?>" title="Days in a row you sent a clip"><?= icon('flame') ?><?= (int)$week['streak'] ?></span>
        <?php endif; ?>
    </div>

    <?php require_once __DIR__ . '/../includes/offers.php'; $offerHtml = offer_render($user); ?>
    <?php if ($offerHtml !== ''): ?>
    <?= $offerHtml ?>
    <script>
    (function () {
        var box = document.querySelector('[data-offer]');
        if (!box) return;
        var csrf = <?= json_encode(csrf_token()) ?>, sent = false;
        function mark(clicked) {
            if (sent && !clicked) return;
            sent = true;
            var fd = new FormData(); fd.append('csrf', csrf); fd.append('id', box.getAttribute('data-offer')); fd.append('clicked', clicked ? '1' : '0');
            if (navigator.sendBeacon) navigator.sendBeacon(box.getAttribute('data-api'), fd); else fetch(box.getAttribute('data-api'), { method: 'POST', credentials: 'same-origin', body: fd, keepalive: true });
        }
        function close() { box.classList.remove('is-in'); setTimeout(function () { box.remove(); }, 220); mark(false); }
        var x = box.querySelector('[data-offer-x]'), go = box.querySelector('[data-offer-go]');
        if (x) x.addEventListener('click', close);
        if (go) go.addEventListener('click', function () { mark(true); });
        if (box.classList.contains('offer-line')) { setTimeout(function () { mark(false); }, 4000); return; }
        // The card waits its turn: never on top of another pop-up window, and not while a banner is showing.
        function busy() {
            var l = document.querySelectorAll('[role="dialog"][aria-modal="true"], .hpl-modal, .hpldm');
            for (var i = 0; i < l.length; i++) { if (l[i].getClientRects().length) return true; }
            return false;
        }
        var tries = 0;
        (function open() {
            if (busy() && tries++ < 30) { setTimeout(open, 2000); return; }
            if (busy()) return;
            box.hidden = false;
            requestAnimationFrame(function () { box.classList.add('is-in'); });
            setTimeout(function () { mark(false); }, 4000);
        })();
    })();
    </script>
    <?php endif; ?>
    <?php if (function_exists('creator_clip_ban') && creator_clip_ban($user) > 0): ?>
    <div class="hk-warn hk-warn--last" role="alert"><span><?= creator_clip_ban_html($user) ?></span></div>
    <?php endif; ?>
    <?php require_once __DIR__ . '/../includes/clip_deadline.php'; $cdEnd = clip_deadline_notice($user);
    $ttEnd = (int)setting('tt_deadline_end', '0'); $ttNeed = $ttEnd > time() && !tiktok_accounts_for_user((int)$user['id']); ?>
    <?php if ($ttNeed): $cdEnd = null; /* one box at a time: connecting TikTok comes first */ ?>
    <div class="hk-warn hk-warn--last" role="alert">
        <strong>Connect your TikTok: <b data-countdown="<?= $ttEnd ?>"><?= (int)ceil(($ttEnd - time()) / 3600) ?>h</b> left</strong>
        <span>Connect it by <?= e(clip_deadline_date($ttEnd)) ?>, or your account is suspended.</span>
        <a class="hk-btn hk-btn--ink" href="<?= e(u('creator/connect_tiktok.php')) ?>">Connect TikTok now</a>
    </div>
    <?php endif; ?>
    <?php if ($cdEnd): $cdReady = ($user['assessment_status'] ?? '') === 'passed' || (int)($user['qualified'] ?? 0) === 1; ?>
    <div class="hk-warn hk-warn--last" role="alert">
        <strong>Send a clip to keep your account: <b data-countdown="<?= (int)$cdEnd ?>"><?= (int)ceil(($cdEnd - time()) / 3600) ?>h</b> left</strong>
        <span>Send 1 clip link by <?= e(clip_deadline_date($cdEnd)) ?>, or your account is paused.</span>
        <a class="hk-btn hk-btn--ink" href="<?= e(u($cdReady ? 'creator/index.php?tab=campaigns' : 'creator/index.php#hkNextTitle')) ?>"><?= $cdReady ? 'Take a clip now' : 'Do my next step' ?></a>
    </div>
    <?php endif; ?>
    <?php require_once __DIR__ . '/../includes/inactivity.php'; $inact = ($cdEnd || !empty($ttNeed)) ? null : inactivity_notice($user); ?>
    <?php if ($inact): $inReady = ($user['assessment_status'] ?? '') === 'passed' || (int)($user['qualified'] ?? 0) === 1; ?>
    <div class="hk-warn hk-warn--<?= $inact[0] === 2 ? 'last' : 'first' ?>" role="alert">
        <strong><?= $inact[0] === 2 ? 'Last warning: your account will be paused on ' . e(date('D j M', $inact[1])) : 'We have not seen a clip from you' ?></strong>
        <span><?= $inReady ? 'Take a ready clip, post it on your TikTok and send the link' : 'Finish your steps below (the test, then connect TikTok)' ?><?= $inact[0] === 2 ? ' before then. A paused account cannot sign in.' : ' to keep your account. Clips are waiting.' ?></span>
        <a class="hk-btn hk-btn--ink" href="<?= e(u($inReady ? 'creator/index.php?tab=campaigns' : 'creator/index.php#hkNextTitle')) ?>"><?= $inReady ? 'Take a clip now' : 'Do my next step' ?></a>
    </div>
    <?php endif; ?>
    <div class="hk-ask" data-push-card hidden>
        <span class="hk-ask__ico"><?= icon('bell') ?></span>
        <div class="hk-ask__txt"><strong>Turn on phone notifications</strong><small>Hear it when new clips arrive, when you are paid and when someone writes to you, even when HPL is closed.</small></div>
        <div class="hk-ask__btns"><button type="button" class="hk-btn hk-btn--ink" data-push-yes>Turn on</button><button type="button" class="hk-ask__no" data-push-no>Not now</button></div>
    </div>
    <?php $clipPath = (string)($user['clip_path'] ?? ''); if ($clipPath === ''): /* the first question (owner, 2026-11-13): which way will you clip? */ ?>
    <form class="hk-path" method="post" action="<?= e(u('creator/profile.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="clip_path"><input type="hidden" name="back" value="home">
        <strong>How do you want to earn?</strong>
        <button type="submit" name="path" value="ready" class="hk-path__opt hk-path__opt--main"><?= icon('video') ?><span><b>Post ready clips</b><small>We give you finished videos. You post them. No editing. Most people choose this.</small></span></button>
        <button type="submit" name="path" value="edit" class="hk-path__opt"><?= icon('film') ?><span><b>Edit my own clips</b><small>You get long raw footage and cut your own clips. For people who can edit.</small></span></button>
        <small class="hk-path__note">You can change this later on your Profile.</small>
    </form>
    <?php endif; ?>
    <?php if ($clipPath !== '' && empty($user['town'])): require_once __DIR__ . '/../includes/towns.php'; ?>
    <form class="hk-ask hk-ask--town" method="post" action="<?= e(u('creator/profile.php')) ?>">
        <?= csrf_field() ?><input type="hidden" name="action" value="town"><input type="hidden" name="back" value="home">
        <span class="hk-ask__ico"><?= icon('pin') ?></span>
        <div class="hk-ask__txt"><strong>Which town are you in?</strong><small>We want clippers in every town of Zambia.</small></div>
        <div class="hk-ask__btns"><select class="input" name="town" required aria-label="Your town"><?= zm_town_options() ?></select><button type="submit" class="hk-btn hk-btn--ink">Save</button></div>
    </form>
    <?php endif; ?>

    <?php if ($since && ($since['money'] > 0 || $since['views'] >= 50)): ?>
        <div class="hk-since" role="status">
            <span class="hk-since__ico"><?= icon('rocket') ?></span>
            <span>Since <?= e(time_ago($since['since'])) ?>:
                <?php if ($since['money'] > 0): ?><b data-countup="<?= e(number_format($since['money'], 2, '.', '')) ?>" data-money="1">+<?= money($since['money']) ?></b><?php endif; ?>
                <?php if ($since['money'] > 0 && $since['views'] > 0): ?> and <?php endif; ?>
                <?php if ($since['views'] > 0): ?><b data-countup="<?= (int)$since['views'] ?>">+<?= e(num($since['views'])) ?></b> views<?php endif; ?>
            </span>
        </div>
    <?php endif; ?>

    <!-- Money -->
    <section class="hk-wallet ui-live" id="liveEarn" data-endpoint="<?= e(u('creator/live.php')) ?>" data-csrf="<?= e(csrf_token()) ?>" data-withdraw="<?= e(u('creator/withdraw.php')) ?>" aria-label="Your money">
        <script type="application/json" id="liveData"><?= $liveJson ?></script>
        <div class="hk-wallet__top">
            <span><?= $live['balance'] >= $live['min_payout'] ? 'Ready to withdraw' : 'Your balance' ?></span>
            <span class="ui-live__pulse" title="Updates on its own while this page is open"><i></i>Live</span>
        </div>
        <div class="ui-live__amount hk-wallet__amount" data-live="balance" data-money="1"><?= money($live['balance']) ?></div>
        <div class="hk-wallet__today">
            <span class="hk-pill hk-pill--up"><b data-live="today_amount" data-money="1" data-sign="1"><?= ($live['today_amount'] > 0 ? '+' : '') . money($live['today_amount']) ?></b> today</span>
            <span><b data-live="today_views"><?= e(num($live['today_views'])) ?></b> new views</span>
        </div>
        <?php if ($live['in_review'] > 0): ?>
            <p class="hk-wallet__note"><?= icon('clock') ?><?= money($live['in_review']) ?> unlocks when the team approves your clip</p>
        <?php endif; ?>
        <?php if ($live['balance'] < $live['min_payout']): ?>
            <div class="ui-live__goal hk-wallet__goal" data-goal>
                <div class="ui-live__bar"><span style="width:<?= $live['min_payout'] > 0 ? min(100, (int)floor($live['balance'] / $live['min_payout'] * 100)) : 100 ?>%" data-goal-bar></span></div>
                <div class="ui-live__goaltext" data-goal-text><?= money($live['min_payout'] - $live['balance']) ?> to go until you can withdraw</div>
            </div>
        <?php endif; ?>
        <div class="hk-wallet__actions">
            <a class="hk-btn hk-btn--gold" href="<?= e(u('creator/withdraw.php')) ?>"><?= icon('wallet') ?>Withdraw</a>
            <button type="button" class="hk-btn hk-btn--dark" data-live-refresh><?= icon('refresh') ?><span>Refresh</span></button>
        </div>
        <p class="hk-wallet__sync" data-live-sync></p>
    </section>

    <?php if ($ttClips > 0): ?>
        <a class="hk-alert" href="<?= e(u('creator/connect_tiktok.php')) ?>">
            <?= icon('alert') ?>
            <span><strong><?= num($ttClips) ?> clip<?= $ttClips === 1 ? '' : 's' ?> not counted yet</strong><small><?= e(implode(' ', tiktok_needs_text($ttStatus['needs']))) ?></small></span>
            <?= icon('chevron-right') ?>
        </a>
    <?php endif; ?>

    <!-- The one thing to do now -->
    <section class="hk-next" aria-labelledby="hkNextTitle">
        <?php if ($freeReady > 0 && $currentStep === null): ?>
            <div class="hk-next__stack" aria-hidden="true"><i></i><i></i><i><?= icon('play') ?></i></div>
            <div class="hk-next__txt">
                <h2 id="hkNextTitle"><?= (int)$freeReady ?> ready clip<?= $freeReady === 1 ? '' : 's' ?> waiting</h2>
                <?php if ($nextTakeAt > time()): ?>
                    <p><?= ready_day_full((int)$user['id']) ? 'You posted ' . ready_clips_per_day_for((int)$user['id']) . ' today. Next one tomorrow, in' : 'Your next one unlocks in' ?> <b data-countdown="<?= (int)$nextTakeAt ?>"><?= e(gmdate('G\h i\m', $nextTakeAt - time())) ?></b></p>
                <?php else: ?>
                    <p>Already edited. Post it on TikTok and get paid for views.</p>
                <?php endif; ?>
                <a class="hk-btn hk-btn--ink" href="<?= e(u($focusHref . '#campReady')) ?>"><?= $nextTakeAt > time() ? 'See the clips' : 'Pick a clip' ?></a>
            </div>
        <?php else: ?>
            <span class="hk-next__ico"><?= icon($next['icon']) ?></span>
            <div class="hk-next__txt">
                <?php if ($currentStep !== null): ?><span class="hk-next__step">Step <?= $currentStep + 1 ?> of <?= count($steps) ?></span><?php endif; ?>
                <h2 id="hkNextTitle"><?= e($next['title']) ?></h2>
                <p><?= e($next['text']) ?></p>
                <?php if ($next['btn'] !== ''): ?><a class="hk-btn hk-btn--ink" href="<?= e(u($next['href'])) ?>"><?= e($next['btn']) ?></a><?php endif; ?>
                <?php if ($currentStep !== null): ?>
                    <div class="hk-dots" aria-label="<?= (int)$doneCount ?> of <?= count($steps) ?> steps done"><?php foreach ($steps as $i => $s): ?><i class="<?= $s['done'] ? 'is-done' : ($i === $currentStep ? 'is-now' : '') ?>"></i><?php endforeach; ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Your clips -->
    <section class="hk-clips" aria-labelledby="hkClipsTitle">
        <div class="hk-head">
            <h2 id="hkClipsTitle">Your clips</h2>
            <?php if ($homeClips): ?><a href="<?= e(u('creator/earnings.php')) ?>">See all</a><?php endif; ?>
        </div>
        <?php if (!$homeClips): ?>
            <div class="hk-empty">
                <div class="hk-empty__tiles" aria-hidden="true"><i></i><i></i><i></i></div>
                <p>Your clips show here, with their views going up live.</p>
            </div>
        <?php else: ?>
            <div class="hk-rail">
                <?php foreach ($homeClips as $c):
                    $isCamp = $c['src'] === 'campaign';
                    $cid = (int)substr($c['key'], strpos($c['key'], '-') + 1);
                    $href = $isCamp ? u('creator/clip.php?id=' . $cid) : (string)$c['url'];
                    $thumb = $isCamp ? ($thumbs[$cid] ?? null) : null;
                    $ms = clip_milestone_reached((int)$c['views']);
                ?>
                    <a class="hk-tile" href="<?= e($href) ?>" data-clip="<?= e($c['key']) ?>"<?= $isCamp ? '' : ' target="_blank" rel="noopener"' ?>>
                        <span class="hk-tile__pic">
                            <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?>
                            <span class="hk-tile__name"><?= e($c['title'] !== '' ? $c['title'] : platform_label($c['platform']) . ' clip') ?></span>
                            <?php if ($c['in_review']): ?><span class="hk-tile__tag">Checking</span><?php elseif ($ms > 0): ?><span class="hk-tile__tag hk-tile__tag--ms"><?= e(short_count($ms)) ?>+</span><?php endif; ?>
                            <span class="hk-tile__views"><?= icon('play') ?><span data-clip-views><?= e(num($c['views'])) ?></span></span>
                        </span>
                        <span class="hk-tile__money" data-clip-earned><?= money($c['earned']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- This week -->
    <section class="hk-week" aria-labelledby="hkWeekTitle">
        <div class="hk-head"><h2 id="hkWeekTitle">This week</h2><span class="hk-head__meta"><?= money($week['money']) ?> &middot; <?= e(num($week['views'])) ?> views</span></div>
        <div class="hk-week__grid">
            <div class="hk-streak">
                <div class="hk-streak__top"><span class="hk-streak__ico<?= $week['streak'] > 0 ? ' is-on' : '' ?>"><?= icon('flame') ?></span>
                    <div><strong><?= (int)$week['streak'] ?>-day streak</strong>
                    <small><?= $week['posted_today'] ? 'Clip sent today. Nice.' : ($week['streak'] > 0 ? 'Send a clip today to keep it' : 'Send a clip today to start one') ?></small></div></div>
                <ol class="hk-days" aria-label="Days you sent a clip this week">
                    <?php foreach ($week['days'] as $d): ?>
                        <li class="<?= $d['posted'] ? 'is-on' : '' ?><?= $d['today'] ? ' is-today' : '' ?><?= $d['future'] ? ' is-future' : '' ?>" title="<?= e($d['label']) ?><?= $d['posted'] ? ': clip sent' : '' ?>"><span><?= $d['posted'] ? icon('check') : '' ?></span><small><?= e($d['letter']) ?></small></li>
                    <?php endforeach; ?>
                </ol>
            </div>
            <div class="hk-goal">
                <?php if ($goal > 0): ?>
                    <svg class="hk-ring" viewBox="0 0 64 64" role="img" aria-label="<?= $goalPct ?>% of your weekly goal">
                        <circle cx="32" cy="32" r="26" class="hk-ring__bg"/>
                        <circle cx="32" cy="32" r="26" class="hk-ring__fg" stroke-dasharray="<?= round($ringC, 2) ?>" stroke-dashoffset="<?= round($ringC * (1 - $goalPct / 100), 2) ?>"/>
                        <text x="32" y="37" text-anchor="middle"><?= $goalPct ?>%</text>
                    </svg>
                    <div><strong><?= money($week['money']) ?> of <?= money($goal) ?></strong>
                        <small><?= $week['money'] >= $goal ? 'Goal reached. Set a bigger one?' : money($goal - $week['money']) . ' to your goal' ?></small>
                        <button type="button" class="hk-link" data-goal-edit>Change goal</button></div>
                <?php else: ?>
                    <span class="hk-goal__ico"><?= icon('goal') ?></span>
                    <div><strong>Set a goal for this week</strong><small>Clips you want to post</small>
                        <button type="button" class="hk-link" data-goal-edit>Set my goal</button></div>
                <?php endif; ?>
                <form method="post" action="<?= e(u('creator/goal.php')) ?>" class="hk-goalform" hidden>
                    <?= csrf_field() ?>
                    <div class="hk-goalform__opts">
                        <?php foreach ($goalOptions as $g): ?><button type="submit" name="goal" value="<?= $g ?>" class="hk-chip<?= (float)$g === $goal ? ' is-on' : '' ?>"><?= money($g) ?></button><?php endforeach; ?>
                    </div>
                    <label class="hk-goalform__own"><span>Or type your own</span><input class="input" type="number" name="goal_own" min="1" max="100000" step="1" inputmode="numeric" placeholder="K"></label>
                    <div class="hk-goalform__row"><button type="submit" class="hk-btn hk-btn--ink">Save</button><?php if ($goal > 0): ?><button type="submit" name="goal" value="0" class="hk-link">Remove goal</button><?php endif; ?></div>
                </form>
            </div>
        </div>
        <a class="hk-rank" href="<?= e(u('creator/community.php?tab=leaderboard')) ?>">
            <?php if ($week['rank'] > 0): ?>
                <span class="hk-rank__n">#<?= (int)$week['rank'] ?></span>
                <span class="hk-rank__txt"><strong>Out of <?= (int)$week['ranked'] ?> creator<?= $week['ranked'] === 1 ? '' : 's' ?> this week</strong>
                    <small><?= $week['ahead'] ? money($week['ahead']['gap']) . ' behind ' . e($week['ahead']['name']) : 'You are first. Stay there.' ?></small></span>
            <?php else: ?>
                <span class="hk-rank__n hk-rank__n--off"><?= icon('trophy') ?></span>
                <span class="hk-rank__txt"><strong>Get on this week's board</strong>
                    <small><?= $week['leader'] ? e($week['leader']['name']) . ' leads with ' . money($week['leader']['money']) : 'Nobody has earned yet this week. Be first.' ?></small></span>
            <?php endif; ?>
            <?= icon('chevron-right') ?>
        </a>
    </section>

    <!-- Other creators -->
    <a class="hk-pulse" href="<?= e(u('creator/community.php')) ?>">
        <span class="hk-pulse__dot" aria-hidden="true"></span>
        <span><strong><?= (int)$online ?> online now</strong>
            <?php if ($lastPaid): ?><small><?= e($shortName((string)$lastPaid['full_name'])) ?> got <?= money((float)$lastPaid['amount']) ?> to <?= e(provider_label((string)$lastPaid['payment_provider'])) ?> &middot; <?= e(time_ago($lastPaid['paid_at'])) ?></small><?php else: ?><small>Say hi in the community</small><?php endif; ?></span>
        <?= icon('chevron-right') ?>
    </a>

    <?php if ((!$qualified || !$hasPayout) && ($user['clip_path'] ?? '') === 'edit'): /* the Academy is for people who edit raw footage */ ?>
        <a class="hk-learn" href="<?= e(u('creator/course.php')) ?>"><?= icon('graduation') ?><span><strong>Creator Academy</strong><small>Short lessons on what gets views</small></span><?= icon('chevron-right') ?></a>
    <?php endif; ?>

    <?php if (empty($user['heard_from']) && !empty($user['town'])): require_once __DIR__ . '/../includes/signup_source.php'; // one question at a time: town first ?>
        <form method="post" action="<?= e(u('creator/heard.php')) ?>" class="hk-ask">
            <?= csrf_field() ?><input type="hidden" name="back" value="creator/index.php">
            <p>One quick question for the team</p>
            <?php heard_fields(); ?>
            <button class="hk-btn hk-btn--light" type="submit">Save</button>
        </form>
    <?php endif; ?>
</div>
<script src="<?= e(u('assets/js/home.js?v=20261109a')) ?>" defer></script>

<!-- CONTACT SUPPORT -->
<div class="tab-panel hide" data-panel="support">
    <div class="panel mb-3">
        <div class="panel__head"><h3>Contact support</h3></div>
        <div class="panel__body">
            <p class="muted">Need help? Open the chat and our team will reply right here.</p>
            <a class="btn btn--primary" href="<?= e(u('creator/support.php')) ?>">Open support chat</a>
        </div>
    </div>
</div>

<!-- ANNOUNCEMENTS -->
<div class="tab-panel hide" data-panel="announcements">
    <div class="ann-channel">
        <div class="ann-header">
            <div class="ann-header__left">
                <span class="ann-icon"><?= icon('megaphone') ?></span>
                <span class="ann-title">Announcements</span>
            </div>

        </div>

        <div class="ann-banner">
            <img src="<?= e(u('assets/media/guide/announcements.svg')) ?>" alt="HPL creator announcements" style="object-position:left center">
        </div>

        <?php if ($user['role'] === 'admin'): ?>
        <div class="ann-composer">
            <div class="avatar" style="width:40px;height:40px;border-radius:50%;background:#b8922a;color:#1a1a1a;display:flex;align-items:center;justify-content:center;font-weight:700;flex:none"><?= e(strtoupper(mb_substr($user['full_name'],0,1))) ?></div>
            <textarea class="ann-composer__input" placeholder="Write an announcement..." rows="2"></textarea>
            <button type="button" class="btn btn--primary btn--sm">Post</button>
        </div>
        <?php endif; ?>

        <div class="ann-feed" role="feed">
            <?php if (!$annTopLevel): ?>
                <div class="ann-empty">
                    <div class="ann-empty__icon"><?= icon('megaphone') ?></div>
                    <p>No announcements yet. Check back soon.</p>
                </div>
            <?php else: ?>
                <?php foreach ($annTopLevel as $idx => $post): ?>
                    <?php
                        $authorName = $post['full_name'] ?: 'Admin';
                        $handle = '@' . strtolower(preg_replace('/[^a-z0-9]/', '', $authorName));
                        $verified = $post['role'] === 'admin';
                        $created = strtotime($post['created_at']);
                        $diff = time() - $created;
                        if ($diff < 3600) { $dateStr = max(1, (int)floor($diff/60)) . 'm'; }
                        elseif ($diff < 86400) { $dateStr = (int)floor($diff/3600) . 'h'; }
                        elseif ($diff < 604800) { $dateStr = (int)floor($diff/86400) . 'd'; }
                        else { $dateStr = date('M j', $created); }
                        $liked = !empty($likedIds[(int)$post['id']]);
                        $children = $annChildren[(int)$post['id']] ?? [];
                    ?>
                    <article class="ann-post" role="article" data-post-id="<?= (int)$post['id'] ?>">
                        <div class="ann-post__row">
                            <div class="ann-avatar"><?= e(strtoupper(mb_substr($authorName,0,1))) ?></div>
                            <div class="ann-post__body">
                                <div class="ann-post__head">
                                    <button type="button" class="ann-name ann-name--link" data-profile="<?= (int)$post['author_id'] ?>"><?= e($authorName) ?></button>
                                    <?php if ($verified): ?><span class="ann-verified">C</span><?php endif; ?>
                                    <span class="ann-handle"><?= e($handle) ?></span>
                                    <span class="ann-dot">·</span>
                                    <span class="ann-date"><?= e($dateStr) ?></span>
                                    <?php if ($post['pinned']): ?><button type="button" class="ann-pinned ann-pinned--btn" data-post="<?= (int)$post['id'] ?>"><span><?= icon('info') ?></span> Pinned post</button><?php endif; ?>
                                    <button type="button" class="ann-overflow" aria-label="More">&#8230;</button>
                                </div>
                                <div class="ann-text ann-clamp" id="annText<?= (int)$post['id'] ?>"><?= nl2br(e($post['body'])) ?></div>
                                <button type="button" class="ann-seemore" data-target="annText<?= (int)$post['id'] ?>">See more</button>
                                <?php if ($post['media_url']): ?>
                                    <div class="ann-media"><img src="<?= e($post['media_url']) ?>" alt=""></div>
                                <?php endif; ?>
                                <div class="ann-actions">
                                    <button type="button" class="ann-action" aria-label="Comments"><span><?= icon('chats') ?></span> <?= e(num(count($children))) ?></button>
                                    <button type="button" class="ann-action ann-like <?= $liked ? 'is-liked' : '' ?>" data-post="<?= (int)$post['id'] ?>" aria-label="Like"><span><?= $liked ? '&#9829;' : '&#9825;' ?></span> <span class="ann-like-count"><?= e(num($post['like_count'])) ?></span></button>
                                    <span class="ann-action" title="Creators who saw this"><span><?= icon('chart') ?></span> <span class="ann-view-count"><?= e(num($post['view_count'])) ?></span></span>
                                    <button type="button" class="ann-action ann-share" aria-label="Share">&#10148;</button>
                                </div>
                            </div>
                        </div>
                        <?php if ($children): ?>
                            <div class="ann-thread">
                                <div class="ann-thread__line"></div>
                                <div class="ann-thread__content">
                                    <?php foreach ($children as $ch): ?>
                                        <?php
                                            $chName = $ch['full_name'] ?: 'Admin';
                                            $chHandle = '@' . strtolower(preg_replace('/[^a-z0-9]/', '', $chName));
                                            $diff = time() - strtotime($ch['created_at']);
                                            if ($diff < 3600) { $chDateStr = max(1,(int)floor($diff/60)).'m'; }
                                            elseif ($diff < 86400) { $chDateStr = (int)floor($diff/3600).'h'; }
                                            elseif ($diff < 604800) { $chDateStr = (int)floor($diff/86400).'d'; }
                                            else { $chDateStr = date('M j', strtotime($ch['created_at'])); }
                                        ?>
                                        <article class="ann-post ann-post--thread" role="article">
                                            <div class="ann-post__row">
                                                <div class="ann-avatar ann-avatar--sm"><?= e(strtoupper(mb_substr($chName,0,1))) ?></div>
                                                <div class="ann-post__body">
                                                    <div class="ann-post__head">
                                                        <button type="button" class="ann-name ann-name--link" data-profile="<?= (int)$ch['author_id'] ?>"><?= e($chName) ?></button>
                                                        <?php if ($ch['role']==='admin'): ?><span class="ann-verified">C</span><?php endif; ?>
                                                        <span class="ann-handle"><?= e($chHandle) ?></span>
                                                        <span class="ann-dot">·</span>
                                                        <span class="ann-date"><?= e($chDateStr) ?></span>
                                                    </div>
                                                    <div class="ann-text"><?= nl2br(e($ch['body'])) ?></div>
                                                </div>
                                            </div>
                                        </article>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                    <?php if ($idx < count($annTopLevel)-1): ?><div class="ann-divider"></div><?php endif; ?>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($annHasMore): ?>
        <div class="ann-loadmore">
            <a class="btn btn--ghost btn--sm" href="<?= e(u('creator/index.php?tab=announcements&ann_all=1')) ?>">Show older announcements</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- CAMPAIGNS -->
<div class="tab-panel hide" data-panel="campaigns">
    <div class="ui-page-head">
        <div>
            <h1>Campaigns</h1>
            <p class="ui-page-head__sub">Post clips. Get paid for views.</p>
        </div>
    </div>
    <ol class="ez-strip" aria-label="How campaigns work">
        <li><span><?= icon('target') ?></span>Join</li>
        <li><span><?= icon('download') ?></span>Download footage</li>
        <li><span><?= icon('video') ?></span>Post &amp; submit link</li>
        <li><span><?= icon('wallet') ?></span>Get paid</li>
    </ol>
    <?php if (!$campaigns): ?>
        <div class="ui-card"><div class="ui-empty"><?= icon('target') ?><p>No campaigns are open right now. New ones are announced in Announcements.</p></div></div>
    <?php else: ?>
        <div class="cl-grid">
            <?php foreach ($campaigns as $c):
                $total = (float)($c['total_budget'] ?? 0);
                $paid = (float)($c['paid_amount'] ?? 0);
                $remaining = max(0, $total - $paid);
                $progress = $total > 0 ? min(100, ($paid / $total) * 100) : 0;
                $cur = (string)($c['currency'] ?: 'K');
                $pls = campaign_platform_list($c);
                $best = campaign_best_rate($c);
                $open = $c['status'] === 'active' && ($c['camp_status'] ?? 'accepting') === 'accepting' && $remaining > 0;
                $cid = (int)$c['id'];
                $mine = $myCampClips[$cid] ?? null;
                if (!$open) { $badge = ['Closed', 'muted']; }
                elseif (!empty($myCampJoins[$cid])) { $badge = ['Joined', 'ok']; }
                elseif ($total > 0 && $remaining / $total < 0.1) { $badge = ['Almost full', 'warn']; }
                else { $badge = ['Open', 'ok']; }
            ?>
                <a class="cl-card<?= $open ? '' : ' is-closed' ?>" href="<?= e(u('creator/campaign.php?id=' . $cid)) ?>">
                    <div class="cl-card__media">
                        <img src="<?= e($c['banner_url'] ?: u('assets/media/campaign-default.svg')) ?>" alt="" loading="lazy" onerror="this.onerror=null;this.src='<?= e(u('assets/media/campaign-default.svg')) ?>'">
                        <span class="cl-badge cl-badge--<?= $badge[1] ?>"><?= e($badge[0]) ?></span>
                    </div>
                    <div class="cl-card__body">
                        <div class="cl-card__owner"><?= e($c['owner_name'] ?: 'HPL Gold Detectors') ?><?php if (!empty($c['owner_verified'])): ?> <span class="cl-ver" title="Verified"><?= icon('check') ?></span><?php endif; ?></div>
                        <h3 class="cl-card__title"><?= e($c['title']) ?></h3>
                        <div class="cl-card__rate">
                            <?php if ($best > 0): ?><strong><?= e(money($best)) ?></strong><span>/ 1K views</span><?php else: ?><span>Rates coming soon</span><?php endif; ?>
                            <span class="cl-card__pls"><?php foreach ($pls as $pl): if (!platform_is_live($pl['key'])) { continue; } ?><?= icon(in_array($pl['key'], ['tiktok', 'instagram', 'youtube', 'facebook'], true) ? $pl['key'] : 'link') ?><?php endforeach; ?></span>
                        </div>
                        <?php $rf = (int)($readyCounts[$cid]['free'] ?? 0); if ($open && $rf > 0): ?><span class="ez-camp__ready"><?= icon('video') ?><?= $rf ?> ready clip<?= $rf === 1 ? '' : 's' ?> to post</span><?php endif; ?>
                        <?php if ($total > 0): ?>
                            <div class="cl-bar" role="progressbar" aria-label="Budget used" aria-valuenow="<?= (int)$progress ?>" aria-valuemin="0" aria-valuemax="100"><i style="width:<?= round($progress, 1) ?>%"></i></div>
                            <div class="cl-card__foot"><span><?= e($cur . number_format($remaining, 0)) ?> left of <?= e($cur . number_format($total, 0)) ?></span><span><?= (int)round($progress) ?>% used</span></div>
                        <?php endif; ?>
                        <span class="ez-cardcta<?= !$open ? ' is-off' : (!empty($myCampJoins[$cid]) ? ' is-joined' : '') ?>"><?= !$open ? 'Closed' : (!empty($myCampJoins[$cid]) ? 'Open campaign' : 'Join this campaign') ?><?= $open ? icon('arrow-right') : '' ?></span>
                        <?php if ($mine): ?>
                            <div class="cl-card__mine"><?= (int)$mine['n'] ?> clip<?= (int)$mine['n'] === 1 ? '' : 's' ?> &middot; <b><?= e($cur . number_format((float)$mine['e'], 2)) ?></b> earned</div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
(function(){
    var tabs = document.querySelectorAll('.dash-tab, button.sidebar-tab');
    var panels = document.querySelectorAll('.tab-panel');
    var names = [];
    tabs.forEach(function(t){ names.push(t.getAttribute('data-tab')); });
    function show(name){
        if (names.indexOf(name) === -1) name = 'home';
        tabs.forEach(function(t){ t.classList.toggle('is-active', t.getAttribute('data-tab')===name); });
        panels.forEach(function(p){ p.classList.toggle('hide', p.getAttribute('data-panel')!==name); });
        try { localStorage.setItem('dashTab', name); } catch(e){}
        document.querySelectorAll('[data-dock-tab]').forEach(function(d){ d.classList.toggle('is-active', d.getAttribute('data-dock-tab')===name); });
        var t = document.querySelector('.hplbar__title'); if (t) t.textContent = {home:'Home', campaigns:'Campaigns', announcements:'Announcements', chats:'Community chat', support:'Support'}[name] || 'Dashboard';
    }
    document.querySelectorAll('[data-dock-tab]').forEach(function(d){ d.addEventListener('click', function(e){ var n=d.getAttribute('data-dock-tab'); if (names.indexOf(n)!==-1){ e.preventDefault(); show(n); window.scrollTo(0,0); } }); });
    tabs.forEach(function(t){ t.addEventListener('click', function(){ show(t.getAttribute('data-tab')); if (window.innerWidth <= 900) { var sb=document.querySelector('.app__sidebar'); if (sb && sb.classList.contains('is-open')) sb.classList.remove('is-open'); var ov=document.querySelector('.sidebar-overlay'); if (ov) ov.classList.remove('is-active'); document.body.classList.remove('sidebar-locked'); } }); });
    // Open on Home ("Your next step") unless the link asks for a tab, so sign-in and
    // notification links never land on whatever tab was used last time.
    var qtab = null; try { qtab = new URLSearchParams(window.location.search).get('tab'); } catch(e){}
    var initial = (qtab && names.indexOf(qtab) !== -1) ? qtab : 'home';
    show(initial);
})();
</script>

<!-- Contact support modal -->
<div class="cs-overlay" id="csOverlay" hidden>
    <div class="cs-modal" role="dialog" aria-modal="true" aria-labelledby="csTitle">
        <div class="cs-modal__head">
            <h2 id="csTitle">Contact support</h2>
            <button type="button" class="cs-modal__close" id="csClose" aria-label="Close">&times;</button>
        </div>
        <div class="cs-info">
            <div class="cs-info__line1">You're messaging the <?= e(APP_NAME) ?> admin team.</div>
            <div class="cs-info__line2">One of the admins will reply, whoever is best suited to help.</div>
        </div>
        <textarea class="cs-textarea" id="csText" placeholder="What would you like help with?" rows="5"></textarea>
        <div class="cs-divider"></div>
        <div class="cs-footer">
            <button type="button" class="cs-btn" id="csCancel">Cancel</button>
            <button type="button" class="cs-btn cs-btn--send" id="csSend" disabled>Send message</button>
        </div>
    </div>
</div>

<script>
(function(){
    var overlay = document.getElementById('csOverlay');
    var modal = overlay.querySelector('.cs-modal');
    var text = document.getElementById('csText');
    var send = document.getElementById('csSend');
    var cancel = document.getElementById('csCancel');
    var closeBtn = document.getElementById('csClose');
    var lastFocus = null;
    var threadId = <?= (int)$supportThreadId ?>;
    var csrf = '<?= e($_SESSION['csrf'] ?? '') ?>';

    function openModal(){
        lastFocus = document.activeElement;
        overlay.hidden = false;
        document.body.style.overflow = 'hidden';
        requestAnimationFrame(function(){ overlay.classList.add('is-open'); });
        setTimeout(function(){ text.focus(); }, 160);
    }
    function closeModal(){
        overlay.classList.remove('is-open');
        setTimeout(function(){ overlay.hidden = true; document.body.style.overflow = ''; if (lastFocus) lastFocus.focus(); }, 160);
    }
    function updateSend(){
        var has = text.value.trim().length > 0;
        send.disabled = !has;
        send.classList.toggle('is-enabled', has);
    }
    text.addEventListener('input', updateSend);

    // open triggers
    document.querySelectorAll('[data-modal="contact-support"]').forEach(function(b){
        b.addEventListener('click', function(e){ e.preventDefault(); openModal(); });
    });

    closeBtn.addEventListener('click', closeModal);
    cancel.addEventListener('click', closeModal);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', function(e){
        if (overlay.hidden) return;
        if (e.key === 'Escape') closeModal();
    });

    // focus trap
    modal.addEventListener('keydown', function(e){
        if (e.key !== 'Tab') return;
        var focusables = modal.querySelectorAll('button, textarea, input, [tabindex]:not([tabindex="-1"])');
        var first = focusables[0], last = focusables[focusables.length-1];
        if (e.shiftKey && document.activeElement === first){ e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last){ e.preventDefault(); first.focus(); }
    });

    send.addEventListener('click', function(){
        if (send.disabled) return;
        send.disabled = true;
        send.textContent = 'Sending...';
        var fd = new FormData();
        fd.append('thread', threadId);
        fd.append('message', text.value.trim());
        fd.append('csrf', csrf);
        fetch('<?= e(u('creator/dm_feed.php')) ?>', { method:'POST', credentials:'same-origin', body: fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d && d.ok) {
                    closeModal();
                    window.location.href = '<?= e(u('creator/support.php')) ?>';
                } else {
                    hplToast((d && d.error) ? d.error : 'Could not send. Try again.', 'error');
                    send.disabled = false;
                    send.textContent = 'Send message';
                    updateSend();
                }
            })
            .catch(function(){
                hplToast('Network error. Check your connection.', 'error');
                send.disabled = false;
                send.textContent = 'Send message';
                updateSend();
            });
    });

    function showToast(msg){
        var t = document.createElement('div');
        t.className = 'cs-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function(){ t.classList.add('is-show'); });
        setTimeout(function(){ t.classList.remove('is-show'); setTimeout(function(){ t.remove(); }, 300); }, 2500);
    }
})();
</script>

<script>
(function(){
    var csrf = '<?= e($_SESSION['csrf'] ?? '') ?>';
    // See more toggle + hide when not needed
    document.querySelectorAll('.ann-seemore').forEach(function(b){
        var t = document.getElementById(b.getAttribute('data-target'));
        if (t && t.scrollHeight <= t.clientHeight + 2) { b.style.display = 'none'; }
        b.addEventListener('click', function(){
            if (!t) return;
            t.classList.toggle('ann-expanded');
            b.textContent = t.classList.contains('ann-expanded') ? 'See less' : 'See more';
        });
    });
    // Like toggle
    document.querySelectorAll('.ann-like').forEach(function(b){
        b.addEventListener('click', function(){
            var postId = b.getAttribute('data-post');
            var countEl = b.querySelector('.ann-like-count');
            var icon = b.querySelector('span');
            var fd = new FormData();
            fd.append('post_id', postId);
            fd.append('csrf', csrf);
            fetch('<?= e(u('creator/ann_like.php')) ?>', { method:'POST', credentials:'same-origin', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d || !d.ok) return;
                    b.classList.toggle('is-liked', d.liked);
                    if (icon) icon.innerHTML = d.liked ? '&#9829;' : '&#9825;';
                    if (countEl) countEl.textContent = Number(d.count).toLocaleString();
                    var vc = b.closest('.ann-post') && b.closest('.ann-post').querySelector('.ann-view-count');
                    if (vc && d.views != null) vc.textContent = Number(d.views).toLocaleString();
                });
        });
    });
    // Views: a post counts as seen once at least half of it has been on screen for 1 second.
    (function(){
        if (!('IntersectionObserver' in window)) return;
        var sent = {}, timers = {}, queue = [], flushT = null;
        function flush(){
            flushT = null;
            if (!queue.length) return;
            var fd = new FormData();
            fd.append('ids', queue.join(','));
            fd.append('csrf', csrf);
            queue = [];
            fetch('<?= e(u('creator/ann_view.php')) ?>', { method:'POST', credentials:'same-origin', body: fd, keepalive: true })
                .then(function(r){ return r.json(); })
                .then(function(d){
                    if (!d || !d.ok || !d.counts) return;
                    Object.keys(d.counts).forEach(function(id){
                        var el = document.querySelector('.ann-post[data-post-id="' + id + '"] .ann-view-count');
                        if (el) el.textContent = Number(d.counts[id]).toLocaleString();
                    });
                }).catch(function(){});
        }
        var io = new IntersectionObserver(function(entries){
            entries.forEach(function(en){
                var id = en.target.getAttribute('data-post-id');
                if (!id || sent[id]) return;
                if (en.isIntersecting && en.intersectionRatio >= 0.5) {
                    if (!timers[id]) timers[id] = setTimeout(function(){
                        sent[id] = true; io.unobserve(en.target);
                        queue.push(id);
                        if (!flushT) flushT = setTimeout(flush, 400);
                    }, 1000);
                } else if (timers[id]) { clearTimeout(timers[id]); timers[id] = null; }
            });
        }, { threshold: [0, 0.5] });
        document.querySelectorAll('.ann-post[data-post-id]').forEach(function(p){ io.observe(p); });
    })();
    // Pinned post button: expand + scroll + highlight
    document.querySelectorAll('.ann-pinned--btn').forEach(function(b){
        b.addEventListener('click', function(){
            var postEl = b.closest('.ann-post');
            if (!postEl) return;
            var text = postEl.querySelector('.ann-text');
            if (text) text.classList.add('ann-expanded');
            var sm = postEl.querySelector('.ann-seemore');
            if (sm) sm.textContent = 'See less';
            postEl.classList.add('is-highlighted');
            postEl.scrollIntoView({ behavior:'smooth', block:'center' });
            setTimeout(function(){ postEl.classList.remove('is-highlighted'); }, 2000);
        });
    });

    // Share
    document.querySelectorAll('.ann-share').forEach(function(b){
        b.addEventListener('click', function(){
            var url = window.location.href.split('#')[0] + '#announcements';
            if (navigator.clipboard) { navigator.clipboard.writeText(url).then(function(){ hplToast('Link copied', 'ok'); }); }
            else { hplToast(url); }
        });
    });
})();
</script>


<!-- Profile modal -->
<div class="pf-overlay" id="pfOverlay" hidden>
    <div class="pf-modal" role="dialog" aria-modal="true" aria-labelledby="pfName">
        <button type="button" class="pf-close" id="pfClose" aria-label="Close">&times;</button>
        <div class="pf-head">
            <img class="pf-avatar" id="pfAvatar" alt="" hidden>
            <div>
                <h2 id="pfName" class="pf-name"></h2>
                <div class="pf-handle" id="pfHandle"></div>
                <div class="pf-followed" id="pfFollowed"></div>
            </div>
        </div>
        <div class="pf-bio" id="pfBio"></div>
        <div class="pf-email" id="pfEmail"></div>
        <button type="button" class="pf-msgbtn" id="pfMsg">Send message</button>
    </div>
</div>

<script>
(function(){
    var overlay = document.getElementById('pfOverlay');
    var closeBtn = document.getElementById('pfClose');
    var pfId = 0;
    function openProfile(id){
        pfId = id;
        fetch('<?= e(u('creator/profile_view.php')) ?>?id=' + encodeURIComponent(id), { credentials:'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (!d || d.error) { hplToast(d && d.error ? d.error : 'Could not load profile.', 'error'); return; }
                document.getElementById('pfName').textContent = d.name || '';
                document.getElementById('pfHandle').textContent = d.handle || '';
                var av = document.getElementById('pfAvatar');
                av.src = d.avatar || '<?= e(u('assets/media/guide/avatar.svg')) ?>'; av.hidden = false;
                var followed = d.followers ? (d.followers.toLocaleString() + ' followers') : '';
                document.getElementById('pfFollowed').textContent = followed;
                document.getElementById('pfBio').textContent = d.bio || '';
                document.getElementById('pfEmail').textContent = d.email ? ('Email: ' + d.email) : '';
                overlay.hidden = false;
                requestAnimationFrame(function(){ overlay.classList.add('is-open'); });
            });
    }
    function closeProfile(){
        overlay.classList.remove('is-open');
        setTimeout(function(){ overlay.hidden = true; }, 160);
    }
    closeBtn.addEventListener('click', closeProfile);
    overlay.addEventListener('click', function(e){ if (e.target === overlay) closeProfile(); });
    document.addEventListener('keydown', function(e){ if (!overlay.hidden && e.key === 'Escape') closeProfile(); });
    document.querySelectorAll('.ann-name--link').forEach(function(b){
        b.addEventListener('click', function(){ openProfile(b.getAttribute('data-profile')); });
    });
    document.getElementById('pfMsg').addEventListener('click', function(){
        closeProfile();
        window.location.href = '<?= e(u('creator/messages.php')) ?>?user=' + encodeURIComponent(pfId);
    });
})();
</script>


<script src="<?= e(u('assets/js/live.js?v=20261104a')) ?>"></script>


<?php require __DIR__ . '/../includes/bonus_modal.php'; ?>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
