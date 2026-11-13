<?php
/**
 * Messenger endpoint (creators and admins).
 * GET  ?thread=<id>&since=<last id>   -> {messages, people}
 * GET  ?list=1[&scope=all]            -> {list, unread}
 * GET  ?people=<search>               -> {people} to start a conversation with
 * POST thread=<id>&message=... (+csrf) -> {ok, message, bot} | {ok:false, error}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/dm.php';

$user = current_user();
if (!$user || !in_array($user['role'], ['creator', 'admin'], true)) {
    json_response(['error' => 'Sign in required.'], 401);
}
$me = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';
touch_presence($me);

function dm_feed_people(int $threadId, int $me): array
{
    $out = [];
    foreach (dm_thread_people($threadId) as $p) {
        if ((int)$p['id'] === $me) {
            continue;
        }
        $out[] = ['id' => (int)$p['id'], 'name' => $p['full_name'], 'active' => (int)$p['is_active'] === 1,
                  'read' => $p['last_read_at'] ? (strtotime((string)$p['last_read_at']) ?: 0) : 0];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $threadId = (int)($_POST['thread'] ?? 0);
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        json_response(['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'], 419);
    }
    if ($threadId <= 0 || !dm_can_view($threadId, $user)) {
        json_response(['ok' => false, 'error' => 'Conversation not found.'], 404);
    }
    $text = (string)($_POST['message'] ?? '');
    $err = dm_send($threadId, $me, $text);
    if ($err) {
        json_response(['ok' => false, 'error' => $err]);
    }
    $mine = fetch_one(
        'SELECT m.*, u.full_name, u.role, u.avatar_url FROM dm_messages m JOIN users u ON u.id = m.sender_id
         WHERE m.thread_id = ? AND m.sender_id = ? AND m.is_bot = 0 ORDER BY m.id DESC LIMIT 1',
        [$threadId, $me]
    );
    dm_notify_others($threadId, $me, (string)$user['full_name'], $text, (string)$user['role']);

    // Support chat: answer common questions straight away, and bring in the team when needed.
    $bot = null;
    $teamActive = (int)fetch_val(
        'SELECT COUNT(*) FROM dm_messages m JOIN users u ON u.id = m.sender_id
         WHERE m.thread_id = ? AND m.is_bot = 0 AND u.role = "admin" AND m.created_at >= (NOW() - INTERVAL 1 DAY)',
        [$threadId], 0
    ) > 0; // a person from the team is already talking here: the bot stays quiet
    if (!$isAdmin && !$teamActive && (int)fetch_val('SELECT is_support FROM dm_threads WHERE id = ?', [$threadId], 0) === 1) {
        $lower = mb_strtolower($text);
        $wantHuman = preg_match('/real person|talk to a human|\bhuman\b|\bagent\b|someone from the team|actual person|representative/', $lower) === 1;
        $match = $wantHuman ? null : (function_exists('support_match') ? support_match($text) : null);
        $aiReply = null;
        if (!$match && !$wantHuman) {
            // No fixed answer fits: let the Help assistant try (includes/help_ai.php). Null = hand over to the team.
            try { require_once __DIR__ . '/../includes/help_ai.php'; $aiReply = help_ai_answer($user, $text, $threadId); } catch (Throwable $e) { $aiReply = null; }
        }
        if ($match) {
            $reply = $match['answer'];
        } elseif ($aiReply !== null) {
            $reply = $aiReply;
        } else {
            $reply = $wantHuman
                ? 'Of course. The HPL team has been told and will reply right here.'
                : 'I could not find an automatic answer for that. The HPL team has been told and will reply right here.';
            if (!dm_team_viewing($threadId)) { // someone from the team has this chat open: they can already see it
                notif_admins('message', $user['full_name'] . ' needs help in Messages', mb_substr(trim($text), 0, 120), 'admin/messages.php?thread=' . $threadId);
            }
        }
        $botRow = dm_send_bot($threadId, $reply);
        if ($botRow) {
            $botRow['avatar_url'] = '';
            $bot = dm_message_json($botRow, $me);
        }
    }
    json_response(['ok' => true, 'message' => $mine ? dm_message_json($mine, $me) : null, 'bot' => $bot]);
}

if (isset($_GET['people'])) {
    json_response(['people' => dm_people($me, (string)get('people'))]);
}

if (get('list')) {
    $scope = ($isAdmin && get('scope') === 'all') ? 'all' : 'mine';
    // Keep the open conversation in the list even while it has no messages yet (like the page does).
    $keep = (int)get('keep');
    if ($keep > 0 && !dm_can_view($keep, $user)) {
        $keep = 0;
    }
    json_response(['list' => dm_list($me, $scope, $keep, $isAdmin), 'unread' => dm_unread_total($me)]);
}

$threadId = (int)get('thread');
if ($threadId <= 0 || !dm_can_view($threadId, $user)) {
    json_response(['error' => 'Conversation not found.'], 404);
}
dm_mark_read($threadId, $me);
json_response([
    'messages' => dm_thread_messages($threadId, $me, max(0, (int)get('since'))),
    'people'   => dm_feed_people($threadId, $me),
]);
