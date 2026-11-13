<?php
require __DIR__ . '/_boot.php';
require_admin_page();
$id = (int)get('id');
$isNew = get('new') === '1';
$secs = video_sections();
$products = rows('SELECT id, brand, name FROM shop_products ORDER BY sort, id');

function save_poster_data(string $data): ?string
{
    if (!preg_match('~^data:image/(jpeg|png|webp);base64,~', $data)) return null;
    $bin = base64_decode(substr($data, strpos($data, ',') + 1), true);
    if ($bin === false || strlen($bin) > 6 * 1024 * 1024 || !@getimagesizefromstring($bin)) return null;
    $dir = uploads_ready('posters');
    $name = date('Ymd') . '-' . bin2hex(random_bytes(5)) . '.jpg';
    if (function_exists('imagecreatefromstring') && ($im = @imagecreatefromstring($bin))) {
        imagejpeg($im, "$dir/$name", 82);
        imagedestroy($im);
    } else {
        file_put_contents("$dir/$name", $bin);
    }
    return "posters/$name";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $vid = (int)post('id');
    if (post('action') === 'delete' && $vid) {
        $v = row('SELECT file, poster FROM shop_videos WHERE id = ?', [$vid]);
        q('DELETE FROM shop_videos WHERE id = ?', [$vid]);
        foreach (['file', 'poster'] as $k) if (!empty($v[$k]) && is_file(STORE_UPLOADS . '/' . $v[$k])) @unlink(STORE_UPLOADS . '/' . $v[$k]);
        flash('ok', 'Video deleted.');
        redirect('admin/videos.php');
    }
    if (post('action') === 'order') {
        foreach ((array)($_POST['sort'] ?? []) as $k => $s) q('UPDATE shop_videos SET sort = ?, published = ? WHERE id = ?', [(int)$s, isset($_POST['pub'][$k]) ? 1 : 0, (int)$k]);
        flash('ok', 'Order saved.');
        redirect('admin/videos.php');
    }
    $cur = $vid ? row('SELECT * FROM shop_videos WHERE id = ?', [$vid]) : null;
    $file = $cur['file'] ?? null;
    $newFile = post('file');
    if ($newFile !== '' && preg_match('~^videos/\d{6}/[\w-]+\.mp4$~', $newFile) && is_file(STORE_UPLOADS . '/' . $newFile)) $file = $newFile;
    if (post('remove_file') === '1') $file = null;
    $embed = mb_substr(safe_href(post('embed_url')), 0, 300);
    if (!$file && $embed === '') {
        flash('err', 'Add a video: upload a file, or paste a TikTok, YouTube or Facebook link.');
        redirect('admin/videos.php?' . ($vid ? 'id=' . $vid : 'new=1'));
    }
    $poster = $cur['poster'] ?? null;
    if (!empty($_FILES['poster']['name'])) { $poster = save_image($_FILES['poster'], 'posters', 1280) ?: $poster; }
    elseif (post('poster_data') !== '') { $poster = save_poster_data(post('poster_data')) ?: $poster; }
    if (!$poster && !$file && $embed !== '') {
        $poster = video_link_meta($embed)['poster']; // cover picture taken from TikTok / YouTube
    }
    $sections = implode(',', array_intersect(array_keys($secs), (array)($_POST['sections'] ?? [])));
    $vals = [post_langs('title', 160), post_langs('caption', 500), $file, $embed ?: null, $poster, post('orientation') === 'portrait' ? 'portrait' : 'landscape', $sections,
        (int)post('product_id') ?: null, mb_substr(post('customer'), 0, 120), mb_substr(post('place'), 0, 120), post('recorded_on') ?: null, post('published') ? 1 : 0, (int)post('sort')];
    if (lang_val($vals[0], 'en') === '' && lang_val($vals[0], 'fr') === '') {
        flash('err', 'Give the video a title (at least in English).');
        redirect('admin/videos.php?' . ($vid ? 'id=' . $vid : 'new=1'));
    }
    if ($vid) {
        q('UPDATE shop_videos SET title=?, caption=?, file=?, embed_url=?, poster=?, orientation=?, sections=?, product_id=?, customer=?, place=?, recorded_on=?, published=?, sort=? WHERE id = ?', array_merge($vals, [$vid]));
    } else {
        q('INSERT INTO shop_videos (title, caption, file, embed_url, poster, orientation, sections, product_id, customer, place, recorded_on, published, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
        $vid = (int)db()->lastInsertId();
    }
    flash('ok', 'Video saved.' . ($sections === '' && !post('product_id') ? ' Tick at least one place to show it, or link it to a product.' : ''));
    redirect('admin/videos.php?id=' . $vid);
}

videos_fill_posters(4);
admin_head('Videos', 'videos.php');
if ($id || $isNew):
    $v = $id ? row('SELECT * FROM shop_videos WHERE id = ?', [$id]) : null;
    $v = $v ?: ['id' => 0, 'title' => '{}', 'caption' => '{}', 'file' => null, 'embed_url' => '', 'poster' => null, 'orientation' => 'landscape', 'sections' => get('section'), 'product_id' => (int)get('product') ?: null, 'customer' => '', 'place' => '', 'recorded_on' => null, 'published' => 1, 'sort' => 100];
    $on = explode(',', (string)$v['sections']);
?>
<p><a href="<?= e(url('admin/videos.php')) ?>">&larr; All videos</a></p>
<h1><?= $v['id'] ? 'Edit video' : 'Add a video' ?></h1>
<?php
$iniBytes = function (string $v): int { $v = trim($v); $n = (int)$v; $u = strtolower(substr($v, -1)); return $u === 'g' ? $n << 30 : ($u === 'm' ? $n << 20 : ($u === 'k' ? $n << 10 : $n)); };
$chunk = max(256 * 1024, min($iniBytes((string)ini_get('upload_max_filesize')) ?: 2 << 20, $iniBytes((string)ini_get('post_max_size')) ?: 8 << 20, 8 << 20) - 128 * 1024);
?>
<form class="form a-form" method="post" enctype="multipart/form-data" data-video-form data-upload="<?= e(url('admin/upload.php')) ?>" data-chunk="<?= (int)$chunk ?>">
    <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><input type="hidden" name="action" value="save">
    <input type="hidden" name="file" value="" data-file-field><input type="hidden" name="poster_data" value="" data-poster-field>
    <div class="a-cols">
        <section class="card form">
            <h2>The video</h2>
            <?php if ($v['file']): ?>
                <video src="<?= e(upload_url($v['file'])) ?>" controls preload="metadata" style="max-height:320px;border-radius:8px;background:#000"<?= $v['poster'] ? ' poster="' . e(upload_url($v['poster'])) . '"' : '' ?>></video>
                <label class="choice"><input type="checkbox" name="remove_file" value="1"><span>Remove this file (keep the link below instead)</span></label>
            <?php endif; ?>
            <div class="field">
                <label><?= $v['file'] ? 'Replace with another file' : 'Upload a video file (MP4)' ?></label>
                <input class="input" type="file" accept="video/mp4,video/quicktime" data-video-file>
                <div class="a-progress" data-progress hidden><span></span><b></b></div>
                <small class="muted">Big files are fine: they go up in small pieces. Keep this page open until it says "Uploaded". Best size: 1080p MP4.</small>
            </div>
            <video data-preview hidden muted playsinline style="max-height:240px;border-radius:8px;background:#000"></video>
            <p class="muted small" style="margin:0">or</p>
            <div class="field"><label>Paste a TikTok, YouTube or Facebook link</label><input class="input" name="embed_url" type="url" value="<?= e((string)$v['embed_url']) ?>" placeholder="https://www.tiktok.com/@hpldetectors.zambia/video/…"></div>
            <div class="field"><label>Shape</label>
                <div class="row2"><label class="choice"><input type="radio" name="orientation" value="landscape"<?= $v['orientation'] === 'landscape' ? ' checked' : '' ?>><span>Wide (16:9)</span></label><label class="choice"><input type="radio" name="orientation" value="portrait"<?= $v['orientation'] === 'portrait' ? ' checked' : '' ?>><span>Tall (TikTok, 9:16)</span></label></div>
            </div>
            <div class="field"><label>Cover picture (optional)</label>
                <?php if ($v['poster']): ?><img src="<?= e(upload_url($v['poster'])) ?>" alt="" style="max-height:140px;border-radius:6px"><?php endif; ?>
                <input class="input" type="file" name="poster" accept="image/*">
                <small class="muted">When you upload a video file, a cover is taken from it automatically if you leave this empty.</small>
                <img data-poster-preview alt="" hidden style="max-height:140px;border-radius:6px">
            </div>
        </section>
        <section class="card form">
            <h2>Where it shows</h2>
            <div class="choices">
                <?php foreach ($secs as $k => $n): ?><label class="choice"><input type="checkbox" name="sections[]" value="<?= $k ?>"<?= in_array($k, $on, true) ? ' checked' : '' ?>><span><?= e($n) ?><?= $k === 'home' ? '<small>The first one plays at the top of the home page</small>' : '' ?></span></label><?php endforeach; ?>
            </div>
            <div class="field"><label>Also show on this product's page</label><select class="input" name="product_id"><option value="">No product</option><?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"<?= (int)$v['product_id'] === (int)$p['id'] ? ' selected' : '' ?>><?= e(trim($p['brand'] . ' ' . $p['name'])) ?></option><?php endforeach; ?></select></div>
            <div class="row2">
                <div class="field"><label>Customer (optional)</label><input class="input" name="customer" value="<?= e($v['customer']) ?>" placeholder="e.g. Jean M."></div>
                <div class="field"><label>Place</label><input class="input" name="place" value="<?= e($v['place']) ?>" placeholder="e.g. Mumbwa, Zambia"></div>
            </div>
            <div class="row2">
                <div class="field"><label>Filmed on</label><input class="input" type="date" name="recorded_on" value="<?= e((string)$v['recorded_on']) ?>"></div>
                <div class="field"><label>Order</label><input class="input" type="number" name="sort" value="<?= (int)$v['sort'] ?>"></div>
            </div>
            <label class="choice"><input type="checkbox" name="published" value="1"<?= $v['published'] ? ' checked' : '' ?>><span>Show on the site</span></label>
        </section>
    </div>
    <section class="card form" data-langtabs>
        <h2>Title and caption</h2>
        <div class="tabs" role="tablist"><?php foreach (STORE_LANGS as $l => $n): ?><button class="tab" type="button" role="tab" data-lang-tab="<?= $l ?>" aria-selected="<?= $l === 'en' ? 'true' : 'false' ?>"><?= e($n) ?></button><?php endforeach; ?></div>
        <?php foreach (STORE_LANGS as $l => $n): ?>
            <div class="form" data-lang-pane="<?= $l ?>"<?= $l === 'en' ? '' : ' hidden' ?>>
                <div class="field"><label>Title (<?= e($n) ?>)</label><input class="input" name="title_<?= $l ?>" maxlength="160" value="<?= e(lang_val($v['title'], $l)) ?>"></div>
                <div class="field"><label>Caption (<?= e($n) ?>, optional)</label><textarea class="input" name="caption_<?= $l ?>" rows="2" maxlength="500"><?= e(lang_val($v['caption'], $l)) ?></textarea></div>
            </div>
        <?php endforeach; ?>
    </section>
    <div class="a-savebar"><button class="btn btn--buy btn--lg" type="submit" data-video-save>Save video</button></div>
</form>
<?php if ($v['id']): ?><form method="post" style="margin-top:20px" onsubmit="return confirm('Delete this video for good?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$v['id'] ?>"><button class="btn btn--line btn--sm" style="border-color:#B3261E;color:#B3261E">Delete video</button></form><?php endif; ?>
<?php else:
    $list = rows('SELECT v.*, p.name AS pname FROM shop_videos v LEFT JOIN shop_products p ON p.id = v.product_id ORDER BY v.sort, v.id DESC');
?>
<div class="a-h"><h1>Videos</h1><a class="btn btn--buy" href="<?= e(url('admin/videos.php?new=1')) ?>">Add a video</a></div>
<p class="muted">Each video can show in several places: the home page, the videos page sections, "How to buy", and a product page.</p>
<div class="a-secsum"><?php foreach ($secs as $k => $n): $c = (int)val('SELECT COUNT(*) FROM shop_videos WHERE published = 1 AND FIND_IN_SET(?, sections)', [$k], 0); ?><a class="chip" href="<?= e(url('admin/videos.php?new=1&section=' . $k)) ?>" title="Add a video to <?= e($n) ?>"><?= e($n) ?>: <?= $c ?></a><?php endforeach; ?></div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="order">
<div class="a-table-wrap"><table class="a-table">
    <thead><tr><th></th><th>Title</th><th>Shows in</th><th>Product</th><th>Order</th><th>On site</th></tr></thead>
    <tbody>
    <?php if (!$list): ?><tr><td colspan="6" class="muted">No videos yet.</td></tr><?php endif; ?>
    <?php foreach ($list as $v): ?>
        <tr>
            <td><?php if ($v['poster']): ?><img src="<?= e(upload_url($v['poster'])) ?>" alt="" width="72" style="border-radius:6px"><?php else: ?><span class="a-st"><?= $v['file'] ? 'file' : 'link' ?></span><?php endif; ?></td>
            <td><a href="<?= e(url('admin/videos.php?id=' . $v['id'])) ?>"><strong><?= e(tr($v['title'], 'en')) ?></strong></a><br><span class="muted small"><?= e($v['place']) ?></span></td>
            <td class="small"><?= e(implode(', ', array_map(fn($s) => $secs[$s] ?? $s, array_filter(explode(',', $v['sections']))))) ?></td>
            <td class="small"><?= e((string)$v['pname']) ?></td>
            <td><input class="input" style="width:80px;min-height:36px" type="number" name="sort[<?= (int)$v['id'] ?>]" value="<?= (int)$v['sort'] ?>"></td>
            <td><input type="checkbox" name="pub[<?= (int)$v['id'] ?>]" value="1"<?= $v['published'] ? ' checked' : '' ?>></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div>
<?php if ($list): ?><div class="a-savebar"><button class="btn btn--line" type="submit">Save order</button></div><?php endif; ?>
</form>
<?php endif;
admin_foot();
