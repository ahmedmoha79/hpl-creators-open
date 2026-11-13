<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/homescreen.php';
require_once __DIR__ . '/autosync.php';
$user = require_creator();
touch_presence((int)$user['id']);
try { require_once __DIR__ . '/visits.php'; visit_track($user); } catch (Throwable $e) {}
try { require_once __DIR__ . '/integrity.php'; integrity_touch_device((int)$user['id']); } catch (Throwable $e) {}
hpl_autosync_maybe();
$active = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

$dmUnread = dm_unread_total((int)$user['id']);
$isHome = $active === 'index.php';
$isCoursePage = in_array($active, ['course_intro.php', 'course.php'], true);
$isCampaignPage = in_array($active, ['campaign.php', 'campaign_join.php', 'campaign_submit.php'], true);
$isDmThread = $active === 'messages.php' && (int)($_GET['thread'] ?? 0) > 0;
$hideDock = in_array($active, ['campaign.php', 'campaign_join.php', 'campaign_submit.php', 'course.php'], true) || $isDmThread;
$uiVer = '20261110a';

/**
 * Sidebar item. On the dashboard, the tabbed sections are buttons that switch
 * tabs in place; elsewhere they are links back to that tab.
 */
$navItem = function (string $label, string $icon, string $href, bool $isActive = false, ?string $tab = null, int $count = 0) use ($isHome): string {
    $countHtml = $count > 0 ? '<span class="nav-count">' . ($count > 9 ? '9+' : $count) . '</span>' : '';
    if ($tab !== null && $isHome) {
        return '<button type="button" class="sidebar-tab' . ($tab === 'home' ? ' is-active' : '') . '" data-tab="' . e($tab) . '">' . icon($icon) . '<span>' . e($label) . '</span>' . $countHtml . '</button>';
    }
    return '<a class="sidebar-tab' . ($isActive ? ' is-active' : '') . '" href="' . e(u($href)) . '"' . ($isActive ? ' aria-current="page"' : '') . '>' . icon($icon) . '<span>' . e($label) . '</span>' . $countHtml . '</a>';
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . "/theme.php"; ?>
    <?php homescreen_head(); ?>
    <title><?= e($pageTitle ?? 'Dashboard') ?> &middot; <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,600;12..96,700;12..96,800&family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(u('assets/css/style.css?v=20261108d')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/topbar.css?v=20261108d')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/mobile.css?v=20261113b')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/brand.css?v=20261108d')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/ui.css?v=' . $uiVer)) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/motion.css?v=20261109c')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/easy.css?v=20261108d')) ?>">
    <script src="<?= e(u('assets/js/brain.js?v=20261113a')) ?>"></script>
    <script src="<?= e(u('assets/js/ui.js?v=20261113a')) ?>"></script>
    <script src="<?= e(u('assets/js/topbar.js?v=20261113a')) ?>" defer></script>
    <script src="<?= e(u('assets/js/mobile.js?v=20261113b')) ?>" defer></script>
    <?php branding_head(); theme_head(); ?>
</head>
<body class="ui <?= $hideDock ? 'no-mobile-dock' : '' ?> <?= $active === 'campaign.php' ? 'campaign-view' : '' ?><?= $active === 'messages.php' ? ' dm-page' : '' ?><?= $isDmThread ? ' dm-thread' : '' ?>">
<div class="app">
    <aside class="app__sidebar" id="appSidebar" aria-label="Main navigation">
        <div class="sidebar-head">
            <a class="brand" href="<?= e(u('creator/index.php')) ?>">
                <span class="brand__mark">HPL</span>
                <span><?= e(APP_NAME) ?></span>
            </a>
            <button type="button" class="sidebar-close" data-sidebar-toggle aria-label="Close navigation"><?= icon('x') ?></button>
        </div>

        <nav class="sidebar-nav">
            <?php /* Grouped by what the creator wants to do (earn, get paid, learn, get help), in the order they do it. */ ?>
            <div class="sidebar-nav__label">Earn money</div>
            <?= $navItem('Home', 'home', 'creator/index.php?tab=home', false, 'home') ?>
            <?= $navItem('Campaigns', 'target', 'creator/index.php?tab=campaigns', $isCampaignPage, 'campaigns') ?>
            <?= $navItem('Footage to download', 'download', 'creator/library.php', $active === 'library.php') ?>
            <?= $navItem('My clips', 'video', 'creator/submit_links.php', $active === 'submit_links.php') ?>

            <div class="sidebar-nav__label">Get paid</div>
            <?= $navItem('Wallet', 'wallet', 'creator/earnings.php', $active === 'earnings.php') ?>
            <?= $navItem('Withdraw', 'wallet', 'creator/withdraw.php', $active === 'withdraw.php') ?>
            <?= $navItem('Rewards', 'award', 'creator/rewards.php', $active === 'rewards.php') ?>

            <div class="sidebar-nav__label">Learn &amp; news</div>
            <?php if (($user['clip_path'] ?? '') !== 'ready'): /* only for people who edit raw footage (owner, 2026-11-13) */ ?><?= $navItem('Creator Academy', 'graduation', 'creator/course.php', $isCoursePage) ?><?php endif; ?>
            <?= $navItem('Announcements', 'megaphone', 'creator/index.php?tab=announcements', false, 'announcements') ?>
            <?= $navItem('Community', 'chats', 'creator/community.php', $active === 'community.php') ?>

            <div class="sidebar-nav__label">Help</div>
            <?= $navItem('Messages', 'chat', 'creator/messages.php', $active === 'messages.php', null, $dmUnread) ?>
            <?= $navItem('Help & support', 'support', 'creator/support.php', $active === 'support.php') ?>
            <?= $navItem('Send feedback', 'mail', 'creator/feedback.php', $active === 'feedback.php') ?>
            <span class="hide-in-app" style="display:contents"><?= $navItem('Get the app', 'download', 'app.php') ?></span>
            <?= $navItem('Back to website', 'external', 'index.php') ?>
            <?php if (!empty($_SESSION['demo_admin_id'])): ?><?= $navItem('Back to admin', 'arrow-left', 'admin/demo.php?leave=1') ?><?php endif; ?>
            <?= $navItem('Sign out', 'logout', 'logout.php') ?>
        </nav>

        <a class="sidebar-user" href="<?= e(u('creator/profile.php')) ?>">
            <div class="avatar"><?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?></div>
            <div style="min-width:0">
                <div class="sidebar-user__name"><?= e($user['full_name']) ?></div>
                <div class="sidebar-user__mail"><?= e($user['email']) ?></div>
            </div>
        </a>
    </aside>

    <div class="app__main">
        <?php require __DIR__ . '/topbar.php'; ?>
        <?php if (!$hideDock): ?>
        <nav class="mobile-dock mobile-dock--4" aria-label="Dashboard navigation">
            <a class="mobile-dock__item <?= $isHome ? 'is-active' : '' ?>" href="<?= e(u('creator/index.php?tab=home')) ?>" data-dock-tab="home">
                <span class="mobile-dock__ico"><?= icon('home') ?></span><span>Home</span>
            </a>
            <a class="mobile-dock__item <?= $isCampaignPage ? 'is-active' : '' ?>" href="<?= e(u('creator/index.php?tab=campaigns')) ?>" data-dock-tab="campaigns">
                <span class="mobile-dock__ico"><?= icon('target') ?></span><span>Campaigns</span>
            </a>
            <a class="mobile-dock__item <?= $active === 'community.php' ? 'is-active' : '' ?>" href="<?= e(u('creator/community.php')) ?>">
                <span class="mobile-dock__ico"><?= icon('users') ?></span><span>Community</span>
            </a>
            <a class="mobile-dock__item <?= in_array($active, ['earnings.php', 'withdraw.php'], true) ? 'is-active' : '' ?>" href="<?= e(u('creator/earnings.php')) ?>">
                <span class="mobile-dock__ico"><?= icon('wallet') ?></span><span>Wallet</span>
            </a>
        </nav>
        <?php endif; ?>
        <div class="app__content">
            <?= render_flashes() ?>
            <?php if (false): ?>
            <form method="post" action="<?= e(u('creator/heard.php')) ?>" class="card" style="padding:16px;margin-bottom:16px;border:1px solid #efe4d6">
                <?= csrf_field() ?><input type="hidden" name="back" value="creator/index.php">
                <p style="margin:0 0 10px;font-weight:700">One quick question</p>
                <?php heard_fields(); ?>
                <button class="btn btn--primary btn--sm" type="submit">Save</button>
            </form>
            <?php endif; ?>
            <?php if (empty($hideAppBar) && $isHome && preg_match('/Android|iPhone|iPad/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')) && empty($_COOKIE['hpl_app_bar']) && !empty($user['town'])): /* one question at a time on Home: the town comes first */ ?>
            <div class="flash flash--info hide-in-app" id="appBar" style="display:flex;gap:10px;align-items:center;justify-content:space-between">
                <span><strong>Put HPL Creators on your home screen.</strong> It opens like an app, with one tap.</span>
                <span style="display:flex;gap:6px;flex:none">
                    <a class="btn btn--primary btn--sm" href="<?= e(u('app.php')) ?>">Show me</a>
                    <button type="button" class="btn btn--ghost btn--sm" aria-label="Hide" onclick="document.cookie='hpl_app_bar=1;path=/;max-age=1209600';document.getElementById('appBar').remove()">&times;</button>
                </span>
            </div>
            <?php endif; ?>
