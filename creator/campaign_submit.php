<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/verification.php';
require_once __DIR__ . '/../includes/tiktok.php';
require_once __DIR__ . '/../includes/ready_clips.php';
$user = require_creator();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['ok' => false, 'error' => 'Method not allowed.'], 405); }
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false, 'error' => 'Session expired.'], 419);
}
$campaignId = (int)($_POST['campaign_id'] ?? 0);
// A ready clip (made by HPL) can be sent by anyone who passed the test; an own edit needs full approval.
$readyId = (int)($_POST['ready_clip_id'] ?? 0);
$qualified = (int)($user['qualified'] ?? 0) === 1;
if ($readyId <= 0 && !$qualified) {
    json_response(['ok' => false, 'error' => creator_can_post_ready($user)
        ? 'Take a ready clip below, post it, then send its link with the "I posted it" button. To send clips you edit yourself, first send us one video you edited.'
        : 'First pass the short HPL test. Then you can send campaign clips.'], 403);
}
if ($readyId > 0 && !creator_can_post_ready($user)) {
    json_response(['ok' => false, 'error' => 'First pass the short HPL test. Then you can send campaign clips.'], 403);
}
if (creator_clip_ban($user) > 0) {
    json_response(['ok' => false, 'error' => creator_clip_ban_text($user)], 403);
}
$platform = post('platform');
$url = normalize_url(post('url'));
if ($campaignId <= 0) {
    json_response(['ok' => false, 'error' => 'Campaign is missing. Reopen the campaign and try again.'], 400);
}
if (!$url) {
    json_response(['ok' => false, 'error' => 'Enter a valid public post URL, such as https://www.tiktok.com/@name/video/123.'], 400);
}
$campaign = fetch_one('SELECT * FROM campaigns WHERE id = ?', [$campaignId]);
if (!$campaign) { json_response(['ok' => false, 'error' => 'Campaign not found.'], 404); }
if (($campaign['status'] ?? 'active') !== 'active' || $campaign['camp_status'] !== 'accepting' || (float)$campaign['paid_amount'] >= (float)$campaign['total_budget']) {
    json_response(['ok' => false, 'error' => (float)$campaign['total_budget'] <= 0 ? 'This campaign is not open yet.' : 'This campaign is no longer accepting clips.'], 400);
}
$platform = strtolower(trim($platform));
$capLimit = campaign_creator_cap($campaign);
if ($capLimit > 0 && campaign_creator_earned((int)$campaign['id'], (int)$user['id']) >= $capLimit) {
    json_response(['ok' => false, 'error' => 'You have reached the ' . money($capLimit) . ' limit for this campaign. Great work! Join another campaign to keep earning.'], 400);
}
$allowed = array_column(campaign_platform_list($campaign), 'key');
if (!platform_is_live($platform)) {
    json_response(['ok' => false, 'error' => platform_label($platform) . ' is coming soon. For now, post your clip on TikTok.'], 400);
}
if (!in_array($platform, $allowed, true)) {
    json_response(['ok' => false, 'error' => 'Select one of this campaign’s supported platforms.'], 400);
}
$joined = fetch_one('SELECT id FROM campaign_joins WHERE campaign_id = ? AND user_id = ?', [$campaignId, (int)$user['id']]);
if (!$joined) {
    json_response(['ok' => false, 'error' => 'Join the campaign first.'], 400);
}
// Ready clips: checked with TikTok BEFORE the site-wide lock, so slow TikTok answers never block others.
if ($readyId > 0 && !ready_clip_owned_unposted($readyId, (int)$user['id'], $campaignId)) {
    json_response(['ok' => false, 'error' => 'That ready clip is not yours, or its link was already sent. Take a clip first.'], 400);
}
// Two clips a day (owner, 2026-11-12), for every campaign clip, ready or own edit.
$myPerDay = ready_clips_per_day_for((int)$user['id']);
if ($myPerDay > 0 && clips_sent_today((int)$user['id']) >= $myPerDay) {
    json_response(['ok' => false, 'error' => 'You already sent ' . $myPerDay . ' clip' . ($myPerDay === 1 ? '' : 's') . ' today, the most for one day. Send this one tomorrow.'
        . ($myPerDay < ready_clips_per_day() ? ' ' . clip_trust_line((int)$user['id']) : '')], 400);
}
if ($readyId > 0 && post('as_is') !== '1') {
    json_response(['ok' => false, 'error' => ready_sound_rule() . ' Tick the box to say you posted it as it is.'], 400);
}
if ($readyId > 0) {
    // Ready clips: TikTok only, on the connected account, HPL in the bio and tagged in the caption.
    if ($platform !== 'tiktok') {
        json_response(['ok' => false, 'error' => 'Ready clips are posted on TikTok only. Choose TikTok and paste your TikTok link.'], 400);
    }
    $url = hpl_resolve_url($url); // short vt.tiktok.com links become the full link with the @name
    $h = hpl_handle_from_url('tiktok', $url);
    if ($h === null || tiktok_video_id($url) === null) {
        json_response(['ok' => false, 'error' => 'Use the full link of your TikTok video: open it, tap Share, then "Copy link".'], 400);
    }
    $tt = ready_tiktok_status((int)$user['id'], true, $h);
    if ($tt['reason'] === 'connect') {
        json_response(['ok' => false, 'error' => 'This video is on @' . $h . ', which is not a TikTok account you connected. Post ready clips on your connected TikTok, or connect @' . $h . ' on your Home page.'], 400);
    }
    if ($tt['reason'] === 'bio') {
        json_response(['ok' => false, 'error' => 'Your link was not sent yet. ' . (ready_bio_hint($tt['bio'] ?? null) ?: ready_bio_fix_text() . ' Then tap Send again.'), 'bio' => true], 400);
    }
    if ($tt['reason'] !== null) {
        json_response(['ok' => false, 'error' => $tt['reason']], 400);
    }
    $acc = $tt['acc'];
    $vid = tiktok_video_id($url);
    $cap = $vid !== null ? tiktok_video_caption($acc, $vid) : '';
    if ($cap === null) {
        json_response(['ok' => false, 'error' => 'We could not check the video on TikTok right now. Wait a minute and send the link again.'], 400);
    }
    if ($cap === '' && $vid !== null && tiktok_video_info($acc, $vid) === []) {
        json_response(['ok' => false, 'error' => 'This video is not on your TikTok @' . $acc['username'] . '. Post the clip there, then send its link.'], 400);
    }
    $readyInfo = $vid !== null ? tiktok_video_info($acc, $vid) : null; // posted when, how long: for the automatic check
    if (is_array($readyInfo) && ($gapErr = ready_post_gap_error((int)$user['id'], (int)($readyInfo['create_time'] ?? 0))) !== null) {
        json_response(['ok' => false, 'error' => $gapErr], 400);
    }
    $rcTaken = (int)strtotime((string)fetch_val('SELECT claimed_at FROM ready_clips WHERE id = ?', [$readyId], ''));
    if (!ready_caption_ok($cap, $rcTaken)) {
        json_response(['ok' => false, 'error' => 'Your link was not sent yet. ' . ready_caption_hint((string)$cap) . ' Then send the link again.'
            . (trim((string)$cap) !== '' ? ' TikTok shows us your caption as: "' . mb_substr(trim((string)$cap), 0, 90) . '"' : ' TikTok shows us no caption on this video.')], 400);
    }
}
// TikTok views are read through the creator's connected TikTok. A clip we cannot read when it is
// sent would have its starting count taken days later, and the views in between would never be
// paid. So the TikTok account in the link must be connected (and working) before sending.
if ($platform === 'tiktok' && $readyId <= 0 && tiktok_configured()) {
    $urlCheck = hpl_resolve_url($url);
    if (!tiktok_link_readable((int)$user['id'], $urlCheck)) {
        $h = tiktok_link_handle($urlCheck);
        json_response(['ok' => false, 'error' => 'First connect ' . ($h ? 'your TikTok @' . $h : 'the TikTok account this video is on')
            . ', so we can count every view from the moment you send it. It takes one minute. Then send the link again.',
            'connect' => u('creator/connect_tiktok.php')], 400);
    }
}
// One submission at a time across the site, so the same video cannot slip in twice
// (two campaigns, two tabs) between the duplicate check and the insert. Released when the request ends.
fetch_val("SELECT GET_LOCK('hpl_clip_submit', 10)");
if (fetch_one('SELECT id FROM campaign_submissions WHERE url = ?', [$url]) || fetch_one('SELECT id FROM content_links WHERE url = ?', [$url])) {
    json_response(['ok' => false, 'error' => 'This video has already been submitted. Each video can only earn once.'], 400);
}
$clipErr = hpl_clip_guard((int)$user['id'], $platform, $url, $videoKey);
if ($clipErr !== null) {
    json_response(['ok' => false, 'error' => $clipErr], 400);
}
if ($readyId > 0 && !ready_clip_owned_unposted($readyId, (int)$user['id'], $campaignId)) {
    json_response(['ok' => false, 'error' => 'The link for this ready clip was already sent.'], 400);
}
q('INSERT INTO campaign_submissions (campaign_id, user_id, platform, url, video_key, ready_clip_id) VALUES (?,?,?,?,?,?)', [$campaignId, (int)$user['id'], $platform, $url, $videoKey, $readyId > 0 ? $readyId : null]);
$newSid = (int)db()->lastInsertId();
if ($readyId > 0) {
    q('UPDATE ready_clips SET submission_id = ? WHERE id = ?', [$newSid, $readyId]);
    if (!empty($readyInfo['create_time'])) {
        try {
            q('UPDATE campaign_submissions SET posted_at = FROM_UNIXTIME(?), video_h = ? WHERE id = ?', [(int)$readyInfo['create_time'], min(65535, (int)($readyInfo['height'] ?? 0)) ?: null, $newSid]);
        } catch (Throwable $e) {
        }
    }
}
if (setting('tg_owner_chat_id', '') !== '') {
    try { require_once __DIR__ . '/../includes/telegram.php'; tg_alert_clip($newSid); } catch (Throwable $e) {}
}
fetch_val("SELECT RELEASE_LOCK('hpl_clip_submit')");
// Read the views the moment the clip is sent (owner decision 2026-11-03): that count becomes
// its starting point, so only views after sending earn. If the platform can't be read yet,
// the first later read is the starting point, as before.
$viewNote = '';
try {
    require_once __DIR__ . '/../includes/view_fetcher.php';
    $startViews = ViewFetcher::tryFetch($platform, $url, (int)$user['id']);
    if ($startViews !== null) {
        credit_campaign_submission($newSid, (int)$startViews);
        $viewNote = ' It has ' . num((int)$startViews) . ' views now; you earn from the views after this.';
    }
} catch (Throwable $e) {
}
if ($viewNote === '' && $platform === 'tiktok' && tiktok_configured()) {
    if (!tiktok_link_readable((int)$user['id'], $url)) {
        $h = tiktok_link_handle($url);
        $viewNote = ' Important: connect ' . ($h ? 'your TikTok @' . $h : 'the TikTok account you posted it on') . ' on your Home page, or we cannot read this clip\'s views.';
    }
}
// Ready clips: the automatic check, and (only when the owner switched it on) approval at once.
$autoNote = '';
if ($readyId > 0) {
    try {
        $rc = fetch_one('SELECT * FROM ready_clips WHERE id = ?', [$readyId]);
        [$autoRes, $autoWhy] = $rc ? ready_clip_auto_check($rc, $readyInfo ?? null) : ['review', 'Clip not found'];
        q('UPDATE campaign_submissions SET auto_check = ?, auto_note = ? WHERE id = ?', [$autoRes, mb_substr($autoWhy, 0, 250), $newSid]);
    } catch (Throwable $e) {
        error_log('ready auto check: ' . $e->getMessage());
    }
}
// The Brain reviews every campaign clip (includes/clip_review.php). Sending only puts the link in the queue; after this
// answer has gone to the phone, this request works the queue for a few seconds if no other reviewer is busy (one at a
// time for the whole site, so 1,000 creators sending at once never pile up). The background sync is the safety net.
try {
    require_once __DIR__ . '/../includes/clip_review.php';
    if (clip_review_on() && $platform === 'tiktok') {
        q('UPDATE campaign_submissions SET review_due_at = NOW() WHERE id = ?', [$newSid]);
        register_shutdown_function(function () {
            if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
            if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); } elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }
            @ignore_user_abort(true);
            @set_time_limit(60);
            clip_review_work(8.0, 3);
        });
    }
} catch (Throwable $e) {
    error_log('clip review queue: ' . $e->getMessage());
}
json_response(['ok' => true, 'message' => 'Clip sent.' . $autoNote . $viewNote . ' We count the views it gets from now on. We are checking it now and will tell you when it is approved.']);
