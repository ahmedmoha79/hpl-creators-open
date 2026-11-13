<?php
require __DIR__ . '/_boot.php';
require_admin_page();
$open = (int)get('id');
$tpls = ['wu' => 'Western Union details', 'mg' => 'MoneyGram details', 'bank' => 'Bank (SWIFT) details', 'quote' => 'Delivery quote text'];
admin_head('Chats', 'chat.php');
?>
<div class="inbox" id="inbox" data-api="<?= e(url('admin/api.php')) ?>" data-open="<?= $open ?>">
    <aside class="inbox__list">
        <div class="inbox__tools">
            <input class="input" type="search" placeholder="Search name or country" data-filter aria-label="Search chats">
            <button class="btn btn--line btn--sm" type="button" data-notify>Turn on alerts</button>
        </div>
        <ul data-list></ul>
    </aside>
    <section class="inbox__conv" data-conv hidden>
        <header class="inbox__head">
            <button class="btn btn--line btn--sm inbox__back" type="button" data-back>Back</button>
            <div class="inbox__who"><b data-c-name></b><span data-c-meta></span></div>
            <div class="inbox__acts">
                <a class="btn btn--line btn--sm" data-c-wa target="_blank" rel="noopener" hidden>WhatsApp</a>
                <select class="input" data-c-status aria-label="Chat status" style="min-height:36px;width:auto">
                    <option value="open">Open</option><option value="closed">Done</option><option value="spam">Spam</option>
                </select>
            </div>
        </header>
        <div class="inbox__orders" data-c-orders></div>
        <div class="chat__body inbox__body" data-c-body></div>
        <div class="inbox__tpls">
            <?php foreach ($tpls as $k => $n): ?><button class="btn btn--line btn--sm" type="button" data-tpl="<?= $k ?>">Send <?= e($n) ?></button><?php endforeach; ?>
        </div>
        <form class="chat__foot" data-c-form>
            <label class="chat__icon" title="Send a photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="2" d="M4 7h3l2-2h6l2 2h3v12H4z"/><circle cx="12" cy="13" r="3.5" fill="none" stroke="currentColor" stroke-width="2"/></svg><input type="file" accept="image/*" data-c-photo hidden><span class="sr">Photo</span></label>
            <textarea class="chat__input" rows="1" placeholder="Reply (Enter sends, Shift+Enter new line)" data-c-input aria-label="Reply"></textarea>
            <button class="chat__icon chat__send" type="submit" aria-label="Send"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M3 20.5 21.5 12 3 3.5 3 10l13 2-13 2z"/></svg></button>
        </form>
    </section>
    <div class="inbox__empty" data-empty><p>Pick a chat on the left. New messages ring and show here instantly while this page is open.</p></div>
</div>
<?php admin_foot();
