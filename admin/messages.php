<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/dm.php';
$user = require_admin();
$me = (int)$user['id'];
$pageTitle = 'Messages';
$scope = get('scope') === 'all' ? 'all' : 'mine';

// Message a creator directly: ?user=<id> opens (or creates) their support conversation.
if (($with = (int)get('user')) > 0) {
    $target = fetch_one('SELECT id, role FROM users WHERE id = ? AND status = "active"', [$with]);
    if (!$target || $target['role'] !== 'creator') {
        flash('error', 'That creator could not be found.');
        redirect('admin/messages.php');
    }
    $tid = support_thread_for($with);
    if ($tid > 0 && !fetch_one('SELECT 1 FROM dm_participants WHERE thread_id = ? AND user_id = ?', [$tid, $me])) {
        // Any admin can answer in a creator's support conversation.
        q('INSERT IGNORE INTO dm_participants (thread_id, user_id) VALUES (?, ?)', [$tid, $me]);
    }
    redirect('admin/messages.php' . ($tid ? '?thread=' . $tid : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $tid = (int)post('thread');
    $back = 'admin/messages.php?' . ($scope === 'all' ? 'scope=all&' : '') . 'thread=' . $tid;
    if ($action === 'delete') {
        // Moderation: remove a message that breaks the rules.
        $mid = (int)post('id');
        $ok = $mid > 0 && fetch_one('SELECT id FROM dm_messages WHERE id = ? AND thread_id = ?', [$mid, $tid]);
        if ($ok) {
            q('DELETE FROM dm_messages WHERE id = ?', [$mid]);
        }
        if (post('ajax')) {
            json_response(['ok' => (bool)$ok]);
        }
        flash($ok ? 'success' : 'error', $ok ? 'Message removed.' : 'That message no longer exists.');
        redirect($back);
    }
    if ($action === 'reply' && $tid > 0) { // no-JavaScript fallback
        $err = dm_send($tid, $me, (string)($_POST['message'] ?? ''));
        if ($err) {
            flash('error', $err);
        } else {
            dm_notify_others($tid, $me, (string)$user['full_name'], (string)$_POST['message'], 'admin');
        }
        redirect($back);
    }
    redirect('admin/messages.php');
}

$thread = null;
$messages = [];
$threadId = (int)get('thread');
if ($threadId > 0) {
    $exists = fetch_one('SELECT id, is_support FROM dm_threads WHERE id = ?', [$threadId]);
    if (!$exists) {
        flash('error', 'Conversation not found.');
        redirect('admin/messages.php');
    }
    $support = (int)$exists['is_support'] === 1;
    $people = dm_thread_people($threadId);
    $isMember = in_array($me, array_map('intval', array_column($people, 'id')), true);
    if (!$isMember && $support) {
        // Support conversations belong to the whole team: join so you can answer.
        q('INSERT IGNORE INTO dm_participants (thread_id, user_id) VALUES (?, ?)', [$threadId, $me]);
        $people = dm_thread_people($threadId);
        $isMember = true;
    }
    if ($isMember) {
        dm_mark_read($threadId, $me);
    }
    $others = array_values(array_filter($people, function ($p) use ($me) { return (int)$p['id'] !== $me; }));
    $creators = array_values(array_filter($others, function ($p) { return $p['role'] !== 'admin'; }));
    $thread = [
        'id'      => $threadId,
        'title'   => $isMember ? ($creators[0]['full_name'] ?? ($others[0]['full_name'] ?? 'Conversation'))
                               : implode(' & ', array_column($people, 'full_name')),
        'people'  => $creators ? array_merge($creators, array_filter($people, function ($p) { return $p['role'] === 'admin'; })) : $people,
        'member'  => $isMember,
        'support' => $support,
    ];
    $messages = dm_thread_messages($threadId, $me);
}

$list = dm_list($me, $scope, $threadId, true);
if ($scope === 'mine') {
    // In the team inbox, name each conversation after the creator, not the admin persona.
    foreach ($list as &$c) {
        $creator = fetch_one(
            'SELECT u.id, u.full_name, u.avatar_url, (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active
             FROM dm_participants p JOIN users u ON u.id = p.user_id WHERE p.thread_id = ? AND u.role = "creator" ORDER BY u.id LIMIT 1',
            [presence_window_minutes(), $c['id']]
        );
        if ($creator) {
            $c['title'] = $creator['full_name'];
            $c['lead'] = ['id' => (int)$creator['id'], 'name' => $creator['full_name'], 'role' => 'creator',
                          'avatar' => (string)($creator['avatar_url'] ?? ''), 'active' => (int)$creator['is_active'] === 1];
        }
    }
    unset($c);
}

$dm = [
    'mode'       => 'admin',
    'me'         => $me,
    'base'       => 'admin/messages.php',
    'scope'      => $scope,
    'list'       => $list,
    'thread'     => $thread,
    'messages'   => $messages,
    'unreadMine' => dm_unread_total($me),
    'teamUrl'    => '',
];

require __DIR__ . '/../includes/admin_header.php';
require __DIR__ . '/../includes/dm_view.php';
require __DIR__ . '/../includes/admin_footer.php';
