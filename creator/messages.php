<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/dm.php';
$user = require_creator();
$me = (int)$user['id'];
$pageTitle = 'Messages';

// Start (or open) a 1-on-1 conversation: ?user=<id>
if (($newWith = (int)get('user')) > 0) {
    $target = fetch_one(
        'SELECT id, role FROM users WHERE id = ? AND id <> ? AND role IN ("creator", "admin") AND status = "active"',
        [$newWith, $me]
    );
    if (!$target) {
        flash('error', 'That person could not be found.');
        redirect('creator/messages.php');
    }
    // Writing to the team always goes to the one support conversation.
    $tid = $target['role'] === 'admin' ? support_thread_for($me) : dm_thread_between($me, $newWith);
    redirect('creator/messages.php' . ($tid ? '?thread=' . $tid : ''));
}

// Without JavaScript the form posts here.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $tid = (int)($_POST['thread'] ?? 0);
    if ($tid > 0 && dm_can_view($tid, $user)) {
        $err = dm_send($tid, $me, (string)($_POST['message'] ?? ''));
        if ($err) {
            flash('error', $err);
        } else {
            // Same alerts as sending with JavaScript on.
            dm_notify_others($tid, $me, (string)$user['full_name'], (string)$_POST['message'], (string)$user['role']);
        }
        redirect('creator/messages.php?thread=' . $tid);
    }
    redirect('creator/messages.php');
}

$thread = null;
$messages = [];
$threadId = (int)get('thread');
if ($threadId > 0) {
    if (!dm_can_view($threadId, $user)) {
        flash('error', 'Conversation not found.');
        redirect('creator/messages.php');
    }
    dm_mark_read($threadId, $me);
    $people = dm_thread_people($threadId);
    $others = array_values(array_filter($people, function ($p) use ($me) { return (int)$p['id'] !== $me; }));
    $support = (int)fetch_val('SELECT is_support FROM dm_threads WHERE id = ?', [$threadId], 0) === 1;
    $thread = [
        'id'      => $threadId,
        'title'   => $support ? 'HPL team' : ($others[0]['full_name'] ?? 'Conversation'),
        'people'  => $people,
        'member'  => true,
        'support' => $support,
    ];
    $messages = dm_thread_messages($threadId, $me);
}

$list = dm_list($me, 'mine', $threadId);
foreach ($list as &$c) {
    if ($c['support']) {
        $c['title'] = 'HPL team';
    }
}
unset($c);

$dm = [
    'mode'       => 'creator',
    'me'         => $me,
    'base'       => 'creator/messages.php',
    'scope'      => 'mine',
    'list'       => $list,
    'thread'     => $thread,
    'messages'   => $messages,
    'unreadMine' => 0,
    'teamUrl'    => u('creator/support.php'),
];

$hideAppBar = true; // a chat screen: the "add to home screen" bar would cover the conversation
require __DIR__ . '/../includes/creator_header.php';
require __DIR__ . '/../includes/dm_view.php';
require __DIR__ . '/../includes/creator_footer.php';
