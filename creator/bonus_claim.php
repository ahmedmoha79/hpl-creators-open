<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/bonus.php';
require_once __DIR__ . '/../includes/email_verify.php';
$user = current_user();
if (!$user || ($user['role'] ?? '') !== 'creator') { json_response(['ok' => false, 'error' => 'Sign in required.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['ok' => false, 'error' => 'Method not allowed.'], 405); }
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
}
if ((int)($user['is_demo'] ?? 0) === 1) {
    json_response(['ok' => false, 'error' => 'This is the demo account: it cannot receive money.'], 403);
}
if (($user['status'] ?? '') !== 'active') {
    json_response(['ok' => false, 'error' => 'Your account cannot receive rewards right now. Please contact support.'], 403);
}
if (!email_is_verified($user)) {
    json_response(['ok' => false, 'error' => 'Confirm your email first, then tap Receive.', 'verify' => u('creator/verify_email.php')], 403);
}
$amount = bonus_claim_signup((int)$user['id']);
$balance = (float)creator_stats((int)$user['id'])['balance'];
if ($amount === null) {
    json_response(['ok' => true, 'already' => true, 'balance' => $balance, 'balance_text' => money($balance)]);
}
json_response(['ok' => true, 'amount' => $amount, 'amount_text' => money($amount), 'balance' => $balance, 'balance_text' => money($balance)]);
