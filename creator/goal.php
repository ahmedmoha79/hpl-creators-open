<?php
/** Save the creator's own weekly money goal (shown on Home). 0 removes it. */
require_once __DIR__ . '/../config.php';
$user = require_creator();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('creator/index.php');
}
csrf_check();
$own = trim((string)($_POST['goal_own'] ?? ''));
$goal = $own !== '' ? (float)$own : (float)($_POST['goal'] ?? -1);
if ($goal < 0 || $goal > 100000 || !is_finite($goal)) {
    flash('error', 'Choose a goal between K1 and K100,000.');
    redirect('creator/index.php');
}
$goal = round($goal, 2);
try {
    q('UPDATE users SET weekly_goal = ? WHERE id = ?', [$goal > 0 ? $goal : null, (int)$user['id']]);
    flash('success', $goal > 0 ? 'Goal set: ' . money($goal) . ' this week. You can do it.' : 'Weekly goal removed.');
} catch (Throwable $e) {
    flash('error', 'Could not save your goal right now. Try again in a minute.');
}
redirect('creator/index.php');
