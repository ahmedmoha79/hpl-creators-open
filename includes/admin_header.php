<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/branding.php';
require_once __DIR__ . '/autosync.php';
$user = require_admin();
hpl_autosync_maybe();
touch_presence((int)$user['id']);
$active = basename($_SERVER['SCRIPT_NAME'] ?? 'index.php');

$pendingPortfolio = (int)fetch_val('SELECT COUNT(*) FROM portfolio_links WHERE status = "pending"', [], 0);
$pendingWithdrawals = (int)fetch_val('SELECT COUNT(*) FROM withdrawals WHERE status = "pending"', [], 0);
$pendingClips = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE status = "pending"', [], 0);
$openFeedback = (int)fetch_val('SELECT COUNT(*) FROM feedback WHERE status = "open"', [], 0);
$dmUnread = dm_unread_total((int)$user['id']);
$pendingPages = (int)fetch_val('SELECT COUNT(*) FROM social_pages WHERE status = "pending"', [], 0);
$newComments = 0;
// Creator questions still waiting for a team reply (same as the "Unanswered" list), so the badge clears once answered.
try { $newComments = (int)fetch_val('SELECT COUNT(*) FROM lesson_comments c JOIN users u ON u.id = c.user_id WHERE u.role <> "admin" AND c.deleted_at IS NULL AND c.parent_id IS NULL AND NOT EXISTS (SELECT 1 FROM lesson_comments r JOIN users ru ON ru.id = r.user_id WHERE r.parent_id = c.id AND ru.role = "admin" AND r.deleted_at IS NULL)', [], 0); } catch (Throwable $e) {}
$notifyCount = $pendingPortfolio + $pendingWithdrawals + $openFeedback + $dmUnread;

// Grouped by job, in plain words. Each item: [page, label, icon, badge count, other pages that belong to it].
// Clips the website is holding or wants looked at (includes/integrity.php).
$riskCount = 0;
try { $riskCount = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE risk_level = "high" AND risk_ok = 0 AND status <> "rejected"', [], 0); } catch (Throwable $e) {}
$navGroups = [
    '' => [
        ['admin/index.php', 'Home: what to do now', 'home', 0, []],
        ['admin/brain.php', 'Brain', 'sparkles', 0, []],
        ['admin/analytics.php', 'Analytics', 'chart', 0, []],
        ['admin/risk.php', 'Checks', 'shield', $riskCount ?? 0, []],
    ],
    'Creators' => [
        ['admin/creators.php', 'All creators (by step)', 'users', 0, ['creator_detail.php']],
        ['admin/inactivity.php', 'Inactive creators', 'clock', 0, []],
        ['admin/demo.php', 'Demo account', 'video', 0, []],
        ['admin/portfolio.php', 'Approve new creators', 'check-circle', $pendingPortfolio, []],
        ['admin/social_pages.php', 'Verify pages', 'shield', $pendingPages, []],
        ['admin/tiktok_accounts.php', 'TikTok accounts', 'tiktok', 0, []],
    ],
    'Talk to creators' => [
        ['admin/send.php', 'Send reminders', 'send', 0, []],
        ['admin/messages.php', 'Private messages', 'chat', $dmUnread, []],
        ['admin/announcements.php', 'Announcements', 'megaphone', 0, []],
        ['admin/offers.php', 'Offers that pop up', 'award', 0, []],
        ['admin/lesson_comments.php', 'Academy Q&A', 'chat', $newComments, []],
        ['admin/feedback.php', 'Feedback inbox', 'mail', $openFeedback, []],
        ['admin/community.php', 'Community', 'chats', 0, []],
    ],
    'Campaigns & clips' => [
        ['admin/campaigns.php', 'Campaigns', 'target', $pendingClips, []],
        ['admin/footage.php', 'Raw footage', 'film', 0, []],
        ['admin/ready_clips.php', 'Ready clips', 'video', 0, []],
        ['admin/links.php', 'Clips sent by creators', 'link', 0, []],
    ],
    'Money' => [
        ['admin/withdrawals.php', 'Pay withdrawals', 'wallet', $pendingWithdrawals, []],
        ['admin/rewards.php', 'Rewards & bonuses', 'award', 0, []],
        ['admin/payment_gateways.php', 'Payment methods', 'banknote', 0, []],
    ],
    'Academy' => [
        ['admin/courses.php', 'Courses & lessons', 'graduation', 0, []],
        ['admin/tutorials.php', 'Tutorial videos', 'video', 0, []],
        ['admin/questions.php', 'Test questions', 'check', 0, []],
    ],
    'Settings' => [
        ['admin/settings.php', 'Rates & site settings', 'settings', 0, []],
        ['admin/dashboard_controls.php', 'Sign-up & creator pages', 'settings', 0, []],
        ['admin/email.php', 'Email setup & inbox', 'mail', 0, []],
        ['admin/branding.php', 'Logo & favicon', 'folder', 0, []],
        ['admin/telegram.php', 'Telegram and AI help', 'chat', 0, []],
        ['admin/health.php', 'Site health', 'shield', 0, []],
    ],
];
?><!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8">
    
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <?php require_once __DIR__ . "/theme.php"; ?>
    <title><?= e($pageTitle ?? 'Admin') ?> &middot; <?= e(APP_NAME) ?> Admin</title>
    <?php require_once __DIR__ . '/homescreen.php'; homescreen_head(); ?>
    <link rel="stylesheet" href="<?= e(u('assets/css/style.css')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/topbar.css')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/mobile.css?v=20261113b')) ?>">
    <link rel="stylesheet" href="<?= e(u('assets/css/brand.css?v=20261108d')) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
    <link rel="stylesheet" href="<?= e(u('assets/css/ui.css?v=20261110a')) ?>">
    <script src="<?= e(u('assets/js/ui.js?v=20261113a')) ?>"></script>
    <script src="<?= e(u('assets/js/topbar.js?v=20261113a')) ?>" defer></script>
    <script src="<?= e(u('assets/js/mobile.js?v=20261113b')) ?>" defer></script>
    <?php branding_head(); theme_head(); ?>
</head>
<?php $adminDm = basename($_SERVER['SCRIPT_NAME'] ?? '') === 'messages.php'; ?>
<body class="ui<?= $adminDm ? ' dm-page' : '' ?><?= $adminDm && (int)($_GET['thread'] ?? 0) > 0 ? ' dm-thread' : '' ?>">
<div class="app">
    <aside class="app__sidebar" id="appSidebar">
        <div class="sidebar-head">
            <a class="brand" href="<?= e(u('admin/index.php')) ?>">
                <span class="brand__mark">HPL</span>
                <span>Admin</span>
            </a>
            <button type="button" class="sidebar-close" data-sidebar-toggle aria-label="Close navigation"><?= icon('x') ?></button>
        </div>
        <div class="sidebar-user mt-3">
            <div class="avatar"><?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?></div>
            <div style="min-width:0">
                <div class="sidebar-user__name"><?= e($user['full_name']) ?></div>
                <div class="sidebar-user__mail">Administrator</div>
            </div>
        </div>
        <nav class="sidebar-nav">
            <?php foreach ($navGroups as $groupLabel => $navItems): ?>
                <?php if ($groupLabel !== ''): ?><div class="sidebar-nav__label"><?= e($groupLabel) ?></div><?php endif; ?>
                <?php foreach ($navItems as $navItem): $isOn = $active === basename($navItem[0]) || in_array($active, $navItem[4], true); ?>
                    <a href="<?= e(u($navItem[0])) ?>" class="sidebar-tab <?= $isOn ? 'is-active' : '' ?>"<?= $isOn ? ' aria-current="page"' : '' ?>>
                        <?= icon($navItem[2]) ?><span><?= e($navItem[1]) ?></span>
                        <?php if ($navItem[3] > 0): ?><span class="nav-count"><?= (int)$navItem[3] > 99 ? '99+' : (int)$navItem[3] ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <div class="sidebar-nav__label">Account</div>
            <a class="sidebar-tab" href="<?= e(u('index.php')) ?>"><?= icon('external') ?><span>View website</span></a>
            <a class="sidebar-tab<?= basename($_SERVER['SCRIPT_NAME'] ?? '') === 'account.php' ? ' is-active' : '' ?>" href="<?= e(u('admin/account.php')) ?>"><?= icon('lock') ?><span>My password</span></a>
            <a class="sidebar-tab" href="<?= e(u('logout.php')) ?>"><?= icon('logout') ?><span>Sign out</span></a>
        </nav>
    </aside>
    <div class="app__main">
        <?php require __DIR__ . '/topbar.php'; ?>
        <nav class="mobile-dock" aria-label="Admin navigation">
            <a class="mobile-dock__item <?= $active === 'index.php' ? 'is-active' : '' ?>" href="<?= e(u('admin/index.php')) ?>">
                <span class="mobile-dock__ico"><?= icon('home') ?></span><span>Home</span>
            </a>
            <a class="mobile-dock__item <?= in_array($active, ['creators.php', 'creator_detail.php'], true) ? 'is-active' : '' ?>" href="<?= e(u('admin/creators.php')) ?>">
                <span class="mobile-dock__ico"><?= icon('users') ?></span><span>Creators</span>
            </a>
            <a class="mobile-dock__item <?= in_array($active, ['portfolio.php', 'links.php', 'footage.php', 'ready_clips.php'], true) ? 'is-active' : '' ?>" href="<?= e(u('admin/portfolio.php')) ?>">
                <span class="mobile-dock__ico"><?= icon('check-circle') ?></span><span>Review</span>
            </a>
            <a class="mobile-dock__item <?= $active === 'withdrawals.php' ? 'is-active' : '' ?>" href="<?= e(u('admin/withdrawals.php')) ?>">
                <span class="mobile-dock__ico"><?= icon('wallet') ?></span><span>Payouts</span>
            </a>
            <button type="button" class="mobile-dock__item" data-sidebar-toggle>
                <span class="mobile-dock__ico"><?= icon('menu') ?></span><span>More</span>
            </button>
        </nav>
        <div class="app__content">
            <?= render_flashes() ?>
