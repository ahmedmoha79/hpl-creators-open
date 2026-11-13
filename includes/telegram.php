<?php
/**
 * HPL Telegram assistant (2026-10-31).
 *
 * - Alerts: things that need the owner (new work, pages to verify, clips, withdrawals, messages)
 *   are sent to the owner's Telegram with buttons. Sent after the page has gone to the visitor.
 * - Buttons act on the site straight away (approve, reject with a reason, mark a payout paid).
 * - Typed replies are read by Claude (when an Anthropic key is saved), which may only use the
 *   actions listed in tg_ai_tools(). Anything that moves money asks "Confirm?" first.
 * Only the connected owner chat can give orders. Every action is logged in tg_log.
 */

require_once __DIR__ . '/earnings.php';
require_once __DIR__ . '/social_pages.php';

// ---------------------------------------------------------------------------------------------
// Settings and low-level API
// ---------------------------------------------------------------------------------------------

function tg_token(): string { return trim((string)setting('tg_bot_token', '')); }
function tg_owner(): string { return trim((string)setting('tg_owner_chat_id', '')); }
function tg_ready(): bool { return tg_token() !== '' && tg_owner() !== ''; }
function tg_ai_key(): string { return trim((string)setting('anthropic_api_key', '')); }
function tg_ai_model(): string { return trim((string)setting('anthropic_model', '')) ?: 'claude-haiku-5-5'; }

/** Which kinds of alert are switched on (all on unless switched off in admin/telegram.php). */
function tg_alert_kinds(): array
{
    return [
        'signup'     => 'New sign-ups',
        'work'       => 'Work videos to approve',
        'page'       => 'Social pages to verify',
        'clip'       => 'Campaign clips to check',
        'withdrawal' => 'Withdrawal requests',
        'message'    => 'Messages from creators',
        'feedback'   => 'Feedback and Academy questions',
        'daily'      => 'Evening summary (8 pm)',
        'brain'      => 'Brain: updates 4 times a day and alerts when something serious starts',
    ];
}
function tg_kind_on(string $kind): bool { return setting('tg_off_' . $kind, '0') !== '1'; }

/**
 * Busy alerts go into one summary instead of one message each (owner, 2026-11-11, launch ads bring many sign-ups).
 * Default: sign-ups, campaign clips, messages and feedback. Withdrawals, work videos and pages stay instant.
 */
function tg_summary_kinds(): array
{
    $raw = setting('tg_summary_kinds', null);
    return $raw === null ? ['signup', 'clip', 'message', 'feedback'] : array_values(array_filter(explode(',', (string)$raw)));
}
function tg_summary_hours(): int { return max(1, min(24, (int)setting('tg_summary_hours', '3'))); }

/** Keep a busy alert for the next summary (no Telegram message now). */
function tg_summary_keep(string $kind, string $html, string $refType, int $refId): void
{
    try {
        q('INSERT INTO tg_alerts (ref_type, ref_id, text, message_id) VALUES (?, ?, ?, NULL)', ['wait:' . $kind, $refId, mb_substr(strip_tags($html), 0, 600)]);
    } catch (Throwable $e) {
    }
}

/** Send the summary of kept alerts, at most once every tg_summary_hours() (run by the background sync). */
function tg_summary_maybe(bool $force = false): bool
{
    try {
        if (!tg_ready()) return false;
        $last = (string)setting('tg_summary_last', '');
        if ($last === '' && setting('tg_summary_last', null) === null) {
            q('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ("tg_summary_last", "")');
        }
        if (!$force && $last !== '' && time() - (int)$last < tg_summary_hours() * 3600) return false;
        $rows = fetch_all('SELECT id, ref_type, text FROM tg_alerts WHERE ref_type LIKE "wait:%" ORDER BY id LIMIT 2000');
        if (!$rows) return false;
        if (q('UPDATE settings SET setting_value = ? WHERE setting_key = "tg_summary_last" AND setting_value = ?', [(string)time(), $last])->rowCount() !== 1) return false;
        $by = [];
        foreach ($rows as $r) { $by[substr((string)$r['ref_type'], 5)][] = (string)$r['text']; }
        $labels = ['signup' => '🆕 New sign-ups', 'clip' => '🎞 Campaign clips sent', 'message' => '💬 Messages from creators', 'feedback' => '📝 Feedback and questions',
                   'work' => '🎬 Work videos', 'page' => '🛡 Pages to verify', 'withdrawal' => '💸 Withdrawal requests'];
        $since = $last !== '' ? date('H:i', (int)$last) : '';
        $h = '<b>Summary' . ($since !== '' ? ' since ' . $since : '') . '</b>';
        foreach ($by as $k => $list) {
            $h .= "\n\n" . ($labels[$k] ?? ucfirst($k)) . ': <b>' . count($list) . '</b>';
            foreach (array_slice(array_reverse($list), 0, 3) as $t) {
                $ln = array_values(array_filter(array_map('trim', explode("\n", $t)), 'strlen'));
                $l0 = $ln[0] ?? '';
                $after = strpos($l0, ':') !== false ? trim(substr($l0, strpos($l0, ':') + 1)) : '';
                $first = $after !== '' ? $after : ($k === 'clip' && isset($ln[1]) ? $ln[1] : trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', $l0)));
                $h .= "\n• " . tg_h(mb_substr($first, 0, 70));
            }
            if (count($list) > 3) { $h .= "\n• and " . (count($list) - 3) . ' more'; }
        }
        $today = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND DATE(created_at) = CURDATE()', [], 0);
        $h .= "\n\nSign-ups today: <b>" . $today . '</b>';
        tg_send($h, [[['See what is waiting', 'm:pending']]]);
        $maxId = (int)end($rows)['id'];
        q('DELETE FROM tg_alerts WHERE ref_type LIKE "wait:%" AND id <= ?', [$maxId]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Call the Telegram Bot API. Returns the decoded reply, or null when it could not be reached. */
function tg_api(string $method, array $params = [], int $timeout = 8): ?array
{
    $tok = tg_token();
    if ($tok === '' || !function_exists('curl_init')) {
        return null;
    }
    if (getenv('HPL_TG_FAKE')) { // local tests only: record the call instead of sending it
        file_put_contents(getenv('HPL_TG_FAKE'), json_encode([$method, $params]) . "\n", FILE_APPEND);
        static $n = 100;
        return ['ok' => true, 'result' => $method === 'getMe' ? ['username' => 'hpl_test_bot'] : ['message_id' => ++$n, 'url' => 'x']];
    }
    $ch = curl_init('https://api.telegram.org/bot' . $tok . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $d = json_decode((string)$raw, true);
    return is_array($d) ? $d : null;
}

/** Plain text safe for Telegram HTML mode. */
function tg_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/** Send a message to the owner. $buttons = rows of [label, callback data or https URL]. Returns message_id. */
function tg_send(string $html, array $buttons = [], ?int $replyTo = null): ?int
{
    if (!tg_ready()) {
        return null;
    }
    $p = ['chat_id' => tg_owner(), 'text' => mb_substr($html, 0, 4000), 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true]];
    // A video link (TikTok, Instagram, YouTube, Facebook) shows as a big preview you can play inside Telegram.
    if (preg_match('~https?://(?:www\.|m\.|vm\.|vt\.)?(?:tiktok\.com|instagram\.com|youtube\.com|youtu\.be|facebook\.com|fb\.watch)/[^\s<"]+~i', html_entity_decode($html), $vm)) {
        $p['link_preview_options'] = ['url' => $vm[0], 'prefer_large_media' => true, 'show_above_text' => false];
    }
    if ($buttons) {
        $p['reply_markup'] = ['inline_keyboard' => tg_keyboard($buttons)];
    }
    if ($replyTo) {
        $p['reply_parameters'] = ['message_id' => $replyTo, 'allow_sending_without_reply' => true];
    }
    $r = tg_api('sendMessage', $p);
    return ($r && !empty($r['ok'])) ? (int)$r['result']['message_id'] : null;
}

function tg_keyboard(array $rows): array
{
    $kb = [];
    foreach ($rows as $row) {
        $line = [];
        foreach ($row as [$label, $val]) {
            $line[] = preg_match('~^https?://~', $val) ? ['text' => $label, 'url' => $val] : ['text' => $label, 'callback_data' => substr($val, 0, 64)];
        }
        $kb[] = $line;
    }
    return $kb;
}

/** Replace an alert's buttons with a one-line result, so it is clear it was handled. */
function tg_mark_done(int $messageId, string $originalHtml, string $result): void
{
    tg_api('editMessageText', [
        'chat_id' => tg_owner(), 'message_id' => $messageId, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true,
        'text' => mb_substr($originalHtml . "\n\n<b>" . tg_h($result) . '</b> · ' . date('H:i'), 0, 4000),
    ]);
}

/** Write to the action log (what was done, from which channel). */
function tg_log(string $what, string $detail = ''): void
{
    try {
        q('INSERT INTO tg_log (what, detail) VALUES (?, ?)', [mb_substr($what, 0, 120), mb_substr($detail, 0, 1000)]);
    } catch (Throwable $e) {
    }
}

/** The admin account actions are recorded under. */
function tg_admin_id(): int
{
    return (int)fetch_val('SELECT id FROM users WHERE role = "admin" AND status = "active" ORDER BY id LIMIT 1', [], 0);
}

// ---------------------------------------------------------------------------------------------
// Alerts (queued during the request, sent after the visitor's page has gone)
// ---------------------------------------------------------------------------------------------

/**
 * Queue an alert. $kind = one of tg_alert_kinds(); $refType/$refId say what it is about
 * (work, page, clip, withdrawal) so buttons and typed replies can act on it.
 */
function tg_alert(string $kind, string $html, array $buttons = [], string $refType = '', int $refId = 0): void
{
    static $queue = null;
    // Asked for on purpose (show_item): send now, whatever the alert switches say.
    if (!empty($GLOBALS['tg_alert_now'])) {
        if (!tg_ready()) return;
        $html = (string)($GLOBALS['tg_alert_prefix'] ?? '') . $html;
        $mid = tg_send($html, $buttons);
        try { q('INSERT INTO tg_alerts (ref_type, ref_id, text, message_id) VALUES (?, ?, ?, ?)', [$refType, $refId, mb_substr($html, 0, 3000), $mid]); } catch (Throwable $e) {}
        return;
    }
    try {
        if (!tg_ready() || !tg_kind_on($kind)) {
            return;
        }
        if (in_array($kind, tg_summary_kinds(), true)) {
            tg_summary_keep($kind, $html, $refType, $refId);
            return;
        }
    } catch (Throwable $e) {
        return;
    }
    if ($queue === null) {
        $queue = [];
        register_shutdown_function(function () use (&$queue) {
            if (!$queue) {
                return;
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                @session_write_close();
            }
            if (function_exists('fastcgi_finish_request')) {
                @fastcgi_finish_request();
            }
            foreach ($queue as [$h, $b, $t, $i]) {
                try {
                    $mid = tg_send($h, $b);
                    q('INSERT INTO tg_alerts (ref_type, ref_id, text, message_id) VALUES (?, ?, ?, ?)', [$t, $i, mb_substr($h, 0, 3000), $mid]);
                } catch (Throwable $e) {
                }
            }
        });
    }
    $queue[] = [$html, $buttons, $refType, $refId];
}

/** Called by notif_admins(): turns every admin notification into a Telegram alert. */
function tg_from_notification(string $type, string $title, string $body, string $url, array $ref = []): void
{
    $link = $url !== '' ? abs_url($url) : '';
    $rt = (string)($ref['type'] ?? '');
    $rid = (int)($ref['id'] ?? 0);
    if ($type === 'portfolio' && $rt === 'work' && $rid) {
        $pl = fetch_one('SELECT p.*, u.full_name, u.phone FROM portfolio_links p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$rid]);
        if (!$pl) return;
        $h = "🎬 <b>New work video to approve</b>\n" . tg_h($pl['full_name']) . ' · ' . tg_h(platform_label($pl['platform']))
            . "\n" . tg_h($pl['url']) . ($pl['note'] ? "\nNote: " . tg_h($pl['note']) : '');
        tg_alert('work', $h, [[['✅ Approve', 'w:a:' . $rid], ['❌ Reject', 'w:r:' . $rid]], [['▶️ Watch video', $pl['url']]]], 'work', $rid);
        return;
    }
    if ($type === 'social' && $rt === 'page' && $rid) {
        $sp = fetch_one('SELECT s.*, u.full_name FROM social_pages s JOIN users u ON u.id = s.user_id WHERE s.id = ?', [$rid]);
        if (!$sp) return;
        $code = hpl_social_code($sp);
        $h = "🛡 <b>Page to verify</b>\n" . tg_h($sp['full_name']) . ' · ' . tg_h(platform_label($sp['platform'])) . "\n" . tg_h($sp['url'])
            . "\nCheck the bio shows <b>" . tg_h($code) . '</b>';
        tg_alert('page', $h, [[['✅ Verified', 'p:a:' . $rid], ['❌ Reject', 'p:r:' . $rid]], [['🔗 Open page', $sp['url']]]], 'page', $rid);
        return;
    }
    if ($type === 'withdrawal' && $rt === 'withdrawal' && $rid) {
        $w = fetch_one('SELECT w.*, u.full_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$rid]);
        if (!$w) return;
        $h = "💸 <b>Withdrawal request: " . tg_h(money($w['amount'])) . "</b>\n" . tg_h($w['full_name'])
            . "\nPay to: " . tg_h((string)$w['method']) . ' · ' . tg_h((string)($w['paid_to_number'] ?: $w['account_details']))
            . "\nSend the money first, then tap <b>I paid it</b>.";
        tg_alert('withdrawal', $h, [[['✅ I paid it', 'd:p:' . $rid], ['❌ Reject', 'd:r:' . $rid]]], 'withdrawal', $rid);
        return;
    }
    if ($type === 'payout_dispute') {
        tg_alert('withdrawal', '⚠️ <b>' . tg_h($title) . '</b>' . ($body !== '' ? "\n" . tg_h($body) : ''), $link ? [[['Open', $link]]] : []);
        return;
    }
    $kind = in_array($type, ['message'], true) ? 'message' : (in_array($type, ['feedback', 'comment'], true) ? 'feedback' : ($type === 'social' ? 'page' : 'message'));
    $icon = ['message' => '💬', 'feedback' => '📝', 'comment' => '❓', 'verification' => '⚠️', 'social' => '🛡'][$type] ?? '🔔';
    tg_alert($kind, $icon . ' <b>' . tg_h($title) . '</b>' . ($body !== '' ? "\n" . tg_h($body) : ''), $link ? [[['Open', $link]]] : []);
}

/** New sign-up alert. */
function tg_alert_signup(int $uid): void
{
    $u = fetch_one('SELECT * FROM users WHERE id = ?', [$uid]);
    if (!$u) return;
    require_once __DIR__ . '/signup_source.php';
    $src = signup_source_line($u);
    $today = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND DATE(created_at) = CURDATE()', [], 0);
    tg_alert('signup', '🆕 <b>New sign-up:</b> ' . tg_h($u['full_name']) . "\n" . tg_h((string)$u['phone']) . ' · ' . tg_h((string)$u['email'])
        . ($src !== '' ? "\nHeard from: " . tg_h($src) : '')
        . "\nSign-ups today: <b>" . $today . '</b>');
}

/** New campaign clip alert. */
function tg_alert_clip(int $subId): void
{
    $s = fetch_one('SELECT s.*, u.full_name, c.title FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id WHERE s.id = ?', [$subId]);
    if (!$s) return;
    $h = "🎞 <b>New campaign clip</b>\n" . tg_h($s['full_name']) . ' · ' . tg_h($s['title']) . ' · ' . tg_h(platform_label($s['platform']))
        . "\n" . tg_h($s['url']) . "\nIts money stays locked until you approve it.";
    tg_alert('clip', $h, [[['✅ Approve', 'c:a:' . $subId], ['❌ Reject', 'c:r:' . $subId]], [['▶️ Watch clip', $s['url']]]], 'clip', $subId);
}

// ---------------------------------------------------------------------------------------------
// Actions (the only things buttons and Claude can do)
// ---------------------------------------------------------------------------------------------

function tg_do_work(int $pid, bool $approve, string $note = ''): array
{
    $link = fetch_one('SELECT p.*, u.full_name FROM portfolio_links p JOIN users u ON u.id = p.user_id WHERE p.id = ?', [$pid]);
    if (!$link) return ['ok' => false, 'msg' => 'That work video no longer exists.'];
    if ($link['status'] !== 'pending') return ['ok' => false, 'msg' => 'That work video is already ' . $link['status'] . '. Nothing changed.'];
    $uid = (int)$link['user_id'];
    if ($approve) {
        q('UPDATE portfolio_links SET status = "approved", admin_note = ? WHERE id = ?', [$note, $pid]);
        q('UPDATE users SET qualified = 1, portfolio_status = "approved", qualified_at = NOW(), rejection_note = NULL WHERE id = ?', [$uid]);
        creator_decision_notify($uid, true);
        tg_log('work approved', $link['full_name'] . ' #' . $pid);
        return ['ok' => true, 'msg' => $link['full_name'] . ' is approved and was told by notification and email.'];
    }
    $note = trim($note) !== '' ? trim($note) : 'Please send a short video (15 to 60 seconds) that you edited yourself, showing the most exciting moment.';
    q('UPDATE portfolio_links SET status = "rejected", admin_note = ? WHERE id = ?', [$note, $pid]);
    $told = portfolio_after_reject($uid, $note);
    tg_log('work rejected', $link['full_name'] . ' #' . $pid . ': ' . $note);
    return ['ok' => true, 'msg' => $told ? $link['full_name'] . ' was told: ' . $note : 'Video rejected. ' . $link['full_name'] . ' was already approved, so they stay approved.'];
}

function tg_do_page(int $id, bool $approve, string $reasonKey = 'other', string $extra = ''): array
{
    $r = $approve ? sp_approve($id, tg_admin_id(), 'Verified via Telegram') : sp_reject($id, tg_admin_id(), $reasonKey, $extra);
    tg_log($approve ? 'page verified' : 'page rejected', '#' . $id . ' ' . ($r['msg'] ?? ''));
    return ['ok' => !empty($r['ok']), 'msg' => (string)($r['msg'] ?? '')];
}

function tg_do_clip(int $sid, bool $approve, string $reason = ''): array
{
    $s = fetch_one('SELECT s.*, u.full_name, c.title FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id WHERE s.id = ?', [$sid]);
    if (!$s) return ['ok' => false, 'msg' => 'That clip no longer exists.'];
    if ($s['status'] !== 'pending') return ['ok' => false, 'msg' => 'That clip is already ' . $s['status'] . '. Change it on the website if needed.'];
    if (!campaign_submission_set_status($sid, $approve ? 'approved' : 'rejected', $reason)) {
        return ['ok' => false, 'msg' => 'Could not change that clip.'];
    }
    $earned = (float)fetch_val('SELECT earned FROM campaign_submissions WHERE id = ?', [$sid], 0);
    tg_log($approve ? 'clip approved' : 'clip rejected', $s['full_name'] . ' #' . $sid . ($reason ? ': ' . $reason : ''));
    return ['ok' => true, 'msg' => $s['full_name'] . "'s clip " . ($approve ? 'approved' . ($earned > 0 ? ' (' . money($earned) . ' unlocked)' : '') : 'rejected' . campaign_debt_note($sid)) . '.'];
}

/** Mark a withdrawal paid (re-checks the creator still has the money, like the admin page). */
function tg_do_withdrawal_paid(int $id, string $txn): array
{
    $w = fetch_one('SELECT w.*, u.full_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$id]);
    if (!$w) return ['ok' => false, 'msg' => 'That withdrawal no longer exists.'];
    if ($w['status'] !== 'pending') return ['ok' => false, 'msg' => 'That withdrawal is already ' . $w['status'] . '.'];
    $txn = mb_substr(trim($txn), 0, 100);
    if ($txn === '') return ['ok' => false, 'msg' => 'A transaction reference is needed.'];
    $provider = (string)($w['payment_provider'] ?: 'mtn');
    if (!array_key_exists($provider, mobile_providers())) $provider = 'mtn';
    $done = hpl_tx(function () use ($w, $id, $provider, $txn) {
        q('SELECT id FROM users WHERE id = ? FOR UPDATE', [(int)$w['user_id']]);
        $cur = fetch_one('SELECT amount, status FROM withdrawals WHERE id = ? FOR UPDATE', [$id]);
        if (!$cur || $cur['status'] !== 'pending') return 0;
        $st = creator_stats((int)$w['user_id']);
        if ((float)$cur['amount'] > round($st['available'] + (float)$cur['amount'], 2) + 0.001) return -1;
        return q('UPDATE withdrawals SET status = "paid", payment_provider = ?, txn_reference = ?, admin_note = ?, resolved_at = NOW(), receipt = "waiting" WHERE id = ? AND status = "pending"',
            [$provider, $txn, 'Paid via ' . mobile_providers()[$provider]['label'] . ' (Telegram)', $id])->rowCount();
    });
    if ($done === -1) {
        return ['ok' => false, 'msg' => 'Not marked as paid: ' . $w['full_name'] . ' no longer has enough approved money for this request. Check it in the admin.'];
    }
    if ($done !== 1) return ['ok' => false, 'msg' => 'This request was already handled.'];
    notif_add((int)$w['user_id'], 'withdrawal', 'Withdrawal paid: please confirm', money($w['amount']) . ' was sent to you. Ref: ' . $txn . '. Open Wallet and tap "I got it", or "I did not get it". No answer in 3 days counts as received.', 'creator/withdraw.php');
    try { require_once __DIR__ . '/community.php'; chat_event((int)$w['user_id'], 'wins', 'got paid ' . money($w['amount'])); } catch (Throwable $e) {}
    tg_log('withdrawal paid', $w['full_name'] . ' ' . money($w['amount']) . ' ref ' . $txn);
    return ['ok' => true, 'msg' => money($w['amount']) . ' to ' . $w['full_name'] . ' marked as paid (ref ' . $txn . '). They were asked to confirm. Add the screenshot of the sent message in Admin > Pay withdrawals as your proof.'];
}

function tg_do_withdrawal_reject(int $id, string $note): array
{
    $w = fetch_one('SELECT w.*, u.full_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$id]);
    if (!$w) return ['ok' => false, 'msg' => 'That withdrawal no longer exists.'];
    $note = trim($note) ?: 'Rejected';
    $n = q('UPDATE withdrawals SET status = "rejected", admin_note = ?, resolved_at = NOW() WHERE id = ? AND status = "pending"', [mb_substr($note, 0, 250), $id])->rowCount();
    if ($n !== 1) return ['ok' => false, 'msg' => 'That withdrawal is already ' . $w['status'] . '.'];
    notif_add((int)$w['user_id'], 'withdrawal', 'Withdrawal not approved', $note . ' The amount is back in your balance.', 'creator/withdraw.php');
    tg_log('withdrawal rejected', $w['full_name'] . ' ' . money($w['amount']) . ': ' . $note);
    return ['ok' => true, 'msg' => $w['full_name'] . "'s " . money($w['amount']) . ' request rejected; the money is back in their balance.'];
}

/** Numbers for /today and the evening summary. */
function tg_stats(string $period = 'today'): array
{
    $since = $period === 'week' ? 'NOW() - INTERVAL 7 DAY' : 'CURDATE()';
    return [
        'period'              => $period === 'week' ? 'last 7 days' : 'today',
        'signups'             => (int)fetch_val("SELECT COUNT(*) FROM users WHERE role = 'creator' AND created_at >= $since", [], 0),
        'tests_passed'        => (int)fetch_val("SELECT COUNT(*) FROM assessments WHERE passed = 1 AND taken_at >= $since", [], 0),
        'work_sent'           => (int)fetch_val("SELECT COUNT(*) FROM portfolio_links WHERE created_at >= $since", [], 0),
        'clips_sent'          => (int)fetch_val("SELECT COUNT(*) FROM campaign_submissions WHERE submitted_at >= $since", [], 0),
        'money_earned'        => money((float)fetch_val("SELECT COALESCE(SUM(amount),0) FROM earning_days WHERE day >= " . ($period === 'week' ? 'CURDATE() - INTERVAL 7 DAY' : 'CURDATE()'), [], 0)),
        'waiting_work'        => (int)fetch_val('SELECT COUNT(*) FROM portfolio_links WHERE status = "pending"', [], 0),
        'waiting_pages'       => (int)fetch_val('SELECT COUNT(*) FROM social_pages WHERE status = "pending"', [], 0),
        'waiting_clips'       => (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE status = "pending"', [], 0),
        'waiting_withdrawals' => (int)fetch_val('SELECT COUNT(*) FROM withdrawals WHERE status = "pending"', [], 0),
        'total_creators'      => (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator"', [], 0),
    ];
}

function tg_stats_text(array $s): string
{
    return '📊 <b>HPL Creators, ' . tg_h($s['period']) . "</b>\n"
        . "Sign-ups: <b>{$s['signups']}</b> (all creators: {$s['total_creators']})\n"
        . "Tests passed: <b>{$s['tests_passed']}</b> · Work videos sent: <b>{$s['work_sent']}</b>\n"
        . "Campaign clips sent: <b>{$s['clips_sent']}</b> · Earned by creators: <b>" . tg_h($s['money_earned']) . "</b>\n\n"
        . "<b>Waiting for you</b>\nWork videos: {$s['waiting_work']} · Pages: {$s['waiting_pages']} · Clips: {$s['waiting_clips']} · Withdrawals: {$s['waiting_withdrawals']}";
}

/** Everything waiting for a decision, with ids (for /pending and for Claude). */
function tg_pending_items(int $limit = 15): array
{
    $out = [];
    foreach (fetch_all('SELECT p.id, u.full_name, p.platform, p.url FROM portfolio_links p JOIN users u ON u.id = p.user_id WHERE p.status = "pending" ORDER BY p.id LIMIT ' . $limit) as $r) {
        $out[] = ['type' => 'work', 'id' => (int)$r['id'], 'creator' => $r['full_name'], 'detail' => platform_label($r['platform']) . ' ' . $r['url']];
    }
    foreach (fetch_all('SELECT s.id, u.full_name, s.platform, s.url FROM social_pages s JOIN users u ON u.id = s.user_id WHERE s.status = "pending" ORDER BY s.id LIMIT ' . $limit) as $r) {
        $out[] = ['type' => 'page', 'id' => (int)$r['id'], 'creator' => $r['full_name'], 'detail' => platform_label($r['platform']) . ' ' . $r['url']];
    }
    foreach (fetch_all('SELECT s.id, u.full_name, s.url, c.title, s.earned FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id WHERE s.status = "pending" ORDER BY s.id LIMIT ' . $limit) as $r) {
        $out[] = ['type' => 'clip', 'id' => (int)$r['id'], 'creator' => $r['full_name'], 'detail' => $r['title'] . ' ' . $r['url'] . ' (locked ' . money($r['earned']) . ')'];
    }
    foreach (fetch_all('SELECT w.id, u.full_name, w.amount, w.method, w.paid_to_number FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.status = "pending" ORDER BY w.id LIMIT ' . $limit) as $r) {
        $out[] = ['type' => 'withdrawal', 'id' => (int)$r['id'], 'creator' => $r['full_name'], 'detail' => money($r['amount']) . ' to ' . $r['method'] . ' ' . $r['paid_to_number']];
    }
    return $out;
}

// ---------------------------------------------------------------------------------------------
// Conversation state (what the bot is waiting for: a reason, a reference, a confirmation)
// ---------------------------------------------------------------------------------------------

/**
 * What the bot is waiting for (a typed reason, or a Confirm tap). It expires after 10 minutes,
 * so an old Reject tap can never turn a later, unrelated message into a rejection reason.
 */
function tg_state(): array
{
    $s = json_decode((string)setting('tg_state', ''), true) ?: [];
    if ($s && time() - (int)($s['at'] ?? 0) > 600) {
        return [];
    }
    return $s;
}

/** Saves the state. A confirm gets a fresh code that its Confirm button must carry. */
function tg_set_state(array $s): array
{
    if ($s) {
        $s['at'] = time();
        if (!empty($s['confirm'])) {
            $s['code'] = bin2hex(random_bytes(4));
        }
    }
    set_setting('tg_state', $s ? json_encode($s) : '');
    return $s;
}

/** Confirm / Cancel buttons tied to the confirm just saved (the code must match when tapped). */
function tg_confirm_buttons(array $st): array
{
    $id = (int)($st['id'] ?? 0);
    return [[['✅ Confirm', 'x:y:' . $id . ':' . ($st['code'] ?? '')], ['Cancel', 'x:n:' . $id]]];
}

/** Asks the owner to confirm approving or rejecting a campaign clip (it moves money). */
function tg_ask_clip_confirm(int $sid, bool $approve, string $reason = '', int $mid = 0): array
{
    $s = fetch_one('SELECT s.status, s.earned, u.full_name, c.title FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id WHERE s.id = ?', [$sid]);
    if (!$s) return ['ok' => false, 'msg' => 'That clip no longer exists.'];
    if ($s['status'] !== 'pending') return ['ok' => false, 'msg' => 'That clip is already ' . $s['status'] . '. Change it on the website if needed.'];
    $st = tg_set_state(['confirm' => $approve ? 'clip_approve' : 'clip_reject', 'id' => $sid, 'val' => $reason, 'mid' => $mid]);
    tg_send(($approve ? '✅ Confirm: approve ' . tg_h($s['full_name']) . "'s " . tg_h($s['title']) . ' clip' . ((float)$s['earned'] > 0 ? ' and unlock <b>' . tg_h(money((float)$s['earned'])) . '</b>' : '') . '?'
        : '❌ Confirm: reject ' . tg_h($s['full_name']) . "'s " . tg_h($s['title']) . ' clip? It will earn nothing.' . ($reason !== '' ? ' Reason: ' . tg_h($reason) : '')),
        tg_confirm_buttons($st));
    return ['ok' => true, 'msg' => 'Confirmation buttons sent to the owner. Nothing has changed yet.'];
}

// ---------------------------------------------------------------------------------------------
// Claude: typed replies
// ---------------------------------------------------------------------------------------------

function tg_ai_tools(): array
{
    $id = ['type' => 'integer', 'description' => 'The id shown in the pending list or alert'];
    $why = ['type' => 'string', 'description' => 'Reason in plain, kind English, written to the creator'];
    return [
        ['name' => 'list_pending', 'description' => 'List everything waiting for the owner: work videos, pages, campaign clips, withdrawals (with ids).', 'input_schema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'get_stats', 'description' => 'Sign-ups, tests, clips, earnings and waiting items for today or the last 7 days.', 'input_schema' => ['type' => 'object', 'properties' => ['period' => ['type' => 'string', 'enum' => ['today', 'week']]]]],
        ['name' => 'find_creator', 'description' => 'Look up creators by name, email or phone: step, balance, pages, clips.', 'input_schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']], 'required' => ['query']]],
        ['name' => 'approve_work', 'description' => "Approve a creator's work video (they become an approved clipper).", 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']]],
        ['name' => 'reject_work', 'description' => "Reject a creator's work video with a reason they will read.", 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id, 'reason' => $why], 'required' => ['id', 'reason']]],
        ['name' => 'verify_page', 'description' => 'Mark a social page as verified.', 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']]],
        ['name' => 'reject_page', 'description' => 'Reject a social page with a reason.', 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id, 'reason' => $why], 'required' => ['id', 'reason']]],
        ['name' => 'approve_clip', 'description' => 'Ask the owner to confirm approving a campaign clip (unlocks its money). Nothing changes until the owner taps Confirm.', 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id], 'required' => ['id']]],
        ['name' => 'reject_clip', 'description' => 'Ask the owner to confirm rejecting a campaign clip (it earns nothing). Nothing changes until the owner taps Confirm.', 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id, 'reason' => $why], 'required' => ['id']]],
        ['name' => 'withdrawal_paid', 'description' => 'Ask the owner to confirm that a withdrawal was paid, with the mobile money transaction reference. Nothing changes until the owner taps Confirm.', 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id, 'reference' => ['type' => 'string']], 'required' => ['id', 'reference']]],
        ['name' => 'withdrawal_reject', 'description' => 'Ask the owner to confirm rejecting a withdrawal (money goes back to the creator balance). Nothing changes until the owner taps Confirm.', 'input_schema' => ['type' => 'object', 'properties' => ['id' => $id, 'reason' => $why], 'required' => ['id', 'reason']]],
        ['name' => 'list_submissions', 'description' => 'List submitted items with their links: work videos (work), campaign clips (clip), social pages (page) or withdrawals (withdrawal). Filter by status and creator name; order newest, oldest or random (use random for "pick any/random"). Returns ids, creator, link, status, date, and views/earnings for clips.', 'input_schema' => ['type' => 'object', 'properties' => [
            'type' => ['type' => 'string', 'enum' => ['work', 'clip', 'page', 'withdrawal']],
            'status' => ['type' => 'string', 'enum' => ['any', 'pending', 'approved', 'rejected', 'paid'], 'description' => 'Default any'],
            'creator' => ['type' => 'string', 'description' => 'Optional part of the creator name, email or phone'],
            'campaign' => ['type' => 'string', 'description' => 'Optional part of a campaign title (clips only)'],
            'order' => ['type' => 'string', 'enum' => ['newest', 'oldest', 'random', 'most_views'], 'description' => 'Default newest'],
            'limit' => ['type' => 'integer', 'description' => '1 to 25, default 10'],
        ], 'required' => ['type']]],
        ['name' => 'list_creators', 'description' => 'List creators with their step, sign-up date, balance and clip count. Steps: all, no_test, failed, no_work, review (waiting for owner), rejected, approved (no clips yet), earning (sending clips), suspended. Order newest, oldest, random, top_earners or last_active.', 'input_schema' => ['type' => 'object', 'properties' => [
            'step' => ['type' => 'string', 'enum' => ['all', 'no_test', 'failed', 'no_work', 'review', 'rejected', 'approved', 'earning', 'suspended']],
            'order' => ['type' => 'string', 'enum' => ['newest', 'oldest', 'random', 'top_earners', 'last_active']],
            'limit' => ['type' => 'integer', 'description' => '1 to 25, default 10'],
        ]]],
        ['name' => 'show_item', 'description' => 'Send the owner a card for one item (work, clip, page or withdrawal) with its video preview and Approve/Reject buttons, like an alert. Use it whenever the owner wants to see or act on a specific item.', 'input_schema' => ['type' => 'object', 'properties' => ['type' => ['type' => 'string', 'enum' => ['work', 'clip', 'page', 'withdrawal']], 'id' => $id], 'required' => ['type', 'id']]],
        ['name' => 'message_creator', 'description' => "Send a private message to one creator in their HPL inbox (they also get a notification). Write it in plain, kind English. Only when the owner's newest message asks you to message someone. A request from an earlier message has already been done: never send it again.", 'input_schema' => ['type' => 'object', 'properties' => ['creator_id' => ['type' => 'integer'], 'text' => ['type' => 'string', 'description' => 'Under 1000 characters'], 'send_again' => ['type' => 'boolean', 'description' => 'Only true when the owner clearly asks to message this creator again after a message in the last 30 minutes']], 'required' => ['creator_id', 'text']]],
        ['name' => 'query_database', 'description' => "Read-only SQL (MySQL) for any question the other tools cannot answer: counts, totals, trends, lists. One SELECT statement only; at most 50 rows come back. Password, token and secret columns and the settings table are hidden. Tables and columns:\n" . tg_ai_schema(), 'input_schema' => ['type' => 'object', 'properties' => ['sql' => ['type' => 'string']], 'required' => ['sql']]],
        ['name' => 'send_reminder', 'description' => 'Remind every creator in a step about their next step (app notification + email). Steps: no_test, failed, no_work, rejected, approved (approved but no clips yet).', 'input_schema' => ['type' => 'object', 'properties' => ['step' => ['type' => 'string', 'enum' => ['no_test', 'failed', 'no_work', 'rejected', 'approved']]], 'required' => ['step']]],
    ];
}

function tg_ai_run_tool(string $name, array $in): array
{
    $id = (int)($in['id'] ?? 0);
    switch ($name) {
        case 'list_pending': return ['items' => tg_pending_items()];
        case 'get_stats': return tg_stats((string)($in['period'] ?? 'today'));
        case 'find_creator':
            $qq = '%' . trim((string)($in['query'] ?? '')) . '%';
            $rows = fetch_all('SELECT id, full_name, email, phone, assessment_status, portfolio_status, qualified, status, created_at FROM users WHERE role = "creator" AND (full_name LIKE ? OR email LIKE ? OR phone LIKE ?) ORDER BY id DESC LIMIT 5', [$qq, $qq, $qq]);
            foreach ($rows as &$r) {
                $st = creator_stats((int)$r['id']);
                $r['balance'] = money($st['balance']); $r['waiting_review'] = money($st['in_review']); $r['earned'] = money($st['earned']);
                $r['verified_pages'] = (int)fetch_val('SELECT COUNT(*) FROM social_pages WHERE user_id = ? AND status = "approved"', [(int)$r['id']], 0);
                $r['campaign_clips'] = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ?', [(int)$r['id']], 0);
            }
            return ['creators' => $rows];
        case 'approve_work': return tg_do_work($id, true);
        case 'reject_work': return tg_do_work($id, false, (string)($in['reason'] ?? ''));
        case 'verify_page': return tg_do_page($id, true);
        case 'reject_page': return tg_do_page($id, false, 'other', (string)($in['reason'] ?? ''));
        case 'approve_clip': return tg_ask_clip_confirm($id, true);
        case 'reject_clip': return tg_ask_clip_confirm($id, false, (string)($in['reason'] ?? ''));
        case 'withdrawal_paid':
        case 'withdrawal_reject':
            $w = fetch_one('SELECT w.*, u.full_name FROM withdrawals w JOIN users u ON u.id = w.user_id WHERE w.id = ?', [$id]);
            if (!$w || $w['status'] !== 'pending') return ['ok' => false, 'msg' => 'No pending withdrawal with that id.'];
            $paid = $name === 'withdrawal_paid';
            $val = trim((string)($in[$paid ? 'reference' : 'reason'] ?? ''));
            $cst = tg_set_state(['confirm' => $paid ? 'paid' : 'reject', 'id' => $id, 'val' => $val]);
            tg_send(($paid ? '💸 Confirm: <b>' . tg_h(money($w['amount'])) . '</b> paid to ' . tg_h($w['full_name']) . ', reference <b>' . tg_h($val) . '</b>?'
                : '❌ Confirm: reject ' . tg_h($w['full_name']) . "'s " . tg_h(money($w['amount'])) . ' request? Reason: ' . tg_h($val)),
                tg_confirm_buttons($cst));
            return ['ok' => true, 'msg' => 'Confirmation buttons sent to the owner. Nothing has changed yet.'];
        case 'list_submissions': return tg_ai_list_submissions($in);
        case 'list_creators': return tg_ai_list_creators($in);
        case 'show_item':
            $ok = tg_show_item((string)($in['type'] ?? ''), $id);
            return $ok ? ['ok' => true, 'msg' => 'Card sent to the owner. Do not repeat its details.'] : ['ok' => false, 'msg' => 'No item of that type with that id.'];
        case 'message_creator':
            $cid = (int)($in['creator_id'] ?? 0);
            $c = fetch_one('SELECT id, full_name FROM users WHERE id = ? AND role = "creator"', [$cid]);
            if (!$c) return ['ok' => false, 'msg' => 'No creator with that id.'];
            $admin = tg_admin_id();
            // The creator's support chat ("HPL team"), the same one the website uses.
            $tid = support_thread_for($cid);
            // Guard against repeats: the same creator is not messaged twice in 30 minutes unless the owner asks again.
            $prev = $tid ? fetch_one('SELECT message, created_at FROM dm_messages WHERE thread_id = ? AND sender_id = ? AND created_at > (NOW() - INTERVAL 30 MINUTE) ORDER BY id DESC LIMIT 1', [$tid, $admin]) : null;
            if ($prev && empty($in['send_again'])) {
                return ['ok' => false, 'msg' => 'Not sent: ' . $c['full_name'] . ' already got a message from you at ' . date('H:i', strtotime((string)$prev['created_at'])) . ' ("' . mb_substr((string)$prev['message'], 0, 120) . '"). Tell the owner it was already sent. Only send another if the owner clearly asks for a second message.'];
            }
            $err = $tid ? dm_send($tid, $admin, (string)($in['text'] ?? '')) : 'Could not open the conversation.';
            if ($err !== null) return ['ok' => false, 'msg' => $err];
            notif_add($cid, 'message', 'New message from HPL', mb_substr((string)$in['text'], 0, 120), 'creator/messages.php?thread=' . $tid);
            tg_log('message sent', $c['full_name'] . ': ' . mb_substr((string)$in['text'], 0, 200));
            return ['ok' => true, 'msg' => 'Message sent to ' . $c['full_name'] . '.'];
        case 'query_database': return tg_ai_query((string)($in['sql'] ?? ''));
        case 'send_reminder':
            require_once __DIR__ . '/creator_stages.php';
            $n = creators_send_reminders((string)($in['step'] ?? ''));
            tg_log('reminder sent', (string)($in['step'] ?? '') . ' ' . var_export($n, true));
            return $n === null ? ['ok' => false, 'msg' => 'That group was already reminded in the last 24 hours.'] : ['ok' => true, 'msg' => $n . ' creators reminded.'];
    }
    return ['ok' => false, 'msg' => 'Unknown action.'];
}

/** Compact list of tables and columns for Claude (sensitive columns and tables left out). */
function tg_ai_schema(): string
{
    static $s = null;
    if ($s !== null) return $s;
    $s = '';
    try {
        $rows = fetch_all('SELECT TABLE_NAME t, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() GROUP BY TABLE_NAME');
        foreach ($rows as $r) {
            if (tg_ai_hidden_table($r['t'])) continue;
            $cols = array_filter(explode(',', (string)$r['c']), fn($c) => !tg_ai_hidden_col($c));
            $s .= $r['t'] . '(' . implode(',', $cols) . ")\n";
        }
    } catch (Throwable $e) {
    }
    $s .= 'Notes: users.role is creator or admin. Money columns are in Kwacha. Statuses: pending/approved/rejected (withdrawals: pending/paid/rejected). Use NOW() and CURDATE() for dates.';
    return $s;
}

function tg_ai_hidden_table(string $t): bool { return in_array(strtolower($t), ['settings', 'auth_throttle', 'tg_updates', 'tiktok_accounts'], true); }
function tg_ai_hidden_col(string $c): bool { return (bool)preg_match('~password|token|secret|hash|api_?key|email_code|open_id~i', $c); }

/** Run one read-only SELECT for Claude. Writes are impossible: the statement runs in a READ ONLY transaction. */
function tg_ai_query(string $sql): array
{
    $sql = trim($sql);
    $sql = rtrim($sql, "; \t\n\r");
    if (!preg_match('~^(select|with)\b~i', $sql)) return ['ok' => false, 'msg' => 'Only SELECT queries are allowed.'];
    if (strpos($sql, ';') !== false) return ['ok' => false, 'msg' => 'One statement only.'];
    if (preg_match('~\b(into\s+(outfile|dumpfile|@)|load_file|sleep|benchmark|get_lock|for\s+update|lock\s+in\s+share)\b~i', $sql)) return ['ok' => false, 'msg' => 'That kind of query is not allowed.'];
    foreach (['settings', 'auth_throttle', 'tiktok_accounts', 'tg_updates', 'mysql', 'performance_schema'] as $bad) {
        if (preg_match('~(?<![a-z_])`?' . $bad . '`?(?![a-z_])~i', $sql)) return ['ok' => false, 'msg' => 'The ' . $bad . ' table is private.'];
    }
    if (preg_match('~password|token|secret|_hash|api_?key|email_code|open_id~i', $sql)) return ['ok' => false, 'msg' => 'Private columns cannot be read.'];
    $pdo = db();
    try {
        $pdo->exec('SET SESSION MAX_EXECUTION_TIME = 5000');
    } catch (Throwable $e) {
    }
    try {
        $pdo->exec('START TRANSACTION READ ONLY');
        $st = $pdo->query($sql);
        $rows = [];
        while (count($rows) < 50 && ($r = $st->fetch(PDO::FETCH_ASSOC))) {
            foreach (array_keys($r) as $k) { if (tg_ai_hidden_col((string)$k)) unset($r[$k]); }
            $rows[] = $r;
        }
        $more = $st->fetch(PDO::FETCH_ASSOC) !== false;
        $st->closeCursor();
        $pdo->exec('ROLLBACK');
        return ['ok' => true, 'rows' => $rows, 'more_rows' => $more];
    } catch (Throwable $e) {
        try { $pdo->exec('ROLLBACK'); } catch (Throwable $e2) {}
        return ['ok' => false, 'msg' => 'Query error: ' . $e->getMessage()];
    }
}

function tg_ai_limit(array $in): int { return max(1, min(25, (int)($in['limit'] ?? 10) ?: 10)); }

function tg_ai_list_submissions(array $in): array
{
    $type = (string)($in['type'] ?? 'work');
    $status = (string)($in['status'] ?? 'any');
    $order = (string)($in['order'] ?? 'newest');
    $map = [
        'work' => ['portfolio_links', 'x.id, u.id AS creator_id, u.full_name AS creator, x.platform, x.url AS link, x.status, x.note, x.admin_note, x.created_at AS sent_at', 'x.created_at', ''],
        'clip' => ['campaign_submissions', 'x.id, u.id AS creator_id, u.full_name AS creator, c.title AS campaign, x.platform, x.url AS link, x.status, x.views, x.earned, x.submitted_at AS sent_at', 'x.submitted_at', ' JOIN campaigns c ON c.id = x.campaign_id'],
        'page' => ['social_pages', 'x.id, u.id AS creator_id, u.full_name AS creator, x.platform, x.url AS link, x.page_name, x.status, x.admin_note, x.submitted_at AS sent_at', 'x.submitted_at', ''],
        'withdrawal' => ['withdrawals', 'x.id, u.id AS creator_id, u.full_name AS creator, x.amount, x.method, x.paid_to_number, x.status, x.txn_reference, x.requested_at AS sent_at', 'x.requested_at', ''],
    ];
    if (!isset($map[$type])) return ['ok' => false, 'msg' => 'Unknown type.'];
    [$table, $cols, $dateCol, $join] = $map[$type];
    $where = []; $args = [];
    if ($status !== 'any' && $status !== '') { $where[] = 'x.status = ?'; $args[] = $status; }
    if (trim((string)($in['creator'] ?? '')) !== '') { $q = '%' . trim((string)$in['creator']) . '%'; $where[] = '(u.full_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)'; array_push($args, $q, $q, $q); }
    if ($type === 'clip' && trim((string)($in['campaign'] ?? '')) !== '') { $where[] = 'c.title LIKE ?'; $args[] = '%' . trim((string)$in['campaign']) . '%'; }
    $ord = ['newest' => $dateCol . ' DESC', 'oldest' => $dateCol . ' ASC', 'random' => 'RAND()', 'most_views' => ($type === 'clip' ? 'x.views DESC' : $dateCol . ' DESC')][$order] ?? $dateCol . ' DESC';
    $sql = 'SELECT ' . $cols . ' FROM ' . $table . ' x JOIN users u ON u.id = x.user_id' . $join . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $ord . ' LIMIT ' . tg_ai_limit($in);
    $rows = fetch_all($sql, $args);
    $total = (int)fetch_val('SELECT COUNT(*) FROM ' . $table . ' x JOIN users u ON u.id = x.user_id' . $join . ($where ? ' WHERE ' . implode(' AND ', $where) : ''), $args, 0);
    return ['ok' => true, 'type' => $type, 'total_matching' => $total, 'items' => $rows];
}

function tg_ai_list_creators(array $in): array
{
    require_once __DIR__ . '/creator_stages.php';
    $step = (string)($in['step'] ?? 'all');
    $stages = creator_stages();
    $where = 'role = "creator"' . (isset($stages[$step]) ? ' AND ' . $stages[$step]['sql'] : '');
    $ord = ['newest' => 'created_at DESC', 'oldest' => 'created_at ASC', 'random' => 'RAND()', 'last_active' => 'last_seen DESC'][(string)($in['order'] ?? 'newest')] ?? 'created_at DESC';
    $top = ($in['order'] ?? '') === 'top_earners';
    $rows = fetch_all('SELECT id, full_name, phone, country, social_handle, status, qualified, assessment_status, assessment_score, portfolio_status, created_at, last_seen FROM users WHERE ' . $where . ' ORDER BY ' . ($top ? 'id' : $ord) . ' LIMIT ' . ($top ? 500 : tg_ai_limit($in)));
    foreach ($rows as &$r) {
        $st = creator_stats((int)$r['id']);
        $r['step'] = $stages[creator_stage_of($r + ['links' => (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ?', [(int)$r['id']], 0)])]['label'] ?? '';
        $r['earned'] = (float)$st['earned']; $r['balance'] = (float)$st['balance'];
        $r['clips'] = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ?', [(int)$r['id']], 0);
    }
    unset($r);
    if ($top) { usort($rows, fn($a, $b) => $b['earned'] <=> $a['earned']); $rows = array_slice($rows, 0, tg_ai_limit($in)); }
    $total = (int)fetch_val('SELECT COUNT(*) FROM users WHERE ' . $where, [], 0);
    return ['ok' => true, 'total_matching' => $total, 'creators' => $rows];
}

/** Send one item as an alert card with buttons (same look as the automatic alerts). */
function tg_show_item(string $type, int $id): bool
{
    $tables = ['work' => 'portfolio_links', 'clip' => 'campaign_submissions', 'page' => 'social_pages', 'withdrawal' => 'withdrawals'];
    if (!isset($tables[$type])) return false;
    $status = fetch_val('SELECT status FROM ' . $tables[$type] . ' WHERE id = ?', [$id], null);
    if ($status === null) return false;
    $GLOBALS['tg_alert_now'] = true;
    $GLOBALS['tg_alert_prefix'] = $status !== 'pending' ? '📌 <i>Already ' . tg_h((string)$status) . "</i>\n" : '';
    try {
        if ($type === 'clip') {
            tg_alert_clip($id);
        } else {
            $kinds = ['work' => 'portfolio', 'page' => 'social', 'withdrawal' => 'withdrawal'];
            tg_from_notification($kinds[$type], '', '', '', ['type' => $type, 'id' => $id]);
        }
    } finally {
        unset($GLOBALS['tg_alert_now'], $GLOBALS['tg_alert_prefix']);
    }
    return true;
}

/** Ask Claude to carry out the owner's typed message. Returns the reply text to send back. */
function tg_ai_handle(string $text, string $contextAlert = ''): string
{
    $key = tg_ai_key();
    if ($key === '') {
        return "I can only understand buttons for now. Add your Claude (Anthropic) API key in Admin → Telegram assistant to reply in your own words.\nTry /pending or /today.";
    }
    $history = tg_hist_get();
    $recent = fetch_all('SELECT ref_type, ref_id, text FROM tg_alerts WHERE ref_type NOT LIKE "wait:%" ORDER BY id DESC LIMIT 6');
    $ctx = '';
    foreach (array_reverse($recent) as $r) {
        $ctx .= '- [' . ($r['ref_type'] ?: 'info') . ($r['ref_id'] ? ' id ' . $r['ref_id'] : '') . '] ' . strip_tags((string)$r['text']) . "\n";
    }
    $system = "You are the HPL Creators assistant. You work for the owner of HPL Gold Detectors (Zambia), who runs a clipping program: creators sign up, pass a test, send one work video for approval, verify a social page, join campaigns, post clips and withdraw to mobile money.\n"
        . "New work videos, campaign clips, pages to verify, withdrawals and sign-ups are already sent to the owner automatically as alerts with a video preview and Approve/Reject buttons (switched on or off in Admin > Telegram assistant). If asked to be reminded about these, say they arrive automatically.\n"
        . "You chat with the owner on Telegram like a capable business assistant. You can talk about anything: answer questions, give advice on the business, marketing, sales, content and video ideas, write captions, scripts, messages and announcements, explain numbers.\n"
        . "You can see everything on the website through the tools: list_submissions and list_creators for lists (use order random when asked for any or a random one), show_item to send a card with the video and Approve/Reject buttons, query_database for any other question about creators, clips, views or money. Never tell the owner you cannot see or list something; use the tools first.\n"
        . "When the owner asks you to do something on the website, use the tools. Never invent ids: use the alert the owner replied to, the recent alerts, or list_pending/find_creator. If a website action is unclear or matches several items, ask one short question instead of acting.\n"
        . "Money (withdrawals) is never changed by you directly: the withdrawal tools only send the owner Confirm buttons.\n"
        . "Only act on the owner's newest message. Earlier messages in this chat were already handled: the notes in [Done: ...] list the website actions already carried out. Never repeat an action (message, approval, rejection, reminder) unless the newest message clearly asks for it again.\n"
        . "Write plain text for a phone screen (no markdown, no tables). Keep replies short unless the owner asks for more. After a website action, say exactly what you did. Today is " . date('l j F Y, H:i') . ".\n\n"
        . ($contextAlert !== '' ? "The owner is replying to this alert:\n" . strip_tags($contextAlert) . "\n\n" : '')
        . "Recent alerts (oldest first):\n" . ($ctx ?: "none\n");
    $messages = $history;
    $messages[] = ['role' => 'user', 'content' => $text];
    $done = []; // website actions carried out in this turn, remembered with the reply
    $readOnly = ['list_pending', 'get_stats', 'find_creator', 'list_submissions', 'list_creators', 'query_database'];
    for ($turn = 0; $turn < 8; $turn++) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
            CURLOPT_POSTFIELDS => json_encode(['model' => tg_ai_model(), 'max_tokens' => 1500, 'system' => $system, 'tools' => tg_ai_tools(), 'messages' => $messages]),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $d = json_decode((string)$raw, true);
        if ($code !== 200 || !is_array($d)) {
            $err = is_array($d) ? (string)($d['error']['message'] ?? '') : '';
            $out = 'Claude could not be reached right now' . ($err !== '' ? ' (' . $err . ')' : '') . '. You can still use the buttons, /pending and /today.';
            if ($done) tg_hist_add($text, $out . "\n[Done: " . implode('; ', $done) . ']');
            return $out;
        }
        $content = $d['content'] ?? [];
        $messages[] = ['role' => 'assistant', 'content' => $content];
        if (($d['stop_reason'] ?? '') !== 'tool_use') {
            $out = '';
            foreach ($content as $b) { if (($b['type'] ?? '') === 'text') $out .= $b['text']; }
            $out = trim($out) ?: 'Done.';
            tg_hist_add($text, $out . ($done ? "\n[Done: " . implode('; ', $done) . ']' : ''));
            return $out;
        }
        $results = [];
        foreach ($content as $b) {
            if (($b['type'] ?? '') !== 'tool_use') continue;
            try {
                $res = tg_ai_run_tool((string)$b['name'], (array)($b['input'] ?? []));
            } catch (Throwable $e) {
                $res = ['ok' => false, 'msg' => 'Error: ' . $e->getMessage()];
            }
            if (!in_array((string)$b['name'], $readOnly, true)) {
                $done[] = $b['name'] . ' → ' . mb_substr((string)($res['msg'] ?? (($res['ok'] ?? false) ? 'ok' : 'failed')), 0, 140);
            }
            $results[] = ['type' => 'tool_result', 'tool_use_id' => $b['id'], 'content' => json_encode($res)];
        }
        $messages[] = ['role' => 'user', 'content' => $results];
    }
    $out = 'I stopped after several steps. Check /pending to see where things are.';
    tg_hist_add($text, $out . ($done ? "\n[Done: " . implode('; ', $done) . ']' : ''));
    return $out;
}

/** Recent conversation with Claude (plain text only), forgotten after 3 hours of silence or /new. */
function tg_hist_get(): array
{
    $h = json_decode((string)setting('tg_chat_history', ''), true);
    if (!is_array($h) || time() - (int)($h['t'] ?? 0) > 10800) return [];
    $m = array_values(array_filter((array)($h['m'] ?? []), fn($x) => is_array($x) && isset($x['role'], $x['content']) && is_string($x['content'])));
    while ($m && $m[0]['role'] !== 'user') array_shift($m);
    return $m;
}

function tg_hist_add(string $user, string $reply): void
{
    $m = tg_hist_get();
    $m[] = ['role' => 'user', 'content' => mb_substr($user, 0, 2000)];
    $m[] = ['role' => 'assistant', 'content' => mb_substr($reply, 0, 3000)];
    $m = array_slice($m, -20);
    set_setting('tg_chat_history', json_encode(['t' => time(), 'm' => $m]));
}

function tg_hist_clear(): void { set_setting('tg_chat_history', ''); }

// ---------------------------------------------------------------------------------------------
// Evening summary (sent once a day after 20:00, triggered by site traffic)
// ---------------------------------------------------------------------------------------------

function tg_daily_maybe(): void
{
    try {
        if (!tg_ready() || !tg_kind_on('daily') || (int)date('G') < 20) return;
        $today = date('Y-m-d');
        $last = (string)setting('tg_daily_last', '');
        if ($last === $today) return;
        if ($last === '' && setting('tg_daily_last', null) === null) {
            q('INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ("tg_daily_last", "")');
        }
        if (q('UPDATE settings SET setting_value = ? WHERE setting_key = "tg_daily_last" AND setting_value = ?', [$today, $last])->rowCount() !== 1) return;
        tg_alert('daily', tg_stats_text(tg_stats('today')), [[['See what is waiting', 'm:pending']]]);
    } catch (Throwable $e) {
    }
}
