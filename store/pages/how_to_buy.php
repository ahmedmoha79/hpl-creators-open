<?php
require STORE_ROOT . '/inc/layout.php';
$co = company();
$vids = videos(['section' => 'how_to_buy']);
page_head(t('how.title'), t('how.meta'), ['nav' => 'how-to-buy', 'head' => videos_jsonld($vids)]);
$recv = array_values(array_filter(array_map('trim', explode("\n", (string)cfg('co_receivers', '')))));
?>
<div class="phead"><div class="wrap"><h1><?= e(t('how.h1')) ?></h1><p><?= e(t('how.sub')) ?></p></div></div>
<section class="sec">
    <div class="wrap">
        <ol class="flow">
            <?php for ($i = 1; $i <= 4; $i++): ?><li><h3><?= e(t('flow.' . $i)) ?></h3><p><?= e(t('flow.' . $i . 'd')) ?></p></li><?php endfor; ?>
        </ol>
    </div>
</section>
<?php if ($vids): ?>
<section class="sec sec--dark">
    <div class="wrap">
        <div class="sec__head"><div><h2><?= e(t('how.videos_h')) ?></h2></div></div>
        <div class="reel"><?php foreach ($vids as $v) echo video_tile($v); ?></div>
    </div>
</section>
<?php else: ?><?= video_slot_hint('how_to_buy') ?><?php endif; ?>
<section class="sec">
    <div class="wrap split">
        <div>
            <h2><?= e(t('how.pay_h')) ?></h2>
            <ul class="paylist">
                <li><b><?= e(t('pay.showroom')) ?></b><span><?= e(t('how.pay_showroom')) ?></span></li>
                <li><b><?= e(t('pay.bank')) ?></b><span><?= e(t('how.pay_bank')) ?></span></li>
                <li><b><?= e(t('pay.wu')) ?> / <?= e(t('pay.mg')) ?></b><span><?= e(t('how.pay_wu')) ?></span></li>
                <li><b><?= e(t('pay.instal')) ?></b><span><?= e(t('how.pay_instal')) ?></span></li>
            </ul>
            <p style="margin-top:20px"><button class="btn btn--dark" type="button" data-chat-open data-chat-text="<?= e(t('how.ask_details_msg')) ?>"><?= e(t('order.request_details')) ?></button></p>
        </div>
        <div>
            <h2><?= e(t('how.deliv_h')) ?></h2>
            <ul class="ticks"><?php for ($i = 1; $i <= 4; $i++): ?><li><?= e(t('how.deliv_' . $i)) ?></li><?php endfor; ?></ul>
        </div>
    </div>
</section>
<section class="sec sec--paper">
    <div class="wrap split">
        <div>
            <h2><?= e(t('how.safe_h')) ?></h2>
            <ul class="never" style="grid-template-columns:1fr"><?php for ($i = 1; $i <= 3; $i++): ?><li><?= e(t('how.safe_' . $i)) ?></li><?php endfor; ?></ul>
        </div>
        <div>
            <h2><?= e(t('verify.receivers_h')) ?></h2>
            <?php if ($recv): ?><p class="muted"><?= e(t('verify.receivers_d')) ?></p><ul class="ticks"><?php foreach ($recv as $r): ?><li><strong><?= e($r) ?></strong></li><?php endforeach; ?></ul>
            <?php else: ?><p class="muted"><?= e(t('verify.receivers_none')) ?></p><?php endif; ?>
            <p style="margin-top:18px"><a href="<?= e(url('verify')) ?>"><?= e(t('foot.check')) ?></a></p>
        </div>
    </div>
</section>
<?php page_foot();
