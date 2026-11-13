<?php
/**
 * Creator actions on their social pages (all POST, all redirect back to the dashboard).
 *   add     platform + url       add a page (or re-open one that was turned down)
 *   check   id                   read the bio now; verified on the spot when an official source confirms the code
 *   cancel  id                   withdraw a page that is still waiting
 *   retry   id                   send a turned-down page again (same code)
 *   remove  id                   clear a turned-down page
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/social_pages.php';
require_once __DIR__ . '/../includes/tiktok.php';
$user = require_creator();
$back = 'creator/profile.php#socialPage';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect($back);
}
csrf_check();
$uid = (int)$user['id'];
$action = post('action') ?: 'add';
$id = (int)post('id');

if ($action === 'cancel') {
    $r = sp_cancel($uid, $id);
    sp_say($r['type'], $r['msg']);
    redirect($back);
}
if ($action === 'remove') {
    $r = sp_dismiss($uid, $id);
    sp_say($r['type'], $r['msg']);
    redirect($back);
}
// TikTok pages are verified by connecting TikTok, never by a code in the bio.
if (in_array($action, ['add', 'retry', 'check'], true) && tiktok_configured()) {
    $pf = $action === 'add' ? (string)post('platform')
        : (string)fetch_val('SELECT platform FROM social_pages WHERE id = ? AND user_id = ?', [$id, $uid]);
    if ($pf === 'tiktok') {
        sp_say('info', 'TikTok does not need a code. Tap Connect TikTok and sign in: TikTok confirms the account is yours.');
        redirect('creator/connect_tiktok.php');
    }
}
if ($action === 'retry') {
    $r = sp_retry($user, $id);
    sp_say($r['type'], $r['msg']);
    redirect($back);
}

if ($action === 'check') {
    $pg = fetch_one('SELECT * FROM social_pages WHERE id = ? AND user_id = ?', [$id, $uid]);
    if (!$pg) {
        sp_say('info', 'That page is no longer on your account.');
        redirect($back);
    }
    if ($pg['status'] !== 'pending') {
        sp_say('info', sp_label($pg) . ' is already ' . ($pg['status'] === 'approved' ? 'verified.' : 'reviewed.'));
        redirect($back);
    }
    $key = 'bio_check_' . (int)$pg['id'];
    if (!empty($_SESSION[$key]) && time() - (int)$_SESSION[$key] < 20) {
        sp_say('info', 'Give it a few seconds, then check again.');
        redirect($back);
    }
    $_SESSION[$key] = time();
    $code = hpl_social_code($pg);
    $r = hpl_bio_check($pg, $code);
    if ($r['found'] === true && $r['trusted']) {
        $a = sp_approve((int)$pg['id'], null, 'Verified automatically: code found in the bio (' . $r['how'] . ')');
        sp_say($a['ok'] ? 'success' : 'warning', $a['ok'] ? 'Verified! We found ' . $code . ' in your bio. You can remove it now and start submitting clips.' : $a['msg']);
    } elseif ($r['found'] === true) {
        sp_say('success', 'We can see ' . $code . ' on your page. The HPL team will confirm it shortly.');
    } elseif ($r['found'] === false) {
        sp_say('warning', 'We read your bio but ' . $code . ' is not there yet. Save it in your bio exactly as shown, wait a minute, then check again.');
    } else {
        sp_say('info', ($r['tip'] !== '' ? $r['tip'] : platform_label((string)$pg['platform']) . ' does not let us read profiles automatically.') . ' Keep ' . $code . ' in your bio and the HPL team will check it by hand, usually within 24 hours.');
    }
    redirect($back);
}

if (!platform_is_live((string)post('platform'))) {
    sp_say('info', platform_label((string)post('platform')) . ' is coming soon. For now, HPL Creators is for TikTok.');
    redirect($back);
}
$r = sp_submit($user, (string)post('platform'), (string)post('url'));
sp_say($r['type'], $r['msg']);
redirect($back);
