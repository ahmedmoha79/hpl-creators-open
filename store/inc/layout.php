<?php
/** Shared page frame: <head> with search-engine data, header, footer and the chat widget. */

/** The HPL logo (inline, so it is sharp and takes the colour of the text around it). */
function logo_svg(): string
{
    static $svg = null;
    if ($svg === null) {
        $svg = (string)@file_get_contents(STORE_ROOT . '/assets/img/logo.svg');
        $svg = (string)preg_replace('~^<svg ~', '<svg aria-hidden="true" focusable="false" ', $svg);
    }
    return $svg;
}

/**
 * The stylesheet, minified and put inside the page: saves one slow round trip to the server
 * before anything can be shown (the server is far from most buyers). Rebuilt when store.css changes.
 */
function inline_css(): string
{
    $src = STORE_ROOT . '/assets/css/store.css';
    $out = STORE_UPLOADS . '/cache/store-' . @filemtime($src) . '.min.css';
    if (is_file($out)) return (string)file_get_contents($out);
    $css = (string)file_get_contents($src);
    $css = preg_replace('~/\*.*?\*/~s', '', $css);
    $css = preg_replace('~\s+~', ' ', $css);
    $css = preg_replace('~\s*([{};:,>])\s*~', '$1', $css);
    $css = str_replace([';}', 'url(../'], ['}', 'url(/assets/'], $css);
    $css = str_replace('</', '<\\/', trim($css));
    if (!is_dir(dirname($out))) @mkdir(dirname($out), 0755, true);
    @file_put_contents($out, $css);
    return $css;
}

function icon(string $name): string
{
    $p = [
        'wa' => '<path fill="currentColor" d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.9 11.9 0 0 0 4.6 4c1.7.7 2.3.8 3.2.7.5-.1 1.5-.6 1.8-1.2.2-.6.2-1.1.1-1.2l-.5-.3Z"/>',
        'chat' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M4 5h16v11H10l-5 4v-4H4z"/>',
        'tiktok' => '<path fill="currentColor" d="M16.6 3c.4 2 1.6 3.3 3.6 3.5v3a7.4 7.4 0 0 1-3.6-1.1v6.2A5.7 5.7 0 1 1 11 9v3.1a2.7 2.7 0 1 0 2.6 2.7V3h3Z"/>',
        'facebook' => '<path fill="currentColor" d="M13.5 22v-8h2.7l.4-3.2h-3.1V8.8c0-.9.3-1.5 1.6-1.5h1.7V4.4a22 22 0 0 0-2.5-.1c-2.4 0-4.1 1.5-4.1 4.2v2.3H7.5V14h2.7v8h3.3Z"/>',
        'phone' => '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" d="M5 3h4l2 5-2.5 1.5a11 11 0 0 0 6 6L16 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2Z"/>',
    ][$name] ?? '';
    return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . $p . '</svg>';
}

function tel_href(string $n): string { return 'tel:' . preg_replace('~[^\d+]~', '', $n); }

/** Organisation facts for search engines (one block on every page). */
function org_jsonld(): array
{
    $co = company();
    $branches = [];
    foreach ($co['branches'] as $b) {
        [$city, $addr] = array_pad(array_map('trim', explode(':', $b, 2)), 2, '');
        $branches[] = ['@type' => 'Store', 'name' => $co['brand'] . ' ' . $city, 'telephone' => $co['phone'],
            'address' => ['@type' => 'PostalAddress', 'streetAddress' => $addr ?: $city, 'addressLocality' => $city, 'addressCountry' => 'ZM']];
    }
    return [
        '@context' => 'https://schema.org',
        '@type' => 'Store',
        '@id' => abs_url_in('en') . '#store',
        'name' => $co['brand'],
        'legalName' => $co['legal'],
        'url' => abs_url_in('en'),
        'logo' => 'https://' . site_host() . asset('img/logo-mark.png'),
        'image' => 'https://' . site_host() . asset('img/og.jpg'),
        'description' => t('meta.desc'),
        'telephone' => $co['phone'],
        'email' => $co['email'],
        'currenciesAccepted' => 'ZMW, USD',
        'paymentAccepted' => 'Cash, Bank transfer, Western Union, MoneyGram',
        'areaServed' => ['Zambia', 'Democratic Republic of the Congo', 'Madagascar', 'Liberia', 'Tanzania', 'Malawi', 'Zimbabwe', 'Africa'],
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => $co['address'], 'addressLocality' => 'Lusaka', 'addressCountry' => 'ZM'],
        'taxID' => $co['tpin'],
        'sameAs' => array_values(array_filter([$co['tiktok'], $co['facebook'], $co['website'] ?? ''])),
        'department' => $branches,
    ];
}

/**
 * Start a page. $o: nav, image (share picture), body (class), head (extra HTML), noindex,
 * path (page address without language, for canonical/hreflang; defaults to this request).
 */
function page_head(string $title, string $desc = '', array $o = []): void
{
    $co = company();
    $l = lang();
    $nav = [
        'shop/gold-detectors' => t('nav.detectors'),
        'shop/gold-washers'   => t('nav.machines'),
        'videos'              => t('nav.videos'),
        'how-to-buy'          => t('nav.how'),
        'guides'              => t('nav.guides'),
        'contact'             => t('nav.contact'),
    ];
    $here = $o['nav'] ?? '';
    $full = $title === '' ? t('meta.title') : $title . ' | ' . $co['brand'];
    $desc = $desc !== '' ? $desc : t('meta.desc');
    $desc = mb_substr((string)preg_replace('~\s+~', ' ', $desc), 0, 165);
    $img = $o['image'] ?? asset('img/og.jpg');
    $img = preg_match('~^https?://~', $img) ? $img : 'https://' . site_host() . $img;
    $path = ltrim($o['path'] ?? req_path(), '/');
    $wa = wa_link(t('wa.hello'));
    if (!headers_sent()) header('Content-Language: ' . $l);
    ?><!doctype html>
<html lang="<?= e($l) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($full) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if (!empty($o['noindex'])): ?><meta name="robots" content="noindex, nofollow">
<?php else: ?><meta name="robots" content="index, follow, max-image-preview:large, max-video-preview:-1">
<link rel="canonical" href="<?= e(abs_url_in($l, $path)) ?>">
<?php foreach (array_keys(STORE_LANGS) as $code): ?><link rel="alternate" hreflang="<?= $code ?>" href="<?= e(abs_url_in($code, $path)) ?>">
<?php endforeach; ?><link rel="alternate" hreflang="x-default" href="<?= e(abs_url_in('en', $path)) ?>">
<?php endif; ?>
<?php if ($gv = (string)cfg('google_verify', '')): ?><meta name="google-site-verification" content="<?= e($gv) ?>">
<?php endif; ?>
<meta name="theme-color" content="#151412">
<meta property="og:site_name" content="<?= e($co['brand']) ?>">
<meta property="og:title" content="<?= e($full) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<meta property="og:image" content="<?= e($img) ?>">
<meta property="og:url" content="<?= e(abs_url_in($l, $path)) ?>">
<meta property="og:type" content="<?= e($o['og_type'] ?? 'website') ?>">
<meta property="og:locale" content="<?= ['en' => 'en_GB', 'fr' => 'fr_FR', 'sw' => 'sw_TZ'][$l] ?>">
<meta name="twitter:card" content="summary_large_image">
<link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
<link rel="apple-touch-icon" href="<?= e(asset('img/logo-mark.png')) ?>">
<link rel="preload" href="/assets/fonts/barlow-condensed-latin-700-normal.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/assets/fonts/barlow-latin-400-normal.woff2" as="font" type="font/woff2" crossorigin>
<style><?= inline_css() ?></style>
<script type="application/ld+json"><?= json_encode(org_jsonld(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?= $o['head'] ?? '' ?>
</head>
<body class="<?= e($o['body'] ?? '') ?>" data-t="<?= e(url('api/t.php')) ?>"<?= !empty($o['product']) ? ' data-product="' . (int)$o['product'] . '"' : '' ?>>
<a class="skip" href="#main"><?= e(t('a11y.skip')) ?></a>
<header class="top">
    <div class="wrap top__in">
        <a class="brand" href="<?= e(url()) ?>"><img src="<?= e(asset('img/logo-gold.svg')) ?>" alt="<?= e($co['brand']) ?>, Hayati Prospectors Limited" width="242" height="40" fetchpriority="high"></a>
        <nav class="nav" id="nav" aria-label="<?= e(t('a11y.menu')) ?>">
            <?php foreach ($nav as $href => $label): ?>
                <a href="<?= e(url($href)) ?>"<?= $here === $href ? ' aria-current="page"' : '' ?>><?= e($label) ?></a>
            <?php endforeach; ?>
            <span class="nav__langs"><?php foreach (STORE_LANGS as $code => $n): ?><a href="<?= e(lang_url($code)) ?>" hreflang="<?= $code ?>" lang="<?= $code ?>"<?= $code === $l ? ' aria-current="true"' : '' ?>><?= e($n) ?></a><?php endforeach; ?></span>
        </nav>
        <div class="top__acts">
            <span class="langs" role="group" aria-label="<?= e(t('a11y.lang')) ?>">
                <?php foreach (STORE_LANGS as $code => $n): ?>
                    <a href="<?= e(lang_url($code)) ?>" hreflang="<?= $code ?>" lang="<?= $code ?>" title="<?= e($n) ?>"<?= $code === $l ? ' aria-current="true"' : '' ?>><?= e(strtoupper($code)) ?></a>
                <?php endforeach; ?>
            </span>
            <button class="btn btn--gold btn--sm top__chat" type="button" data-chat-open><span class="chatfab__live" aria-hidden="true"></span><?= e(t('chat.live')) ?></button>
            <?php if ($wa !== ''): ?><a class="top__wa" href="<?= e($wa) ?>" target="_blank" rel="noopener" aria-label="WhatsApp <?= e($co['phone']) ?>"><?= icon('wa') ?></a><?php endif; ?>
            <button class="burger" type="button" aria-controls="nav" aria-expanded="false" data-burger><span></span><span></span><span class="sr"><?= e(t('a11y.menu')) ?></span></button>
        </div>
    </div>
</header>
<main id="main">
<?php
}

function page_foot(array $o = []): void
{
    $co = company();
    $year = date('Y');
    ?>
</main>
<footer class="foot">
    <div class="wrap foot__grid">
        <div>
            <a class="foot__logo" href="<?= e(url()) ?>"><img src="<?= e(asset('img/logo-gold.svg')) ?>" alt="<?= e($co['brand']) ?>" width="278" height="46" loading="lazy"></a>
            <p><?= e(t('foot.about')) ?></p>
            <div class="foot__social">
                <?php if ($co['tiktok'] !== ''): ?><a href="<?= e($co['tiktok']) ?>" target="_blank" rel="noopener" aria-label="TikTok"><?= icon('tiktok') ?></a><?php endif; ?>
                <?php if ($co['facebook'] !== ''): ?><a href="<?= e($co['facebook']) ?>" target="_blank" rel="noopener" aria-label="Facebook"><?= icon('facebook') ?></a><?php endif; ?>
                <?php if (wa_link() !== ''): ?><a href="<?= e(wa_link(t('wa.hello'))) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><?= icon('wa') ?></a><?php endif; ?>
            </div>
        </div>
        <div>
            <p class="foot__h"><?= e(t('foot.shop')) ?></p>
            <ul>
                <?php foreach (products(['limit' => 8]) as $p): ?><li><a href="<?= e(product_url($p)) ?>"><?= e(product_title($p)) ?></a></li><?php endforeach; ?>
            </ul>
        </div>
        <div>
            <p class="foot__h"><?= e(t('foot.buying')) ?></p>
            <ul>
                <li><a href="<?= e(url('how-to-buy')) ?>"><?= e(t('nav.how')) ?></a></li>
                <li><a href="<?= e(url('videos')) ?>"><?= e(t('nav.videos')) ?></a></li>
                <li><a href="<?= e(url('guides')) ?>"><?= e(t('nav.guides')) ?></a></li>
                <li><a href="<?= e(consult_url()) ?>" target="_blank" rel="noopener"><?= e(t('btn.consult')) ?></a></li>
                <?php if (request_link() !== ''): ?><li><a href="<?= e(request_link()) ?>" target="_blank" rel="noopener"><?= e(t('req.btn')) ?></a></li><?php endif; ?>
                <li><a href="<?= e(url('track')) ?>"><?= e(t('nav.track')) ?></a></li>
                <li><a href="<?= e(url('delivery')) ?>"><?= e(t('legal.delivery')) ?></a></li>
                <li><a href="<?= e(url('terms')) ?>"><?= e(t('legal.terms')) ?></a></li>
                <li><a href="<?= e(url('privacy')) ?>"><?= e(t('legal.privacy')) ?></a></li>
            </ul>
        </div>
        <div>
            <p class="foot__h"><?= e(t('foot.contact')) ?></p>
            <ul>
                <?php foreach ($co['branches'] as $b): [$city, $addr] = array_pad(array_map('trim', explode(':', $b, 2)), 2, ''); ?>
                    <li><b style="color:#fff;font-weight:600"><?= e($city) ?></b><br><?= e($addr) ?></li>
                <?php endforeach; ?>
                <?php if ($co['phone'] !== ''): ?><li><a href="<?= e(tel_href($co['phone'])) ?>"><?= e($co['phone']) ?></a><?php if ($co['phone2'] !== ''): ?> · <a href="<?= e(tel_href($co['phone2'])) ?>"><?= e($co['phone2']) ?></a><?php endif; ?></li><?php endif; ?>
                <?php if ($co['email'] !== ''): ?><li><a href="mailto:<?= e($co['email']) ?>"><?= e($co['email']) ?></a></li><?php endif; ?>
                <li><button class="linkish" type="button" data-chat-open><?= e(t('chat.open')) ?></button></li>
            </ul>
        </div>
    </div>
    <div class="wrap foot__reg">
        <span>&copy; <?= $year ?> <?= e($co['legal']) ?>. <?= e(t('foot.reg', ['pacra' => $co['pacra'], 'tpin' => $co['tpin']])) ?></span>
        <a href="<?= e(url('verify')) ?>"><?= e(t('foot.check')) ?></a>
    </div>
</footer>
<?php require STORE_ROOT . '/inc/chat_widget.php'; ?>
<script src="<?= e(asset('js/store.js')) ?>" defer></script>
</body>
</html>
<?php
}

/** Price block: kwacha large, rough dollars small. */
function price_html(array $p, string $cls = ''): string
{
    $pp = price_parts($p);
    if (!$pp) return '<span class="price price--ask ' . e($cls) . '">' . e(t('price.ask')) . '</span>';
    return '<span class="price ' . e($cls) . '">' . e($pp['main']) . '</span> <span class="price__usd">' . e($pp['usd']) . '</span>';
}

function product_title(array $p): string { return ($p['brand'] === '' ? '' : $p['brand'] . ' ') . $p['name']; }

/** Product card used on the shop and related lists. */
function product_card(array $p): string
{
    [$sl, $st] = stock_label($p['stock']);
    $href = product_url($p);
    $photo = !str_starts_with((string)$p['image'], 'site/');
    ob_start(); ?>
    <article class="pcard">
        <a class="pcard__img<?= $photo ? ' pcard__img--photo' : '' ?>" href="<?= e($href) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e(product_image($p, 640)) ?>" alt="" loading="lazy" width="640" height="480"></a>
        <div class="pcard__body">
            <p class="pcard__brand"><?= e($p['brand']) ?></p>
            <h3 class="pcard__name"><a href="<?= e($href) ?>"><?= e($p['name']) ?></a></h3>
            <p class="pcard__tag"><?= e(tr($p['tagline'])) ?></p>
            <div class="pcard__row"><span><?= price_html($p) ?></span><span class="stock stock--<?= e($st) ?>"><?= e($sl) ?></span></div>
            <div class="pcard__acts">
                <a class="btn btn--gold btn--sm" href="<?= e($href) ?>"><?= e(t('btn.details')) ?></a>
                <?php if (wa_link() !== ''): ?><a class="btn btn--wa btn--sm" href="<?= e(wa_for($p, t('wa.product', ['name' => product_title($p), 'url' => abs_url('product/' . $p['slug'])]))) ?>" target="_blank" rel="noopener"><?= icon('wa') ?> WhatsApp</a><?php endif; ?>
            </div>
        </div>
    </article>
    <?php return (string)ob_get_clean();
}

function dur_txt(?int $s): string { return $s ? sprintf('%d:%02d', intdiv($s, 60), $s % 60) : ''; }

/** One video: our own file plays in place (cover first, so no data is used until tapped). */
function video_tile(array $v, string $size = ''): string
{
    $title = tr($v['title']);
    $poster = $v['poster'] ? thumb((string)$v['poster'], 360) : '';
    $src = $v['file'] ? upload_url($v['file']) : '';
    $embed = !$src && $v['embed_url'] ? embed_src((string)$v['embed_url']) : null;
    $meta = array_filter([$v['customer'], $v['place']]);
    ob_start(); ?>
    <figure class="vtile vtile--<?= e($v['orientation']) ?> <?= e($size) ?>">
        <div class="vtile__media"<?= $embed ? ' data-embed="' . e($embed) . '"' : '' ?>>
            <?php if ($src): ?>
                <button class="vtile__play" type="button" data-video-play data-src="<?= e($src) ?>" aria-label="<?= e(t('video.play', ['t' => $title])) ?>">
                    <?php if ($poster): ?><img src="<?= e($poster) ?>" alt="" loading="lazy" decoding="async" width="360" height="640"><?php endif; ?>
                    <span class="vtile__btn" aria-hidden="true"></span>
                    <?php if (!empty($v['duration'])): ?><span class="vtile__dur"><?= e(dur_txt((int)$v['duration'])) ?></span><?php endif; ?>
                </button>
            <?php elseif ($embed): ?>
                <button class="vtile__play" type="button" data-embed-play aria-label="<?= e(t('video.play', ['t' => $title])) ?>">
                    <?php if ($poster): ?><img src="<?= e($poster) ?>" alt="" loading="lazy"><?php endif; ?>
                    <span class="vtile__btn" aria-hidden="true"></span>
                </button>
            <?php else: ?>
                <a class="vtile__play" href="<?= e(safe_href((string)$v['embed_url'])) ?>" target="_blank" rel="noopener"><?php if ($poster): ?><img src="<?= e($poster) ?>" alt="" loading="lazy"><?php endif; ?><span class="vtile__btn" aria-hidden="true"></span></a>
            <?php endif; ?>
        </div>
        <figcaption>
            <strong><?= e($title) ?></strong>
            <?php if ($meta): ?><span class="vtile__meta"><?= e(implode(', ', $meta)) ?></span><?php endif; ?>
            <?php if (!empty($v['product_slug'])): ?><a class="vtile__prod" href="<?= e(url('product/' . $v['product_slug'])) ?>"><?= e($v['product_name']) ?></a><?php endif; ?>
        </figcaption>
    </figure>
    <?php return (string)ob_get_clean();
}

/** VideoObject data for search engines (Google shows these in video results). */
function videos_jsonld(array $list): string
{
    $items = [];
    foreach ($list as $v) {
        if (!$v['file'] || !$v['poster']) continue;
        $items[] = array_filter([
            '@type' => 'VideoObject',
            'name' => tr($v['title']),
            'description' => tr($v['title']) . ' (' . company()['brand'] . ', ' . ($v['place'] ?: 'Zambia') . ')',
            'thumbnailUrl' => 'https://' . site_host() . upload_url($v['poster']),
            'contentUrl' => 'https://' . site_host() . upload_url($v['file']),
            'uploadDate' => date('c', strtotime((string)($v['recorded_on'] ?: $v['created_at']))),
            'duration' => !empty($v['duration']) ? 'PT' . (int)$v['duration'] . 'S' : null,
            'publisher' => ['@id' => abs_url_in('en') . '#store'],
        ], fn($x) => $x !== null);
    }
    if (!$items) return '';
    return '<script type="application/ld+json">' . json_encode(['@context' => 'https://schema.org', '@graph' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) . '</script>';
}

/** "Can't find the machine you need?" band, sends the visitor to WhatsApp. */
function request_band(string $cls = ''): string
{
    if (request_link() === '') return '';
    return '<section class="band ' . e($cls) . '"><div class="wrap band__in"><div><h2>' . e(t('req.h')) . '</h2><p>' . e(t('req.d')) . '</p></div>'
        . '<a class="btn btn--wa btn--lg" href="' . e(request_link()) . '" target="_blank" rel="noopener">' . icon('wa') . ' ' . e(t('req.btn')) . '</a></div></section>';
}

/** "Not sure which detector?" band, sends the visitor to the free advice page. */
function consult_band(): string
{
    return '<section class="band band--consult"><div class="wrap band__in"><div><h2>' . e(t('home.consult_h')) . '</h2><p>' . e(t('home.consult_d')) . '</p></div>'
        . '<a class="btn btn--gold btn--lg" href="' . e(consult_url()) . '" target="_blank" rel="noopener">' . e(t('btn.consult')) . '</a></div></section>';
}

/** Empty video slot shown only to signed-in team members, so they see where uploads appear. */
function video_slot_hint(string $section): string
{
    store_session();
    if (empty($_SESSION['shop_admin'])) return '';
    return '<p class="slot-hint">' . e(t('video.slot_admin', ['s' => video_sections()[$section] ?? $section])) . ' <a href="/admin/videos.php">Admin &rsaquo; Videos</a></p>';
}
