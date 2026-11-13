<?php
require STORE_ROOT . '/inc/layout.php';
require_once STORE_ROOT . '/inc/guides.php';
$slug = $ARGS[0] ?? '';
$all = guides();
if ($slug !== '' && (!isset($all[$slug]) || count($ARGS) > 1)) {
    http_response_code(404);
    require __DIR__ . '/not_found.php';
    return;
}
if ($slug === '') {
    page_head(t('guides.title'), t('guides.meta'), ['nav' => 'guides', 'path' => 'guides']);
    ?>
<div class="phead"><div class="wrap"><h1><?= e(t('guides.h1')) ?></h1><p><?= e(t('guides.sub')) ?></p></div></div>
<section class="sec">
    <div class="wrap glist">
        <?php foreach ($all as $s => $g): [$gt, $gs] = guide_text($g); ?>
            <a class="gcard" href="<?= e(url('guides/' . $s)) ?>"><h2><?= e($gt) ?></h2><p><?= e($gs) ?></p><span><?= e(t('guides.read')) ?></span></a>
        <?php endforeach; ?>
    </div>
</section>
<?= consult_band() ?>
<?php
    page_foot();
    return;
}
$g = $all[$slug];
[$title, $sum, $paras] = guide_text($g);
$prods = array_values(array_filter(array_map('product_by_slug', $g['products'])));
$vids = [];
foreach (array_slice($prods, 0, 1) as $p) $vids = videos(['product' => $p['id'], 'limit' => 6]);
if ($slug === 'what-is-sambaza') $vids = videos(['section' => 'mumbwa', 'limit' => 6]);
$ld = ['@context' => 'https://schema.org', '@type' => 'Article', 'headline' => $title, 'description' => $sum, 'inLanguage' => lang(),
    'author' => ['@id' => abs_url_in('en') . '#store'], 'publisher' => ['@id' => abs_url_in('en') . '#store'],
    'mainEntityOfPage' => abs_url('guides/' . $slug), 'image' => 'https://' . site_host() . asset('img/og.jpg'), 'datePublished' => '2026-11-04'];
page_head($title, $sum, ['nav' => 'guides', 'path' => 'guides/' . $slug, 'og_type' => 'article',
    'head' => '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>' . videos_jsonld($vids)]);
?>
<div class="phead"><div class="wrap"><p class="crumbs"><a href="<?= e(url()) ?>">HPL</a> / <a href="<?= e(url('guides')) ?>"><?= e(t('guides.h1')) ?></a></p><h1><?= e($title) ?></h1><p><?= e($sum) ?></p></div></div>
<section class="sec">
    <div class="wrap split">
        <article class="prose">
            <?php foreach ($paras as $para): ?><p><?= e($para) ?></p><?php endforeach; ?>
            <p class="guide__cta"><a class="btn btn--gold" href="<?= e(consult_url()) ?>" target="_blank" rel="noopener"><?= e(t('btn.consult')) ?></a> <?php if (wa_link() !== ''): ?><a class="btn btn--wa" href="<?= e(wa_link(t('wa.hello'))) ?>" target="_blank" rel="noopener"><?= icon('wa') ?> WhatsApp</a><?php endif; ?></p>
        </article>
        <aside>
            <?php if ($vids): ?><div class="reel" style="grid-auto-columns:minmax(180px,1fr)"><?php foreach (array_slice($vids, 0, 4) as $v) echo video_tile($v); ?></div><?php endif; ?>
        </aside>
    </div>
</section>
<?php if ($prods): ?>
<section class="sec sec--paper">
    <div class="wrap">
        <div class="sec__head"><h2><?= e(t('guides.products')) ?></h2></div>
        <div class="pgrid"><?php foreach ($prods as $p) echo product_card($p); ?></div>
    </div>
</section>
<?php endif; ?>
<section class="sec">
    <div class="wrap">
        <div class="sec__head"><h2><?= e(t('guides.more')) ?></h2></div>
        <div class="glist"><?php foreach ($all as $s => $og): if ($s === $slug) continue; [$ot, $os] = guide_text($og); ?><a class="gcard" href="<?= e(url('guides/' . $s)) ?>"><h3><?= e($ot) ?></h3><p><?= e($os) ?></p><span><?= e(t('guides.read')) ?></span></a><?php endforeach; ?></div>
    </div>
</section>
<?php page_foot();
