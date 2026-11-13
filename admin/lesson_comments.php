<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/academy.php';
$user = require_admin();
$pageTitle = 'Lesson comments';

// Lesson titles for the default course, so admins see "Lesson 03 · Getting approved" instead of an index.
$lessonTitles = [];
foreach (fetch_all('SELECT id, chapters FROM courses') as $c) {
    $ch = json_decode($c['chapters'] ?: '[]', true) ?: [];
    if (!hpl_course_has_videos($ch)) {
        $ch = hpl_academy_chapters();
    }
    $i = 0;
    foreach ($ch as $chapter) {
        foreach (($chapter['lessons'] ?? []) as $l) {
            $lessonTitles[(int)$c['id']][$i++] = (string)($l['title'] ?? 'Lesson');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $cid = (int)post('comment_id');
    $c = fetch_one('SELECT * FROM lesson_comments WHERE id = ?', [$cid]);
    if (!$c) {
        flash('error', 'Comment not found.');
        redirect('admin/lesson_comments.php');
    }
    if (post('action') === 'delete') {
        q('UPDATE lesson_comments SET deleted_at = NOW() WHERE id = ?', [$cid]);
        flash('success', 'Comment deleted.');
    } elseif (post('action') === 'reply') {
        $body = trim((string)post('body'));
        if (mb_strlen($body) < 2) {
            flash('error', 'Write a reply first.');
        } else {
            $parentId = $c['parent_id'] ? (int)$c['parent_id'] : (int)$c['id'];
            q('INSERT INTO lesson_comments (course_id, lesson_idx, user_id, parent_id, body) VALUES (?,?,?,?,?)',
                [(int)$c['course_id'], (int)$c['lesson_idx'], (int)$user['id'], $parentId, mb_substr($body, 0, 800)]);
            if ((int)$c['user_id'] !== (int)$user['id']) {
                notif_add((int)$c['user_id'], 'comment', 'The HPL team replied to your comment', mb_substr($body, 0, 120),
                    'creator/course.php?id=' . (int)$c['course_id'] . '&lesson=' . (int)$c['lesson_idx'] . '#comments');
            }
            flash('success', 'Reply posted. The creator has been notified.');
        }
    }
    redirect('admin/lesson_comments.php' . (get('filter') ? '?filter=' . urlencode(get('filter')) : ''));
}

$filter = get('filter', 'unanswered');
$rows = fetch_all(
    'SELECT c.*, u.full_name, u.email, u.role,
            (SELECT COUNT(*) FROM lesson_comments r JOIN users ru ON ru.id = r.user_id WHERE r.parent_id = c.id AND ru.role = "admin" AND r.deleted_at IS NULL) AS staff_replies,
            (SELECT COUNT(*) FROM lesson_comments r WHERE r.parent_id = c.id AND r.deleted_at IS NULL) AS replies
       FROM lesson_comments c JOIN users u ON u.id = c.user_id
      WHERE c.deleted_at IS NULL AND u.role <> "admin"
      ORDER BY c.id DESC LIMIT 200'
);
if ($filter === 'unanswered') {
    $rows = array_values(array_filter($rows, function ($r) { return empty($r['parent_id']) && (int)$r['staff_replies'] === 0; }));
}

require __DIR__ . '/../includes/admin_header.php';
?>
<div class="ui-page-head">
    <div>
        <h1>Lesson comments</h1>
        <p>Questions and replies from creators in the Creator Academy. Reply here and the creator gets a notification.</p>
    </div>
    <div class="ui-page-head__actions">
        <a class="btn <?= $filter === 'unanswered' ? 'btn--primary' : 'btn--ghost' ?> btn--sm" href="?filter=unanswered">Unanswered</a>
        <a class="btn <?= $filter === 'all' ? 'btn--primary' : 'btn--ghost' ?> btn--sm" href="?filter=all">All</a>
    </div>
</div>

<section class="ui-card">
    <?php if (!$rows): ?>
        <div class="ui-empty"><?= icon('check-circle') ?><p><?= $filter === 'unanswered' ? 'All questions have an answer from the team.' : 'No comments yet.' ?></p></div>
    <?php else: ?>
        <ul class="ui-rows">
            <?php foreach ($rows as $r):
                $lessonTitle = $lessonTitles[(int)$r['course_id']][(int)$r['lesson_idx']] ?? ('Lesson ' . ((int)$r['lesson_idx'] + 1));
            ?>
                <li class="ui-row" style="align-items:flex-start">
                    <span class="ui-dot"><?= e(strtoupper(mb_substr($r['full_name'], 0, 1))) ?></span>
                    <div class="ui-row__main">
                        <div class="ui-row__sub"><strong style="color:var(--ui-text)"><?= e($r['full_name']) ?></strong> &middot; <?= e($lessonTitle) ?> &middot; <?= e(time_ago($r['created_at'])) ?><?= $r['parent_id'] ? ' &middot; reply' : '' ?>
                            &middot; Likes <?= (int)$r['likes'] ?> &middot; Dislikes <?= (int)$r['dislikes'] ?></div>
                        <p style="margin:6px 0 10px;white-space:pre-wrap"><?= e($r['body']) ?></p>
                        <form method="post" class="ui-form" style="grid-template-columns:minmax(0,1fr) auto auto">
                            <?= csrf_field() ?><input type="hidden" name="comment_id" value="<?= (int)$r['id'] ?>">
                            <input class="input" name="body" placeholder="Write a reply as the HPL team" style="width:100%">
                            <button class="btn btn--primary btn--sm" type="submit" name="action" value="reply"><?= icon('reply') ?>Reply</button>
                            <button class="btn btn--ghost btn--sm" type="submit" name="action" value="delete" data-confirm="Delete this comment?"><?= icon('trash') ?></button>
                        </form>
                    </div>
                    <span class="small muted" style="flex:none"><?= (int)$r['replies'] ?> repl<?= (int)$r['replies'] === 1 ? 'y' : 'ies' ?></span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
