<?php
if (!function_exists('page_head')) require STORE_ROOT . '/inc/layout.php';
page_head(t('nf.h1'), '', ['noindex' => true]);
?>
<div class="phead"><div class="wrap"><h1><?= e(t('nf.h1')) ?></h1><p><?= e(t('nf.d')) ?></p>
<p style="margin-top:18px;display:flex;gap:10px;flex-wrap:wrap"><a class="btn btn--gold" href="<?= e(url('shop')) ?>"><?= e(t('shop.h1')) ?></a><button class="btn btn--ghost" type="button" data-chat-open><?= e(t('chat.open')) ?></button></p></div></div>
<?php page_foot();
