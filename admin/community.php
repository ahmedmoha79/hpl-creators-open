<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/community.php';
$user = require_admin();
$pageTitle = 'Community';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'reply') {
        $err = community_post((int)$user['id'], (string)($_POST['message'] ?? ''));
        flash($err ? 'error' : 'success', $err ?: 'Reply posted to the community.');
    } elseif ($action === 'update') {
        $mid = (int)post('id');
        $text = trim((string)post('message'));
        if ($mid > 0 && $text !== '') {
            q('UPDATE community_messages SET message = ? WHERE id = ?', [$text, $mid]);
            flash('success', 'Message updated.');
        } else {
            flash('error', 'Message cannot be empty.');
        }
    } elseif ($action === 'sticker_price') {
        $price = round((float)post('price'), 2);
        if ($price < 0 || $price > 1000) {
            flash('error', 'Choose a price between K0 and K1,000.');
        } else {
            set_setting('sticker_pack_price', (string)$price);
            flash('success', $price > 0 ? 'Paid sticker packs now cost ' . money($price) . ' each.' : 'Paid sticker packs are now free for everyone.');
        }
        redirect('admin/community.php');
    } elseif ($action === 'delete') {
        if (community_has_soft_delete()) {
            q('UPDATE community_messages SET deleted_at = NOW() WHERE id = ?', [(int)post('id')]); // disappears for everyone on their next refresh
        } else {
            q('DELETE FROM community_messages WHERE id = ?', [(int)post('id')]);
        }
        flash('warning', 'Message deleted.');
    }
    require_once __DIR__ . '/../includes/chat_cache.php';
    chat_bump();
    redirect('admin/community.php');
}

$editId = (int)get('edit');
$editMsg = $editId > 0 ? fetch_one('SELECT * FROM community_messages WHERE id = ?', [$editId]) : null;
$live = community_has_soft_delete() ? ' WHERE m.deleted_at IS NULL' : '';
$messages = [];
try {
    $messages = fetch_all('SELECT m.id, m.user_id, m.message, m.sticker, m.created_at, ' . (chat_has_rooms() ? 'm.channel, ' : '') . 'u.full_name, u.role, u.avatar_url
                             FROM community_messages m JOIN users u ON u.id = m.user_id' . $live . ' ORDER BY m.id DESC LIMIT 60');
} catch (Throwable $e) {
    $messages = array_reverse(community_messages(60));
}
$activeNow = active_users();
$cStats = community_stats();
$msgsToday = (int)fetch_val('SELECT COUNT(*) FROM community_messages WHERE created_at >= CURDATE()', [], 0);
$board = community_leaderboard(7, 5);
$people = community_people(array_column($activeNow, 'id'));
$packSales = [];
try {
    foreach (fetch_all('SELECT pack, COUNT(*) n, COALESCE(SUM(price),0) t FROM sticker_purchases GROUP BY pack') as $r) {
        $packSales[$r['pack']] = $r;
    }
} catch (Throwable $e) {
}
$salesTotal = array_sum(array_map(function ($r) { return (float)$r['t']; }, $packSales));
$salesCount = array_sum(array_map(function ($r) { return (int)$r['n']; }, $packSales));
$initial = function (string $n): string { return strtoupper(mb_substr(trim($n) ?: '?', 0, 1)); };
require __DIR__ . '/../includes/admin_header.php';
?>
<link rel="stylesheet" href="<?= e(u('assets/css/admin-community.css?v=20261109a')) ?>">
<div class="acm">
    <section class="acm-hero">
        <div class="acm-hero__txt">
            <h1>Community</h1>
            <p>Talk with creators, keep the chat friendly, and see who is active.</p>
        </div>
        <div class="acm-hero__actions">
            <a class="acm-btn acm-btn--gold" href="<?= e(u('creator/community.php?tab=feed')) ?>"><?= icon('chats') ?>Open the posts</a>
            <a class="acm-btn acm-btn--dark" href="<?= e(u('creator/community.php?tab=chat')) ?>"><?= icon('message') ?>Open the chat groups</a>
        </div>
    </section>

    <div class="acm-stats">
        <div class="acm-stat"><span>Members</span><strong><?= e(num($cStats['members'])) ?></strong><small>+<?= e(num($cStats['new_week'])) ?> this week</small></div>
        <div class="acm-stat acm-stat--live"><span><i></i>Online now</span><strong><?= e(num(count($activeNow))) ?></strong><small>last <?= (int)presence_window_minutes() ?> minutes</small></div>
        <div class="acm-stat"><span>Messages today</span><strong><?= e(num($msgsToday)) ?></strong><small><?= e(num($cStats['posts_week'])) ?> posts and messages this week</small></div>
        <div class="acm-stat"><span>Sticker sales</span><strong><?= money($salesTotal) ?></strong><small><?= e(num($salesCount)) ?> pack<?= $salesCount === 1 ? '' : 's' ?> bought</small></div>
    </div>

    <div class="acm-grid">
        <section class="acm-card acm-chat" aria-labelledby="acmChat">
            <div class="acm-card__head"><h2 id="acmChat">Live chat</h2><span>Newest first. Edit or remove anything that breaks the rules.</span></div>
            <?php if ($editMsg): ?>
                <form method="post" class="acm-edit">
                    <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= (int)$editMsg['id'] ?>">
                    <label for="acmEditText">Edit message</label>
                    <textarea class="textarea" id="acmEditText" name="message" rows="3" maxlength="1000" required><?= e($editMsg['message']) ?></textarea>
                    <div class="acm-row"><button class="acm-btn acm-btn--dark" type="submit">Save changes</button><a class="acm-link" href="<?= e(u('admin/community.php')) ?>">Cancel</a></div>
                </form>
            <?php endif; ?>
            <form method="post" class="acm-reply">
                <?= csrf_field() ?><input type="hidden" name="action" value="reply">
                <textarea class="textarea" name="message" rows="1" maxlength="1000" placeholder="Write to everyone as the HPL team..." required aria-label="Message everyone"></textarea>
                <button class="acm-btn acm-btn--dark" type="submit" aria-label="Send"><?= icon('send') ?></button>
            </form>
            <?php if (!$messages): ?>
                <p class="acm-empty">No messages yet.</p>
            <?php else: ?>
                <ol class="acm-msgs">
                    <?php foreach ($messages as $m): $sticker = !empty($m['sticker']) ? community_sticker_url((string)$m['sticker']) : null; $isTeam = $m['role'] === 'admin'; ?>
                        <li class="acm-msg<?= $isTeam ? ' is-team' : '' ?>">
                            <?php if (!empty($m['avatar_url'])): ?><img class="acm-av" src="<?= e($m['avatar_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="acm-av"><?= e($initial((string)$m['full_name'])) ?></span><?php endif; ?>
                            <div class="acm-msg__body">
                                <div class="acm-msg__top"><strong><?= e($m['full_name']) ?></strong><?php if ($isTeam): ?><span class="acm-tag">HPL team</span><?php endif; ?><?php if (!empty($m['channel'])): ?><span class="acm-tag" style="background:none;border:1px solid currentColor;opacity:.7"><?= e(chat_rooms()[$m['channel']]['name'] ?? $m['channel']) ?></span><?php endif; ?><time datetime="<?= e($m['created_at']) ?>"><?= e(time_ago($m['created_at'])) ?></time></div>
                                <?php if (trim((string)$m['message']) !== ''): ?><p><?= nl2br(e((string)$m['message'])) ?></p><?php endif; ?>
                                <?php if ($sticker): ?><img class="acm-sticker" src="<?= e($sticker) ?>" alt="Sticker" loading="lazy"><?php endif; ?>
                            </div>
                            <div class="acm-msg__tools">
                                <a class="acm-icon" href="<?= e(u('admin/community.php?edit=' . (int)$m['id'])) ?>" aria-label="Edit message" title="Edit"><?= icon('edit') ?></a>
                                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="acm-icon acm-icon--bad" type="submit" data-confirm="Delete this message for everyone?" aria-label="Delete message" title="Delete"><?= icon('trash') ?></button></form>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </section>

        <div class="acm-side">
            <section class="acm-card" aria-labelledby="acmOnline">
                <div class="acm-card__head"><h2 id="acmOnline">Online now</h2><span class="acm-pill"><i></i><?= e(num(count($activeNow))) ?></span></div>
                <?php if (!$activeNow): ?>
                    <p class="acm-empty">Nobody is online right now.</p>
                <?php else: ?>
                    <ul class="acm-people">
                        <?php foreach (array_slice($activeNow, 0, 12) as $a): $pp = $people[(int)$a['id']] ?? []; ?>
                            <li><?php if (!empty($pp['avatar'])): ?><img class="acm-av acm-av--sm" src="<?= e($pp['avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="acm-av acm-av--sm"><?= e($initial((string)$a['full_name'])) ?></span><?php endif; ?>
                                <span><?= e($a['full_name']) ?></span><?php if ($a['role'] === 'admin'): ?><span class="acm-tag">Team</span><?php endif; ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (count($activeNow) > 12): ?><p class="acm-more">and <?= e(num(count($activeNow) - 12)) ?> more</p><?php endif; ?>
                <?php endif; ?>
            </section>

            <section class="acm-card" aria-labelledby="acmTop">
                <div class="acm-card__head"><h2 id="acmTop">Most helpful this week</h2><a class="acm-link" href="<?= e(u('creator/community.php?tab=leaderboard')) ?>">See all</a></div>
                <?php if (!$board): ?>
                    <p class="acm-empty">No points yet this week.</p>
                <?php else: ?>
                    <ol class="acm-board">
                        <?php foreach ($board as $b): ?>
                            <li><span class="acm-rank acm-rank--<?= (int)$b['rank'] ?>"><?= (int)$b['rank'] ?></span><span class="acm-board__name"><?= e($b['name'] ?? '') ?></span><strong>+<?= (int)$b['pts'] ?></strong></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </section>

            <section class="acm-card" aria-labelledby="acmStk">
                <div class="acm-card__head"><h2 id="acmStk">Sticker packs</h2></div>
                <p class="small muted" style="margin:0 0 10px">Where creators find them: in any chat group, the round smiley button at the left of the "Message" box opens the stickers. As the HPL team you have every pack unlocked.</p>
                <ul class="acm-packs">
                    <?php foreach (community_sticker_packs() as $pk => $p): if ($p['lock'] === 'hidden') { continue; } $sale = $packSales[$pk] ?? null; ?>
                        <li><img src="<?= e(community_sticker_url($p['cover'])) ?>" alt="" loading="lazy">
                            <span><strong><?= e($p['name']) ?></strong><small><?= e(count($p['stickers'])) ?> stickers &middot; <?= $p['lock'] === 'buy' ? 'Paid' : ($p['lock'] === 'level' ? ucfirst($p['level']) . ' level' : 'Free') ?></small></span>
                            <?php if ($p['lock'] === 'buy'): ?><em><?= (int)($sale['n'] ?? 0) ?> sold</em><?php endif; ?>
                            <details style="flex-basis:100%"><summary class="small" style="cursor:pointer;padding:6px 0">See all <?= count($p['stickers']) ?> stickers</summary>
                                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(56px,1fr));gap:8px;padding:6px 0"><?php foreach ($p['stickers'] as $sk => $sv): $skKey = is_int($sk) ? (string)$sv : (string)$sk; ?><img src="<?= e((string)community_sticker_url($skKey)) ?>" alt="<?= e($skKey) ?>" title="<?= e($skKey) ?>" loading="lazy" style="width:56px;height:56px;object-fit:contain"><?php endforeach; ?></div>
                            </details></li>
                    <?php endforeach; ?>
                </ul>
                <form method="post" class="acm-price">
                    <?= csrf_field() ?><input type="hidden" name="action" value="sticker_price">
                    <label for="stkPrice">Price of a paid pack (K)</label>
                    <div class="acm-row"><input class="input" id="stkPrice" type="number" name="price" min="0" max="1000" step="0.5" value="<?= e((string)sticker_pack_price()) ?>"><button class="acm-btn acm-btn--dark" type="submit">Save price</button></div>
                    <small>Paid once from the creator's wallet. Set 0 to make them free.</small>
                </form>
                <p class="acm-credit">Sticker art: Microsoft Fluent Emoji (MIT licence).</p>
            </section>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
