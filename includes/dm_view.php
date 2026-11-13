<?php
/**
 * Messenger screen, shared by creator/messages.php and admin/messages.php.
 * Expects $dm = [
 *   'mode' => 'creator'|'admin', 'base' => page path, 'scope' => 'mine'|'all',
 *   'list' => dm_list(...), 'thread' => null|['id','title','people','member','support'],
 *   'messages' => dm_thread_messages(...), 'unreadMine' => int, 'teamUrl' => creator: link that opens the team chat,
 * ]
 * Phones: one screen at a time (list, or the open chat full screen). Desktop: list + chat side by side.
 */
$dmThread = $dm['thread'];
$dmIsAdmin = $dm['mode'] === 'admin';
$dmVerified = '<svg class="dm-tick" viewBox="0 0 24 24" aria-label="HPL team" role="img"><path fill="currentColor" d="M12 1.8l2.6 1.9 3.2-.1 1 3 2.6 1.9-1 3 1 3-2.6 1.9-1 3-3.2-.1L12 22.2l-2.6-1.9-3.2.1-1-3-2.6-1.9 1-3-1-3 2.6-1.9 1-3 3.2.1z"/><path fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M8 12.3l2.7 2.7L16.2 9.5"/></svg>';
$dmAvatar = function (string $name, string $avatar, bool $team = false, string $size = ''): string {
    $initial = strtoupper(mb_substr(trim($name) !== '' ? trim($name) : '?', 0, 1));
    $hue = $team ? '' : ' style="--h:' . (crc32(mb_strtolower($name)) % 360) . '"';
    $inner = $avatar !== '' ? '<img src="' . e($avatar) . '" alt="" loading="lazy">' : ($team ? 'H' : e($initial));
    return '<span class="dm-av' . ($size ? ' dm-av--' . $size : '') . ($team ? ' is-team' : '') . '"' . $hue . ' aria-hidden="true">' . $inner . '</span>';
};
// The team chat is always at the top for creators.
$dmList = $dm['list'];
if (!$dmIsAdmin) {
    usort($dmList, function ($a, $b) { return ($b['support'] <=> $a['support']) ?: ($b['ts'] <=> $a['ts']); });
}
$dmHasTeam = false;
foreach ($dmList as $c) { if ($c['support']) { $dmHasTeam = true; } }
$dmLead = null;
if ($dmThread) {
    foreach ($dmThread['people'] as $p) {
        if ((int)$p['id'] !== (int)$dm['me']) { $dmLead = $p; break; }
    }
}
$dmTeamThread = $dmThread && $dmThread['support'] && !$dmIsAdmin;
$dmSeed = json_encode([
    'me'        => (int)$dm['me'],
    'feed'      => u('creator/dm_feed.php'),
    'base'      => u($dm['base']),
    'scope'     => $dm['scope'],
    'csrf'      => csrf_token(),
    'thread'    => $dmThread ? (int)$dmThread['id'] : 0,
    'member'    => $dmThread ? (bool)$dmThread['member'] : false,
    'admin'     => $dmIsAdmin,
    'team'      => $dmTeamThread,
    'community' => u($dmIsAdmin ? 'admin/community.php' : 'creator/community.php'),
    'messages'  => $dm['messages'],
    'now'       => time(),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$dmHref = function (int $id) use ($dm): string {
    return u($dm['base'] . '?' . ($dm['scope'] === 'all' ? 'scope=all&' : '') . 'thread=' . $id);
};
?>
<link rel="stylesheet" href="<?= e(u('assets/css/dm.css?v=20261108d')) ?>">
<div class="dm<?= $dmThread ? ' dm--open' : '' ?>" id="dm">
    <script type="application/json" id="dmSeed"><?= $dmSeed ?></script>

    <aside class="dm__side" aria-label="Conversations">
        <div class="dm__top">
            <h1 class="dm__title">Messages</h1>
            <button type="button" class="dm__iconbtn" data-dm-compose aria-label="New message" title="New message">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
            </button>
        </div>
        <?php if ($dmIsAdmin): ?>
            <nav class="dm__scope" aria-label="Which conversations">
                <a href="<?= e(u($dm['base'])) ?>" class="<?= $dm['scope'] === 'mine' ? 'is-on' : '' ?>">Team inbox<?php if ($dm['unreadMine'] > 0): ?> <b><?= (int)$dm['unreadMine'] ?></b><?php endif; ?></a>
                <a href="<?= e(u($dm['base'] . '?scope=all')) ?>" class="<?= $dm['scope'] === 'all' ? 'is-on' : '' ?>">All chats</a>
            </nav>
        <?php endif; ?>
        <div class="dm__tools">
            <label class="dm__search">
                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                <input type="search" id="dmSearch" placeholder="Search" autocomplete="off" aria-label="Search conversations">
            </label>
            <div class="dm__filters" role="tablist" aria-label="Filter">
                <button type="button" role="tab" class="is-on" data-filter="all" aria-selected="true">All</button>
                <button type="button" role="tab" data-filter="unread" aria-selected="false">Unread</button>
                <?php if ($dmIsAdmin): ?><button type="button" role="tab" data-filter="community" aria-selected="false">From Community</button><?php endif; ?>
            </div>
        </div>

        <ul class="dm__list" id="dmList">
            <?php if (!$dmIsAdmin && !$dmHasTeam): ?>
                <li data-pinned>
                    <a class="dm-row" href="<?= e($dm['teamUrl']) ?>" data-name="hpl team">
                        <span class="dm-row__av"><?= $dmAvatar('HPL', '', true) ?></span>
                        <span class="dm-row__body">
                            <span class="dm-row__line"><strong>HPL team</strong><?= $dmVerified ?></span>
                            <span class="dm-row__line"><span class="dm-row__preview">Questions about payouts, views or your account? Ask us here.</span></span>
                        </span>
                    </a>
                </li>
            <?php endif; ?>
            <?php foreach ($dmList as $c): $lead = $c['lead']; $isTeam = $c['support'] && !$dmIsAdmin; $unread = $c['unread'] > 0 && (!$dmThread || (int)$dmThread['id'] !== $c['id']); ?>
                <li>
                    <a class="dm-row<?= $dmThread && (int)$dmThread['id'] === $c['id'] ? ' is-on' : '' ?><?= $unread ? ' is-unread' : '' ?>" href="<?= e($dmHref($c['id'])) ?>"
                       data-id="<?= (int)$c['id'] ?>" data-name="<?= e(mb_strtolower($c['title'])) ?>" data-community="<?= $c['community'] ? 1 : 0 ?>">
                        <span class="dm-row__av"><?= $dmAvatar($lead['name'], $lead['avatar'], $isTeam) ?><?php if ($lead['active'] && !$isTeam): ?><i class="dm-online" aria-label="Online"></i><?php endif; ?></span>
                        <span class="dm-row__body">
                            <span class="dm-row__line">
                                <strong><?= e($c['title']) ?></strong><?= $isTeam ? $dmVerified : '' ?>
                                <?php if ($dmIsAdmin && $c['community']): ?><em class="dm-chip">Community</em><?php endif; ?>
                                <time data-ts="<?= (int)$c['ts'] ?>"><?= e(dm_short_ago($c['ts'])) ?></time>
                            </span>
                            <span class="dm-row__line">
                                <span class="dm-row__preview"><?= e($c['preview']) ?></span>
                                <?php if ($unread): ?><b class="dm-badge"><?= $c['unread'] > 99 ? '99+' : (int)$c['unread'] ?></b><?php endif; ?>
                            </span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="dm__empty" id="dmEmpty" <?= ($dmList || !$dmIsAdmin) ? 'hidden' : '' ?>>
            <strong><?= $dm['scope'] === 'all' ? 'No conversations yet' : 'Inbox zero' ?></strong>
            <span><?= $dm['scope'] === 'all' ? 'Chats between creators will show here.' : 'When a creator messages the team, or asks for help in Community, it lands here.' ?></span>
        </div>
        <?php if (!$dmIsAdmin && count($dmList) < 4): ?>
            <button type="button" class="dm__tip" data-dm-compose>
                <strong>Message another creator</strong>
                <span>Swap tips with people you met in Community.</span>
            </button>
        <?php endif; ?>
        <div class="dm__empty" id="dmNoMatch" hidden><strong>No matches</strong><span>Try another name or filter.</span></div>

        <button type="button" class="dm__fab" data-dm-compose aria-label="New message">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
        </button>
    </aside>

    <section class="dm__main" aria-label="Conversation">
        <?php if (!$dmThread): ?>
            <div class="dm__blank">
                <div class="dm__blankart" aria-hidden="true"><span></span><span></span><span></span></div>
                <h2><?= $dmIsAdmin ? 'Pick a conversation' : 'Your messages' ?></h2>
                <p><?= $dmIsAdmin ? 'Creators who message the team, or ask for help in Community, appear on the left.' : 'Talk to the HPL team about payouts, views or your account, or message another creator.' ?></p>
                <button type="button" class="dm__primary" data-dm-compose>New message</button>
            </div>
        <?php else: ?>
            <header class="dm__bar">
                <a class="dm__back" href="<?= e(u($dm['base'] . ($dm['scope'] === 'all' ? '?scope=all' : ''))) ?>" aria-label="Back to messages">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6"/></svg>
                </a>
                <?php if ($dmTeamThread): ?>
                    <span class="dm-row__av"><?= $dmAvatar('HPL', '', true, 'md') ?></span>
                <?php elseif ($dmLead): ?>
                    <span class="dm-row__av"><?= $dmAvatar($dmLead['full_name'], (string)($dmLead['avatar_url'] ?? ''), false, 'md') ?><?php if ((int)$dmLead['is_active']): ?><i class="dm-online"></i><?php endif; ?></span>
                <?php endif; ?>
                <div class="dm__who">
                    <strong><span><?= e($dmThread['title']) ?></span><?= $dmTeamThread ? $dmVerified : '' ?></strong>
                    <span id="dmStatus" data-off="<?= e($dmTeamThread ? 'Usually replies within a day' : 'Offline') ?>" class="<?= !$dmTeamThread && $dmLead && (int)$dmLead['is_active'] ? 'is-on' : '' ?>"><?= $dmTeamThread ? 'Usually replies within a day' : ($dmLead && (int)$dmLead['is_active'] ? 'Online' : 'Offline') ?></span>
                </div>
                <?php if ($dmIsAdmin && $dmLead && $dmLead['role'] === 'creator'): ?>
                    <a class="dm__ghost" href="<?= e(u('admin/creator_detail.php?id=' . (int)$dmLead['id'] . '#clips')) ?>">Profile</a>
                <?php endif; ?>
            </header>
            <?php if (!$dmThread['member']): ?>
                <div class="dm__note">Private chat between creators. You can read it and remove messages that break the rules.</div>
            <?php endif; ?>

            <div class="dm__scroll" id="dmScroll">
                <div class="dm__feed" id="dmFeed" aria-live="polite"></div>
                <noscript><?php foreach ($dm['messages'] as $m): ?><p><strong><?= e($m['name']) ?>:</strong> <?= $m['html'] ? $m['text'] : nl2br(e($m['text'])) ?></p><?php endforeach; ?></noscript>
            </div>
            <button type="button" class="dm__jump" id="dmJump" hidden aria-label="Go to the latest message">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
            </button>

            <?php if ($dmThread['member']): ?>
                <div class="dm__compose">
                    <?php if ($dmTeamThread && function_exists('support_faq')): ?>
                        <div class="dm__quick" id="dmQuick" aria-label="Common questions">
                            <?php foreach (array_slice(support_faq(), 0, 8) as $f): ?>
                                <button type="button" data-q="<?= e($f['question']) ?>"><?= e($f['question']) ?></button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form class="dm__form" id="dmForm" method="post" autocomplete="off">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="reply">
                        <input type="hidden" name="thread" value="<?= (int)$dmThread['id'] ?>">
                        <textarea id="dmInput" name="message" rows="1" maxlength="1000" placeholder="Message" aria-label="Write a message" required></textarea>
                        <button class="dm__send" type="submit" aria-label="Send">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h13"/><path d="M13 6l6 6-6 6"/></svg>
                        </button>
                    </form>
                </div>
            <?php elseif ($dmLead): ?>
                <div class="dm__readonly">Read only &middot; <a href="<?= e(u($dm['base'] . '?user=' . (int)$dmLead['id'])) ?>">Message <?= e($dmLead['full_name']) ?> yourself</a></div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<div class="dm-sheet" id="dmSheet" hidden>
    <div class="dm-sheet__panel" role="dialog" aria-modal="true" aria-labelledby="dmSheetTitle">
        <div class="dm-sheet__grab" aria-hidden="true"></div>
        <div class="dm-sheet__head">
            <h2 id="dmSheetTitle">New message</h2>
            <button type="button" class="dm__iconbtn" data-dm-close aria-label="Close">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
        </div>
        <label class="dm__search dm-sheet__search">
            <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" id="dmPeopleQ" placeholder="Search creators by name" autocomplete="off" aria-label="Search people">
        </label>
        <?php if (!$dmIsAdmin): ?>
            <a class="dm-row dm-sheet__team" href="<?= e($dm['teamUrl']) ?>"><span class="dm-row__av"><?= $dmAvatar('HPL', '', true) ?></span><span class="dm-row__body"><span class="dm-row__line"><strong>HPL team</strong><?= $dmVerified ?></span><span class="dm-row__line"><span class="dm-row__preview">Support for payouts, views and your account</span></span></span></a>
        <?php endif; ?>
        <p class="dm-sheet__label" id="dmPeopleLabel">Creators</p>
        <ul class="dm-sheet__list" id="dmPeople"></ul>
        <p class="dm-sheet__hint" id="dmPeopleHint">Loading…</p>
    </div>
</div>
<script src="<?= e(u('assets/js/dm.js?v=20261103a')) ?>"></script>
