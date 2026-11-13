<?php
/**
 * Clip deadline (owner, 2026-11-13): "inactive users are just pressing load on the site". Every creator gets 3 days to
 * send at least one clip link. Warning at the start (bell, phone, email), a reminder on the last day, and when the time
 * is up everyone who sent no clip link is paused (users.status = suspended, suspend_reason = 'no_clip'), like the
 * inactivity pause. Opening the site does not count; only a clip link sent (and not rejected) after the start.
 * Money is never touched: balances stay; an admin can open an account again from the creator's page.
 *
 * Settings: clip_deadline_start, clip_deadline_end (unix). Progress per creator in clip_deadline_log.
 * Run by the background sync (clip_deadline_run(), every 10 minutes, 25 emails a run).
 */

function clip_deadline_window(): ?array
{
    $s = (int)setting('clip_deadline_start', '0');
    $e = (int)setting('clip_deadline_end', '0');
    return ($s > 0 && $e > $s) ? [$s, $e] : null;
}

/** Did this creator send a clip link since the start? */
function clip_deadline_done(int $uid, int $start): bool
{
    return (bool)fetch_val('SELECT 1 FROM campaign_submissions WHERE user_id = ? AND submitted_at >= FROM_UNIXTIME(?) AND status <> "rejected" LIMIT 1', [$uid, $start], 0);
}

function clip_deadline_date(int $t): string
{
    return date('D j M', $t) . ' at ' . date('H:i', $t);
}

/** Title and words of each step. $ready = passed the test. */
function clip_deadline_message(string $step, bool $ready, int $end): array
{
    $when = clip_deadline_date($end);
    if ($step === 'warn') {
        return ['Send a clip in the next 3 days to keep your account',
            'Send 1 clip link before ' . $when . ', or your account is paused.'];
    }
    if ($step === 'remind') {
        return ['1 day left to send your clip',
            'Send 1 clip link before ' . $when . ', or your account is paused.'];
    }
    return ['Your account is paused',
        'You did not send a clip link by ' . $when . ', so your HPL account was paused.'];
}

/** For the creator's Home: the end time while this creator still has to send a clip, or null. */
function clip_deadline_notice(array $user): ?int
{
    try {
        $w = clip_deadline_window();
        if ($w === null || time() >= $w[1] || !hpl_table_exists('clip_deadline_log')) {
            return null;
        }
        if (!fetch_val('SELECT 1 FROM clip_deadline_log WHERE user_id = ? AND warned_at IS NOT NULL', [(int)$user['id']], 0)) {
            return null;
        }
        return clip_deadline_done((int)$user['id'], $w[0]) ? null : $w[1];
    } catch (Throwable $e) {
        return null;
    }
}

function clip_deadline_run(bool $force = false, int $max = 25): array
{
    $out = ['warned' => 0, 'reminded' => 0, 'paused' => 0, 'done' => 0, 'skipped' => ''];
    try {
        $w = clip_deadline_window();
        if ($w === null || !hpl_table_exists('clip_deadline_log')) {
            $out['skipped'] = 'not set';
            return $out;
        }
        if (!$force && (int)setting('clip_deadline_ran_at', '0') > time() - 600) {
            $out['skipped'] = 'ran less than 10 minutes ago';
            return $out;
        }
        set_setting('clip_deadline_ran_at', (string)time());
        [$start, $end] = $w;
        $now = time();
        if ($now > $end + 7 * 86400) {
            $out['skipped'] = 'finished';
            return $out;
        }
        require_once __DIR__ . '/inactivity.php'; // inactivity_email()
        $rows = fetch_all('SELECT u.*, l.warned_at, l.reminded_at, l.paused_at FROM users u LEFT JOIN clip_deadline_log l ON l.user_id = u.id
            WHERE u.role = "creator" AND u.status = "active" AND u.created_at < FROM_UNIXTIME(' . (int)$start . ')' . (hpl_column_exists('users', 'is_demo') ? ' AND u.is_demo = 0' : '') . ' ORDER BY u.id LIMIT 5000');
        $changes = 0;
        foreach ($rows as $u) {
            if ($changes >= $max) {
                break;
            }
            $uid = (int)$u['id'];
            if (clip_deadline_done($uid, $start)) {
                $out['done']++;
                continue;
            }
            $ready = ($u['assessment_status'] ?? '') === 'passed' || (int)($u['qualified'] ?? 0) === 1;
            // Only creators who already had an account when the 3 days started (new sign-ups follow the normal rules).
            if (empty($u['warned_at']) && $now < $end) {
                [$t, $b] = clip_deadline_message('warn', $ready, $end);
                q('INSERT INTO clip_deadline_log (user_id, warned_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE warned_at = NOW()', [$uid]);
                notif_add($uid, 'reminder', $t, $b, $ready ? 'creator/index.php?tab=campaigns' : 'creator/index.php');
                inactivity_email($u, $t, $b);
                $out['warned']++;
                $changes++;
                continue;
            }
            if (!empty($u['warned_at']) && empty($u['reminded_at']) && $now >= $end - 86400 && $now < $end
                && strtotime((string)$u['warned_at']) < $now - 6 * 3600) {
                [$t, $b] = clip_deadline_message('remind', $ready, $end);
                q('UPDATE clip_deadline_log SET reminded_at = NOW() WHERE user_id = ?', [$uid]);
                notif_add($uid, 'reminder', $t, $b, $ready ? 'creator/index.php?tab=campaigns' : 'creator/index.php');
                inactivity_email($u, $t, $b);
                $out['reminded']++;
                $changes++;
                continue;
            }
            if (!empty($u['warned_at']) && empty($u['paused_at']) && $now >= $end) {
                q('UPDATE users SET status = "suspended", suspend_reason = "no_clip" WHERE id = ? AND status = "active"', [$uid]);
                q('UPDATE clip_deadline_log SET paused_at = NOW() WHERE user_id = ?', [$uid]);
                [$t, $b] = clip_deadline_message('pause', $ready, $end);
                inactivity_email($u, $t, $b . ' If you want to come back and clip seriously, write to us' . inactivity_contact() . '.');
                $out['paused']++;
                $changes++;
            }
        }
        if ($out['paused'] > 0) {
            notif_admins('info', $out['paused'] . ' creator' . ($out['paused'] === 1 ? ' was' : 's were') . ' paused: no clip in 3 days',
                'They were warned and reminded. Open an account again from the creator\'s page.', 'admin/creators.php');
        }
        set_setting('clip_deadline_last', json_encode($out + ['at' => $now]));
        $out['tiktok'] = tt_deadline_run(max(1, $max - $changes));
    } catch (Throwable $e) {
        error_log('clip_deadline_run: ' . $e->getMessage());
        $out['skipped'] = 'error';
    }
    return $out;
}

/**
 * Final TikTok warning (owner, 2026-11-13 evening): creators who have not connected a TikTok account get one last
 * warning now (bell, phone, email); whoever still has none at tt_deadline_end (Sat 14 Nov 16:00) is suspended
 * (suspend_reason no_tiktok; the sign-in page says why). Money untouched; an admin can open the account again.
 * Log: clip_deadline_log.tt_warned_at / tt_paused_at. Runs with clip_deadline_run() (background sync).
 */
function tt_deadline_run(int $max = 25): array
{
    $out = ['warned' => 0, 'paused' => 0];
    $end = (int)setting('tt_deadline_end', '0');
    if ($end <= 0 || time() > $end + 7 * 86400 || !hpl_column_exists('clip_deadline_log', 'tt_warned_at')) {
        return $out;
    }
    require_once __DIR__ . '/inactivity.php';
    $when = clip_deadline_date($end);
    $rows = fetch_all('SELECT u.*, l.tt_warned_at, l.tt_paused_at FROM users u LEFT JOIN clip_deadline_log l ON l.user_id = u.id
        WHERE u.role = "creator" AND u.status = "active" AND u.created_at < FROM_UNIXTIME(?)' . (hpl_column_exists('users', 'is_demo') ? ' AND u.is_demo = 0' : '') . '
        AND NOT EXISTS (SELECT 1 FROM tiktok_accounts t WHERE t.user_id = u.id) ORDER BY u.id LIMIT 3000', [(int)setting('tt_deadline_start', (string)time())]);
    $n = 0;
    foreach ($rows as $u) {
        if ($n >= $max) break;
        $uid = (int)$u['id'];
        if (empty($u['tt_warned_at']) && time() < $end) {
            q('INSERT INTO clip_deadline_log (user_id, tt_warned_at) VALUES (?, NOW()) ON DUPLICATE KEY UPDATE tt_warned_at = NOW()', [$uid]);
            $t = 'Last warning: connect your TikTok by ' . $when;
            $b = 'You have not connected a TikTok account yet. Connect it by ' . $when . ', or your HPL account will be suspended. It takes 1 minute: Home > Connect TikTok.';
            notif_add($uid, 'warning', $t, $b, 'creator/index.php');
            inactivity_email($u, $t, $b);
            $out['warned']++;
            $n++;
        } elseif (!empty($u['tt_warned_at']) && empty($u['tt_paused_at']) && time() >= $end) {
            q('UPDATE users SET status = "suspended", suspend_reason = "no_tiktok" WHERE id = ? AND status = "active"', [$uid]);
            q('UPDATE clip_deadline_log SET tt_paused_at = NOW() WHERE user_id = ?', [$uid]);
            inactivity_email($u, 'Your HPL account is suspended', 'You did not connect a TikTok account by ' . $when . ', so your HPL account was suspended. If you want to come back and clip, write to us' . inactivity_contact() . '.');
            $out['paused']++;
            $n++;
        }
    }
    if ($out['paused'] > 0) {
        notif_admins('info', $out['paused'] . ' creator' . ($out['paused'] === 1 ? ' was' : 's were') . ' suspended: no TikTok connected', 'They had the last warning. Open an account again from the creator\'s page.', 'admin/creators.php');
    }
    return $out;
}

