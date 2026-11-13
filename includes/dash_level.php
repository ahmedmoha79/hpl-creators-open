<?php
/* Creator dashboard: level card. Expects $stats from creator_stats(). Add ?demo=1 to preview with sample views. */
require_once __DIR__ . '/social_proof.php';
$lvViews = sp_demo() ? 68400 : (int)($stats['views'] ?? 0);
$lv = hpl_level_for($lvViews);
?>
<div class="panel level-panel level-panel--<?= e($lv['current']['key']) ?>">
    <div class="panel__body">
        <div class="level-panel__grid">
            <div class="level-panel__badge">
                <span class="level-panel__icon"><?= $lv['current']['icon'] ?></span>
                <div>
                    <small>Your level<?= sp_demo() ? ' &middot; demo' : '' ?></small>
                    <strong><?= e($lv['current']['name']) ?></strong>
                </div>
            </div>
            <div class="level-panel__progress">
                <div class="level-panel__row">
                    <span><b><?= e(num($lvViews)) ?></b> total views</span>
                    <?php if ($lv['next']): ?>
                        <span><?= e(num($lv['to_go'])) ?> to <b><?= e($lv['next']['name']) ?></b></span>
                    <?php else: ?>
                        <span>Top level reached</span>
                    <?php endif; ?>
                </div>
                <div class="level-bar"><i style="width:<?= (int)$lv['pct'] ?>%"></i></div>
                <?php if ($lv['next']): ?>
                    <p class="small muted">Next unlock: <?= e(implode(' · ', $lv['next']['perks'])) ?></p>
                <?php endif; ?>
            </div>
            <ul class="level-panel__perks">
                <?php foreach ($lv['current']['perks'] as $perk): ?><li>&#10003; <?= e($perk) ?></li><?php endforeach; ?>
            </ul>
        </div>
    </div>
</div>
