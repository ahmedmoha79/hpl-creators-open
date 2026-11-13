<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/campaign_help.php';
$user = require_creator();

$id = (int)get('id');
$item = fetch_one('SELECT * FROM footage WHERE id = ? AND is_active = 1', [$id]);
if (!$item) {
    flash('error', 'That footage could not be found.');
    redirect('creator/index.php?tab=campaigns');
}
if (!footage_user_can_download($user, $item)) {
    $cid = (int)($item['campaign_id'] ?? 0);
    if ((int)($user['qualified'] ?? 0) === 1 && $cid > 0) {
        flash('warning', 'Join this campaign first. Then you can download its footage.');
        redirect('creator/campaign.php?id=' . $cid);
    }
    flash('warning', 'First get approved: pass the short test and send us one video you edited. Then you can download footage.');
    redirect(onboarding_url($user));
}

$driveUrl = trim($item['drive_url'] ?? '');
if ($driveUrl !== '' && filter_var($driveUrl, FILTER_VALIDATE_URL)) {
    q('UPDATE footage SET downloads = downloads + 1 WHERE id = ?', [$id]);
    q('INSERT INTO footage_downloads (user_id, footage_id) VALUES (?, ?)', [(int)$user['id'], $id]);
    redirect($driveUrl);
}

if (empty($item['file_name'])) {
    flash('error', 'Download link is not available for that footage yet. Please contact support.');
    redirect('creator/library.php');
}

$path = UPLOAD_PATH . '/footage/' . basename($item['file_name']);
if (!is_file($path)) {
    flash('error', 'The file for that footage is missing. Please contact support.');
    redirect('creator/library.php');
}

q('UPDATE footage SET downloads = downloads + 1 WHERE id = ?', [$id]);
q('INSERT INTO footage_downloads (user_id, footage_id) VALUES (?, ?)', [(int)$user['id'], $id]);

$safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $item['title']) . '.' . pathinfo($path, PATHINFO_EXTENSION);

while (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
readfile($path);
exit;
