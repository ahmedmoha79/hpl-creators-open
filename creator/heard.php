<?php
/** Saves the one-time "How did you hear about HPL Creators?" answer from the dashboard. */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/signup_source.php';
$user = require_creator();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $err = heard_save((int)$user['id'], post('heard_from'), post('heard_detail'));
    flash($err ? 'error' : 'success', $err ?: 'Thank you!');
}
$back = (string)post('back');
redirect(preg_match('~^creator/[a-z_]+\.php(\?[\w=&%-]*)?$~', $back) ? $back : 'creator/index.php');
