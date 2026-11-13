<?php
/* Homepage: payouts strip, clip of the week, earnings calculator, creator levels. */
require_once __DIR__ . '/social_proof.php';
$spPayouts = sp_recent_payouts(12);
$spClip    = sp_clip_of_week();
$spRate    = (float)setting('rate_per_1000', 10);
$spMin     = payout_min();
$spDemo    = sp_demo();
?>
<section class="section proof" id="proof">
    <div class="container">
        <?php if ($spDemo): ?>
            <div class="proof__demo">Demo preview &middot; sample names and numbers, not real payouts</div>
        <?php endif; ?>

        <div class="section__head">
            <h2>Clip it. Post it. Get paid.</h2>
        </div>

        <?php
        // The strip always runs: real paid withdrawals first, then plain facts about how payouts work.
        $spFacts = [
            ['icon' => 'banknote', 'text' => 'Paid for every <strong>1,000 real views</strong>'],
            ['icon' => 'wallet',   'text' => 'Withdraw from <strong>' . e(money($spMin)) . '</strong>'],
            ['icon' => 'phone',    'text' => 'Paid to <strong>MTN MoMo</strong>'],
            ['icon' => 'phone',    'text' => 'Paid to <strong>Airtel Money</strong>'],
            ['icon' => 'phone',    'text' => 'Paid to <strong>Zamtel Kwacha</strong>'],
            ['icon' => 'eye',      'text' => 'Only <strong>real views</strong> count'],
            ['icon' => 'shield',   'text' => 'Your page, <strong>verified</strong>'],
            ['icon' => 'film',     'text' => '<strong>Free</strong> HPL footage to clip'],
        ];
        ?>
        <div class="payout-strip" aria-label="Recent payouts and payout facts">
            <div class="payout-strip__track">
                <?php for ($loop = 0; $loop < 2; $loop++): ?>
                    <?php foreach ($spPayouts as $p): ?>
                        <div class="payout-chip"<?= $loop ? ' aria-hidden="true"' : '' ?>>
                            <span class="payout-chip__dot"><?= icon('check', 'ico ico--sm') ?></span>
                            <strong><?= e($p['name']) ?></strong>
                            <span>was paid</span>
                            <b><?= e(money($p['amount'])) ?></b>
                            <span class="payout-chip__meta"><?= e($p['method']) ?> &middot; <?= e($p['ago']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php foreach ($spFacts as $f): ?>
                        <div class="payout-chip payout-chip--fact"<?= $loop ? ' aria-hidden="true"' : '' ?>>
                            <span class="payout-chip__dot"><?= icon($f['icon'], 'ico ico--sm') ?></span>
                            <span><?= $f['text'] ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endfor; ?>
            </div>
        </div>

        <div class="proof__grid proof__grid--single">
            <?php if ($spClip): ?>
            <article class="cotw">
                <div class="cotw__badge"><?= icon('award', 'ico ico--sm') ?> Clip of the week</div>
                <h3 class="cotw__title"><?= e($spClip['title']) ?></h3>
                <div class="cotw__by">by <strong><?= e($spClip['name']) ?></strong> &middot; <?= e($spClip['platform']) ?></div>
                <div class="cotw__stats">
                    <div><b><?= e(num($spClip['views'])) ?></b><span>views</span></div>
                    <div><b><?= e(money($spClip['earned'])) ?></b><span>earned</span></div>
                </div>
                <?php if ($spClip['url'] !== '#' && !hpl_link_blocked($spClip['url'])): ?>
                    <a class="btn btn--ghost" href="<?= e($spClip['url']) ?>" target="_blank" rel="noopener">Watch the clip &rarr;</a>
                <?php else: ?>
                    <span class="btn btn--ghost" aria-disabled="true">Watch the clip &rarr;</span>
                <?php endif; ?>
            </article>
            <?php endif; ?>

        </div>

        <div class="levels" id="levels">
            <div class="section__head">
                <h2>Grow your level</h2>
                <p>The more views your clips earn, the more you unlock.</p>
            </div>
            <div class="levels__grid">
                <?php foreach (hpl_levels() as $lv): ?>
                <div class="level-card level-card--<?= e($lv['key']) ?>">
                    <div class="level-card__icon"><?= $lv['icon'] ?></div>
                    <h3><?= e($lv['name']) ?></h3>
                    <div class="level-card__min"><?= $lv['min'] ? e(num($lv['min'])) . '+ views' : 'Start here' ?></div>
                    <ul>
                        <?php foreach ($lv['perks'] as $perk): ?><li><?= e($perk) ?></li><?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</section>

