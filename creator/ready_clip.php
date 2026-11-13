<?php
/**
 * Ready clips for creators: take a clip (POST), preview a clip, download a clip you took.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ready_clips.php';
$user = require_creator();
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_response(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
    }
    // Give a taken clip back (owner, 2026-11-13): no warning, the clip goes back to the free list at once.
    if (post('action') === 'give_back') {
        $err = ready_clip_give_back((int)post('clip_id'), $uid);
        if ($err !== null) {
            json_response(['ok' => false, 'error' => $err], 400);
        }
        json_response(['ok' => true, 'message' => 'Clip given back. You can take another one when you are ready to post it.']);
    }
    if (!creator_can_post_ready($user)) {
        json_response(['ok' => false, 'error' => 'First pass the short HPL test. Then you can take ready clips.', 'redirect' => u('creator/assessment.php')], 403);
    }
    if (creator_clip_ban($user) > 0) {
        json_response(['ok' => false, 'error' => creator_clip_ban_text($user)], 403);
    }
    $tt = ready_tiktok_status($uid, true);
    if ($tt['reason'] === 'connect') {
        json_response(['ok' => false, 'error' => 'Ready clips are for TikTok. First connect your TikTok account.', 'redirect' => u('creator/connect_tiktok.php')], 403);
    }
    if ($tt['reason'] === 'bio') {
        json_response(['ok' => false, 'error' => (ready_bio_hint($tt['bio'] ?? null) ?: ready_bio_fix_text() . ' Then tap Take again.'), 'redirect' => u('creator/tiktok_ready.php')], 403);
    }
    if ($tt['reason'] !== null) {
        json_response(['ok' => false, 'error' => $tt['reason']], 400);
    }
    // The account must be a real, active TikTok account (follows people, warmed up). 2026-11-07.
    $chk = ready_account_check($user, $tt);
    if ($chk['next'] !== null) {
        $it = $chk['items'][$chk['next']];
        json_response(['ok' => false, 'error' => 'Your TikTok is not ready yet: ' . $it['title'] . '. ' . $it['text'], 'redirect' => u('creator/tiktok_ready.php')], 403);
    }
    $err = ready_clip_take((int)post('clip_id'), $user);
    if ($err !== null) {
        json_response(['ok' => false, 'error' => $err], 400);
    }
    json_response(['ok' => true, 'message' => 'The clip is yours until ' . date('D j M, H:i', time() + ready_clip_days() * 86400) . '. Download it, post it on your TikTok, then send the link.']);
}

$clip = fetch_one('SELECT * FROM ready_clips WHERE id = ? AND is_active = 1', [(int)get('id')]);
$path = $clip ? ready_clips_dir() . '/' . basename((string)$clip['file_name']) : '';
if (!$clip || !is_file($path)) {
    http_response_code(404);
    exit('This clip is not available.');
}
$mine = (int)$clip['claimed_by'] === $uid;
$joined = (bool)fetch_one('SELECT id FROM campaign_joins WHERE campaign_id = ? AND user_id = ?', [(int)$clip['campaign_id'], $uid]);

/** Hand the file to the web server (see "Fast downloads" in includes/ready_clips.php). */
$goStatic = function (string $fileName, bool $download = false, int $cache = 0) {
    $pub = ready_clip_public($fileName);
    if ($pub === null) {
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Cache-Control: ' . ($cache > 0 ? 'private, max-age=' . $cache : 'private, no-store'));
    header('Location: ' . u($pub) . ($download ? '?dl=1' : ''), true, 302);
    exit;
};

if (get('poster') === '1') {
    // The still picture is not the clip itself: anyone signed in may see it.
    $pp = ready_clips_dir() . '/' . pathinfo(basename((string)$clip['file_name']), PATHINFO_FILENAME) . '.jpg';
    if (!is_file($pp)) { http_response_code(404); exit; }
    $goStatic(basename($pp), false, 3600);
    header('Content-Type: image/jpeg');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($pp));
    readfile($pp);
    exit;
}
if (get('dl') === '1') {
    if (!$mine) {
        flash('warning', 'Take the clip first. Then you can download it.');
        redirect('creator/campaign.php?id=' . (int)$clip['campaign_id'] . '#campReady');
    }
    q('UPDATE ready_clips SET downloads = downloads + 1 WHERE id = ?', [(int)$clip['id']]);
    $c = fetch_one('SELECT title FROM campaigns WHERE id = ?', [(int)$clip['campaign_id']]);
    $name = preg_replace('/[^A-Za-z0-9-]+/', '-', (string)($c['title'] ?? 'HPL')) . '-clip-' . (int)$clip['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION);
    $goStatic((string)$clip['file_name'], true);
    ready_clip_send($path, true, $name);
}

// Preview: free clips for creators in the campaign, and your own clips.
if (!$mine && !($joined && creator_can_post_ready($user) && $clip['claimed_by'] === null)) {
    http_response_code(403);
    exit('Join the campaign to watch its ready clips.');
}
$goStatic((string)$clip['file_name'], false, 600);
ready_clip_send($path, false, 'clip-' . (int)$clip['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION));
