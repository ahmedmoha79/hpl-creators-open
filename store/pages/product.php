<?php
require STORE_ROOT . '/inc/layout.php';
$p = count($ARGS) === 1 ? product_by_slug($ARGS[0]) : null;
if (!$p) {
    http_response_code(404);
    require __DIR__ . '/not_found.php';
    return;
}
$co = company();
[$sl, $st] = stock_label($p['stock']);
$specs = lang_list($p['specs']);
$box = lang_list($p['in_box']);
$gallery = array_values(array_unique(array_filter(array_merge([$p['image']], json_decode((string)$p['gallery'], true) ?: []))));
$vids = videos(['product' => $p['id']]);
$related = products(['exclude' => $p['id'], 'limit' => 3]);
$desc = (string)tr($p['description']);
$title = product_title($p);
$askText = t('chat.about', ['name' => $title]);
$waText = t('wa.product', ['name' => $title, 'url' => abs_url('product/' . $p['slug'])]);
$pp = price_parts($p);
$isPhoto = !str_starts_with((string)$p['image'], 'site/');
$keyLabels = ['Technology', 'Waterproof', 'Water', 'Weight', 'Battery'];
$enSpecs = lang_list($p['specs'], 'en');
$keyfacts = [];
foreach ($enSpecs as $i => $s) {
    if (isset($s[0]) && in_array($s[0], $keyLabels, true) && isset($specs[$i]) && count($keyfacts) < 4) $keyfacts[] = $specs[$i];
}
$avail = ['in_stock' => 'InStock', 'low' => 'LimitedAvailability', 'order' => 'PreOrder', 'out' => 'OutOfStock'][$p['stock']] ?? 'InStock';
$ld = [
    '@context' => 'https://schema.org', '@type' => 'Product',
    'name' => $title, 'sku' => strtoupper($p['slug']),
    'brand' => ['@type' => 'Brand', 'name' => $p['brand']],
    'description' => (string)preg_replace('~\s+~', ' ', mb_substr($desc, 0, 500)),
    'image' => array_map(fn($g) => 'https://' . site_host() . img_url($g), $gallery),
    'url' => abs_url('product/' . $p['slug']),
    'category' => tr($p['cat_name']),
];
if ($pp) {
    $ld['offers'] = ['@type' => 'Offer', 'priceCurrency' => 'ZMW', 'price' => (string)(int)$p['price_zmw'], 'url' => abs_url('product/' . $p['slug']),
        'availability' => 'https://schema.org/' . $avail, 'itemCondition' => 'https://schema.org/NewCondition',
        'seller' => ['@id' => abs_url_in('en') . '#store'], 'areaServed' => 'Africa',
        'priceValidUntil' => date('Y-12-31')];
}
$crumbs = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => $co['brand'], 'item' => abs_url()],
    ['@type' => 'ListItem', 'position' => 2, 'name' => tr($p['cat_name']), 'item' => abs_url('shop/' . $p['cat_slug'])],
    ['@type' => 'ListItem', 'position' => 3, 'name' => $title, 'item' => abs_url('product/' . $p['slug'])],
]];
$metaDesc = $pp ? t('prod.meta', ['name' => $title, 'price' => $pp['main'], 'tag' => tr($p['tagline'])]) : tr($p['tagline']);
page_head(t('prod.title', ['name' => $title]) , $metaDesc, [
    'nav' => 'shop/' . $p['cat_slug'],
    'image' => img_url($p['image']) !== '' ? img_url($p['image']) : product_image($p),
    'og_type' => 'product',
    'product' => (int)$p['id'],
    'path' => 'product/' . $p['slug'],
    'body' => 'has-mbar',
    'head' => '<script type="application/ld+json">' . json_encode([$ld, $crumbs], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . videos_jsonld($vids),
]);
?>
<div class="wrap"><p class="crumbs crumbs--light"><a href="<?= e(url()) ?>"><?= e($co['brand']) ?></a> / <a href="<?= e(url('shop/' . $p['cat_slug'])) ?>"><?= e(tr($p['cat_name'])) ?></a> / <?= e($p['name']) ?></p></div>
<div class="wrap pp">
    <div class="gallery" data-gallery>
        <div class="gallery__main<?= $isPhoto ? ' is-photo' : '' ?>"><img src="<?= e(product_image($p, 1200)) ?>" alt="<?= e($title) ?>" width="1200" height="900" fetchpriority="high"></div>
        <?php if (count($gallery) > 1): ?>
            <div class="gallery__thumbs">
                <?php foreach ($gallery as $i => $g): ?>
                    <button type="button" data-src="<?= e(thumb($g, 1200)) ?>"<?= str_starts_with($g, 'site/') ? '' : ' data-photo' ?><?= $i === 0 ? ' aria-current="true"' : '' ?> aria-label="<?= e(t('prod.photo', ['n' => $i + 1])) ?>"><img src="<?= e(thumb($g, 200)) ?>" alt="" loading="lazy"></button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($p['photo_credit'])): ?><p class="muted small" style="margin-top:8px"><?= e(t('prod.photo_illus')) ?><?= str_starts_with($p['photo_credit'], 'Photo:') ? ' ' . e($p['photo_credit']) : '' ?></p><?php endif; ?>
    </div>
    <aside class="buybox">
        <?php if ($p['brand'] !== ''): ?><p class="buybox__brand"><?= e($p['brand']) ?></p><?php endif; ?>
        <h1><?= e($p['name']) ?></h1>
        <p class="buybox__tag"><?= e(tr($p['tagline'])) ?></p>
        <div class="buybox__price">
            <?= price_html($p) ?>
            <span class="stock stock--<?= e($st) ?>"><?= e($sl) ?></span>
        </div>
        <p class="buybox__note muted small"><?= e(tr($p['price_note']) ?: t('price.note')) ?></p>
        <div class="buybox__acts">
            <?php if (wa_link() !== '' && $p['stock'] !== 'out'): ?><a class="btn btn--wa btn--lg btn--block" href="<?= e(wa_for($p, $waText)) ?>" target="_blank" rel="noopener"><?= icon('wa') ?> <?= e(t('prod.wa_order')) ?></a><?php endif; ?>
            <div class="buybox__acts2">
                <?php if ($p['stock'] !== 'out'): ?><a class="btn btn--gold" href="<?= e(url('order?product=' . $p['slug'])) ?>"><?= e(t('btn.order_online')) ?></a><?php endif; ?>
                <button class="btn btn--line" type="button" data-chat-open data-chat-product="<?= (int)$p['id'] ?>" data-chat-text="<?= e($askText) ?>"><?= e(t('btn.ask')) ?></button>
            </div>
        </div>
        <ul class="ticks">
            <?php if ($p['cat_kind'] === 'detector'): ?><li><?= e(t('prod.trust1')) ?></li><?php endif; ?>
            <li><?= e(t('prod.trust2')) ?></li>
            <li><?= e(t('prod.trust3')) ?></li>
            <?php if ($p['cat_kind'] === 'detector'): ?><li><?= e(t('prod.trust5')) ?></li><li><?= e(t('prod.trust4')) ?></li><?php endif; ?>
        </ul>
        <?php if ($p['cat_kind'] === 'detector'): ?><p class="buybox__consult"><?= e(t('prod.consult')) ?> <a href="<?= e(consult_url()) ?>" target="_blank" rel="noopener"><?= e(t('btn.consult')) ?></a></p><?php endif; ?>
    </aside>
</div>

<?php if ($keyfacts): ?>
<div class="wrap" style="padding-bottom:clamp(40px,6vw,64px)">
    <dl class="keyfacts"><?php foreach ($keyfacts as $k): ?><div><dt><?= e($k[0]) ?></dt><dd><?= e($k[1]) ?></dd></div><?php endforeach; ?></dl>
</div>
<?php endif; ?>

<?php if ($vids): ?>
<section class="sec sec--dark">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('prod.videos', ['name' => $p['name']])) ?></h2><p><?= e(t('prod.videos_sub')) ?></p></div></div>
        <div class="reel"><?php foreach ($vids as $v) echo video_tile($v); ?></div>
    </div>
</section>
<?php endif; ?>

<section class="sec">
    <div class="wrap split">
        <div class="prose">
            <h2><?= e(t('prod.about', ['name' => $p['name']])) ?></h2>
            <?php foreach (preg_split('~\n\s*\n~', $desc) as $para): if (trim($para) === '') continue; ?><p><?= nl2br(e(trim($para))) ?></p><?php endforeach; ?>
            <?php if (tr($p['best_for']) !== ''): ?><p><strong><?= e(t('prod.best')) ?>:</strong> <?= e(tr($p['best_for'])) ?></p><?php endif; ?>
            <?php if ($box): ?><h3 style="margin-top:28px"><?= e(t('prod.box')) ?></h3><ul class="boxlist"><?php foreach ($box as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul><?php endif; ?>
        </div>
        <div>
            <?php if ($specs): ?>
                <h2><?= e(t('prod.specs')) ?></h2>
                <table class="spec"><?php foreach ($specs as $s): if (!is_array($s) || count($s) < 2) continue; ?><tr><th scope="row"><?= e($s[0]) ?></th><td><?= e($s[1]) ?></td></tr><?php endforeach; ?></table>
                <?php if ($p['official_url'] !== ''): ?><p class="small" style="margin-top:14px"><a href="<?= e(safe_href($p['official_url'])) ?>" target="_blank" rel="noopener"><?= e(t('prod.official')) ?></a></p><?php endif; ?>
            <?php else: ?>
                <h2><?= e(t('prod.ask_h')) ?></h2>
                <p class="muted"><?= e(t('prod.ask_d')) ?></p>
                <button class="btn btn--dark" type="button" data-chat-open data-chat-product="<?= (int)$p['id'] ?>" data-chat-text="<?= e($askText) ?>"><?= e(t('btn.ask')) ?></button>
            <?php endif; ?>
        </div>
    </div>
</section>

<section class="sec sec--paper">
    <div class="wrap split">
        <div>
            <h2><?= e(t('prod.buy_h')) ?></h2>
            <ol class="flow" style="grid-template-columns:1fr 1fr">
                <?php for ($i = 1; $i <= 4; $i++): ?><li<?= $i === 3 ? ' style="padding-left:0;border-left:0"' : '' ?>><h3><?= e(t('flow.' . $i)) ?></h3><p><?= e(t('flow.' . $i . 'd')) ?></p></li><?php endfor; ?>
            </ol>
        </div>
        <div>
            <h2><?= e(t('how.pay_h')) ?></h2>
            <ul class="paylist">
                <li><b><?= e(t('pay.showroom')) ?></b><span><?= e(t('how.pay_showroom')) ?></span></li>
                <li><b><?= e(t('pay.bank')) ?></b><span><?= e(t('how.pay_bank')) ?></span></li>
                <li><b><?= e(t('pay.wu')) ?> / <?= e(t('pay.mg')) ?></b><span><?= e(t('how.pay_wu')) ?></span></li>
                <?php if ($p['cat_kind'] === 'detector'): ?><li><b><?= e(t('pay.instal')) ?></b><span><?= e(t('how.pay_instal')) ?></span></li><?php endif; ?>
            </ul>
        </div>
    </div>
</section>

<?php if ($related): ?>
<section class="sec">
    <div class="wrap">
        <div class="sec__head"><h2><?= e(t('prod.related')) ?></h2></div>
        <div class="pgrid"><?php foreach ($related as $r) echo product_card($r); ?></div>
    </div>
</section>
<?php endif; ?>

<?= request_band('band--paper') ?>

<div class="mbar">
    <?php if (wa_link() !== ''): ?><a class="btn btn--wa" href="<?= e(wa_for($p, $waText)) ?>" target="_blank" rel="noopener"><?= icon('wa') ?> WhatsApp</a><?php endif; ?>
    <?php if ($p['stock'] !== 'out'): ?><a class="btn btn--gold" href="<?= e(url('order?product=' . $p['slug'])) ?>"><?= e(t('btn.order')) ?><?= $pp ? ' · ' . e($pp['main']) : '' ?></a><?php endif; ?>
</div>
<?php page_foot();
