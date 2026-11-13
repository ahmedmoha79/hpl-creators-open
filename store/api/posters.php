<?php
/**
 * Fills in missing video covers (TikTok/YouTube links) a few at a time. Safe to call by anyone:
 * it only downloads covers for videos the admin already added, and is rate limited.

 */
require dirname(__DIR__) . '/inc/bootstrap.php';
require dirname(__DIR__) . '/inc/store.php';
if (!rate_ok('posters', 30, 3600)) json_out(['ok' => false, 'error' => 'slow'], 429);
$before = (int)val('SELECT COUNT(*) FROM shop_videos WHERE poster IS NULL AND file IS NULL AND embed_url IS NOT NULL', [], 0);
if (get('retry') === '1') q('UPDATE shop_videos SET poster_tried_at = NULL WHERE poster IS NULL');
videos_fill_posters(min(8, max(1, (int)get('n', '4'))));
$after = (int)val('SELECT COUNT(*) FROM shop_videos WHERE poster IS NULL AND file IS NULL AND embed_url IS NOT NULL', [], 0);
json_out(['ok' => true, 'missing_before' => $before, 'missing_after' => $after]);
