<?php
/**
 * Inactive creators (owner, 2026-11-11): people who sign up and then do nothing are warned, step by step,
 * and finally paused (suspended), so the programme keeps only people who really clip.
 *
 * What counts as "doing something" (app visits alone do NOT count, otherwise opening the app would be enough):
 *   signing up, taking the test, connecting TikTok, taking a ready clip, sending a clip link,
 *   and the day the team opened the account again.
 *
 * Steps (days without doing something; all editable in Admin > Inactive creators):
 *   1. after  5 days: a friendly reminder (bell, phone, email);
 *   2. after 10 days: a last warning with the exact date the account will be paused;
 *   3. after 15 days: the account is paused (users.status = 'suspended', suspend_reason = 'inactive').
 * Between warning 1 and 2 there are always at least 2 days, and between warning 2 and the pause at least 3,
 * so nobody is ever paused without having had both warnings in time.
 *
 * Never paused automatically (they stay at the last warning and show in the admin list as "needs you"):
 *   creators with money they can still withdraw, a withdrawal waiting, or a clip waiting for approval
 *   (money must never be trapped by an automatic rule), and the HPL team.
 * Fair to everyone:
 *   - the clock started when this rule went live (setting inactivity_start), nobody was paused for the past;
 *   - while there is no ready clip to take, creators who passed the test are not moved to a next step
 *     (they cannot clip if there is nothing to clip);
 *   - doing something at any step puts them back to zero at once; when the team opens a paused account
 *     again, its clock starts again from that day.
 * Earned money is never touched.
 */

function inactivity_on(): bool
{
    return (string)setting('inactivity_on', '1') === '1';
}

/** The three day counts: [reminder, last warning, pause]. */
function inactivity_days(): array
{
    $a = max(2, (int)setting('inactivity_warn1_days', '5'));
    $b = max($a + 2, (int)setting('inactivity_warn2_days', '10'));
    $c = max($b + 3, (int)setting('inactivity_pause_days', '15'));
    return [$a, $b, $c];
}

/** The day the rule went live (made on the first run). */
function inactivity_start(): int
{
    $t = (int)setting('inactivity_start', '0');
    if ($t <= 0) {
        $t = time();
        set_setting('inactivity_start', (string)$t);
    }
    return $t;
}

/** SQL for the unix time of each creator's last real action (u = users). */
function inactivity_last_sql(): string
{
    return 'GREATEST(
        UNIX_TIMESTAMP(u.created_at),
        COALESCE(UNIX_TIMESTAMP(u.active_reset_at), 0),
        COALESCE((SELECT UNIX_TIMESTAMP(MAX(a.taken_at)) FROM assessments a WHERE a.user_id = u.id), 0),
        COALESCE((SELECT UNIX_TIMESTAMP(MAX(t.connected_at)) FROM tiktok_accounts t WHERE t.user_id = u.id), 0),
        COALESCE((SELECT UNIX_TIMESTAMP(MAX(s.submitted_at)) FROM campaign_submissions s WHERE s.user_id = u.id), 0),
        COALESCE((SELECT UNIX_TIMESTAMP(MAX(r.claimed_at)) FROM ready_clips r WHERE r.claimed_by = u.id), 0)
    )';
}

/** Is there anything to clip right now (a free ready clip in an open campaign)? */
function inactivity_work_available(): bool
{
    try {
        return (bool)fetch_val('SELECT 1 FROM ready_clips r JOIN campaigns c ON c.id = r.campaign_id
            WHERE r.is_active = 1 AND r.claimed_by IS NULL AND c.status = "active" AND COALESCE(c.camp_status, "accepting") = "accepting" LIMIT 1', [], 0);
    } catch (Throwable $e) {
        return true;
    }
}

/** Why this creator must not be paused automatically ('' = nothing stops it). */
function inactivity_protected(array $u): string
{
    $uid = (int)$u['id'];
    if (fetch_val('SELECT 1 FROM withdrawals WHERE user_id = ? AND status = "pending" LIMIT 1', [$uid], 0)) {
        return 'a withdrawal is waiting to be paid';
    }
    if (fetch_val('SELECT 1 FROM campaign_submissions WHERE user_id = ? AND status = "pending" LIMIT 1', [$uid], 0)) {
        return 'a clip is waiting for approval';
    }
    try {
        require_once __DIR__ . '/earnings.php';
        $st = creator_stats($uid);
        if ((float)($st['available'] ?? 0) > 0.009) {
            return 'has ' . money((float)$st['available']) . ' to withdraw';
        }
    } catch (Throwable $e) {
        return 'balance could not be read';
    }
    return '';
}

/** The words of each step, for someone who has passed the test ($ready) or not yet. */
function inactivity_message(int $stage, bool $ready, int $pauseAt): array
{
    $date = date('D j M', $pauseAt);
    $todo = $ready ? 'take a ready clip, post it on your TikTok and send the link' : 'finish the steps on your Home page (the test, then connect TikTok)';
    if ($stage === 1) {
        return ['We have not seen a clip from you', 'It has been a few days. To keep your HPL account, ' . $todo . '. Clips are waiting for you.'];
    }
    if ($stage === 2) {
        return ['Last warning: your account will be paused on ' . $date, 'You have not done anything on HPL for a long time. If you do not ' . $todo . ' before ' . $date . ', your account will be paused and you will not be able to sign in.'];
    }
    return ['Your account is paused', 'You did not ' . ($ready ? 'post a clip' : 'finish your steps') . ' for a long time, so your HPL account was paused.'];
}

/**
 * The run: look at every active creator and move them to the right step. Called by the background sync,
 * at most every 20 minutes ($force from the admin page). Returns counts for the admin page.
 * At most 25 step changes a run, because each also sends an email: a big group reaching the same day
 * is spread over a few hours instead of one run that is too slow.
 */
function inactivity_run(bool $force = false, int $max = 25): array
{
    $out = ['checked' => 0, 'reset' => 0, 'warn1' => 0, 'warn2' => 0, 'paused' => 0, 'protected' => 0, 'waiting_for_work' => 0, 'skipped' => ''];
    try {
        if (!hpl_column_exists('users', 'inactive_stage')) {
            $out['skipped'] = 'not ready';
            return $out;
        }
        if (!inactivity_on()) {
            $out['skipped'] = 'switched off';
            return $out;
        }
        if (!$force && (int)setting('inactivity_ran_at', '0') > time() - 1200) {
            $out['skipped'] = 'ran less than 20 minutes ago';
            return $out;
        }
        set_setting('inactivity_ran_at', (string)time());
        [$d1, $d2, $d3] = inactivity_days();
        $start = inactivity_start();
        $now = time();
        $work = inactivity_work_available();

        // The team opened a paused account again (here or on the creator's page): its clock starts again today.
        q('UPDATE users SET inactive_stage = 0, inactive_stage_at = NULL, active_reset_at = NOW(), suspend_reason = NULL
            WHERE role = "creator" AND status = "active" AND inactive_stage = 3');

        $rows = fetch_all('SELECT u.*, ' . inactivity_last_sql() . ' AS last_act FROM users u
            WHERE u.role = "creator" AND u.status = "active"' . (hpl_column_exists('users', 'is_demo') ? ' AND u.is_demo = 0' : '') . ' ORDER BY u.id LIMIT 5000');
        $changes = 0;
        foreach ($rows as $u) {
            $out['checked']++;
            $uid = (int)$u['id'];
            $stage = (int)$u['inactive_stage'];
            $stageAt = !empty($u['inactive_stage_at']) ? strtotime((string)$u['inactive_stage_at']) : 0;
            $last = max((int)$u['last_act'], $start);
            $idle = ($now - $last) / 86400;

            // Did something since the last step: back to zero, quietly.
            if ($stage > 0 && $last >= $stageAt) {
                q('UPDATE users SET inactive_stage = 0, inactive_stage_at = NULL WHERE id = ?', [$uid]);
                $out['reset']++;
                continue;
            }
            $want = $idle >= $d3 ? 3 : ($idle >= $d2 ? 2 : ($idle >= $d1 ? 1 : 0));
            if ($want <= $stage) {
                continue;
            }
            $ready = ($u['assessment_status'] ?? '') === 'passed' || (int)($u['qualified'] ?? 0) === 1;
            if ($ready && !$work) {
                $out['waiting_for_work']++;
                continue; // nothing to clip: do not punish
            }
            // One step at a time, with time between them.
            $next = $stage + 1;
            if ($next === 2 && $stageAt > $now - 2 * 86400) { continue; }
            if ($next === 3 && $stageAt > $now - 3 * 86400) { continue; }
            if ($changes >= $max) { break; }
            $pauseAt = max($last + $d3 * 86400, $now + 3 * 86400);
            if ($next === 3) {
                $why = inactivity_protected($u);
                if ($why !== '') {
                    $out['protected']++;
                    continue; // stays at the last warning; the admin list shows it
                }
                q('UPDATE users SET status = "suspended", suspend_reason = "inactive", inactive_stage = 3, inactive_stage_at = NOW() WHERE id = ? AND status = "active"', [$uid]);
                [$title, $body] = inactivity_message(3, $ready, $pauseAt);
                inactivity_email($u, $title, $body . ' If you want to come back and clip seriously, write to us' . inactivity_contact() . '.');
                $out['paused']++;
            } else {
                q('UPDATE users SET inactive_stage = ?, inactive_stage_at = NOW() WHERE id = ?', [$next, $uid]);
                [$title, $body] = inactivity_message($next, $ready, $pauseAt);
                notif_add($uid, 'reminder', $title, $body, $ready ? 'creator/index.php?tab=campaigns' : 'creator/index.php');
                inactivity_email($u, $title, $body);
                $out[$next === 1 ? 'warn1' : 'warn2']++;
            }
            $changes++;
        }
        if ($out['paused'] > 0) {
            notif_admins('info', $out['paused'] . ' inactive creator' . ($out['paused'] === 1 ? ' was' : 's were') . ' paused',
                'They were warned twice and did nothing. See Admin > Inactive creators to open an account again.', 'admin/inactivity.php');
        }
        set_setting('inactivity_last_result', json_encode($out + ['at' => $now]));
    } catch (Throwable $e) {
        error_log('inactivity_run: ' . $e->getMessage());
        $out['skipped'] = 'error';
    }
    return $out;
}

/** " on WhatsApp 0966 ..." when a support number is set. */
function inactivity_contact(): string
{
    $sp = trim((string)setting('support_phone', ''));
    return $sp !== '' ? ' on WhatsApp ' . $sp : '';
}

/** Also by email (a warning in the app alone is not seen by someone who stopped opening it). Never throws. */
function inactivity_email(array $u, string $title, string $body): void
{
    try {
        if (empty($u['email']) || !filter_var((string)$u['email'], FILTER_VALIDATE_EMAIL)) {
            return;
        }
        require_once __DIR__ . '/mailer.php';
        $first = explode(' ', trim((string)$u['full_name']))[0] ?: 'there';
        $link = abs_url('login.php');
        $html = '<p>Hi ' . e($first) . ',</p><p>' . e($body) . '</p><p><a href="' . e($link) . '">Open HPL Creators</a></p><p>HPL Creators</p>';
        app_mail((string)$u['email'], $title, $html, "Hi $first,\n\n$body\n\n$link\n\nHPL Creators");
    } catch (Throwable $e) {
    }
}

/** For the creator's Home: [stage, unix time of the pause] while a warning is running, or null. */
function inactivity_notice(array $user): ?array
{
    try {
        $stage = (int)($user['inactive_stage'] ?? 0);
        if ($stage < 1 || $stage > 2 || !inactivity_on()) {
            return null;
        }
        [, , $d3] = inactivity_days();
        $last = (int)fetch_val('SELECT ' . inactivity_last_sql() . ' FROM users u WHERE u.id = ?', [(int)$user['id']], 0);
        $last = max($last, inactivity_start());
        $stageAt = !empty($user['inactive_stage_at']) ? strtotime((string)$user['inactive_stage_at']) : time();
        if ($last >= $stageAt) {
            return null; // already did something; the next run clears the step
        }
        $pause = max($last + $d3 * 86400, ($stage === 2 ? $stageAt : time()) + 3 * 86400);
        return [$stage, $pause];
    } catch (Throwable $e) {
        return null;
    }
}
