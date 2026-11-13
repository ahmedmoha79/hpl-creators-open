<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/community.php';
$user = current_user();
if (!$user) {
    require_login();
}
if (!in_array($user['role'], ['creator', 'admin'], true)) {
    redirect('login.php');
}
$pageTitle = 'Community';
$uid = (int)$user['id'];
$isAdmin = $user['role'] === 'admin';

// No-JavaScript fallback for the live chat: plain form post.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $newId = 0;
    $err = community_post($uid, (string)($_POST['message'] ?? ''), $newId);
    if ($err) {
        flash('error', $err);
    } elseif (!$isAdmin && $newId > 0) {
        require_once __DIR__ . '/../includes/dm.php';
        if (dm_forward_from_community($uid, $newId, (string)($_POST['message'] ?? ''))) {
            flash('success', 'Sent to the HPL team as well. They will reply in Messages.');
        }
    }
    redirect('creator/community.php?tab=chat');
}

$tab = (string)get('tab');
$tab = in_array($tab, ['feed', 'chat', 'leaderboard', 'members'], true) ? $tab : ((int)get('post') > 0 ? 'feed' : 'chat');
$room = chat_room_key((string)get('room'));
$roomOpen = get('room') !== '' && isset(chat_rooms()[(string)get('room')]);
$ready = community_ready();
$focusPost = (int)get('post');

// Live chat seed (same data the chat API returns).
$chatRows = community_messages(60, 0, 0, $room);
$chatIds = array_map(function ($m) { return (int)$m['id']; }, $chatRows);
$extra = $ready ? community_chat_extras($chatIds) : [];
$crx = $ready ? community_reaction_map('chat', $chatIds, $uid) : [];
$seedNamed = chat_mentions_of($chatRows);
$chat = [];
foreach ($chatRows as $m) {
    $j = community_message_json($m, $uid);
    $j['mentions'] = $seedNamed[$j['id']] ?? [];
    $j['sticker'] = $extra[$j['id']]['sticker'] ?? null;
    $j['reply'] = $extra[$j['id']]['reply'] ?? null;
    $j['reacts'] = $crx[$j['id']] ?? [];
    $chat[] = $j;
}

$roomInfo = chat_room_last();
$roomLast = $roomInfo['last'];
$feed = $ready ? community_feed($user) : ['posts' => [], 'more' => false];
$stats = community_stats();
$onlineUsers = community_people(array_column(active_users(), 'id'));
$me = community_people([$uid])[$uid] ?? ['id' => $uid, 'name' => $user['full_name'], 'initial' => 'M', 'avatar' => '', 'tier' => 'bronze', 'points' => 0];
$myPosts = $ready ? (int)fetch_val('SELECT COUNT(*) FROM community_posts WHERE user_id = ? AND deleted_at IS NULL', [$uid], 0) : 0;
$newMembers = array_values(array_filter(community_members('', 'new', 6), function ($p) use ($uid) { return $p['id'] !== $uid && !$p['team']; }));

$channels = [];
foreach (community_channels() as $k => [$label, $ico, $desc]) {
    $channels[] = ['key' => $k, 'label' => $label, 'desc' => $desc, 'icon' => icon($ico)];
}
$stickerPacks = sticker_packs_for($user);
$stickers = [];
foreach ($stickerPacks as $pk) {
    if ($pk['open']) {
        foreach ($pk['stickers'] as $st) { $stickers[] = $st; }
    }
}
$reacts = [];
foreach (community_reactions() as $k => $e) {
    $reacts[] = ['k' => $k, 'e' => $e];
}

$seed = json_encode([
    'me'        => $me + ['isAdmin' => $isAdmin, 'level' => community_level((int)($me['points'] ?? 0))],
    'tab'       => $tab,
    'posts'     => $feed['posts'],
    'more'      => $feed['more'],
    'focus'     => $focusPost,
    'channels'  => $channels,
    'stickers'  => $stickers,
    'packs'     => $stickerPacks,
    'reacts'    => $reacts,
    'online'    => array_values($onlineUsers),
    'stats'     => $stats,
    'board'     => community_leaderboard(7, 5),
    'activity'  => community_activity(7),
    'newbies'   => array_slice($newMembers, 0, 4),
    'firstPost' => $myPosts === 0,
    'ready'     => $ready,
    'urls'      => ['api' => u('creator/community_api.php'), 'chat' => u('creator/community_feed.php'), 'dm' => u($isAdmin ? 'admin/messages.php' : 'creator/messages.php'), 'self' => u('creator/community.php')],
    'icons'     => ['heart' => icon('heart'), 'message' => icon('message'), 'smile' => icon('smile'), 'image' => icon('image'), 'sticker' => icon('sticker'),
                    'pin' => icon('pin'), 'more' => icon('more'), 'x' => icon('x'), 'send' => icon('send'), 'reply' => icon('reply'), 'trash' => icon('trash'),
                    'trophy' => icon('trophy'), 'search' => icon('search'), 'chat' => icon('chat'), 'arrow' => icon('arrow-right'), 'chevron' => icon('chevron-down')],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
$chatSeed = json_encode([
    'me' => $uid, 'isAdmin' => $isAdmin, 'messages' => $chat, 'more' => count($chat) === 60, 'now' => time(),
    'stickers' => $stickers, 'packs' => $stickerPacks, 'reacts' => $reacts, 'api' => u('creator/community_api.php'),
    'room' => $room, 'roomOpen' => $roomOpen, 'rooms' => chat_rooms_json($isAdmin), 'last' => (object)$roomLast, 'prev' => (object)$roomInfo['prev'], 'at' => (object)$roomInfo['at'], 'myName' => (string)$user['full_name'],
    // Tap a person in the chat: message them (and, for the team, open their admin page). Owner, 2026-11-12.
    'dm' => u($isAdmin ? 'admin/messages.php' : 'creator/messages.php'), 'detail' => $isAdmin ? u('admin/creator_detail.php') : '',
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

$hideAppBar = true; // the "add to home screen" bar would push the chat down
require __DIR__ . '/../includes/' . ($isAdmin ? 'admin_header.php' : 'creator_header.php');
$tabs = [
    'chat' => ['Chat', 'message'],
    'feed' => ['Posts', 'chats'],
    'leaderboard' => ['Leaderboard', 'trophy'],
    'members' => ['Members', 'users'],
];
?>
<link rel="stylesheet" href="<?= e(u('assets/css/chat.css?v=20261112b')) ?>">
<link rel="stylesheet" href="<?= e(u('assets/css/community.css?v=20261113a')) ?>">
<div class="cmx" id="cmx" data-tab="<?= e($tab) ?>" data-csrf="<?= e(csrf_token()) ?>">
    <script type="application/json" id="cmxSeed"><?= $seed ?></script>

    <header class="cmx-hero">
        <div class="cmx-hero__text">
            <h1>Community</h1>
            <p>Talk with other HPL clippers.</p>
        </div>
        <div class="cmx-hero__live" aria-live="polite">
            <div class="cmx-stack" id="cmxStack">
                <?php foreach (array_slice(array_values($onlineUsers), 0, 5) as $p): ?>
                    <span class="cmx-av cmx-av--sm cmx-av--<?= e($p['tier']) ?>" title="<?= e($p['name']) ?>"><?php if ($p['avatar']): ?><img src="<?= e($p['avatar']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e($p['initial']) ?><?php endif; ?></span>
                <?php endforeach; ?>
            </div>
            <div class="cmx-hero__nums">
                <span class="cmx-live"><i></i><b id="cmxOnline"><?= (int)$stats['online'] ?></b> online<span class="cmx-wide"> now</span></span>
                <span class="cmx-hero__members"><?= e(num($stats['members'])) ?> members</span>
            </div>
        </div>
    </header>

    <nav class="cmx-tabs" role="tablist" aria-label="Community sections">
        <?php foreach ($tabs as $k => [$label, $ico]): ?>
            <button type="button" role="tab" class="cmx-tab<?= $k === $tab ? ' is-on' : '' ?>" data-tab="<?= e($k) ?>" aria-selected="<?= $k === $tab ? 'true' : 'false' ?>" aria-controls="cmxPanel-<?= e($k) ?>">
                <?= icon($ico) ?><span><?= e($label) ?></span><?php if ($k === 'chat'): ?><em class="cmx-tab__dot" id="cmxChatDot" hidden></em><?php endif; ?>
            </button>
        <?php endforeach; ?>
    </nav>

    <div class="cmx-body">
        <div class="cmx-main">
            <section class="cmx-panel" id="cmxPanel-feed" data-panel="feed" role="tabpanel" <?= $tab === 'feed' ? '' : 'hidden' ?>>
                <?php if (!$ready): ?>
                    <div class="cmx-card cmx-empty">The community feed is being set up. Refresh in a minute.</div>
                <?php endif; ?>
                <div id="cmxComposer"></div>
                <div class="cmx-channels" id="cmxChannels" role="toolbar" aria-label="Filter by channel"></div>
                <button type="button" class="cmx-newer" id="cmxNewer" hidden></button>
                <div class="cmx-feed" id="cmxFeed" aria-live="polite"></div>
                <div class="cmx-more"><button type="button" class="cmx-btn cmx-btn--ghost" id="cmxMore" hidden>Show older posts</button></div>
                <noscript><p class="cmx-card cmx-empty">Turn on JavaScript to see the community feed.</p></noscript>
            </section>

            <section class="cmx-panel cmx-panel--chat" id="cmxPanel-chat" data-panel="chat" role="tabpanel" <?= $tab === 'chat' ? '' : 'hidden' ?>>
                <div class="hc" id="hplChat" data-feed="<?= e(u('creator/community_feed.php')) ?>" data-csrf="<?= e(csrf_token()) ?>">
                    <script type="application/json" id="hcSeed"><?= $chatSeed ?></script>
                    <nav class="hc__rooms" id="hcRooms" aria-label="Chat groups"></nav>
                    <section class="hc__main">
                        <header class="hc__head">
                            <button type="button" class="hc__back" id="hcBack" aria-label="Back to all groups"><?= icon('chevron-left') ?></button>
                            <div class="hc__title">
                                <span class="hc__hash" id="hcRoomIcon" aria-hidden="true"><?= icon(chat_rooms()[$room]['icon'] ?? 'chats') ?></span>
                                <div>
                                    <h2 id="hcRoomName"><?= e(chat_rooms()[$room]['name']) ?></h2>
                                    <p class="hc__sub"><span class="hc__sub-desc" id="hcRoomDesc"><?= e(chat_rooms()[$room]['desc']) ?></span><span class="hc__sub-on"><i></i><b id="hcOnlineCount"><?= (int)$stats['online'] ?></b> online</span></p>
                                </div>
                            </div>
                            <div class="hc__headstack" id="hcHeadStack" aria-hidden="true">
                                <?php foreach (array_slice(array_values($onlineUsers), 0, 3) as $p): ?>
                                    <span class="cmx-av cmx-av--sm cmx-av--<?= e($p['tier']) ?>"><?php if ($p['avatar']): ?><img src="<?= e($p['avatar']) ?>" alt="" referrerpolicy="no-referrer"><?php else: ?><?= e($p['initial']) ?><?php endif; ?></span>
                                <?php endforeach; ?>
                            </div>
                        </header>
                        <div class="hc__scroll" id="hcScroll" aria-live="polite">
                            <div class="hc__older" id="hcOlder" hidden><button type="button" class="hc__olderbtn">Load earlier messages</button></div>
                            <div class="hc__list" id="hcList"></div>
                            <noscript>
                                <?php foreach ($chat as $m): ?><p><strong><?= e($m['name']) ?>:</strong> <?= nl2br(e($m['text'])) ?></p><?php endforeach; ?>
                            </noscript>
                        </div>
                        <div class="hc__typing" id="hcTyping" aria-live="polite"></div>
                        <button type="button" class="hc__jump" id="hcJump" hidden>New messages <?= icon('chevron-down') ?></button>
                        <div class="hc__replying" id="hcReplying" hidden></div>
                        <div class="hc__mention" id="hcMention" hidden></div>
                        <div class="hc__locked" id="hcLocked" hidden><span>Only HPL writes here. Tap a message to react.</span><button type="button" data-room="general">Open General chat</button></div>
                        <form class="hc__composer" id="hcForm" method="post" action="<?= e(u('creator/community.php')) ?>" autocomplete="off">
                            <?= csrf_field() ?>
                            <div class="hc__field">
                                <textarea id="hcInput" name="message" rows="1" maxlength="1000" placeholder="Message" aria-label="Write a message" enterkeyhint="send"></textarea>
                                <button type="button" class="hc__tool hc__tool--stk" id="hcStickerBtn" aria-label="Stickers" title="Stickers" aria-expanded="false"><?= icon('smile') ?></button>
                                <span class="hc__count" id="hcCount" hidden></span>
                            </div>
                            <button class="hc__send" type="submit" aria-label="Send"><?= icon('send') ?></button>
                            <div class="cmx-pop hc__pop" id="hcPop" hidden></div>
                        </form>
                    </section>
                    <aside class="hc__side" aria-label="Online now">
                        <h3>Online now</h3>
                        <ul class="hc__people" id="hcPeople"></ul>
                    </aside>
                </div>
            </section>

            <section class="cmx-panel" id="cmxPanel-leaderboard" data-panel="leaderboard" role="tabpanel" <?= $tab === 'leaderboard' ? '' : 'hidden' ?>>
                <div class="cmx-card cmx-board" id="cmxBoard"></div>
            </section>

            <section class="cmx-panel" id="cmxPanel-members" data-panel="members" role="tabpanel" <?= $tab === 'members' ? '' : 'hidden' ?>>
                <div class="cmx-card cmx-members" id="cmxMembers"></div>
            </section>
        </div>

        <aside class="cmx-side" id="cmxSide" aria-label="About the community"></aside>
    </div>

    <div class="cmx-lightbox" id="cmxLightbox" hidden><button type="button" aria-label="Close photo"><?= icon('x') ?></button><img alt=""></div>
</div>
<script src="<?= e(u('assets/js/stickers.js?v=20261109b')) ?>"></script>
<script src="<?= e(u('assets/js/community.js?v=20261113a')) ?>"></script>
<script src="<?= e(u('assets/js/chat.js?v=20261112a')) ?>"></script>
<?php require __DIR__ . '/../includes/' . ($isAdmin ? 'admin_footer.php' : 'creator_footer.php'); ?>
