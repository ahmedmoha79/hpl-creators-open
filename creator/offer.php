<?php
/** An offer was seen, closed or tapped (includes/offers.php). POST id, clicked=0|1. */
require_once __DIR__ . '/../config.php';
$user = current_user();
if (!$user || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['ok' => false], 400);
}
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false], 419);
}
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
$id = (int)($_POST['id'] ?? 0);
if ($id > 0 && fetch_val('SELECT 1 FROM offers WHERE id = ?', [$id], 0)) {
    q('INSERT INTO offer_views (offer_id, user_id, clicked) VALUES (?,?,?) ON DUPLICATE KEY UPDATE clicked = GREATEST(clicked, VALUES(clicked))',
        [$id, (int)$user['id'], ($_POST['clicked'] ?? '') === '1' ? 1 : 0]);
}
json_response(['ok' => true]);
