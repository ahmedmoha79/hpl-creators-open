<?php
/**
 * Offers (2026-11-10): short messages that pop up for creators on Home. See includes/offers.php.
 * An offer never pays money by itself; real bonuses are made in Rewards & bonuses.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/offers.php';
$user = require_admin();
$pageTitle = 'Offers';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    $id = (int)post('id');
    if ($action === 'create') {
        $title = mb_substr(trim(post('title')), 0, 80);
        $body = mb_substr(trim(post('body')), 0, 400);
        $label = mb_substr(trim(post('btn_label')), 0, 40);
        $url = offer_clean_url(post('btn_url'));
        $aud = array_key_exists(post('audience'), offer_audiences()) ? post('audience') : 'all';
        $days = max(0, min(60, (int)post('days')));
        if ($title === '' || $body === '') {
            flash('error', 'Write a title and a message.');
        } else {
            q('INSERT INTO offers (title, body, btn_label, btn_url, audience, style, ends_at) VALUES (?,?,?,?,?,?,?)',
                [$title, $body, $url !== '' ? ($label !== '' ? $label : 'Open') : '', $url, $aud, post('style') === 'line' ? 'line' : 'card', $days > 0 ? date('Y-m-d H:i:s', time() + $days * 86400) : null]);
            flash('success', 'The offer is live. Creators see it the next time they open Home.');
        }
    } elseif ($action === 'toggle' && $id > 0) {
        q('UPDATE offers SET is_active = 1 - is_active WHERE id = ?', [$id]);
    } elseif ($action === 'delete' && $id > 0) {
        q('DELETE FROM offer_views WHERE offer_id = ?', [$id]);
        q('DELETE FROM offers WHERE id = ?', [$id]);
        flash('success', 'Offer deleted.');
    }
    redirect('admin/offers.php');
}
$offers = fetch_all('SELECT o.*, (SELECT COUNT(*) FROM offer_views v WHERE v.offer_id = o.id) seen, (SELECT COUNT(*) FROM offer_views v WHERE v.offer_id = o.id AND v.clicked = 1) clicks FROM offers o ORDER BY o.id DESC LIMIT 50');
$links = ['creator/index.php?tab=campaigns' => 'Campaigns', 'creator/connect_tiktok.php' => 'Connect TikTok', 'creator/assessment.php' => 'The test', 'creator/tiktok_ready.php' => 'Get your TikTok ready',
    'creator/rewards.php' => 'Rewards', 'creator/community.php' => 'Community chat', 'creator/withdraw.php' => 'Withdraw', 'creator/course.php' => 'Academy'];
require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Offers</h1>
<p class="muted">A short message with one button that pops up for creators on their Home page: a small card from the bottom, or a thin line at the top. Each creator sees an offer once. <strong>An offer is only a message</strong>: to give real money, make the bonus in <a href="<?= e(u('admin/rewards.php')) ?>">Rewards &amp; bonuses</a> and point the offer to it.</p>

<div class="panel"><div class="panel__head"><h3>New offer</h3></div><div class="panel__body">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create">
        <div class="field"><label class="label" for="oTitle">Title (short)</label><input class="input" id="oTitle" name="title" maxlength="80" required placeholder="New clips just landed"></div>
        <div class="field"><label class="label" for="oBody">Message</label><textarea class="input" id="oBody" name="body" maxlength="400" rows="2" required placeholder="20 new ready clips for the Mumbwa campaign. Take one before they are gone."></textarea></div>
        <div class="form-grid">
            <div class="field"><label class="label" for="oUrl">Button opens</label><select class="input" id="oUrl" name="btn_url"><option value="">No button</option><?php foreach ($links as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label class="label" for="oLabel">Words on the button</label><input class="input" id="oLabel" name="btn_label" maxlength="40" placeholder="Take a clip"></div>
            <div class="field"><label class="label" for="oAud">Who sees it</label><select class="input" id="oAud" name="audience"><?php foreach (offer_audiences() as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></div>
            <div class="field"><label class="label" for="oStyle">How it shows</label><select class="input" id="oStyle" name="style"><option value="card">Small card from the bottom</option><option value="line">Thin line at the top</option></select></div>
            <div class="field"><label class="label" for="oDays">Stop after (days, 0 = until you switch it off)</label><input class="input" id="oDays" type="number" name="days" min="0" max="60" value="7"></div>
        </div>
        <button class="btn btn--primary" type="submit">Show this offer</button>
    </form>
</div></div>

<div class="panel"><div class="panel__head"><h3>Offers</h3></div><div class="panel__body">
    <?php if (!$offers): ?><p class="muted" style="margin:0">No offers yet.</p><?php else: ?>
    <div style="overflow-x:auto"><table class="table"><thead><tr><th>Offer</th><th>Who</th><th>Seen</th><th>Tapped</th><th>Status</th><th></th></tr></thead><tbody>
    <?php foreach ($offers as $o): $over = $o['ends_at'] !== null && strtotime((string)$o['ends_at']) < time(); ?>
        <tr><td><strong><?= e($o['title']) ?></strong><div class="small muted"><?= e($o['body']) ?></div></td>
            <td class="small"><?= e(offer_audiences()[$o['audience']] ?? $o['audience']) ?><div class="muted"><?= $o['style'] === 'line' ? 'Line' : 'Card' ?></div></td>
            <td><?= e(num((int)$o['seen'])) ?></td><td><?= e(num((int)$o['clicks'])) ?><?= (int)$o['seen'] > 0 ? ' <span class="small muted">(' . round((int)$o['clicks'] / (int)$o['seen'] * 100) . '%)</span>' : '' ?></td>
            <td><span class="badge <?= $over || !$o['is_active'] ? '' : 'ok' ?>"><?= $over ? 'Ended' : ($o['is_active'] ? 'Showing' : 'Off') ?></span><?php if ($o['ends_at'] && !$over): ?><div class="small muted">until <?= e(date('D j M', strtotime((string)$o['ends_at']))) ?></div><?php endif; ?></td>
            <td><div class="flex" style="gap:6px"><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn--ghost btn--sm" type="submit"><?= $o['is_active'] ? 'Switch off' : 'Switch on' ?></button></form>
                <form method="post" data-confirm="Delete this offer?"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$o['id'] ?>"><button class="btn btn--danger btn--sm" type="submit">Delete</button></form></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php endif; ?>
</div></div>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
