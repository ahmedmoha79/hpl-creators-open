<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Settings';

$keys = [
    'rate_per_1000', 'earnings_mode', 'only_new_videos', 'min_payout', 'max_payout', 'max_daily_links',
    'currency_code', 'currency_symbol', 'autosync_minutes',
    'tiktok_client_key', 'tiktok_client_secret', 'tiktok_scopes',
    'promo_video_url', 'promo_embed_code', 'landing_headline', 'landing_subheadline',
    'payout_contact_email', 'support_phone', 'blocked_link_words', 'signup_bonus_amount', 'campaign_creator_cap', 'general_clips_enabled',
    'youtube_api_key', 'facebook_access_token', 'instagram_access_token', 'tiktok_access_token',
    'google_client_id', 'google_client_secret',
    'meta_app_id', 'meta_app_secret', 'meta_access_token', 'meta_ig_user_id', 'meta_graph_version', 'meta_media_pages',
    'mail_from_email', 'mail_from_name',
    'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption',
    'imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_encryption',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (post('action') === 'regenerate_key') {
        set_setting('cron_key', bin2hex(random_bytes(16)));
        flash('success', 'New cron key generated.');
        redirect('admin/settings.php');
    }
    if (post('action') === 'maintenance') {
        // Put the creator site into downtime for a chosen number of minutes, or turn it off.
        // Admins and the HPL Store stay up.
        if (post('maintenance') === 'off') {
            set_setting('maintenance_until', '0');
            flash('success', 'Downtime turned off. The site is live for everyone.');
        } else {
            $mins = max(1, min(1440, (int)post('maintenance_minutes')));
            set_setting('maintenance_until', (string)(time() + $mins * 60));
            set_setting('maintenance_message', mb_substr(trim((string)post('maintenance_message')), 0, 200));
            flash('success', 'Downtime on for ' . $mins . ' minutes. Creators see the downtime page; you still have full access.');
        }
        redirect('admin/settings.php');
    }
    if (post('action') === 'tiktok_check') {
        // Test TikTok connections with the key saved in Settings: 40 per press (2026-11-12: testing a thousand in one
        // page load would time out), continuing where the last press stopped.
        require_once __DIR__ . '/../includes/tiktok.php';
        $res = ['working' => 0, 'reconnect' => 0, 'temporary' => 0];
        $cursor = (int)setting('tiktok_check_cursor', '0');
        $batch = fetch_all('SELECT * FROM tiktok_accounts WHERE id > ? ORDER BY id LIMIT 40', [$cursor]);
        foreach ($batch as $acc) {
            $r = tiktok_check_account($acc);
            $res[$r === 'working' || $r === 'temporary' ? $r : 'reconnect']++;
            $cursor = (int)$acc['id'];
        }
        $left = (int)fetch_val('SELECT COUNT(*) FROM tiktok_accounts WHERE id > ?', [$cursor], 0);
        set_setting('tiktok_check_cursor', $left > 0 ? (string)$cursor : '0');
        @unlink(BASE_PATH . '/storage/cache/tt_overview.json');
        flash($res['reconnect'] || $res['temporary'] ? 'warning' : 'success', count($batch) . ' TikTok connections checked: ' . $res['working'] . ' working, '
            . $res['reconnect'] . ' must reconnect' . ($res['temporary'] ? ', ' . $res['temporary'] . ' could not be reached (TikTok busy; try again later)' : '') . '.'
            . ($left > 0 ? ' ' . $left . ' more to check: press "Check connections" again.' : ' All checked.'));
        redirect('admin/settings.php#tiktok');
    }
    if (post('action') === 'tiktok_nudge') {
        // Ask creators with TikTok clips but no working connection to connect (once a day at most).
        require_once __DIR__ . '/../includes/tiktok.php';
        require_once __DIR__ . '/../includes/mailer.php';
        @set_time_limit(300);
        $sent = 0;
        $skipped = 0;
        $link = abs_url('creator/connect_tiktok.php');
        foreach (tiktok_creators_to_connect() as $r) {
            if (!empty($r['tiktok_nudged_at']) && strtotime((string)$r['tiktok_nudged_at']) > time() - 86400) {
                $skipped++;
                continue;
            }
            $title = $r['unreadable'] . ' TikTok clip' . ($r['unreadable'] === 1 ? '' : 's') . ' cannot be counted yet';
            $body = 'We read TikTok views through the TikTok account each clip is posted on. ' . implode(' ', tiktok_needs_text($r['needs']))
                . ' Tap "Connect my TikTok", sign in and press Continue. Leave every box ticked.';
            notif_add((int)$r['id'], 'tiktok', $title, $body, 'creator/connect_tiktok.php');
            if (!empty($r['email'])) {
                $first = trim(explode(' ', (string)$r['full_name'])[0]);
                @app_mail((string)$r['email'], $title . ' - ' . APP_NAME,
                    '<p>Hi ' . e($first) . ',</p><p>' . e($body) . '</p>'
                    . '<p><a href="' . e($link) . '" style="display:inline-block;background:#e2ad7b;color:#1e140f;padding:12px 20px;border-radius:10px;font-weight:700;text-decoration:none">Connect TikTok</a></p>',
                    "Hi $first,\n\n$body\n\n$link");
            }
            q('UPDATE users SET tiktok_nudged_at = NOW() WHERE id = ?', [(int)$r['id']]);
            $sent++;
        }
        flash('success', $sent . ' creator' . ($sent === 1 ? '' : 's') . ' asked to connect TikTok (app message and email).'
            . ($skipped ? ' ' . $skipped . ' already asked in the last 24 hours.' : ''));
        redirect('admin/settings.php#tiktok');
    }
    require_once __DIR__ . '/../includes/meta.php';
    if (post('action') === 'meta_test') {
        $r = meta_graph_get(trim((string)setting('meta_ig_user_id', '')), ['fields' => 'username,followers_count,media_count']);
        if (isset($r['error'])) {
            flash('error', 'Meta connection failed: ' . ($r['error']['message'] ?? 'unknown error'));
        } else {
            meta_note_error(null);
            flash('success', 'Meta connection works. Reading as @' . ($r['username'] ?? '?') . ' (' . num((int)($r['media_count'] ?? 0)) . ' posts).');
        }
        redirect('admin/settings.php#meta');
    }
    // Payout limits that would stop every creator from withdrawing are refused.
    if (array_key_exists('min_payout', $_POST) || array_key_exists('max_payout', $_POST)) {
        $minP = round((float)($_POST['min_payout'] ?? payout_min()), 2);
        $maxP = round((float)($_POST['max_payout'] ?? payout_max()), 2);
        if ($minP < 0 || $maxP <= 0 || $maxP < $minP) {
            flash('error', 'Not saved: the maximum per withdrawal must be more than 0 and at least the minimum payout (' . money(max(0, $minP)) . '). Otherwise nobody could withdraw.');
            redirect('admin/settings.php');
        }
    }
    $oldMetaToken = trim((string)setting('meta_access_token', ''));
    $oldTtKey = trim((string)setting('tiktok_client_key', ''));
    foreach ($keys as $k) {
        if (array_key_exists($k, $_POST)) {
            if (in_array($k, secret_setting_keys(), true)) {
                $sv = secret_setting_posted($k);
                if ($sv !== null) {
                    set_setting($k, $sv);
                }
                continue;
            }
            $val = is_string($_POST[$k]) ? trim($_POST[$k]) : '';
            if ($k === 'earnings_mode' && !in_array($val, ['blocks', 'exact'], true)) {
                $val = 'exact';
            }
            if ($k === 'only_new_videos') {
                $val = $val === '0' ? '0' : '1';
            }
            set_setting($k, $val);
        }
    }
    // A newly pasted Meta token: swap it for a 60-day one and find HPL's Instagram account.
    $newMetaToken = trim((string)setting('meta_access_token', ''));
    if ($newMetaToken !== '' && $newMetaToken !== $oldMetaToken) {
        [$long, $err] = meta_exchange_long_lived($newMetaToken);
        if ($long) {
            set_setting('meta_access_token', $long);
            set_setting('meta_token_saved_at', (string)time());
        } elseif ($err) {
            flash('warning', 'Meta token saved, but it could not be made long-lived: ' . $err . ' It may stop working within hours.');
        }
        if (trim((string)setting('meta_ig_user_id', '')) === '') {
            $found = meta_find_ig_accounts();
            if (!empty($found['accounts'])) {
                set_setting('meta_ig_user_id', $found['accounts'][0]['ig_id']);
                flash('success', 'Found Instagram @' . $found['accounts'][0]['username'] . ' (linked to ' . $found['accounts'][0]['page'] . ').');
            } elseif (isset($found['error'])) {
                flash('error', 'Meta: ' . $found['error']);
            } else {
                flash('warning', 'None of your ' . (int)($found['pages'] ?? 0) . ' Facebook Pages has an Instagram business account linked. Link HPL\'s Instagram to a Page, then save again.');
            }
        }
    }
    $newTtKey = trim((string)setting('tiktok_client_key', ''));
    if ($newTtKey !== $oldTtKey && $newTtKey !== '') {
        set_setting('tiktok_setup_alert_at', '0');
        set_setting('tiktok_last_setup_error', '');
        if (strncasecmp($newTtKey, 'sb', 2) === 0) {
            flash('warning', 'This TikTok key starts with "sb", so it looks like the sandbox (test) key. Only test users can connect with it. Paste the key of the live app.');
        } elseif ($oldTtKey !== '') {
            flash('info', 'New TikTok key saved. Connections made with the old key now show "Must reconnect", and those creators see a Reconnect button on their Home page.');
        }
    }
    set_setting('view_simulation', '0'); // simulated views are permanently off
    set_setting('google_sandbox', '0');
    flash('success', 'Settings saved.');
    redirect('admin/settings.php');
}

require_once __DIR__ . '/../includes/tiktok.php';
// One overview row per creator with a TikTok connection or TikTok clips that can earn.
// Worked out at most every 10 minutes (it reads every clip link), not on every visit to Settings.
$ttRows = [];
if (tiktok_configured()) {
    $ttCache = BASE_PATH . '/storage/cache/tt_overview.json';
    $ttRows = is_file($ttCache) && filemtime($ttCache) > time() - 600 ? (json_decode((string)file_get_contents($ttCache), true) ?: []) : null;
    if ($ttRows === null) {
        $ttRows = tiktok_overview();
        @file_put_contents($ttCache, json_encode($ttRows), LOCK_EX);
    }
}
$ttCount = ['working' => 0, 'broken' => 0, 'action' => 0, 'none' => 0];
foreach ($ttRows as $r) { $ttCount[$r['state']]++; }
$ttToAsk = array_values(array_filter($ttRows, function ($r) { return $r['state'] === 'action'; }));
$ttAskNow = array_values(array_filter($ttToAsk, function ($r) { return empty($r['tiktok_nudged_at']) || strtotime((string)$r['tiktok_nudged_at']) <= time() - 86400; }));
$ttUnread = array_sum(array_column($ttToAsk, 'unreadable'));
$ttSandbox = tiktok_key_is_sandbox();
$ttSetupErr = (string)setting('tiktok_last_setup_error', '');
require __DIR__ . '/../includes/admin_header.php';
$cronKey = setting('cron_key', '');
$cronUrl = (isset($_SERVER['HTTP_HOST']) ? 'https://' . $_SERVER['HTTP_HOST'] : '') . u('cron/fetch_views.php') . '?key=' . urlencode((string)$cronKey);
?>

<h1>Settings</h1>
<p class="muted">Control the payout economics, automation and landing page.</p>

<?php $mUntil = (int)setting('maintenance_until', 0); $mOn = $mUntil > time(); ?>
<div class="panel" style="margin-bottom:18px;<?= $mOn ? 'border-color:#d98a00' : '' ?>">
    <div class="panel__head"><h3>Site downtime<?= $mOn ? ' — ON' : '' ?></h3></div>
    <div class="panel__body">
        <?php if ($mOn): ?>
            <p class="small" style="margin-top:0">Creators see the downtime page now. It ends on its own <strong><?= e(date('g:i a', $mUntil)) ?></strong> (about <?= e(max(1, (int)round(($mUntil - time()) / 60))) ?> min). You and the HPL Store are not affected.</p>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="maintenance"><input type="hidden" name="maintenance" value="off"><button class="btn btn--ok btn--sm" type="submit">Turn the site back on now</button></form>
        <?php else: ?>
            <p class="small" style="margin-top:0">Shows every creator a "back soon" page with a countdown. You keep full access, and the HPL Store stays up.</p>
            <form method="post" class="flex flex-wrap" style="gap:10px;align-items:flex-end"><?= csrf_field() ?><input type="hidden" name="action" value="maintenance">
                <div class="field" style="margin:0"><label class="label">Minutes</label><input class="input input--sm" type="number" name="maintenance_minutes" min="1" max="1440" value="90" style="width:110px"></div>
                <div class="field" style="margin:0;flex:1;min-width:200px"><label class="label">Extra line (optional)</label><input class="input input--sm" name="maintenance_message" maxlength="200" placeholder="e.g. Back with new campaigns!"></div>
                <button class="btn btn--primary btn--sm" type="submit" data-confirm="Show all creators the downtime page now?">Start downtime</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<form method="post">
    <?= csrf_field() ?>
    <div class="grid-2">
        <div class="panel">
            <div class="panel__head"><h3>Payouts &amp; limits</h3></div>
            <div class="panel__body">
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="rate_per_1000">Rate per 1,000 views <span class="muted">(My clips only; campaigns set their own rate)</span></label>
                        <input class="input" type="number" step="0.01" min="0" id="rate_per_1000" name="rate_per_1000" value="<?= e(setting('rate_per_1000', '10.00')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="earnings_mode">How views are paid <span class="muted">(My clips only; campaign clips are paid for every view)</span></label>
                        <?php $em = setting('earnings_mode', 'exact') === 'blocks' ? 'blocks' : 'exact'; ?>
                        <select class="input" id="earnings_mode" name="earnings_mode">
                            <option value="exact"<?= $em === 'exact' ? ' selected' : '' ?>>Every view (pro-rata, like Whop)</option>
                            <option value="blocks"<?= $em === 'blocks' ? ' selected' : '' ?>>Per full 1,000 views</option>
                        </select>
                        <div class="field__hint">"Every view": 934 views pays <?= money(floor(934 * (float)setting('rate_per_1000', 10) / 10) / 100) ?> straight away. "Per full 1,000": a clip is paid each time it passes another 1,000 views. Changing this never re-pays views already credited.</div>
                    </div>
                    <div class="field">
                        <label class="label" for="only_new_videos">Which videos can earn</label>
                        <?php $onv = setting('only_new_videos', '1') === '1'; ?>
                        <select class="input" id="only_new_videos" name="only_new_videos">
                            <option value="1"<?= $onv ? ' selected' : '' ?>>Only videos posted after the creator joined</option>
                            <option value="0"<?= $onv ? '' : ' selected' ?>>Any video from their verified page</option>
                        </select>
                        <div class="field__hint">Stops creators from cashing in old viral videos. Checked through TikTok for creators who connected their account.</div>
                    </div>
                    <div class="field">
                        <label class="label" for="max_daily_links">Max links per creator / day <span class="muted">(My clips only)</span></label>
                        <input class="input" type="number" min="1" id="max_daily_links" name="max_daily_links" value="<?= e(setting('max_daily_links', '20')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="signup_bonus_amount">Sign-up bonus</label>
                        <input class="input" type="number" step="0.01" min="0" id="signup_bonus_amount" name="signup_bonus_amount" value="<?= e(setting('signup_bonus_amount', '50.00')) ?>">
                        <div class="field__hint">Given once to every new creator account. They tap Receive on their dashboard and it goes straight to their wallet. 0 turns it off. Changing it only affects accounts created after the change.</div>
                    </div>
                    <div class="field">
                        <label class="label" for="min_payout">Minimum payout</label>
                        <input class="input" type="number" step="0.01" min="0" id="min_payout" name="min_payout" value="<?= e(number_format(payout_min(), 2, '.', '')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="max_payout">Maximum per withdrawal</label>
                        <input class="input" type="number" step="0.01" min="0" id="max_payout" name="max_payout" value="<?= e(number_format(payout_max(), 2, '.', '')) ?>">
                        <div class="field__hint">The most a creator can take out in one request. They can have one request waiting at a time.</div>
                    </div>
                    <div class="field">
                        <label class="label" for="general_clips_enabled">Pay for clips outside campaigns</label>
                        <?php $gce = setting('general_clips_enabled', '0') === '1'; ?>
                        <select class="input" id="general_clips_enabled" name="general_clips_enabled">
                            <option value="0"<?= !$gce ? ' selected' : '' ?>>No, campaigns only (every kwacha comes off a campaign budget)</option>
                            <option value="1"<?= $gce ? ' selected' : '' ?>>Yes, "My clips" also earns (no budget limit)</option>
                        </select>
                        <div class="field__hint">When off, creators submit clips inside campaigns only, and older clips outside campaigns stop earning. Money already earned stays in their balance.</div>
                    </div>
                    <div class="field">
                        <label class="label" for="campaign_creator_cap">Default max per creator, per campaign</label>
                        <input class="input" type="number" step="0.01" min="0" id="campaign_creator_cap" name="campaign_creator_cap" value="<?= e(setting('campaign_creator_cap', '3000.00')) ?>">
                        <div class="field__hint">The most one creator can earn from a single campaign (all their clips together). Each campaign can override this. 0 = no limit.</div>
                    </div>
                    <div class="field">
                        <label class="label" for="currency_symbol">Currency symbol</label>
                        <input class="input" type="text" id="currency_symbol" name="currency_symbol" value="<?= e(setting('currency_symbol', 'K')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="currency_code">Currency code</label>
                        <input class="input" type="text" id="currency_code" name="currency_code" value="<?= e(setting('currency_code', 'ZMW')) ?>">
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="blocked_link_words">Blocked links</label>
                    <textarea class="textarea" id="blocked_link_words" name="blocked_link_words" rows="2"><?= e(implode(', ', hpl_blocked_link_words())) ?></textarea>
                    <div class="field__hint">Links containing any of these words or sites are refused when creators submit, and shown as "Blocked link" (never clickable) everywhere on the site. Separate with commas.</div>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label class="label" for="payout_contact_email">Payout contact email</label>
                        <input class="input" type="email" id="payout_contact_email" name="payout_contact_email" value="<?= e(setting('payout_contact_email', '')) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="support_phone">Support phone</label>
                        <input class="input" type="text" id="support_phone" name="support_phone" value="<?= e(setting('support_phone', '')) ?>">
                    </div>
                </div>
            </div>
        </div>

        <div class="panel" id="tiktok">
            <div class="panel__head"><h3><?= icon('tiktok') ?> TikTok connection</h3>
                <span class="ui-badge <?= tiktok_configured() && !$ttSandbox ? 'ui-badge--ok' : 'ui-badge--warn' ?>"><?= !tiktok_configured() ? 'Not set up' : ($ttSandbox ? 'Test (sandbox) key' : 'Live') ?></span></div>
            <div class="panel__body">
                <p class="small muted">Creators sign in with TikTok. This proves the account is theirs (the page is verified at once) and lets the site read the real views of their TikTok clips.</p>
                <?php if ($ttSandbox): ?>
                    <div class="ui-alert ui-alert--warn"><?= icon('alert') ?><span>The client key below starts with "sb", so it is the <strong>test (sandbox)</strong> key. Only test users can connect. In the TikTok developer portal open your app's <strong>Production</strong> side and paste its client key and client secret here.</span></div>
                <?php endif; ?>
                <?php if ($ttSetupErr !== ''): ?>
                    <div class="ui-alert ui-alert--error"><?= icon('alert') ?><span>Last failed sign-in: <?= e($ttSetupErr) ?>. Check the key, secret, redirect URI and scopes below. This message goes away after the next successful connection.</span></div>
                <?php endif; ?>
                <?php if (tiktok_configured()): ?>
                    <p class="small" style="margin-bottom:6px"><?= $ttUnread ? '<strong>' . num($ttUnread) . '</strong> TikTok clip' . ($ttUnread === 1 ? '' : 's') . ' of <strong>' . count($ttToAsk) . '</strong> creator' . (count($ttToAsk) === 1 ? '' : 's') . ' cannot be read yet.' : 'Every TikTok clip that can earn is being read.' ?></p>
                    <p class="small" style="margin:0 0 8px"><?= num($ttCount['working']) ?> working &middot; <?= num($ttCount['broken'] + $ttCount['action']) ?> need something &middot; <a href="<?= e(u('admin/tiktok_accounts.php')) ?>">See every TikTok account (search, 50 a page)</a></p>
                    <?php $ttNeed = array_values(array_filter($ttRows, fn($r) => $r['state'] !== 'working')); // only the ones that need something (2026-11-12) ?>
                    <?php if ($ttNeed): ?>
                        <div style="border:1px solid var(--line);border-radius:10px;margin-bottom:10px">
                            <?php foreach (array_slice($ttNeed, 0, 20) as $k => $r): ?>
                                <div style="padding:8px 10px;<?= $k ? 'border-top:1px solid var(--line);' : '' ?>">
                                    <div class="small"><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$r['id'])) ?>"><strong><?= e($r['full_name']) ?></strong></a>
                                        <span class="muted">&middot; <?= (int)$r['clips'] ?> TikTok clip<?= (int)$r['clips'] === 1 ? '' : 's' ?> that can earn</span></div>
                                    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px">
                                        <?php foreach ($r['accounts'] as $tx): ?>
                                            <span class="badge <?= $tx['problem'] === null ? 'ok' : 'bad' ?>">@<?= e($tx['row']['username'] ?: '?') ?>: <?= $tx['problem'] === null ? 'working' . ($tx['row']['last_sync_at'] ? ', read ' . e(time_ago($tx['row']['last_sync_at'])) : '') : 'must reconnect (' . e(tiktok_problem_label($tx['problem'])) . ')' ?></span>
                                        <?php endforeach; ?>
                                        <?php if (!$r['accounts']): ?><span class="badge warn">No TikTok connected</span><?php endif; ?>
                                    </div>
                                    <?php if ($r['unreadable'] > 0): ?>
                                        <div class="small" style="margin-top:4px;color:var(--ui-bad)"><?= $r['unreadable'] ?> clip<?= $r['unreadable'] === 1 ? '' : 's' ?> cannot be read: <?= e(implode(', ', array_map(function ($h, $n) { return $h === '' ? 'short link (account unknown)' : ($n['connected'] ? 'reconnect @' . $h : 'connect @' . $h); }, array_keys($r['needs']), $r['needs']))) ?>.</div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (count($ttNeed) > 20): ?><p class="small" style="margin:-4px 0 10px"><?= count($ttNeed) - 20 ?> more need something: <a href="<?= e(u('admin/tiktok_accounts.php')) ?>">see them all</a>.</p><?php endif; ?>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
                        <button class="btn btn--ghost btn--sm" type="submit" form="ttCheck">Check connections<?= (int)setting('tiktok_check_cursor', '0') > 0 ? ' (continue)' : ' now' ?></button>
                        <?php if ($ttToAsk): ?>
                            <button class="btn btn--primary btn--sm" type="submit" form="ttNudge"<?= ($ttSandbox || !$ttAskNow) ? ' disabled' : '' ?>><?= $ttAskNow ? 'Ask ' . count($ttAskNow) . ' creator' . (count($ttAskNow) === 1 ? '' : 's') . ' to connect' : 'All asked today' ?></button>
                            <span class="small muted">Sends each of them an app message and an email saying exactly which TikTok account to connect. Once a day at most.</span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                <div class="field">
                    <label class="label" for="tiktok_client_key">Client key</label>
                    <input class="input" type="text" id="tiktok_client_key" name="tiktok_client_key" autocomplete="off" value="<?= e(setting('tiktok_client_key', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="tiktok_client_secret">Client secret</label>
                    <?= secret_setting_input('tiktok_client_secret') ?>
                </div>
                <div class="field">
                    <label class="label">Redirect URI <span class="muted">(add this exact address in Login Kit)</span></label>
                    <p><span class="code-inline" data-copy="<?= e(tiktok_redirect_uri()) ?>" style="cursor:pointer"><?= e(tiktok_redirect_uri()) ?></span></p>
                </div>
                <div class="field">
                    <label class="label" for="tiktok_scopes">Scopes</label>
                    <input class="input" type="text" id="tiktok_scopes" name="tiktok_scopes" value="<?= e(setting('tiktok_scopes', '')) ?>" placeholder="user.info.basic,user.info.profile,user.info.stats,video.list">
                    <div class="field__hint">Leave empty for the default. Every scope listed here must also be added to your app in the TikTok developer portal.</div>
                </div>
            </div>
        </div>

        <div class="panel" id="meta">
            <?php require_once __DIR__ . '/../includes/meta.php'; $metaOn = meta_configured(); $metaErr = (string)setting('meta_last_error', ''); ?>
            <div class="panel__head"><h3><?= icon('instagram') ?> Instagram views (Meta)</h3>
                <span class="ui-badge <?= $metaOn ? 'ui-badge--ok' : 'ui-badge--warn' ?>"><?= $metaOn ? 'On' : 'Not set up' ?></span></div>
            <div class="panel__body">
                <p class="small muted">HPL's own Instagram business account reads the public view count of creators' Reels. Creators don't log in, but their Instagram must be a Professional (business or creator) account.</p>
                <div class="field">
                    <label class="label" for="meta_app_id">App ID</label>
                    <input class="input" type="text" id="meta_app_id" name="meta_app_id" autocomplete="off" value="<?= e(setting('meta_app_id', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="meta_app_secret">App secret</label>
                    <?= secret_setting_input('meta_app_secret') ?>
                </div>
                <div class="field">
                    <label class="label" for="meta_access_token">Access token</label>
                    <?= secret_setting_input('meta_access_token') ?>
                    <div class="field__hint">Paste the token from Graph API Explorer. On save it is swapped for a 60-day token automatically<?= setting('meta_token_saved_at') ? ' (last renewed ' . e(time_ago(date('Y-m-d H:i:s', (int)setting('meta_token_saved_at')))) . ')' : '' ?>.</div>
                </div>
                <div class="field">
                    <label class="label" for="meta_ig_user_id">HPL Instagram account ID</label>
                    <input class="input" type="text" id="meta_ig_user_id" name="meta_ig_user_id" value="<?= e(setting('meta_ig_user_id', '')) ?>" placeholder="Found automatically when you save a token">
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label class="label" for="meta_graph_version">Graph API version</label>
                        <input class="input" type="text" id="meta_graph_version" name="meta_graph_version" value="<?= e(setting('meta_graph_version', '')) ?>" placeholder="<?= e(meta_graph_version()) ?>">
                    </div>
                    <div class="field">
                        <label class="label" for="meta_media_pages">Posts checked per creator</label>
                        <select class="input" id="meta_media_pages" name="meta_media_pages">
                            <?php foreach ([1 => 'Latest 50', 2 => 'Latest 100', 4 => 'Latest 200', 8 => 'Latest 400'] as $pv => $pl): ?>
                                <option value="<?= $pv ?>" <?= (int)setting('meta_media_pages', 4) === $pv ? 'selected' : '' ?>><?= $pl ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <?php if ($metaErr !== ''): ?>
                    <div class="ui-alert ui-alert--warn"><?= icon('alert') ?><span>Last Meta problem: <?= e($metaErr) ?></span></div>
                <?php endif; ?>
                <?php if ($metaOn): ?>
                    <button class="btn btn--ghost btn--sm" type="submit" name="action" value="meta_test" data-busy="Testing...">Test connection</button>
                <?php endif; ?>
            </div>
        </div>

        <div class="panel">
            <div class="panel__head"><h3>How views are read</h3></div>
            <div class="panel__body">
                <ul class="small" style="margin:0 0 14px;padding-left:18px;display:grid;gap:6px">
                    <li><strong>TikTok:</strong> official API through each creator's connected account<?= tiktok_configured() ? '' : ' (switch on above)' ?>.</li>
                    <li><strong>YouTube:</strong> read from the public video page, no key needed.</li>
                    <li><strong>Instagram:</strong> Meta Business Discovery through HPL's business account<?= meta_configured() ? '' : ' (switch on above)' ?>. Creators need a Professional account.</li>
                    <li><strong>Facebook:</strong> not read automatically yet. Enter views by hand on the creator's page (Creators &rarr; open the creator &rarr; Campaign clips).</li>
                    <li>Simulated views are switched off. Money is only credited for real views.</li>
                </ul>
                <div class="field">
                    <label class="label" for="autosync_minutes">Refresh views every (minutes)</label>
                    <input class="input" type="number" min="10" max="1440" id="autosync_minutes" name="autosync_minutes" value="<?= e(setting('autosync_minutes', '30')) ?>">
                    <div class="field__hint">The site refreshes views by itself while people use it (the hosting plan has no cron). Last run: <?= setting('autosync_last_at') ? e(time_ago(date('Y-m-d H:i:s', (int)setting('autosync_last_at')))) : 'not yet' ?>.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Landing page</h3></div>
        <div class="panel__body">
            <div class="field">
                <label class="label" for="landing_headline">Hero headline</label>
                <input class="input" type="text" id="landing_headline" name="landing_headline" value="<?= e(setting('landing_headline', '')) ?>">
            </div>
            <div class="field">
                <label class="label" for="landing_subheadline">Hero sub-headline</label>
                <textarea class="textarea" id="landing_subheadline" name="landing_subheadline" rows="2"><?= e(setting('landing_subheadline', '')) ?></textarea>
            </div>
            <div class="field">
                <label class="label" for="promo_video_url">Promo video URL or path</label>
                <input class="input" type="text" id="promo_video_url" name="promo_video_url" value="<?= e(setting('promo_video_url', 'assets/media/promo.mp4')) ?>">
                <div class="field__hint">Fallback MP4 used only when no Bunny embed is set. Upload to <span class="code-inline">assets/media/promo.mp4</span> or paste a hosted MP4 URL. Current file: <?= is_file(BASE_PATH . '/' . setting('promo_video_url', '')) ? 'found' : 'not found on disk' ?>.</div>
            </div>
            <div class="field">
                <label class="label" for="promo_embed_code">Bunny.net embed code or URL</label>
                <textarea class="textarea" id="promo_embed_code" name="promo_embed_code" rows="4" placeholder="&lt;iframe src=&quot;https://iframe.mediadelivery.net/embed/...&quot;&gt;&lt;/iframe&gt;"><?= e(setting('promo_embed_code', '')) ?></textarea>
                <div class="field__hint">Paste the Bunny Stream embed code (or direct embed URL). When set, it replaces the promo video on the homepage and dashboard intro.</div>
            </div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Google sign-in</h3></div>
        <div class="panel__body">
            <p class="small muted mb-2">Create OAuth credentials at <a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener">Google Cloud Console</a> and add <span class="code-inline"><?= e(google_redirect_uri()) ?></span> as an authorized redirect URI.</p>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="google_client_id">Google OAuth Client ID</label>
                    <input class="input" type="text" id="google_client_id" name="google_client_id" placeholder="xxxx.apps.googleusercontent.com" value="<?= e(setting('google_client_id', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="google_client_secret">Google OAuth Client Secret</label>
                    <?= secret_setting_input('google_client_secret') ?>
                </div>
            </div>
            <p class="muted small">Google sign-in requires real Google OAuth credentials. Email-only sandbox sign-in is disabled. Manage signup protection under Dashboard &amp; signup.</p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Email — send (SMTP)</h3></div>
        <div class="panel__body">
            <p class="small muted mb-2">Used for password resets and notifications. Leave host empty to fall back to PHP <span class="code-inline">mail()</span>/log. If SMTP fails, reset links are still written to <span class="code-inline">storage/mail.log</span>.</p>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="mail_from_email">From email</label>
                    <input class="input" type="email" id="mail_from_email" name="mail_from_email" value="<?= e(setting('mail_from_email', '')) ?>" placeholder="no-reply@<?= e(preg_replace('/^www\./i', '', (string)($_SERVER['HTTP_HOST'] ?? 'golddetectorinzambia.com'))) ?>">
                </div>
                <div class="field">
                    <label class="label" for="mail_from_name">From name</label>
                    <input class="input" type="text" id="mail_from_name" name="mail_from_name" value="<?= e(setting('mail_from_name', APP_NAME)) ?>">
                </div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="smtp_host">SMTP host</label>
                    <input class="input" type="text" id="smtp_host" name="smtp_host" placeholder="mail.yourdomain.com" value="<?= e(setting('smtp_host', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="smtp_port">SMTP port</label>
                    <input class="input" type="number" id="smtp_port" name="smtp_port" value="<?= e(setting('smtp_port', '587')) ?>">
                </div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="smtp_username">SMTP username</label>
                    <input class="input" type="text" id="smtp_username" name="smtp_username" placeholder="info@yourdomain.com" value="<?= e(setting('smtp_username', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="smtp_password">SMTP password</label>
                    <?= secret_setting_input('smtp_password') ?>
                </div>
            </div>
            <div class="field">
                <label class="label" for="smtp_encryption">Encryption</label>
                <select class="input" id="smtp_encryption" name="smtp_encryption">
                    <option value="tls" <?= setting('smtp_encryption', 'tls') === 'tls' ? 'selected' : '' ?>>TLS (STARTTLS, port 587)</option>
                    <option value="ssl" <?= setting('smtp_encryption', 'tls') === 'ssl' ? 'selected' : '' ?>>SSL (implicit TLS, port 465)</option>
                    <option value="none" <?= setting('smtp_encryption', 'tls') === 'none' ? 'selected' : '' ?>>None (port 25)</option>
                </select>
            </div>
            <div class="field__hint">Typical cPanel mailboxes: host <span class="code-inline">mail.yourdomain.com</span>, port <span class="code-inline">587</span> or <span class="code-inline">465</span>, and the full email address as username.</div>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Email — receive (IMAP inbox)</h3></div>
        <div class="panel__body">
            <p class="small muted mb-2">Lets the admin <strong>Inbox</strong> page read email from a mailbox (e.g. <span class="code-inline">info@golddetectorinzambia.com</span>). Requires the PHP IMAP extension.</p>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="imap_host">IMAP host</label>
                    <input class="input" type="text" id="imap_host" name="imap_host" placeholder="mail.yourdomain.com" value="<?= e(setting('imap_host', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="imap_port">IMAP port</label>
                    <input class="input" type="number" id="imap_port" name="imap_port" value="<?= e(setting('imap_port', '993')) ?>">
                </div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label class="label" for="imap_username">IMAP username</label>
                    <input class="input" type="text" id="imap_username" name="imap_username" placeholder="info@yourdomain.com" value="<?= e(setting('imap_username', '')) ?>">
                </div>
                <div class="field">
                    <label class="label" for="imap_password">IMAP password</label>
                    <?= secret_setting_input('imap_password') ?>
                </div>
            </div>
            <div class="field">
                <label class="label" for="imap_encryption">Encryption</label>
                <select class="input" id="imap_encryption" name="imap_encryption">
                    <option value="ssl" <?= setting('imap_encryption', 'ssl') === 'ssl' ? 'selected' : '' ?>>SSL (port 993)</option>
                    <option value="tls" <?= setting('imap_encryption', 'ssl') === 'tls' ? 'selected' : '' ?>>STARTTLS (port 143)</option>
                    <option value="none" <?= setting('imap_encryption', 'ssl') === 'none' ? 'selected' : '' ?>>None (port 143)</option>
                </select>
            </div>
        </div>
    </div>

    <button class="btn btn--primary" type="submit">Save settings</button>
</form>
<form method="post" id="ttCheck"><?= csrf_field() ?><input type="hidden" name="action" value="tiktok_check"></form>
<form method="post" id="ttNudge"><?= csrf_field() ?><input type="hidden" name="action" value="tiktok_nudge"></form>

<div class="panel mt-3">
    <div class="panel__head"><h3>View sync schedule</h3></div>
    <div class="panel__body">
        <p class="small muted mb-2">Views already refresh automatically (see "How views are read"). For an exact schedule, a free service such as cron-job.org can open this link every 30 minutes:</p>
        <p><span class="code-inline" data-copy="<?= e($cronUrl) ?>" style="cursor:pointer"><?= e($cronUrl) ?></span></p>
        <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="regenerate_key"><button class="btn btn--ghost btn--sm" type="submit">Regenerate key</button></form>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
