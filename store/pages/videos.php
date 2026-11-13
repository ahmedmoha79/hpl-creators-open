<?php
require STORE_ROOT . '/inc/layout.php';
$names = video_sections();
unset($names['home']);
$groups = [];
$all = [];
foreach ($names as $k => $n) {
    $l = videos(['section' => $k]);
    if ($l) { $groups[$k] = $l; $all = array_merge($all, $l); }
}
$uniq = [];
foreach ($all as $v) $uniq[$v['id']] = $v;
page_head(t('videos.title'), t('videos.meta'), ['nav' => 'videos', 'head' => videos_jsonld(array_values($uniq))]);
?>
<div class="phead"><div class="wrap"><h1><?= e(t('videos.h1')) ?></h1><p><?= e(t('videos.sub')) ?></p>
<?php if ($groups): ?><div class="chips" style="margin:22px 0 0"><?php foreach ($groups as $k => $l): ?><a class="chip" style="background:transparent;color:#E3DED4;border-color:rgba(255,255,255,.2)" href="#v-<?= e($k) ?>"><?= e($names[$k]) ?> (<?= count($l) ?>)</a><?php endforeach; ?></div><?php endif; ?>
</div></div>
<?php if (!$groups): ?>
    <section class="sec"><div class="wrap"><?= video_slot_hint('field') ?><div class="empty"><?= e(t('videos.none')) ?></div></div></section>
<?php endif; ?>
<?php $i = 0; foreach ($groups as $k => $l): ?>
<section class="sec<?= $i++ % 2 ? ' sec--paper' : '' ?>" id="v-<?= e($k) ?>" style="padding:clamp(40px,6vw,64px) 0">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e($names[$k]) ?></h2><?php if (t('vsub.' . $k) !== 'vsub.' . $k): ?><p><?= e(t('vsub.' . $k)) ?></p><?php endif; ?></div></div>
        <div class="vgrid"><?php foreach ($l as $v) echo video_tile($v); ?></div>
    </div>
</section>
<?php endforeach; ?>
<?php page_foot();
