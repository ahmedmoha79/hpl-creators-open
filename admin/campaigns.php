<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/campaign_help.php';
$user = require_admin();
$pageTitle = 'Campaigns';

function campaign_upload_image(string $field): array
{
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return [null, null];
    }
    $err = (int)($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return [null, 'Image is too large. Please use one under 6 MB.'];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return [null, 'Image upload failed. Please try again.'];
    }
    $tmp = $_FILES[$field]['tmp_name'] ?? '';
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return [null, 'Invalid image upload.'];
    }
    if ((int)($_FILES[$field]['size'] ?? 0) > 6 * 1024 * 1024) {
        return [null, 'Image is too large. Please use one under 6 MB.'];
    }
    $info = @getimagesize($tmp);
    $mime = $info['mime'] ?? '';
    $extMap = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    if (!isset($extMap[$mime])) {
        return [null, 'Use a JPG, PNG, WebP or GIF image.'];
    }
    $dir = UPLOAD_PATH . '/campaigns';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return [null, 'Upload folder is not writable.'];
    }
    $name = 'campaign-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    if (!@move_uploaded_file($tmp, $dir . '/' . $name)) {
        return [null, 'Could not save the image. Check folder permissions.'];
    }
    return [u('uploads/campaigns/' . $name), null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'create') {
        $title = post('title');
        $desc = mb_substr(post('description'), 0, 2000);
        [$bannerUrl, $bannerError] = campaign_upload_image('banner_file');
        $ownerName = mb_substr(post('owner_name'), 0, 200);
        [$ownerAvatarUrl, $avatarError] = campaign_upload_image('owner_avatar_file');
        $ownerVerified = post('owner_verified') === '1' ? 1 : 0;
        $tag = mb_substr(post('tag'), 0, 100);
        $totalBudget = (float)post('total_budget');
        $paidAmount = 0.0; // the amount used is never typed in; it is always worked out from what creators earned
        $currency = mb_substr(post('currency') ?: 'K', 0, 10);
        [$platforms, $platformsError] = campaign_platforms_from_form();
        $capRaw = trim((string)post('creator_cap'));
        $creatorCap = $capRaw === '' ? null : max(0.0, round((float)$capRaw, 2));
        // reference links: "label|url" per line
        $refsRaw = trim(post('reference_links'));
        $refs = [];
        foreach (preg_split('/\r?\n/', $refsRaw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (strpos($line, '|') !== false) {
                [$lbl, $u] = array_map('trim', explode('|', $line, 2));
                $refs[] = ['label' => $lbl, 'url' => $u, 'provider' => 'link'];
            } else {
                $refs[] = ['label' => $line, 'url' => $line, 'provider' => 'link'];
            }
        }
        // requirements: one per line
        $reqsRaw = trim(post('requirements'));
        $reqs = [];
        foreach (preg_split('/\r?\n/', $reqsRaw) as $line) {
            $line = trim($line);
            if ($line !== '') $reqs[] = $line;
        }
        $reqUrlRaw = trim(post('requirements_url'));
        $reqUrl = $reqUrlRaw === '' ? null : normalize_url($reqUrlRaw);
        $styledRaw = trim(post('styled_videos_url'));
        $styledUrl = $styledRaw === '' ? null : normalize_url($styledRaw);
        $campStatus = post('camp_status') === 'closed' ? 'closed' : 'accepting';
        $status = post('status') === 'ended' ? 'ended' : 'active';

        if ($title === '') {
            flash('error', 'Title is required.');
        } elseif ($bannerError || $avatarError) {
            flash('error', $bannerError ?: $avatarError);
        } elseif ($platformsError) {
            flash('error', $platformsError);
        } else {
            q('INSERT INTO campaigns (title, description, banner_url, owner_name, owner_avatar_url, owner_verified, tag, total_budget, paid_amount, creator_cap, currency, platforms, reference_links, requirements, requirements_url, styled_videos_url, camp_status, status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $title, $desc, $bannerUrl, $ownerName ?: 'HPL Admin', $ownerAvatarUrl, $ownerVerified, $tag ?: null,
                $totalBudget, $paidAmount, $creatorCap, $currency,
                json_encode($platforms), json_encode($refs), json_encode($reqs), $reqUrl, $styledUrl,
                $campStatus, $status
            ]);
            flash('success', 'Campaign created.');
        }
    } elseif ($action === 'update') {
        $id = (int)post('id');
        $title = post('title');
        $desc = mb_substr(post('description'), 0, 2000);
        [$bannerUpload, $bannerError] = campaign_upload_image('banner_file');
        $ownerName = mb_substr(post('owner_name'), 0, 200);
        [$ownerAvatarUpload, $avatarError] = campaign_upload_image('owner_avatar_file');
        $ownerVerified = post('owner_verified') === '1' ? 1 : 0;
        $currentCampaign = fetch_one('SELECT banner_url, owner_avatar_url FROM campaigns WHERE id = ?', [$id]);
        $bannerUrl = $bannerUpload ?: ($currentCampaign['banner_url'] ?? null);
        $ownerAvatarUrl = $ownerAvatarUpload ?: ($currentCampaign['owner_avatar_url'] ?? null);
        $tag = mb_substr(post('tag'), 0, 100);
        $totalBudget = (float)post('total_budget');
        // Amount used = what creators have actually earned from this campaign (cannot drift or be overwritten by a stale form).
        $paidBefore = array_key_exists('paid_before', $_POST) ? max(0.0, round((float)post('paid_before'), 2)) : null; // null = form without the field: keep what is saved
        $paidBeforeNote = mb_substr(trim((string)post('paid_before_note')), 0, 255);
        $currency = mb_substr(post('currency') ?: 'K', 0, 10);
        [$platforms, $platformsError] = campaign_platforms_from_form();
        $capRaw = trim((string)post('creator_cap'));
        $creatorCap = $capRaw === '' ? null : max(0.0, round((float)$capRaw, 2));
        $refsRaw = trim(post('reference_links'));
        $refs = [];
        foreach (preg_split('/
?
/', $refsRaw) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (strpos($line, '|') !== false) { [$lbl,$u]=array_map('trim',explode('|',$line,2)); $refs[]=['label'=>$lbl,'url'=>$u,'provider'=>'link']; }
            else { $refs[]=['label'=>$line,'url'=>$line,'provider'=>'link']; }
        }
        $reqsRaw = trim(post('requirements'));
        $reqs = [];
        foreach (preg_split('/
?
/', $reqsRaw) as $line) { $line=trim($line); if ($line!=='') $reqs[]=$line; }
        $reqUrlRaw = trim(post('requirements_url'));
        $reqUrl = $reqUrlRaw === '' ? null : normalize_url($reqUrlRaw);
        $styledRaw = trim(post('styled_videos_url'));
        $styledUrl = $styledRaw === '' ? null : normalize_url($styledRaw);
        $campStatus = post('camp_status') === 'closed' ? 'closed' : 'accepting';
        $status = post('status') === 'ended' ? 'ended' : 'active';
        if ($title === '' || $id <= 0) {
            flash('error', 'Title and id required.');
        } elseif ($bannerError || $avatarError) {
            flash('error', $bannerError ?: $avatarError);
        } elseif ($platformsError) {
            flash('error', $platformsError);
        } else {
            // Locked, so a view check running at the same moment cannot be overwritten; the used
            // budget is then recounted from what creators earned plus anything paid before.
            hpl_tx(function () use ($id, $title, $desc, $bannerUrl, $ownerName, $ownerAvatarUrl, $ownerVerified, $tag, $totalBudget, $creatorCap, $currency, $platforms, $refs, $reqs, $reqUrl, $styledUrl, $campStatus, $status, $paidBefore, $paidBeforeNote) {
                fetch_one('SELECT id FROM campaigns WHERE id = ? FOR UPDATE', [$id]);
                q('UPDATE campaigns SET title=?,description=?,banner_url=?,owner_name=?,owner_avatar_url=?,owner_verified=?,tag=?,total_budget=?,creator_cap=?,currency=?,platforms=?,reference_links=?,requirements=?,requirements_url=?,styled_videos_url=?,camp_status=?,status=? WHERE id=?', [
                    $title, $desc, $bannerUrl, $ownerName ?: 'HPL Admin', $ownerAvatarUrl, $ownerVerified, $tag ?: null,
                    $totalBudget, $creatorCap, $currency,
                    json_encode($platforms), json_encode($refs), json_encode($reqs), $reqUrl, $styledUrl,
                    $campStatus, $status, $id
                ]);
                if ($paidBefore !== null && hpl_column_exists('campaigns', 'paid_before')) {
                    q('UPDATE campaigns SET paid_before = ?, paid_before_note = ? WHERE id = ?', [$paidBefore, $paidBeforeNote !== '' ? $paidBeforeNote : null, $id]);
                }
                campaign_recalc_paid($id);
            });
            // Apply the new terms (rate, minimum, cap, budget) to the clips now, not at the next views check.
            require_once __DIR__ . '/../includes/view_fetcher.php';
            $recount = 0.0;
            foreach (fetch_all('SELECT id, views FROM campaign_submissions WHERE campaign_id = ? AND status IN ("pending","approved") AND start_views IS NOT NULL', [$id]) as $cs) {
                $recount += credit_campaign_submission((int)$cs['id'], (int)$cs['views']);
            }
            flash('success', 'Campaign updated.' . ($recount > 0 ? ' ' . money($recount) . ' was added to creators under the new terms.' : ''));
        }
    } elseif ($action === 'toggle') {
        $c = fetch_one('SELECT status FROM campaigns WHERE id = ?', [(int)post('id')]);
        if ($c) {
            $new = $c['status'] === 'active' ? 'ended' : 'active';
            q('UPDATE campaigns SET status = ? WHERE id = ?', [$new, (int)post('id')]);
            flash('success', 'Status updated.');
        }
    } elseif ($action === 'toggle_accept') {
        $c = fetch_one('SELECT camp_status FROM campaigns WHERE id = ?', [(int)post('id')]);
        if ($c) {
            $new = $c['camp_status'] === 'accepting' ? 'closed' : 'accepting';
            q('UPDATE campaigns SET camp_status = ? WHERE id = ?', [$new, (int)post('id')]);
            flash('success', 'Acceptance updated.');
        }
    } elseif ($action === 'submission_status') {
        $sid = (int)post('submission_id');
        $st = post('submission_status');
        if ($sid > 0 && campaign_submission_set_status($sid, (string)$st)) {
            flash('success', $st === 'rejected' ? 'Clip rejected. Its earnings were removed from the creator and returned to the campaign budget.' . campaign_debt_note($sid) : 'Submission status updated.');
        }
    } elseif ($action === 'submission_update') {
        $sid = (int)post('submission_id');
        $views = max(0, (int)post('views'));
        $earned = max(0, round((float)post('earned'), 2));
        $cur = fetch_one('SELECT views, earned FROM campaign_submissions WHERE id = ?', [$sid]);
        if ($cur && abs($earned - (float)$cur['earned']) < 0.005 && $views >= (int)$cur['views']) {
            // Only the views were raised: pay the new views at the campaign's rate, like a normal views check
            // (before, typing views here marked them as paid without paying them).
            require_once __DIR__ . '/../includes/view_fetcher.php';
            $paid = credit_campaign_submission($sid, $views);
            flash('success', 'Views saved: ' . num($views) . '.' . ($paid > 0 ? ' ' . money($paid) . ' added for the new views.' : ''));
        } elseif ($cur && campaign_submission_admin_update($sid, $views, $earned)) {
            flash('success', 'Submission updated. The campaign budget was recounted.' . campaign_debt_note($sid));
        }
    } elseif ($action === 'submission_delete') {
        $sid = (int)post('submission_id');
        $owner = (int)fetch_val('SELECT user_id FROM campaign_submissions WHERE id = ?', [$sid], 0);
        campaign_submission_delete($sid);
        flash('warning', 'Submission deleted. Its earnings were removed from the creator and returned to the campaign budget.' . ($owner ? campaign_debt_note(0, $owner) : ''));
    } elseif ($action === 'delete') {
        $cid = (int)post('id');
        $hasMoney = (float)fetch_val('SELECT COALESCE(SUM(earned),0) + COALESCE(SUM(accrued),0) FROM campaign_submissions WHERE campaign_id = ? AND status <> "rejected"', [$cid], 0) > 0
            || (float)fetch_val('SELECT paid_amount FROM campaigns WHERE id = ?', [$cid], 0) > 0;
        if ($hasMoney) {
            flash('error', 'Creators have earned money in this campaign (paid or still building up), so it cannot be deleted: their money would disappear. Press End instead.');
            redirect('admin/campaigns.php');
        }
        // Its ready clips go too (files and rows): nobody can post them any more.
        try {
            require_once __DIR__ . '/../includes/ready_clips.php';
            foreach (fetch_all('SELECT file_name FROM ready_clips WHERE campaign_id = ?', [(int)post('id')]) as $rc) {
                ready_clip_delete_files((string)$rc['file_name']);
            }
            q('DELETE FROM ready_clips WHERE campaign_id = ?', [(int)post('id')]);
        } catch (Throwable $e) {}
        q('DELETE FROM campaign_joins WHERE campaign_id = ?', [(int)post('id')]);
        q('DELETE FROM campaign_submissions WHERE campaign_id = ?', [(int)post('id')]);
        q('DELETE FROM campaigns WHERE id = ?', [(int)post('id')]);
        flash('warning', 'Campaign deleted.');
    }
    redirect('admin/campaigns.php');
}

$editId = (int)get('edit');
$editCampaign = null;
if ($editId > 0) {
    $editCampaign = fetch_one('SELECT * FROM campaigns WHERE id = ?', [$editId]);
}
$campaigns = fetch_all('SELECT * FROM campaigns ORDER BY created_at DESC');
// Clips waiting for a decision first: their money is locked until approved.
$submissions = fetch_all('SELECT s.*, u.full_name, c.title AS campaign_title FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id ORDER BY (s.status = "pending") DESC, s.submitted_at DESC LIMIT 200');

require __DIR__ . '/../includes/admin_header.php';
?>
<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Campaigns</h1>
        <p class="muted mb-0">Create and manage campaigns shown to creators.</p>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel__head"><?php if ($editCampaign): ?><h3>Edit campaign</h3><?php else: ?><h3>New campaign</h3><?php endif; ?></div>
    <div class="panel__body">
        <form method="post" class="form-grid" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editCampaign ? 'update' : 'create' ?>">
            <?php if ($editCampaign): ?><input type="hidden" name="id" value="<?= (int)$editCampaign['id'] ?>"><?php endif; ?>
            <div class="field">
                <label class="label" for="title">Title</label>
                <input class="input" type="text" id="title" name="title" required value="<?= e($editCampaign['title'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="label" for="owner_name">Owner name</label>
                <input class="input" type="text" id="owner_name" name="owner_name" placeholder="HPL Admin" value="<?= e($editCampaign['owner_name'] ?? '') ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="description">Description</label>
                <textarea class="textarea" id="description" name="description" rows="2"><?= e($editCampaign['description'] ?? '') ?></textarea>
            </div>
            <div class="field">
                <label class="label" for="banner_file">Campaign image</label>
                <input class="input" type="file" id="banner_file" name="banner_file" accept="image/jpeg,image/png,image/webp,image/gif">
                <p class="small muted" style="margin:6px 0 0">JPG, PNG, WebP or GIF up to 6 MB.</p>
                <img class="upload-preview" id="bannerPreview" alt="Campaign image preview" <?= empty($editCampaign['banner_url']) ? 'hidden' : '' ?> <?= !empty($editCampaign['banner_url']) ? 'src="' . e($editCampaign['banner_url']) . '"' : '' ?>>
            </div>
            <div class="field">
                <label class="label" for="owner_avatar_file">Owner avatar</label>
                <input class="input" type="file" id="owner_avatar_file" name="owner_avatar_file" accept="image/jpeg,image/png,image/webp,image/gif">
                <p class="small muted" style="margin:6px 0 0">Square JPG, PNG, WebP or GIF up to 6 MB.</p>
                <img class="upload-preview upload-preview--round" id="ownerAvatarPreview" alt="Owner avatar preview" <?= empty($editCampaign['owner_avatar_url']) ? 'hidden' : '' ?> <?= !empty($editCampaign['owner_avatar_url']) ? 'src="' . e($editCampaign['owner_avatar_url']) . '"' : '' ?>>
            </div>
            <div class="field">
                <label class="label" for="tag">Tag (chip)</label>
                <input class="input" type="text" id="tag" name="tag" placeholder="facebook" value="<?= e($editCampaign['tag'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="label" for="owner_verified">Verified?</label>
                <select class="select" id="owner_verified" name="owner_verified">
                    <option value="1" <?= (isset($editCampaign['owner_verified']) && $editCampaign['owner_verified']) ? 'selected' : '' ?>>Yes</option>
                    <option value="0" <?= (isset($editCampaign['owner_verified']) && !$editCampaign['owner_verified']) ? 'selected' : '' ?>>No</option>
                </select>
            </div>
            <div class="field">
                <label class="label" for="total_budget">Total budget</label>
                <input class="input" type="number" step="0.01" id="total_budget" name="total_budget" value="<?= e($editCampaign['total_budget'] ?? 0) ?>">
            </div>
            <div class="field">
                <span class="label">Used so far</span>
                <?php $paidBeforeNow = (float)($editCampaign['paid_before'] ?? 0); $usedNow = $editCampaign ? (float)fetch_val('SELECT COALESCE(SUM(earned),0) FROM campaign_submissions WHERE campaign_id = ?', [(int)$editCampaign['id']], 0) + $paidBeforeNow : 0.0; $budgetNow = (float)($editCampaign['total_budget'] ?? 0); ?>
                <div class="input" style="background:var(--ui-sunken);display:flex;align-items:center"><?= e(money($usedNow)) ?><?php if ($budgetNow > 0): ?>&nbsp;<span class="muted small">&middot; <?= e(money(max(0, $budgetNow - $usedNow))) ?> left</span><?php endif; ?></div>
                <div class="field__hint">Worked out automatically from what creators have earned in this campaign<?= $paidBeforeNow > 0 ? ', plus ' . e(money($paidBeforeNow)) . ' already paid outside the campaign' : '' ?>. The budget goes down the moment views are credited.</div>
            </div>
<?php if ($editCampaign && array_key_exists('paid_before', $editCampaign)): ?>
            <div class="field">
                <label class="label" for="paid_before">Already paid outside this campaign (<?= e(currency()) ?>)</label>
                <input class="input" type="number" step="0.01" min="0" id="paid_before" name="paid_before" inputmode="decimal" value="<?= $paidBeforeNow > 0 ? e(number_format($paidBeforeNow, 2, '.', '')) : '' ?>" placeholder="0.00">
                <div class="field__hint">Money a creator earned from this campaign's footage before it was tracked here (for example through "My clips"). It is taken off the budget. Leave empty if none.</div>
            </div>
            <div class="field">
                <label class="label" for="paid_before_note">Who it was paid to</label>
                <input class="input" type="text" maxlength="255" id="paid_before_note" name="paid_before_note" value="<?= e($editCampaign['paid_before_note'] ?? '') ?>" placeholder="e.g. mubinga, 4 clips">
            </div>
<?php endif; ?>
            <div class="field">
                <label class="label" for="currency">Currency</label>
                <input class="input" type="text" id="currency" name="currency" value="<?= e($editCampaign['currency'] ?? 'K') ?>">
            </div>
            <div class="field">
                <label class="label" for="camp_status">Accepting clips?</label>
                <select class="select" id="camp_status" name="camp_status">
                    <option value="accepting" <?= (($editCampaign['camp_status'] ?? 'accepting') === 'accepting') ? 'selected' : '' ?>>Accepting</option>
                    <option value="closed" <?= (($editCampaign['camp_status'] ?? '') === 'closed') ? 'selected' : '' ?>>Closed</option>
                </select>
            </div>
            <div class="field">
                <label class="label" for="status">Status</label>
                <select class="select" id="status" name="status">
                    <option value="active" <?= (($editCampaign['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>Active</option>
                    <option value="ended" <?= (($editCampaign['status'] ?? '') === 'ended') ? 'selected' : '' ?>>Ended</option>
                </select>
            </div>
<?php
    $cpList = $editCampaign ? (json_decode($editCampaign['platforms'] ?: '[]', true) ?: []) : [];
    $cpFirst = $cpList[0] ?? [];
    $cpRate = (float)($cpFirst['perThousandViews'] ?? 0);
    $cpKeys = $cpList ? array_column($cpList, 'key') : campaign_platform_keys();
?>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="camp_rate">Pay per 1,000 views (<?= e(currency()) ?>)</label>
                <input class="input" type="number" step="0.01" min="0" id="camp_rate" name="camp_rate" inputmode="decimal" value="<?= $cpRate > 0 ? e(number_format($cpRate, 2, '.', '')) : '' ?>" placeholder="Standard: <?= e(number_format(rate_per_1000(), 2)) ?>">
                <div class="field__hint">Leave empty to pay the standard rate (<?= e(money(rate_per_1000())) ?>, set in Settings). Type a number to give this campaign its own price, for example 7 for <?= e(currency()) ?>7 per 1,000 views.</div>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="creator_cap">Max one creator can earn from this campaign (<?= e(currency()) ?>)</label>
                <input class="input" type="number" step="0.01" min="0" id="creator_cap" name="creator_cap" inputmode="decimal" value="<?= ($editCampaign && $editCampaign['creator_cap'] !== null) ? e(number_format((float)$editCampaign['creator_cap'], 2, '.', '')) : '' ?>" placeholder="Default: <?= e(number_format(campaign_creator_cap_default(), 2)) ?>">
                <div class="field__hint">Each creator can earn up to this much from this campaign, across all their clips. Views after that earn nothing here, so the budget reaches more creators. Leave empty for the default (<?= e(money(campaign_creator_cap_default())) ?>, set in Settings). 0 = no limit.</div>
            </div>
            <div class="field">
                <label class="label" for="camp_min">Minimum per clip (<?= e(currency()) ?>)</label>
                <input class="input" type="number" step="0.01" min="0" id="camp_min" name="camp_min" inputmode="decimal" value="<?= (float)($cpFirst['minPayout'] ?? 0) > 0 ? e(number_format((float)$cpFirst['minPayout'], 2, '.', '')) : '' ?>" placeholder="None">
                <div class="field__hint">A clip pays nothing until it has earned this much. Empty = no minimum.</div>
            </div>
            <div class="field">
                <label class="label" for="camp_max">Cap per clip (<?= e(currency()) ?>)</label>
                <input class="input" type="number" step="0.01" min="0" id="camp_max" name="camp_max" inputmode="decimal" value="<?= (float)($cpFirst['maxPayout'] ?? 0) > 0 ? e(number_format((float)$cpFirst['maxPayout'], 2, '.', '')) : '' ?>" placeholder="No cap">
                <div class="field__hint">The most one clip can earn. Empty = no cap.</div>
            </div>
            <div class="field" style="grid-column:1/-1">
                <span class="label">Platforms</span>
                <div class="flex" style="gap:8px;flex-wrap:wrap">
                    <?php foreach (campaign_platform_keys() as $pk): ?>
                        <label class="btn btn--ghost btn--sm" style="gap:6px;cursor:pointer"><input type="checkbox" name="camp_plats[]" value="<?= e($pk) ?>" <?= in_array($pk, $cpKeys, true) ? 'checked' : '' ?>> <?= e(platform_label($pk)) ?></label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="reference_links">Reference links (one per line: label|url)</label>
                <textarea class="textarea" id="reference_links" name="reference_links" rows="3" placeholder="Google Drive - Raw footage|https://drive.google.com"><?php
if ($editCampaign) {
    $rr = json_decode($editCampaign['reference_links'] ?: '[]', true) ?: [];
    $lines = [];
    foreach ($rr as $r) { $lines[] = ($r['label'] ?? '') . '|' . ($r['url'] ?? ''); }
    echo e(implode("
", $lines));
}
?></textarea>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="requirements">Content requirements (one per line)</label>
                <textarea class="textarea" id="requirements" name="requirements" rows="3" placeholder="Minimum video length 10s"><?php
if ($editCampaign) {
    $rrr = json_decode($editCampaign['requirements'] ?: '[]', true) ?: [];
    echo e(implode("
", $rrr));
}
?></textarea>
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="requirements_url">Requirements doc URL (Google Docs)</label>
                <input class="input" type="url" id="requirements_url" name="requirements_url" placeholder="https://docs.google.com/..." value="<?= e($editCampaign['requirements_url'] ?? '') ?>">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="styled_videos_url">Styled videos URL (company styled videos)</label>
                <input class="input" type="url" id="styled_videos_url" name="styled_videos_url" placeholder="https://..." value="<?= e($editCampaign['styled_videos_url'] ?? '') ?>">
            </div>
            <div class="form-actions" style="grid-column:1/-1">
                <button class="btn btn--primary" type="submit"><?= $editCampaign ? 'Update campaign' : 'Create campaign' ?></button>
                <?php if ($editCampaign): ?><a class="btn btn--ghost" href="<?= e(u('admin/campaigns.php')) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>All campaigns</h3><span class="badge"><?= count($campaigns) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$campaigns): ?>
            <div class="empty"><p class="muted">No campaigns yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Title</th><th>Pays</th><th>Budget</th><th>Accepting</th><th>Status</th><th>Created</th><th></th></tr></thead>
                    <tbody>
                    <?php $footCounts = campaign_footage_counts(); foreach ($campaigns as $c): $fcount = $footCounts[(int)$c['id']] ?? 0; ?>
                        <tr>
                            <td><strong><?= e($c['title']) ?></strong><br><span class="muted small"><?= e($c['owner_name']) ?></span><br>
                                <?php if ($fcount > 0): ?><a class="small" href="<?= e(u('admin/footage.php')) ?>"><?= icon('film') ?> <?= $fcount ?> footage <?= $fcount === 1 ? 'video' : 'videos' ?></a>
                                <?php else: ?><a class="badge warn" href="<?= e(u('admin/footage.php?campaign=' . (int)$c['id'])) ?>" title="Creators who join have nothing to download">No footage, add it</a><?php endif; ?>
                            </td>
                            <td class="small"><?php $cr = campaign_best_rate($c); $own = (bool)array_filter(campaign_platform_list($c), function ($p) { return $p['own_rate']; }); ?><strong><?= e(money($cr)) ?></strong> / 1K<br><span class="muted"><?= $own ? 'Campaign price' : 'Standard rate' ?></span><?php $ccap = campaign_creator_cap($c); ?><br><span class="muted">Max <?= $ccap > 0 ? e(money($ccap)) : 'unlimited' ?> per creator</span></td>
                            <td><?php if ((float)$c['total_budget'] <= 0): ?><span class="badge warn" title="Creators cannot submit clips until a budget is set">No budget set</span><?php else: ?><?= e($c['currency']) ?><?= number_format((float)$c['total_budget'],2) ?><?php endif; ?></td>
                            <td><span class="badge <?= $c['camp_status']==='accepting'?'ok':'muted' ?>"><?= e(ucfirst($c['camp_status'])) ?></span></td>
                            <td><span class="badge <?= $c['status']==='active'?'ok':'muted' ?>"><?= e(ucfirst($c['status'])) ?></span></td>
                            <td class="small muted"><?= e(time_ago($c['created_at'])) ?></td>
                            <td>
                                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/campaigns.php?edit='.(int)$c['id'])) ?>">Edit</a>
                                <a class="btn btn--ghost btn--sm" href="<?= e(u('creator/campaign.php?id='.(int)$c['id'])) ?>" target="_blank">View</a>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_accept">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= $c['camp_status']==='accepting'?'Close':'Open' ?></button>
                                </form>
                                <form method="post" style="display:inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="btn btn--ghost btn--sm" type="submit"><?= $c['status']==='active'?'End':'Reactivate' ?></button>
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

<div class="panel" id="submissions">
    <div class="panel__head"><h3>Campaign submissions</h3><span class="badge"><?= e(num(count($submissions))) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$submissions): ?>
            <div class="empty"><p class="muted mb-0">No clips submitted to campaigns yet.</p></div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>Creator</th><th>Campaign</th><th>Platform</th><th>Link</th><th class="num">Views counted</th><th class="num">Earned</th><th>Status</th><th>Submitted</th><th>Actions</th></tr></thead>
                    <tbody>
                    <?php foreach ($submissions as $s): ?>
                        <tr>
                            <td><a class="small" href="<?= e(u('admin/creator_detail.php?id=' . (int)$s['user_id'])) ?>"><?= e($s['full_name']) ?></a></td>
                            <td class="small"><?= e($s['campaign_title']) ?></td>
                            <td><span class="flex"><?= platform_icon($s['platform']) ?> <span class="small"><?= e(platform_label($s['platform'])) ?></span></span></td>
                            <?php
                                // The clip's picture: TikTok's own cover while its address is fresh, else our still picture of the ready clip.
                                $sPic = (!empty($s['cover_url']) && !empty($s['cover_at']) && strtotime((string)$s['cover_at']) > time() - 5 * 3600) ? (string)$s['cover_url'] : (!empty($s['ready_clip_id']) ? u('admin/ready_clips.php?poster=' . (int)$s['ready_clip_id']) : '');
                            ?>
                            <td class="truncate"><?php if ($sPic !== ''): ?><a href="<?= e((string)$s['url']) ?>" target="_blank" rel="noopener"><img src="<?= e($sPic) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()" style="width:44px;height:78px;object-fit:cover;border-radius:6px;background:#111;float:left;margin-right:10px"></a><?php endif; ?><?= safe_link((string)$s['url']) ?><?php if (!empty($s['ready_clip_id'])): ?><div class="small"><a href="<?= e(u('admin/ready_clips.php?campaign=' . (int)$s['campaign_id'])) ?>">Ready clip #<?= (int)$s['ready_clip_id'] ?></a> <span class="muted">(check it is this clip, posted as it is with no music or sound added over it)</span></div><?php endif; ?></td>
                            <?php $startV = $s['start_views'] ?? null; ?>
                            <td class="num"><?= e(num(max(0, (int)$s['views'] - (int)($startV ?? $s['views'])))) ?><?php if ($startV === null): ?><div class="small muted">not read yet</div><?php elseif ((int)$startV > 0): ?><div class="small muted">had <?= e(num($startV)) ?> when sent</div><?php endif; ?></td>
                            <td class="num"><?= money($s['earned']) ?></td>
                            <td><span class="badge <?= status_class($s['status']) ?>"><?= e($s['status']) ?></span><?php if (!empty($s['auto_check'])): ?><div class="small <?= $s['auto_check'] === 'pass' ? '' : 'muted' ?>" title="<?= e((string)($s['auto_note'] ?? '')) ?>"><?= $s['auto_check'] === 'pass' ? 'System check passed' : 'Check by hand: ' . e((string)($s['auto_note'] ?? '')) ?></div><?php endif; ?></td>
                            <td class="small muted"><?= e(time_ago($s['submitted_at'])) ?></td>
                            <td>
                                <div class="flex" style="gap:6px;flex-wrap:wrap">
                                    <?php if ($s['status'] !== 'approved'): ?>
                                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="submission_status"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="submission_status" value="approved"><button class="btn btn--ok btn--sm" type="submit">Approve</button></form>
                                    <?php endif; ?>
                                    <?php if ($s['status'] !== 'rejected'): ?>
                                        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="submission_status"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>"><input type="hidden" name="submission_status" value="rejected"><button class="btn btn--ghost btn--sm" type="submit">Reject</button></form>
                                    <?php endif; ?>
                                    <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="submission_delete"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Delete this submission?">Delete</button></form>
                                </div>
                                <details class="mt-1">
                                    <summary class="small">Edit views / earnings</summary>
                                    <form method="post" class="mt-1 flex" style="gap:6px;flex-wrap:wrap">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="submission_update">
                                        <input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>">
                                        <input class="input input--sm" type="number" name="views" min="0" value="<?= (int)$s['views'] ?>" style="width:110px" title="Total views on the platform now (not views counted)" placeholder="Total views">
                                        <input class="input input--sm" type="number" name="earned" min="0" step="0.01" value="<?= e(number_format((float)$s['earned'], 2, '.', '')) ?>" style="width:110px" title="Earned (money)" placeholder="Earned">
                                        <button class="btn btn--ghost btn--sm" type="submit">Save</button>
                                        <span class="small muted" style="flex-basis:100%">Left box: total views on the platform now. Right box: money for this clip. The campaign budget is recounted.</span>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    function bind(inputId, previewId) {
        var input = document.getElementById(inputId);
        var preview = document.getElementById(previewId);
        if (!input || !preview) return;
        input.addEventListener('change', function () {
            var file = input.files && input.files[0];
            if (!file) return;
            preview.src = URL.createObjectURL(file);
            preview.hidden = false;
        });
    }
    bind('banner_file', 'bannerPreview');
    bind('owner_avatar_file', 'ownerAvatarPreview');
})();
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
