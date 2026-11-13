<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ann.php';
$user = current_user();
if (!$user) { json_response(['ok' => false, 'error' => 'Sign in required.'], 401); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['ok' => false, 'error' => 'Method not allowed.'], 405); }
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false, 'error' => 'Session expired.'], 419);
}
$ids = array_map('intval', explode(',', (string)($_POST['ids'] ?? '')));
$added = ann_mark_viewed($ids, $user);
$counts = [];
if ($added) {
    $ph = implode(',', array_fill(0, count($added), '?'));
    foreach (fetch_all('SELECT id, view_count FROM ann_posts WHERE id IN (' . $ph . ')', $added) as $r) {
        $counts[(int)$r['id']] = (int)$r['view_count'];
    }
}
json_response(['ok' => true, 'counts' => $counts]);
