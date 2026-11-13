<?php
require_once __DIR__ . '/../config.php';
$user = require_creator();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { json_response(['ok' => false, 'error' => 'Method not allowed.'], 405); }
$token = (string)($_POST['csrf'] ?? '');
if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    json_response(['ok' => false, 'error' => 'Session expired.'], 419);
}
$courseId = (int)($_POST['course_id'] ?? 0);
$lessonIdx = (int)($_POST['lesson_idx'] ?? -1);
if ($courseId <= 0 || $lessonIdx < 0) { json_response(['ok' => false, 'error' => 'Bad input.'], 400); }
$row = fetch_one('SELECT completed_lessons FROM course_progress WHERE user_id = ? AND course_id = ?', [(int)$user['id'], $courseId]);
$completed = $row ? (json_decode($row['completed_lessons'] ?: '[]', true) ?: []) : [];
if (($_POST['complete'] ?? '1') !== '0' && !in_array($lessonIdx, $completed, true)) { $completed[] = $lessonIdx; }
if ($row) {
    q('UPDATE course_progress SET completed_lessons = ?, last_lesson_id = ? WHERE user_id = ? AND course_id = ?', [json_encode($completed), $lessonIdx, (int)$user['id'], $courseId]);
} else {
    q('INSERT INTO course_progress (user_id, course_id, completed_lessons, last_lesson_id) VALUES (?,?,?,?)', [(int)$user['id'], $courseId, json_encode($completed), $lessonIdx]);
}
json_response(['ok' => true]);
