<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/telegram.php';
$user = require_admin();
$pageTitle = 'Telegram assistant';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'save_token') {
        $tok = trim((string)($_POST['tg_bot_token'] ?? ''));
        if (!preg_match('~^\d{6,12}:[A-Za-z0-9_-]{30,}$~', $tok)) {
            flash('error', 'That does not look like a bot token. Copy the whole line BotFather gave you, like 123456789:AA…');
        } else {
            set_setting('tg_bot_token', $tok);
            $me = tg_api('getMe');
            if (!$me || empty($me['ok'])) {
                set_setting('tg_bot_token', '');
                flash('error', 'Telegram did not accept this token' . (!empty($me['description']) ? ' (' . $me['description'] . ')' : '') . '. Check it in BotFather and paste it again.');
            } else {
                set_setting('tg_bot_username', (string)$me['result']['username']);
                $secret = bin2hex(random_bytes(24));
                set_setting('tg_webhook_secret', $secret);
                $hook = tg_api('setWebhook', [
                    'url' => abs_url('telegram_webhook.php'),
                    'secret_token' => $secret,
                    'allowed_updates' => ['message', 'callback_query'],
                    'drop_pending_updates' => true,
                ]);
                if ($hook && !empty($hook['ok'])) {
                    flash('success', 'Bot @' . $me['result']['username'] . ' is linked to the website. Now tap "Connect my Telegram".');
                } else {
                    flash('error', 'The token works, but Telegram could not reach the website' . (!empty($hook['description']) ? ' (' . $hook['description'] . ')' : '') . '.');
                }
            }
        }
    } elseif ($action === 'connect') {
        set_setting('tg_link_code', bin2hex(random_bytes(10)));
        set_setting('tg_link_expires', (string)(time() + 1800));
        redirect('admin/telegram.php?connect=1');
    } elseif ($action === 'disconnect') {
        set_setting('tg_owner_chat_id', '');
        set_setting('tg_owner_name', '');
        flash('warning', 'Telegram disconnected. No more alerts will be sent.');
    } elseif ($action === 'remove_token') {
        tg_api('deleteWebhook');
        foreach (['tg_bot_token', 'tg_bot_username', 'tg_webhook_secret', 'tg_owner_chat_id', 'tg_owner_name'] as $k) set_setting($k, '');
        flash('warning', 'Bot removed from the website.');
    } elseif ($action === 'save_ai') {
        $key = trim((string)($_POST['anthropic_api_key'] ?? ''));
        if ($key !== '') {
            if (!preg_match('~^sk-ant-[A-Za-z0-9_-]{20,}$~', $key)) {
                flash('error', 'That does not look like an Anthropic API key (it starts with sk-ant-).');
                redirect('admin/telegram.php');
            }
            set_setting('anthropic_api_key', $key);
        }
        set_setting('anthropic_model', mb_substr(trim(post('anthropic_model')), 0, 60));
        set_setting('help_ai_on', post('help_ai_on') === '1' ? '1' : '0');
        flash('success', 'Claude settings saved.');
    } elseif ($action === 'remove_ai') {
        set_setting('anthropic_api_key', '');
        flash('warning', 'Claude key removed. Buttons still work; typed replies are off.');
    } elseif ($action === 'save_alerts') {
        foreach (array_keys(tg_alert_kinds()) as $k) set_setting('tg_off_' . $k, isset($_POST['on'][$k]) ? '0' : '1');
        $sum = array_values(array_intersect(array_keys(tg_alert_kinds()), array_keys((array)($_POST['sum'] ?? []))));
        set_setting('tg_summary_kinds', implode(',', array_diff($sum, ['daily'])));
        set_setting('tg_summary_hours', (string)max(1, min(24, (int)post('summary_hours'))));
        flash('success', 'Alert choices saved.');
    } elseif ($action === 'save_voice') {
        require_once __DIR__ . '/../includes/brain_call.php';
        foreach (['elevenlabs_api_key', 'twilio_auth_token'] as $k) { $v = secret_setting_posted($k); if ($v !== null) set_setting($k, $v); }
        set_setting('elevenlabs_voice_id', preg_replace('~[^A-Za-z0-9]~', '', post('elevenlabs_voice_id')));
        set_setting('twilio_sid', preg_replace('~[^A-Za-z0-9]~', '', post('twilio_sid')));
        set_setting('twilio_from', preg_replace('~[^0-9+]~', '', post('twilio_from')));
        set_setting('brain_call_to', preg_replace('~[^0-9+ ]~', '', post('brain_call_to')));
        set_setting('brain_voice_notes', post('brain_voice_notes') === '1' ? '1' : '0');
        set_setting('brain_calls_on', post('brain_calls_on') === '1' ? '1' : '0');
        set_setting('brain_call_max', (string)max(1, min(10, (int)post('brain_call_max'))));
        flash('success', 'Voice and phone settings saved.');
    } elseif ($action === 'test_voice') {
        require_once __DIR__ . '/../includes/brain_call.php';
        $ok = brain_send_voice_note('Hello. This is the HPL Brain. From now on I will read my updates to you like this, four times a day, and tell you at once when something needs you.', 'Test voice note');
        flash($ok ? 'success' : 'error', $ok ? 'Voice note sent. Check Telegram.' : 'No voice note: ' . ((string)setting('brain_voice_last_error', '') ?: 'add the ElevenLabs key and connect Telegram first.'));
    } elseif ($action === 'brief_call') {
        require_once __DIR__ . '/../includes/brain_call.php';
        [$ok, $msg] = brain_briefing_call();
        flash($ok ? 'success' : 'error', $ok ? $msg . ' The Brain is telling you how the app is doing today.' : $msg);
    } elseif ($action === 'brief_later') {
        require_once __DIR__ . '/../includes/brain_call.php';
        $at = brain_brief_schedule(max(1, min(600, (int)post('minutes'))));
        $daily = trim(post('daily'));
        set_setting('brain_brief_daily', preg_match('~^([01]?\d|2[0-3]):[0-5]\d$~', $daily) ? $daily : '');
        flash('success', 'The Brain will call you at about ' . date('H:i', $at) . '.' . ($daily !== '' ? ' And every day at ' . $daily . '.' : ''));
    } elseif ($action === 'test_call') {
        require_once __DIR__ . '/../includes/brain_call.php';
        [$ok, $msg] = brain_phone_call('This is a test call. When something serious happens in the app, I will call you like this and explain it.', true, true);
        flash($ok ? 'success' : 'error', $msg);
    } elseif ($action === 'test') {
        $mid = tg_send("👋 <b>Test from HPL Creators.</b> Alerts are working.\n\n" . tg_stats_text(tg_stats('today')), [[['See what is waiting', 'm:pending']]]);
        flash($mid ? 'success' : 'error', $mid ? 'Test message sent. Check Telegram.' : 'Could not send. Is the bot connected?');
    }
    redirect('admin/telegram.php');
}

$tok = tg_token();
$bot = (string)setting('tg_bot_username', '');
$owner = tg_owner();
$ownerName = (string)setting('tg_owner_name', '');
$aiKey = tg_ai_key();
$hookInfo = $tok !== '' ? tg_api('getWebhookInfo', [], 5) : null;
$hookOk = $hookInfo && !empty($hookInfo['ok']) && ($hookInfo['result']['url'] ?? '') !== '';
$hookErr = $hookInfo['result']['last_error_message'] ?? '';
$linkCode = (string)setting('tg_link_code', '');
$linkLive = $linkCode !== '' && (int)setting('tg_link_expires', 0) > time();
$log = [];
try { $log = fetch_all('SELECT * FROM tg_log ORDER BY id DESC LIMIT 15'); } catch (Throwable $e) {}

require __DIR__ . '/../includes/admin_header.php';
?>
<style>
.tg-steps { display:grid; gap:16px; }
.tg-step__n { display:inline-grid; place-items:center; width:30px; height:30px; border-radius:50%; background:var(--ui-accent, #a8642c); color:#fff; font-weight:700; margin-right:10px; }
.tg-ok { color:#1f8a4c; font-weight:700; }
.tg-muted { color:var(--muted); }
.tg-big { display:inline-flex; align-items:center; gap:8px; }
.tg-alerts label { display:flex; gap:10px; align-items:center; padding:6px 0; }
.tg-alert-row { display:flex; gap:16px; align-items:center; justify-content:space-between; flex-wrap:wrap; border-bottom:1px solid var(--ui-line, #eee); }
</style>

<div class="mb-3">
    <h1 style="margin-bottom:4px">Telegram assistant</h1>
    <p class="muted mb-0">Get alerts on your phone and answer them there: tap Approve or Reject, or type what you want and Claude does it on the website.</p>
</div>

<div class="tg-steps">
    <div class="panel"><div class="panel__body">
        <h3><span class="tg-step__n">1</span>Link your bot</h3>
        <?php if ($tok !== '' && $bot !== ''): ?>
            <p class="tg-ok">✓ Bot @<?= e($bot) ?> is linked (token ending …<?= e(substr($tok, -4)) ?>).</p>
            <p class="small <?= $hookOk && $hookErr === '' ? 'tg-muted' : '' ?>">Website connection: <?= $hookOk ? ($hookErr === '' ? 'working' : 'last error: ' . e($hookErr)) : 'not set, paste the token again' ?></p>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="remove_token"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Remove the bot from the website?">Remove bot</button></form>
        <?php else: ?>
            <p>Paste the token that <b>@BotFather</b> gave you (it looks like <code>123456789:AAF…</code>).</p>
        <?php endif; ?>
        <form method="post" class="mt-2" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_token">
            <div class="field"><label class="label" for="tg_bot_token"><?= $tok ? 'Replace token' : 'Bot token' ?></label>
                <input class="input" type="password" id="tg_bot_token" name="tg_bot_token" autocomplete="off" placeholder="123456789:AA..." required></div>
            <button class="btn btn--primary" type="submit" data-busy="Linking…">Save and link</button>
        </form>
    </div></div>

    <div class="panel"><div class="panel__body">
        <h3><span class="tg-step__n">2</span>Connect your Telegram</h3>
        <?php if ($owner !== ''): ?>
            <p class="tg-ok">✓ Connected<?= $ownerName !== '' ? ' to ' . e($ownerName) : '' ?>. Alerts go to this Telegram account only.</p>
            <div class="flex flex-wrap" style="gap:10px">
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test"><button class="btn btn--primary btn--sm" type="submit">Send a test alert</button></form>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="disconnect"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Stop sending alerts to this Telegram?">Disconnect</button></form>
            </div>
        <?php elseif ($bot === ''): ?>
            <p class="tg-muted">Do step 1 first.</p>
        <?php elseif ($linkLive && get('connect')): ?>
            <p>Open this link on your phone, then tap <b>Start</b> in Telegram. The link works once, for 30 minutes.</p>
            <p><a class="btn btn--primary tg-big" href="https://t.me/<?= e($bot) ?>?start=<?= e($linkCode) ?>" target="_blank" rel="noopener">Open @<?= e($bot) ?> in Telegram →</a></p>
            <p class="small tg-muted">After you tap Start, the bot replies "Connected". Then refresh this page.</p>
            <div class="panel" style="background:var(--surface-2,#f6f1ea);padding:12px;margin-top:10px">
                <p class="small" style="margin:0 0 6px"><b>Start button does nothing?</b> Open Telegram on your phone, search <b>@<?= e($bot) ?></b>, open the chat and send this exact message:</p>
                <p style="margin:0"><code id="tgStartCmd" style="font-size:15px;user-select:all">/start <?= e($linkCode) ?></code>
                <button class="btn btn--ghost btn--sm" type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('tgStartCmd').textContent);this.textContent='Copied'">Copy</button></p>
            </div>
        <?php else: ?>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="connect"><button class="btn btn--primary" type="submit">Connect my Telegram</button></form>
        <?php endif; ?>
    </div></div>

    <div class="panel"><div class="panel__body">
        <h3><span class="tg-step__n">3</span>Typed replies (Claude) <span class="badge muted">optional</span></h3>
        <p class="small tg-muted">Buttons work without this. With a Claude key you can type things like "reject Chanda, the video is too long" or "who is waiting for payment?". About K0.10 per reply, billed by Anthropic.</p>
        <?php if ($aiKey !== ''): ?><p class="tg-ok">✓ Claude key saved (ending …<?= e(substr($aiKey, -4)) ?>).</p><?php endif; ?>
        <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_ai">
            <div class="field"><label class="label" for="anthropic_api_key"><?= $aiKey ? 'Replace Claude API key' : 'Claude API key (from console.anthropic.com)' ?></label>
                <input class="input" type="password" id="anthropic_api_key" name="anthropic_api_key" autocomplete="off" placeholder="sk-ant-..."></div>
            <div class="field"><label class="label" for="anthropic_model">Model</label>
                <input class="input" type="text" id="anthropic_model" name="anthropic_model" value="<?= e((string)setting('anthropic_model', '')) ?>" placeholder="claude-haiku-5-5 (fast and cheap)">
                <div class="field__hint">Leave empty for the default.</div></div>
            <label class="field" style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="help_ai_on" value="1" <?= (string)setting('help_ai_on', '1') === '1' ? 'checked' : '' ?> style="width:22px;height:22px;margin-top:2px">
                <span><strong>Also answer creators in Help</strong><br><span class="small tg-muted">When a creator asks something in Help that has no ready answer, Claude answers from the site's own Help texts and that creator's status. It cannot change anything, never explains how clips are checked, and hands over to you when it is not sure. At most 10 answers a creator a day.</span></span></label>
            <button class="btn btn--primary" type="submit">Save</button>
        </form>
        <?php if ($aiKey !== ''): ?><form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="remove_ai"><button class="btn btn--ghost btn--sm" type="submit">Remove key</button></form><?php endif; ?>
    </div></div>

    <?php require_once __DIR__ . '/../includes/brain_call.php'; ?>
    <div class="panel"><div class="panel__body">
        <h3>Voice notes and phone calls from the Brain <span class="badge muted">optional</span></h3>
        <p class="small tg-muted">The Brain reads its updates to you as Telegram voice notes, and rings your phone when something needs you now. Voice: ElevenLabs (a few cents a day). Calls: Twilio (paid per minute; at most a few calls a day, never 22:00 to 07:00 unless it is about money).</p>
        <?php if (brain_voice_ready()): ?><p class="tg-ok">✓ Voice is set up.</p><?php endif; ?>
        <?php if (brain_call_ready()): ?><p class="tg-ok">✓ Phone calls are set up (to <?= e(brain_call_to()) ?>).</p><?php endif; ?>
        <?php foreach (['brain_voice_last_error' => 'Last voice problem', 'brain_call_last_error' => 'Last call problem'] as $ek => $el): if ((string)setting($ek, '') !== ''): ?><p class="flash flash--warning small"><?= e($el) ?>: <?= e((string)setting($ek, '')) ?></p><?php endif; endforeach; ?>
        <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="action" value="save_voice">
            <h4 style="margin:10px 0 6px">Voice (ElevenLabs)</h4>
            <div class="field"><label class="label" for="elevenlabs_api_key">ElevenLabs API key (elevenlabs.io: Profile, API keys)</label><?= secret_setting_input('elevenlabs_api_key') ?></div>
            <div class="field"><label class="label" for="elevenlabs_voice_id">Voice ID (optional)</label><input class="input" id="elevenlabs_voice_id" name="elevenlabs_voice_id" value="<?= e((string)setting('elevenlabs_voice_id', '')) ?>" placeholder="Leave empty for the default voice"><div class="field__hint">In ElevenLabs, open Voices, pick one you like and copy its ID.</div></div>
            <label class="field" style="display:flex;gap:10px;align-items:center"><input type="checkbox" name="brain_voice_notes" value="1" <?= setting('brain_voice_notes', '1') === '1' ? 'checked' : '' ?> style="width:20px;height:20px"> Send the updates as voice notes too</label>
            <h4 style="margin:14px 0 6px">Phone calls (Twilio)</h4>
            <div class="field"><label class="label" for="twilio_sid">Account SID (Twilio console home page, starts with AC)</label><input class="input" id="twilio_sid" name="twilio_sid" value="<?= e((string)setting('twilio_sid', '')) ?>" placeholder="AC..."></div>
            <div class="field"><label class="label" for="twilio_auth_token">Auth Token (Twilio console home page)</label><?= secret_setting_input('twilio_auth_token') ?></div>
            <div class="field"><label class="label" for="twilio_from">Twilio phone number it calls from</label><input class="input" id="twilio_from" name="twilio_from" value="<?= e((string)setting('twilio_from', '')) ?>" placeholder="+1..."></div>
            <div class="field"><label class="label" for="brain_call_to">Your phone (it calls this number)</label><input class="input" id="brain_call_to" name="brain_call_to" value="<?= e((string)setting('brain_call_to', '')) ?>" placeholder="097 504 0243"></div>
            <div class="field"><label class="label" for="brain_call_max">Most calls a day</label><input class="input" type="number" min="1" max="10" id="brain_call_max" name="brain_call_max" value="<?= (int)setting('brain_call_max', '3') ?>" style="max-width:100px"></div>
            <label class="field" style="display:flex;gap:10px;align-items:center"><input type="checkbox" name="brain_calls_on" value="1" <?= setting('brain_calls_on', '1') === '1' ? 'checked' : '' ?> style="width:20px;height:20px"> Call me when something needs me now</label>
            <button class="btn btn--primary" type="submit">Save</button>
        </form>
        <div class="flex flex-wrap mt-2" style="gap:8px">
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_voice"><button class="btn btn--ghost btn--sm" type="submit">Send me a test voice note</button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="test_call"><button class="btn btn--ghost btn--sm" type="submit">Call me now to test</button></form>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="brief_call"><button class="btn btn--primary btn--sm" type="submit">Call me with a briefing now</button></form>
        </div>
        <form method="post" class="flex flex-wrap mt-2" style="gap:8px;align-items:center"><?= csrf_field() ?><input type="hidden" name="action" value="brief_later">
            <span class="small">Call me with a briefing in</span><input class="input input--sm" type="number" name="minutes" min="1" max="600" value="10" style="width:80px"><span class="small">minutes, and every day at</span>
            <input class="input input--sm" type="time" name="daily" value="<?= e((string)setting('brain_brief_daily', '')) ?>" style="width:120px"><button class="btn btn--ghost btn--sm" type="submit">Schedule</button>
        </form>
        <p class="small muted" style="margin-top:6px">From your phone you can also type <b>/call</b> to the Telegram bot (or <b>/call 10</b> for 10 minutes from now). It works with your computer off.</p>
        <div>
        </div>
    </div></div>

    <div class="panel"><div class="panel__body">
        <h3>Which alerts to send</h3>
        <form method="post" class="tg-alerts"><?= csrf_field() ?><input type="hidden" name="action" value="save_alerts">
            <p class="small muted" style="margin:0 0 6px">"In a summary" sends one message every few hours instead of one message each time. Good for busy alerts like sign-ups.</p>
            <?php $sumKinds = tg_summary_kinds(); foreach (tg_alert_kinds() as $k => $lab): ?>
                <div class="tg-alert-row"><label><input type="checkbox" name="on[<?= e($k) ?>]" value="1" <?= tg_kind_on($k) ? 'checked' : '' ?>> <?= e($lab) ?></label>
                <?php if ($k !== 'daily'): ?><label class="small"><input type="checkbox" name="sum[<?= e($k) ?>]" value="1" <?= in_array($k, $sumKinds, true) ? 'checked' : '' ?>> In a summary</label><?php endif; ?></div>
            <?php endforeach; ?>
            <label class="small" style="margin-top:8px">Send the summary every <input class="input input--sm" type="number" name="summary_hours" min="1" max="24" value="<?= tg_summary_hours() ?>" style="width:70px;display:inline-block"> hours</label>
            <button class="btn btn--primary btn--sm mt-2" type="submit">Save choices</button>
        </form>
    </div></div>

    <div class="panel"><div class="panel__body">
        <h3>What you can type in Telegram</h3>
        <ul class="small">
            <li><b>/pending</b>: everything waiting for you, with buttons</li>
            <li><b>/today</b> or <b>/week</b>: sign-ups, tests, clips and money</li>
            <li>Reply to an alert with your own words, e.g. "reject, not her own video"</li>
            <li>Money is never moved without you tapping <b>Confirm</b></li>
        </ul>
    </div></div>

    <?php if ($log): ?>
    <div class="panel"><div class="panel__head"><h3>Done from Telegram</h3></div><div class="panel__body panel__body--flush"><div class="table-wrap"><table class="table">
        <thead><tr><th>When</th><th>What</th><th>Detail</th></tr></thead><tbody>
        <?php foreach ($log as $l): ?><tr><td class="small muted"><?= e(time_ago($l['created_at'])) ?></td><td class="small"><?= e($l['what']) ?></td><td class="small"><?= e((string)$l['detail']) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></div></div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
