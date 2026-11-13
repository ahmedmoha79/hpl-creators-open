<?php
/**
 * Community feed API (creators + HPL team). All answers are JSON.
 *   GET  action=feed        channel, before          a page of posts
 *   GET  action=post        id                       one post with its comments
 *   GET  action=comments    id                       comments of a post
 *   GET  action=poll        since, channel           count of newer posts + who is online
 *   GET  action=leaderboard days (7|30|0)
 *   GET  action=members     q, filter (all|online|team|new)
 *   POST action=create      channel, text, sticker, photo (file)
 *   POST action=comment     id, text, sticker, parent
 *   POST action=react       kind (post|comment|chat), id, emoji
 *   POST action=remove      kind (post|comment), id
 *   POST action=pin         id                       HPL team only, toggles
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/community.php';

$user = current_user();
if (!$user || !in_array($user['role'], ['creator', 'admin'], true) || ($user['status'] ?? 'active') !== 'active') {
    json_response(['ok' => false, 'error' => 'Sign in required.'], 401);
}
if (!community_ready()) {
    hpl_migrate();
    if (!community_ready()) {
        json_response(['ok' => false, 'error' => 'The community is being set up. Try again in a minute.'], 503);
    }
}
$uid = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';
touch_presence($uid);
$action = (string)($_REQUEST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_response(['ok' => false, 'error' => 'Session expired. Refresh the page and try again.'], 419);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $id = (int)($_POST['id'] ?? 0);
    $sticker = trim((string)($_POST['sticker'] ?? '')) ?: null;
    switch ($action) {
        case 'create':
            $r = community_create_post($user, (string)($_POST['channel'] ?? 'general'), (string)($_POST['text'] ?? ''), $sticker, $_FILES['photo'] ?? null);
            if (!$r['ok']) {
                json_response($r);
            }
            $feed = community_feed($user, '', 0, 1, $r['id']);
            json_response(['ok' => true, 'post' => $feed['posts'][0] ?? null]);
        case 'comment':
            $r = community_add_comment($user, $id, (string)($_POST['text'] ?? ''), $sticker, (int)($_POST['parent'] ?? 0));
            if (!$r['ok']) {
                json_response($r);
            }
            $count = (int)fetch_val('SELECT comments FROM community_posts WHERE id = ?', [$id], 0);
            json_response(['ok' => true, 'comments' => community_comments($id, $user), 'count' => $count]);
        case 'react':
            json_response(community_toggle_reaction($user, (string)($_POST['kind'] ?? ''), $id, (string)($_POST['emoji'] ?? '')));
        case 'remove':
            json_response(community_remove($user, (string)($_POST['kind'] ?? 'post') === 'comment' ? 'comment' : 'post', $id));
        case 'buy_pack':
            $err = sticker_buy_pack($user, (string)($_POST['pack'] ?? ''));
            if ($err !== null) {
                json_response(['ok' => false, 'error' => $err]);
            }
            require_once __DIR__ . '/../includes/earnings.php';
            json_response(['ok' => true, 'packs' => sticker_packs_for($user), 'balance' => money(creator_stats($uid)['balance'])]);
        case 'pin':
            if (!$isAdmin) {
                json_response(['ok' => false, 'error' => 'Only the HPL team can pin posts.'], 403);
            }
            q('UPDATE community_posts SET pinned = 1 - pinned WHERE id = ? AND deleted_at IS NULL', [$id]);
            json_response(['ok' => true, 'pinned' => (int)fetch_val('SELECT pinned FROM community_posts WHERE id = ?', [$id], 0) === 1]);
    }
    json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Cache-Control: no-store');

switch ($action) {
    case 'feed':
        $f = community_feed($user, (string)get('channel'), (int)get('before'));
        json_response(['ok' => true] + $f);
    case 'post':
        $f = community_feed($user, '', 0, 1, (int)get('id'));
        if (!$f['posts']) {
            json_response(['ok' => false, 'error' => 'This post was removed.'], 404);
        }
        json_response(['ok' => true, 'post' => $f['posts'][0], 'comments' => community_comments((int)get('id'), $user)]);
    case 'comments':
        json_response(['ok' => true, 'comments' => community_comments((int)get('id'), $user)]);
    case 'poll':
        $since = (int)get('since');
        $channel = (string)get('channel');
        $args = [$since];
        $ch = '';
        if ($channel !== '' && isset(community_channels()[$channel])) {
            $ch = ' AND channel = ?';
            $args[] = $channel;
        }
        $newer = (int)fetch_val('SELECT COUNT(*) FROM community_posts WHERE deleted_at IS NULL AND id > ? AND user_id <> ' . $uid . $ch, $args, 0);
        $online = [];
        foreach (community_people(array_column(active_users(), 'id')) as $p) {
            $online[] = $p;
        }
        json_response(['ok' => true, 'newer' => $newer, 'online' => $online, 'now' => time()]);
    case 'leaderboard':
        $days = (int)get('days');
        $days = in_array($days, [7, 30], true) ? $days : 0;
        json_response(['ok' => true, 'rows' => community_leaderboard($days, 25), 'me' => community_points_for([$uid], $days)[$uid] ?? 0]);
    case 'members':
        $filter = (string)get('filter');
        json_response(['ok' => true, 'rows' => community_members(trim((string)get('q')), in_array($filter, ['online', 'team', 'new'], true) ? $filter : 'all')]);
}
json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
