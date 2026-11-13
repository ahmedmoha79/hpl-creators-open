<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/campaign_help.php';
$user = require_admin();
$pageTitle = 'Footage library';

$allowedExt = ['mp4', 'mov', 'webm', 'mkv', 'avi', 'm4v'];
$campaignList = fetch_all('SELECT id, title, status FROM campaigns ORDER BY status = "active" DESC, created_at DESC');
$campaignIds = array_map('intval', array_column($campaignList, 'id'));
$postCampaign = function () use ($campaignIds): ?int {
    $c = (int)post('campaign_id');
    return in_array($c, $campaignIds, true) ? $c : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');

    if ($action === 'upload') {
        $title = post('title');
        $desc = mb_substr(post('description'), 0, 1000);
        $category = mb_substr(post('category'), 0, 100);
        $driveRaw = post('drive_url');
        $driveUrl = $driveRaw === '' ? null : normalize_url($driveRaw);
        $hasFile = !empty($_FILES['file']['name']) && ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_OK;

        if ($title === '') {
            flash('error', 'Please give the footage a title.');
        } elseif ($driveRaw !== '' && $driveUrl === null) {
            flash('error', 'That Google Drive link does not look like a valid URL.');
        } elseif (!$hasFile && $driveUrl === null) {
            flash('error', 'Provide a video file upload and/or a Google Drive link.');
        } else {
            $fileName = null;
            $fileSize = 0;
            if ($hasFile) {
                $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, $allowedExt, true)) {
                    flash('error', 'Unsupported file type. Allowed: ' . implode(', ', $allowedExt));
                    redirect('admin/footage.php');
                }
                $dir = UPLOAD_PATH . '/footage';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $fileName = 'footage-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
                $dest = $dir . '/' . $fileName;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                    $fileSize = filesize($dest);
                } else {
                    flash('error', 'The upload could not be saved. Check folder permissions.');
                    redirect('admin/footage.php');
                }
            }
            $campId = $postCampaign();
            q('INSERT INTO footage (title, description, category, campaign_id, file_name, file_size, drive_url) VALUES (?,?,?,?,?,?,?)', [$title, $desc, $category, $campId, $fileName, $fileSize, $driveUrl]);
            if ($campId) {
                campaign_notify_footage($campId, $title);
                flash('success', 'Footage published on the campaign page. Creators who joined were notified.');
            } else {
                flash('success', 'Footage saved and published to the library.');
            }
        }
    } elseif ($action === 'update_meta') {
        $id = (int)post('id');
        $title = post('title');
        $desc = mb_substr(post('description'), 0, 1000);
        $category = mb_substr(post('category'), 0, 100);
        if ($id <= 0 || $title === '') {
            flash('error', 'Please give the footage a title.');
        } else {
            $before = fetch_one('SELECT campaign_id, is_active FROM footage WHERE id = ?', [$id]);
            $campId = $postCampaign();
            q('UPDATE footage SET title = ?, description = ?, category = ?, campaign_id = ? WHERE id = ?', [$title, $desc, $category, $campId, $id]);
            if ($campId && $before && (int)$before['is_active'] === 1 && (int)($before['campaign_id'] ?? 0) !== $campId) {
                campaign_notify_footage($campId, $title);
            }
            flash('success', 'Footage details updated.');
        }
    } elseif ($action === 'update_drive') {
        $id = (int)post('id');
        $driveRaw = post('drive_url');
        $driveUrl = $driveRaw === '' ? null : normalize_url($driveRaw);
        if ($id <= 0) {
            flash('error', 'Invalid footage item.');
        } elseif ($driveRaw !== '' && $driveUrl === null) {
            flash('error', 'That Google Drive link does not look like a valid URL.');
        } else {
            q('UPDATE footage SET drive_url = ? WHERE id = ?', [$driveUrl, $id]);
            flash('success', $driveUrl ? 'Google Drive link saved.' : 'Google Drive link removed.');
        }
    } elseif ($action === 'toggle') {
        q('UPDATE footage SET is_active = 1 - is_active WHERE id = ?', [(int)post('id')]);
        $f = fetch_one('SELECT title, campaign_id, is_active FROM footage WHERE id = ?', [(int)post('id')]);
        if ($f && (int)$f['is_active'] === 1 && (int)($f['campaign_id'] ?? 0) > 0) {
            campaign_notify_footage((int)$f['campaign_id'], (string)$f['title']);
            flash('success', 'Footage published on the campaign page. Creators who joined were notified.');
        } else {
            flash('success', 'Footage visibility updated.');
        }
    } elseif ($action === 'delete') {
        $f = fetch_one('SELECT * FROM footage WHERE id = ?', [(int)post('id')]);
        if ($f) {
            if (!empty($f['file_name'])) {
                @unlink(UPLOAD_PATH . '/footage/' . basename($f['file_name']));
            }
            q('DELETE FROM footage WHERE id = ?', [(int)$f['id']]);
            flash('warning', 'Footage deleted.');
        }
    }
    redirect('admin/footage.php');
}

$items = fetch_all('SELECT * FROM footage ORDER BY uploaded_at DESC');
$campaignTitles = array_column($campaignList, 'title', 'id');
$prefillCampaign = (int)get('campaign');

$editId = (int)get('edit');
$editItem = $editId > 0 ? fetch_one('SELECT * FROM footage WHERE id = ?', [$editId]) : null;

require __DIR__ . '/../includes/admin_header.php';
?>

<h1>Footage library</h1>
<p class="muted">Upload raw footage or link a Google Drive folder/file. Pick a campaign to show it on that campaign's page, where everyone who joined can download it. "General library" footage is for qualified creators.</p>

<div class="panel">
    <div class="panel__head"><h3><?= $editItem ? 'Edit footage' : 'Add new footage' ?></h3></div>
    <div class="panel__body">
        <form method="post" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editItem ? 'update_meta' : 'upload' ?>">
            <?php if ($editItem): ?><input type="hidden" name="id" value="<?= (int)$editItem['id'] ?>"><?php endif; ?>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="title">Title</label>
                    <input class="input" type="text" id="title" name="title" value="<?= e($editItem['title'] ?? '') ?>" required>
                </div>
                <div class="field">
                    <label class="label" for="category">Category</label>
                    <input class="input" type="text" id="category" name="category" value="<?= e($editItem['category'] ?? '') ?>" placeholder="Product, Lifestyle, Testimonials...">
                </div>
            </div>
            <?php $selCamp = $editItem ? (int)($editItem['campaign_id'] ?? 0) : $prefillCampaign; ?>
            <div class="field">
                <label class="label" for="campaign_id">Show on campaign</label>
                <select class="select" id="campaign_id" name="campaign_id">
                    <option value="0">General library (qualified creators)</option>
                    <?php foreach ($campaignList as $cl): ?>
                        <option value="<?= (int)$cl['id'] ?>" <?= $selCamp === (int)$cl['id'] ? 'selected' : '' ?>><?= e($cl['title']) ?><?= $cl['status'] !== 'active' ? ' (ended)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="field__hint">Creators who join that campaign get a "Download footage" button on the campaign page, and are notified when you add footage.</div>
            </div>
            <div class="field">
                <label class="label" for="description">Description</label>
                <textarea class="textarea" id="description" name="description" rows="2" placeholder="What's in this clip and how can creators use it?"><?= e($editItem['description'] ?? '') ?></textarea>
            </div>
            <?php if (!$editItem): ?>
            <div class="field">
                <label class="label" for="drive_url">Google Drive link</label>
                <input class="input" type="url" id="drive_url" name="drive_url" placeholder="https://drive.google.com/...">
                <div class="field__hint">Paste the shareable Drive file/folder link. Creators will see a “Download via Google Drive” button. Leave empty if you only upload a file.</div>
            </div>
            <div class="field">
                <label class="label" for="file">Video file (optional if Drive link given)</label>
                <input class="input" type="file" id="file" name="file" accept="video/*">
                <div class="field__hint">Allowed: <?= e(implode(', ', $allowedExt)) ?>. Provide a file and/or a Drive link — at least one is required.</div>
            </div>
            <?php endif; ?>
            <button class="btn btn--primary" type="submit"><?= $editItem ? 'Save changes' : 'Save footage' ?></button>
            <?php if ($editItem): ?><a class="btn btn--ghost" href="<?= e(u('admin/footage.php')) ?>">Cancel</a><?php endif; ?>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>Library items</h3><span class="badge"><?= e(num(count($items))) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$items): ?>
            <div class="empty"><div class="empty__icon">&#127916;</div><p>No footage uploaded yet.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Title</th><th>Campaign</th><th>Category</th><th>File</th><th>Google Drive</th><th class="num">Downloads</th><th>Status</th><th>Uploaded</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($items as $f): $drive = $f['drive_url'] ?? null; ?>
                    <tr>
                        <td><strong class="small"><?= e($f['title']) ?></strong><?php if ($f['description']): ?><div class="small muted truncate"><?= e($f['description']) ?></div><?php endif; ?></td>
                        <td class="small"><?php $fc = (int)($f['campaign_id'] ?? 0); ?><?= $fc && isset($campaignTitles[$fc]) ? e($campaignTitles[$fc]) : '<span class="muted">General library</span>' ?></td>
                        <td class="small"><?= e($f['category'] ?: '—') ?></td>
                        <td class="small muted truncate"><?= e(!empty($f['file_name']) ? $f['file_name'] . ' (' . number_format((float)$f['file_size'] / 1048576, 1) . ' MB)' : '—') ?></td>
                        <td class="small">
                            <?php if ($drive): ?>
                                <a href="<?= e($drive) ?>" target="_blank" rel="noopener">Open Drive ↗</a>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                            <form method="post" style="margin-top:6px;display:flex;gap:6px">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <input type="hidden" name="action" value="update_drive">
                                <input class="input input--sm" type="url" name="drive_url" value="<?= e($drive ?? '') ?>" placeholder="https://drive.google.com/..." style="min-width:180px">
                                <button class="btn btn--ghost btn--sm" type="submit">Save link</button>
                            </form>
                        </td>
                        <td class="num small"><?= e(num($f['downloads'])) ?></td>
                        <td><span class="badge <?= $f['is_active'] ? 'ok' : 'muted' ?>"><?= $f['is_active'] ? 'Published' : 'Hidden' ?></span></td>
                        <td class="small muted"><?= e(time_ago($f['uploaded_at'])) ?></td>
                        <td>
                            <div class="flex" style="gap:6px">
                                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/footage.php?edit=' . (int)$f['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn btn--ghost btn--sm" type="submit"><?= $f['is_active'] ? 'Hide' : 'Publish' ?></button></form>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Delete this footage permanently?">Delete</button></form>
                            </div>
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
