<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Courses';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'create') {
        $title = post('title');
        $desc = mb_substr(post('description'), 0, 2000);
        $thumb = trim(post('thumbnail_url'));
        $thumbUrl = $thumb === '' ? null : normalize_url($thumb);
        $chaptersRaw = trim(post('chapters'));
        $chapters = [];
        if ($chaptersRaw !== '') {
            $decoded = json_decode($chaptersRaw, true);
            if (is_array($decoded)) { $chapters = $decoded; }
        }
        if ($title === '') {
            flash('error', 'Title is required.');
        } else {
            q('INSERT INTO courses (title, description, thumbnail_url, published, sort_order, chapters) VALUES (?,?,?,?,?,?)', [$title, $desc, $thumbUrl, post('published')==='1'?1:0, (int)post('sort_order'), json_encode($chapters)]);
            flash('success', 'Course created.');
        }
    } elseif ($action === 'update') {
        $id = (int)post('id');
        $title = post('title');
        $desc = mb_substr(post('description'), 0, 2000);
        $thumb = trim(post('thumbnail_url'));
        $thumbUrl = $thumb === '' ? null : normalize_url($thumb);
        $chaptersRaw = trim(post('chapters'));
        $chapters = [];
        if ($chaptersRaw !== '') {
            $decoded = json_decode($chaptersRaw, true);
            if (is_array($decoded)) { $chapters = $decoded; }
        }
        if ($id <= 0 || $title === '') {
            flash('error', 'Title is required.');
        } else {
            q('UPDATE courses SET title = ?, description = ?, thumbnail_url = ?, published = ?, sort_order = ?, chapters = ? WHERE id = ?', [$title, $desc, $thumbUrl, post('published')==='1'?1:0, (int)post('sort_order'), json_encode($chapters), $id]);
            flash('success', 'Course updated.');
        }
    } elseif ($action === 'delete') {
        q('DELETE FROM courses WHERE id = ?', [(int)post('id')]);
        q('DELETE FROM course_progress WHERE course_id = ?', [(int)post('id')]);
        flash('warning', 'Course deleted.');
    } elseif ($action === 'toggle') {
        q('UPDATE courses SET published = 1 - published WHERE id = ?', [(int)post('id')]);
        flash('success', 'Visibility updated.');
    }
    redirect('admin/courses.php');
}

$courses = fetch_all('SELECT * FROM courses ORDER BY sort_order ASC, id ASC');

$editId = (int)get('edit');
$editCourse = $editId > 0 ? fetch_one('SELECT * FROM courses WHERE id = ?', [$editId]) : null;
$chapterJson = '';
if ($editCourse) {
    $decoded = json_decode((string)$editCourse['chapters'], true);
    $chapterJson = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) : (string)$editCourse['chapters'];
}

require __DIR__ . '/../includes/admin_header.php';
?>
<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Courses</h1>
        <p class="muted mb-0">Manage free clipping courses.</p>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel__head"><h3><?= $editCourse ? 'Edit course' : 'New course' ?></h3></div>
    <div class="panel__body">
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editCourse ? 'update' : 'create' ?>">
            <?php if ($editCourse): ?><input type="hidden" name="id" value="<?= (int)$editCourse['id'] ?>"><?php endif; ?>
            <div class="field">
                <label class="label" for="title">Title</label>
                <input class="input" type="text" id="title" name="title" value="<?= e($editCourse['title'] ?? '') ?>" required>
            </div>
            <div class="field">
                <label class="label" for="thumbnail_url">Thumbnail URL</label>
                <input class="input" type="url" id="thumbnail_url" name="thumbnail_url" value="<?= e($editCourse['thumbnail_url'] ?? '') ?>" placeholder="https://...">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="description">Description</label>
                <textarea class="textarea" id="description" name="description" rows="2"><?= e($editCourse['description'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label class="label" for="sort_order">Sort order</label>
                <input class="input" type="number" id="sort_order" name="sort_order" value="<?= (int)($editCourse['sort_order'] ?? 0) ?>">
            </div>
            <div class="field">
                <label class="label" for="published">Published?</label>
                <select class="select" id="published" name="published">
                    <option value="1" <?= !$editCourse || $editCourse['published'] ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= $editCourse && !$editCourse['published'] ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="chapters">Chapters (JSON)</label>
                <textarea class="textarea" id="chapters" name="chapters" rows="6" placeholder='[{"title":"Intro","lessons":[{"title":"Welcome","durationSec":120,"videoUrl":""}]}]'><?= e($chapterJson) ?></textarea>
            </div>
            <div class="form-actions" style="grid-column:1/-1">
                <button class="btn btn--primary" type="submit"><?= $editCourse ? 'Save changes' : 'Create course' ?></button>
                <?php if ($editCourse): ?><a class="btn btn--ghost" href="<?= e(u('admin/courses.php')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>All courses</h3><span class="badge"><?= count($courses) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$courses): ?>
            <div class="empty"><p class="muted">No courses yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Title</th><th>Published</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($courses as $c): ?>
                        <tr>
                            <td><?= e($c['title']) ?></td>
                            <td><span class="badge <?= $c['published']?'ok':'muted' ?>"><?= $c['published']?'Published':'Draft' ?></span></td>
                            <td class="small muted"><?= e(time_ago($c['created_at'])) ?></td>
                            <td>
                                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/courses.php?edit=' . (int)$c['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= $c['published']?'Unpublish':'Publish' ?></button>
                                </form>
                                <form method="post" style="display:inline" data-confirm="Delete this item? This cannot be undone.">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
