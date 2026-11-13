<?php
require STORE_ROOT . '/inc/layout.php';
$co = company();
page_head(t('contact.title'), t('contact.meta'), ['nav' => 'contact']);
?>
<div class="phead"><div class="wrap"><h1><?= e(t('contact.h1')) ?></h1><p><?= e(t('contact.sub')) ?></p></div></div>
<section class="sec">
    <div class="wrap split">
        <div class="shops" style="grid-template-columns:1fr">
            <?php foreach ($co['branches'] as $b): [$city, $addr] = array_pad(array_map('trim', explode(':', $b, 2)), 2, ''); ?>
                <div class="shop">
                    <h3><?= e($city) ?></h3>
                    <p><?= e($addr) ?><?= $co['hours'] !== '' ? '<br>' . e($co['hours']) : '' ?></p>
                    <div class="shop__acts"><a class="btn btn--line btn--sm" href="https://www.google.com/maps/search/?api=1&amp;query=<?= e(rawurlencode('HPL Gold Detectors ' . $addr . ' ' . $city . ' Zambia')) ?>" target="_blank" rel="noopener"><?= e(t('home.map')) ?></a></div>
                </div>
            <?php endforeach; ?>
        </div>
        <div>
            <h2><?= e(t('contact.reach')) ?></h2>
            <ul class="paylist">
                <?php if (wa_link() !== ''): ?><li><b>WhatsApp</b><span><a href="<?= e(wa_link(t('wa.hello'))) ?>" target="_blank" rel="noopener"><?= e($co['phone']) ?></a></span></li><?php endif; ?>
                <li><b><?= e(t('show.phone')) ?></b><span><a href="<?= e(tel_href($co['phone'])) ?>"><?= e($co['phone']) ?></a><?php if ($co['phone2'] !== ''): ?><br><a href="<?= e(tel_href($co['phone2'])) ?>"><?= e($co['phone2']) ?></a><?php endif; ?></span></li>
                <?php if ($co['email'] !== ''): ?><li><b>Email</b><span><a href="mailto:<?= e($co['email']) ?>"><?= e($co['email']) ?></a></span></li><?php endif; ?>
                <li><b><?= e(t('chat.title')) ?></b><span><button class="linkish" type="button" data-chat-open><?= e(t('chat.open')) ?></button></span></li>
                <?php if ($co['tiktok'] !== ''): ?><li><b>TikTok</b><span><a href="<?= e($co['tiktok']) ?>" target="_blank" rel="noopener">@hpldetectors.zambia</a></span></li><?php endif; ?>
                <?php if ($co['facebook'] !== ''): ?><li><b>Facebook</b><span><a href="<?= e($co['facebook']) ?>" target="_blank" rel="noopener">HPL Gold detectors Zambia</a></span></li><?php endif; ?>
            </ul>
        </div>
    </div>
</section>
<?php page_foot();
