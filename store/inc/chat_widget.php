<?php
/** The chat bubble and window (markup only; behaviour in assets/js/store.js). */
$cwCo = company();
?>
<button class="chatfab" type="button" data-chat-open aria-label="<?= e(t('chat.open')) ?>">
    <span class="chatfab__label"><span class="chatfab__live" aria-hidden="true"></span><?= e(t('chat.fab')) ?></span>
    <svg viewBox="0 0 32 32" aria-hidden="true"><path fill="currentColor" d="M6 6h20a3 3 0 0 1 3 3v11a3 3 0 0 1-3 3H14l-6 5v-5H6a3 3 0 0 1-3-3V9a3 3 0 0 1 3-3Zm4 7.5a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm6 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Zm6 0a1.8 1.8 0 1 0 0 3.6 1.8 1.8 0 0 0 0-3.6Z"/></svg>
    <span class="chatfab__dot" data-chat-badge hidden>0</span>
    <span class="chatfab__hint" data-chat-hint hidden><?= e(t('chat.open')) ?></span>
</button>
<section class="chat" id="hplchat" hidden aria-label="<?= e(t('chat.title')) ?>"
    data-api="<?= e(url('api/chat.php')) ?>" data-csrf="<?= e(csrf()) ?>" data-lang="<?= e(lang()) ?>"
    data-t-sent-fail="<?= e(t('chat.err')) ?>" data-t-photo-fail="<?= e(t('chat.photo_err')) ?>"
    data-t-online="<?= e(t('chat.online')) ?>" data-t-away="<?= e(t('chat.away')) ?>" data-t-typing="<?= e(t('chat.typing')) ?>"
    data-t-today="<?= e(t('chat.today')) ?>" data-t-new="<?= e(t('chat.new')) ?>">
    <header class="chat__head">
        <span class="chat__avatar"><img src="<?= e(asset('img/favicon.svg')) ?>" alt="" width="40" height="40"></span>
        <span class="chat__who"><b><?= e(t('chat.title')) ?></b><span data-chat-status><?= e(t('chat.away')) ?></span></span>
        <button class="chat__x" type="button" data-chat-close aria-label="<?= e(t('chat.close')) ?>">&times;</button>
    </header>
    <form class="chat__intro" data-chat-intro hidden>
        <h3><?= e(t('chat.intro_h')) ?></h3>
        <p><?= e(t('chat.intro_d')) ?></p>
        <input class="input" name="name" autocomplete="name" placeholder="<?= e(t('chat.your_name')) ?>" required maxlength="80">
        <input class="input" name="phone" autocomplete="tel" inputmode="tel" placeholder="<?= e(t('chat.your_phone')) ?>" maxlength="30">
        <select class="input" name="country" aria-label="<?= e(t('chat.your_country')) ?>">
            <option value=""><?= e(t('chat.your_country')) ?></option>
            <?php foreach (countries() as $c): ?><option><?= e($c) ?></option><?php endforeach; ?>
        </select>
        <button class="btn btn--gold btn--block" type="submit"><?= e(t('chat.start')) ?></button>
    </form>
    <div class="chat__body" data-chat-body aria-live="polite">
        <div class="chat__safe"><?= e(t('chat.safe')) ?></div>
    </div>
    <form class="chat__foot" data-chat-form>
        <label class="chat__icon" title="<?= e(t('chat.photo')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M4 7h3l2-2h6l2 2h3v12H4z"/><circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            <span class="sr"><?= e(t('chat.photo')) ?></span>
            <input type="file" accept="image/jpeg,image/png,image/webp" data-chat-photo hidden>
        </label>
        <textarea class="chat__input" rows="1" maxlength="2000" placeholder="<?= e(t('chat.placeholder')) ?>" aria-label="<?= e(t('chat.placeholder')) ?>" data-chat-input></textarea>
        <button class="chat__icon chat__send" type="submit" aria-label="<?= e(t('chat.send')) ?>">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 20.5 21.5 12 3 3.5 3 10l13 2-13 2z"/></svg>
        </button>
    </form>
    <?php if (wa_link() !== ''): ?><div class="chat__wa"><?= e(t('chat.wa_alt')) ?> <a href="<?= e(wa_link(t('wa.hello'))) ?>" target="_blank" rel="noopener">WhatsApp</a></div><?php endif; ?>
</section>
