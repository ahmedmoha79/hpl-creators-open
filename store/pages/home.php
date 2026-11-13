<?php
require STORE_ROOT . '/inc/layout.php';
$co = company();
$detectors = products(['category' => 'gold-detectors']);
$washer = product_by_slug('hpl-sluice-wash-plant');
require_once STORE_ROOT . '/inc/guides.php';
$tabs = [];
foreach (['field', 'mumbwa', 'customers', 'learn'] as $s) {
    $l = videos(['section' => $s, 'limit' => 10]);
    if ($l) $tabs[$s] = $l;
}
$washVids = videos(['section' => 'machines', 'limit' => 6]);
$howVids = videos(['section' => 'how_to_buy', 'limit' => 3]);
$shopVids = videos(['section' => 'showroom', 'limit' => 3]);
// Front picture: HPL's own team in the field (from HPL's Facebook page).
$heroFile = is_file(STORE_UPLOADS . '/site/hpl-team-field.jpg') ? 'site/hpl-team-field.jpg' : (is_file(STORE_UPLOADS . '/site/mg-bg2.jpg') ? 'site/mg-bg2.jpg' : '');
$heroImg = $heroFile ? thumb($heroFile, 1100) : asset('img/hero.jpg');
$heroSet = $heroFile ? thumb($heroFile, 600) . ' 600w, ' . $heroImg . ' 1100w' : '';
$specOf = function (array $p, int $i): string { $s = lang_list($p['specs']); return is_array($s) && isset($s[$i][1]) ? (string)$s[$i][1] : ''; };
$find = function (array $p, string $label) { foreach (lang_list($p['specs'], 'en') as $i => $s) if (($s[0] ?? '') === $label) return $i; return null; };
$allV = [];
foreach ($tabs as $l) $allV = array_merge($allV, $l);

page_head('', '', [
    'nav' => '',
    'head' => '<link rel="preload" as="image" href="' . e($heroImg) . '"' . ($heroSet ? ' imagesrcset="' . e($heroSet) . '" imagesizes="(min-width: 921px) 55vw, 100vw"' : '') . ' fetchpriority="high">' . videos_jsonld(array_slice($allV, 0, 12)),
]);
?>
<section class="hero">
    <div class="hero__bg"><img src="<?= e($heroImg) ?>"<?= $heroSet ? ' srcset="' . e($heroSet) . '" sizes="100vw"' : '' ?> alt="" width="1400" height="663" fetchpriority="high" decoding="async"></div>
    <div class="wrap hero__in">
        <h1><?= e(t('home.h1')) ?></h1>
        <p class="lead"><?= e(t('home.lead')) ?></p>
        <div class="hero__acts">
            <a class="btn btn--gold btn--lg" href="<?= e(url('shop/gold-detectors')) ?>"><?= e(t('home.cta_shop')) ?></a>
            <?php if (wa_link() !== ''): ?><a class="btn btn--wa btn--lg" href="<?= e(wa_link(t('wa.hello'))) ?>" target="_blank" rel="noopener"><?= icon('wa') ?> <?= e(t('home.cta_wa')) ?></a><?php endif; ?>
        </div>
        <ul class="hero__facts">
            <li><?= e(t('home.fact1')) ?></li>
            <li><?= e(t('home.fact2')) ?></li>
            <li><?= e(t('home.fact3')) ?></li>
        </ul>
    </div>
</section>

<?php if ($detectors): ?>
<div class="wrap rail" aria-label="<?= e(t('home.rail')) ?>">
    <ul class="rail__list">
        <?php foreach (array_slice($detectors, 0, 3) as $p): $pp = price_parts($p); ?>
            <li><a class="rail__item" href="<?= e(product_url($p)) ?>">
                <img src="<?= e(product_image($p, 320)) ?>" alt="" width="112" height="96">
                <span>
                    <span class="rail__brand"><?= e($p['brand']) ?></span>
                    <span class="rail__name" style="display:block"><?= e($p['name']) ?></span>
                    <?php if ($pp): ?><span class="rail__price"><?= e($pp['main']) ?></span><span class="rail__usd"><?= e($pp['usd']) ?></span><?php else: ?><span class="rail__price" style="font-size:20px"><?= e(t('price.ask')) ?></span><?php endif; ?>
                </span>
            </a></li>
        <?php endforeach; ?>
    </ul>
    <p class="rail__note"><span><?= e(t('home.rail_note')) ?></span><span><?= e(t('home.rail_note2')) ?></span></p>
</div>
<?php endif; ?>

<?php if ($tabs): $names = video_sections(); $first = array_key_first($tabs); ?>
<section class="sec">
    <div class="wrap" data-tabs>
        <div class="sec__head">
            <div><h2><?= e(t('home.videos_h')) ?></h2><p><?= e(t('home.videos_sub')) ?></p></div>
            <a class="sec__link" href="<?= e(url('videos')) ?>"><?= e(t('btn.all_videos')) ?></a>
        </div>
        <div class="chips" role="tablist">
            <?php foreach ($tabs as $s => $l): ?>
                <button class="chip" role="tab" id="tab-<?= $s ?>" aria-controls="vp-<?= $s ?>" aria-selected="<?= $s === $first ? 'true' : 'false' ?>" tabindex="<?= $s === $first ? '0' : '-1' ?>"><?= e($names[$s]) ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($tabs as $s => $l): ?>
            <div class="reel" role="tabpanel" id="vp-<?= $s ?>" aria-labelledby="tab-<?= $s ?>"<?= $s === $first ? '' : ' hidden' ?>><?php foreach ($l as $v) echo video_tile($v); ?></div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if (count($detectors) > 1): ?>
<section class="sec sec--paper" id="compare">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('cmp.h')) ?></h2><p><?= e(t('cmp.sub')) ?></p></div></div>
        <div class="tablewrap">
            <table class="compare">
                <thead><tr><td></td>
                    <?php foreach ($detectors as $p): ?><th scope="col"><a href="<?= e(product_url($p)) ?>"><img src="<?= e(product_image($p, 320)) ?>" alt="" loading="lazy"><span class="compare__name"><?= e($p['name']) ?></span></a></th><?php endforeach; ?>
                </tr></thead>
                <tbody>
                    <tr><th scope="row"><?= e(t('cmp.price')) ?></th><?php foreach ($detectors as $p): ?><td><?= price_html($p) ?></td><?php endforeach; ?></tr>
                    <tr><th scope="row"><?= e(t('cmp.best')) ?></th><?php foreach ($detectors as $p): ?><td><?= e(tr($p['best_for'])) ?></td><?php endforeach; ?></tr>
                    <?php foreach (['Technology' => 'cmp.tech', 'Waterproof' => 'cmp.water', 'Water' => 'cmp.water', 'Weight' => 'cmp.weight', 'Battery' => 'cmp.battery', 'Maker\'s warranty' => 'cmp.warranty'] as $label => $key):
                        $cells = array_map(function ($p) use ($find, $specOf, $label) { $i = $find($p, $label); return $i === null ? null : $specOf($p, $i); }, $detectors);
                        if (count(array_filter($cells, fn($c) => $c !== null)) === 0) continue;
                        if ($label === 'Water') continue; ?>
                        <tr><th scope="row"><?= e(t($key)) ?></th><?php foreach ($detectors as $j => $p): $c = $cells[$j] ?? null; if ($c === null && $label === 'Waterproof') { $i = $find($p, 'Water'); $c = $i === null ? '' : $specOf($p, $i); } ?><td><?= e((string)$c) ?></td><?php endforeach; ?></tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot><tr><td></td><?php foreach ($detectors as $p): ?><td><a class="btn btn--dark btn--sm" href="<?= e(product_url($p)) ?>"><?= e(t('btn.details')) ?></a></td><?php endforeach; ?></tr></tfoot>
            </table>
        </div>
        <p class="muted small" style="margin-top:12px"><?= e(t('cmp.note')) ?></p>
    </div>
</section>
<?php endif; ?>

<?= consult_band() ?>

<section class="sec">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('home.get_h')) ?></h2><p><?= e(t('home.get_sub')) ?></p></div></div>
        <ul class="gets">
            <?php for ($i = 1; $i <= 4; $i++): ?><li><b><?= e(t('get.' . $i)) ?></b><span><?= e(t('get.' . $i . 'd')) ?></span></li><?php endfor; ?>
        </ul>
    </div>
</section>

<?php if ($washer): ?>
<section class="sec sec--dark">
    <div class="wrap split split--center">
        <div class="reel"><?php foreach ($washVids as $v) echo video_tile($v); ?></div>
        <div>
            <h2><?= e(t('home.wash_h')) ?></h2>
            <p class="lead"><?= e(tr($washer['tagline'])) ?></p>
            <p class="muted"><?= e(t('home.wash_d')) ?></p>
            <ul class="ticks ticks--dark"><?php foreach (lang_list($washer['in_box']) as $b): ?><li><?= e($b) ?></li><?php endforeach; ?></ul>
            <div class="hero__acts">
                <a class="btn btn--gold" href="<?= e(product_url($washer)) ?>"><?= e(t('btn.details')) ?></a>
                <?php if (wa_link() !== ''): ?><a class="btn btn--wa" href="<?= e(wa_for($washer + ['cat_slug' => 'gold-washers'], t('wa.product', ['name' => product_title($washer), 'url' => abs_url('product/' . $washer['slug'])]))) ?>" target="_blank" rel="noopener"><?= icon('wa') ?> <?= e(t('home.wash_wa')) ?></a><?php endif; ?>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<?php $acc = products(['category' => 'accessories']); if ($acc): ?>
<section class="sec sec--paper">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('home.acc_h')) ?></h2><p><?= e(t('home.acc_sub')) ?></p></div><a class="sec__link" href="<?= e(url('shop/accessories')) ?>"><?= e(t('btn.see_all')) ?></a></div>
        <div class="pgrid"><?php foreach ($acc as $p) echo product_card($p); ?></div>
    </div>
</section>
<?php endif; ?>

<section class="sec">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('home.flow_h')) ?></h2><p><?= e(t('home.flow_sub')) ?></p></div><a class="sec__link" href="<?= e(url('how-to-buy')) ?>"><?= e(t('home.flow_more')) ?></a></div>
        <ol class="flow">
            <?php for ($i = 1; $i <= 4; $i++): ?><li><h3><?= e(t('flow.' . $i)) ?></h3><p><?= e(t('flow.' . $i . 'd')) ?></p></li><?php endfor; ?>
        </ol>
    </div>
</section>

<section class="sec sec--paper">
    <div class="wrap split">
        <div>
            <h2><?= e(t('how.pay_h')) ?></h2>
            <ul class="paylist">
                <li><b><?= e(t('pay.showroom')) ?></b><span><?= e(t('how.pay_showroom')) ?></span></li>
                <li><b><?= e(t('pay.bank')) ?></b><span><?= e(t('how.pay_bank')) ?></span></li>
                <li><b><?= e(t('pay.wu')) ?> / <?= e(t('pay.mg')) ?></b><span><?= e(t('how.pay_wu')) ?></span></li>
                <li><b><?= e(t('pay.instal')) ?></b><span><?= e(t('how.pay_instal')) ?></span></li>
            </ul>
            <h3 style="margin-top:32px"><?= e(t('how.deliv_h')) ?></h3>
            <p class="muted"><?= e(t('home.deliv_short')) ?></p>
        </div>
        <?php if ($howVids): ?><div class="reel" style="grid-auto-columns:minmax(180px,1fr)"><?php foreach ($howVids as $v) echo video_tile($v); ?></div><?php endif; ?>
    </div>
</section>

<section class="sec" id="showroom">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('home.showroom_h')) ?></h2><p><?= e(t('home.showroom_sub')) ?></p></div></div>
        <div>
            <div class="shops shops--3">
                <?php foreach ($co['branches'] as $b): [$city, $addr] = array_pad(array_map('trim', explode(':', $b, 2)), 2, ''); ?>
                    <div class="shop">
                        <h3><?= e($city) ?></h3>
                        <p><?= e($addr) ?><?= $co['hours'] !== '' ? '<br>' . e($co['hours']) : '' ?></p>
                        <div class="shop__acts">
                            <a class="btn btn--line btn--sm" href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(rawurlencode('HPL Gold Detectors ' . $addr . ' ' . $city . ' Zambia')) ?>" target="_blank" rel="noopener"><?= e(t('home.map')) ?></a>
                            <a class="btn btn--line btn--sm" href="<?= e(tel_href($co['phone'])) ?>"><?= e(t('show.call')) ?></a>
                        </div>
                    </div>
                <?php endforeach; ?>
                <div class="shop">
                    <h3><?= e(t('cta.video_call')) ?></h3>
                    <p><?= e(t('home.video_call_d')) ?></p>
                    <div class="shop__acts">
                        <?php if (wa_link() !== ''): ?><a class="btn btn--wa btn--sm" href="<?= e(wa_link(t('cta.video_call_msg'))) ?>" target="_blank" rel="noopener"><?= icon('wa') ?> WhatsApp</a><?php endif; ?>
                        <button class="btn btn--dark btn--sm" type="button" data-chat-open data-chat-text="<?= e(t('cta.video_call_msg')) ?>"><?= e(t('chat.open')) ?></button>
                    </div>
                </div>
            </div>
            <?php if ($shopVids): ?><div class="reel" style="margin-top:28px"><?php foreach ($shopVids as $v) echo video_tile($v); ?></div><?php endif; ?>
        </div>
    </div>
</section>

<section class="sec">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('home.guides_h')) ?></h2><p><?= e(t('home.guides_sub')) ?></p></div><a class="sec__link" href="<?= e(url('guides')) ?>"><?= e(t('guides.all')) ?></a></div>
        <div class="glist">
            <?php foreach (array_slice(guides(), 0, 6, true) as $slug => $g): [$gt, $gs] = guide_text($g); ?>
                <a class="gcard" href="<?= e(url('guides/' . $slug)) ?>"><h3><?= e($gt) ?></h3><p><?= e($gs) ?></p><span><?= e(t('guides.read')) ?></span></a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<?= request_band() ?>

<section class="sec sec--paper">
    <div class="wrap">
        <div class="sec__head"><h2><?= e(t('home.faq_h')) ?></h2></div>
        <div class="faq">
            <?php for ($i = 1; $i <= 8; $i++): ?>
                <details><summary><?= e(t('faq.' . $i . 'q')) ?></summary><p><?= e(t('faq.' . $i . 'a')) ?></p></details>
            <?php endfor; ?>
        </div>
    </div>
</section>
<script type="application/ld+json"><?= json_encode(['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => array_map(fn($i) => ['@type' => 'Question', 'name' => t('faq.' . $i . 'q'), 'acceptedAnswer' => ['@type' => 'Answer', 'text' => t('faq.' . $i . 'a')]], range(1, 8))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php page_foot();
