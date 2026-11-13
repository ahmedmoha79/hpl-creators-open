<?php
/**
 * HPL Community (2026-11-08): a Skool/Whop-style community for creators.
 *  - Feed: posts in channels (Wins, Questions, Tips, Introduce yourself, General) with a photo or a sticker,
 *    reactions, comments and one level of replies. The HPL team can pin posts.
 *  - Live chat: the existing community_messages room, now with reactions, replies, stickers and "typing...".
 *  - Points: every reaction someone else gives you is 1 point. Leaderboard for 7 days, 30 days and all time.
 *  - Members: everyone, who is online now, their creator level (Bronze to Diamond, from views) and a Message button.
 * Tables are created by a migration step (includes/migrations.php).
 */
require_once __DIR__ . '/social_proof.php'; // hpl_level_for(): Bronze to Diamond from views

function community_channels(): array
{
    return [
        // Owner, 2026-11-13: no separate "Tips" or "Introduce yourself"; General is where people say hello.
        'general'   => ['General', 'chats', 'Anything about clipping'],
        'wins'      => ['Wins', 'award', 'Views and payouts'],
        'questions' => ['Questions', 'support', 'Ask anything'],
    ];
}

/*
 * Chat groups (2026-11-10): the live chat is split into groups (like WhatsApp groups, no hashtags), so hundreds of
 * creators can talk without one endless stream. Fixed rooms plus one room per open campaign.
 * key => ['name' (plain words, shown as written), 'icon', 'tone', 'desc', 'write' => all|team, 'group' => rooms|campaigns]
 * Only the HPL team writes in #announcements; everyone can react there.
 */
function chat_rooms(): array
{
    static $rooms = null;
    if ($rooms !== null) {
        return $rooms;
    }
    $rooms = [
        // Owner, 2026-11-13: "Say hello" and "TikTok tips" removed; people say hello in General chat.
        'announcements' => ['name' => 'HPL News', 'desc' => 'Only HPL writes here', 'write' => 'team', 'group' => 'rooms', 'icon' => 'megaphone', 'tone' => 'gold'],
        'general'       => ['name' => 'General chat', 'desc' => 'Say hello, talk about anything', 'write' => 'all', 'group' => 'rooms', 'icon' => 'chats', 'tone' => 'brown'],
        'wins'          => ['name' => 'Wins and payouts', 'desc' => 'Show your views and payouts', 'write' => 'all', 'group' => 'rooms', 'icon' => 'trophy', 'tone' => 'green'],
        'help'          => ['name' => 'Help', 'desc' => 'Ask anything', 'write' => 'all', 'group' => 'rooms', 'icon' => 'support', 'tone' => 'blue'],
    ];
    try {
        foreach (fetch_all('SELECT id, title FROM campaigns WHERE status = "active" AND COALESCE(camp_status, "accepting") = "accepting" ORDER BY id DESC LIMIT 6') as $c) {
            $rooms['c' . (int)$c['id']] = ['name' => mb_substr(trim((string)$c['title']) !== '' ? trim((string)$c['title']) : 'Campaign ' . (int)$c['id'], 0, 40), 'desc' => 'Everyone clipping this campaign',
                'write' => 'all', 'group' => 'campaigns', 'campaign' => (int)$c['id'], 'icon' => 'video', 'tone' => 'dark'];
        }
    } catch (Throwable $e) {
    }
    return $rooms;
}

/** A valid room key (falls back to general). */
function chat_room_key(string $key): string
{
    return isset(chat_rooms()[$key]) ? $key : 'general';
}

/** Rooms for the browser. */
function chat_rooms_json(bool $isAdmin): array
{
    $out = [];
    foreach (chat_rooms() as $k => $r) {
        $out[] = ['key' => $k, 'name' => $r['name'], 'desc' => $r['desc'], 'group' => $r['group'], 'can' => $r['write'] === 'all' || $isAdmin,
            'icon' => icon($r['icon'] ?? 'chats'), 'tone' => $r['tone'] ?? 'brown'];
    }
    return $out;
}

/** Newest message of every room: ['last' => [room => id], 'prev' => [room => "Mary: text"]]. Two small queries. */
function chat_room_last(): array
{
    $out = ['last' => [], 'prev' => [], 'at' => []];
    try {
        if (!chat_has_rooms()) {
            return $out;
        }
        foreach (fetch_all('SELECT channel, MAX(id) last_id FROM community_messages GROUP BY channel') as $r) {
            $out['last'][(string)$r['channel']] = (int)$r['last_id'];
        }
        if ($out['last']) {
            $ids = implode(',', array_map('intval', $out['last']));
            foreach (fetch_all("SELECT m.channel, m.message, m.kind, m.sticker, m.deleted_at, m.created_at, u.full_name FROM community_messages m JOIN users u ON u.id = m.user_id WHERE m.id IN ($ids)") as $r) {
                if ($r['deleted_at'] !== null) {
                    continue;
                }
                $out['at'][(string)$r['channel']] = strtotime((string)$r['created_at']) ?: 0;
                $first = explode(' ', trim((string)$r['full_name']))[0] ?: 'Someone';
                $text = trim((string)$r['message']) !== '' ? (string)$r['message'] : ($r['sticker'] ? 'sent a sticker' : '');
                $out['prev'][(string)$r['channel']] = mb_substr($first . ($r['kind'] === 'event' ? ' ' : ': ') . preg_replace('/\s+/', ' ', $text), 0, 70);
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** People named with @ in these chat rows: [message id => [['id' =>, 'name' =>], ...]], one query. */
function chat_mentions_of(array $rows): array
{
    $want = [];
    foreach ($rows as $m) {
        foreach (array_filter(array_map('intval', explode(',', (string)($m['mentions'] ?? '')))) as $x) {
            $want[(int)$m['id']][] = $x;
        }
    }
    if (!$want) {
        return [];
    }
    $names = [];
    try {
        foreach (fetch_all('SELECT id, full_name FROM users WHERE id IN (' . implode(',', array_unique(array_merge(...array_values($want)))) . ')') as $r) {
            $names[(int)$r['id']] = (string)$r['full_name'];
        }
    } catch (Throwable $e) {
    }
    $out = [];
    foreach ($want as $mid => $ids) {
        foreach ($ids as $x) {
            if (isset($names[$x])) { $out[$mid][] = ['id' => $x, 'name' => $names[$x]]; }
        }
    }
    return $out;
}

/** Have the chat-room columns been added yet? */
function chat_has_rooms(): bool
{
    static $has = null;
    if ($has === null) {
        try { $has = hpl_column_exists('community_messages', 'channel'); } catch (Throwable $e) { $has = false; }
    }
    return $has;
}

/**
 * An activity line in a chat room ("Mary's clip passed 10K views"). It belongs to the person it is
 * about, shows as a small centred line, and people can react to it. Never throws.
 */
function chat_event(int $userId, string $room, string $text): void
{
    try {
        if ($userId <= 0 || !chat_has_rooms() || !isset(chat_rooms()[$room])) {
            return;
        }
        q('INSERT INTO community_messages (user_id, message, channel, kind) VALUES (?,?,?,?)', [$userId, mb_substr($text, 0, 300), $room, 'event']);
        require_once __DIR__ . '/chat_cache.php';
        chat_bump();
    } catch (Throwable $e) {
    }
}

/**
 * People named with @ in a chat message. $ids come from the browser's picker; only real, active
 * users whose first name is written after an @ in the text count. Max 5. Returns [id => full name].
 */
function chat_mentions(string $text, array $ids): array
{
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 8);
    if (!$ids || strpos($text, '@') === false) {
        return [];
    }
    $out = [];
    try {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (fetch_all("SELECT id, full_name FROM users WHERE status = 'active' AND id IN ($in)", $ids) as $r) {
            $first = explode(' ', trim((string)$r['full_name']))[0];
            if ($first !== '' && mb_stripos($text, '@' . $first) !== false && count($out) < 5) {
                $out[(int)$r['id']] = (string)$r['full_name'];
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Reaction key => emoji shown. */
function community_reactions(): array
{
    return ['like' => '👍', 'love' => '❤️', 'fire' => '🔥', 'haha' => '😂', 'clap' => '👏', 'money' => '💰'];
}

/**
 * Sticker packs (2026-11-09, artwork: Microsoft Fluent Emoji 3D, MIT licence, see assets/img/stickers/LICENSE-Fluent-Emoji.txt).
 * Files: assets/img/stickers/<key>.webp or <key>.svg (still) and <key>.a.svg (animated).
 * lock: free | level (needs that tier from views) | buy (paid once from the wallet, setting sticker_pack_price) | hidden (old, not offered).
 */
function community_sticker_packs(): array
{
    return [
        'faces' => ['name' => 'Faces', 'desc' => 'Laughing, love, shock, cool and more', 'lock' => 'free', 'level' => '', 'cover' => 'lol',
            'stickers' => ['lol' => 'Crying laughing', 'rofl' => 'Rolling laughing', 'hearteyes' => 'Love it', 'starstruck' => 'Star-struck', 'cool' => 'Cool', 'think' => 'Hmm', 'shock' => 'Shocked', 'cry' => 'Crying', 'party' => 'Party', 'mindblown' => 'Mind blown', 'hug' => 'Hug', 'salute' => 'Salute', 'please' => 'Please', 'moneyface' => 'Money mouth', 'nerd' => 'Nerd', 'melt' => 'Melting', 'wink' => 'Wink', 'sleepy' => 'Sleepy']],
        'reactions' => ['name' => 'Reactions', 'desc' => 'Thumbs up, clap, fire, 100, congrats', 'lock' => 'free', 'level' => '', 'cover' => 'thumbsup',
            'stickers' => ['thumbsup' => 'Thumbs up', 'clap' => 'Clapping', 'raise' => 'Yes!', 'pray' => 'Thank you', 'strong' => 'Strong', 'okhand' => 'OK', 'wave' => 'Hello', 'handshake' => 'Deal', 'heart' => 'Heart', 'fire' => 'Fire', 'hundred' => '100', 'sparkles' => 'Sparkles', 'tada' => 'Congrats', 'eyes' => 'Watching', 'done' => 'Done', 'boom' => 'Boom']],
        'gold' => ['name' => 'Gold & money', 'desc' => 'Money bag, coins, pick, cash, going up', 'lock' => 'free', 'level' => '', 'cover' => 'moneybag',
            'stickers' => ['moneybag' => 'Money bag', 'coin' => 'Coin', 'pick' => 'Dig', 'rock' => 'Rock', 'search' => 'Searching', 'cash' => 'Cash', 'moneywings' => 'Money flying', 'mountain' => 'Out in the bush', 'truck' => 'On the road', 'sun' => 'Hot day', 'chart' => 'Going up', 'bricks' => 'Building']],
        'clipper' => ['name' => 'Clipper life', 'desc' => 'Camera, action, going viral, new post', 'lock' => 'free', 'level' => '', 'cover' => 'movie',
            'stickers' => ['movie' => 'Recording', 'clapper' => 'Action', 'phone' => 'Phone', 'flash' => 'Photo', 'rocket' => 'Going viral', 'bell' => 'New post', 'megaphone' => 'Shout-out', 'idea' => 'Idea', 'laptop' => 'Editing', 'hourglass' => 'Waiting', 'target' => 'On target', 'clock' => 'Posting time']],
        'zambia' => ['name' => 'Zambia', 'desc' => 'Zikomo, Muli bwanji, Natotela, Sharp!', 'lock' => 'level', 'level' => 'silver', 'cover' => 'zikomo',
            'stickers' => ['zikomo' => 'Zikomo', 'mulibwanji' => 'Muli bwanji', 'natotela' => 'Natotela', 'mwaiseni' => 'Mwaiseni', 'sharp' => 'Sharp!', 'lusaka' => 'Lusaka', 'copperbelt' => 'Copperbelt', 'mwebantu' => 'Mwebantu']],
        'vip' => ['name' => 'Gold VIP', 'desc' => 'Animated and glowing: crown, diamond, trophy, ring', 'lock' => 'buy', 'level' => '', 'cover' => 'crown',
            'stickers' => ['crown' => 'Crown', 'gem' => 'Diamond', 'trophy' => 'Champion', 'first' => 'Number one', 'medal' => 'Medal', 'star' => 'Superstar', 'shootingstar' => 'Shooting star', 'richbag' => 'Rich', 'goldheart' => 'Golden heart', 'heartfire' => 'Heart on fire', 'ring' => 'Ring', 'key' => 'Key']],
        'hype' => ['name' => 'Hype', 'desc' => 'Animated and glowing: GOAT, lion, dragon, fireworks', 'lock' => 'buy', 'level' => '', 'cover' => 'goat',
            'stickers' => ['goat' => 'GOAT', 'lion' => 'Lion', 'eagle' => 'Eagle', 'volt' => 'Charged up', 'fireworks' => 'Fireworks', 'confetti' => 'Confetti', 'bomb' => 'Bomb', 'volcano' => 'Erupting', 'gorilla' => 'Beast mode', 'shark' => 'Shark', 'dragon' => 'Dragon', 'unicorn' => 'Rare']],
        // Stickers from before 9 Nov 2026: not offered any more, kept so old messages still show them.
        'legacy' => ['name' => 'Old stickers', 'desc' => '', 'lock' => 'hidden', 'level' => '', 'cover' => '',
            'stickers' => ['wow' => 'Wow', 'love' => 'Love it', 'sad' => 'Sad', 'lovestruck' => 'In love', 'paid' => 'Paid!', 'nugget' => 'Gold nugget', 'letsgo' => 'Let\'s go', 'viral' => 'Viral', 'beep' => 'Beep beep', 'goldrush' => 'Gold rush', 'clipit' => 'Clip it', 'thanks' => 'Thank you', 'welcome' => 'Welcome', 'help' => 'Help?', 'gg' => 'GG', 'goldbar' => 'Gold bar', 'goldpan' => 'Gold in the pan', 'pickaxe' => 'Dig deeper', 'detector' => 'Beep!', 'chest' => 'Treasure', 'jackpot' => 'Jackpot', 'foundit' => 'Found it', 'kwacha' => 'Kwacha', 'sluice' => 'Wash it', 'notgold' => 'Fool\'s gold', 'posted' => 'Posted', 'onetk' => '1K views', 'tenk' => '10K views', 'goingviral' => 'Going viral', 'recording' => 'Recording', 'editing' => 'Editing', 'algorithm' => 'Algorithm loves me', 'streak' => 'Streak', 'payday' => 'Payday', 'newclip' => 'New clip', 'zambialove' => 'Zambia love', 'diamond' => 'Diamond', 'boss' => 'Boss move', 'bigwin' => 'Big win', 'legend' => 'Legend', 'moneyrain' => 'Money rain', 'respect' => 'Respect', 'vip' => 'VIP', 'letsgooo' => 'Let\'s gooo', 'w' => 'W', 'facts' => 'Facts', 'lockedin' => 'Locked in', 'hustle' => 'Hustle', 'bless' => 'Bless', 'onfire' => 'On fire', 'bigbrain' => 'Big brain', 'shoutout' => 'Shout-out', 'patience' => 'Patience', 'vibes' => 'Good vibes']],
    ];
}

/** Stickers whose still picture is a .webp file (Fluent 3D emoji); the others are .svg. */
function community_sticker_is_webp(string $key): bool
{
    static $w = null;
    if ($w === null) {
        $w = array_flip(['lol', 'rofl', 'hearteyes', 'starstruck', 'cool', 'think', 'shock', 'cry', 'party', 'mindblown', 'hug', 'salute', 'please', 'moneyface', 'nerd', 'melt', 'wink', 'sleepy', 'thumbsup', 'clap', 'raise', 'pray', 'strong', 'okhand', 'wave', 'handshake', 'heart', 'fire', 'hundred', 'sparkles', 'tada', 'eyes', 'done', 'boom', 'moneybag', 'coin', 'pick', 'rock', 'search', 'cash', 'moneywings', 'mountain', 'truck', 'sun', 'chart', 'bricks', 'movie', 'clapper', 'phone', 'flash', 'rocket', 'bell', 'megaphone', 'idea', 'laptop', 'hourglass', 'target', 'clock']);
    }
    return isset($w[$key]);
}

/** Every sticker key => label (all packs). */
function community_stickers(): array
{
    static $all = null;
    if ($all === null) {
        $all = [];
        foreach (community_sticker_packs() as $p) {
            $all += $p['stickers'];
        }
    }
    return $all;
}

function community_sticker_pack_of(string $key): ?string
{
    foreach (community_sticker_packs() as $pk => $p) {
        if (isset($p['stickers'][$key])) {
            return $pk;
        }
    }
    return null;
}

function community_sticker_url(?string $key, bool $animated = false): ?string
{
    if ($key === null || !isset(community_stickers()[$key])) {
        return null;
    }
    $file = $animated ? $key . '.a.svg' : $key . (community_sticker_is_webp($key) ? '.webp' : '.svg');
    return u('assets/img/stickers/' . $file . '?v=3');
}

/** Price of one paid pack in kwacha (0 = paid packs are free for everyone). */
function sticker_pack_price(): float
{
    return max(0.0, round((float)setting('sticker_pack_price', '5'), 2));
}

/** Packs this person has bought. */
function sticker_packs_owned(int $uid): array
{
    try {
        return array_column(fetch_all('SELECT pack FROM sticker_purchases WHERE user_id = ?', [$uid]), 'pack');
    } catch (Throwable $e) {
        return [];
    }
}

/** Whether a pack is open for this person, and if not why: ['open' => bool, 'why' => text]. */
function sticker_pack_status(array $user, string $pack, ?array $owned = null, ?string $tierKey = null): array
{
    $p = community_sticker_packs()[$pack] ?? null;
    if (!$p) {
        return ['open' => false, 'why' => 'Unknown pack'];
    }
    if ($p['lock'] === 'hidden') {
        return ['open' => false, 'why' => 'No longer offered'];
    }
    if ($p['lock'] === 'free' || ($user['role'] ?? '') === 'admin') {
        return ['open' => true, 'why' => ''];
    }
    if ($p['lock'] === 'level') {
        require_once __DIR__ . '/social_proof.php';
        require_once __DIR__ . '/earnings.php';
        $order = ['bronze' => 0, 'silver' => 1, 'gold' => 2, 'diamond' => 3];
        $tierKey = $tierKey ?? (string)(hpl_level_for((int)creator_stats((int)$user['id'])['views'])['current']['key'] ?? 'bronze');
        $need = $p['level'] ?: 'silver';
        return ($order[$tierKey] ?? 0) >= ($order[$need] ?? 1)
            ? ['open' => true, 'why' => '']
            : ['open' => false, 'why' => 'Reach ' . ucfirst($need) . ' level to unlock'];
    }
    $owned = $owned ?? sticker_packs_owned((int)$user['id']);
    if (sticker_pack_price() <= 0 || in_array($pack, $owned, true)) {
        return ['open' => true, 'why' => ''];
    }
    return ['open' => false, 'why' => 'Unlock for ' . money(sticker_pack_price())];
}

/** May this person send this sticker? */
function sticker_allowed(array $user, string $key): bool
{
    $pack = community_sticker_pack_of($key);
    return $pack !== null && sticker_pack_status($user, $pack)['open'];
}

/** Sticker packs for the picker: open/locked state for this viewer. */
function sticker_packs_for(array $user): array
{
    require_once __DIR__ . '/social_proof.php';
    require_once __DIR__ . '/earnings.php';
    $owned = sticker_packs_owned((int)$user['id']);
    $tier = (string)(hpl_level_for((int)creator_stats((int)$user['id'])['views'])['current']['key'] ?? 'bronze');
    $out = [];
    foreach (community_sticker_packs() as $pk => $p) {
        if ($p['lock'] === 'hidden') {
            continue;
        }
        $st = sticker_pack_status($user, $pk, $owned, $tier);
        $items = [];
        foreach ($p['stickers'] as $k => $label) {
            $items[] = ['key' => $k, 'label' => $label, 'url' => community_sticker_url($k)];
        }
        $out[] = ['key' => $pk, 'name' => $p['name'], 'desc' => $p['desc'], 'lock' => $p['lock'], 'open' => $st['open'], 'why' => $st['why'],
                  'price' => $p['lock'] === 'buy' ? sticker_pack_price() : 0, 'cover' => community_sticker_url($p['cover']), 'stickers' => $items];
    }
    return $out;
}

/**
 * Buy a paid pack with wallet money. The price is recorded in sticker_purchases, which creator_stats()
 * takes off the balance, so it can never also be withdrawn. Returns null on success or a message.
 */
function sticker_buy_pack(array $user, string $pack): ?string
{
    $p = community_sticker_packs()[$pack] ?? null;
    if (!$p || $p['lock'] !== 'buy') {
        return 'This pack cannot be bought.';
    }
    $price = sticker_pack_price();
    $uid = (int)$user['id'];
    require_once __DIR__ . '/earnings.php';
    return hpl_tx(function () use ($uid, $pack, $price) {
        q('SELECT id FROM users WHERE id = ? FOR UPDATE', [$uid]);
        if (fetch_val('SELECT id FROM sticker_purchases WHERE user_id = ? AND pack = ?', [$uid, $pack])) {
            return null; // already yours
        }
        if ($price > 0 && (float)creator_stats($uid)['available'] < $price) {
            return 'You need ' . money($price) . ' in your wallet to unlock this pack. Post a clip and earn it.';
        }
        q('INSERT INTO sticker_purchases (user_id, pack, price) VALUES (?,?,?)', [$uid, $pack, $price]);
        return null;
    });
}

function community_ready(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            fetch_val('SELECT 1 FROM community_posts LIMIT 1');
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
        }
    }
    return $ok;
}

/* ------------------------------------------------------------------ people */

/**
 * Display info for a set of users: name, initial, avatar, role, creator level (from views), online, points.
 * Returns [uid => [...]].
 */
function community_people(array $uids): array
{
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
    if (!$uids) {
        return [];
    }
    $in = implode(',', array_fill(0, count($uids), '?'));
    $rows = fetch_all("SELECT id, full_name, role, avatar_url, last_seen, created_at FROM users WHERE id IN ($in)", $uids);
    $views = array_fill_keys($uids, 0);
    try {
        foreach (fetch_all("SELECT user_id, COALESCE(SUM(GREATEST(CAST(views AS SIGNED) - CAST(COALESCE(start_views, views) AS SIGNED), 0)),0) v
                              FROM campaign_submissions WHERE status = 'approved' AND user_id IN ($in) GROUP BY user_id", $uids) as $r) {
            $views[(int)$r['user_id']] += (int)$r['v'];
        }
        foreach (fetch_all("SELECT user_id, COALESCE(SUM(views),0) v FROM content_links WHERE status = 'active' AND user_id IN ($in) GROUP BY user_id", $uids) as $r) {
            $views[(int)$r['user_id']] += (int)$r['v'];
        }
    } catch (Throwable $e) {
    }
    $points = community_points_for($uids);
    $win = presence_window_minutes() * 60;
    $out = [];
    foreach ($rows as $r) {
        $id = (int)$r['id'];
        $name = trim((string)$r['full_name']) !== '' ? (string)$r['full_name'] : 'Member';
        $lv = hpl_level_for($views[$id] ?? 0);
        $seen = $r['last_seen'] ? strtotime((string)$r['last_seen']) : 0;
        $out[$id] = [
            'id'      => $id,
            'name'    => $name,
            'initial' => strtoupper(mb_substr($name, 0, 1)),
            'avatar'  => (string)($r['avatar_url'] ?? ''),
            'team'    => $r['role'] === 'admin',
            'tier'    => $r['role'] === 'admin' ? 'team' : $lv['current']['key'],
            'tierName'=> $r['role'] === 'admin' ? 'HPL team' : $lv['current']['name'],
            'online'  => $seen > 0 && time() - $seen <= $win,
            'seen'    => $seen,
            'joined'  => strtotime((string)$r['created_at']) ?: 0,
            'points'  => $points[$id] ?? 0,
        ];
    }
    return $out;
}

/** All-time points (reactions received from other people) for some users. */
function community_points_for(array $uids, int $days = 0): array
{
    if (!$uids || !community_ready()) {
        return [];
    }
    $in = implode(',', array_fill(0, count($uids), '?'));
    $since = $days > 0 ? ' AND created_at >= NOW() - INTERVAL ' . (int)$days . ' DAY' : '';
    $out = [];
    foreach (fetch_all("SELECT author_id, COUNT(*) n FROM community_reactions WHERE author_id IN ($in) AND author_id <> user_id$since GROUP BY author_id", $uids) as $r) {
        $out[(int)$r['author_id']] = (int)$r['n'];
    }
    return $out;
}

/** Community level from points (Skool-style curve, so early levels come quickly). */
function community_level(int $points): int
{
    $lv = 1;
    foreach ([5, 20, 65, 155, 515, 2015, 8015, 33015] as $i => $min) {
        if ($points >= $min) {
            $lv = $i + 2;
        }
    }
    return $lv;
}

/* ------------------------------------------------------------------ reactions */

/** Reaction summary for targets: [id => [['k'=>key,'e'=>emoji,'n'=>count,'mine'=>bool], ...]] */
function community_reaction_map(string $kind, array $ids, int $viewer): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !community_ready()) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $emoji = community_reactions();
    $out = [];
    $rows = fetch_all(
        "SELECT target_id, emoji, COUNT(*) n, SUM(user_id = ?) mine, MIN(id) first_id
           FROM community_reactions WHERE kind = ? AND target_id IN ($in)
          GROUP BY target_id, emoji ORDER BY first_id",
        array_merge([$viewer, $kind], $ids)
    );
    foreach ($rows as $r) {
        if (!isset($emoji[$r['emoji']])) {
            continue;
        }
        $out[(int)$r['target_id']][] = ['k' => $r['emoji'], 'e' => $emoji[$r['emoji']], 'n' => (int)$r['n'], 'mine' => (int)$r['mine'] > 0];
    }
    return $out;
}

/** Add or remove one reaction. Returns ['ok'=>bool, 'error'=>?, 'reacts'=>[...]] */
function community_toggle_reaction(array $user, string $kind, int $id, string $key): array
{
    if (!in_array($kind, ['post', 'comment', 'chat'], true) || !isset(community_reactions()[$key])) {
        return ['ok' => false, 'error' => 'Unknown reaction.'];
    }
    $uid = (int)$user['id'];
    $table = ['post' => 'community_posts', 'comment' => 'community_comments', 'chat' => 'community_messages'][$kind];
    $dead = $kind === 'chat' ? (community_has_soft_delete() ? ' AND deleted_at IS NULL' : '') : ' AND deleted_at IS NULL';
    $author = (int)fetch_val("SELECT user_id FROM $table WHERE id = ?$dead", [$id], 0);
    if ($author <= 0) {
        return ['ok' => false, 'error' => 'That was removed.'];
    }
    $have = (int)fetch_val('SELECT id FROM community_reactions WHERE kind = ? AND target_id = ? AND user_id = ? AND emoji = ?', [$kind, $id, $uid, $key], 0);
    if ($have) {
        q('DELETE FROM community_reactions WHERE id = ?', [$have]);
    } else {
        $recent = (int)fetch_val('SELECT COUNT(*) FROM community_reactions WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE', [$uid], 0);
        if ($recent >= 40) {
            return ['ok' => false, 'error' => 'Slow down a little.'];
        }
        q('INSERT IGNORE INTO community_reactions (kind, target_id, user_id, author_id, emoji) VALUES (?,?,?,?,?)', [$kind, $id, $uid, $author, $key]);
        // One "liked your post" notification per post per 6 hours keeps it encouraging, not noisy.
        if ($kind === 'post' && $author !== $uid) {
            $k = 'cm_rx_' . $id;
            $last = (int)fetch_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = ? AND created_at > NOW() - INTERVAL 6 HOUR', [$author, mb_substr($k, 0, 40)], 0);
            if (!$last) {
                notif_add($author, mb_substr($k, 0, 40), community_first_name($user) . ' reacted ' . community_reactions()[$key] . ' to your post', '', 'creator/community.php?post=' . $id);
            }
        }
    }
    if ($kind === 'chat') {
        require_once __DIR__ . '/chat_cache.php';
        chat_bump();
    }
    return ['ok' => true, 'reacts' => community_reaction_map($kind, [$id], $uid)[$id] ?? []];
}

function community_first_name(array $user): string
{
    $n = trim((string)($user['full_name'] ?? ''));
    return $n !== '' ? explode(' ', $n)[0] : 'Someone';
}

/* ------------------------------------------------------------------ posts */

function community_post_json(array $r, array $people, array $rx, int $viewer, bool $isAdmin, array $commenters = []): array
{
    $p = $people[(int)$r['user_id']] ?? null;
    $own = (int)$r['user_id'] === $viewer;
    return [
        'id'        => (int)$r['id'],
        'author'    => $p,
        'channel'   => (string)$r['channel'],
        'text'      => (string)$r['body'],
        'image'     => (string)($r['image'] ?? ''),
        'sticker'   => community_sticker_url($r['sticker'] ?? null),
        'pinned'    => (int)$r['pinned'] === 1,
        'comments'  => (int)$r['comments'],
        'ts'        => strtotime((string)$r['created_at']) ?: time(),
        'activity'  => strtotime((string)$r['last_activity_at']) ?: 0,
        'reacts'    => $rx[(int)$r['id']] ?? [],
        'mine'      => $own,
        'canDelete' => $own || $isAdmin,
        'canPin'    => $isAdmin,
        'commenters'=> $commenters,
    ];
}

/** A page of the feed. Pinned posts come first on the first page. */
function community_feed(array $viewer, string $channel = '', int $before = 0, int $limit = 15, int $onlyId = 0): array
{
    if (!community_ready()) {
        return ['posts' => [], 'more' => false];
    }
    $uid = (int)$viewer['id'];
    $isAdmin = $viewer['role'] === 'admin';
    $where = ['p.deleted_at IS NULL'];
    $args = [];
    if ($onlyId > 0) {
        $where[] = 'p.id = ?';
        $args[] = $onlyId;
    } else {
        if ($channel !== '' && isset(community_channels()[$channel])) {
            $where[] = 'p.channel = ?';
            $args[] = $channel;
        }
        if ($before > 0) {
            $where[] = 'p.id < ? AND p.pinned = 0';
            $args[] = $before;
        }
    }
    $order = ($before > 0 || $onlyId > 0) ? 'p.id DESC' : 'p.pinned DESC, p.id DESC';
    $rows = fetch_all(
        'SELECT p.* FROM community_posts p JOIN users u ON u.id = p.user_id AND u.status = "active"
          WHERE ' . implode(' AND ', $where) . " ORDER BY $order LIMIT " . ($limit + 1),
        $args
    );
    $more = count($rows) > $limit;
    $rows = array_slice($rows, 0, $limit);
    $ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
    $uids = array_map(function ($r) { return (int)$r['user_id']; }, $rows);
    // Last few people who commented, for the little avatar row.
    $commenters = [];
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        foreach (fetch_all("SELECT post_id, user_id, MAX(id) last_id FROM community_comments WHERE deleted_at IS NULL AND post_id IN ($in) GROUP BY post_id, user_id ORDER BY last_id DESC", $ids) as $c) {
            if (count($commenters[(int)$c['post_id']] ?? []) < 4) {
                $commenters[(int)$c['post_id']][] = (int)$c['user_id'];
                $uids[] = (int)$c['user_id'];
            }
        }
    }
    $people = community_people($uids);
    $rx = community_reaction_map('post', $ids, $uid);
    $posts = [];
    foreach ($rows as $r) {
        $cm = array_values(array_filter(array_map(function ($id) use ($people) { return $people[$id] ?? null; }, $commenters[(int)$r['id']] ?? [])));
        $posts[] = community_post_json($r, $people, $rx, $uid, $isAdmin, $cm);
    }
    return ['posts' => $posts, 'more' => $more && $onlyId === 0];
}

/** Store a photo for a post: checked, resized to at most 1600px and re-saved (drops hidden data). */
function community_store_image(?array $file): ?string
{
    if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    require_once __DIR__ . '/announcement_upload.php';
    if (($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('The photo could not be uploaded. Try a smaller photo (up to 8 MB).');
    }
    $ext = announcement_image_extension((string)$file['tmp_name'], (int)($file['size'] ?? 0));
    // The app makes photos small before sending; a huge photo that slips through would need hundreds
    // of MB of memory to resize, so it is refused (many people post at once in busy hours).
    $dim = @getimagesize((string)$file['tmp_name']);
    if ($dim && $dim[0] * $dim[1] > 16000000) {
        throw new RuntimeException('This photo is too big. Take a screenshot of it, or choose a smaller photo.');
    }
    $dir = BASE_PATH . '/uploads/community';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Photo storage is not available right now.');
    }
    $name = bin2hex(random_bytes(16));
    $src = (string)$file['tmp_name'];
    if ($ext !== 'gif' && function_exists('imagecreatefromstring')) {
        $img = @imagecreatefromstring((string)file_get_contents($src));
        if ($img) {
            if ($ext === 'jpg' && function_exists('exif_read_data')) {
                $o = (int)(@exif_read_data($src)['Orientation'] ?? 1);
                $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
                if ($rot) {
                    $r2 = imagerotate($img, $rot, 0);
                    if ($r2) { imagedestroy($img); $img = $r2; }
                }
            }
            $w = imagesx($img); $h = imagesy($img);
            $scale = min(1, 1600 / max($w, $h));
            if ($scale < 1) {
                $nw = (int)round($w * $scale); $nh = (int)round($h * $scale);
                $dst = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false); imagesavealpha($dst, true);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($img); $img = $dst;
            }
            $ok = $ext === 'png' ? imagepng($img, "$dir/$name.png", 7) : imagejpeg($img, "$dir/$name.jpg", 84);
            imagedestroy($img);
            if ($ok) {
                return u('uploads/community/' . $name . ($ext === 'png' ? '.png' : '.jpg'));
            }
        }
    }
    if (!move_uploaded_file($src, "$dir/$name.$ext")) {
        throw new RuntimeException('Could not save the photo.');
    }
    return u('uploads/community/' . $name . '.' . $ext);
}

/** Create a post. Returns ['ok'=>true,'id'=>..] or ['ok'=>false,'error'=>..] */
function community_create_post(array $user, string $channel, string $text, ?string $sticker, ?array $file): array
{
    $uid = (int)$user['id'];
    $text = trim(str_replace("\r\n", "\n", $text));
    if (!isset(community_channels()[$channel])) {
        $channel = 'general';
    }
    if ($sticker !== null && !isset(community_stickers()[$sticker])) {
        $sticker = null;
    }
    if ($sticker !== null && !sticker_allowed($user, $sticker)) {
        return ['ok' => false, 'error' => 'That sticker pack is locked for you.'];
    }
    $hasFile = $file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($text === '' && !$sticker && !$hasFile) {
        return ['ok' => false, 'error' => 'Write something first.'];
    }
    if (mb_strlen($text) > 3000) {
        return ['ok' => false, 'error' => 'Keep posts under 3,000 characters.'];
    }
    $recent = (int)fetch_val('SELECT COUNT(*) FROM community_posts WHERE user_id = ? AND created_at > NOW() - INTERVAL 10 MINUTE', [$uid], 0);
    if ($recent >= 5 && $user['role'] !== 'admin') {
        return ['ok' => false, 'error' => 'You have posted a lot in the last few minutes. Wait a little, then post again.'];
    }
    $last = fetch_one('SELECT body, created_at FROM community_posts WHERE user_id = ? ORDER BY id DESC LIMIT 1', [$uid]);
    if ($last && $text !== '' && $last['body'] === $text && time() - strtotime((string)$last['created_at']) < 3600) {
        return ['ok' => false, 'error' => 'You already posted that.'];
    }
    try {
        $image = community_store_image($hasFile ? $file : null);
    } catch (RuntimeException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
    q('INSERT INTO community_posts (user_id, channel, body, image, sticker, last_activity_at) VALUES (?,?,?,?,?,NOW())', [$uid, $channel, $text, $image, $sticker]);
    $id = (int)db()->lastInsertId();
    // A question for the team also reaches the HPL team inbox, like @team in chat.
    if ($user['role'] !== 'admin' && ($channel === 'questions' || stripos($text, '@team') !== false)) {
        notif_admins('community', 'Question in the community', community_first_name($user) . ': ' . mb_substr($text, 0, 120), 'creator/community.php?post=' . $id);
    }
    return ['ok' => true, 'id' => $id];
}

/* ------------------------------------------------------------------ comments */

function community_comments(int $postId, array $viewer): array
{
    $uid = (int)$viewer['id'];
    $isAdmin = $viewer['role'] === 'admin';
    $rows = fetch_all('SELECT c.* FROM community_comments c JOIN users u ON u.id = c.user_id AND u.status = "active"
                        WHERE c.post_id = ? AND c.deleted_at IS NULL ORDER BY c.id ASC LIMIT 300', [$postId]);
    $people = community_people(array_map(function ($r) { return (int)$r['user_id']; }, $rows));
    $rx = community_reaction_map('comment', array_map(function ($r) { return (int)$r['id']; }, $rows), $uid);
    $out = [];
    foreach ($rows as $r) {
        $own = (int)$r['user_id'] === $uid;
        $out[] = [
            'id' => (int)$r['id'], 'parent' => (int)($r['parent_id'] ?? 0), 'author' => $people[(int)$r['user_id']] ?? null,
            'text' => (string)$r['body'], 'sticker' => community_sticker_url($r['sticker'] ?? null),
            'ts' => strtotime((string)$r['created_at']) ?: time(), 'reacts' => $rx[(int)$r['id']] ?? [],
            'mine' => $own, 'canDelete' => $own || $isAdmin,
        ];
    }
    return $out;
}

function community_add_comment(array $user, int $postId, string $text, ?string $sticker, int $parentId = 0): array
{
    $uid = (int)$user['id'];
    $text = trim(str_replace("\r\n", "\n", $text));
    if ($sticker !== null && !isset(community_stickers()[$sticker])) {
        $sticker = null;
    }
    if ($sticker !== null && !sticker_allowed($user, $sticker)) {
        return ['ok' => false, 'error' => 'That sticker pack is locked for you.'];
    }
    if ($text === '' && !$sticker) {
        return ['ok' => false, 'error' => 'Write something first.'];
    }
    if (mb_strlen($text) > 1500) {
        return ['ok' => false, 'error' => 'Keep comments under 1,500 characters.'];
    }
    $post = fetch_one('SELECT id, user_id, body FROM community_posts WHERE id = ? AND deleted_at IS NULL', [$postId]);
    if (!$post) {
        return ['ok' => false, 'error' => 'That post was removed.'];
    }
    $parent = null;
    if ($parentId > 0) {
        $parent = fetch_one('SELECT id, user_id, parent_id FROM community_comments WHERE id = ? AND post_id = ? AND deleted_at IS NULL', [$parentId, $postId]);
        if (!$parent) {
            $parentId = 0;
        } elseif ((int)$parent['parent_id'] > 0) {
            $parentId = (int)$parent['parent_id']; // one level of replies
        }
    }
    $recent = (int)fetch_val('SELECT COUNT(*) FROM community_comments WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 MINUTE', [$uid], 0);
    if ($recent >= 8) {
        return ['ok' => false, 'error' => 'You are commenting very fast. Wait a few seconds.'];
    }
    q('INSERT INTO community_comments (post_id, user_id, parent_id, body, sticker) VALUES (?,?,?,?,?)', [$postId, $uid, $parentId ?: null, $text, $sticker]);
    $id = (int)db()->lastInsertId();
    q('UPDATE community_posts SET comments = (SELECT COUNT(*) FROM community_comments WHERE post_id = ? AND deleted_at IS NULL), last_activity_at = NOW() WHERE id = ?', [$postId, $postId]);
    $url = 'creator/community.php?post=' . $postId;
    $snippet = $text !== '' ? mb_substr($text, 0, 120) : 'sent a sticker';
    $told = [];
    if ($parent && (int)$parent['user_id'] !== $uid) {
        notif_add((int)$parent['user_id'], 'community', community_first_name($user) . ' replied to your comment', $snippet, $url);
        $told[(int)$parent['user_id']] = true;
    }
    if ((int)$post['user_id'] !== $uid && empty($told[(int)$post['user_id']])) {
        notif_add((int)$post['user_id'], 'community', community_first_name($user) . ' commented on your post', $snippet, $url);
    }
    return ['ok' => true, 'id' => $id];
}

/** Remove a post or comment (author or HPL team). */
function community_remove(array $user, string $kind, int $id): array
{
    $table = $kind === 'comment' ? 'community_comments' : 'community_posts';
    $row = fetch_one("SELECT * FROM $table WHERE id = ? AND deleted_at IS NULL", [$id]);
    if (!$row) {
        return ['ok' => false, 'error' => 'Already removed.'];
    }
    if ((int)$row['user_id'] !== (int)$user['id'] && $user['role'] !== 'admin') {
        return ['ok' => false, 'error' => 'You can only remove your own posts.'];
    }
    q("UPDATE $table SET deleted_at = NOW() WHERE id = ?", [$id]);
    if ($kind === 'comment') {
        q('UPDATE community_comments SET deleted_at = NOW() WHERE parent_id = ? AND deleted_at IS NULL', [$id]);
        $pid = (int)$row['post_id'];
        q('UPDATE community_posts SET comments = (SELECT COUNT(*) FROM community_comments WHERE post_id = ? AND deleted_at IS NULL) WHERE id = ?', [$pid, $pid]);
    }
    return ['ok' => true];
}

/* ------------------------------------------------------------------ sidebar data */

function community_stats(): array
{
    $s = ['members' => 0, 'online' => 0, 'posts_week' => 0, 'new_week' => 0];
    try {
        $s['members'] = (int)fetch_val('SELECT COUNT(*) FROM users WHERE status = "active" AND role = "creator"', [], 0);
        $s['online'] = count(active_users());
        $s['new_week'] = (int)fetch_val('SELECT COUNT(*) FROM users WHERE status = "active" AND role = "creator" AND created_at >= NOW() - INTERVAL 7 DAY', [], 0);
        if (community_ready()) {
            $s['posts_week'] = (int)fetch_val('SELECT COUNT(*) FROM community_posts WHERE deleted_at IS NULL AND created_at >= NOW() - INTERVAL 7 DAY', [], 0)
                + (int)fetch_val('SELECT COUNT(*) FROM community_messages WHERE created_at >= NOW() - INTERVAL 7 DAY', [], 0);
        }
    } catch (Throwable $e) {
    }
    return $s;
}

/** Top people by points: $days = 7, 30 or 0 (all time). */
function community_leaderboard(int $days, int $limit = 10): array
{
    if (!community_ready()) {
        return [];
    }
    $since = $days > 0 ? ' AND r.created_at >= NOW() - INTERVAL ' . (int)$days . ' DAY' : '';
    $rows = fetch_all(
        "SELECT r.author_id uid, COUNT(*) pts FROM community_reactions r JOIN users u ON u.id = r.author_id AND u.status = 'active' AND u.role = 'creator'
          WHERE r.author_id <> r.user_id$since GROUP BY r.author_id ORDER BY pts DESC, MIN(r.id) ASC LIMIT " . (int)$limit
    );
    $people = community_people(array_column($rows, 'uid'));
    $out = [];
    foreach ($rows as $i => $r) {
        if (!isset($people[(int)$r['uid']])) {
            continue;
        }
        $p = $people[(int)$r['uid']];
        $out[] = ['rank' => $i + 1, 'pts' => (int)$r['pts'], 'level' => community_level($p['points'])] + $p;
    }
    return $out;
}

/** Members list for the Members tab. $filter: all | online | team | new */
function community_members(string $query = '', string $filter = 'all', int $limit = 60): array
{
    $where = ['status = "active"', $filter === 'team' ? 'role = "admin"' : 'role = "creator"']; // owner, 2026-11-13: the HPL team is not a member
    $args = [];
    if ($query !== '') {
        $where[] = 'full_name LIKE ?';
        $args[] = '%' . str_replace(['%', '_'], ['\%', '\_'], $query) . '%';
    }
    if ($filter === 'online') {
        $where[] = 'last_seen >= NOW() - INTERVAL ' . presence_window_minutes() . ' MINUTE';
    } elseif ($filter === 'team') {
        $where[] = 'role = "admin"';
    } elseif ($filter === 'new') {
        $where[] = 'created_at >= NOW() - INTERVAL 14 DAY';
    }
    $rows = fetch_all('SELECT id FROM users WHERE ' . implode(' AND ', $where) . ' ORDER BY (last_seen IS NULL), last_seen DESC LIMIT ' . (int)$limit, $args);
    $people = community_people(array_column($rows, 'id'));
    $out = [];
    foreach ($rows as $r) {
        if (isset($people[(int)$r['id']])) {
            $p = $people[(int)$r['id']];
            $p['level'] = community_level($p['points']);
            $out[] = $p;
        }
    }
    return $out;
}

/** What is happening: new members, payouts and approved clips (all already public on HPL pages). */
function community_activity(int $limit = 8): array
{
    $items = [];
    try {
        foreach (fetch_all('SELECT id, full_name, created_at FROM users WHERE status = "active" AND role = "creator" ORDER BY id DESC LIMIT 6') as $r) {
            $items[] = ['kind' => 'joined', 'uid' => (int)$r['id'], 'name' => (string)$r['full_name'], 'ts' => strtotime((string)$r['created_at']) ?: 0, 'text' => 'joined the community'];
        }
        foreach (fetch_all('SELECT w.user_id, u.full_name, w.amount, COALESCE(w.resolved_at, w.requested_at) t FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = "paid" ORDER BY t DESC LIMIT 6') as $r) {
            $items[] = ['kind' => 'paid', 'uid' => (int)$r['user_id'], 'name' => (string)$r['full_name'], 'ts' => strtotime((string)$r['t']) ?: 0, 'text' => 'got paid ' . money((float)$r['amount'])];
        }
    } catch (Throwable $e) {
    }
    usort($items, function ($a, $b) { return $b['ts'] <=> $a['ts']; });
    return array_slice($items, 0, $limit);
}

/* ------------------------------------------------------------------ live chat extras */

function community_typing_touch(int $uid, string $room = 'general'): void
{
    try {
        if (chat_has_rooms()) {
            q('INSERT INTO community_typing (user_id, typed_at, channel) VALUES (?, NOW(), ?) ON DUPLICATE KEY UPDATE typed_at = NOW(), channel = VALUES(channel)', [$uid, chat_room_key($room)]);
        } else {
            q('INSERT INTO community_typing (user_id, typed_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE typed_at = NOW()', [$uid]);
        }
    } catch (Throwable $e) {
    }
}

function community_typing_now(int $viewer): array
{
    try {
        return array_map(function ($r) { return explode(' ', trim((string)$r['full_name']))[0] ?: 'Someone'; },
            fetch_all('SELECT u.full_name FROM community_typing t JOIN users u ON u.id = t.user_id WHERE t.typed_at >= NOW() - INTERVAL 6 SECOND AND t.user_id <> ? LIMIT 4', [$viewer]));
    } catch (Throwable $e) {
        return [];
    }
}

/** Reply previews and stickers for chat messages: [id => ['reply'=>..,'sticker'=>..]] */
function community_chat_extras(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids || !community_ready()) {
        return [];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $out = [];
    try {
        $rows = fetch_all("SELECT m.id, m.sticker, m.reply_to, r.message r_text, r.sticker r_sticker, ru.full_name r_name
                             FROM community_messages m
                             LEFT JOIN community_messages r ON r.id = m.reply_to
                             LEFT JOIN users ru ON ru.id = r.user_id
                            WHERE m.id IN ($in)", $ids);
    } catch (Throwable $e) {
        return [];
    }
    foreach ($rows as $r) {
        $x = ['sticker' => community_sticker_url($r['sticker'] ?? null), 'reply' => null];
        if ((int)$r['reply_to'] > 0) {
            $x['reply'] = ['id' => (int)$r['reply_to'], 'name' => (string)($r['r_name'] ?? 'Member'),
                'text' => $r['r_text'] !== null ? mb_substr((string)$r['r_text'], 0, 140) : 'Removed message', 'sticker' => (bool)$r['r_sticker']];
        }
        $out[(int)$r['id']] = $x;
    }
    return $out;
}

/**
 * New message in a chat group: tell the people in that group (owner, 2026-11-13: "they should get a notification that
 * pops up on their phone with a sound"). Built for 1,000+ creators:
 *  - who: a campaign group = everyone who joined that campaign; HPL News = every active creator; the other groups = the
 *    people who wrote there in the last 7 days. Never the sender.
 *  - how often: at most one notification per person per group every 20 minutes ("New messages in Lusaka Campaign"),
 *    so a busy chat never floods a phone; HPL News always.
 *  - cost: two queries and one multi-row insert per 300 people, run after the page has answered.
 */
function chat_notify_room(string $room, int $senderId, string $text): void
{
    $rooms = chat_rooms();
    if (!isset($rooms[$room])) {
        return;
    }
    $r = $rooms[$room];
    q('CREATE TABLE IF NOT EXISTS chat_notify_last (user_id INT UNSIGNED NOT NULL, room VARCHAR(24) NOT NULL, at DATETIME NOT NULL, PRIMARY KEY (user_id, room)) ENGINE=InnoDB');
    if ($room === 'announcements') {
        $ids = array_map('intval', array_column(fetch_all('SELECT id FROM users WHERE role = "creator" AND status = "active" AND id <> ?', [$senderId]), 'id'));
    } elseif (!empty($r['campaign'])) {
        $ids = array_map('intval', array_column(fetch_all('SELECT j.user_id FROM campaign_joins j JOIN users u ON u.id = j.user_id WHERE j.campaign_id = ? AND u.status = "active" AND j.user_id <> ?', [(int)$r['campaign'], $senderId]), 'user_id'));
    } else {
        $ids = array_map('intval', array_column(fetch_all('SELECT DISTINCT user_id FROM community_messages WHERE channel = ? AND created_at > NOW() - INTERVAL 7 DAY AND user_id <> ?', [$room, $senderId]), 'user_id'));
    }
    if (!$ids) {
        return;
    }
    if ($room !== 'announcements') {
        // Only those not told about this group in the last 20 minutes.
        $recent = [];
        foreach (array_chunk($ids, 500) as $ch) {
            foreach (fetch_all('SELECT user_id FROM chat_notify_last WHERE room = ? AND at > NOW() - INTERVAL 20 MINUTE AND user_id IN (' . implode(',', $ch) . ')', [$room]) as $x) {
                $recent[(int)$x['user_id']] = true;
            }
        }
        $ids = array_values(array_filter($ids, fn($i) => !isset($recent[$i])));
    }
    if (!$ids) {
        return;
    }
    $who = (string)fetch_val('SELECT full_name FROM users WHERE id = ?', [$senderId], 'Someone');
    $first = trim(explode(' ', trim($who))[0] ?? '') ?: 'Someone';
    $title = $room === 'announcements' ? 'HPL News' : 'New messages in ' . $r['name'];
    $body = $first . ': ' . mb_strimwidth(trim(preg_replace('/\s+/', ' ', $text)), 0, 140, '…');
    $url = 'creator/community.php?room=' . rawurlencode($room);
    foreach (array_chunk($ids, 300) as $ch) {
        $vals = [];
        $args = [];
        foreach ($ch as $i) {
            $vals[] = '(?,?,?,?,?)';
            array_push($args, $i, 'chat', mb_substr($title, 0, 190), mb_substr($body, 0, 255), $url);
        }
        q('INSERT INTO notifications (user_id, type, title, body, url) VALUES ' . implode(',', $vals), $args);
        q('INSERT INTO chat_notify_last (user_id, room, at) VALUES ' . implode(',', array_fill(0, count($ch), '(?,?,NOW())')) . ' ON DUPLICATE KEY UPDATE at = NOW()',
            array_merge(...array_map(fn($i) => [$i, $room], $ch)));
        require_once __DIR__ . '/push.php';
        push_mark($ch);
    }
}

