<?php
require __DIR__ . '/_boot.php';
require_admin_page();
$id = (int)get('id');
$isNew = get('new') === '1';
$cats = rows('SELECT * FROM shop_categories ORDER BY sort, id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $pid = (int)post('id');
    $act = post('action');
    if ($act === 'delete' && $pid) {
        q('DELETE FROM shop_products WHERE id = ?', [$pid]);
        q('UPDATE shop_videos SET product_id = NULL WHERE product_id = ?', [$pid]);
        flash('ok', 'Product deleted.');
        redirect('admin/products.php');
    }
    if ($act === 'quick') { // price/stock straight from the list
        foreach ((array)($_POST['price'] ?? []) as $k => $v) {
            $st = (string)($_POST['stock'][$k] ?? 'in_stock');
            q('UPDATE shop_products SET price_zmw = ?, stock = ?, published = ? WHERE id = ?', [$v === '' ? null : max(0, (float)$v), in_array($st, ['in_stock', 'low', 'order', 'out'], true) ? $st : 'in_stock', isset($_POST['pub'][$k]) ? 1 : 0, (int)$k]);
        }
        flash('ok', 'Prices and stock saved. The site shows them now.');
        redirect('admin/products.php');
    }
    if ($act === 'save') {
        $name = mb_substr(post('name'), 0, 160);
        if ($name === '') {
            flash('err', 'The product needs a name.');
            redirect('admin/products.php?' . ($pid ? 'id=' . $pid : 'new=1'));
        }
        $slug = slugify(post('slug') !== '' ? post('slug') : post('brand') . ' ' . $name);
        if (val('SELECT id FROM shop_products WHERE slug = ? AND id <> ?', [$slug, $pid])) $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 3);
        // Specs and box contents are written per language: {"en": [[label, value], ...], "fr": [...]}.
        $specs = [];
        $box = [];
        foreach (array_keys(STORE_LANGS) as $l) {
            foreach ((array)($_POST['spec_k_' . $l] ?? []) as $i => $k) {
                $k = trim((string)$k); $v = trim((string)($_POST['spec_v_' . $l][$i] ?? ''));
                if ($k !== '' && $v !== '') $specs[$l][] = [mb_substr($k, 0, 60), mb_substr($v, 0, 200)];
            }
            $b = array_values(array_filter(array_map('trim', explode("\n", post('in_box_' . $l)))));
            if ($b) $box[$l] = $b;
        }
        $cur = $pid ? row('SELECT image, gallery FROM shop_products WHERE id = ?', [$pid]) : null;
        $image = $cur['image'] ?? null;
        $gallery = json_decode((string)($cur['gallery'] ?? '[]'), true) ?: [];
        if (!empty($_FILES['image']['name'])) {
            $img = save_image($_FILES['image'], 'products', 1400);
            if ($img) $image = $img; else flash('err', 'The main photo could not be saved: use a JPG, PNG or WebP under 12 MB.');
        }
        foreach ((array)($_POST['drop'] ?? []) as $d) { $gallery = array_values(array_diff($gallery, [$d])); }
        if (!empty($_FILES['gallery']['name'][0])) {
            foreach ($_FILES['gallery']['name'] as $i => $_) {
                $f = ['name' => $_FILES['gallery']['name'][$i], 'type' => $_FILES['gallery']['type'][$i], 'tmp_name' => $_FILES['gallery']['tmp_name'][$i], 'error' => $_FILES['gallery']['error'][$i], 'size' => $_FILES['gallery']['size'][$i]];
                if ($g = save_image($f, 'products', 1400)) $gallery[] = $g;
            }
        }
        if (!$image && $gallery) $image = array_shift($gallery);
        $vals = [$slug, (int)post('category_id') ?: null, mb_substr(post('brand'), 0, 60), $name, post_langs('tagline', 300), post_langs('description', 6000), post_langs('best_for', 300),
            json_encode((object)$specs, JSON_UNESCAPED_UNICODE), json_encode((object)$box, JSON_UNESCAPED_UNICODE), post('price') === '' ? null : max(0, (float)post('price')), post_langs('price_note', 300),
            in_array(post('stock'), ['in_stock', 'low', 'order', 'out'], true) ? post('stock') : 'in_stock', mb_substr(post('warranty'), 0, 80), mb_substr(safe_href(post('official_url')), 0, 300),
            $image, json_encode(array_values($gallery)), post('featured') ? 1 : 0, post('published') ? 1 : 0, (int)post('sort')];
        if ($pid) {
            q('UPDATE shop_products SET slug=?, category_id=?, brand=?, name=?, tagline=?, description=?, best_for=?, specs=?, in_box=?, price_zmw=?, price_note=?, stock=?, warranty=?, official_url=?, image=?, gallery=?, featured=?, published=?, sort=? WHERE id = ?', array_merge($vals, [$pid]));
        } else {
            q('INSERT INTO shop_products (slug, category_id, brand, name, tagline, description, best_for, specs, in_box, price_zmw, price_note, stock, warranty, official_url, image, gallery, featured, published, sort) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $vals);
            $pid = (int)db()->lastInsertId();
        }
        flash('ok', 'Saved. ' . $name . ' is ' . (post('published') ? 'live on the site.' : 'hidden from the site.'));
        redirect('admin/products.php?id=' . $pid);
    }
}

admin_head('Products', 'products.php');
if ($id || $isNew):
    $p = $id ? row('SELECT * FROM shop_products WHERE id = ?', [$id]) : null;
    if ($id && !$p) { echo '<p>Product not found.</p>'; admin_foot(); exit; }
    $p = $p ?: ['id' => 0, 'slug' => '', 'category_id' => $cats[0]['id'] ?? null, 'brand' => '', 'name' => '', 'tagline' => '{}', 'description' => '{}', 'best_for' => '{}', 'specs' => '[]', 'in_box' => '[]', 'price_zmw' => '', 'price_note' => '{}', 'stock' => 'in_stock', 'warranty' => '', 'official_url' => '', 'image' => null, 'gallery' => '[]', 'featured' => 0, 'published' => 1, 'sort' => 100];
    $specsAll = json_decode((string)$p['specs'], true) ?: [];
    if ($specsAll && array_is_list($specsAll)) $specsAll = ['en' => $specsAll];
    $boxAll = json_decode((string)$p['in_box'], true) ?: [];
    if ($boxAll && array_is_list($boxAll)) $boxAll = ['en' => $boxAll];
    $gallery = json_decode((string)$p['gallery'], true) ?: [];
?>
<p><a href="<?= e(url('admin/products.php')) ?>">&larr; All products</a><?php if ($p['id']): ?> · <a href="<?= e(url('product/' . $p['slug'])) ?>" target="_blank" rel="noopener">See it on the site</a><?php endif; ?></p>
<h1><?= $p['id'] ? e(trim($p['brand'] . ' ' . $p['name'])) : 'New product' ?></h1>
<form class="form a-form" method="post" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <div class="a-cols">
        <section class="card form">
            <h2>Basics</h2>
            <div class="row2">
                <div class="field"><label>Brand</label><input class="input" name="brand" value="<?= e($p['brand']) ?>" placeholder="Nokta, Minelab, HPL…"></div>
                <div class="field"><label>Name</label><input class="input" name="name" required value="<?= e($p['name']) ?>"></div>
            </div>
            <div class="row2">
                <div class="field"><label>Category</label><select class="input" name="category_id"><?php foreach ($cats as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$p['category_id'] === (int)$c['id'] ? ' selected' : '' ?>><?= e(tr($c['name'], 'en')) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Web address</label><input class="input" name="slug" value="<?= e($p['slug']) ?>" placeholder="made from the name"></div>
            </div>
            <div class="row2">
                <div class="field"><label>Price in kwacha (K)</label><input class="input" name="price" type="number" step="1" min="0" value="<?= e($p['price_zmw'] !== null && $p['price_zmw'] !== '' ? (string)(int)$p['price_zmw'] : '') ?>" placeholder="empty = Ask for price"><small class="muted">Buyers abroad also see it in US dollars (rate in Settings).</small></div>
                <div class="field"><label>Stock</label><select class="input" name="stock"><?php foreach (['in_stock' => 'In stock in Lusaka', 'low' => 'Few left', 'order' => 'On order (1–3 weeks)', 'out' => 'Sold out'] as $k => $n): ?><option value="<?= $k ?>"<?= $p['stock'] === $k ? ' selected' : '' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
            </div>
            <div class="row2">
                <div class="field"><label>Warranty</label><input class="input" name="warranty" value="<?= e($p['warranty']) ?>" placeholder="2 years (Nokta)"></div>
                <div class="field"><label>Maker's page (link)</label><input class="input" name="official_url" type="url" value="<?= e($p['official_url']) ?>"></div>
            </div>
            <div class="row2">
                <label class="choice"><input type="checkbox" name="published" value="1"<?= $p['published'] ? ' checked' : '' ?>><span>Show on the site</span></label>
                <label class="choice"><input type="checkbox" name="featured" value="1"<?= $p['featured'] ? ' checked' : '' ?>><span>Feature on the home page</span></label>
            </div>
            <div class="field" style="max-width:160px"><label>Order in lists</label><input class="input" name="sort" type="number" value="<?= (int)$p['sort'] ?>"><small class="muted">Lower comes first</small></div>
        </section>
        <section class="card form">
            <h2>Photos</h2>
            <p class="muted small">Your own photos from the shop work best. Square, plain background, at least 1000 px wide.</p>
            <?php if ($p['image']): ?><img src="<?= e(product_image($p)) ?>" alt="" style="width:180px;height:180px;object-fit:contain;border:1px solid var(--line);border-radius:8px;background:#fff"><?php endif; ?>
            <div class="field"><label>Main photo</label><input class="input" type="file" name="image" accept="image/jpeg,image/png,image/webp"></div>
            <?php if ($gallery): ?><div class="a-thumbs"><?php foreach ($gallery as $g): ?><label><img src="<?= e(img_url($g)) ?>" alt=""><span><input type="checkbox" name="drop[]" value="<?= e($g) ?>"> remove</span></label><?php endforeach; ?></div><?php endif; ?>
            <div class="field"><label>More photos (you can pick several)</label><input class="input" type="file" name="gallery[]" accept="image/jpeg,image/png,image/webp" multiple></div>
        </section>
    </div>
    <section class="card form" data-langtabs>
        <h2>Texts</h2>
        <div class="tabs" role="tablist"><?php foreach (STORE_LANGS as $l => $n): ?><button class="tab" type="button" role="tab" data-lang-tab="<?= $l ?>" aria-selected="<?= $l === 'en' ? 'true' : 'false' ?>"><?= e($n) ?></button><?php endforeach; ?></div>
        <?php foreach (STORE_LANGS as $l => $n): ?>
            <div class="form" data-lang-pane="<?= $l ?>"<?= $l === 'en' ? '' : ' hidden' ?>>
                <div class="field"><label>Short line under the name (<?= e($n) ?>)</label><input class="input" name="tagline_<?= $l ?>" maxlength="300" value="<?= e(lang_val($p['tagline'], $l)) ?>"></div>
                <div class="field"><label>Description (<?= e($n) ?>). Empty line = new paragraph</label><textarea class="input" name="description_<?= $l ?>" rows="7"><?= e(lang_val($p['description'], $l)) ?></textarea></div>
                <div class="field"><label>Best for (<?= e($n) ?>)</label><input class="input" name="best_for_<?= $l ?>" value="<?= e(lang_val($p['best_for'], $l)) ?>"></div>
                <div class="field"><label>Note under the price (<?= e($n) ?>, optional)</label><input class="input" name="price_note_<?= $l ?>" value="<?= e(lang_val($p['price_note'], $l)) ?>" placeholder="<?= e(t('price.note')) ?>"></div>
                <div class="field"><span class="label">Specifications (<?= e($n) ?>)</span>
                    <div data-specs>
                        <?php foreach (array_merge($specsAll[$l] ?? [], [['', ''], ['', '']]) as $sp): ?>
                            <div class="row2 a-spec"><input class="input" name="spec_k_<?= $l ?>[]" value="<?= e($sp[0] ?? '') ?>" placeholder="e.g. Weight"><input class="input" name="spec_v_<?= $l ?>[]" value="<?= e($sp[1] ?? '') ?>" placeholder="e.g. 1.4 kg"></div>
                        <?php endforeach; ?>
                    </div>
                    <button class="btn btn--line btn--sm" type="button" data-add-spec style="justify-self:start">Add a line</button>
                </div>
                <div class="field"><label>In the box (<?= e($n) ?>), one item per line</label><textarea class="input" name="in_box_<?= $l ?>" rows="5"><?= e(implode("\n", $boxAll[$l] ?? [])) ?></textarea></div>
            </div>
        <?php endforeach; ?>
        <p class="muted small">Empty French or Swahili boxes show the English text.</p>
    </section>
    <div class="a-savebar">
        <button class="btn btn--buy btn--lg" type="submit">Save product</button>
    </div>
</form>
<?php if ($p['id']): ?>
<form method="post" style="margin-top:20px" onsubmit="return confirm('Delete this product for good?')"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><button class="btn btn--line btn--sm" type="submit" style="border-color:#B3261E;color:#B3261E">Delete product</button></form>
<?php endif; ?>
<?php else:
    $list = rows('SELECT p.*, c.name AS cname FROM shop_products p LEFT JOIN shop_categories c ON c.id = p.category_id ORDER BY c.sort, p.sort, p.id');
?>
<div class="a-h"><h1>Products</h1><a class="btn btn--buy" href="<?= e(url('admin/products.php?new=1')) ?>">Add a product</a></div>
<p class="muted">Change prices and stock right here and press Save, or open a product to edit its texts and photos.</p>
<form method="post">
    <?= csrf_field() ?><input type="hidden" name="action" value="quick">
    <div class="a-table-wrap"><table class="a-table">
        <thead><tr><th></th><th>Product</th><th>Category</th><th>Price (K)</th><th>Stock</th><th>On site</th></tr></thead>
        <tbody>
        <?php foreach ($list as $p): ?>
            <tr>
                <td><img src="<?= e(product_image($p + ['cat_kind' => 'detector'])) ?>" alt="" width="48" height="48" style="object-fit:contain;background:#fff;border-radius:6px"></td>
                <td><a href="<?= e(url('admin/products.php?id=' . $p['id'])) ?>"><strong><?= e(trim($p['brand'] . ' ' . $p['name'])) ?></strong></a><?= $p['featured'] ? ' <span class="a-st">home</span>' : '' ?></td>
                <td class="small"><?= e(tr($p['cname'], 'en')) ?></td>
                <td><input class="input" style="width:120px;min-height:38px" type="number" step="1" min="0" name="price[<?= (int)$p['id'] ?>]" value="<?= e($p['price_zmw'] !== null ? (string)(int)$p['price_zmw'] : '') ?>"></td>
                <td><select class="input" style="min-height:38px" name="stock[<?= (int)$p['id'] ?>]"><?php foreach (['in_stock' => 'In stock', 'low' => 'Few left', 'order' => 'On order', 'out' => 'Sold out'] as $k => $n): ?><option value="<?= $k ?>"<?= $p['stock'] === $k ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></td>
                <td><input type="checkbox" name="pub[<?= (int)$p['id'] ?>]" value="1"<?= $p['published'] ? ' checked' : '' ?> aria-label="Show on site"></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
    <div class="a-savebar"><button class="btn btn--buy" type="submit">Save prices and stock</button></div>
</form>
<?php endif;
admin_foot();
