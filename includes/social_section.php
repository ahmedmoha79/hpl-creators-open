<?php
/**
 * "Your TikTok" accounts and page verification (moved from Home to Profile, 2026-11-08).
 * Expects $user. Loads what it needs itself.
 */
require_once __DIR__ . '/tiktok.php';
$ttOn = tiktok_configured();
$ttStatus = $ttOn ? tiktok_creator_status((int)$user['id']) : ['accounts' => [], 'clips' => 0, 'unreadable' => 0, 'needs' => [], 'state' => 'none'];
$mySocials = fetch_all('SELECT * FROM social_pages WHERE user_id = ? ORDER BY submitted_at DESC', [(int)$user['id']]);
$platformIcon = $platformIcon ?? function (string $p): string {
    $known = ['tiktok', 'instagram', 'facebook', 'youtube'];
    return '<span class="ui-dot ui-dot--' . (in_array($p, $known, true) ? $p : 'neutral') . '">' . icon(in_array($p, $known, true) ? $p : 'link') . '</span>';
};
?>
<!-- Social pages + verification -->
<section class="ui-card" id="socialPage">
    <div class="ui-card__head">
        <div>
            <h2><?= icon('tiktok') ?>Your TikTok</h2>
            <p class="ui-card__sub">Only clips posted on your own TikTok can earn.</p>
        </div>
    </div>

    <?php if ($ttOn): ?>
        <?php foreach ($ttStatus['accounts'] as $tx): $ta = $tx['row']; $tp = $tx['problem']; ?>
            <div class="ui-connect">
                <?php if (!empty($ta['avatar_url'])): ?>
                    <img class="ui-connect__logo" style="object-fit:cover;border-radius:50%;background:#eee" src="<?= e($ta['avatar_url']) ?>" alt="" width="40" height="40" referrerpolicy="no-referrer" onerror="this.style.visibility='hidden'">
                <?php else: ?>
                    <span class="ui-connect__logo"><?= icon('tiktok') ?></span>
                <?php endif; ?>
                <div class="ui-connect__text">
                    <strong><?= $tp !== null ? 'Reconnect needed' : 'TikTok connected' ?><?= $ta['username'] ? ': @' . e($ta['username']) : '' ?></strong>
                    <?php
                    $ttMeta = [];
                    if (!empty($ta['display_name'])) { $ttMeta[] = e($ta['display_name']); }
                    if ($ta['follower_count'] !== null) { $fc = (int)$ta['follower_count']; $ttMeta[] = '<b>' . num($fc) . '</b> follower' . ($fc === 1 ? '' : 's'); }
                    ?>
                    <?php if ($ttMeta && $tp === null): ?><span class="ui-connect__meta" style="display:block;color:var(--ui-text);font-size:.88rem;margin:1px 0 2px"><?= implode(' &middot; ', $ttMeta) ?></span><?php endif; ?>
                    <span><?= $tp !== null ? e(tiktok_problem_text($tp)) : 'Views update on their own' . ($ta['last_sync_at'] ? ' · read ' . e(time_ago($ta['last_sync_at'])) : '') . '.' ?></span>
                </div>
                <?php if ($tp !== null): ?>
                    <a class="btn btn--tiktok btn--sm" href="<?= e(u('creator/connect_tiktok.php')) ?>">Reconnect</a>
                <?php else: ?>
                <form method="post" action="<?= e(u('tiktok_auth.php')) ?>">
                    <?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><input type="hidden" name="account_id" value="<?= (int)$ta['id'] ?>">
                    <button class="btn btn--ghost btn--sm" type="submit" data-confirm="Disconnect @<?= e($ta['username'] ?: 'TikTok') ?>? The views of its clips will stop being read.">Disconnect</button>
                </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <div class="ui-connect">
            <span class="ui-connect__logo"><?= icon('tiktok') ?></span>
            <div class="ui-connect__text">
                <strong><?= $ttStatus['accounts'] ? 'Connect another TikTok account' : 'Connect your TikTok account' ?></strong>
                <span><?= $ttStatus['accounts'] ? 'Switch to it in TikTok first.' : 'Sign in with TikTok. Views then count by themselves.' ?></span>
            </div>
            <a class="btn btn--tiktok btn--sm" href="<?= e(u('creator/connect_tiktok.php' . ($ttStatus['accounts'] ? '?another=1' : ''))) ?>"><?= icon('tiktok') ?>Connect</a>
        </div>
    <?php endif; ?>

    <?php require __DIR__ . '/sp_creator.php'; ?>
    <?= coming_soon_html() ?>
</section>

