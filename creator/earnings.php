<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
$user = require_creator();
$pageTitle = 'Wallet';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('action') === 'refresh') {
    csrf_check();
    require_once __DIR__ . '/../includes/view_fetcher.php';
    $balBefore = (float)creator_stats((int)$user['id'])['balance'];
    $summary = sync_views((int)$user['id'], 300);
    $csummary = sync_campaign_submissions((int)$user['id'], 300);
    $got = (float)$summary['credited'] + (float)$csummary['credited'];
    $toBalance = max(0.0, round((float)creator_stats((int)$user['id'])['balance'] - $balBefore, 2));
    $locked = max(0.0, round($got - $toBalance, 2));
    flash('success', $got <= 0 ? 'Views refreshed. No new money yet; keep your clips going.'
        : 'Views refreshed. ' . ($toBalance > 0 ? money($toBalance) . ' added to your balance.' : '')
          . ($locked > 0 ? ' ' . money($locked) . ' earned on clips waiting for approval (unlocks when our team approves them).' : ''));
    redirect('creator/earnings.php');
}

$uid = (int)$user['id'];
$stats = creator_stats($uid);
$rate = rate_per_1000();
$minPayout = payout_min();
$maxPayout = payout_max();
$series = earning_series($uid, 30);
$activity = earning_activity($uid, 14);
$monthEarned = 0.0;
$periodEarned = 0.0;
$periodViews = 0;
$seriesMax = 0.0;
foreach ($series as $day => $d) {
    $periodEarned += $d['amount'];
    $periodViews += $d['views'];
    $seriesMax = max($seriesMax, $d['amount']);
    if (substr($day, 0, 7) === date('Y-m')) {
        $monthEarned += $d['amount'];
    }
}
$live = creator_live($uid);
$payouts = fetch_all('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY requested_at DESC LIMIT 6', [$uid]);
$pendingOut = (float)$stats['reserved'] - (float)$stats['paid'];
require_once __DIR__ . '/../includes/rewards.php';
$rewardsClaimable = achievement_claimable_count($uid);

require __DIR__ . '/../includes/creator_header.php';
?>
<link rel="stylesheet" href="<?= e(u('assets/css/rewards.css?v=20261108d')) ?>">
<div class="ui-page">
    <div class="ui-page-head">
        <div>
            <h1>Wallet</h1>
            <p><?php if (general_clips_enabled()): ?><?= money($rate) ?> per 1,000 verified views<?= earnings_mode() === 'blocks' ? ', added each time a clip passes another 1,000' : ', for every view' ?>.<?php else: ?>Each campaign pays its own rate per 1,000 views.<?php endif; ?> Views are read automatically.</p>
        </div>
        <div class="ui-page-head__actions">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="refresh">
                <button class="btn btn--ghost" type="submit"><?= icon('refresh') ?>Refresh my views</button>
            </form>
            <a class="btn btn--primary" href="<?= e(u('creator/withdraw.php')) ?>"><?= icon('wallet') ?>Withdraw</a>
        </div>
    </div>

    <div class="ui-stats">
        <div class="ui-stat ui-stat--dark">
            <div class="ui-stat__label"><?= icon('wallet') ?>Available balance</div>
            <div class="ui-stat__value"><?= money($stats['balance']) ?></div>
            <?php if ($stats['in_review'] > 0): ?><div class="ui-stat__meta"><?= money($stats['in_review']) ?> waiting for review. Added once we approve your clip.</div><?php endif; ?>
            <?php if ((float)($stats['spent'] ?? 0) > 0): ?><div class="ui-stat__meta"><?= money($stats['spent']) ?> spent on sticker packs</div><?php endif; ?>
            <div class="ui-stat__meta"><?= $stats['balance'] >= $minPayout ? '<a href="' . e(u('creator/withdraw.php')) . '">Withdraw up to ' . money(min($stats['balance'], $maxPayout)) . '</a>' : money($minPayout - $stats['balance']) . ' to the ' . money($minPayout) . ' minimum' ?></div>
            <?php if ($live['held'] > 0): ?><div class="ui-stat__meta" title="Campaign clips below the campaign's minimum per clip. Paid once they reach it."><?= money($live['held']) ?> pending on campaign clips</div><?php endif; ?>
        </div>
        <div class="ui-stat">
            <div class="ui-stat__label"><?= icon('trending') ?>This month</div>
            <div class="ui-stat__value"><?= money($monthEarned) ?></div>
            <div class="ui-stat__meta"><?php if ($live['accruing'] > 0): ?><?= money($live['accruing']) ?> building up on your clips<?php else: ?><?= ($live['today_amount'] > 0 ? '+' : '') . money($live['today_amount']) ?> today &middot; <?= e(num($live['today_views'])) ?> views<?php endif; ?></div>
        </div>
        <div class="ui-stat">
            <div class="ui-stat__label"><?= icon('chart') ?>Lifetime earned</div>
            <div class="ui-stat__value"><?= money($stats['earned']) ?></div>
            <div class="ui-stat__meta"><?php if ((float)$stats['bonus'] > 0): ?><?= money(round((float)$stats['earned'] - (float)$stats['bonus'], 2)) ?> from views + <?= money($stats['bonus']) ?> bonus &middot; <?php endif; ?><?= e(num($stats['views'])) ?> verified views</div>
        </div>
        <div class="ui-stat">
            <div class="ui-stat__label"><?= icon('banknote') ?>Paid out</div>
            <div class="ui-stat__value"><?= money($stats['paid']) ?></div>
            <div class="ui-stat__meta"><?= $pendingOut > 0 ? money($pendingOut) . ' on its way' : 'Nothing pending' ?></div>
        </div>
    </div>

    <a class="rw-strip<?= $rewardsClaimable > 0 ? ' rw-strip--hot' : '' ?>" href="<?= e(u('creator/rewards.php')) ?>">
        <span class="rw-strip__ico" aria-hidden="true">&#127873;</span>
        <span class="rw-strip__txt"><strong><?= $rewardsClaimable > 0 ? $rewardsClaimable . ' reward' . ($rewardsClaimable === 1 ? '' : 's') . ' ready to claim!' : 'Rewards and bonus codes' ?></strong>
            <small><?= $rewardsClaimable > 0 ? 'Tap to add them to your wallet.' : 'Have a bonus code? Redeem it here and track your achievements.' ?></small></span>
        <?= icon('chevron-right') ?>
    </a>

    <section class="ui-card">
        <div class="ui-card__head">
            <h2><?= icon('chart') ?>Last 30 days</h2>
            <span class="ui-card__sub"><?= money($periodEarned) ?> earned &middot; <?= e(num($periodViews)) ?> views counted</span>
        </div>
        <div class="ui-card__body">
            <div class="ui-bars" role="img" aria-label="Earnings per day for the last 30 days">
                <?php $i = 0; foreach ($series as $day => $d): $i++;
                    $h = $seriesMax > 0 ? max(2, (int)round($d['amount'] / $seriesMax * 100)) : 2; ?>
                    <div class="ui-bars__col<?= $day === date('Y-m-d') ? ' is-today' : '' ?>">
                        <span class="ui-bars__bar<?= $d['amount'] > 0 ? '' : ' is-zero' ?>" style="height:<?= $h ?>%"></span>
                        <span class="ui-bars__tip"><?= e(date('D j M', strtotime($day))) ?><br><b><?= money($d['amount']) ?></b> &middot; <?= e(num($d['views'])) ?> views</span>
                        <?php if ($day === date('Y-m-d') || (($i === 1 || $i % 7 === 1) && $i < 27)): ?><span class="ui-bars__label"><?= $day === date('Y-m-d') ? 'Today' : e(date('j M', strtotime($day))) ?></span><?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <div class="ui-grid">
        <section class="ui-card">
            <div class="ui-card__head">
                <h2><?= icon('trending') ?>Activity</h2>
                <a class="small" href="<?= e(u('creator/submit_links.php')) ?>">All my clips</a>
            </div>
            <?php if (!$activity): ?>
                <div class="ui-empty"><?= icon('chart') ?><p>No activity in the last 14 days. New views and payments on your clips show up here.</p></div>
            <?php else: ?>
                <?php foreach ($activity as $day => $g): ?>
                    <div class="ui-act-day">
                        <span><?= e(day_label($day)) ?></span>
                        <span><?= e(num($g['views'])) ?> views &middot; <b><?= money($g['amount']) ?></b></span>
                    </div>
                    <ul class="ui-rows">
                        <?php foreach ($g['items'] as $it):
                            $isAdj = $it['src'] === 'adjust';
                            $label = $isAdj ? 'Adjustment from the HPL team' : ($it['src'] === 'campaign' ? ($it['campaign'] ?: 'Campaign clip') : ($it['title'] ?: platform_label($it['platform']) . ' clip')); ?>
                            <li class="ui-row">
                                <?php $pk = !$isAdj && in_array($it['platform'], ['tiktok', 'instagram', 'facebook', 'youtube'], true) ? $it['platform'] : ''; ?><span class="ui-dot ui-dot--<?= e($pk ?: 'neutral') ?>"><?= icon($pk ?: ($isAdj ? 'banknote' : 'link')) ?></span>
                                <div class="ui-row__main">
                                    <div class="ui-row__title"><?php if (!empty($it['url'])): ?><?= safe_link((string)$it['url'], $label) ?><?php else: ?><?= e($label) ?><?php endif; ?></div>
                                    <div class="ui-row__sub"><?php if ($isAdj): ?><?= e((string)$it['campaign']) ?><?= !empty($it['title']) ? ' &middot; ' . e($it['title']) : '' ?><?php else: ?><?= $it['src'] === 'campaign' ? 'Campaign clip' : 'Clip' ?> &middot; +<?= e(num($it['views_gained'])) ?> views<?php endif; ?></div>
                                </div>
                                <div class="ui-row__end">
                                    <?php if ((float)$it['amount'] < 0): ?>
                                        <strong class="ui-minus"><?= money($it['amount']) ?></strong>
                                    <?php elseif ((float)$it['amount'] > 0): ?>
                                        <strong class="ui-plus">+<?= money($it['amount']) ?></strong>
                                    <?php elseif (earnings_mode() === 'blocks' && $it['src'] === 'link'): ?>
                                        <small>building up</small>
                                    <?php else: ?>
                                        <small><?= money(0) ?></small>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>

        <aside>
            <section class="ui-card">
                <div class="ui-card__head"><h2><?= icon('banknote') ?>Payouts</h2><a class="small" href="<?= e(u('creator/withdraw.php')) ?>">Withdraw</a></div>
                <?php if (!$payouts): ?>
                    <div class="ui-empty"><?= icon('banknote') ?><p>No payouts yet. Once your balance reaches <?= money($minPayout) ?>, cash out to mobile money.</p></div>
                <?php else: ?>
                    <ul class="ui-rows">
                        <?php foreach ($payouts as $w):
                            $st = (string)$w['status'];
                            $badge = $st === 'paid' ? 'ui-badge--ok' : ($st === 'pending' ? 'ui-badge--warn' : 'ui-badge--bad'); ?>
                            <li class="ui-row">
                                <span class="ui-dot ui-dot--neutral"><?= icon('wallet') ?></span>
                                <div class="ui-row__main">
                                    <div class="ui-row__title"><?= money($w['amount']) ?></div>
                                    <div class="ui-row__sub"><?= e(mobile_providers()[$w['payment_provider'] ?? '']['label'] ?? (preg_match('/^[a-z_]+$/', (string)$w['method']) ? 'Mobile money' : ($w['method'] ?: 'Mobile money'))) ?> &middot; <?= e(time_ago($w['requested_at'])) ?><?= $st === 'paid' && !empty($w['txn_reference']) ? ' &middot; Ref ' . e($w['txn_reference']) : '' ?></div>
                                </div>
                                <div class="ui-row__end"><span class="ui-badge <?= $badge ?>"><?= e(['pending' => 'Processing', 'paid' => 'Paid', 'rejected' => 'Not paid'][$st] ?? ucfirst($st)) ?></span></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="ui-card">
                <div class="ui-card__head"><h2><?= icon('info') ?>How you get paid</h2></div>
                <div class="ui-card__body ui-howpaid">
                    <?php if (general_clips_enabled()): ?>
                    <p><b><?= money($rate) ?></b> for every 1,000 verified views on clips you submit<?= earnings_mode() === 'blocks' ? ', added each time a clip passes another 1,000 views' : '. Every view counts: 500 views is ' . money(views_value(500, $rate)) ?>.</p>
                    <p>Campaigns can pay their own rate. You see it on each campaign.</p>
                    <?php else: ?>
                    <p>You earn from campaigns. Each campaign shows its rate per 1,000 views. Only views your clip gets after you send the link count.</p>
                    <p>Money on a clip is locked until our team approves the clip. Then it moves to your balance.</p>
                    <?php endif; ?>
                    <p>Withdraw from <b><?= money($minPayout) ?></b>, up to <?= money($maxPayout) ?> per request, to MTN, Airtel or Zamtel mobile money.</p>
                </div>
            </section>
        </aside>
    </div>
</div>
<style>
.ui-bars { display: grid; grid-template-columns: repeat(30, minmax(0, 1fr)); align-items: end; gap: 4px; height: 170px; padding-bottom: 22px; position: relative; }
.ui-bars__col { position: relative; height: 100%; display: flex; align-items: flex-end; justify-content: center; }
.ui-bars__bar { width: 100%; max-width: 22px; border-radius: 5px 5px 2px 2px; background: linear-gradient(180deg, #d79a5c, var(--ui-accent)); transition: opacity .15s; }
.ui-bars__bar.is-zero { background: var(--ui-sunken); }
.ui-bars__col.is-today .ui-bars__bar:not(.is-zero) { background: linear-gradient(180deg, #f0c48b, #c98a4e); box-shadow: 0 0 0 2px rgba(201, 138, 78, .25); }
.ui-bars__label { position: absolute; bottom: -20px; left: 50%; transform: translateX(-50%); font-size: .8125rem; color: var(--ui-muted); white-space: nowrap; }
.ui-bars__tip { position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: var(--ui-espresso); color: #f6efe4; font-size: .8125rem; line-height: 1.4; padding: 6px 9px; border-radius: 8px; white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity .12s; z-index: 2; }
.ui-bars__col:hover .ui-bars__tip { opacity: 1; }
.ui-bars__col:hover .ui-bars__bar { opacity: .85; }
.ui-act-day { display: flex; justify-content: space-between; gap: 10px; padding: 12px 20px 6px; font-size: .8125rem; color: var(--ui-muted); border-top: 1px solid var(--ui-line); }
.ui-act-day:first-of-type { border-top: 0; }
.ui-act-day span:first-child { font-weight: 600; letter-spacing: .04em; text-transform: none; }
.ui-plus { color: var(--ui-ok); }
.ui-minus { color: var(--ui-bad); }
.ui-howpaid p { margin: 0 0 10px; color: var(--ui-text-2); font-size: .9rem; }
.ui-howpaid p:last-child { margin: 0; }
@media (max-width: 640px) { .ui-bars { gap: 2px; height: 140px; } .ui-bars__label { font-size: .8125rem; } }
</style>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
