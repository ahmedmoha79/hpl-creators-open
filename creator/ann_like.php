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
$postId = (int)($_POST['post_id'] ?? 0);
if ($postId <= 0) { json_response(['ok' => false, 'error' => 'Bad post.'], 400); }

$existing = fetch_one('SELECT id FROM ann_likes WHERE post_id = ? AND user_id = ?', [$postId, (int)$user['id']]);
if ($existing) {
    q('DELETE FROM ann_likes WHERE post_id = ? AND user_id = ?', [$postId, (int)$user['id']]);
    q('UPDATE ann_posts SET like_count = GREATEST(like_count - 1, 0) WHERE id = ?', [$postId]);
    $liked = false;
} else {
    q('INSERT INTO ann_likes (post_id, user_id) VALUES (?, ?)', [$postId, (int)$user['id']]);
    q('UPDATE ann_posts SET like_count = like_count + 1 WHERE id = ?', [$postId]);
    $liked = true;
    ann_mark_viewed([$postId], $user); // liking a post means it was seen
}
$row = fetch_one('SELECT like_count, view_count FROM ann_posts WHERE id = ?', [$postId]);
json_response(['ok' => true, 'liked' => $liked, 'count' => (int)($row['like_count'] ?? 0), 'views' => (int)($row['view_count'] ?? 0)]);
