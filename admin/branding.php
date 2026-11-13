<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
require_once __DIR__ . '/../includes/branding.php';
$pageTitle = 'Logo & favicon';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $changes = [];
        foreach (['logo','favicon'] as $kind) {
            $upload = branding_upload($_FILES[$kind] ?? null, $kind);
            if ($upload !== null) { $changes['branding_' . $kind] = $upload; }
            elseif (post('remove_' . $kind) === '1') { $changes['branding_' . $kind] = ''; }
        }
        foreach ($changes as $key => $value) { set_setting($key, $value); }
        flash('success', 'Logo and favicon settings saved.');
    } catch (RuntimeException $error) { flash('error', $error->getMessage()); }
    redirect('admin/branding.php');
}
require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Logo &amp; favicon</h1>
<p class="muted">Upload your logo for the website and dashboard navigation, and your favicon for browser tabs.</p>
<form method="post" enctype="multipart/form-data">
<?= csrf_field() ?>
<div class="grid-2">
<?php foreach (['logo'=>'Company logo','favicon'=>'Browser favicon'] as $kind => $label): $current=branding_asset($kind); ?>
<section class="panel"><div class="panel__head"><h3><?= e($label) ?></h3></div><div class="panel__body">
<div style="min-height:140px;display:grid;place-items:center;background:#f5f3ed;border:1px dashed #c9c0aa;border-radius:14px;padding:20px;margin-bottom:16px">
 <img id="preview-<?= e($kind) ?>" <?= $current !== '' ? 'src="' . e($current) . '"' : '' ?> alt="<?= e($label) ?> preview" style="max-width:100%;max-height:110px;object-fit:contain;<?= $current===''?'display:none':'' ?>">
 <span id="empty-<?= e($kind) ?>" class="muted" <?= $current!==''?'hidden':'' ?>><?= $kind==='logo'?'Default HPL logo':'Default browser icon' ?></span>
</div>
<label class="label" for="<?= e($kind) ?>">Upload <?= e(strtolower($label)) ?></label>
<input class="input" type="file" id="<?= e($kind) ?>" name="<?= e($kind) ?>" accept="<?= $kind==='favicon'?'image/png,image/jpeg,image/webp,image/gif,image/x-icon,image/vnd.microsoft.icon,.ico':'image/png,image/jpeg,image/webp,image/gif' ?>" data-brand-upload="<?= e($kind) ?>">
<p class="field__hint"><?= $kind==='logo'?'PNG with a transparent background works well. JPG, WebP and GIF also supported.':'Use a square PNG, ideally 512 × 512. Square JPG, WebP, GIF and ICO also supported (32–1024 pixels).' ?> Maximum 4 MB.</p>
<?php if ($current!==''): ?><label class="label"><input type="checkbox" name="remove_<?= e($kind) ?>" value="1"> Remove current <?= e($kind) ?> and use the default</label><?php endif; ?>
</div></section>
<?php endforeach; ?>
</div><button class="btn btn--primary" type="submit">Save branding</button>
</form>
<p class="muted mt-3">Uploading a replacement gives it a new address so browsers can refresh it. If a browser keeps an old tab icon, close and reopen the tab.</p>
<script>
(function(){document.querySelectorAll('[data-brand-upload]').forEach(function(input){var objectUrl;input.addEventListener('change',function(){if(objectUrl)URL.revokeObjectURL(objectUrl);var kind=input.getAttribute('data-brand-upload');var file=input.files[0];if(!file)return;objectUrl=URL.createObjectURL(file);var image=document.getElementById('preview-'+kind);image.src=objectUrl;image.style.display='block';document.getElementById('empty-'+kind).hidden=true;});});})();
</script>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
