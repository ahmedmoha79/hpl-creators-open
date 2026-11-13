<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Tutorials';

$allowedExt = ['mp4', 'webm', 'mov', 'm4v'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Detect oversized body BEFORE csrf_check: when the request exceeds PHP's
    // post_max_size the whole $_POST is dropped (csrf included), which would
    // otherwise surface as a confusing "invalid token" error.
    if (empty($_POST) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0 && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        flash('error', 'The video looks too large — the server has a small upload limit. Ask your host to raise PHP upload_max_filesize and post_max_size (needs to be bigger than the video file).');
        redirect('admin/tutorials.php');
    }
    csrf_check();
    $action = post('action');
    $id = post('clip');

    if ($action === 'upload') {
        $tut = null;
        foreach (tutorials_catalogue() as $t) {
            if ($t['id'] === $id && !empty($t['file'])) {
                $tut = $t;
                break;
            }
        }
        if (!$tut) {
            flash('error', 'That tutorial does not accept an upload.');
        } elseif (empty($_FILES['file']['name'])) {
            flash('error', 'Choose a video file to upload.');
        } elseif (($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_INI_SIZE || ($_FILES['file']['error'] ?? 1) === UPLOAD_ERR_FORM_SIZE) {
            flash('error', 'That video is bigger than the server\'s upload limit. Ask your host to raise PHP upload_max_filesize / post_max_size, or upload a smaller file.');
        } elseif (($_FILES['file']['error'] ?? 1) !== UPLOAD_ERR_OK) {
            flash('error', 'The video could not be uploaded (error ' . (int)($_FILES['file']['error'] ?? 1) . ').');
        } else {
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExt, true)) {
                flash('error', 'Unsupported file type. Allowed: ' . implode(', ', $allowedExt));
            } else {
                $dir = BASE_PATH . '/assets/media/tutorials';
                if (!is_dir($dir)) {
                    @mkdir($dir, 0775, true);
                }
                $dest = $dir . '/' . $tut['file'];
                if (move_uploaded_file($_FILES['file']['tmp_name'], $dest)) {
                    flash('success', 'Saved "' . $tut['title'] . '". It is now playing on the Tutorials page.');
                } else {
                    flash('error', 'The upload could not be saved. Check that assets/media/tutorials is writable.');
                }
            }
        }
    } elseif ($action === 'delete') {
        $tut = null;
        foreach (tutorials_catalogue() as $t) {
            if ($t['id'] === $id && !empty($t['file'])) {
                $tut = $t;
                break;
            }
        }
        if (!$tut) {
            flash('error', 'That tutorial does not have a file to remove.');
        } else {
            $dest = BASE_PATH . '/assets/media/tutorials/' . $tut['file'];
            if (is_file($dest)) {
                @unlink($dest);
                flash('warning', 'Removed the video for "' . $tut['title'] . '".');
            } else {
                flash('error', 'No uploaded file found for that tutorial.');
            }
        }
    } elseif ($action === 'setlink') {
        $tut = null;
        foreach (tutorials_catalogue() as $t) {
            if ($t['id'] === $id) {
                $tut = $t;
                break;
            }
        }
        if (!$tut) {
            flash('error', 'That tutorial does not exist.');
        } else {
            // The Clear button sends clear_drive=1; the link box is still filled in then.
            $link = post('clear_drive') === '1' ? '' : trim((string)post('drive_link'));
            $key = 'tutorial_drive_' . $tut['id'];
            if ($link === '') {
                set_setting($key, '');
                flash('warning', 'Cleared the Google Drive link for "' . $tut['title'] . '".');
            } else {
                if (!preg_match('#^https?://#i', $link)) {
                    $link = 'https://' . $link;
                }
                if (!preg_match('#^https?://(drive\.google\.com|docs\.google\.com|.+\.googleusercontent\.com)/#i', $link)) {
                    flash('error', 'That doesn\'t look like a Google Drive link. Paste a share link like https://drive.google.com/file/d/…/view.');
                } else {
                    set_setting($key, $link);
                    flash('success', 'Google Drive link saved for "' . $tut['title'] . '". The video will stream from Drive.');
                }
            }
        }
    }
    redirect('admin/tutorials.php');
}

$clips = [];
foreach (tutorials_catalogue() as $t) {
    $v = tutorial_video($t);
    $clips[] = $t + $v;
}

require __DIR__ . '/../includes/admin_header.php';
?>
<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Tutorial videos</h1>
        <p class="muted mb-0">Upload the MP4 for each tutorial. Every clip has its own file, so videos stay independent. Videos appear on the public Tutorials page as soon as they are uploaded.</p>
    </div>
    <a class="btn btn--ghost btn--sm" href="<?= e(u('tutorial.php')) ?>" target="_blank">&#8599; View tutorial page</a>
</div>

<div class="grid-2" style="margin-top:4px">
        <?php foreach ($clips as $c): $locked = empty($c['file']); ?>
            <div class="panel">
                <div class="panel__head">
                    <h3><?= e($c['title']) ?></h3>
                    <?php if ($c['has_video']): ?>
                        <span class="badge ok">Live <?= $c['drive'] ? '&middot; Drive' : '' ?></span>
                    <?php elseif ($locked): ?><span class="badge">Coming soon</span>
                    <?php else: ?><span class="badge warn">No video yet</span><?php endif; ?>
                </div>
                <div class="panel__body">
                    <?php if ($c['has_video']): ?>
                        <video class="admin-preview" controls playsinline preload="metadata" src="<?= e($c['src']) ?>"></video>
                        <?php if ($c['file']): ?>
                            <p class="small muted" style="margin:10px 0 0"><?= e(round($c['size'] / 1048576, 1)) ?> MB &middot; <?= e($c['file']) ?></p>
                        <?php else: ?>
                            <p class="small muted" style="margin:10px 0 0">Streaming from Google Drive</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="muted small" style="min-height:40px">
                            <?= $locked ? 'Comes soon — add a Google Drive link to publish it now.' : 'No video uploaded yet for this clip.' ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!$locked): ?>
                        <form method="post" enctype="multipart/form-data" class="mt-2">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="upload">
                            <input type="hidden" name="clip" value="<?= e($c['id']) ?>">
                            <div class="field">
                                <input class="input input--file" type="file" name="file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v" required>
                            </div>
                            <button class="btn btn--primary btn--sm" type="submit"><?= $c['has_video'] && !$c['drive'] ? 'Replace video' : 'Upload video' ?></button>
                        </form>
                        <?php if ($c['has_video'] && !$c['drive']): ?>
                            <form method="post" class="mt-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="clip" value="<?= e($c['id']) ?>">
                                <button class="btn btn--ghost btn--sm" type="submit" data-confirm="Remove this tutorial video?">Remove video</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <hr class="mt-3 mb-3" style="border-color:var(--line);border-style:dashed">
                    <form method="post" class="mt-1">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="setlink">
                        <input type="hidden" name="clip" value="<?= e($c['id']) ?>">
                        <label class="label" for="drv_<?= e($c['id']) ?>">Google Drive video link <span class="muted small">(for large videos — shared with "Anyone with the link")</span></label>
                        <div class="flex" style="gap:8px">
                            <input class="input" type="url" id="drv_<?= e($c['id']) ?>" name="drive_link"
                                   value="<?= e($c['drive']) ?>"
                                   placeholder="https://drive.google.com/file/d/…/view">
                            <button class="btn btn--primary btn--sm" type="submit">Save</button>
                            <?php if ($c['drive']): ?>
                                <button class="btn btn--ghost btn--sm" type="submit" name="clear_drive" value="1" data-confirm="Clear the Drive link for this tutorial?">Clear</button>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>