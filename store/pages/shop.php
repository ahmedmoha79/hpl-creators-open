<?php
require STORE_ROOT . '/inc/layout.php';
$catSlug = $ARGS[0] ?? '';
$cat = $catSlug !== '' ? category_by_slug($catSlug) : null;
if ($cat && (int)val('SELECT COUNT(*) FROM shop_products WHERE category_id = ? AND published = 1', [$cat['id']], 0) === 0) $cat = null;
if (($catSlug !== '' && !$cat) || count($ARGS) > 1) {
    http_response_code(404);
    require __DIR__ . '/not_found.php';
    return;
}
$cats = array_values(array_filter(categories(), fn($c) => (int)$c['n'] > 0 && (!$cat || $c['id'] == $cat['id'])));
$title = $cat ? tr($cat['name']) : t('shop.h1');
$tk = fn(string $k, string $fb) => t($k) === $k ? $fb : t($k);
$desc = $cat ? (in_array($cat['slug'], ['gold-detectors', 'gold-washers', 'accessories'], true) ? $tk('shop.meta_' . $cat['kind'], (string)tr($cat['blurb'])) : (string)tr($cat['blurb'])) : t('shop.meta');
$ptitle = $cat ? (in_array($cat['slug'], ['gold-detectors', 'gold-washers', 'accessories'], true) ? $tk('shop.title_' . $cat['kind'], $title) : $title) : t('shop.h1');
page_head($ptitle, $desc, ['nav' => $cat ? 'shop/' . $cat['slug'] : '', 'path' => $cat ? 'shop/' . $cat['slug'] : 'shop']);
$listLd = ['@context' => 'https://schema.org', '@type' => 'ItemList', 'itemListElement' => []];
?>
<div class="phead">
    <div class="wrap">
        <p class="crumbs"><a href="<?= e(url()) ?>">HPL</a> / <a href="<?= e(url('shop')) ?>"><?= e(t('shop.h1')) ?></a><?= $cat ? ' / ' . e(tr($cat['name'])) : '' ?></p>
        <h1><?= e($title) ?></h1>
        <p><?= e($cat ? tr($cat['blurb']) : t('shop.sub')) ?></p>
    </div>
</div>
<section class="sec">
    <div class="wrap">
        <?php if (!$cats): ?>
            <div class="empty"><p><?= e(t('shop.none')) ?></p><button class="btn btn--dark" type="button" data-chat-open><?= e(t('chat.open')) ?></button></div>
        <?php endif; ?>
        <?php foreach ($cats as $c): $list = products(['category' => $c['slug']]); ?>
            <div class="catgroup">
                <?php if (!$cat): ?><h2><a href="<?= e(url('shop/' . $c['slug'])) ?>" style="text-decoration:none"><?= e(tr($c['name'])) ?></a></h2><p><?= e(tr($c['blurb'])) ?></p><?php endif; ?>
                <div class="pgrid"><?php foreach ($list as $p) { echo product_card($p); $listLd['itemListElement'][] = ['@type' => 'ListItem', 'position' => count($listLd['itemListElement']) + 1, 'url' => abs_url('product/' . $p['slug']), 'name' => product_title($p)]; } ?></div>
            </div>
        <?php endforeach; ?>
        <?php if ($cat && $cat['kind'] === 'detector'): ?><p style="margin-top:28px"><a class="btn btn--line" href="<?= e(url('#compare')) ?>"><?= e(t('cmp.h')) ?></a></p><?php endif; ?>
    </div>
</section>
<?= request_band('band--paper') ?>
<script type="application/ld+json"><?= json_encode($listLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_foot();
