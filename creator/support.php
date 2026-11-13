<?php
require_once __DIR__ . '/../config.php';
$user = require_creator();
$me = (int)$user['id'];
$pageTitle = 'Support';

// The support thread is a real 1-on-1 with the admin: the bot answers
// common questions automatically, and any admin reply lands in the same chat.
$threadId = support_thread_for($me);
if ($threadId <= 0) {
    flash('error', 'Support is not available right now. Please try again shortly.');
    redirect('creator/index.php');
}

// One place for all conversations: support lives in Messages (with quick questions).
redirect('creator/messages.php?thread=' . $threadId);
