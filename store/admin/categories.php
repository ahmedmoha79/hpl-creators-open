<?php
require __DIR__ . '/_boot.php';
require_admin_page();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'add') {
        $slug = slugify(post('slug') ?: post('name_en'));
        if (val('SELECT id FROM shop_categories WHERE slug = ?', [$slug])) $slug .= '-2';
        q('INSERT INTO shop_categories (slug, kind, name, blurb, sort) VALUES (?,?,?,?,?)', [$slug, in_array(post('kind'), ['detector', 'machine', 'accessory', 'service'], true) ? post('kind') : 'machine', post_langs('name', 80), post_langs('blurb', 300), (int)post('sort')]);
        flash('ok', 'Category added.');
    } elseif (post('action') === 'save') {
        $cid = (int)post('id');
        q('UPDATE shop_categories SET kind = ?, name = ?, blurb = ?, sort = ? WHERE id = ?', [in_array(post('kind'), ['detector', 'machine', 'accessory', 'service'], true) ? post('kind') : 'machine', post_langs('name', 80), post_langs('blurb', 300), (int)post('sort'), $cid]);
        flash('ok', 'Category saved.');
    } elseif (post('action') === 'delete') {
        $cid = (int)post('id');
        if ((int)val('SELECT COUNT(*) FROM shop_products WHERE category_id = ?', [$cid], 0) > 0) flash('err', 'Move or delete its products first.');
        else { q('DELETE FROM shop_categories WHERE id = ?', [$cid]); flash('ok', 'Category deleted.'); }
    }
    redirect('admin/categories.php');
}
$cats = rows('SELECT c.*, (SELECT COUNT(*) FROM shop_products p WHERE p.category_id = c.id) AS n FROM shop_categories c ORDER BY sort, id');
$kinds = ['detector' => 'Detectors menu', 'machine' => 'Machines menu', 'accessory' => 'Accessories', 'service' => 'Services'];
admin_head('Categories', 'categories.php');
$fields = function (array $c) use ($kinds) {
    ?>
    <div class="a-catrow">
        <?php foreach (STORE_LANGS as $l => $n): ?><div class="field"><label>Name (<?= $n ?>)</label><input class="input" name="name_<?= $l ?>" value="<?= e(lang_val($c['name'] ?? '{}', $l)) ?>"></div><?php endforeach; ?>
    </div>
    <div class="a-catrow">
        <?php foreach (STORE_LANGS as $l => $n): ?><div class="field"><label>One line (<?= $n ?>)</label><input class="input" name="blurb_<?= $l ?>" value="<?= e(lang_val($c['blurb'] ?? '{}', $l)) ?>"></div><?php endforeach; ?>
    </div>
    <div class="row2"><div class="field"><label>Menu</label><select class="input" name="kind"><?php foreach ($kinds as $k => $n): ?><option value="<?= $k ?>"<?= ($c['kind'] ?? '') === $k ? ' selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Order</label><input class="input" type="number" name="sort" value="<?= (int)($c['sort'] ?? 100) ?>"></div></div>
    <?php
};
?>
<h1>Categories</h1>
<?php foreach ($cats as $c): ?>
    <form class="card form" method="post" style="margin-bottom:14px"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
        <div class="a-h"><h2 style="margin:0"><?= e(tr($c['name'], 'en')) ?> <span class="muted small">/shop/<?= e($c['slug']) ?> · <?= (int)$c['n'] ?> products</span></h2></div>
        <?php $fields($c); ?>
        <div style="display:flex;gap:8px"><button class="btn btn--dark btn--sm" name="action" value="save">Save</button><button class="btn btn--line btn--sm" name="action" value="delete" onclick="return confirm('Delete this category?')">Delete</button></div>
    </form>
<?php endforeach; ?>
<form class="card form" method="post"><?= csrf_field() ?><h2>Add a category</h2><?php $fields([]); ?><button class="btn btn--buy" name="action" value="add">Add category</button></form>
<?php admin_foot();
