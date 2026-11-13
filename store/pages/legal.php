<?php
require STORE_ROOT . '/inc/layout.php';
$which = $first; // terms | privacy | delivery
$co = company();
$key = ['terms' => 'legal.terms', 'privacy' => 'legal.privacy', 'delivery' => 'legal.delivery'][$which];
$custom = tr(cfg('page_' . $which, ''));
page_head(t($key), '', ['noindex' => $which !== 'delivery']);
?>
<div class="phead"><div class="wrap"><h1><?= e(t($key)) ?></h1><p><?= e($co['legal']) ?>, PACRA <?= e($co['pacra']) ?>, TPIN <?= e($co['tpin']) ?>, <?= e($co['city']) ?></p></div></div>
<section class="sec"><div class="wrap prose">
<?php if ($custom !== ''): foreach (preg_split('~\n\s*\n~', $custom) as $para): ?><p><?= nl2br(e(trim($para))) ?></p><?php endforeach;
else: for ($i = 1; $i <= 8; $i++): $s = t($which . '.' . $i); if ($s === $which . '.' . $i) break; ?><p><?= e($s) ?></p><?php endfor; endif; ?>
</div></section>
<?php page_foot();
