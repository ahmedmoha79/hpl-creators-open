<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/announcement_upload.php';
$user = require_admin();
$pageTitle = 'Announcements';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'create') {
        $body = trim(post('body'));
        $pinned = post('pinned') === '1' ? 1 : 0;
        if ($body === '') {
            flash('error', 'Post body is required.');
        } else {
            try { $mediaUrl = announcement_upload_image($_FILES['announcement_image'] ?? null); }
            catch (RuntimeException $exception) { flash('error', $exception->getMessage()); redirect('admin/announcements.php'); }
            q('INSERT INTO ann_posts (author_id, body, media_url, pinned) VALUES (?,?,?,?)', [(int)$user['id'], $body, $mediaUrl, $pinned]);
            $annTitle = trim((string)post('notify_title')) ?: 'New announcement';
            notif_creators('announcement', mb_substr($annTitle, 0, 120), mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 200), 'creator/index.php?tab=announcements');
            if (post('email_all') === '1') {
                require_once __DIR__ . '/../includes/broadcast.php';
                broadcast_email_queue($annTitle, trim(preg_replace('/\s+/', ' ', $body)), 'creator/index.php?tab=announcements');
            }
            flash('success', 'Announcement posted. Everyone got a notification' . (post('email_all') === '1' ? '; emails go out in the background (about 40 every few minutes).' : '.'));
        }
    } elseif ($action === 'update') {
        $pid = (int)post('id');
        $body = trim(post('body'));
        $pinned = post('pinned') === '1' ? 1 : 0;
        if ($pid <= 0 || $body === '') {
            flash('error', 'Post body is required.');
        } else {
            $existingPost = fetch_one('SELECT media_url FROM ann_posts WHERE id = ?', [$pid]);
            if (!$existingPost) { flash('error', 'Announcement not found.'); redirect('admin/announcements.php'); }
            $mediaUrl = post('remove_image') === '1' ? null : $existingPost['media_url'];
            try { $newImage = announcement_upload_image($_FILES['announcement_image'] ?? null); }
            catch (RuntimeException $exception) { flash('error', $exception->getMessage()); redirect('admin/announcements.php?edit=' . $pid); }
            if ($newImage !== null) { $mediaUrl = $newImage; }
            q('UPDATE ann_posts SET body = ?, media_url = ?, pinned = ? WHERE id = ?', [$body, $mediaUrl, $pinned, $pid]);
            flash('success', 'Announcement updated.');
        }
    } elseif ($action === 'toggle_pin') {
        $p = fetch_one('SELECT pinned FROM ann_posts WHERE id = ?', [(int)post('id')]);
        if ($p) {
            q('UPDATE ann_posts SET pinned = ? WHERE id = ?', [(int)$p['pinned'] === 1 ? 0 : 1, (int)post('id')]);
            flash('success', 'Pin updated.');
        }
    } elseif ($action === 'delete') {
        $pid = (int)post('id');
        $old = fetch_one('SELECT body, parent_id FROM ann_posts WHERE id = ?', [$pid]);
        q('DELETE FROM ann_posts WHERE id = ? OR parent_id = ?', [$pid, $pid]); // its replies go too
        q('DELETE FROM ann_likes WHERE post_id = ?', [$pid]);
        q('DELETE FROM ann_views WHERE post_id = ?', [$pid]);
        if ($old && $old['parent_id'] === null) {
            // Remove the "New announcement" notifications for it, so the pop-up can't show deleted text.
            q('DELETE FROM notifications WHERE type = "announcement" AND body = ?', [mb_substr(trim(preg_replace('/\s+/', ' ', (string)$old['body'])), 0, 110)]);
        }
        flash('warning', 'Post deleted.');
    } elseif ($action === 'reply') {
        $body = trim(post('body'));
        $parentId = (int)post('parent_id');
        if ($body !== '' && $parentId > 0) {
            q('INSERT INTO ann_posts (author_id, body, parent_id) VALUES (?,?,?)', [(int)$user['id'], $body, $parentId]);
            flash('success', 'Reply posted.');
        }
    }
    redirect('admin/announcements.php');
}

$posts = fetch_all('SELECT p.*, u.full_name FROM ann_posts p JOIN users u ON u.id = p.author_id WHERE p.parent_id IS NULL ORDER BY p.pinned DESC, p.created_at DESC');
$counts = fetch_all('SELECT parent_id, COUNT(*) AS c FROM ann_posts WHERE parent_id IS NOT NULL GROUP BY parent_id');
$childCounts = [];
foreach ($counts as $c) { $childCounts[(int)$c['parent_id']] = (int)$c['c']; }

$editId = (int)get('edit');
$editPost = $editId > 0 ? fetch_one('SELECT * FROM ann_posts WHERE id = ?', [$editId]) : null;

$replies = fetch_all('SELECT r.*, u.full_name FROM ann_posts r JOIN users u ON u.id = r.author_id WHERE r.parent_id IS NOT NULL ORDER BY r.created_at ASC');
$repliesByParent = [];
foreach ($replies as $r) { $repliesByParent[(int)$r['parent_id']][] = $r; }

require __DIR__ . '/../includes/admin_header.php';
?>
<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Announcements</h1>
        <p class="muted mb-0">Create and manage announcement posts shown to creators.</p>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel__head"><h3><?= $editPost ? ($editPost['parent_id'] ? 'Edit reply' : 'Edit announcement') : 'New announcement' ?></h3></div>
    <div class="panel__body">
        <form method="post" enctype="multipart/form-data" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editPost ? 'update' : 'create' ?>">
            <?php if ($editPost): ?><input type="hidden" name="id" value="<?= (int)$editPost['id'] ?>"><?php endif; ?>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="body">Message</label>
                <textarea class="textarea" id="body" name="body" rows="4" placeholder="Write an announcement..." required><?= e($editPost['body'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label class="label" for="announcement_image">Announcement image (optional)</label>
                <input class="input" type="file" id="announcement_image" name="announcement_image" accept="image/jpeg,image/png,image/webp,image/gif" aria-describedby="announcementImageHint">
                <div class="field__hint" id="announcementImageHint">Choose from your phone or computer. JPG, PNG, WebP or GIF, maximum 8 MB. A new upload replaces the current image.</div>
                <img id="announcementPreview" <?= !empty($editPost['media_url']) ? 'src="' . e($editPost['media_url']) . '"' : '' ?> alt="Announcement image preview" style="max-width:100%;max-height:280px;object-fit:contain;border-radius:12px;margin-top:12px;<?= empty($editPost['media_url']) ? 'display:none;' : '' ?>">
                <p id="announcementImageError" class="field__error" role="alert" hidden></p>
                <?php if (!empty($editPost['media_url'])): ?><label class="label mt-2"><input type="checkbox" name="remove_image" value="1" id="removeAnnouncementImage"> Remove current image</label><?php endif; ?>
            </div>
            <div class="field">
                <label class="label" for="pinned">Pinned?</label>
                <select class="select" id="pinned" name="pinned">
                    <option value="0" <?= !$editPost || !$editPost['pinned'] ? 'selected' : '' ?>>No</option>
                    <option value="1" <?= $editPost && $editPost['pinned'] ? 'selected' : '' ?>>Yes</option>
                </select>
            </div>
            <?php if (!$editPost): ?>
            <div class="field"><label class="label" for="notify_title">Notification title (what phones show in bold)</label><input class="input" id="notify_title" name="notify_title" maxlength="120" placeholder="New announcement"></div>
            <label class="field" style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="email_all" value="1"> Also email every creator</label>
            <?php endif; ?>
            <div class="form-actions" style="grid-column:1/-1">
                <button class="btn btn--primary" type="submit"><?= $editPost ? 'Save changes' : 'Post announcement' ?></button>
                <?php if ($editPost): ?><a class="btn btn--ghost" href="<?= e(u('admin/announcements.php')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>All posts</h3><span class="badge"><?= count($posts) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$posts): ?>
            <div class="empty"><p class="muted">No announcements yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Author</th><th>Body</th><th>Pinned</th><th>Replies</th><th>Likes</th><th>Views</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($posts as $p): ?>
                        <tr>
                            <td><?= e($p['full_name']) ?></td>
                            <td class="truncate" style="max-width:300px"><?= e(mb_substr($p['body'],0,80)) ?><?= mb_strlen($p['body'])>80?'…':'' ?></td>
                            <td><?= $p['pinned'] ? '<span class="badge ok">Pinned</span>' : '<span class="badge muted">No</span>' ?></td>
                            <td><?= (int)($childCounts[(int)$p['id']] ?? 0) ?></td>
                            <td><?= e(num($p['like_count'])) ?></td>
                            <td><?= e(num($p['view_count'])) ?></td>
                            <td class="small muted"><?= e(time_ago($p['created_at'])) ?></td>
                            <td>
                                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/announcements.php?edit=' . (int)$p['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_pin">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= $p['pinned'] ? 'Unpin' : 'Pin' ?></button>
                                </form>
                                <form method="post" style="display:inline" data-confirm="Delete this post?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php if (!empty($repliesByParent[(int)$p['id']])): ?>
                        <tr>
                            <td colspan="8" style="background:rgba(0,0,0,.02)">
                                <details>
                                    <summary class="small">Replies (<?= count($repliesByParent[(int)$p['id']]) ?>)</summary>
                                    <div class="mt-1" style="display:flex;flex-direction:column;gap:8px">
                                        <?php foreach ($repliesByParent[(int)$p['id']] as $r): ?>
                                            <div class="flex-between" style="gap:10px;align-items:flex-start">
                                                <div class="small"><strong><?= e($r['full_name']) ?></strong> &middot; <span class="muted"><?= e(time_ago($r['created_at'])) ?></span><div><?= nl2br(e($r['body'])) ?></div></div>
                                                <div class="flex" style="gap:6px;flex:none">
                                                    <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/announcements.php?edit=' . (int)$r['id'])) ?>">Edit</a>
                                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Delete this reply?">&times;</button></form>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var input = document.getElementById('announcement_image');
    var preview = document.getElementById('announcementPreview');
    var message = document.getElementById('announcementImageError');
    var remove = document.getElementById('removeAnnouncementImage');
    var original = preview.getAttribute('src');
    var objectUrl = null;
    function refresh() {
        if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
        message.hidden = true;
        var file = input.files[0];
        if (file && file.size > 8 * 1024 * 1024) {
            message.textContent = 'Choose an image smaller than 8 MB.'; message.hidden = false;
            input.value = ''; file = null;
        }
        if (file) {
            objectUrl = URL.createObjectURL(file); preview.src = objectUrl; preview.style.display = 'block';
            if (remove) remove.checked = false;
        } else {
            preview.src = original;
            preview.style.display = original && !(remove && remove.checked) ? 'block' : 'none';
        }
    }
    input.addEventListener('change', refresh);
    if (remove) remove.addEventListener('change', function () { if (remove.checked) input.value = ''; refresh(); });
})();
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
