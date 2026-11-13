<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/home_hooks.php';
$user = require_creator();
$pageTitle = 'Profile';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'clip_path') {
        // Ready clips or own edits (asked once on Home; changeable on Profile). Editors see the Academy and the work video step.
        $p = post('path') === 'edit' ? 'edit' : 'ready';
        q('UPDATE users SET clip_path = ? WHERE id = ?', [$p, (int)$user['id']]);
        flash('success', $p === 'edit' ? 'Saved. The Creator Academy shows you how to cut clips from raw footage.' : 'Saved. Next: pass the short test, connect your TikTok and take your first clip.');
        redirect(post('back') === 'home' ? 'creator/index.php' : 'creator/profile.php');
    } elseif ($action === 'town') {
        require_once __DIR__ . '/../includes/towns.php';
        $town = zm_town_clean(post('town'));
        if ($town === '') {
            flash('error', 'Choose your town from the list.');
        } else {
            q('UPDATE users SET town = ? WHERE id = ?', [$town, (int)$user['id']]);
            flash('success', 'Saved. Thank you.');
        }
        redirect(post('back') === 'home' ? 'creator/index.php' : 'creator/profile.php');
    } elseif ($action === 'details') {
        $name = post('full_name');
        $phone = post('phone');
        $country = mb_substr(post('country'), 0, 80);
        $social = mb_substr(post('social_handle'), 0, 190);
        if (mb_strlen($name) < 2 || strlen(preg_replace('/\D/', '', $phone)) < 7) {
            flash('error', 'Please provide a valid name and phone number.');
        } elseif (!name_is_ok($name)) {
            flash('error', 'Please enter your real name only, with no web links.');
        } else {
            require_once __DIR__ . '/../includes/towns.php';
            $town = zm_town_clean(post('town'));
            q('UPDATE users SET full_name = ?, phone = ?, country = ?, social_handle = ?, town = COALESCE(?, town) WHERE id = ?', [$name, $phone, $country, $social, $town !== '' ? $town : null, (int)$user['id']]);
            flash('success', 'Profile updated.');
        }
    } elseif ($action === 'password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');
        if (!password_verify($current, $user['password'])) {
            flash('error', 'Your current password is incorrect.');
        } elseif (strlen($new) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($new !== $confirm) {
            flash('error', 'New passwords do not match.');
        } else {
            $newHash = password_hash($new, PASSWORD_DEFAULT);
            q('UPDATE users SET password = ? WHERE id = ?', [$newHash, (int)$user['id']]);
            session_keep_after_password_change((int)$user['id'], $newHash);
            flash('success', 'Password changed. Other phones and computers signed in to your account are now signed out.');
        }
    }
    redirect('creator/profile.php');
}

require __DIR__ . '/../includes/creator_header.php';
?>

<?php
require_once __DIR__ . '/../includes/social_proof.php';
$pStats = creator_stats((int)$user['id']);
$pLevel = hpl_level_for((int)$pStats['views']);
$pAvatar = (string)($user['avatar_url'] ?? '');
$pLinks = [
    ['Creator Academy', 'Short lessons on what gets views', 'graduation', 'creator/course.php'],
    ['Rewards', 'Badges and bonus codes', 'award', 'creator/rewards.php'],
    ['Messages', 'Talk to the HPL team', 'chat', 'creator/messages.php'],
    ['Announcements', 'News from HPL', 'megaphone', 'creator/index.php?tab=announcements'],
    ['Help', 'Answers to common questions', 'support', 'creator/support.php'],
    ['Send feedback', 'Tell us what to improve', 'mail', 'creator/feedback.php'],
];
?>
<link rel="stylesheet" href="<?= e(u('assets/css/home.css?v=20261113a')) ?>">
<section class="pf-hero">
    <button type="button" class="pf-avbtn" id="pfAvBtn" aria-label="Change your profile picture">
        <?php if ($pAvatar !== ''): ?>
            <img class="pf-hero__av" id="pfAv" src="<?= e($pAvatar) ?>" alt="" referrerpolicy="no-referrer">
        <?php else: ?>
            <span class="pf-hero__av pf-hero__av--initial" id="pfAv" aria-hidden="true"><?= e(strtoupper(mb_substr($user['full_name'], 0, 1))) ?></span>
        <?php endif; ?>
        <span class="pf-avbtn__cam"><?= icon('image') ?></span>
    </button>
    <input type="file" id="pfAvFile" accept="image/jpeg,image/png,image/webp" hidden>
    <div class="pf-hero__txt">
        <h1><?= e($user['full_name']) ?></h1>
        <span class="pf-level pf-level--<?= e($pLevel['current']['key']) ?>"><?= icon('award') ?><?= e($pLevel['current']['name']) ?></span>
    </div>
    <dl class="pf-hero__stats">
        <div><dt>Earned</dt><dd><?= money($pStats['earned']) ?></dd></div>
        <div><dt>Views</dt><dd><?= e(short_count((int)$pStats['views'])) ?></dd></div>
        <div><dt>Clips</dt><dd><?= e(num((int)$pStats['links'] + (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND status <> "rejected"', [(int)$user['id']], 0))) ?></dd></div>
    </dl>
</section>

<?php require_once __DIR__ . '/../includes/page_score.php'; echo page_score_card($user); ?>

<?php require __DIR__ . '/../includes/social_section.php'; ?>

<nav class="pf-links" aria-label="More">
    <?php foreach ($pLinks as $l): ?>
        <a class="pf-link" href="<?= e(u($l[3])) ?>"><span class="pf-link__ico"><?= icon($l[2]) ?></span><span class="pf-link__txt"><strong><?= e($l[0]) ?></strong><small><?= e($l[1]) ?></small></span><?= icon('chevron-right') ?></a>
    <?php endforeach; ?>
    <a class="pf-link hide-in-app" href="<?= e(u('app.php')) ?>"><span class="pf-link__ico"><?= icon('download') ?></span><span class="pf-link__txt"><strong>Put the app on your phone</strong><small>Opens with one tap from your home screen</small></span><?= icon('chevron-right') ?></a>
</nav>

<section class="pf-theme pf-photo" aria-label="Profile picture">
    <div class="pf-theme__txt"><strong>Profile picture</strong><small id="pfAvNote">Tap your picture to change it.</small></div>
    <div class="pf-theme__seg"><button type="button" id="pfAvPick">Choose photo</button><?php if ($pAvatar !== ''): ?><button type="button" id="pfAvDel">Remove</button><?php endif; ?></div>
</section>
<section class="pf-theme" data-push-row data-app="<?= e(u('app.php')) ?>" aria-label="Notifications on your phone">
    <div class="pf-theme__txt"><strong>Notifications on your phone</strong><small>Get new clips, payments and messages on your phone, even when HPL is closed.</small></div>
    <div class="pf-theme__seg"><button type="button">Turn on</button></div>
</section>
<script>
(function () {
    var file = document.getElementById('pfAvFile'), note = document.getElementById('pfAvNote');
    var csrf = <?= json_encode(csrf_token()) ?>, url = <?= json_encode(u('creator/avatar.php')) ?>;
    function pick() { file.click(); }
    ['pfAvBtn', 'pfAvPick'].forEach(function (id) { var b = document.getElementById(id); if (b) b.addEventListener('click', pick); });
    function say(t, bad) { note.textContent = t; note.style.color = bad ? 'var(--ui-bad, #b3261e)' : ''; }
    // Phones take 5-10 MB photos: make it a small square here first, so it uploads in a second on slow data.
    function shrink(f) {
        return new Promise(function (res) {
            var img = new Image(), u = URL.createObjectURL(f);
            img.onload = function () {
                var s = Math.min(img.naturalWidth, img.naturalHeight), c = document.createElement('canvas');
                c.width = c.height = 640;
                c.getContext('2d').drawImage(img, (img.naturalWidth - s) / 2, (img.naturalHeight - s) / 2, s, s, 0, 0, 640, 640);
                URL.revokeObjectURL(u);
                c.toBlob(function (b) { res(b || f); }, 'image/jpeg', 0.88);
            };
            img.onerror = function () { URL.revokeObjectURL(u); res(f); };
            img.src = u;
        });
    }
    function send(fd) {
        return fetch(url, { method: 'POST', credentials: 'same-origin', body: fd }).then(function (r) { return r.json(); });
    }
    file.addEventListener('change', function () {
        var f = file.files && file.files[0];
        if (!f) return;
        say('Saving your picture...');
        shrink(f).then(function (blob) {
            var fd = new FormData(); fd.append('csrf', csrf); fd.append('photo', blob, 'me.jpg');
            return send(fd);
        }).then(function (d) {
            if (!d.ok) { say(d.error || 'Could not save the picture.', true); return; }
            location.reload();
        }).catch(function () { say('No connection. Try again.', true); });
        file.value = '';
    });
    var del = document.getElementById('pfAvDel');
    if (del) del.addEventListener('click', function () {
        var fd = new FormData(); fd.append('csrf', csrf); fd.append('remove', '1');
        send(fd).then(function () { location.reload(); });
    });
})();
</script>

<section class="pf-theme" aria-labelledby="pfThemeT">
    <div class="pf-theme__txt"><strong id="pfThemeT">Look</strong><small>Auto follows your phone's dark mode setting.</small></div>
    <div class="pf-theme__seg" role="radiogroup" aria-labelledby="pfThemeT">
        <button type="button" role="radio" data-theme-set="auto">Auto</button>
        <button type="button" role="radio" data-theme-set="light">Light</button>
        <button type="button" role="radio" data-theme-set="dark">Dark</button>
    </div>
</section>
<script>
(function () {
    var btns = document.querySelectorAll('[data-theme-set]');
    function mark() { var v = window.hplTheme ? window.hplTheme.get() : 'auto'; btns.forEach(function (b) { b.setAttribute('aria-checked', b.getAttribute('data-theme-set') === v ? 'true' : 'false'); }); }
    btns.forEach(function (b) { b.addEventListener('click', function () { if (window.hplTheme) window.hplTheme.set(b.getAttribute('data-theme-set')); mark(); }); });
    mark();
})();
</script>

<details class="pf-more" id="account">
    <summary><?= icon('settings') ?>Account details and password</summary>


    <form class="hk-path" method="post" style="margin-bottom:16px">
        <?= csrf_field() ?><input type="hidden" name="action" value="clip_path">
        <strong>How I clip: <?= ($user['clip_path'] ?? '') === 'edit' ? 'I edit my own clips' : (($user['clip_path'] ?? '') === 'ready' ? 'I post ready clips' : 'not chosen yet') ?></strong>
        <div style="display:flex;gap:8px;flex-wrap:wrap"><button class="btn btn--ghost btn--sm" name="path" value="ready" type="submit">Post ready clips</button><button class="btn btn--ghost btn--sm" name="path" value="edit" type="submit">Edit my own clips</button></div>
        <small class="hk-path__note">Ready clips: no editing. Editing your own: you get raw footage, the Creator Academy, and send one work video first.</small>
    </form>
<div class="grid-2">
    <div class="panel">
        <div class="panel__head"><h3>Account details</h3></div>
        <div class="panel__body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="details">
                <div class="field">
                    <label class="label" for="full_name">Full name</label>
                    <input class="input" type="text" id="full_name" name="full_name" value="<?= e($user['full_name']) ?>" required>
                </div>
                <div class="field">
                    <label class="label">Email</label>
                    <input class="input" type="email" value="<?= e($user['email']) ?>" disabled>
                    <div class="field__hint">Contact support to change your email.</div>
                </div>
                <div class="field">
                    <label class="label" for="phone">Phone number</label>
                    <input class="input" type="tel" id="phone" name="phone" value="<?= e($user['phone']) ?>" required>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="country">Country</label>
                        <input class="input" type="text" id="country" name="country" value="<?= e($user['country']) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="town">Town</label>
                        <?php require_once __DIR__ . '/../includes/towns.php'; ?>
                        <select class="input" id="town" name="town"><?= zm_town_options((string)($user['town'] ?? '')) ?></select>
                    </div>
                    <div class="field">
                        <label class="label" for="social_handle">Social handle</label>
                        <input class="input" type="text" id="social_handle" name="social_handle" value="<?= e($user['social_handle']) ?>">
                    </div>
                </div>
                <button class="btn btn--primary" type="submit">Save changes</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Change password</h3></div>
        <div class="panel__body">
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="password">
                <div class="field">
                    <label class="label" for="current_password">Current password</label>
                    <input class="input" type="password" id="current_password" name="current_password" required>
                </div>
                <div class="field">
                    <label class="label" for="new_password">New password</label>
                    <input class="input" type="password" id="new_password" name="new_password" required>
                </div>
                <div class="field">
                    <label class="label" for="confirm_password">Confirm new password</label>
                    <input class="input" type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button class="btn btn--primary" type="submit">Update password</button>
            </form>
        </div>
    </div>
</div>

</details>

<a class="pf-signout" href="<?= e(u('logout.php')) ?>"><?= icon('logout') ?>Sign out</a>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
