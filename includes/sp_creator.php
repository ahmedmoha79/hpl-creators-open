<?php
/**
 * Creator dashboard: "Your social pages" list + add form.
 * Expects $mySocials (rows of social_pages for this creator), $ttOn, $platformIcon.
 */
require_once __DIR__ . '/social_pages.php';
$spOrder = ['pending' => 0, 'rejected' => 1, 'approved' => 2];
usort($mySocials, function ($a, $b) use ($spOrder) {
    return ($spOrder[$a['status']] ?? 3) <=> ($spOrder[$b['status']] ?? 3) ?: strcmp((string)$b['submitted_at'], (string)$a['submitted_at']);
});
$spPending = count(array_filter($mySocials, function ($s) { return $s['status'] === 'pending'; }));
$spPost = e(u('creator/submit_social.php'));
// Only platforms open now (2026-11-07: TikTok first). With TikTok sign-in switched on, connecting
// TikTok verifies the page, so the code-in-bio form is not shown at all.
$spPlats = array_filter(sp_platforms(), 'platform_is_live', ARRAY_FILTER_USE_KEY) ?: ['tiktok' => sp_platforms()['tiktok']];
$spShowForm = !(!empty($ttOn) && array_keys($spPlats) === ['tiktok']);
$spDefault = (string)array_key_first($spPlats);
foreach ($mySocials as $s0) {
    if ($s0['status'] !== 'approved' && array_key_exists((string)$s0['platform'], $spPlats)) { $spDefault = (string)$s0['platform']; break; }
}
?>
<link rel="stylesheet" href="<?= e(u('assets/css/sp.css?v=20261108a')) ?>">
<?php if (!empty($_SESSION['sp_msg'])): $spMsg = $_SESSION['sp_msg']; unset($_SESSION['sp_msg']); ?>
    <div class="flash flash--<?= e($spMsg['type']) ?> sp-flash" role="status"><?= e($spMsg['msg']) ?></div>
<?php endif; ?>
<?php if ($mySocials): ?>
    <ul class="sp-list">
        <?php foreach ($mySocials as $s):
            $code = hpl_social_code($s);
            $label = sp_label($s);
            $note = (string)$s['admin_note'];
            $how = strpos($note, 'signed in with TikTok') !== false ? 'Verified by TikTok sign-in'
                : (strpos($note, 'Verified automatically') === 0 ? 'Verified automatically' : 'Verified by the HPL team');
            $when = $s['status'] === 'pending' ? 'Added ' . time_ago($s['submitted_at']) : ($s['reviewed_at'] ? time_ago($s['reviewed_at']) : time_ago($s['submitted_at']));
        ?>
            <li class="sp-item sp-item--<?= e($s['status']) ?>">
                <div class="sp-item__head">
                    <?= $platformIcon((string)$s['platform']) ?>
                    <div class="sp-item__who">
                        <?= safe_link((string)$s['url'], $label) ?>
                        <span><?= e(platform_label((string)$s['platform'])) ?> &middot; <?= e($when) ?></span>
                    </div>
                    <?php if ($s['status'] === 'approved'): ?>
                        <span class="sp-pill sp-pill--ok"><?= icon('check') ?>Verified</span>
                    <?php elseif ($s['platform'] === 'tiktok' && !empty($ttOn)): ?>
                        <span class="sp-pill sp-pill--wait">Not connected</span>
                    <?php elseif ($s['status'] === 'pending'): ?>
                        <span class="sp-pill sp-pill--wait">Waiting</span>
                    <?php else: ?>
                        <span class="sp-pill sp-pill--bad">Not approved</span>
                    <?php endif; ?>
                </div>

                <?php if ($s['status'] !== 'approved' && $s['platform'] === 'tiktok' && !empty($ttOn)): ?>
                    <?php // TikTok sign-in proves the account is yours, so TikTok pages never need a code in the bio. ?>
                    <?php if ($s['status'] === 'rejected' && $note !== '' && $note !== 'Rejected'): ?><div class="sp-reason"><strong>Why:</strong> <?= e($note) ?></div><?php endif; ?>
                    <p class="sp-hint">Connect this account (switch to it in TikTok first).</p>
                    <div class="sp-actions">
                        <a class="btn btn--tiktok btn--sm" href="<?= e(u('creator/connect_tiktok.php' . (!empty($ttStatus['accounts']) ? '?another=1' : ''))) ?>"><?= icon('tiktok') ?>Connect TikTok</a>
                        <form method="post" action="<?= $spPost ?>" data-confirm="Remove <?= e($label) ?> from this list?">
                            <?= csrf_field() ?><input type="hidden" name="action" value="<?= $s['status'] === 'pending' ? 'cancel' : 'remove' ?>"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn--ghost btn--sm" type="submit">Remove</button>
                        </form>
                    </div>
                <?php elseif ($s['status'] === 'pending'): ?>
                    <ol class="sp-steps">
                        <li><span>Copy your code</span>
                            <button type="button" class="sp-code" data-copy="<?= e($code) ?>" aria-label="Copy code <?= e($code) ?>"><?= icon('copy') ?><?= e($code) ?></button></li>
                        <li><span>Paste it anywhere in your <?= e(platform_label((string)$s['platform'])) ?> bio and save</span></li>
                        <li><span>Tap <strong>Check now</strong></span></li>
                    </ol>
                    <?php if ($s['platform'] === 'instagram'): ?>
                        <p class="sp-hint">Instagram must be a <strong>Professional</strong> account (free: Settings, then "Account type and tools"). We need it to read your bio and count your Reel views.</p>
                    <?php elseif (!in_array($s['platform'], ['tiktok', 'youtube'], true)): ?>
                        <p class="sp-hint"><?= e(platform_label((string)$s['platform'])) ?> does not let us read bios automatically, so the HPL team checks it by hand, usually within 24 hours.</p>
                    <?php endif; ?>
                    <div class="sp-actions">
                        <form method="post" action="<?= $spPost ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="check"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn--primary btn--sm" type="submit" data-busy="Checking…">Check now</button>
                        </form>
                        <form method="post" action="<?= $spPost ?>" data-confirm="Cancel verification for <?= e($label) ?>? You can add it again later.">
                            <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn--ghost btn--sm" type="submit">Cancel</button>
                        </form>
                    </div>
                <?php elseif ($s['status'] === 'rejected'): ?>
                    <div class="sp-reason">
                        <strong>Why:</strong> <?= e($note !== '' && $note !== 'Rejected' ? $note : 'The HPL team could not confirm this page is yours.') ?>
                    </div>
                    <div class="sp-actions">
                        <form method="post" action="<?= $spPost ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn--primary btn--sm" type="submit" data-busy="Sending…">Try again</button>
                        </form>
                        <form method="post" action="<?= $spPost ?>" data-confirm="Remove <?= e($label) ?> from your list?">
                            <?= csrf_field() ?><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                            <button class="btn btn--ghost btn--sm" type="submit">Remove</button>
                        </form>
                    </div>
                <?php else: ?>
                    <?php
                    // TikTok pages: say whether this exact account is connected, because its views are read through it.
                    $ttLine = '';
                    if ($s['platform'] === 'tiktok' && function_exists('tiktok_configured') && tiktok_configured() && isset($ttStatus)) {
                        $pageHandle = hpl_handle_from_url('tiktok', (string)$s['url']);
                        $pageState = 'none';
                        foreach ($ttStatus['accounts'] as $tx) {
                            if ($pageHandle !== null && strtolower((string)$tx['row']['username']) === $pageHandle) {
                                $pageState = $tx['problem'] === null ? 'working' : 'broken';
                                if ($pageState === 'working') { break; }
                            }
                        }
                        $ttLine = $pageState === 'working' ? 'Views of clips on this page are read automatically.'
                            : ($pageState === 'broken' ? 'Reconnect it above so its views count.'
                            : 'Connect it above so its views count.');
                    }
                    ?>
                    <p class="sp-hint sp-hint--ok"><?= e($how) ?>. <?= $ttLine !== '' ? e($ttLine) : 'Clips from this page can earn.' ?></p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if ($spShowForm): ?>
<div class="sp-add">
    <h3><?= $mySocials ? 'Add another page' : 'Add your page' ?></h3>
    <?php if ($spPending >= SP_MAX_PENDING): ?>
        <p class="sp-hint">You have <?= SP_MAX_PENDING ?> pages waiting. Finish or cancel one before adding more.</p>
    <?php else: ?>
        <form method="post" action="<?= $spPost ?>" class="sp-form">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="add">
            <div class="sp-platforms" role="radiogroup" aria-label="Platform">
                <?php foreach ($spPlats as $key => [$name, $example]): ?>
                    <label class="sp-plat">
                        <input type="radio" name="platform" value="<?= e($key) ?>" data-example="<?= e($example) ?>" <?= $key === $spDefault ? 'checked' : '' ?> required>
                        <span><?= e($name) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
            <label class="label" for="sp_url">Profile link</label>
            <div class="sp-row">
                <input class="input" type="text" inputmode="url" autocapitalize="off" spellcheck="false" id="sp_url" name="url" placeholder="<?= e($spPlats[$spDefault][1]) ?>" autocomplete="off" required>
                <button class="btn btn--primary" type="submit" data-busy="Adding…"><?= icon('plus') ?>Add</button>
            </div>
            <p class="sp-hint">On your profile, tap Share, then Copy link, and paste it here.</p>
        </form>
    <?php endif; ?>
</div>

<details class="ui-details" id="spHelp">
    <summary><?= icon('info') ?>How does verification work?<?= icon('chevron-down') ?></summary>
    <div class="ui-details__body">
        <?php
        $spHelp = [
            'tiktok'    => ['https://www.tiktok.com/@yourname', 'bio', 'TikTok is checked on the spot when you tap Check now.'],
            'instagram' => ['https://www.instagram.com/yourname', 'bio', 'Your Instagram must be a Professional account (free: Instagram settings, then "Account type and tools", then "Switch to professional account"). It is checked on the spot through Instagram when you tap Check now, and it is also how we count your Reel views.'],
            'facebook'  => ['https://www.facebook.com/yourpage', 'Page intro (About)', 'Facebook does not let websites read pages, so the HPL team checks it by hand, usually within 24 hours.'],
            'youtube'   => ['https://www.youtube.com/@yourchannel', 'channel description', 'YouTube is checked on the spot when you tap Check now.'],
            'twitter'   => ['https://x.com/yourname', 'bio', 'X does not let websites read profiles, so the HPL team checks it by hand, usually within 24 hours.'],
        ];
        ?>
        <?php foreach ($spHelp as $pk => [$example, $where, $note]): if (!isset($spPlats[$pk])) { continue; } $pname = $spPlats[$pk][0]; ?>
            <ol data-sp-help="<?= e($pk) ?>" <?= $pk === $spDefault ? '' : 'hidden' ?>>
                <?php if ($pk === 'tiktok' && $ttOn): ?><li><strong>Fastest:</strong> tap "Connect TikTok" above and sign in. TikTok confirms the account is yours, so it is verified straight away. No code needed.</li><?php endif; ?>
                <li>Open your <?= e($pname) ?> profile, tap Share, then Copy link. It looks like <span class="ui-code"><?= e($example) ?></span>. Paste it above and tap Add.</li>
                <li>You get a personal code like <span class="ui-code">HPL-7K3QF2</span>. Put it anywhere in your <?= e($where) ?> and save.</li>
                <li><?= e($note) ?></li>
                <li>Changed your mind? Tap <strong>Cancel</strong> while it is waiting. If it is not approved you will see why, and can tap <strong>Try again</strong> with the same code.</li>
                <li>After approval you can remove the code. A page can belong to only one HPL account.</li>
            </ol>
        <?php endforeach; ?>
    </div>
</details>
<script>
(function () {
    var form = document.querySelector('.sp-form');
    if (!form) return;
    var url = form.querySelector('#sp_url');
    function show(p) {
        document.querySelectorAll('[data-sp-help]').forEach(function (ol) { ol.hidden = ol.getAttribute('data-sp-help') !== p; });
    }
    form.addEventListener('change', function (e) {
        if (e.target.name === 'platform') { url.placeholder = e.target.getAttribute('data-example'); show(e.target.value); }
    });
})();
</script>
<?php endif; ?>
