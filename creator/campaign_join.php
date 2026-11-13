<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ready_clips.php';
$user = require_creator();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['ok' => false, 'error' => 'Method not allowed.'], 405); }
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false, 'error' => 'Session expired.'], 419);
}
// Passing the test is enough to join and post ready clips; raw footage still needs full approval.
if (!creator_can_post_ready($user)) {
    json_response(['ok' => false, 'error' => 'First pass the short HPL test. Then you can join campaigns.', 'redirect' => u(onboarding_url($user))], 403);
}
$campaignId = (int)($_POST['campaign_id'] ?? 0);
if ($campaignId <= 0) { json_response(['ok' => false, 'error' => 'Bad campaign.'], 400); }
$c = fetch_one('SELECT id, status, camp_status, total_budget, paid_amount FROM campaigns WHERE id = ?', [$campaignId]);
if (!$c) { json_response(['ok' => false, 'error' => 'Campaign not found.'], 404); }
$remaining = (float)$c['total_budget'] - (float)$c['paid_amount'];
if (($c['status'] ?? 'active') !== 'active' || $c['camp_status'] !== 'accepting' || $remaining <= 0) {
    json_response(['ok' => false, 'error' => 'Campaign not accepting clips.'], 400);
}
$exists = fetch_one('SELECT id FROM campaign_joins WHERE campaign_id = ? AND user_id = ?', [$campaignId, (int)$user['id']]);
if (!$exists) {
    q('INSERT INTO campaign_joins (campaign_id, user_id) VALUES (?, ?)', [$campaignId, (int)$user['id']]);
}
json_response(['ok' => true, 'joined' => true]);
