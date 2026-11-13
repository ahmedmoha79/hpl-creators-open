<?php
require STORE_ROOT . '/inc/layout.php';
$co = company();
$docs = cfg('co_cert_image', '');
page_head(t('verify.h1'), t('verify.sub'), ['nav' => '']);
?>
<div class="phead"><div class="wrap"><h1><?= e(t('verify.h1')) ?></h1><p><?= e(t('verify.sub')) ?></p></div></div>
<section class="sec">
    <div class="wrap checks">
        <div class="check">
            <h2><?= e(t('verify.pacra_h')) ?></h2>
            <p><?= e(t('verify.pacra_d', ['pacra' => $co['pacra']])) ?><br><span class="bignum"><?= e($co['pacra']) ?></span> &nbsp;<?= e($co['legal']) ?></p>
            <div class="check__acts">
                <button class="btn btn--line btn--sm" type="button" data-copy="<?= e($co['pacra']) ?>" data-copied="<?= e(t('verify.copied')) ?>"><?= e(t('verify.copy')) ?></button>
                <a class="btn btn--dark btn--sm" href="<?= e($co['pacra_url']) ?>" target="_blank" rel="noopener"><?= e(t('verify.open_pacra')) ?></a>
            </div>
        </div>
        <div class="check">
            <h2><?= e(t('verify.tpin_h')) ?></h2>
            <p><?= e(t('verify.tpin_d', ['tpin' => $co['tpin']])) ?><br><span class="bignum"><?= e($co['tpin']) ?></span></p>
            <div class="check__acts"><button class="btn btn--line btn--sm" type="button" data-copy="<?= e($co['tpin']) ?>" data-copied="<?= e(t('verify.copied')) ?>"><?= e(t('verify.copy')) ?></button></div>
        </div>
        <div class="check">
            <h2><?= e(t('verify.brand_h')) ?></h2>
            <p><?= e(t('verify.brand_d')) ?></p>
            <div class="check__acts"><a class="btn btn--line btn--sm" href="https://www.noktadetectors.com/" target="_blank" rel="noopener">Nokta</a></div>
        </div>
        <?php $recv = array_values(array_filter(array_map('trim', explode("\n", (string)cfg('co_receivers', ''))))); ?>
        <div class="check" id="receivers">
            <h2><?= e(t('verify.receivers_h')) ?></h2>
            <p><?php if ($recv): ?><?= e(t('verify.receivers_d')) ?></p>
                <ul style="margin:6px 0 0;padding-left:20px;grid-column:1"><?php foreach ($recv as $r): ?><li><strong><?= e($r) ?></strong></li><?php endforeach; ?></ul>
            <?php else: ?><?= e(t('verify.receivers_none')) ?></p><?php endif; ?>
            <div class="check__acts"><button class="btn btn--dark btn--sm" type="button" data-chat-open><?= e(t('btn.ask')) ?></button></div>
        </div>
        <div class="check">
            <h2><?= e(t('verify.video_h')) ?></h2>
            <p><?= e(t('verify.video_d')) ?></p>
            <div class="check__acts"><button class="btn btn--dark btn--sm" type="button" data-chat-open data-chat-text="<?= e(t('cta.video_call_msg')) ?>"><?= e(t('btn.ask')) ?></button></div>
        </div>
        <div class="check">
            <h2><?= e(t('verify.social_h')) ?></h2>
            <p><?= e(t('verify.social_d')) ?></p>
            <div class="check__acts">
                <?php foreach (['tiktok' => 'TikTok', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'youtube' => 'YouTube'] as $k => $n): if (($co[$k] ?? '') === '') continue; ?>
                    <a class="btn btn--line btn--sm" href="<?= e($co[$k]) ?>" target="_blank" rel="noopener"><?= e($n) ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="check">
            <h2><?= e(t('verify.docs_h')) ?></h2>
            <p><?= e(t('verify.docs_d')) ?></p>
            <div class="check__acts">
                <?php if ($docs !== ''): ?><a class="btn btn--line btn--sm" href="<?= e(upload_url($docs)) ?>" target="_blank" rel="noopener"><?= e(t('verify.view_cert')) ?></a><?php endif; ?>
                <button class="btn btn--dark btn--sm" type="button" data-chat-open data-chat-text="<?= e(t('verify.docs_ask')) ?>"><?= e(t('btn.ask')) ?></button>
            </div>
        </div>
    </div>
</section>
<section class="sec sec--paper">
    <div class="wrap">
        <div class="sec__head"><h2><?= e(t('home.never_h')) ?></h2></div>
        <ul class="never"><?php for ($i = 1; $i <= 4; $i++): ?><li><?= e(t('never.' . $i)) ?></li><?php endfor; ?></ul>
    </div>
</section>
<?php page_foot();
