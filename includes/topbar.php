<?php
/**
 * Global top bar (dark) shared by the creator and admin dashboards.
 *
 * Expects $user to be set by the including header. Falls back to the
 * session user so it can be dropped onto any logged-in page.
 */
if (!isset($user) || !is_array($user)) {
    $user = current_user();
}
if (!$user) {
    return;
}

require_once __DIR__ . '/earnings.php';

if (!function_exists('tb_icon')) {
    function tb_icon(string $name): string
    {
        $icons = [
            'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
            'menu'     => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
            'chevron'  => '<path d="m6 9 6 6 6-6"/>',
            'star'     => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1-5.4-2.9-5.4 2.9 1-6.1L3.2 9.5l6.1-.9L12 3Z"/>',
            'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.2 9a2.8 2.8 0 1 1 4.1 2.5c-.8.5-1.3 1-1.3 2"/><path d="M12 17h.01"/>',
            'sparkle'  => '<path d="M12 3.5 13.6 9l5.4 1.6-5.4 1.6L12 17.5l-1.6-5.3L5 10.6 10.4 9 12 3.5Z"/><path d="M19 16.5l.7 2.1 2.1.7-2.1.7-.7 2.1-.7-2.1-2.1-.7 2.1-.7.7-2.1Z"/>',
            'message'  => '<path d="M20.5 11.5a8.5 8.5 0 0 1-12.4 7.6L3.5 20.5l1.4-4.6A8.5 8.5 0 1 1 20.5 11.5Z"/><path d="M8.5 11.5h.01M12 11.5h.01M15.5 11.5h.01"/>',
            'bell'     => '<path d="M18 9a6 6 0 1 0-12 0c0 4-1.5 5.2-2 6h16c-.5-.8-2-2-2-6Z"/><path d="M10 19a2.2 2.2 0 0 0 4 0"/>',
            'arrow'    => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'user'     => '<circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/>',
            'chart'    => '<path d="M4 19V5M4 19h16"/><path d="M8 15v-4M12 15V8M16 15v-6"/>',
            'wallet'   => '<path d="M3.5 7.5A2.5 2.5 0 0 1 6 5h11.5A2.5 2.5 0 0 1 20 7.5v9a2.5 2.5 0 0 1-2.5 2.5H6a2.5 2.5 0 0 1-2.5-2.5v-9Z"/><path d="M20 10h-4.5a2 2 0 0 0 0 4H20"/>',
            'logout'   => '<path d="M14 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2v-2"/><path d="M9 12h11M17 8l4 4-4 4"/>',
            'campaign' => '<path d="M4 10v4l12 5V5L4 10Z"/><path d="M16 8.5a3.5 3.5 0 0 1 0 7"/><path d="M7 15v4"/>',
            'course'   => '<path d="M4 7.5 12 4l8 3.5-8 3.5-8-3.5Z"/><path d="M7 9.4V15c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5V9.4"/>',
            'post'     => '<path d="M5 4h14v16l-3-2-4 2-4-2-3 2V4Z"/><path d="M8.5 9h7M8.5 13h5"/>',
            'people'   => '<circle cx="9" cy="8" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.2a3 3 0 0 1 0 5.6M17 19a5.5 5.5 0 0 0-2.4-4.5"/>',
            'refresh'  => '<path d="M20 11a8 8 0 1 0-2.3 5.7"/><path d="M20 5v6h-6"/>',
        ];
        $body = $icons[$name] ?? $icons['search'];
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $body . '</svg>';
    }
}

$tbIsAdmin    = (($user['role'] ?? 'creator') === 'admin');
$tbUid        = (int)$user['id'];
$tbName       = (string)$user['full_name'];
$tbEmail      = (string)$user['email'];
$tbAvatar     = trim((string)($user['avatar_url'] ?? ''));
$tbInitial    = strtoupper(mb_substr($tbName, 0, 1));
$tbFirstName  = explode(' ', trim($tbName))[0] ?? $tbName;

$tbHome       = $tbIsAdmin ? 'admin/index.php' : 'creator/index.php';
$tbProfile    = $tbIsAdmin ? 'admin/settings.php' : 'creator/profile.php';
$tbMessages   = $tbIsAdmin ? 'admin/messages.php' : 'creator/messages.php';
$tbNotifsAll  = $tbIsAdmin ? 'admin/notifications.php' : 'creator/notifications.php';
$tbHelp       = $tbIsAdmin ? 'admin/support.php' : 'creator/support.php';
$tbEarnings   = $tbIsAdmin ? 'admin/withdrawals.php' : 'creator/earnings.php';

$tbStats      = $tbIsAdmin ? null : creator_stats($tbUid);
$tbBalance    = $tbStats ? (float)$tbStats['balance'] : 0.0;
$tbUnreadDm   = dm_unread_total($tbUid);
$tbUnreadNote = notif_unread_count($tbUid);
$tbNotes      = notif_recent($tbUid, 8);


$tbSearchPlaceholder = $tbIsAdmin
    ? 'Search'
    : 'Search';
?>
<header class="hplbar" role="banner" data-hplbar
        data-tb-counts="<?= e(u('api/topbar.php?action=counts')) ?>"
        data-tb-search="<?= e(u('api/topbar.php?action=search')) ?>"
        data-tb-read="<?= e(u('api/topbar.php?action=read_all')) ?>"
        data-tb-seen="<?= e(u('api/topbar.php?action=seen')) ?>"
        data-tb-csrf="<?= e(csrf_token()) ?>"
        data-tb-role="<?= $tbIsAdmin ? 'admin' : 'creator' ?>"
        data-tb-uid="<?= (int)$tbUid ?>">

    <button class="hplbar__burger sidebar-toggle" type="button" data-sidebar-toggle aria-label="Open navigation" aria-controls="appSidebar">
        <?= tb_icon('menu') ?>
    </button>

    <h1 class="hplbar__title"><?= e($pageTitle ?? ($tbIsAdmin ? 'Admin' : 'Dashboard')) ?></h1>

    <div class="hplbar__search">
        <button class="hplbar__searchbtn" type="button" data-tb-search-open
                aria-haspopup="dialog" aria-expanded="false" aria-label="Search">
            <span class="hplbar__searchicon"><?= tb_icon('search') ?></span>
            <span class="hplbar__searchtext"><?= e($tbSearchPlaceholder) ?></span>
            <kbd class="hplbar__kbd" aria-hidden="true"><span data-tb-mod>Ctrl</span><span>K</span></kbd>
        </button>
    </div>

    <nav class="hplbar__actions" aria-label="Account">

        <?php if (!$tbIsAdmin): ?>
        <a class="hplbar__balance" href="<?= e(u($tbEarnings)) ?>" aria-label="Balance: <?= e(money($tbBalance)) ?>">
            <span class="hplbar__balance-label">Balance</span>
            <span class="hplbar__balance-value" data-tb-balance><?= e(money($tbBalance)) ?></span>
        </a>
        <?php endif; ?>



        <div class="hplmenu hplmenu--hide-sm" data-tb-menu="help">
            <button class="hplbar__icon" type="button" aria-haspopup="menu" aria-expanded="false" aria-label="Help" data-tb-menu-btn data-tb-hide-compact>
                <?= tb_icon('help') ?>
            </button>
            <div class="hplmenu__panel" role="menu" hidden>
                <div class="hplmenu__head">
                    <span class="hplmenu__title">Help &amp; resources</span>
                </div>
                <a class="hplmenu__item" role="menuitem" href="<?= e(u($tbHelp)) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('help') ?></span>
                    <span class="hplmenu__item-txt"><strong>Help center</strong><small>Guides and answers</small></span>
                </a>
<?php if ($tbIsAdmin || (($GLOBALS['user']['clip_path'] ?? ($user['clip_path'] ?? '')) !== 'ready')): ?>                <a class="hplmenu__item" role="menuitem" href="<?= e(u($tbIsAdmin ? 'admin/tutorials.php' : 'creator/course.php')) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('course') ?></span>
                    <span class="hplmenu__item-txt"><strong><?= $tbIsAdmin ? 'Tutorials' : 'Creator Academy' ?></strong><small>Learn the workflow</small></span>
                </a><?php endif; ?>
                <a class="hplmenu__item" role="menuitem" href="<?= e(u('tutorial.php')) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('post') ?></span>
                    <span class="hplmenu__item-txt"><strong>Tutorial videos</strong><small>Watch quick clips</small></span>
                </a>
                <div class="hplmenu__sep"></div>
                <a class="hplmenu__item" role="menuitem" href="<?= e(u($tbHelp)) ?>" data-tb-support>
                    <span class="hplmenu__ico"><?= tb_icon('message') ?></span>
                    <span class="hplmenu__item-txt"><strong>Contact support</strong><small>Chat with the team</small></span>
                </a>
                <div class="hplmenu__hint"><kbd data-tb-mod>Ctrl</kbd><kbd>K</kbd> <span>to search anything</span></div>
            </div>
        </div>

        <a class="hplbar__icon" href="<?= e(u($tbMessages)) ?>" title="Messages"
           aria-label="Messages<?= $tbUnreadDm > 0 ? ', ' . (int)$tbUnreadDm . ' unread' : '' ?>" data-tb-hide-compact>
            <?= tb_icon('message') ?>
            <span class="hplbar__badge<?= $tbUnreadDm > 0 ? '' : ' is-empty' ?>" data-tb-badge="messages"><?= $tbUnreadDm > 9 ? '9+' : (int)$tbUnreadDm ?></span>
        </a>

        <div class="hplmenu" data-tb-menu="notifications">
            <button class="hplbar__icon" type="button" aria-haspopup="menu" aria-expanded="false"
                    aria-label="Notifications<?= $tbUnreadNote > 0 ? ', ' . (int)$tbUnreadNote . ' unread' : '' ?>" data-tb-menu-btn>
                <?= tb_icon('bell') ?>
                <span class="hplbar__badge<?= $tbUnreadNote > 0 ? '' : ' is-empty' ?>" data-tb-badge="notifications"><?= $tbUnreadNote > 9 ? '9+' : (int)$tbUnreadNote ?></span>
            </button>
            <div class="hplmenu__panel hplmenu__panel--wide" role="menu" hidden>
                <div class="hplmenu__head">
                    <span class="hplmenu__title">Notifications</span>
                    <button class="hplmenu__linkbtn" type="button" data-tb-readall>Mark all as read</button>
                </div>
                <div class="hplalerts" data-tb-alerts>
                    <button type="button" class="hplalerts__btn" data-tb-sound aria-pressed="true">Message sound: on</button>
                    <button type="button" class="hplalerts__btn" data-tb-push hidden>Turn on phone alerts</button>
                </div>
                <div class="hplnotifs" data-tb-notiflist>
                    <?php if (!$tbNotes): ?>
                        <div class="hplmenu__empty">
                            <span class="hplmenu__empty-ico"><?= tb_icon('bell') ?></span>
                            <strong>You're all caught up</strong>
                            <small>New activity will show up here.</small>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tbNotes as $n): ?>
                            <a class="hplnotif<?= (int)$n['is_read'] === 0 ? ' is-unread' : '' ?>" role="menuitem"
                               href="<?= e(u('api/topbar.php?action=open&id=' . (int)$n['id'])) ?>">
                                <span class="hplnotif__dot" aria-hidden="true"></span>
                                <span class="hplnotif__body">
                                    <span class="hplnotif__title"><?= e($n['title']) ?></span>
                                    <?php if (!empty($n['body'])): ?><span class="hplnotif__text"><?= e($n['body']) ?></span><?php endif; ?>
                                    <span class="hplnotif__time"><?= e(time_ago($n['created_at'])) ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="hplmenu__foot">
                    <a class="hplmenu__footlink" href="<?= e(u($tbNotifsAll)) ?>">View all notifications</a>
                </div>
            </div>
        </div>

        <div class="hplmenu hplmenu--profile" data-tb-menu="account">
            <button class="hplbar__profile" type="button" aria-haspopup="menu" aria-expanded="false"
                    aria-label="Account menu for <?= e($tbName) ?>" data-tb-menu-btn>
                <?php if ($tbAvatar !== ''): ?>
                    <img class="hplbar__avatar" src="<?= e($tbAvatar) ?>" alt="">
                <?php else: ?>
                    <span class="hplbar__avatar hplbar__avatar--initial" aria-hidden="true"><?= e($tbInitial) ?></span>
                <?php endif; ?>
                <span class="hplbar__chev" aria-hidden="true"><?= tb_icon('chevron') ?></span>
            </button>
            <div class="hplmenu__panel hplmenu__panel--right" role="menu" hidden>
                <div class="hplmenu__user">
                    <?php if ($tbAvatar !== ''): ?>
                        <img class="hplbar__avatar hplbar__avatar--lg" src="<?= e($tbAvatar) ?>" alt="">
                    <?php else: ?>
                        <span class="hplbar__avatar hplbar__avatar--lg hplbar__avatar--initial" aria-hidden="true"><?= e($tbInitial) ?></span>
                    <?php endif; ?>
                    <span class="hplmenu__user-txt">
                        <strong><?= e($tbName) ?></strong>
                        <small><?= e($tbEmail) ?></small>
                    </span>
                </div>
                <a class="hplmenu__item" role="menuitem" href="<?= e(u($tbProfile)) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('user') ?></span>
                    <span class="hplmenu__item-txt"><strong>View profile</strong><small>Photo and details</small></span>
                </a>
                <a class="hplmenu__item" role="menuitem" href="<?= e(u($tbIsAdmin ? 'admin/settings.php' : 'creator/earnings.php')) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('wallet') ?></span>
                    <span class="hplmenu__item-txt"><strong><?= $tbIsAdmin ? 'Settings' : 'Earnings & payouts' ?></strong><small><?= $tbIsAdmin ? 'Site configuration' : 'Balance and withdrawals' ?></small></span>
                </a>
                <a class="hplmenu__item hplmenu__item--compact" role="menuitem" href="<?= e(u($tbMessages)) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('message') ?></span>
                    <span class="hplmenu__item-txt"><strong>Messages</strong><small><?= $tbUnreadDm > 0 ? (int)$tbUnreadDm . ' unread' : 'Chat with the team' ?></small></span>
                </a>
                <a class="hplmenu__item hplmenu__item--compact" role="menuitem" href="<?= e(u($tbHelp)) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('help') ?></span>
                    <span class="hplmenu__item-txt"><strong>Help &amp; support</strong><small>Answers and contact</small></span>
                </a>
                <div class="hplmenu__sep"></div>
                <?php if (!empty($_SESSION['demo_admin_id'])): ?>
                <a class="hplmenu__item" role="menuitem" href="<?= e(u('admin/demo.php?leave=1')) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('arrow') ?></span>
                    <span class="hplmenu__item-txt"><strong>Back to admin</strong><small>You are using the demo account</small></span>
                </a>
                <?php endif; ?>
                <a class="hplmenu__item hplmenu__item--danger" role="menuitem" href="<?= e(u('logout.php')) ?>">
                    <span class="hplmenu__ico"><?= tb_icon('logout') ?></span>
                    <span class="hplmenu__item-txt"><strong>Sign out</strong><small>End this session</small></span>
                </a>
            </div>
        </div>
    </nav>
</header>

<div class="hplpalette" data-tb-palette hidden>
    <div class="hplpalette__backdrop" data-tb-palette-close></div>
    <div class="hplpalette__box" role="dialog" aria-modal="true" aria-label="Search the dashboard">
        <div class="hplpalette__head">
            <span class="hplpalette__ico"><?= tb_icon('search') ?></span>
            <input class="hplpalette__input" type="text" id="hplPaletteInput" data-tb-palette-input
                   placeholder="<?= e($tbSearchPlaceholder) ?>" autocomplete="off" spellcheck="false"
                   role="combobox" aria-expanded="true" aria-controls="hplPaletteResults" aria-autocomplete="list">
            <button class="hplpalette__close" type="button" data-tb-palette-close aria-label="Close search"><?= tb_icon('close') ?></button>
        </div>
        <div class="hplpalette__results" id="hplPaletteResults" data-tb-palette-results role="listbox" aria-label="Results">
            <div class="hplpalette__loading"><?= tb_icon('refresh') ?> <span>Loading...</span></div>
        </div>
        <div class="hplpalette__foot">
            <span><kbd>&uarr;</kbd><kbd>&darr;</kbd> navigate</span>
            <span><kbd>&crarr;</kbd> open</span>
            <span><kbd>esc</kbd> close</span>
        </div>
    </div>
</div>
