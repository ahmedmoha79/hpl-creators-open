<?php
/**
 * Announcement reach: one view per creator per post (like "seen by").
 * Admin views are not counted, so the number shows how many creators the post reached.
 */
function ann_mark_viewed(array $postIds, array $user): array
{
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0 || ($user['role'] ?? '') === 'admin') {
        return [];
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $postIds), function ($i) { return $i > 0; })));
    $ids = array_slice($ids, 0, 50);
    $added = [];
    foreach ($ids as $id) {
        // Only real top-level posts; INSERT IGNORE + rowCount makes it count once even with parallel requests.
        $st = q('INSERT IGNORE INTO ann_views (post_id, user_id) SELECT id, ? FROM ann_posts WHERE id = ? AND parent_id IS NULL', [$uid, $id]);
        if ($st->rowCount() > 0) {
            q('UPDATE ann_posts SET view_count = view_count + 1 WHERE id = ?', [$id]);
            $added[] = $id;
        }
    }
    return $added;
}
