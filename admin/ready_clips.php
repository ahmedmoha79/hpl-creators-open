<?php
/**
 * Admin: ready clips per campaign (owner decision 2026-11-05).
 * The team uploads finished clips; each one goes to one creator, who posts it and sends the link.
 * Files are uploaded in small pieces, so big batches work whatever the host's upload limit is.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/ready_clips.php';
// Upload key (2026-11-10): lets a trusted computer send clips straight from a folder (pieces only, nothing else).
// An admin makes a key on this page's API (action=upload_key); it works for 2 hours and only for action=chunk.
// Only its fingerprint is stored. Ten wrong keys from one address lock that address out for an hour.
$uploadKeyOk = false;
$sentKey = (string)($_SERVER['HTTP_X_HPL_UPLOAD_KEY'] ?? '');
if ($sentKey !== '' && ($_POST['action'] ?? '') === 'chunk') {
    $tkKey = 'upkey:' . (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $hash = (string)setting('rc_upload_key_hash', '');
    if (throttle_blocked($tkKey, 10, 3600)) {
        json_response(['ok' => false, 'error' => 'Too many wrong keys. Try again in an hour.'], 429);
    }
    if ($hash !== '' && (int)setting('rc_upload_key_until', '0') > time() && hash_equals($hash, hash('sha256', $sentKey))) {
        $uploadKeyOk = true;
    } else {
        throttle_fail($tkKey, 3600);
        json_response(['ok' => false, 'error' => 'The upload key is wrong or has ended.'], 403);
    }
}
$user = $uploadKeyOk ? ['id' => 0, 'role' => 'admin', 'full_name' => 'Upload key'] : require_admin();
$pageTitle = 'Ready clips';

$allowedExt = ['mp4', 'mov', 'm4v', 'webm'];
$maxBytes = 300 * 1048576;
/** PHP size setting ("2M", "8M", "1G") in bytes. */
$iniBytes = function (string $k): int {
    $v = trim((string)ini_get($k));
    if ($v === '' || $v === '0' || $v === '-1') { return PHP_INT_MAX; }
    $n = (int)$v;
    $u = strtolower(substr($v, -1));
    return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n));
};
// Each upload piece must fit the hosting's PHP limits (often only 2 MB), with room for the still picture.
$chunkBytes = max(256 * 1024, min(4 << 20, $iniBytes('upload_max_filesize') - (64 << 10), $iniBytes('post_max_size') - (512 << 10)));
$campaignList = fetch_all('SELECT id, title, status FROM campaigns ORDER BY status = "active" DESC, created_at DESC');
$campaignTitles = array_column($campaignList, 'title', 'id');
$cid = (int)(get('campaign') ?: post('campaign_id'));
if (!isset($campaignTitles[$cid])) {
    $cid = (int)($campaignList[0]['id'] ?? 0);
}

// Admin: the clip's still picture.
if (get('poster') !== '') {
    $clip = fetch_one('SELECT file_name FROM ready_clips WHERE id = ?', [(int)get('poster')]);
    $pp = $clip ? ready_clips_dir() . '/' . pathinfo(basename((string)$clip['file_name']), PATHINFO_FILENAME) . '.jpg' : '';
    if (!$clip || !is_file($pp)) { http_response_code(404); exit; }
    header('Content-Type: image/jpeg');
    readfile($pp);
    exit;
}
// Admin preview of a clip.
if (get('view') !== '') {
    $clip = fetch_one('SELECT * FROM ready_clips WHERE id = ?', [(int)get('view')]);
    $path = $clip ? ready_clips_dir() . '/' . basename((string)$clip['file_name']) : '';
    if (!$clip || !is_file($path)) {
        http_response_code(404);
        exit('Not found');
    }
    ready_clip_send($path, false, 'clip-' . (int)$clip['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    // One piece of a file. JSON answers for the uploader script.
    if ($action === 'chunk') {
        $token = (string)($_POST['csrf'] ?? '');
        if (!$uploadKeyOk && ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token))) {
            json_response(['ok' => false, 'error' => 'Session expired. Reload the page.'], 419);
        }
        if (!isset($campaignTitles[(int)post('campaign_id')])) {
            json_response(['ok' => false, 'error' => 'Pick a campaign.'], 400);
        }
        $upId = preg_replace('/[^a-z0-9]/', '', strtolower(post('upload_id')));
        $index = (int)post('index');
        $total = (int)post('total');
        $name = (string)post('name');
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (strlen($upId) < 10 || $total < 1 || $index < 0 || $index >= $total || !in_array($ext, $allowedExt, true)) {
            json_response(['ok' => false, 'error' => 'Only MP4, MOV, M4V or WebM videos.'], 400);
        }
        $f = $_FILES['chunk'] ?? null;
        if (!$f || ($f['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $code = (int)($f['error'] ?? -1);
            json_response(['ok' => false, 'error' => in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? 'The server refused a piece as too big. Reload the page and try again.'
                : 'A piece of the file did not arrive (code ' . $code . '). Try again.'], 400);
        }
        $tmpDir = ready_clips_dir() . '/tmp';
        if (!is_dir($tmpDir)) {
            @mkdir($tmpDir, 0755, true);
        }
        $part = $tmpDir . '/' . $upId . '.part';
        if ($index === 0) {
            // Clear pieces of uploads that were abandoned more than a day ago.
            foreach ((array)glob($tmpDir . '/*.part') as $old) {
                if (is_file($old) && filemtime($old) < time() - 86400) { @unlink($old); }
            }
        }
        // Each piece says where it starts, so a piece sent twice (a retry after a lost answer) is not added twice.
        $offset = (int)post('offset');
        clearstatcache(true, $part);
        $have = is_file($part) ? filesize($part) : 0;
        if ($offset === 0 && $have > 0 && $index === 0) {
            @unlink($part);
            $have = 0;
        }
        if ($offset < $have) {
            // already saved
        } elseif ($offset > $have) {
            json_response(['ok' => false, 'error' => 'The upload was interrupted. Try this file again.'], 400);
        } else {
            file_put_contents($part, file_get_contents($f['tmp_name']), FILE_APPEND | LOCK_EX);
        }
        if (filesize($part) > $maxBytes) {
            @unlink($part);
            json_response(['ok' => false, 'error' => 'Too big: a ready clip can be up to 300 MB.'], 400);
        }
        if ($index < $total - 1) {
            json_response(['ok' => true, 'done' => false]);
        }
        // Last piece: check it really is a video, then keep it.
        $head = (string)file_get_contents($part, false, null, 0, 12);
        $isVideo = substr($head, 4, 4) === 'ftyp' || substr($head, 0, 4) === "\x1A\x45\xDF\xA3";
        if (!$isVideo) {
            @unlink($part);
            json_response(['ok' => false, 'error' => 'That file is not a video we can use.'], 400);
        }
        $c = (int)post('campaign_id');
        $replaceId = (int)post('replace_id');
        if ($replaceId > 0) {
            $old = fetch_one('SELECT * FROM ready_clips WHERE id = ?', [$replaceId]);
            if (!$old || $old['claimed_by'] !== null || $old['submission_id'] !== null) {
                @unlink($part);
                json_response(['ok' => false, 'error' => 'A creator already took this clip, so its video cannot be changed. Upload a new clip instead.'], 400);
            }
            $c = (int)$old['campaign_id'];
        }
        $file = $c . '-' . date('Ymd') . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
        if (!@rename($part, ready_clips_dir() . '/' . $file)) {
            @unlink($part);
            json_response(['ok' => false, 'error' => 'The file could not be saved. Check the server space.'], 500);
        }
        // A still picture from the clip, made in the admin's browser, shown before creators press play.
        $pf = $_FILES['poster'] ?? null;
        if ($pf && ($pf['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($pf['tmp_name']) && $pf['size'] < 3 * 1048576) {
            $info = @getimagesize($pf['tmp_name']);
            if ($info && $info[2] === IMAGETYPE_JPEG) {
                @move_uploaded_file($pf['tmp_name'], ready_clips_dir() . '/' . pathinfo($file, PATHINFO_FILENAME) . '.jpg');
            }
        }
        if ($replaceId > 0) {
            // Swap the video only if the clip is still free (checked again, locked, in case a creator took it meanwhile).
            $swapped = hpl_tx(function () use ($replaceId, $file) {
                $o = fetch_one('SELECT * FROM ready_clips WHERE id = ? FOR UPDATE', [$replaceId]);
                if (!$o || $o['claimed_by'] !== null || $o['submission_id'] !== null) {
                    return null;
                }
                q('UPDATE ready_clips SET file_name = ?, file_size = ?, downloads = 0 WHERE id = ?', [$file, filesize(ready_clips_dir() . '/' . $file), $replaceId]);
                return $o;
            });
            $dir = ready_clips_dir();
            if (!$swapped) {
                @unlink($dir . '/' . $file);
                @unlink($dir . '/' . pathinfo($file, PATHINFO_FILENAME) . '.jpg');
                json_response(['ok' => false, 'error' => 'A creator just took this clip, so its video was not changed.'], 409);
            }
            ready_clip_delete_files((string)$swapped['file_name']);
            json_response(['ok' => true, 'done' => true, 'id' => $replaceId, 'replaced' => true]);
        }
        $title = mb_substr(trim(preg_replace('/[_-]+/', ' ', pathinfo($name, PATHINFO_FILENAME))), 0, 200);
        if (isset($_POST['title'])) {
            $title = mb_substr(trim((string)$_POST['title']), 0, 200); // the sender chose the title (may be empty)
        }
        $style = ready_clip_style_pick((string)($_POST['style'] ?? ''));
        q('INSERT INTO ready_clips (campaign_id, title, file_name, file_size, style) VALUES (?,?,?,?,?)',
            [$c, $title, $file, filesize(ready_clips_dir() . '/' . $file), $style !== '' ? $style : null]);
        json_response(['ok' => true, 'done' => true, 'id' => (int)db()->lastInsertId()]);
    }

    csrf_check();
    if ($action === 'upload_key' || $action === 'upload_key_off') {
        // Make (or end) the upload key. The key itself is shown once, here, and never stored.
        $key = $action === 'upload_key' ? bin2hex(random_bytes(24)) : '';
        set_setting('rc_upload_key_hash', $key !== '' ? hash('sha256', $key) : '');
        set_setting('rc_upload_key_until', $key !== '' ? (string)(time() + 7200) : '0');
        json_response(['ok' => true, 'key' => $key, 'chunk' => $chunkBytes, 'minutes' => $key !== '' ? 120 : 0]);
    }
    $id = (int)post('id');
    $clip = $id ? fetch_one('SELECT * FROM ready_clips WHERE id = ?', [$id]) : null;
    if ($action === 'poster') {
        // A still picture made by the admin's browser for a clip that has none (see "missing pictures" script below).
        $pf = $_FILES['poster'] ?? null;
        $info = ($clip && $pf && ($pf['error'] ?? 1) === UPLOAD_ERR_OK && is_uploaded_file($pf['tmp_name']) && $pf['size'] < 3 * 1048576) ? @getimagesize($pf['tmp_name']) : null;
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            json_response(['ok' => false], 400);
        }
        json_response(['ok' => @move_uploaded_file($pf['tmp_name'], ready_clips_dir() . '/' . pathinfo(basename((string)$clip['file_name']), PATHINFO_FILENAME) . '.jpg')]);
    }
    if ($action === 'trust_set') { // the team decides for one creator: 2 = normal always, 1 = 1 a day always, '' = the website decides
        $v = post('trust');
        q('UPDATE users SET clip_trust_override = ?, clip_trust_at = NULL WHERE id = ? AND role = "creator"', [$v === '' ? null : ((int)$v === 1 ? 1 : 2), (int)post('user_id')]);
        flash('success', 'Saved.');
        redirect('admin/ready_clips.php?campaign=' . $cid . '#trust');
    }
    if ($action === 'lift_ban') {
        q('UPDATE users SET clip_ban_until = NULL, clip_ban_reason = NULL WHERE id = ?', [(int)post('user_id')]);
        q('UPDATE tiktok_accounts SET bio_missing_since = NULL, bio_warned_at = NULL, bio_checked_at = NULL WHERE user_id = ?', [(int)post('user_id')]);
        notif_add((int)post('user_id'), 'info', 'You can take and send clips again', 'The HPL team lifted your pause. Keep @' . ready_tag_handle() . ' in your TikTok bio.', 'creator/index.php');
        flash('success', 'Pause lifted. The creator was told.');
        redirect('admin/ready_clips.php?campaign=' . (int)post('campaign_id'));
    }
    if ($action === 'fastcheck') {
        flash(ready_fast_files_check() ? 'success' : 'warning', (string)setting('ready_fast_files', '0') === '1'
            ? 'Fast downloads are on: the web server sends the videos itself.'
            : 'Fast downloads could not be switched on here, so videos still go through the site (slower when many people download at once). Tell the developer.');
        redirect('admin/ready_clips.php?campaign=' . (int)post('campaign_id'));
    }
    if ($action === 'rules') {
        // Ready-clip rules (2026-11-07): how ready a creator's TikTok must be, and how fast they may post.
        set_setting('auto_approve_ready', post('auto_approve_ready') === '1' ? '1' : '0');
        set_setting('brain_approve_own_edits', post('brain_approve_own_edits') === '1' ? '1' : '0');
        set_setting('ready_bio_grace_days', (string)max(1, min(14, (int)post('bio_grace_days'))));
        set_setting('ready_bio_ban_days', (string)max(0, min(30, (int)post('bio_ban_days'))));
        set_setting('ready_warmup_days', (string)max(0, min(30, (int)post('warmup_days'))));
        set_setting('ready_min_following', (string)max(0, min(1000, (int)post('min_following'))));
        set_setting('ready_active_following', (string)max(0, min(5000, (int)post('active_following'))));
        set_setting('ready_clip_cooldown_mins', (string)(max(0, min(48, (float)post('cooldown_hours'))) * 60));
        set_setting('ready_clip_hold_max', (string)max(1, min(10, (int)post('hold_max'))));
        set_setting('ready_clips_per_day', (string)max(0, min(20, (int)post('per_day'))));
        set_setting('clip_trust_on', post('trust_on') === '1' ? '1' : '0');
        set_setting('clip_trust_min_views', (string)max(0, min(100000, (int)post('trust_min_views'))));
        set_setting('clip_trust_min_reactions', (string)max(0, min(50, (float)post('trust_min_reactions'))));
        set_setting('clip_trust_min_score', (string)max(0, min(100, (int)post('trust_min_score'))));
        set_setting('clip_trust_recover_views', (string)max(1, min(1000000, (int)post('trust_recover_views'))));
        q('UPDATE users SET clip_trust_at = NULL'); // judge everyone again with the new numbers
        flash('success', 'Ready-clip rules saved.');
        redirect('admin/ready_clips.php?campaign=' . $cid);
    } elseif ($action === 'notify') {
        $n = max(0, (int)post('count'));
        $open = (bool)fetch_val('SELECT 1 FROM campaigns WHERE id = ? AND status = "active" AND camp_status = "accepting"', [$cid], 0);
        if ($n > 0 && $open) {
            ready_clips_notify($cid, $n);
        }
        flash('success', $n . ' clip' . ($n === 1 ? '' : 's') . ' added.' . ($n > 0 ? ($open ? ' Creators in this campaign were told.' : ' This campaign is not taking clips now, so creators were not told.') : ''));
    } elseif ($clip && $action === 'caption') {
        $style = ready_clip_style_pick(post('style') === '_new' ? post('style_new') : post('style'));
        q('UPDATE ready_clips SET title = ?, style = ? WHERE id = ?', [mb_substr(trim(post('title')), 0, 200), $style !== '' ? $style : null, $id]);
        flash('success', 'Clip #' . $id . ' saved.');
    } elseif ($clip && $action === 'release') {
        $subRow = $clip['submission_id'] !== null ? fetch_one('SELECT status, miss_count, missing_since FROM campaign_submissions WHERE id = ?', [(int)$clip['submission_id']]) : null;
        $subSt = (string)($subRow['status'] ?? '');
        $subGone = $subRow && ((int)$subRow['miss_count'] > 0 || !empty($subRow['missing_since'])); // its post is gone from TikTok
        if ($clip['submission_id'] !== null && $subSt !== 'rejected' && $subSt !== '' && !$subGone) {
            flash('error', 'That clip was already posted, so it cannot be given to someone else.');
        } else {
            q('UPDATE ready_clips SET claimed_by = NULL, claimed_at = NULL, reminded_at = NULL, submission_id = NULL WHERE id = ?', [$id]);
            flash('success', 'Clip #' . $id . ' is free again for other creators.');
        }
    } elseif ($clip && $action === 'toggle') {
        $holder = ready_clip_holder($clip);
        if ($clip['is_active'] && $holder) {
            // A creator took it but has not posted it: take it back quietly, no warning for them.
            q('UPDATE ready_clips SET is_active = 0, claimed_by = NULL, claimed_at = NULL, reminded_at = NULL, submission_id = NULL WHERE id = ?', [$id]);
            ready_clip_tell_removed($holder, $clip);
        } else {
            q('UPDATE ready_clips SET is_active = 1 - is_active WHERE id = ?', [$id]);
        }
        flash('success', 'Clip #' . $id . ($clip['is_active'] ? ' hidden. Creators cannot see it.' : ' shown again.'));
    } elseif ($clip && $action === 'move') {
        $to = (int)post('to_campaign');
        if (!isset($campaignTitles[$to])) {
            flash('error', 'Pick the campaign to move the clip to.');
        } elseif ($clip['claimed_by'] !== null || $clip['submission_id'] !== null) {
            flash('error', 'A creator already took this clip, so it cannot be moved.');
        } elseif ($to !== (int)$clip['campaign_id']) {
            q('UPDATE ready_clips SET campaign_id = ? WHERE id = ? AND claimed_by IS NULL AND submission_id IS NULL', [$to, $id]);
            flash('success', 'Clip #' . $id . ' moved to ' . $campaignTitles[$to] . '.');
            $cid = $to;
        }
    } elseif ($clip && $action === 'delete') {
        if ($clip['submission_id'] !== null) {
            flash('error', 'That clip was posted by a creator. Hide it instead of deleting it, so its record stays.');
        } else {
            if ($clip['claimed_by'] !== null) {
                ready_clip_tell_removed((int)$clip['claimed_by'], $clip);
            }
            q('DELETE FROM ready_clips WHERE id = ?', [$id]);
            ready_clip_delete_files((string)$clip['file_name']);
            flash('success', 'Clip #' . $id . ' deleted.');
        }
    }
    redirect('admin/ready_clips.php?campaign=' . $cid);
}

$counts = ready_clip_counts();
$clips = $cid ? fetch_all('SELECT r.*, u.full_name, s.url AS posted_url, s.status AS sub_status, s.miss_count AS sub_miss, s.views, s.start_views, s.earned, s.credited_views
    FROM ready_clips r LEFT JOIN users u ON u.id = r.claimed_by LEFT JOIN campaign_submissions s ON s.id = r.submission_id
    WHERE r.campaign_id = ? ORDER BY r.id DESC', [$cid]) : [];
$nFree = count(array_filter($clips, fn($r) => $r['claimed_by'] === null && $r['is_active']));
$held = fn($r) => $r['claimed_by'] !== null && ($r['submission_id'] === null || $r['sub_status'] === 'rejected' || $r['sub_status'] === null);
$nTaken = count(array_filter($clips, $held));
$nPosted = count(array_filter($clips, fn($r) => $r['submission_id'] !== null && !$held($r)));
$campOpen = (bool)fetch_val('SELECT 1 FROM campaigns WHERE id = ? AND status = "active" AND camp_status = "accepting"', [$cid], 0);

require __DIR__ . '/../includes/admin_header.php';
?>

<h1>Ready clips</h1>

<p class="muted">Finished clips. Each clip goes to <strong>one creator only</strong>: they take it, post it on their TikTok and send the link. Paid like any campaign clip; you still approve it.</p>

<?php
// Creators whose TikTok bio lost the HPL tag after they sent a ready clip (checked about once a day).
$bioMissing = [];
try {
    $bioMissing = fetch_all('SELECT a.username, a.bio_missing_since, u.id, u.full_name, u.clip_ban_until,
            (SELECT COUNT(*) FROM campaign_submissions s WHERE s.user_id = u.id AND s.ready_clip_id IS NOT NULL) clips
        FROM tiktok_accounts a JOIN users u ON u.id = a.user_id
        WHERE a.bio_missing_since IS NOT NULL OR u.clip_ban_until > NOW() ORDER BY u.clip_ban_until IS NULL, a.bio_missing_since ASC LIMIT 100');
} catch (Throwable $e) {
}
?>
<?php if ($bioMissing): ?>
<details class="panel" style="padding:0" open>
    <summary class="panel__head" style="cursor:pointer"><h3>Paused, or bio tag removed</h3><span class="badge warn"><?= count($bioMissing) ?> creator<?= count($bioMissing) === 1 ? '' : 's' ?></span></summary>
    <div class="panel__body">
        <p class="small muted" style="margin-top:0">The website checks bios by itself about once a day. A creator who removes @<?= e(ready_tag_handle()) ?> gets a warning; if it is not back after <?= ready_bio_grace_days() ?> day<?= ready_bio_grace_days() === 1 ? '' : 's' ?>, they cannot take or send clips for <?= ready_bio_ban_days() ?> days. Money they already earned is not touched.</p>
        <div style="overflow-x:auto"><table class="table"><thead><tr><th>Creator</th><th>TikTok</th><th>Now</th><th>Ready clips sent</th><th></th></tr></thead><tbody>
        <?php foreach ($bioMissing as $b): $paused = !empty($b['clip_ban_until']) && strtotime((string)$b['clip_ban_until']) > time(); ?>
            <tr><td><?= e($b['full_name']) ?></td><td><a href="https://www.tiktok.com/@<?= e($b['username']) ?>" target="_blank" rel="noopener">@<?= e($b['username']) ?></a></td>
                <td><?= $paused ? 'Paused until ' . e(date('D j M, H:i', strtotime((string)$b['clip_ban_until']))) : 'Warned ' . e(date('D j M', strtotime((string)$b['bio_missing_since']))) ?></td><td><?= (int)$b['clips'] ?></td>
                <td><?php if ($paused): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="lift_ban"><input type="hidden" name="user_id" value="<?= (int)$b['id'] ?>"><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn--ghost btn--sm" type="submit">Lift pause</button></form><?php endif; ?></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</details>
<?php endif; ?>

<details class="panel" style="padding:0">
    <summary class="panel__head" style="cursor:pointer"><h3>Rules and automatic approval</h3><span class="badge"><?= ready_warmup_days() ?>-day warm-up · <?= round(ready_clip_cooldown_mins() / 60, 1) ?>h between clips · <?= ready_clips_per_day() ?: 'no' ?> a day</span></summary>
    <div class="panel__body">
        <p class="small muted" style="margin-top:0">Before taking a ready clip a creator must pass the test, connect TikTok, put the tag @<?= e(ready_tag_handle()) ?> in their bio (checked again when they send the link, and about once a day after), follow some accounts and warm up the account. Accounts already in daily use (they follow <?= (int)setting('ready_active_following', '50') ?>+ accounts) or with <?= (int)setting('ready_established_videos', '10') ?>+ videos and <?= (int)setting('ready_established_followers', '100') ?>+ followers skip the warm-up.</p>
        <form method="post" class="flex" style="gap:12px;flex-wrap:wrap;align-items:flex-end">
            <?= csrf_field() ?><input type="hidden" name="action" value="rules"><input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <label class="field" style="margin:0">Warm-up days<input class="input" type="number" name="warmup_days" min="0" max="30" value="<?= ready_warmup_days() ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Must follow at least<input class="input" type="number" name="min_following" min="0" max="1000" value="<?= ready_min_following() ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Skip warm-up if they follow<input class="input" type="number" name="active_following" min="0" max="5000" value="<?= (int)setting('ready_active_following', '50') ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Hours between clips<input class="input" type="number" name="cooldown_hours" min="0" max="48" step="0.5" value="<?= round(ready_clip_cooldown_mins() / 60, 1) ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Clips a day (0 = no limit)<input class="input" type="number" name="per_day" min="0" max="20" value="<?= ready_clips_per_day() ?>" style="width:110px"></label>
            <label class="field" style="margin:0;flex-basis:100%;display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="trust_on" value="1" <?= (string)setting('clip_trust_on', '1') === '1' ? 'checked' : '' ?> style="width:22px;height:22px;margin-top:2px">
                <span><strong>1 clip a day for weak posters</strong><br><span class="small muted">Two weak signs (or taking clips and not posting them) = 1 clip a day until one of their clips does well. New creators are never limited. Money is never touched.</span></span></label>
            <label class="field" style="margin:0">Weak: a normal clip gets fewer views than<input class="input" type="number" name="trust_min_views" min="0" value="<?= (int)setting('clip_trust_min_views', '200') ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Weak: reactions per 100 views under<input class="input" type="number" name="trust_min_reactions" min="0" max="50" step="0.5" value="<?= e((string)setting('clip_trust_min_reactions', '1.5')) ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Weak: TikTok page score under<input class="input" type="number" name="trust_min_score" min="0" max="100" value="<?= (int)setting('clip_trust_min_score', '40') ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Back to normal after one clip with views<input class="input" type="number" name="trust_recover_views" min="1" value="<?= (int)setting('clip_trust_recover_views', '1000') ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Clips held at once<input class="input" type="number" name="hold_max" min="1" max="10" value="<?= ready_clip_hold_max() ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Days to put the bio tag back<input class="input" type="number" name="bio_grace_days" min="1" max="14" value="<?= ready_bio_grace_days() ?>" style="width:110px"></label>
            <label class="field" style="margin:0">Then no clips for (days)<input class="input" type="number" name="bio_ban_days" min="0" max="30" value="<?= ready_bio_ban_days() ?>" style="width:110px"></label>
            <label class="field" style="margin:0;flex-basis:100%;display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="auto_approve_ready" value="1" <?= (string)setting('auto_approve_ready', '0') === '1' ? 'checked' : '' ?> style="width:22px;height:22px;margin-top:2px">
                <span><strong>The Brain approves clips when everything is right</strong><br><span class="small muted">When a link is sent the Brain checks it like a reviewer: own connected TikTok, tag in bio and caption, posted after taking, same length, same pictures as our clip, no music added, and it looks at the post's picture itself. With this on, a ready clip that passes everything is approved without you. Anything unclear waits for you, and a wrong video is sent to your Telegram at once. It never rejects. It checks every clip again after 6 hours, 1 day, 3 days and 7 days.</span></span></label>
            <label class="field" style="margin:0;flex-basis:100%;display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="brain_approve_own_edits" value="1" <?= (string)setting('brain_approve_own_edits', '0') === '1' ? 'checked' : '' ?> style="width:22px;height:22px;margin-top:2px">
                <span><strong>Also let the Brain approve clips creators edited themselves</strong><br><span class="small muted">Off: own edits always wait for you (the Brain still looks and tells you what it saw). There is no clip of ours to compare an own edit with, so it is less sure.</span></span></label>
            <button class="btn btn--primary btn--sm" type="submit">Save rules</button>
        </form>
        <p class="small muted" style="margin-bottom:0">Set a number to 0 to switch that rule off.</p>
        <form method="post" class="flex" style="gap:10px;align-items:center;margin-top:12px">
            <?= csrf_field() ?><input type="hidden" name="action" value="fastcheck"><input type="hidden" name="campaign_id" value="<?= $cid ?>">
            <span class="small">Fast downloads (for many creators at once): <strong><?= (string)setting('ready_fast_files', '') === '1' ? 'on' : ((string)setting('ready_fast_files', '') === '0' ? 'off' : 'not checked yet') ?></strong></span>
            <button class="btn btn--ghost btn--sm" type="submit">Check now</button>
        </form>
    </div>
</details>

<?php
// Creators on 1 clip a day (includes/clip_trust.php), and any the team decided for by hand.
$trustRows = [];
try {
    $trustRows = fetch_all('SELECT id, full_name, clip_trust, clip_trust_why, clip_trust_at, clip_trust_override FROM users
        WHERE role = "creator" AND (clip_trust = 1 OR clip_trust_override IS NOT NULL) ORDER BY clip_trust_at DESC LIMIT 200');
} catch (Throwable $e) {
}
?>
<?php if ($trustRows): ?>
<details class="panel" style="padding:0" id="trust">
    <summary class="panel__head" style="cursor:pointer"><h3>On 1 clip a day</h3><span class="badge"><?= count(array_filter($trustRows, fn($t) => $t['clip_trust_override'] === null ? (int)$t['clip_trust'] === 1 : (int)$t['clip_trust_override'] === 1)) ?> creators</span></summary>
    <div class="panel__body">
        <p class="small muted" style="margin-top:0">The website judges each creator's recent clips every few hours. They were told why and how to get back to <?= ready_clips_per_day() ?> a day.</p>
        <div style="overflow-x:auto"><table class="table"><thead><tr><th>Creator</th><th>Why</th><th>Decided by</th><th></th></tr></thead><tbody>
        <?php foreach ($trustRows as $t): ?>
            <tr><td><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$t['id'])) ?>"><?= e($t['full_name']) ?></a></td>
                <td class="small"><?= e((string)($t['clip_trust_why'] ?? '')) ?></td>
                <td class="small"><?= $t['clip_trust_override'] === null ? 'Website' : ((int)$t['clip_trust_override'] === 1 ? 'You: 1 a day' : 'You: normal') ?></td>
                <td><form method="post" class="flex" style="gap:6px"><?= csrf_field() ?><input type="hidden" name="action" value="trust_set"><input type="hidden" name="user_id" value="<?= (int)$t['id'] ?>"><input type="hidden" name="campaign_id" value="<?= $cid ?>">
                    <?php if ($t['clip_trust_override'] === null): ?><button class="btn btn--ghost btn--sm" name="trust" value="2" type="submit">Give normal back</button>
                    <?php else: ?><button class="btn btn--ghost btn--sm" name="trust" value="" type="submit">Let the website decide</button><?php endif; ?></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
    </div>
</details>
<?php endif; ?>

<div class="panel">
    <div class="panel__head"><h3>Campaign</h3></div>
    <div class="panel__body">
        <form method="get" class="flex" style="gap:8px;flex-wrap:wrap;align-items:center">
            <select class="input" name="campaign" onchange="this.form.submit()" style="max-width:420px">
                <?php foreach ($campaignList as $c): $ct = $counts[(int)$c['id']] ?? ['total' => 0, 'free' => 0]; ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $cid ? 'selected' : '' ?>><?= e($c['title']) ?><?= $c['status'] !== 'active' ? ' (ended)' : '' ?> · <?= (int)$ct['free'] ?> free of <?= (int)$ct['total'] ?></option>
                <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn--ghost btn--sm" type="submit">Open</button></noscript>
        </form>
        <?php if ($cid): ?>
        <p class="small" style="margin:10px 0 0"><strong><?= $nFree ?></strong> free to take · <strong><?= $nTaken ?></strong> taken, not posted yet · <strong><?= $nPosted ?></strong> posted</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($cid): ?>
<div class="panel">
    <div class="panel__head"><h3>Add clips to <?= e($campaignTitles[$cid]) ?></h3></div>
    <div class="panel__body">
        <div class="field">
            <label class="label" for="rcStyle">Style of these clips</label>
            <select class="input" id="rcStyle" style="max-width:320px">
                <option value="">Pick a style</option>
                <?php foreach (ready_clip_styles() as $st): ?><option value="<?= e($st) ?>"><?= e($st) ?></option><?php endforeach; ?>
                <option value="_new">New style...</option>
            </select>
            <input class="input" type="text" id="rcStyleNew" maxlength="40" placeholder="Name of the new style, for example Comedy skit" style="max-width:320px;margin-top:8px;display:none">
            <div class="field__hint">Creators see this on top of each clip and can show one style only. Upload different styles one at a time.</div>
        </div>
        <div class="field">
            <label class="label" for="rcFiles">Videos (pick many at once, up to 300 MB each)</label>
            <input class="input" type="file" id="rcFiles" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.m4v,.webm" multiple>
            <div class="field__hint">Picked a wrong one? Tap <strong>Remove</strong> next to it before uploading.</div>
        </div>
        <?php if (!$campOpen): ?><p class="flash flash--warning" style="margin:0 0 12px">This campaign is not taking clips now. Creators cannot take clips from it until you open it again.</p><?php endif; ?>
        <ul id="rcList" class="rc-list"></ul>
        <p class="small muted">Creators write their own captions (no text to copy: TikTok may treat pasted captions as a bot). Every caption and bio must TAG HPL, tapped from TikTok's list, which reaches us as "@<?= e(ready_tag_name()) ?>". Typed or pasted @<?= e(ready_tag_handle()) ?> is plain text and stops counting <?= ready_tag_strict_at() ? e(date('D j M, H:i', ready_tag_strict_at())) : 'once switched on' ?> (clips taken before tonight keep the old rule for their caption).</p>
        <div class="flex" style="gap:8px;flex-wrap:wrap">
            <button type="button" class="btn btn--primary" id="rcStart" disabled>Upload clips</button>
            <button type="button" class="btn btn--ghost" id="rcStop" style="display:none">Stop uploading</button>
        </div>
        <div id="rcProgress" class="small" style="margin-top:12px"></div>
        <form method="post" id="rcNotify" style="display:none"><?= csrf_field() ?><input type="hidden" name="action" value="notify"><input type="hidden" name="campaign_id" value="<?= $cid ?>"><input type="hidden" name="count" value="0"></form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>Clips</h3><span class="badge"><?= count($clips) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$clips): ?>
            <div class="empty"><div class="empty__icon">&#127916;</div><p>No ready clips for this campaign yet.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Clip</th><th>Title</th><th>Who has it</th><th>Posted</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($clips as $r): ?>
                    <tr<?= $r['is_active'] ? '' : ' style="opacity:.55"' ?>>
                        <?php $rHasPic = is_file(ready_clips_dir() . '/' . pathinfo(basename((string)$r['file_name']), PATHINFO_FILENAME) . '.jpg'); ?><td><video src="<?= e(u('admin/ready_clips.php?view=' . (int)$r['id'])) ?>#t=0.5" <?= $rHasPic ? 'poster="' . e(u('admin/ready_clips.php?poster=' . (int)$r['id'])) . '" preload="none"' : 'preload="metadata" data-nopic="' . (int)$r['id'] . '"' ?> controls muted playsinline style="width:90px;aspect-ratio:9/16;object-fit:cover;background:#111;border-radius:6px"></video><div class="small muted">#<?= (int)$r['id'] ?> · <?= number_format((float)$r['file_size'] / 1048576, 1) ?> MB</div></td>
                        <td>
                            <form method="post" style="display:grid;gap:6px;min-width:min(220px,100%);max-width:100%">
                                <?= csrf_field() ?><input type="hidden" name="action" value="caption"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="campaign_id" value="<?= $cid ?>">
                                <input class="input input--sm" name="title" value="<?= e($r['title']) ?>" placeholder="Title">
                                <select class="input input--sm" name="style" aria-label="Style" onchange="this.nextElementSibling.style.display = this.value === '_new' ? '' : 'none'">
                                    <option value="">No style</option>
                                    <?php $rStyles = ready_clip_styles(); if (($r['style'] ?? '') !== '' && !in_array($r['style'], $rStyles, true)) { $rStyles[] = $r['style']; } foreach ($rStyles as $st): ?><option value="<?= e($st) ?>"<?= ($r['style'] ?? '') === $st ? ' selected' : '' ?>><?= e($st) ?></option><?php endforeach; ?>
                                    <option value="_new">New style...</option>
                                </select>
                                <input class="input input--sm" name="style_new" maxlength="40" placeholder="New style name" style="display:none">
                                <button class="btn btn--ghost btn--sm" type="submit">Save</button>
                            </form>
                        </td>
                        <td class="small">
                            <?php if (!$r['is_active']): ?><span class="badge muted">Hidden</span>
                            <?php elseif ($r['claimed_by'] === null): ?><span class="badge ok">Free</span>
                            <?php else: ?><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$r['claimed_by'])) ?>"><?= e($r['full_name'] ?: 'Creator #' . $r['claimed_by']) ?></a><div class="muted">took it <?= e(time_ago($r['claimed_at'])) ?><?= $r['downloads'] ? ' · downloaded' : '' ?><?php if ($r['submission_id'] === null && $r['claimed_at']): ?><br>goes back <?= e(date('D j M, H:i', ready_clip_deadline($r['claimed_at']))) ?> if no link<?php endif; ?></div><?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if ($r['submission_id'] !== null && $r['posted_url']): ?>
                                <?= safe_link((string)$r['posted_url'], 'Open post', '') ?><?php if ((int)($r['sub_miss'] ?? 0) > 0): ?> <span class="badge warn">Not on TikTok now</span><?php endif; ?>
                                <div class="muted"><?= e(ucfirst((string)$r['sub_status'])) ?> · <?= e(num(max(0, (int)$r['views'] - (int)($r['start_views'] ?? $r['views'])))) ?> views · <?= e(money((float)$r['earned'])) ?></div>
                            <?php elseif ($r['claimed_by'] !== null): ?><span class="muted">Not yet</span>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <div class="flex" style="gap:6px;flex-wrap:wrap">
                                <?php if ($r['claimed_by'] !== null && ($r['submission_id'] === null || $r['sub_status'] === 'rejected' || $r['sub_status'] === null || (int)($r['sub_miss'] ?? 0) > 0)): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="release"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Take this clip back and make it free for other creators? Only do this if the creator did not post it.">Make free again</button></form>
                                <?php endif; ?>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $r['is_active'] ? 'Hide' : 'Show' ?></button></form>
                                <?php if ($r['submission_id'] === null): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="campaign_id" value="<?= $cid ?>"><button class="btn btn--danger btn--sm" type="submit" data-confirm="<?= $r['claimed_by'] !== null ? 'A creator took this clip. Delete it anyway? They will be told not to post it (no warning for them).' : 'Delete this clip for good?' ?>">Delete</button></form>
                                <?php endif; ?>
                            </div>
                            <?php if ($r['claimed_by'] === null && $r['submission_id'] === null): ?>
                            <div class="rc-change">
                                <button type="button" class="btn btn--ghost btn--sm rc-replace" data-id="<?= (int)$r['id'] ?>">Replace video</button>
                                <input type="file" class="rc-replace-file" data-id="<?= (int)$r['id'] ?>" accept="video/mp4,video/quicktime,video/webm,.mp4,.mov,.m4v,.webm" hidden>
                                <div class="small rc-replace-msg" data-id="<?= (int)$r['id'] ?>"></div>
                                <?php if (count($campaignList) > 1): ?>
                                <form method="post" class="flex" style="gap:6px;margin-top:6px"><?= csrf_field() ?><input type="hidden" name="action" value="move"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="campaign_id" value="<?= $cid ?>">
                                    <select class="input input--sm" name="to_campaign" aria-label="Move to campaign" style="max-width:170px">
                                        <option value="">Move to…</option>
                                        <?php foreach ($campaignList as $c): if ((int)$c['id'] === $cid) continue; ?><option value="<?= (int)$c['id'] ?>"><?= e($c['title']) ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn btn--ghost btn--sm" type="submit">Move</button>
                                </form>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<style>
.rc-list { list-style: none; margin: 0 0 12px; padding: 0; }
.rc-list li { display: flex; align-items: center; gap: 10px; justify-content: space-between; padding: 8px 10px; border: 1px solid var(--line, #e5e0d6); border-radius: 8px; margin-bottom: 6px; font-size: .9rem; }
.rc-list li span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.rc-list li.ok { border-color: #9fd5b3; background: #f1faf4; }
.rc-list li.bad { border-color: #f0b4ae; background: #fdf3f2; }
.rc-change { margin-top: 8px; }
</style>
<script>
(function(){
    var btn = document.getElementById('rcStart'), stopBtn = document.getElementById('rcStop'), input = document.getElementById('rcFiles'),
        out = document.getElementById('rcProgress'), list = document.getElementById('rcList');
    var csrf = <?= json_encode(csrf_token()) ?>, campaignId = <?= (int)$cid ?>, url = <?= json_encode(u('admin/ready_clips.php')) ?>;
    var CHUNK = <?= (int)$chunkBytes ?>; // fits this server's upload limit
    var picked = [], running = false, stopped = false;

    function mb(n){ return (n / 1048576).toFixed(1) + ' MB'; }
    // The style chosen for this upload ("New style..." uses the typed name). Upload waits until one is chosen.
    var styleSel = document.getElementById('rcStyle'), styleNew = document.getElementById('rcStyleNew');
    function pickedStyle(){ if (!styleSel) return 'none'; return styleSel.value === '_new' ? (styleNew.value || '').trim() : styleSel.value; }
    if (styleSel) {
        styleSel.addEventListener('change', function(){ styleNew.style.display = styleSel.value === '_new' ? '' : 'none'; if (styleSel.value === '_new') styleNew.focus(); draw(); });
        styleNew.addEventListener('input', function(){ draw(); });
    }
    // The files picked so far, each with a Remove button, so a wrong file can be dropped before uploading.
    function draw(){
        list.innerHTML = '';
        picked.forEach(function(it, i){
            var li = document.createElement('li'); li.className = it.state || '';
            var t = document.createElement('span'); t.textContent = (it.msg || it.file.name + ' · ' + mb(it.file.size));
            li.appendChild(t);
            if (!running && !it.state) {
                var x = document.createElement('button'); x.type = 'button'; x.className = 'btn btn--ghost btn--sm'; x.textContent = 'Remove';
                x.onclick = function(){ picked.splice(i, 1); draw(); };
                li.appendChild(x);
            }
            it.li = li; it.label = t;
            list.appendChild(li);
        });
        var waiting = picked.filter(function(it){ return !it.state; }).length;
        btn.disabled = running || waiting === 0 || pickedStyle() === '';
        btn.textContent = waiting > 0 && pickedStyle() === '' ? 'Pick a style first' : (waiting > 1 ? 'Upload ' + waiting + ' clips' : 'Upload clip');
    }
    function say(it, msg, state){ it.msg = msg; if (state) it.state = state; if (it.label) it.label.textContent = msg; if (state && it.li) it.li.className = state; }

    if (input) input.addEventListener('change', function(){
        Array.prototype.forEach.call(input.files || [], function(f){
            var dup = picked.some(function(it){ return !it.state && it.file.name === f.name && it.file.size === f.size; });
            if (!dup) picked.push({ file: f });
        });
        input.value = ''; // so the same file can be picked again after removing it
        out.textContent = '';
        draw();
    });

    function sendChunk(file, upId, i, total, extra){
        var fd = new FormData();
        fd.append('action', 'chunk'); fd.append('csrf', csrf); fd.append('campaign_id', campaignId);
        fd.append('upload_id', upId); fd.append('index', i); fd.append('total', total); fd.append('offset', i * CHUNK); fd.append('name', file.name);
        if (extra && extra.replace) fd.append('replace_id', extra.replace);
        else fd.append('style', pickedStyle());
        fd.append('chunk', file.slice(i * CHUNK, Math.min(file.size, (i + 1) * CHUNK)), 'part');
        if (i === total - 1 && file._poster) { fd.append('poster', file._poster, 'poster.jpg'); }
        return fetch(url, { method:'POST', credentials:'same-origin', body: fd }).then(function(r){ return r.json(); });
    }
    // Grab one picture from the clip (at 1 second) to show before play.
    function makePoster(file){
        return new Promise(function(resolve){
            var isUrl = typeof file === 'string';
            var v = document.createElement('video'), done = false, u = isUrl ? file : URL.createObjectURL(file);
            var finish = function(b){ if (done) return; done = true; if (!isUrl) URL.revokeObjectURL(u); v.removeAttribute('src'); try { v.load(); } catch (e) {} resolve(b); };
            v.muted = true; v.playsInline = true; v.preload = 'auto'; v.crossOrigin = 'anonymous'; v.src = u;
            var seekTo = function(){ try { v.currentTime = Math.min(1, (v.duration || 2) / 2); } catch (e) {} };
            v.addEventListener('loadedmetadata', seekTo);
            v.addEventListener('loadeddata', function(){ if (v.currentTime === 0) seekTo(); });
            v.addEventListener('seeked', function(){
                try {
                    var w = 540, h = Math.round(w * v.videoHeight / Math.max(1, v.videoWidth));
                    var c = document.createElement('canvas'); c.width = w; c.height = h;
                    c.getContext('2d').drawImage(v, 0, 0, w, h);
                    c.toBlob(function(b){ finish(b); }, 'image/jpeg', 0.8);
                } catch (e) { finish(null); }
            });
            v.addEventListener('error', function(){ finish(null); });
            setTimeout(function(){ finish(null); }, 30000); // big files on a slow computer need time; 8 seconds lost the picture
        });
    }
    // Clips that have no still picture (it could not be made when they were uploaded): make it now, one at a time,
    // from the video on the server, and save it. Creators then see a picture instead of a black box.
    (async function(){
        var list = [].slice.call(document.querySelectorAll('video[data-nopic]')).slice(0, 12);
        for (var i = 0; i < list.length; i++) {
            var el = list[i], id = el.getAttribute('data-nopic');
            // The page's own player already has the first frame (or soon will): draw from it. A second download of the
            // same big file from the same page was slow enough to time out on the live site.
            var blob = await (async function(){
                if (el.readyState < 2) { await new Promise(function(r){ el.addEventListener('loadeddata', r, { once: true }); el.preload = 'auto'; try { el.load(); } catch (e) {} setTimeout(r, 30000); }); }
                if (el.readyState < 2 || !el.videoWidth) return null;
                try {
                    var w = 540, h = Math.round(w * el.videoHeight / el.videoWidth), c = document.createElement('canvas'); c.width = w; c.height = h;
                    c.getContext('2d').drawImage(el, 0, 0, w, h);
                    return await new Promise(function(r){ c.toBlob(r, 'image/jpeg', 0.8); });
                } catch (e) { return null; }
            })();
            if (!blob) continue;
            var fd = new FormData(); fd.append('csrf', csrf); fd.append('action', 'poster'); fd.append('id', id); fd.append('campaign_id', campaignId); fd.append('poster', blob, 'poster.jpg');
            try { var r = await fetch(location.pathname, { method: 'POST', credentials: 'same-origin', body: fd }); var d = await r.json(); if (d.ok) { el.poster = URL.createObjectURL(blob); el.removeAttribute('data-nopic'); } } catch (e) {}
        }
    })();
    // Returns 'ok', 'stopped' or an error message.
    async function upload(file, show, extra){
        show('preparing…');
        file._poster = await makePoster(file);
        var total = Math.max(1, Math.ceil(file.size / CHUNK));
        var upId = Date.now().toString(36) + Math.random().toString(36).slice(2, 12);
        for (var i = 0; i < total; i++) {
            if (stopped) return 'stopped';
            var d = null;
            for (var tries = 0; tries < 3; tries++) {
                try { d = await sendChunk(file, upId, i, total, extra); break; } catch (e) { d = null; }
            }
            if (!d || !d.ok) return (d && d.error) || 'network error';
            if (d.done) return 'ok'; // the last piece arrived: the clip is saved, even if Stop was tapped meanwhile
            show(Math.round((i + 1) / total * 100) + '%');
        }
        return 'ok';
    }
    if (btn) btn.addEventListener('click', async function(){
        var todo = picked.filter(function(it){ return !it.state; });
        if (!todo.length) return;
        running = true; stopped = false; draw(); input.disabled = true;
        stopBtn.style.display = ''; out.textContent = '';
        window.onbeforeunload = function(){ return 'Uploads are still running.'; };
        var ok = 0, bad = 0;
        for (var k = 0; k < todo.length; k++) {
            var it = todo[k];
            if (stopped) { say(it, '– ' + it.file.name + ': not uploaded (stopped)', 'bad'); continue; }
            var r = await upload(it.file, function(m){ say(it, '… ' + it.file.name + ': ' + m); });
            if (r === 'ok') { ok++; say(it, '✓ ' + it.file.name, 'ok'); }
            else if (r === 'stopped') { say(it, '– ' + it.file.name + ': stopped, not added', 'bad'); }
            else { bad++; say(it, '✗ ' + it.file.name + ': ' + r, 'bad'); }
        }
        window.onbeforeunload = null; running = false; stopBtn.style.display = 'none'; input.disabled = false;
        var f = document.getElementById('rcNotify');
        f.querySelector('[name=count]').value = ok;
        if (ok > 0 && !bad && !stopped) { f.submit(); return; }
        // Keep failed or stopped files on screen; finishing tells creators about the ones that did upload.
        out.innerHTML = '';
        var p = document.createElement('p'); p.textContent = ok + ' of ' + todo.length + ' uploaded.' + (ok ? ' Clips that did not upload were not added.' : ''); out.appendChild(p);
        if (ok > 0) {
            var fin = document.createElement('button'); fin.type = 'button'; fin.className = 'btn btn--primary btn--sm'; fin.textContent = 'Finish';
            fin.onclick = function(){ f.submit(); }; out.appendChild(fin);
        }
        picked = picked.filter(function(it){ return it.state === 'bad'; }).map(function(it){ return { file: it.file }; });
        draw();
    });
    if (stopBtn) stopBtn.addEventListener('click', function(){
        // Stops after the piece being sent now (a few seconds), so nothing is half-saved.
        stopped = true;
        stopBtn.style.display = 'none'; out.textContent = 'Stopping…';
    });

    // Replace the video of a clip nobody has taken yet (keeps its number and title).
    document.querySelectorAll('.rc-replace').forEach(function(b){
        var id = b.getAttribute('data-id');
        var fi = document.querySelector('.rc-replace-file[data-id="' + id + '"]'), msg = document.querySelector('.rc-replace-msg[data-id="' + id + '"]');
        b.addEventListener('click', function(){ if (!running) fi.click(); });
        fi.addEventListener('change', async function(){
            var file = fi.files && fi.files[0]; fi.value = '';
            if (!file || running) return;
            if (!confirm('Replace the video of clip #' + id + ' with ' + file.name + '?')) return;
            running = true; stopped = false; b.disabled = true; draw();
            window.onbeforeunload = function(){ return 'Upload still running.'; };
            var r = await upload(file, function(m){ msg.textContent = 'Uploading: ' + m; }, { replace: id });
            window.onbeforeunload = null; running = false; b.disabled = false; draw();
            if (r === 'ok') { msg.textContent = '✓ Video replaced.'; setTimeout(function(){ location.reload(); }, 600); }
            else { msg.textContent = '✗ ' + r; }
        });
    });
    draw();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
