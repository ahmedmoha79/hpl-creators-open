<?php
/**
 * TikTok Login Kit: connect / callback / disconnect.
 * Register this exact URL as the redirect URI in the TikTok developer portal:
 *   https://<your domain>/tiktok_auth.php
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/verification.php';
require_once __DIR__ . '/includes/tiktok.php';
require_once __DIR__ . '/includes/view_fetcher.php';

$user = require_creator();
$back = 'creator/profile.php#socialPage';
// Every result of connecting lands on one simple page with one clear message and one button.
$done = 'creator/connect_tiktok.php';
function tt_result(string $kind, string $msg, string $name = ''): void
{
    $_SESSION['tt_result'] = ['kind' => $kind, 'msg' => $msg, 'name' => $name, 'at' => time()];
    redirect('creator/connect_tiktok.php');
}

// Start: POST from the dashboard button.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'disconnect') {
        $acc = fetch_one('SELECT * FROM tiktok_accounts WHERE id = ? AND user_id = ?', [(int)post('account_id'), (int)$user['id']])
            ?: tiktok_account_for_user((int)$user['id']);
        if ($acc) {
            tiktok_http('POST', 'https://open.tiktokapis.com/v2/oauth/revoke/', ['Content-Type: application/x-www-form-urlencoded'],
                http_build_query(['client_key' => tiktok_client_key(), 'client_secret' => tiktok_client_secret(), 'token' => $acc['access_token']]));
            q('DELETE FROM tiktok_accounts WHERE id = ?', [(int)$acc['id']]);
        }
        flash('success', 'TikTok' . (!empty($acc['username']) ? ' @' . $acc['username'] : '') . ' disconnected. The page stays verified, but the views of its clips can no longer be read, so they will not earn until you connect it again.');
        redirect($back);
    }
    if (!tiktok_configured()) {
        flash('warning', 'TikTok connection is not switched on yet. Submit your page with a bio code instead.');
        redirect($back);
    }
    $state = bin2hex(random_bytes(16));
    $_SESSION['tiktok_another'] = post('another') === '1';
    $_SESSION['tiktok_state'] = $state;
    $_SESSION['tiktok_state_at'] = time();
    header('Location: ' . tiktok_auth_url($state));
    exit;
}

// Callback from TikTok.
$state = (string)($_GET['state'] ?? '');
$expected = (string)($_SESSION['tiktok_state'] ?? '');
unset($_SESSION['tiktok_state']);
if ($state === '' || $expected === '' || !hash_equals($expected, $state) || (time() - (int)($_SESSION['tiktok_state_at'] ?? 0)) > 900) {
    tt_result('retry', 'That took too long, so it stopped. Just tap the button again.');
}
if (!empty($_GET['error'])) {
    $errCode = (string)$_GET['error'];
    $why = (string)($_GET['error_description'] ?? $errCode);
    if ($errCode === 'access_denied') {
        tt_result('retry', 'You went back before TikTok finished. Tap the button again and tap Continue (or Authorize) on TikTok.');
    }
    // Anything else is a setup problem on HPL's side (key, redirect address or permissions).
    tiktok_alert_setup_problem($errCode . ': ' . $why);
    tt_result('error', 'TikTok is not answering right now. Our team has been told. Please try again in a little while.');
}
$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    tt_result('retry', 'TikTok did not finish. Just tap the button again.');
}

$tokens = tiktok_exchange_code($code);
if (!is_array($tokens)) {
    error_log('TikTok token exchange failed: ' . $tokens);
    tiktok_alert_setup_problem('Token exchange failed: ' . $tokens);
    tt_result('error', 'TikTok is not answering right now. Our team has been told. Please try again in a little while.');
}
$info = tiktok_user_info((string)$tokens['access_token']);
if (!is_array($info)) {
    error_log('TikTok user info failed: ' . $info);
    tiktok_alert_setup_problem('Profile read failed: ' . $info);
    tt_result('retry', 'TikTok did not share your profile. Tap the button again and leave every box ticked on TikTok.');
}
// "Connect another TikTok" but TikTok handed back an account this creator already has connected:
// say so plainly instead of "TikTok connected" (TikTok uses whichever account is open on the phone).
$wantedAnother = !empty($_SESSION['tiktok_another']);
unset($_SESSION['tiktok_another']);
$already = fetch_one('SELECT id, username FROM tiktok_accounts WHERE user_id = ? AND (open_id = ? OR (username <> "" AND username = ?))',
    [(int)$user['id'], (string)$info['open_id'], strtolower(trim((string)($info['username'] ?? '')))]);
$err = tiktok_save_connection((int)$user['id'], $tokens, $info);
if ($err !== null) {
    tt_result('error', $err);
}

// Pull real views for this creator's TikTok links right away.
try {
    sync_views((int)$user['id']);
    sync_campaign_submissions((int)$user['id']);
} catch (Throwable $e) {
    error_log('TikTok first sync failed: ' . $e->getMessage());
}

$name = !empty($info['username']) ? '@' . $info['username'] : ($info['display_name'] ?? 'your account');
if ($wantedAnother && $already) {
    tt_result('same', 'TikTok connected ' . $name . ' again, and that account was already connected. TikTok always uses the account that is open in the TikTok app on this phone.', $name);
}
if (!tiktok_account_has_videos(['scope' => (string)($tokens['scope'] ?? '')])) {
    // The creator unticked "videos" on TikTok's screen: we can verify the page but cannot read views.
    tt_result('videos', 'You unticked "videos" on TikTok, so we cannot see your views yet. Tap the button again and leave every box ticked.', $name);
}
tt_result('ok', 'Your views now update by themselves. You do not need to do this again.', $name);
