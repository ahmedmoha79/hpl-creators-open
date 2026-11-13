<?php
/**
 * Community chat API (creators + admin).
 * Every call takes ch=<room key> (see chat_rooms() in includes/community.php); default "general".
 *   GET  ?since=<id>        new messages after <id>, who is online, recently removed ids, newest id per room
 *   GET  ?before=<id>       up to 40 older messages (scrolling back)
 *   POST message=...        send (returns the saved message)
 *   POST action=delete&id=  remove a message (admins, or the author within 10 minutes)
 *   POST action=typing      "is typing..." for the others (lasts a few seconds)
 *   POST message=..&sticker=..&reply_to=..   stickers and replies (2026-11-08)
 * Reactions on chat messages go through community_api.php (action=react, kind=chat).
 */
// Fast lane (2026-11-11, launch): a poll that already has the newest chat version gets its short
// "nothing new" answer from the shared snapshot file, without opening a database connection.
// The full path still runs at least once a minute per person (who is online) and on any change.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && (int)($_GET['since'] ?? 0) > 0 && isset($_GET['v'])) {
    require_once __DIR__ . '/../includes/fastlane.php';
    [$flUid, $flRole] = fl_uid();
    $flDir = dirname(__DIR__) . '/storage/cache';
    $flVer = trim((string)@file_get_contents($flDir . '/chat.ver'));
    $flSnap = json_decode((string)@file_get_contents($flDir . '/chat_shared.json'), true);
    if ($flUid > 0 && in_array($flRole, ['creator', 'admin'], true) && $flVer !== '' && $flVer !== '0'
        && (string)$_GET['v'] === $flVer && is_array($flSnap) && ($flSnap['ver'] ?? '') === $flVer
        && microtime(true) - (float)($flSnap['built'] ?? 0) < 8 && fl_seen('p' . $flUid, 50)) {
        $flRoom = (string)($_GET['ch'] ?? 'general');
        $flTyping = [];
        foreach (($flSnap['typing'] ?? []) as $t) {
            if ((int)$t['id'] !== $flUid && ($t['ch'] ?? 'general') === $flRoom && count($flTyping) < 4) { $flTyping[] = $t['name']; }
        }
        $flOut = ['ok' => true, 'v' => $flVer, 'a' => $flSnap['active_at'] ?? 0, 'count' => (int)($flSnap['online'] ?? count($flSnap['active'] ?? [])), 'typing' => $flTyping, 'now' => time(), 'same' => true];
        if ((string)($_GET['a'] ?? '') !== (string)($flSnap['active_at'] ?? 0)) {
            $flOut['active'] = array_map(fn($a) => $a + ['you' => (int)$a['id'] === $flUid], $flSnap['active'] ?? []);
        }
        fl_json($flOut);
    }
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/community.php';
require_once __DIR__ . '/../includes/chat_cache.php';

/** Chat messages as JSON with their reply preview, sticker and reactions. */
function chat_out(array $rows, int $uid): array
{
    $ids = array_map(function ($m) { return (int)$m['id']; }, $rows);
    $extra = community_chat_extras($ids);
    $rx = community_reaction_map('chat', $ids, $uid);
    $named = chat_mentions_of($rows);
    $out = [];
    foreach ($rows as $m) {
        $j = community_message_json($m, $uid);
        $j['mentions'] = $named[$j['id']] ?? [];
        $x = $extra[$j['id']] ?? ['sticker' => null, 'reply' => null];
        $j['sticker'] = $x['sticker'];
        $j['reply'] = $x['reply'];
        $j['reacts'] = $rx[$j['id']] ?? [];
        $out[] = $j;
    }
    return $out;
}

$user = current_user();
if (!$user || !in_array($user['role'], ['creator', 'admin'], true)) {
    json_response(['error' => 'Sign in required.'], 401);
}
$uid = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && session_status() === PHP_SESSION_ACTIVE) {
    session_write_close(); // reading the chat never changes the session: do not hold its lock
}
touch_presence($uid);
$room = chat_room_key((string)($_REQUEST['ch'] ?? 'general'));
$roomDef = chat_rooms()[$room];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_response(['ok' => false, 'error' => 'Session expired. Refresh the page and try again.'], 419);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    if (($_POST['action'] ?? '') === 'typing') {
        community_typing_touch($uid, $room);
        json_response(['ok' => true]);
    }
    if (($_POST['action'] ?? '') === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $m = $id > 0 ? fetch_one('SELECT id, user_id, created_at FROM community_messages WHERE id = ?', [$id]) : null;
        if (!$m) {
            json_response(['ok' => false, 'error' => 'Message not found.'], 404);
        }
        $own = (int)$m['user_id'] === $uid && (time() - strtotime((string)$m['created_at'])) < 600;
        if (!$isAdmin && !$own) {
            json_response(['ok' => false, 'error' => 'You can only remove your own messages within 10 minutes.'], 403);
        }
        if (community_has_soft_delete()) {
            q('UPDATE community_messages SET deleted_at = NOW() WHERE id = ?', [$id]);
        } else {
            q('DELETE FROM community_messages WHERE id = ?', [$id]);
        }
        chat_bump();
        json_response(['ok' => true, 'id' => $id]);
    }

    if ($roomDef['write'] === 'team' && !$isAdmin) {
        json_response(['ok' => false, 'error' => 'Only HPL writes in ' . $roomDef['name'] . '. You can react here, or write in General chat.'], 403);
    }
    $newId = 0;
    $sticker = (string)($_POST['sticker'] ?? '');
    $sticker = isset(community_stickers()[$sticker]) ? $sticker : '';
    if ($sticker !== '' && !sticker_allowed($user, $sticker)) {
        json_response(['ok' => false, 'error' => 'That sticker pack is locked for you.']);
    }
    $replyTo = (int)($_POST['reply_to'] ?? 0);
    $text = (string)($_POST['message'] ?? '');
    if ($sticker !== '' && trim($text) === '') {
        // A sticker on its own (same burst limit as text).
        $recent = (int)fetch_val('SELECT COUNT(*) FROM community_messages WHERE user_id = ? AND created_at > NOW() - INTERVAL 20 SECOND', [$uid], 0);
        if ($recent >= 6) {
            json_response(['ok' => false, 'error' => 'You are sending messages very fast. Wait a few seconds.']);
        }
        if (chat_has_rooms()) {
            q('INSERT INTO community_messages (user_id, message, channel) VALUES (?, "", ?)', [$uid, $room]);
        } else {
            q('INSERT INTO community_messages (user_id, message) VALUES (?, "")', [$uid]);
        }
        $newId = (int)db()->lastInsertId();
    } else {
        $err = community_post($uid, $text, $newId, $room);
        if ($err) {
            json_response(['ok' => false, 'error' => $err]);
        }
    }
    if ($newId > 0 && ($sticker !== '' || $replyTo > 0) && community_ready()) {
        $replyOk = $replyTo > 0 && (int)fetch_val('SELECT COUNT(*) FROM community_messages WHERE id = ? AND id < ?', [$replyTo, $newId], 0) > 0;
        q('UPDATE community_messages SET sticker = ?, reply_to = ? WHERE id = ?', [$sticker ?: null, $replyOk ? $replyTo : null, $newId]);
        if ($replyOk) {
            $to = (int)fetch_val('SELECT user_id FROM community_messages WHERE id = ?', [$replyTo], 0);
            if ($to > 0 && $to !== $uid) {
                notif_add($to, 'community', community_first_name($user) . ' replied to you in the live chat', mb_substr(trim($text) ?: 'sent a sticker', 0, 120), 'creator/community.php?tab=chat&room=' . $room);
            }
        }
    }
    // @mentions: saved with the message, and each person named gets a notification.
    if ($newId > 0 && chat_has_rooms() && !empty($_POST['mentions'])) {
        $named = chat_mentions($text, explode(',', (string)$_POST['mentions']));
        unset($named[$uid]);
        if ($named) {
            q('UPDATE community_messages SET mentions = ? WHERE id = ?', [implode(',', array_keys($named)), $newId]);
            foreach (array_keys($named) as $to) {
                notif_add((int)$to, 'community', community_first_name($user) . ' mentioned you in ' . $roomDef['name'], mb_substr(trim($text), 0, 120), 'creator/community.php?tab=chat&room=' . $room);
            }
        }
    }
    // A team message in #announcements reaches everyone's bell (at most one alert every 10 minutes).
    if ($newId > 0 && $isAdmin && $room === 'announcements' && trim($text) !== '' && (int)setting('chat_announce_at', '0') < time() - 600) {
        try {
            set_setting('chat_announce_at', (string)time());
            q('INSERT INTO notifications (user_id, type, title, body, url)
               SELECT id, "community", "New announcement from HPL", ?, ? FROM users WHERE role = "creator" AND status = "active"',
                [mb_substr(trim($text), 0, 200), 'creator/community.php?tab=chat&room=announcements']);
            // Phones too: everyone who turned notifications on (sent a few people at a time, after the page).
            q('UPDATE users SET push_pending_at = NOW() WHERE push_on = 1 AND role = "creator" AND status = "active"');
            require_once __DIR__ . '/../includes/push.php';
            push_flush_later();
        } catch (Throwable $e) {
        }
    }
    try { q('DELETE FROM community_typing WHERE user_id = ?', [$uid]); } catch (Throwable $e) {}
    $row = fetch_one(
        'SELECT m.*, u.full_name, u.role, u.avatar_url
           FROM community_messages m JOIN users u ON u.id = m.user_id WHERE m.id = ?',
        [$newId]
    );
    $forwarded = false;
    if (!$isAdmin && $newId > 0) {
        require_once __DIR__ . '/../includes/dm.php';
        $forwarded = dm_forward_from_community($uid, $newId, (string)($_POST['message'] ?? ''));
    }
    chat_bump();
    json_response(['ok' => true, 'message' => $row ? (chat_out([$row], $uid)[0] ?? null) : null, 'forwarded' => $forwarded]);
}

if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
header('Cache-Control: no-store');

$before = (int)get('before');
if ($before > 0) {
    $rows = community_messages(40, 0, $before, $room);
    $out = chat_out($rows, $uid);
    json_response(['ok' => true, 'messages' => $out, 'more' => count($rows) === 40]);
}

$since = (int)get('since');
$cacheOk = chat_cache_ok(); // also creates the version file the first time
$ver = chat_version();
$shared = chat_shared();
$typing = [];
foreach ($shared['typing'] as $t) {
    if ($t['id'] !== $uid && ($t['ch'] ?? 'general') === $room && count($typing) < 4) { $typing[] = $t['name']; }
}
// The people list only goes out when it changed since this browser last got it.
$sendActive = (string)get('a') !== (string)$shared['active_at'];
$active = [];
if ($sendActive) {
    foreach ($shared['active'] as $a) {
        $active[] = $a + ['you' => $a['id'] === $uid];
    }
}
$base = ['ok' => true, 'v' => $ver, 'a' => $shared['active_at'], 'count' => (int)($shared['online'] ?? count($shared['active'])), 'typing' => $typing, 'now' => time()];
if ($sendActive) {
    $base['active'] = $active;
}
// Nothing changed since this browser's last answer: a short reply, no message or reaction work.
if ($since > 0 && $cacheOk && $ver !== '0' && (string)get('v') === $ver) {
    json_response($base + ['same' => true]);
}
$out = chat_out(community_messages($since > 0 ? 100 : 60, $since, 0, $room), $uid);
json_response($base + ['ch' => $room, 'rooms' => (object)($shared['last'] ?? []), 'prev' => (object)($shared['prev'] ?? []), 'at' => (object)($shared['at'] ?? []), 'more' => $since <= 0 && count($out) === 60, 'messages' => $out, 'removed' => $shared['removed'], 'rx' => (object)chat_rx_for($shared, $uid)]);
