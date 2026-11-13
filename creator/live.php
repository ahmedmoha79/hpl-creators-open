<?php
/**
 * Live earnings for the creator dashboard (2026-10-27).
 * GET                 -> JSON snapshot of balance, today, accruing and clips.
 * POST action=refresh -> re-read this creator's views now (clips read in the last
 *                        5 minutes are skipped), then return the fresh snapshot.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';

$user = current_user();
if (!$user || $user['role'] !== 'creator') {
    json_response(['error' => 'Sign in required.'], 401);
}
header('Cache-Control: no-store');
$uid = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_response(['ok' => false, 'error' => 'Session expired. Refresh the page and try again.'], 419);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    @set_time_limit(60);
    require_once __DIR__ . '/../includes/view_fetcher.php';
    $before = creator_live($uid);
    $a = sync_views($uid, 300);
    $b = sync_campaign_submissions($uid, 300);
    $data = creator_live($uid);
    $data['ok'] = true;
    $data['refreshed'] = (int)$a['processed'] + (int)$b['processed'];
    $data['credited'] = round((float)$a['credited'] + (float)$b['credited'], 2);
    $data['views_gained'] = max(0, $data['views'] - $before['views']);
    json_response($data);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$data = creator_live($uid);
$data['ok'] = true;
json_response($data);
