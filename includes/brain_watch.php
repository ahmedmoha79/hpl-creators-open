<?php
/**
 * HPL Brain: the watch list, the people lens and self-tuning (owner, 2026-11-12: "map out what could go wrong ... it should
 * know people ... it should not press load on the site but optimise itself").
 *
 * Every watch is one thing that can go wrong on this site, checked the way the developer would check it, on its own
 * schedule (cheap ones every hour, heavier ones every few hours), inside a time budget so a run never slows the site.
 * State per watch (last run, ok or not, one line, how long it took) is kept in setting brain_watch_state and shown on
 * Admin > Brain under "What the Brain watches".
 *
 * Watches READ. The only things they change are safe and logged as repairs: the background reader's batch size
 * (self-tuning) and rotating a huge error log. Money, creators and clips are never changed here.
 */

require_once __DIR__ . '/brain_mind.php';

/** key => [what is watched (shown to the owner), function, seconds between checks] */
function brain_watch_list(): array
{
    return [
        'sync'       => ['Clip views are read on time (so money is counted)', 'brain_watch_sync', 3600],
        'tiktok'     => ['TikTok connections keep working', 'brain_watch_tiktok', 3600],
        'budget'     => ['Campaign money does not run out by surprise', 'brain_watch_budget', 3600],
        'money'      => ['Money adds up (no campaign paid over its budget, no stuck payouts)', 'brain_watch_money', 6 * 3600],
        'email'      => ['Emails are delivered (codes, warnings)', 'brain_watch_email', 3600],
        'speed'      => ['Pages open fast', 'brain_watch_speed', 3600],
        'background' => ['Background jobs run (views, warnings, reminders)', 'brain_watch_background', 3600],
        'space'      => ['Server space and log size', 'brain_watch_space', 6 * 3600],
        'accounts'   => ['No mass fake accounts', 'brain_watch_accounts', 3600],
        'people'     => ['Knows every creator: stars, steady, volume chasers, clip grabbers, stuck', 'brain_watch_people', 6 * 3600],
        'flood'      => ['Nobody floods the server with requests (a changed app or a script)', 'brain_watch_flood', 1800],
        'reviews'    => ['Clip links are reviewed quickly and the rulebook is not too harsh', 'brain_watch_reviews', 1800],
        'reach'      => ['Creators can be reached (phone notifications turned on)', 'brain_watch_reach', 6 * 3600],
    ];
}

/** Run the watches that are due, within a time budget. Returns findings in the Brain's usual shape. */
function brain_look_watch(bool $all = false): array
{
    $state = json_decode((string)setting('brain_watch_state', '{}'), true) ?: [];
    $out = [];
    $start = microtime(true);
    foreach (brain_watch_list() as $key => [$label, $fn, $every]) {
        $last = (int)($state[$key]['at'] ?? 0);
        if (!$all && time() - $last < $every - 120) {
            continue;
        }
        if (!$all && microtime(true) - $start > 4.0) {
            break; // over budget: the rest run next hour, nobody waits
        }
        $t0 = microtime(true);
        try {
            $found = $fn();
        } catch (Throwable $e) {
            error_log('brain watch ' . $key . ': ' . $e->getMessage());
            $found = [];
        }
        $ms = (int)round((microtime(true) - $t0) * 1000);
        $real = array_values(array_filter($found, fn($f) => isset($f['key'])));
        $okNote = '';
        foreach ($found as $f) { if (!isset($f['key']) && isset($f['ok_note'])) $okNote = (string)$f['ok_note']; }
        $serious = array_values(array_filter($real, fn($f) => (float)$f['weight'] >= 0.7));
        $state[$key] = ['at' => time(), 'ok' => !$serious, 'note' => mb_substr($serious ? $serious[0]['title'] : $okNote, 0, 160), 'ms' => $ms];
        foreach ($real as $f) { $out[] = $f; }
    }
    set_setting('brain_watch_state', json_encode($state));
    return $out;
}

// ------------------------------------------------------------------------------------------------
// The watches
// ------------------------------------------------------------------------------------------------

/**
 * Views read on time. A clip whose views are not read is a clip whose money is not counted. The background reader works
 * in batches; with thousands of clips the batch must grow. The Brain tunes the batch itself (setting sync_batch) from the
 * backlog and from how long the last run took (setting autosync_last_ms), and says so.
 */
function brain_watch_sync(): array
{
    $active = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE status IN ("pending","approved") AND submitted_at >= NOW() - INTERVAL 45 DAY', [], 0);
    $stale = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE status IN ("pending","approved") AND submitted_at >= NOW() - INTERVAL 45 DAY
                             AND submitted_at < NOW() - INTERVAL 1 HOUR AND (last_fetched_at IS NULL OR last_fetched_at < NOW() - INTERVAL 12 HOUR)', [], 0);
    brain_tune_sync($active, $stale);
    $out = [];
    if ($stale >= 10 && $stale >= $active * 0.2) {
        $out[] = ['key' => 'watch:sync', 'area' => 'Money', 'title' => $stale . ' of ' . $active . ' clips have not had their views read for 12 hours',
            'people' => (int)ceil($stale / 2), 'weight' => 1.8, 'trend' => '',
            'evidence' => ['The reader takes ' . (int)setting('sync_batch', '40') . ' clips every ' . (int)setting('autosync_minutes', '30') . ' minutes', 'Last run took ' . round((int)setting('autosync_last_ms', '0') / 1000) . ' s'],
            'cause' => 'More clips than the background reader gets through, TikTok refusing reads, or no visitors for a while (the reader runs from page visits).',
            'fix' => 'The Brain already raised the batch. If it stays, check Admin > Settings > TikTok for connections that must reconnect.'];
    }
    if (!$out) $out[] = ['ok_note' => $active . ' clips, all read within 12 hours'];
    return $out;
}

/** Self-tuning of the background reader: enough per run to read every live clip about every 6 hours, never too heavy. */
function brain_tune_sync(int $active, int $stale): void
{
    $minutes = max(10, (int)setting('autosync_minutes', '30'));
    $batch = max(10, (int)setting('sync_batch', '40'));
    $lastMs = (int)setting('autosync_last_ms', '0');
    $runsPer6h = max(1, intdiv(360, $minutes));
    // Enough to read every live clip about every 6 hours, and to clear a backlog within about 2 runs.
    $need = max((int)ceil($active / $runsPer6h * 1.2), (int)ceil($stale / 2));
    $new = max(20, min(150, $need));
    if ($lastMs > 90000) {
        $new = max(20, min($new, (int)floor($batch * 0.7))); // the last run was long: lighter, whatever the backlog
    } elseif ($stale > 0 && $new < $batch) {
        $new = $batch; // never lighter while clips are still waiting
    }
    if (abs($new - $batch) >= 5) {
        set_setting('sync_batch', (string)$new);
        brain_count('repair', 'The background view reader now takes ' . $new . ' clips a run instead of ' . $batch . ' (' . $active . ' live clips, ' . $stale . ' waiting, last run ' . round($lastMs / 1000) . ' s).');
    }
    // Still not enough at 150 a run, or runs are long and clips wait: run more often (down to every 15 minutes).
    if ((($new >= 150 && $need > 150) || ($lastMs > 90000 && $stale > $new)) && $minutes > 15) {
        set_setting('autosync_minutes', '15');
        brain_count('repair', 'The background view reader now runs every 15 minutes instead of ' . $minutes . ' (too many live clips for one run).');
    }
}

/** TikTok connections: a rising share that must reconnect means views stop being read and creators get refusals. */
function brain_watch_tiktok(): array
{
    $all = (int)fetch_val('SELECT COUNT(*) FROM tiktok_accounts', [], 0);
    $bad = (int)fetch_val('SELECT COUNT(*) FROM tiktok_accounts WHERE problem IS NOT NULL AND problem <> ""', [], 0);
    $readOld = (int)fetch_val('SELECT COUNT(*) FROM tiktok_accounts WHERE (problem IS NULL OR problem = "") AND last_sync_at < NOW() - INTERVAL 2 DAY', [], 0);
    $out = [];
    if ($all >= 5 && $bad >= 3 && $bad / $all >= 0.15) {
        $out[] = ['key' => 'watch:tiktok', 'area' => 'TikTok', 'title' => $bad . ' of ' . $all . ' TikTok connections must be made again',
            'people' => $bad, 'weight' => 1.5, 'trend' => '', 'evidence' => [$readOld . ' working connections not read for 2 days'],
            'cause' => 'TikTok ended the sign-in (password change, app removed, token expired) or the app key changed.',
            'fix' => 'Admin > Settings > TikTok: press "Ask creators to connect". If almost all broke at once, check the client key and secret.'];
    }
    if (!$out) $out[] = ['ok_note' => $all . ' connections, ' . $bad . ' must reconnect'];
    return $out;
}

/** Campaign budgets: when one is about to run out, clips stop earning and creators complain. */
function brain_watch_budget(): array
{
    $out = [];
    foreach (fetch_all('SELECT c.id, c.title, c.total_budget, c.paid_amount,
                               (SELECT COALESCE(SUM(e.amount), 0) FROM earning_days e JOIN campaign_submissions s ON s.id = e.ref_id
                                 WHERE e.src = "campaign" AND s.campaign_id = c.id AND e.day >= CURDATE() - INTERVAL 3 DAY) spent3
                        FROM campaigns c WHERE c.status = "active" AND c.camp_status = "accepting" LIMIT 50') as $c) {
        $left = (float)$c['total_budget'] - (float)$c['paid_amount'];
        $perDay = (float)$c['spent3'] / 3;
        if ($left <= 0) {
            $out[] = ['key' => 'watch:budget:' . (int)$c['id'], 'area' => 'Money', 'title' => $c['title'] . ': the budget is used up but the campaign still takes clips',
                'people' => 10, 'weight' => 2.0, 'trend' => '', 'evidence' => ['Budget ' . money((float)$c['total_budget']) . ', counted ' . money((float)$c['paid_amount'])],
                'cause' => 'New clips can no longer earn from this campaign.', 'fix' => 'Add budget in Admin > Campaigns, or close the campaign so nobody posts for nothing.'];
        } elseif ($perDay > 0 && $left / $perDay < 3) {
            $out[] = ['key' => 'watch:budget:' . (int)$c['id'], 'area' => 'Money', 'title' => $c['title'] . ': budget runs out in about ' . max(1, (int)round($left / $perDay)) . ' day' . ((int)round($left / $perDay) === 1 ? '' : 's'),
                'people' => 5, 'weight' => 1.4, 'trend' => '', 'evidence' => [money($left) . ' left, about ' . money($perDay) . ' counted a day'],
                'cause' => 'Clips are earning faster than the budget lasts.', 'fix' => 'Add budget or plan the next campaign now, so creators keep earning.'];
        }
    }
    if (!$out) $out[] = ['ok_note' => 'Every open campaign has more than 3 days of budget'];
    return $out;
}

/** Money adds up: no campaign counted over its budget; no paid withdrawal without a reference. */
function brain_watch_money(): array
{
    $out = [];
    $over = fetch_all('SELECT id, title, total_budget, paid_amount FROM campaigns WHERE paid_amount > total_budget + 1 LIMIT 10');
    foreach ($over as $c) {
        $out[] = ['key' => 'watch:over:' . (int)$c['id'], 'area' => 'Money', 'title' => $c['title'] . ' counted ' . money((float)$c['paid_amount'] - (float)$c['total_budget']) . ' more than its budget',
            'people' => 10, 'weight' => 2.5, 'trend' => '', 'evidence' => ['Budget ' . money((float)$c['total_budget']) . ', counted ' . money((float)$c['paid_amount'])],
            'cause' => 'A bug in counting, or the budget was lowered after clips earned.', 'fix' => 'Ask Claude to check the campaign\'s ledger before paying anyone from it.'];
    }
    $noRef = (int)fetch_val('SELECT COUNT(*) FROM withdrawals WHERE status = "paid" AND (txn_reference IS NULL OR txn_reference = "") AND resolved_at >= NOW() - INTERVAL 30 DAY', [], 0);
    if ($noRef > 0) {
        $out[] = ['key' => 'watch:noref', 'area' => 'Money', 'title' => $noRef . ' paid withdrawal' . ($noRef === 1 ? ' has' : 's have') . ' no transaction ID',
            'people' => $noRef, 'weight' => 1.2, 'trend' => '', 'evidence' => ['Last 30 days'],
            'cause' => 'Marked paid without the mobile money reference, so a dispute cannot be checked.', 'fix' => 'Open Pay withdrawals > Paid and add the reference from your mobile money messages.'];
    }
    if (!$out) $out[] = ['ok_note' => 'Budgets and payouts add up'];
    return $out;
}

/** Emails: verification codes and warnings that never arrive look like a broken site. */
function brain_watch_email(): array
{
    $r = fetch_one('SELECT SUM(CASE WHEN label = "failed" THEN n ELSE 0 END) failed, SUM(n) total FROM brain_counts WHERE kind = "mail" AND day >= CURDATE() - INTERVAL 1 DAY') ?: [];
    $failed = (int)($r['failed'] ?? 0); $total = (int)($r['total'] ?? 0);
    if ($failed >= 3 && $failed >= $total * 0.3) {
        return [['key' => 'watch:email', 'area' => 'Bug', 'title' => $failed . ' of ' . $total . ' emails failed to send in 2 days',
            'people' => $failed, 'weight' => 1.8, 'trend' => '', 'evidence' => ['Last status: ' . (string)setting('mail_last_status', '?')],
            'cause' => 'The email settings (SMTP password changed) or the hosting stopped sending mail.', 'fix' => 'Admin > Email setup: send a test email. Fix the password or ask the hosting company.']];
    }
    return [['ok_note' => $total . ' emails in 2 days, ' . $failed . ' failed']];
}

/** Speed: pages that took more than 3 seconds on the server (counted by includes/auth.php). */
function brain_watch_speed(): array
{
    $rows = fetch_all('SELECT page, SUM(n) n FROM brain_counts WHERE kind = "slow" AND day >= CURDATE() - INTERVAL 1 DAY GROUP BY page ORDER BY n DESC LIMIT 4');
    $total = array_sum(array_map(fn($r) => (int)$r['n'], $rows));
    if ($total >= 10) {
        return [['key' => 'watch:speed', 'area' => 'Bug', 'title' => 'Pages were slow ' . $total . ' times in 2 days (over 3 seconds)',
            'people' => (int)ceil($total / 2), 'weight' => 1.3, 'trend' => '', 'evidence' => array_map(fn($r) => $r['page'] . ': ' . (int)$r['n'] . ' times', $rows),
            'cause' => 'A page doing too much work as the numbers grow, or the hosting was busy.', 'fix' => 'Ask Claude to speed up the first page listed (it is usually one heavy list or a missing index).']];
    }
    return [['ok_note' => $total . ' slow pages in 2 days']];
}

/** Background jobs run from page visits (no cron on this hosting). If they stop, views, warnings and reminders stop. */
function brain_watch_background(): array
{
    $last = (int)setting('autosync_last_at', '0');
    $every = max(10, (int)setting('autosync_minutes', '30')) * 60;
    if ($last > 0 && time() - $last > max(3 * 3600, $every * 4)) {
        return [['key' => 'watch:background', 'area' => 'Bug', 'title' => 'Background jobs have not run for ' . round((time() - $last) / 3600) . ' hours',
            'people' => 10, 'weight' => 1.6, 'trend' => '', 'evidence' => ['Last run ' . date('D H:i', $last)],
            'cause' => 'They run from page visits; with no visitors, or a failing run, nothing is read.',
            'fix' => 'Open the site once. If it stays, set up a free pinger on cron/fetch_views.php (Admin > Settings shows the address).']];
    }
    return [['ok_note' => 'Last run ' . ($last ? round((time() - $last) / 60) . ' minutes ago' : 'unknown')]];
}

/** Space: the error log can fill the hosting quota; a huge one is renamed (safe repair). */
function brain_watch_space(): array
{
    $out = [];
    $log = (string)ini_get('error_log');
    if ($log !== '' && is_file($log) && filesize($log) > 50 * 1024 * 1024) {
        if (@rename($log, $log . '.old')) {
            @unlink($log . '.old.prev');
            set_setting('brain_log_pos', '0');
            brain_count('repair', 'The error log was over 50 MB, so I put it aside and started a fresh one.');
        }
    }
    $free = @disk_free_space(BASE_PATH);
    if ($free !== false && $free < 2 * 1024 * 1024 * 1024) {
        $out[] = ['key' => 'watch:space', 'area' => 'Bug', 'title' => 'Only ' . round($free / 1024 / 1024 / 1024, 1) . ' GB of server space left',
            'people' => 20, 'weight' => 1.6, 'trend' => '', 'evidence' => ['Clip videos are the biggest files (uploads/rc)'],
            'cause' => 'Ready clip videos and footage add up.', 'fix' => 'Remove clips of closed campaigns (Admin > Ready clips) or ask the hosting for more space.'];
    }
    if (!$out) $out[] = ['ok_note' => $free !== false ? round($free / 1024 / 1024 / 1024) . ' GB free' : 'space unknown'];
    return $out;
}

/** Many new accounts from one phone in a week (team only; never shown to creators). */
function brain_watch_accounts(): array
{
    $phones = fetch_all('SELECT d.device, COUNT(DISTINCT d.user_id) n FROM user_devices d JOIN users u ON u.id = d.user_id
                         WHERE u.role = "creator" AND u.created_at >= NOW() - INTERVAL 7 DAY GROUP BY d.device HAVING n >= 3 ORDER BY n DESC LIMIT 10');
    if ($phones) {
        $acc = array_sum(array_map(fn($p) => (int)$p['n'], $phones));
        return [['key' => 'watch:accounts', 'area' => 'Checks', 'title' => count($phones) . ' phone' . (count($phones) === 1 ? '' : 's') . ' made ' . $acc . ' new accounts this week',
            'people' => $acc, 'weight' => 1.2, 'trend' => '', 'evidence' => ['3 or more accounts on the same phone'],
            'cause' => 'One person collecting clips and pay on several accounts (or a shared phone in a family).', 'fix' => 'Admin > Checks lists them under "One phone, many accounts". Decide there; the website never rejects by itself.']];
    }
    return [['ok_note' => 'No phone made 3 or more accounts this week']];
}

// ------------------------------------------------------------------------------------------------
// People: the Brain knows every creator.
// ------------------------------------------------------------------------------------------------

/**
 * Pure: one label for a creator from their numbers. $p: age_days, qualified, has_tiktok, clips_ever, sent_7d, full_days_7d
 * (days they used the whole daily allowance), avg_gained (clips sent 1-21 days ago), best_gained, reactions_per100 (null if
 * too few views), grabbed (ready clips taken and lost), quiet_days (days since last real action), per_day (normal allowance).
 * Returns [tag, why]. Tags: star, steady, chaser (posts as much as allowed with weak results), grabber, stuck, quiet, new.
 */
function brain_person_tag(array $p): array
{
    $minViews = (int)setting('clip_trust_min_views', '200');
    $starViews = 1500;
    if (function_exists('mind_site_percentile')) { // learned from this site's clips when there are enough of them
        $minViews = (int)min($minViews, max(50, mind_site_percentile('clip_views', 0.25, $minViews)));
        $starViews = (int)max(500, mind_site_percentile('clip_views', 0.9, 1500));
    }
    if ((int)$p['grabbed'] >= 2 && (int)$p['grabbed'] >= (int)$p['clips_ever']) {
        return ['grabber', 'took ' . (int)$p['grabbed'] . ' clips and did not post them'];
    }
    if ((int)$p['clips_ever'] >= 2 && ((int)$p['avg_gained'] >= $starViews || (int)$p['best_gained'] >= max(5000, $starViews * 3))) {
        return ['star', 'clips average ' . number_format((int)$p['avg_gained']) . ' views, best ' . number_format((int)$p['best_gained'])];
    }
    if ((int)$p['full_days_7d'] >= 3 && (int)$p['avg_gained'] < $minViews && ($p['reactions_per100'] === null || (float)$p['reactions_per100'] < 2)) {
        return ['chaser', 'posted the most allowed on ' . (int)$p['full_days_7d'] . ' of 7 days, but clips average ' . number_format((int)$p['avg_gained']) . ' views'];
    }
    if ((int)$p['clips_ever'] > 0 && (int)$p['quiet_days'] >= 7) {
        return ['quiet', 'no clip for ' . (int)$p['quiet_days'] . ' days'];
    }
    if ((int)$p['clips_ever'] > 0) {
        return ['steady', number_format((int)$p['avg_gained']) . ' views a clip on average'];
    }
    if ((int)$p['age_days'] >= 3) {
        return ['stuck', !(int)$p['qualified'] ? 'has not passed the test' : (!(int)$p['has_tiktok'] ? 'passed the test, TikTok not connected' : 'TikTok connected, no clip taken')];
    }
    return ['new', 'joined ' . ((int)$p['age_days'] === 0 ? 'today' : (int)$p['age_days'] . ' day' . ((int)$p['age_days'] === 1 ? '' : 's') . ' ago')];
}

/** Label every creator active in the last 60 days with a few grouped queries (no query per person). */
function brain_watch_people(): array
{
    $perDay = max(1, function_exists('ready_clips_per_day') ? ready_clips_per_day() : (int)setting('ready_clips_per_day', '2'));
    $people = [];
    foreach (fetch_all('SELECT u.id, DATEDIFF(NOW(), u.created_at) age_days, u.qualified, u.ready_warnings grabbed,
                               EXISTS (SELECT 1 FROM tiktok_accounts t WHERE t.user_id = u.id) has_tiktok
                        FROM users u WHERE u.role = "creator" AND u.status = "active" AND u.is_demo = 0
                          AND (u.created_at >= NOW() - INTERVAL 60 DAY OR u.last_seen >= NOW() - INTERVAL 60 DAY) LIMIT 5000') as $u) {
        $people[(int)$u['id']] = $u + ['clips_ever' => 0, 'sent_7d' => 0, 'full_days_7d' => 0, 'avg_gained' => 0, 'best_gained' => 0, 'reactions_per100' => null, 'quiet_days' => 999];
    }
    if (!$people) return [['ok_note' => 'No active creators']];
    foreach (fetch_all('SELECT user_id, COUNT(*) clips, DATEDIFF(NOW(), MAX(submitted_at)) quiet,
                               SUM(submitted_at >= NOW() - INTERVAL 7 DAY) sent7,
                               AVG(CASE WHEN submitted_at BETWEEN NOW() - INTERVAL 21 DAY AND NOW() - INTERVAL 1 DAY THEN GREATEST(CAST(views AS SIGNED) - CAST(COALESCE(start_views,0) AS SIGNED), 0) END) avg_g,
                               MAX(GREATEST(CAST(views AS SIGNED) - CAST(COALESCE(start_views,0) AS SIGNED), 0)) best_g
                        FROM campaign_submissions WHERE status <> "rejected" GROUP BY user_id') as $r) {
        $id = (int)$r['user_id'];
        if (!isset($people[$id])) continue;
        $people[$id]['clips_ever'] = (int)$r['clips'];
        $people[$id]['quiet_days'] = (int)$r['quiet'];
        $people[$id]['sent_7d'] = (int)$r['sent7'];
        $people[$id]['avg_gained'] = (int)round((float)$r['avg_g']);
        $people[$id]['best_gained'] = (int)$r['best_g'];
    }
    foreach (fetch_all('SELECT user_id, COUNT(*) d FROM (SELECT user_id, DATE(submitted_at) dd, COUNT(*) n FROM campaign_submissions
                            WHERE status <> "rejected" AND submitted_at >= CURDATE() - INTERVAL 7 DAY GROUP BY user_id, dd HAVING n >= ?) x GROUP BY user_id', [$perDay]) as $r) {
        if (isset($people[(int)$r['user_id']])) $people[(int)$r['user_id']]['full_days_7d'] = (int)$r['d'];
    }
    // Reactions per 100 views from the latest stats of recent clips (grouped, one query).
    foreach (fetch_all('SELECT s.user_id, SUM(c.views) v, SUM(c.likes + c.comments + c.shares) r FROM campaign_submissions s
                        JOIN clip_stats c ON c.id = (SELECT MAX(c2.id) FROM clip_stats c2 WHERE c2.submission_id = s.id)
                        WHERE s.submitted_at >= NOW() - INTERVAL 21 DAY GROUP BY s.user_id') as $r) {
        if (isset($people[(int)$r['user_id']]) && (int)$r['v'] >= 300) $people[(int)$r['user_id']]['reactions_per100'] = (int)$r['r'] / (int)$r['v'] * 100;
    }
    $count = []; $examples = [];
    foreach ($people as $id => $p) {
        [$tag, $why] = brain_person_tag($p);
        $count[$tag] = ($count[$tag] ?? 0) + 1;
        if (count($examples[$tag] ?? []) < 3) $examples[$tag][] = $id;
        q('UPDATE users SET brain_tag = ?, brain_tag_why = ?, brain_tag_at = NOW() WHERE id = ? AND (brain_tag IS NULL OR brain_tag <> ? OR brain_tag_why <> ?)',
            [$tag, mb_substr($why, 0, 200), $id, $tag, mb_substr($why, 0, 200)]);
    }
    set_setting('brain_people_counts', json_encode($count));
    $out = [];
    $n = $count['chaser'] ?? 0;
    if ($n >= 2) {
        $out[] = ['key' => 'people:chaser', 'area' => 'People', 'title' => $n . ' creators post as many clips as allowed with weak results',
            'people' => $n, 'weight' => 0.7, 'trend' => '', 'evidence' => ['They use their full daily allowance on 3 or more days a week, clips average under ' . (int)setting('clip_trust_min_views', '200') . ' views'],
            'cause' => 'Chasing the number of clips, not the views. Each weak post also tells TikTok their page is weak.',
            'fix' => 'The 1-a-day rule slows them by itself. A short HPL News post on what makes a clip work helps the honest ones.'];
    }
    $n = $count['star'] ?? 0;
    if ($n >= 1) {
        $out[] = ['key' => 'people:star', 'area' => 'People', 'title' => $n . ' creator' . ($n === 1 ? ' is' : 's are') . ' doing really well',
            'people' => $n, 'weight' => 0.3, 'trend' => '', 'evidence' => ['Clips average 1,500+ views or one reached 5,000+'],
            'cause' => 'They found what works.', 'fix' => 'Message them (Admin > Creators, filter by the Brain label), ask what they do, and share it in HPL News. Keep them happy: pay them fast.'];
    }
    $n = $count['stuck'] ?? 0;
    if ($n >= 5) {
        $out[] = ['key' => 'people:stuck', 'area' => 'People stop', 'title' => $n . ' people joined 3+ days ago and never posted a clip',
            'people' => $n, 'weight' => 0.9, 'trend' => '', 'evidence' => ['Each one\'s step is on their admin page'],
            'cause' => 'The first steps (test, TikTok, bio tag) lose them.', 'fix' => 'Admin > Send reminders to the "stuck" group with the one step each is missing.'];
    }
    $out[] = ['ok_note' => implode(', ', array_map(fn($k, $v) => $v . ' ' . $k, array_keys($count), $count))];
    return $out;
}

/** Labels shown to the team (never to creators). */
function brain_tag_labels(): array
{
    return ['star' => ['Star', 'ok'], 'steady' => ['Steady', ''], 'chaser' => ['Volume chaser', 'warn'], 'grabber' => ['Clip grabber', 'bad'],
        'stuck' => ['Stuck', 'warn'], 'quiet' => ['Quiet', 'muted'], 'new' => ['New', '']];
}

/** Request floods stopped by includes/guard.php (blocked.log). Also clears old guard files. */
function brain_watch_flood(): array
{
    require_once __DIR__ . '/guard.php';
    hpl_guard_prune();
    $log = dirname(__DIR__) . '/storage/guard/blocked.log';
    $rows = [];
    if (is_file($log)) {
        foreach (array_slice((array)file($log, FILE_IGNORE_NEW_LINES), -400) as $l) {
            $p = explode("\t", $l);
            if (count($p) >= 5 && strtotime($p[0]) > time() - 86400) $rows[] = $p;
        }
        if (filesize($log) > 2 * 1024 * 1024) { @rename($log, $log . '.old'); }
    }
    if (!$rows) return [['ok_note' => 'No request floods in 24 hours']];
    $who = array_count_values(array_column($rows, 1));
    arsort($who);
    $pages = array_count_values(array_column($rows, 4));
    arsort($pages);
    $worst = (int)reset($who);
    return [['key' => 'watch:flood', 'area' => $worst >= 5 ? 'Security' : 'Bug', 'title' => count($rows) . ' minutes of request floods were stopped in 24 hours (' . count($who) . ' browser' . (count($who) === 1 ? '' : 's') . ')',
        'people' => count($who), 'weight' => $worst >= 5 ? 1.7 : 1.0, 'trend' => '',
        'evidence' => array_merge(['Most hit: ' . implode(', ', array_slice(array_map(fn($k, $v) => $k . ' (' . $v . ')', array_keys($pages), $pages), 0, 3))], ['The same browser was stopped in ' . $worst . ' different minutes']),
        'cause' => $worst >= 5 ? 'One browser keeps sending hundreds of requests a minute: a changed app, a script, or a bug looping in one page.' : 'Short bursts: a page stuck in a loop on a bad connection, or someone testing.',
        'fix' => 'Nothing breaks: the guard answers "too many" before the database. If one browser repeats every day, ask Claude to find the account and the page it hits.']];
}

/** The clip review queue and the rulebook: fast enough, and not rejecting too much. */
function brain_watch_reviews(): array
{
    if (!hpl_column_exists('campaign_submissions', 'review_action')) return [['ok_note' => 'not set up']];
    $q = json_decode((string)setting('clip_review_queue', ''), true) ?: [];
    $r = fetch_one('SELECT COUNT(*) n, SUM(review_action = "rejected") bad, SUM(review_action = "approved") ok FROM campaign_submissions WHERE review_at > NOW() - INTERVAL 1 DAY') ?: [];
    $old = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE review_due_at IS NOT NULL AND review_due_at < NOW() - INTERVAL 30 MINUTE AND status <> "rejected"', [], 0);
    $out = [];
    if ($old >= 10) {
        $out[] = ['key' => 'watch:reviewlag', 'area' => 'Bug', 'title' => $old . ' clip links have waited over 30 minutes for their review', 'people' => $old, 'weight' => 1.4, 'trend' => '',
            'evidence' => ['Last run: ' . (int)($q['done'] ?? 0) . ' reviewed in ' . round(((int)($q['ms'] ?? 0)) / 1000, 1) . ' s'],
            'cause' => 'Too few page visits to run the queue, or TikTok answering slowly.', 'fix' => 'Admin > Brain > "Review waiting links now". If it keeps happening, ask Claude to add a pinger.'];
    }
    $n = (int)($r['n'] ?? 0);
    if ($n >= 10 && (int)$r['bad'] / $n > 0.4) {
        $out[] = ['key' => 'watch:harsh', 'area' => 'Trust', 'title' => 'The Brain rejected ' . (int)$r['bad'] . ' of ' . $n . ' clips in 24 hours', 'people' => (int)$r['bad'], 'weight' => 1.5, 'trend' => '',
            'evidence' => ['Approved: ' . (int)$r['ok']], 'cause' => 'Either many wrong clips (a cheater wave) or a rule or TikTok answer that is wrong.',
            'fix' => 'Open Admin > Clips, filter Rejected, and look at 5. If honest clips are among them, tell Claude which rule.'];
    }
    return $out ?: [['ok_note' => $n . ' reviewed in 24 h, ' . (int)($r['bad'] ?? 0) . ' rejected']];
}

/** How many creators can be reached on their phone (push turned on). */
function brain_watch_reach(): array
{
    $all = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND status = "active"', [], 0);
    $on = hpl_column_exists('users', 'push_on') ? (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND status = "active" AND push_on = 1', [], 0) : 0;
    if ($all >= 20 && $on / max(1, $all) < 0.3) {
        return [['key' => 'watch:reach', 'area' => 'People stop', 'title' => 'Only ' . $on . ' of ' . $all . ' creators get notifications on their phone', 'people' => $all - $on, 'weight' => 1.2, 'trend' => '',
            'evidence' => ['Messages, new clips and warnings reach the others only when they open the site'],
            'cause' => 'Phone notifications must be allowed by each person (Home asks once, after the town).',
            'fix' => 'Remind creators in HPL News to tap "Turn on" on Home; on iPhone the site must be added to the home screen first.']];
    }
    return [['ok_note' => $on . ' of ' . $all . ' creators get phone notifications']];
}

