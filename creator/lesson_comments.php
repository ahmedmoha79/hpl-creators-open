<?php
/*
 * Lesson discussion for the course player (2026-10-27).
 * GET  ?course_id=&lesson_idx=           list (threads with replies, vote counts, my vote)
 * POST action=add    body, parent_id?    add a comment or a reply
 * POST action=vote   comment_id, vote (1 = like, -1 = dislike, 0 = remove)
 * POST action=delete comment_id          delete own comment (admins: any)
 */
require_once __DIR__ . '/../config.php';
$user = require_login();
$isAdmin = $user['role'] === 'admin';

$courseId = (int)($_REQUEST['course_id'] ?? 0);
$lesson = (int)($_REQUEST['lesson_idx'] ?? -1);

function lc_name(array $r): string
{
    if ($r['role'] === 'admin') {
        return 'HPL team';
    }
    $parts = preg_split('/\s+/', trim((string)$r['full_name'])) ?: ['Creator'];
    return $parts[0] . (count($parts) > 1 ? ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : '');
}

function lc_list(int $courseId, int $lesson, array $me): array
{
    $rows = fetch_all(
        'SELECT c.id, c.parent_id, c.body, c.created_at, c.user_id, c.likes, c.dislikes, c.deleted_at, u.full_name, u.role,
                COALESCE(v.vote, 0) AS my_vote
           FROM lesson_comments c
           JOIN users u ON u.id = c.user_id
      LEFT JOIN lesson_comment_votes v ON v.comment_id = c.id AND v.user_id = ?
          WHERE c.course_id = ? AND c.lesson_idx = ?
          ORDER BY c.id ASC LIMIT 400',
        [(int)$me['id'], $courseId, $lesson]
    );
    $byId = [];
    $top = [];
    foreach ($rows as $r) {
        $item = [
            'id'       => (int)$r['id'],
            'name'     => lc_name($r),
            'initial'  => $r['role'] === 'admin' ? 'H' : mb_strtoupper(mb_substr(trim((string)$r['full_name']), 0, 1)),
            'staff'    => $r['role'] === 'admin',
            'body'     => $r['deleted_at'] ? '' : (string)$r['body'],
            'deleted'  => (bool)$r['deleted_at'],
            'ago'      => time_ago($r['created_at']),
            'likes'    => (int)$r['likes'],
            'dislikes' => (int)$r['dislikes'],
            'my_vote'  => (int)$r['my_vote'],
            'mine'     => (int)$r['user_id'] === (int)$me['id'],
            'can_delete' => !$r['deleted_at'] && ((int)$r['user_id'] === (int)$me['id'] || $me['role'] === 'admin'),
            'replies'  => [],
        ];
        $byId[$item['id']] = $item;
        if (empty($r['parent_id'])) {
            $top[] = $item['id'];
        }
    }
    foreach ($rows as $r) {
        $pid = (int)($r['parent_id'] ?? 0);
        if ($pid && isset($byId[$pid])) {
            $byId[$pid]['replies'][] = $byId[(int)$r['id']];
        }
    }
    $out = [];
    foreach (array_reverse($top) as $id) { // newest threads first, replies oldest first
        $t = $byId[$id];
        if ($t['deleted'] && !$t['replies']) {
            continue;
        }
        $out[] = $t;
    }
    return $out;
}

if ($courseId <= 0 || $lesson < 0 || $lesson > 500) {
    json_response(['ok' => false, 'error' => 'Lesson not found.'], 400);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string)($_POST['csrf'] ?? '');
        if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
            json_response(['ok' => false, 'error' => 'Your session expired. Reload the page and try again.'], 419);
        }
        $action = (string)($_POST['action'] ?? 'add');

        if ($action === 'vote') {
            $cid = (int)($_POST['comment_id'] ?? 0);
            $vote = max(-1, min(1, (int)($_POST['vote'] ?? 0)));
            $c = fetch_one('SELECT id, user_id FROM lesson_comments WHERE id = ? AND course_id = ? AND lesson_idx = ? AND deleted_at IS NULL', [$cid, $courseId, $lesson]);
            if (!$c) {
                json_response(['ok' => false, 'error' => 'Comment not found.'], 404);
            }
            if ($vote === 0) {
                q('DELETE FROM lesson_comment_votes WHERE comment_id = ? AND user_id = ?', [$cid, (int)$user['id']]);
            } else {
                q('INSERT INTO lesson_comment_votes (comment_id, user_id, vote) VALUES (?,?,?) ON DUPLICATE KEY UPDATE vote = VALUES(vote)', [$cid, (int)$user['id'], $vote]);
            }
            q('UPDATE lesson_comments SET likes = (SELECT COUNT(*) FROM lesson_comment_votes WHERE comment_id = ? AND vote = 1),
                                          dislikes = (SELECT COUNT(*) FROM lesson_comment_votes WHERE comment_id = ? AND vote = -1) WHERE id = ?', [$cid, $cid, $cid]);
            $row = fetch_one('SELECT likes, dislikes FROM lesson_comments WHERE id = ?', [$cid]);
            json_response(['ok' => true, 'likes' => (int)$row['likes'], 'dislikes' => (int)$row['dislikes'], 'my_vote' => $vote]);
        }

        if ($action === 'delete') {
            $cid = (int)($_POST['comment_id'] ?? 0);
            $c = fetch_one('SELECT id, user_id FROM lesson_comments WHERE id = ? AND course_id = ? AND lesson_idx = ?', [$cid, $courseId, $lesson]);
            if (!$c || ((int)$c['user_id'] !== (int)$user['id'] && !$isAdmin)) {
                json_response(['ok' => false, 'error' => 'You can only delete your own comments.'], 403);
            }
            q('UPDATE lesson_comments SET deleted_at = NOW() WHERE id = ?', [$cid]);
            json_response(['ok' => true, 'comments' => lc_list($courseId, $lesson, $user)]);
        }

        // add
        $body = trim(preg_replace('/[ \t]+/u', ' ', str_replace("\r", '', (string)($_POST['body'] ?? ''))));
        $body = preg_replace("/\n{3,}/", "\n\n", $body);
        $parentId = (int)($_POST['parent_id'] ?? 0);
        if (mb_strlen($body) < 2) {
            json_response(['ok' => false, 'error' => 'Write a comment first.'], 400);
        }
        if (mb_strlen($body) > 800) {
            json_response(['ok' => false, 'error' => 'Keep comments under 800 characters.'], 400);
        }
        $recent = (int)fetch_val('SELECT COUNT(*) FROM lesson_comments WHERE user_id = ? AND created_at >= NOW() - INTERVAL 1 HOUR', [(int)$user['id']], 0);
        if (!$isAdmin && $recent >= 15) {
            json_response(['ok' => false, 'error' => 'You are commenting too fast. Try again later.'], 429);
        }
        $parent = null;
        if ($parentId > 0) {
            $parent = fetch_one('SELECT id, user_id, parent_id FROM lesson_comments WHERE id = ? AND course_id = ? AND lesson_idx = ? AND deleted_at IS NULL', [$parentId, $courseId, $lesson]);
            if (!$parent) {
                json_response(['ok' => false, 'error' => 'The comment you are replying to was removed.'], 404);
            }
            if (!empty($parent['parent_id'])) { // keep threads one level deep
                $parentId = (int)$parent['parent_id'];
            }
        }
        q('INSERT INTO lesson_comments (course_id, lesson_idx, user_id, parent_id, body) VALUES (?,?,?,?,?)',
            [$courseId, $lesson, (int)$user['id'], $parentId ?: null, $body]);

        $link = 'creator/course.php?id=' . $courseId . '&lesson=' . $lesson . '#comments';
        $who = $isAdmin ? 'The HPL team' : lc_name($user);
        $snippet = mb_substr($body, 0, 120);
        if ($parent && (int)$parent['user_id'] !== (int)$user['id']) {
            notif_add((int)$parent['user_id'], 'comment', $who . ' replied to your comment', $snippet, $link);
        }
        if (!$isAdmin) {
            notif_admins('comment', 'New lesson ' . ($parent ? 'reply' : 'question') . ' from ' . lc_name($user), $snippet, 'admin/lesson_comments.php');
        }
        json_response(['ok' => true, 'comments' => lc_list($courseId, $lesson, $user)]);
    }

    json_response(['ok' => true, 'comments' => lc_list($courseId, $lesson, $user)]);
} catch (Throwable $e) {
    error_log('lesson_comments: ' . $e->getMessage());
    json_response(['ok' => false, 'error' => 'Comments are not available right now. Please try again shortly.'], 500);
}
