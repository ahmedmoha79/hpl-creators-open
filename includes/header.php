<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/homescreen.php';
require_once __DIR__ . '/signup_source.php';
signup_source_capture();
// A briefing call the owner scheduled also fires from public pages (cheap check of one setting).
if ((int)setting('brain_brief_call_at', '0') > 0 || (string)setting('brain_brief_daily', '') !== '') {
    try { require_once __DIR__ . '/brain_call.php'; brain_brief_due(); } catch (Throwable $e) {}
}
$pageTitle = $pageTitle ?? APP_NAME;
$user = current_user();
try { require_once __DIR__ . '/visits.php'; visit_track($user ?: null); } catch (Throwable $e) {}
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">
    
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php
    $metaDesc = $metaDesc ?? 'Join the HPL Gold Detectors creator program in Zambia: post ready-made short clips on your TikTok and get paid per real view by mobile money.';
    $siteOrigin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com'));
    $canonical = $siteOrigin . strtok((string)($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $ogImage = $siteOrigin . u('assets/img/og-image.jpg');
    ?>
    <meta name="description" content="<?= e($metaDesc) ?>">
    <link rel="canonical" href="<?= e($canonical) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(APP_NAME) ?>">
    <meta property="og:title" content="<?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?>">
    <meta property="og:description" content="<?= e($metaDesc) ?>">
    <meta property="og:url" content="<?= e($canonical) ?>">
    <meta property="og:image" content="<?= e($ogImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <?php homescreen_head(); ?>
    <meta name="theme-color" content="#faf8f4">
    <meta name="color-scheme" content="light">
    <title><?= e($pageTitle) ?> &middot; <?= e(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= e(u('assets/css/style.css?v=20261108d')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/brand.css?v=20261108d')) ?>">
    <?php foreach (($pageCss ?? []) as $hplCss): ?><link rel="stylesheet" href="<?= e(u($hplCss)) ?>"><?php endforeach; ?>
    <?php foreach (($pagePreload ?? []) as $hplPre): /* the first big picture, asked for before anything else */ ?><link rel="preload" as="image" href="<?= e(u($hplPre)) ?>" fetchpriority="high"><?php endforeach; ?>
    <script src="<?= e(u('assets/js/brain.js?v=20261113a')) ?>"></script>
    <script src="<?= e(u('assets/js/ui.js?v=20261113a')) ?>"></script>
    <?php branding_head(); ?>
    <style>
    /* Keep the homepage illustration proportional when HTML dimensions are present. */
    .site .hero__media { min-width:0; max-width:100%; }
    .site .hero__media .creator-visual { width:100%; max-width:100%; margin:0; }
    .site .hero__media img {
        display:block; width:100%; max-width:100%;
        height:auto!important; min-height:0!important; max-height:none!important;
        aspect-ratio:auto!important; object-fit:contain!important;
    }
    @media(max-width:760px) {
        .site .hero__media { margin-top:28px; }
    }
    </style>
</head>
<body class="site">
<header class="site-nav">
    <div class="container site-nav__inner">
        <a class="brand" href="<?= e(u('index.php')) ?>">
            <span class="brand__mark">HPL</span>
            <span><?= e(APP_NAME) ?></span>
        </a>
        <button class="nav-toggle" type="button" onclick="document.querySelector('.nav-links').classList.toggle('is-open')" aria-label="Toggle menu"><?= icon('menu') ?></button>
        <nav class="nav-links">
            <a href="<?= e(u('index.php#how')) ?>">How it works</a>
            <a href="<?= e(u('index.php#faq')) ?>">FAQ</a>
            <a class="hide-in-app" href="<?= e(u('app.php')) ?>">Get the app</a>
            <?php if ($user): ?>
                <a href="<?= e(u($user['role'] === 'admin' ? 'admin/index.php' : 'creator/index.php')) ?>">Dashboard</a>
                <a class="btn btn--ghost btn--sm" href="<?= e(u('logout.php')) ?>">Sign out</a>
            <?php else: ?>
                <a href="<?= e(u('login.php')) ?>">Sign in</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main>
    <?php if ($f = render_flashes()): ?>
        <div class="container" style="padding-top:20px"><?= $f ?></div>
    <?php endif; ?>
