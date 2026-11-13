<?php
/**
 * Telegram calls this address for every message or button tap sent to the HPL bot.
 * It checks the secret Telegram was given, answers at once, then does the work.
 * Only the connected owner chat can give orders.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/telegram.php';

$secret = (string)setting('tg_webhook_secret', '');
if ($secret === '' || !hash_equals($secret, (string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''))) {
    http_response_code(404);
    exit;
}
$update = json_decode((string)file_get_contents('php://input'), true);
// Answer Telegram straight away so it does not resend while we work.
http_response_code(200);
header('Content-Type: application/json');
echo '{}';
if (session_status() === PHP_SESSION_ACTIVE) { @session_write_close(); }
if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
ignore_user_abort(true);
@set_time_limit(120);
if (!is_array($update)) exit;

// Each update is handled once, even if Telegram sends it again.
$uid = (int)($update['update_id'] ?? 0);
try {
    if ($uid && q('INSERT IGNORE INTO tg_updates (update_id) VALUES (?)', [$uid])->rowCount() !== 1) exit;
} catch (Throwable $e) {
}

try {
    if (isset($update['callback_query'])) {
        tg_handle_button($update['callback_query']);
    } elseif (isset($update['message'])) {
        tg_handle_message($update['message']);
    }
} catch (Throwable $e) {
    error_log('HPL telegram: ' . $e->getMessage());
    if (tg_ready()) tg_send('⚠️ Something went wrong: ' . tg_h($e->getMessage()));
}
exit;

function tg_is_owner(array $from): bool
{
    return tg_owner() !== '' && (string)($from['id'] ?? '') === tg_owner();
}

function tg_handle_message(array $m): void
{
    $chatId = (string)($m['chat']['id'] ?? '');
    $text = trim((string)($m['text'] ?? ''));
    $from = (array)($m['from'] ?? []);

    // Connecting: /start <one-time code> from the link on Admin → Telegram assistant.
    if (preg_match('~^/start\s+(\S+)~', $text, $mm)) {
        $code = (string)setting('tg_link_code', '');
        $exp = (int)setting('tg_link_expires', 0);
        if ($code !== '' && hash_equals($code, $mm[1]) && $exp > time() && ($m['chat']['type'] ?? '') === 'private') {
            set_setting('tg_owner_chat_id', $chatId);
            set_setting('tg_owner_name', trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')));
            set_setting('tg_link_code', '');
            tg_log('owner connected', $chatId);
            tg_send("✅ <b>Connected.</b> You will get HPL Creators alerts here.\n\nTap the buttons under an alert, or just type what you want, e.g. <i>approve Chanda</i> or <i>how many sign-ups today?</i>\n\nCommands: /pending (what is waiting) · /today (numbers) · /help");
        } else {
            tg_api('sendMessage', ['chat_id' => $chatId, 'text' => 'This link has expired or is not valid. Open Admin → Telegram assistant on the website and tap Connect again.']);
        }
        return;
    }
    if (!tg_is_owner($from) || $chatId !== tg_owner()) {
        tg_api('sendMessage', ['chat_id' => $chatId, 'text' => 'This is a private bot for the HPL Creators team.']);
        return;
    }

    if ($text === '' ) return;
    if ($text === '/help' || $text === '/start') {
        tg_send("<b>HPL Creators assistant</b>\nTap buttons under alerts, or type instructions like:\n• approve the last work video\n• reject Chanda's page, the code is not in her bio\n• who is waiting for payment?\n• remind everyone who has not taken the test\n\n/pending · /today · /week · /new (fresh conversation)\n/call: the Brain phones you with a briefing now · /call 10: in 10 minutes");
        return;
    }
    if (preg_match('~^/call(?:\s+(\d{1,3}))?$~', $text, $cm)) { // the Brain rings the owner with a briefing (now, or in N minutes)
        require_once __DIR__ . '/includes/brain_call.php';
        if (!brain_call_ready()) { tg_send('Phone calls are not set up yet (Admin > Telegram and AI help).'); return; }
        $mins = isset($cm[1]) ? (int)$cm[1] : 0;
        $at = brain_brief_schedule($mins);
        tg_send($mins > 0 ? '📞 OK. I will call you at about ' . date('H:i', $at) . ' and tell you how the app is doing.' : '📞 Calling you in a moment with a briefing.');
        if ($mins === 0) { brain_brief_due(); }
        return;
    }
    if ($text === '/today' || $text === '/week') { tg_send(tg_stats_text(tg_stats($text === '/week' ? 'week' : 'today'))); return; }
    if ($text === '/pending') { tg_send_pending(); return; }
    if ($text === '/cancel') { tg_set_state([]); tg_send('Cancelled.'); return; }
    if ($text === '/new') { tg_set_state([]); tg_hist_clear(); tg_send('New conversation started.'); return; }

    // Waiting for a reason or a reference after a button tap?
    $st = tg_state();
    if (!empty($st['await'])) {
        tg_set_state([]);
        tg_finish_await($st, $text);
        return;
    }

    // Anything else: Claude reads it and acts.
    $ctx = '';
    if (!empty($m['reply_to_message']['message_id'])) {
        $ctx = (string)fetch_val('SELECT text FROM tg_alerts WHERE message_id = ? ORDER BY id DESC LIMIT 1', [(int)$m['reply_to_message']['message_id']], '');
        if ($ctx === '') $ctx = (string)($m['reply_to_message']['text'] ?? '');
    }
    tg_api('sendChatAction', ['chat_id' => tg_owner(), 'action' => 'typing']);
    tg_send(tg_h(tg_ai_handle($text, $ctx)));
}

function tg_send_pending(): void
{
    $items = tg_pending_items(10);
    if (!$items) { tg_send('✅ Nothing is waiting for you.'); return; }
    tg_send('<b>' . count($items) . ' item(s) waiting for you</b>:');
    $map = ['work' => ['w', '🎬 Work video'], 'page' => ['p', '🛡 Page'], 'clip' => ['c', '🎞 Clip'], 'withdrawal' => ['d', '💸 Withdrawal']];
    foreach (array_slice($items, 0, 10) as $it) {
        [$k, $label] = $map[$it['type']];
        $yes = $it['type'] === 'withdrawal' ? '✅ I paid it' : ($it['type'] === 'page' ? '✅ Verified' : '✅ Approve');
        $h = $label . ': <b>' . tg_h($it['creator']) . '</b>' . "\n" . tg_h($it['detail']);
        $mid = tg_send($h, [[[$yes, $k . ':' . ($it['type'] === 'withdrawal' ? 'p' : 'a') . ':' . $it['id']], ['❌ Reject', $k . ':r:' . $it['id']]]]);
        try { q('INSERT INTO tg_alerts (ref_type, ref_id, text, message_id) VALUES (?, ?, ?, ?)', [$it['type'], $it['id'], $h, $mid]); } catch (Throwable $e) {}
    }
}

function tg_handle_button(array $cb): void
{
    $from = (array)($cb['from'] ?? []);
    if (!tg_is_owner($from)) {
        tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id'], 'text' => 'Not allowed.']);
        return;
    }
    $data = (string)($cb['data'] ?? '');
    $msg = (array)($cb['message'] ?? []);
    $mid = (int)($msg['message_id'] ?? 0);
    $orig = tg_h((string)($msg['text'] ?? ''));
    tg_api('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
    $p = explode(':', $data);
    $k = $p[0] ?? ''; $act = $p[1] ?? ''; $id = (int)($p[2] ?? 0);

    if ($k === 'm' && $act === 'pending') { tg_send_pending(); return; }

    $res = null;
    switch ($k . ':' . $act) {
        case 'w:a': tg_set_state([]); $res = tg_do_work($id, true); break;
        case 'p:a': tg_set_state([]); $res = tg_do_page($id, true); break;
        case 'c:a': tg_set_state([]); $res = tg_do_clip($id, true); break;
        case 'w:r':
            tg_set_state(['await' => 'work_reason', 'id' => $id, 'mid' => $mid]);
            tg_send('Why is it rejected? Tap a reason or type your own (the creator will read it):', [
                [['Too long', 'q:w:' . $id . ':long'], ['Not edited', 'q:w:' . $id . ':edit']],
                [['Not their own video', 'q:w:' . $id . ':own'], ['Poor quality', 'q:w:' . $id . ':quality']]]);
            return;
        case 'p:r':
            tg_set_state(['await' => 'page_reason', 'id' => $id, 'mid' => $mid]);
            tg_send('Why is the page rejected? Tap a reason or type your own:', [
                [['Code not in bio', 'q:p:' . $id . ':code'], ['Private page', 'q:p:' . $id . ':private']],
                [['Not their page', 'q:p:' . $id . ':not_owner'], ['Wrong link', 'q:p:' . $id . ':link']]]);
            return;
        case 'c:r':
            tg_set_state(['await' => 'clip_reason', 'id' => $id, 'mid' => $mid]);
            tg_send('Why is the clip rejected? Type a short reason (or /cancel):', [[['Not HPL footage', 'q:c:' . $id . ':footage'], ['Re-upload / copied', 'q:c:' . $id . ':copy']]]);
            return;
        case 'd:p':
            tg_set_state(['await' => 'wd_ref', 'id' => $id, 'mid' => $mid]);
            tg_send('Type the mobile money <b>transaction reference</b> for this payment (or /cancel):');
            return;
        case 'd:r':
            tg_set_state(['await' => 'wd_reject', 'id' => $id, 'mid' => $mid]);
            tg_send('Why is the withdrawal rejected? Type a short reason (or /cancel):');
            return;
        case 'x:y':
            $st = tg_state(); tg_set_state([]);
            // The button must belong to the confirm that is waiting (same id and same code),
            // so an old Confirm button can never run a different action.
            if (($st['id'] ?? 0) !== $id || empty($st['confirm']) || !hash_equals((string)($st['code'] ?? ''), (string)($p[3] ?? ''))) {
                tg_send('That confirmation has expired. Nothing changed.');
                if ($mid) tg_mark_done($mid, $orig, 'Expired, nothing changed');
                return;
            }
            switch ($st['confirm']) {
                case 'paid': $res = tg_do_withdrawal_paid($id, (string)$st['val']); break;
                case 'reject': $res = tg_do_withdrawal_reject($id, (string)$st['val']); break;
                case 'clip_approve': $res = tg_do_clip($id, true); break;
                case 'clip_reject': $res = tg_do_clip($id, false, (string)$st['val']); break;
                default: tg_send('That confirmation has expired. Nothing changed.'); return;
            }
            $amid = (int)($st['mid'] ?? 0);
            if ($amid && $res['ok']) {
                $t = (string)fetch_val('SELECT text FROM tg_alerts WHERE message_id = ? ORDER BY id DESC LIMIT 1', [$amid], '');
                if ($t !== '') tg_mark_done($amid, $t, ['paid' => '✅ Paid', 'clip_approve' => '✅ Approved'][$st['confirm']] ?? '❌ Rejected');
            }
            if ($mid) tg_mark_done($mid, $orig, $res['ok'] ? '✅ Confirmed' : '⚠️ Not done');
            break;
        case 'x:n':
            tg_set_state([]);
            if ($mid) tg_mark_done($mid, $orig, 'Cancelled, nothing changed');
            return;
        case 'q:w':
        case 'q:p':
        case 'q:c':
            $st = tg_state(); tg_set_state([]);
            $quick = [
                'w' => ['long' => 'Your video is too long. Send a clip of 15 to 60 seconds.', 'edit' => 'The video is not edited. Cut the best moment, add text and send it again.', 'own' => 'This does not look like your own work. Send a video you edited yourself.', 'quality' => 'The video quality is too low. Send a clear, sharp video.'],
                'c' => ['footage' => 'This clip does not use HPL campaign footage.', 'copy' => 'This clip is a re-upload or copy of another video.'],
            ];
            $why = $p[3] ?? '';
            if ($k === 'q' && $act === 'p') { $res = tg_do_page($id, false, $why, ''); }
            elseif ($act === 'w') { $res = tg_do_work($id, false, $quick['w'][$why] ?? ''); }
            else { $res = tg_do_clip($id, false, $quick['c'][$why] ?? ''); }
            $amid = (int)($st['mid'] ?? 0);
            if ($amid && $res['ok']) {
                $t = (string)fetch_val('SELECT text FROM tg_alerts WHERE message_id = ? ORDER BY id DESC LIMIT 1', [$amid], '');
                if ($t !== '') tg_mark_done($amid, $t, '❌ Rejected');
            }
            if ($mid) tg_api('editMessageReplyMarkup', ['chat_id' => tg_owner(), 'message_id' => $mid, 'reply_markup' => ['inline_keyboard' => []]]);
            tg_send(($res['ok'] ? '✅ ' : '⚠️ ') . tg_h($res['msg']));
            return;
    }
    if ($res !== null) {
        if ($res['ok'] && $mid && in_array($k, ['w', 'p', 'c'], true)) {
            $t = (string)fetch_val('SELECT text FROM tg_alerts WHERE message_id = ? ORDER BY id DESC LIMIT 1', [$mid], '');
            tg_mark_done($mid, $t !== '' ? $t : $orig, $k === 'p' ? '✅ Verified' : '✅ Approved');
        }
        tg_send(($res['ok'] ? '✅ ' : '⚠️ ') . tg_h($res['msg']));
    }
}

/** A typed reason or reference after a button asked for one. */
function tg_finish_await(array $st, string $text): void
{
    $id = (int)$st['id'];
    $amid = (int)($st['mid'] ?? 0);
    switch ($st['await']) {
        case 'work_reason': $res = tg_do_work($id, false, $text); break;
        case 'page_reason': $res = tg_do_page($id, false, 'other', $text); break;
        case 'clip_reason': $res = tg_do_clip($id, false, $text); break;
        case 'wd_ref':
        case 'wd_reject':
            $w = fetch_one('SELECT w.*, u.full_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$id]);
            if (!$w || $w['status'] !== 'pending') { tg_send('That withdrawal is no longer waiting. Nothing changed.'); return; }
            $paid = $st['await'] === 'wd_ref';
            $cst = tg_set_state(['confirm' => $paid ? 'paid' : 'reject', 'id' => $id, 'val' => $text, 'mid' => $amid]);
            tg_send(($paid ? '💸 Confirm: <b>' . tg_h(money($w['amount'])) . '</b> paid to ' . tg_h($w['full_name']) . ', reference <b>' . tg_h($text) . '</b>?'
                : '❌ Confirm: reject ' . tg_h($w['full_name']) . "'s " . tg_h(money($w['amount'])) . ' request? Reason: ' . tg_h($text)),
                tg_confirm_buttons($cst));
            return;
        default: return;
    }
    if ($amid && $res['ok']) {
        $t = (string)fetch_val('SELECT text FROM tg_alerts WHERE message_id = ? ORDER BY id DESC LIMIT 1', [$amid], '');
        if ($t !== '') tg_mark_done($amid, $t, '❌ Rejected');
    }
    tg_send(($res['ok'] ? '✅ ' : '⚠️ ') . tg_h($res['msg']));
}
