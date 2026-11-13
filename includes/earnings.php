<?php
/**
 * Earnings, balance and submission-limit helpers.
 */

/** Aggregate a creator's views / earnings / balance. */
function creator_stats(int $userId): array
{
    $row = fetch_one(
        'SELECT COALESCE(SUM(CASE WHEN status <> "rejected" THEN views ELSE 0 END),0) AS views,
                COALESCE(SUM(earned),0) AS earned,
                COUNT(*) AS links
           FROM content_links
          WHERE user_id = ?',
        [$userId]
    ) ?: ['views' => 0, 'earned' => 0, 'links' => 0];

    // Campaign money only becomes withdrawable once the team approves the clip.
    // Until then it shows as "waiting for review".
    $campRow = fetch_one(
        'SELECT COALESCE(SUM(CASE WHEN status <> "rejected" THEN GREATEST(CAST(views AS SIGNED) - CAST(COALESCE(start_views, views) AS SIGNED), 0) ELSE 0 END),0) AS views,
                COALESCE(SUM(earned),0) AS earned,
                COALESCE(SUM(CASE WHEN status = "pending" THEN earned ELSE 0 END),0) AS in_review
           FROM campaign_submissions
          WHERE user_id = ?',
        [$userId]
    ) ?: ['views' => 0, 'earned' => 0, 'in_review' => 0];

    $withdrawn = (float)fetch_val(
        'SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id = ? AND status IN ("pending","paid")',
        [$userId],
        0
    );
    $paidOut = (float)fetch_val(
        'SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id = ? AND status = "paid"',
        [$userId],
        0
    );

    require_once __DIR__ . '/bonus.php';
    $bonus = bonus_total($userId);
    $totalEarned = round((float)$row['earned'] + (float)$campRow['earned'] + $bonus, 2);
    $inReview = round((float)$campRow['in_review'], 2);
    $totalViews = (int)$row['views'] + (int)$campRow['views'];
    // What the creator could withdraw right now. Negative when money was taken back
    // (a clip rejected after it was paid out): future earnings cover that first.
    // Sticker packs bought from the wallet (2026-11-09) come off what can be withdrawn.
    $spent = 0.0;
    try {
        $spent = (float)fetch_val('SELECT COALESCE(SUM(price),0) FROM sticker_purchases WHERE user_id = ?', [$userId], 0);
    } catch (Throwable $e) {
    }
    $available = round($totalEarned - $inReview - $withdrawn - $spent, 2);
    return [
        'views'     => $totalViews,
        'earned'    => $totalEarned,
        'in_review' => $inReview,
        'links'     => (int)$row['links'],
        'bonus'     => $bonus,
        'reserved'  => round($withdrawn, 2),
        'paid'      => round($paidOut, 2),
        'spent'     => round($spent, 2),
        'available' => $available,
        'balance'   => max(0.0, $available),
    ];
}

/** Links a creator has submitted today. */
function links_submitted_today(int $userId): int
{
    return (int)fetch_val(
        'SELECT COUNT(*) FROM content_links WHERE user_id = ? AND DATE(submitted_at) = CURDATE()',
        [$userId],
        0
    );
}

/** Maximum links a creator may still submit today. */
function links_remaining_today(int $userId): int
{
    return max(0, (int)setting('max_daily_links', 20) - links_submitted_today($userId));
}

/** Rate per 1,000 views. */
function rate_per_1000(): float
{
    return (float)setting('rate_per_1000', 10);
}

/**
 * How views turn into money (admin setting "earnings_mode"):
 *   exact:  every view is credited pro-rata (rate / 1,000 per view), rounded down to the cent (default).
 *   blocks: a clip is credited each time it passes a whole 1,000 views.
 */
function earnings_mode(): string
{
    return setting('earnings_mode', 'exact') === 'blocks' ? 'blocks' : 'exact';
}

/**
 * Do clips submitted outside campaigns ("My clips") earn money? Off by default:
 * all earning goes through campaigns, so every kwacha comes off a campaign budget.
 */
function general_clips_enabled(): bool
{
    return setting('general_clips_enabled', '0') === '1';
}

/** Where a creator goes to submit a clip. */
function clip_submit_url(): string
{
    return general_clips_enabled() ? 'creator/submit_links.php' : 'creator/index.php?tab=campaigns';
}

/** Default most one creator can earn from a single campaign (Settings; 0 = no limit). */
function campaign_creator_cap_default(): float
{
    return max(0.0, round((float)setting('campaign_creator_cap', 3000), 2));
}

/** Most one creator can earn from this campaign (campaign's own value, else the default). 0 = no limit. */
function campaign_creator_cap(array $campaign): float
{
    if (array_key_exists('creator_cap', $campaign) && $campaign['creator_cap'] !== null && $campaign['creator_cap'] !== '') {
        return max(0.0, round((float)$campaign['creator_cap'], 2));
    }
    return campaign_creator_cap_default();
}

/** What a creator has earned from one campaign so far. */
function campaign_creator_earned(int $campaignId, int $userId): float
{
    return (float)fetch_val('SELECT COALESCE(SUM(earned),0) FROM campaign_submissions WHERE campaign_id = ? AND user_id = ?', [$campaignId, $userId], 0);
}

/**
 * Re-count what a campaign has paid from its clips (budget used = sum of creators' earnings).
 * Call inside a transaction that already locked the campaign row.
 */
function campaign_recalc_paid(int $campaignId): float
{
    $paid = round((float)fetch_val('SELECT COALESCE(SUM(earned),0) FROM campaign_submissions WHERE campaign_id = ?', [$campaignId], 0) + campaign_paid_before($campaignId), 2);
    q('UPDATE campaigns SET paid_amount = ? WHERE id = ?', [$paid, $campaignId]);
    return $paid;
}

/**
 * Money already paid for this campaign outside its clip tracking (typed in by the team,
 * e.g. a creator who earned from this campaign's footage through "My clips"). Part of the used budget.
 */
function campaign_paid_before(int $campaignId): float
{
    try {
        return round((float)fetch_val('SELECT paid_before FROM campaigns WHERE id = ?', [$campaignId], 0), 2);
    } catch (Throwable $e) {
        return 0.0; // column not added yet
    }
}

/**
 * Admin changes a campaign clip's status. Rejecting takes its money back out of the
 * creator's balance and returns it to the campaign budget. Approving it again lets the
 * next view check pay it from its views as normal.
 */
function campaign_submission_set_status(int $subId, string $status, string $reason = ''): bool
{
    if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
        return false;
    }
    $before = null;
    $ok = (bool)hpl_tx(function () use ($subId, $status, &$before) {
        $sub = fetch_one('SELECT * FROM campaign_submissions WHERE id = ? FOR UPDATE', [$subId]);
        if (!$sub) {
            return false;
        }
        $before = $sub;
        fetch_one('SELECT id FROM campaigns WHERE id = ? FOR UPDATE', [(int)$sub['campaign_id']]);
        if ($status === 'rejected') {
            q('UPDATE campaign_submissions SET status = "rejected", earned = 0 WHERE id = ?', [$subId]);
            // The history (today / this month) must show the money leaving too.
            earning_day_money((int)$sub['user_id'], 'campaign', $subId, -(float)$sub['earned']);
        } else {
            q('UPDATE campaign_submissions SET status = ? WHERE id = ?', [$status, $subId]);
        }
        campaign_recalc_paid((int)$sub['campaign_id']);
        return true;
    });
    // Tell the creator, from wherever the decision was made (website or Telegram).
    if ($ok && $before && $before['status'] !== $status && $status !== 'pending') {
        $title = (string)fetch_val('SELECT title FROM campaigns WHERE id = ?', [(int)$before['campaign_id']], 'campaign');
        if ($status === 'approved') {
            $earned = (float)fetch_val('SELECT earned FROM campaign_submissions WHERE id = ?', [$subId], 0);
            notif_add((int)$before['user_id'], 'earning', 'Your clip was approved',
                'Your ' . $title . ' clip was approved. ' . ($earned > 0 ? money($earned) . ' is now in your balance.' : 'Its money moves to your balance as views come in.'), 'creator/earnings.php');
        } else {
            notif_add((int)$before['user_id'], 'earning', 'Your clip was not approved',
                'Your ' . $title . ' clip was not approved. ' . ($reason !== '' ? $reason . ' ' : '') . 'It will not earn money.', 'creator/campaign.php?id=' . (int)$before['campaign_id']);
        }
    }
    return $ok;
}

/** Admin corrects a campaign clip's views/earnings; the campaign's used budget follows. */
function campaign_submission_admin_update(int $subId, int $views, float $earned): bool
{
    return (bool)hpl_tx(function () use ($subId, $views, $earned) {
        $sub = fetch_one('SELECT * FROM campaign_submissions WHERE id = ? FOR UPDATE', [$subId]);
        if (!$sub) {
            return false;
        }
        fetch_one('SELECT id FROM campaigns WHERE id = ? FOR UPDATE', [(int)$sub['campaign_id']]);
        // A rejected clip earns nothing, so its money cannot be typed back in.
        $earned = ($sub['status'] ?? '') === 'rejected' ? 0.0 : max(0.0, round($earned, 2));
        // credited_views never goes down: if the team lowers the views (e.g. fake views), the
        // next view check must not pay those same views again. It only pays views above the
        // highest count already counted.
        q('UPDATE campaign_submissions SET views = ?, credited_views = GREATEST(credited_views, ?), earned = ?, accrued = ? WHERE id = ?', [max(0, $views), max(0, $views), $earned, $earned, $subId]);
        earning_day_money((int)$sub['user_id'], 'campaign', $subId, $earned - (float)$sub['earned']);
        campaign_recalc_paid((int)$sub['campaign_id']);
        return true;
    });
}

/** Admin deletes a campaign clip; its money leaves the creator's balance and returns to the budget. */
function campaign_submission_delete(int $subId): bool
{
    return (bool)hpl_tx(function () use ($subId) {
        $sub = fetch_one('SELECT * FROM campaign_submissions WHERE id = ? FOR UPDATE', [$subId]);
        if (!$sub) {
            return false;
        }
        fetch_one('SELECT id FROM campaigns WHERE id = ? FOR UPDATE', [(int)$sub['campaign_id']]);
        q('DELETE FROM campaign_submissions WHERE id = ?', [$subId]);
        // A ready clip whose link is deleted goes back to its creator, who can send the right link.
        try { q('UPDATE ready_clips SET submission_id = NULL WHERE submission_id = ?', [$subId]); } catch (Throwable $e) {}
        earning_day_money((int)$sub['user_id'], 'campaign', $subId, -(float)$sub['earned']);
        campaign_recalc_paid((int)$sub['campaign_id']);
        return true;
    });
}

/**
 * After money is taken back from a creator: a note for the admin when they had already
 * withdrawn it, so their balance is now below zero (their next earnings cover it first).
 */
function campaign_debt_note(int $subId, int $userId = 0): string
{
    if ($userId <= 0) {
        $userId = (int)fetch_val('SELECT user_id FROM campaign_submissions WHERE id = ?', [$subId], 0);
    }
    if ($userId <= 0) {
        return '';
    }
    $st = creator_stats($userId);
    $pending = (float)fetch_val('SELECT COALESCE(SUM(amount),0) FROM withdrawals WHERE user_id = ? AND status = "pending"', [$userId], 0);
    if ($st['available'] < 0) {
        return ' Note: this creator had already withdrawn or requested ' . money(-$st['available']) . ' of it.'
            . ($pending > 0 ? ' Check their pending withdrawal before paying it.' : ' Their next earnings will cover it first.');
    }
    return '';
}

/** Video platforms a campaign can pay on. */
function campaign_platform_keys(): array
{
    return ['tiktok', 'instagram', 'facebook', 'youtube'];
}

/**
 * A campaign's platforms with their terms. Each entry gets 'rate' (what a creator is paid per
 * 1,000 views) and 'own_rate' (true when the campaign sets its own price instead of the standard rate).
 * A campaign with no platforms saved is open on every video platform at the standard rate.
 */
function campaign_platform_list(array $campaign): array
{
    $saved = json_decode((string)($campaign['platforms'] ?? '') ?: '[]', true);
    $list = [];
    foreach (is_array($saved) ? $saved : [] as $p) {
        if (is_array($p) && !empty($p['key'])) {
            $list[] = $p;
        }
    }
    if (!$list) {
        foreach (campaign_platform_keys() as $k) {
            $list[] = ['key' => $k, 'perThousandViews' => 0, 'minPayout' => 0, 'maxPayout' => 0];
        }
    }
    $out = [];
    foreach ($list as $p) {
        $own = (float)($p['perThousandViews'] ?? 0);
        $p['own_rate'] = $own > 0;
        $p['rate'] = $own > 0 ? $own : rate_per_1000();
        $out[] = $p;
    }
    return $out;
}

/** Highest rate a campaign pays (for cards: "K10.00 / 1K views"). */
function campaign_best_rate(array $campaign): float
{
    return max(array_map(function ($p) { return (float)$p['rate']; }, campaign_platform_list($campaign)));
}

/**
 * Build a campaign's platform terms from the admin form:
 * camp_rate (empty = standard rate), camp_min, camp_max, camp_plats[].
 * Returns [platforms array, error message or null].
 */
function campaign_platforms_from_form(): array
{
    $rateRaw = trim((string)($_POST['camp_rate'] ?? ''));
    $rate = $rateRaw === '' ? 0.0 : round((float)$rateRaw, 2);
    $min = round((float)($_POST['camp_min'] ?? 0), 2);
    $max = round((float)($_POST['camp_max'] ?? 0), 2);
    if ($rate < 0 || $min < 0 || $max < 0) {
        return [[], 'Rates and limits cannot be negative.'];
    }
    if ($max > 0 && $min > $max) {
        return [[], 'The minimum per clip cannot be higher than the cap per clip.'];
    }
    $picked = array_map('strval', (array)($_POST['camp_plats'] ?? []));
    $keys = array_values(array_intersect(campaign_platform_keys(), $picked));
    if (!$keys) {
        return [[], 'Pick at least one platform for this campaign.'];
    }
    $out = [];
    foreach ($keys as $k) {
        $out[] = ['key' => $k, 'perThousandViews' => $rate, 'minPayout' => $min, 'maxPayout' => $max];
    }
    return [$out, null];
}

/** Per-platform terms of a campaign: rate per 1,000 views, minimum and maximum payout per clip. */
function campaign_terms(string $platformsJson, string $platform): array
{
    $terms = ['rate' => 0.0, 'min' => 0.0, 'max' => 0.0];
    foreach (json_decode($platformsJson ?: '[]', true) ?: [] as $p) {
        if (($p['key'] ?? '') === $platform) {
            $terms = [
                'rate' => (float)($p['perThousandViews'] ?? 0),
                'min'  => (float)($p['minPayout'] ?? 0),
                'max'  => (float)($p['maxPayout'] ?? 0),
            ];
            break;
        }
    }
    if ($terms['rate'] <= 0) {
        $terms['rate'] = rate_per_1000();
    }
    return $terms;
}

/** Value of a number of views in currency, rounded down to the cent. */
function views_value(int $views, ?float $rate = null): float
{
    $rate = $rate ?? rate_per_1000();
    return floor(round($views * $rate / 10, 6)) / 100;
}

/** Run $fn inside a database transaction (rolls back and rethrows on error). */
function hpl_tx(callable $fn)
{
    $pdo = db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $out = $fn();
        $pdo->commit();
        return $out;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Add a clip's views and money to the per-day roll-up that powers "today",
 * the earnings chart and the activity list. One row per clip per day.
 */
function earning_day_add(int $userId, string $src, int $refId, int $viewsGained, float $amount): void
{
    if ($viewsGained <= 0 && $amount <= 0) {
        return;
    }
    try {
        q(
            'INSERT INTO earning_days (user_id, src, ref_id, day, views_gained, amount) VALUES (?,?,?,CURDATE(),?,?)
             ON DUPLICATE KEY UPDATE views_gained = views_gained + VALUES(views_gained), amount = amount + VALUES(amount)',
            [$userId, $src, $refId, max(0, $viewsGained), max(0, $amount)]
        );
    } catch (Throwable $e) {
        // table not created yet: never block a payout over the roll-up
    }
}

/**
 * Record money added (+) or taken back (-) by the team today, so "today", "this month"
 * and the activity list match the balance. Views are not touched.
 */
function earning_day_money(int $userId, string $src, int $refId, float $delta): void
{
    $delta = round($delta, 2);
    if (abs($delta) < 0.005) {
        return;
    }
    try {
        q('INSERT INTO earning_days (user_id, src, ref_id, day, views_gained, amount) VALUES (?,?,?,CURDATE(),0,?)
           ON DUPLICATE KEY UPDATE amount = amount + VALUES(amount)', [$userId, $src, $refId, $delta]);
    } catch (Throwable $e) {
    }
}

/**
 * Credit a link's earnings based on its current view count. Returns the amount credited.
 * Safe when two reads of the same link run at the same moment: the row is locked,
 * so the same views can never be paid twice.
 */
function credit_link_earnings(int $linkId, int $newViews, string $source = 'auto'): float
{
    return (float)hpl_tx(function () use ($linkId, $newViews, $source) {
        $link = fetch_one('SELECT id, user_id, views, credited_views, status FROM content_links WHERE id = ? FOR UPDATE', [$linkId]);
        if (!$link) {
            return 0.0;
        }
        $oldViews = (int)$link['views'];
        $newViews = max($newViews, $oldViews); // views never go backwards
        $rate = rate_per_1000();
        $credited = (int)$link['credited_views'];
        if (!general_clips_enabled()) {
            // Only campaign clips earn: keep the view count up to date, pay nothing. The views
            // are also marked as counted, so switching paid clips back on later does not pay
            // for views gained while it was off.
            q('UPDATE content_links SET views = ?, credited_views = GREATEST(credited_views, ?), last_fetched_at = NOW() WHERE id = ?', [$newViews, $newViews, $linkId]);
            return 0.0;
        }

        // Flagged clips keep their view count but earn nothing until the team clears them.
        $payable = $link['status'] !== 'active' ? $credited
            : (earnings_mode() === 'exact' ? $newViews : intdiv($newViews, 1000) * 1000);

        $amount = 0.0;
        $creditedViews = $credited;
        if ($payable > $credited) {
            $amount = max(0.0, round(views_value($payable, $rate) - views_value($credited, $rate), 2));
            $creditedViews = $payable;
        }

        q(
            'UPDATE content_links SET views = ?, credited_views = ?, earned = earned + ?, last_fetched_at = NOW() WHERE id = ?',
            [$newViews, $creditedViews, $amount, $linkId]
        );

        $gained = $newViews - $oldViews;
        // The detailed log keeps money events and the first read only; small view
        // changes go to the daily roll-up instead of flooding the activity list.
        if ($amount > 0 || ($oldViews === 0 && $newViews > 0)) {
            q(
                'INSERT INTO view_fetch_logs (link_id, views_before, views_after, views_gained, amount_credited, source) VALUES (?,?,?,?,?,?)',
                [$linkId, $oldViews, $newViews, max(0, $gained), $amount, $source]
            );
        }
        earning_day_add((int)$link['user_id'], 'link', $linkId, $gained, $amount);
        return $amount;
    });
}

/**
 * A manual change to a creator's earnings on one clip, made by the team.
 * Recorded with its reason, added to the daily roll-up and announced to the creator.
 * Refuses anything that would make the clip's earnings or the creator's balance negative
 * (money already withdrawn cannot be taken back here). Returns null on success, or an error.
 */
function earnings_adjust(int $adminId, int $linkId, float $amount, string $reason): ?string
{
    $amount = round($amount, 2);
    $reason = trim(mb_substr($reason, 0, 255));
    if ($amount == 0.0) {
        return 'Enter an amount to add (e.g. 20) or remove (e.g. -20).';
    }
    if (mb_strlen($reason) < 5) {
        return 'Add a short reason. The creator sees it.';
    }
    return hpl_tx(function () use ($adminId, $linkId, $amount, $reason) {
        $link = fetch_one('SELECT id, user_id, title, platform, earned FROM content_links WHERE id = ? FOR UPDATE', [$linkId]);
        if (!$link) {
            return 'Clip not found.';
        }
        $uid = (int)$link['user_id'];
        q('SELECT id FROM users WHERE id = ? FOR UPDATE', [$uid]);
        if ((float)$link['earned'] + $amount < 0) {
            return 'This clip has only earned ' . money($link['earned']) . '. You cannot remove more than that.';
        }
        if ($amount < 0 && (float)creator_stats($uid)['balance'] + $amount < 0) {
            return 'The creator has already withdrawn this money. Their balance would go below zero.';
        }
        q('UPDATE content_links SET earned = earned + ? WHERE id = ?', [$amount, $linkId]);
        q('INSERT INTO earning_adjustments (user_id, link_id, amount, reason, admin_id) VALUES (?,?,?,?,?)', [$uid, $linkId, $amount, $reason, $adminId]);
        $adjId = (int)db()->lastInsertId();
        try {
            q('INSERT INTO earning_days (user_id, src, ref_id, day, views_gained, amount) VALUES (?, "adjust", ?, CURDATE(), 0, ?)', [$uid, $adjId, $amount]);
        } catch (Throwable $e) {
        }
        $label = trim((string)$link['title']) ?: platform_label($link['platform']) . ' clip';
        notif_add($uid, 'earning', ($amount > 0 ? 'Earnings added: +' : 'Earnings corrected: ') . money($amount),
            $label . '. Reason: ' . $reason, 'creator/earnings.php');
        return null;
    });
}

/**
 * What a clip is building toward: money accrued but not yet credited (blocks mode)
 * and how many views remain until the next credit.
 */
function clip_progress(int $views, int $creditedViews, ?float $rate = null): array
{
    $rate = $rate ?? rate_per_1000();
    if (earnings_mode() === 'exact') {
        return ['accruing' => 0.0, 'to_next' => 0, 'pct' => 100, 'next_amount' => 0.0];
    }
    $uncredited = max(0, $views - $creditedViews);
    $intoBlock = $views % 1000;
    return [
        'accruing'    => views_value($uncredited, $rate),
        'to_next'     => 1000 - $intoBlock,
        'pct'         => (int)floor($intoBlock / 10),
        'next_amount' => views_value(1000, $rate),
    ];
}

/** Everything the live dashboard shows, read fresh from the database. */
function creator_live(int $userId): array
{
    $stats = creator_stats($userId);
    $rate = rate_per_1000();
    $mode = earnings_mode();

    $clips = fetch_all(
        '(SELECT id, "link" AS src, platform, url, title, views, credited_views, earned, submitted_at, last_fetched_at, NULL AS cplatforms, 0 AS accrued, "approved" AS cstatus
            FROM content_links WHERE user_id = ? AND status = "active")
         UNION ALL
         (SELECT cs.id, "campaign" AS src, cs.platform, cs.url, c.title, GREATEST(CAST(cs.views AS SIGNED) - CAST(COALESCE(cs.start_views, cs.views) AS SIGNED), 0) AS views, cs.credited_views, cs.earned, cs.submitted_at, cs.last_fetched_at, c.platforms AS cplatforms, cs.accrued, cs.status AS cstatus
            FROM campaign_submissions cs JOIN campaigns c ON c.id = cs.campaign_id WHERE cs.user_id = ? AND cs.status IN ("pending","approved"))
         ORDER BY submitted_at DESC',
        [$userId, $userId]
    );
    $accruing = 0.0;
    $held = 0.0;
    $lastRead = null;
    $out = [];
    foreach ($clips as $c) {
        // Campaign clips below the campaign's minimum per clip: earned, but held until they reach it.
        if ($c['src'] === 'campaign') {
            $t = campaign_terms((string)$c['cplatforms'], (string)$c['platform']);
            $value = floor(round((float)$c['accrued'] * 100, 4)) / 100;
            if ($t['max'] > 0) {
                $value = min($value, $t['max']);
            }
            if ($t['min'] > 0 && $value < $t['min']) {
                $held += $value;
            }
        }
        // Campaign submissions are always paid per view, so nothing waits for a full 1,000.
        $p = $c['src'] === 'link'
            ? clip_progress((int)$c['views'], (int)$c['credited_views'], $rate)
            : ['accruing' => 0.0, 'to_next' => 0, 'pct' => 100, 'next_amount' => 0.0];
        $accruing += $p['accruing'];
        if ($c['last_fetched_at'] && ($lastRead === null || $c['last_fetched_at'] > $lastRead)) {
            $lastRead = $c['last_fetched_at'];
        }
        if (count($out) >= 20) {
            continue;
        }
        $out[] = [
            'key'          => $c['src'] . '-' . (int)$c['id'],
            'src'          => $c['src'],
            'platform'     => (string)$c['platform'],
            'title'        => (string)($c['title'] ?? ''),
            'url'          => (string)$c['url'],
            'submitted_at' => strtotime((string)$c['submitted_at']),
            'views'        => (int)$c['views'],
            'earned'       => round((float)$c['earned'], 2),
            'accruing'     => $p['accruing'],
            'to_next'      => $p['to_next'],
            'pct'          => $p['pct'],
            'next_amount'  => $p['next_amount'],
            'in_review'    => $c['src'] === 'campaign' && $c['cstatus'] === 'pending',
        ];
    }

    try {
        $today = fetch_one(
            'SELECT COALESCE(SUM(amount),0) AS amount, COALESCE(SUM(views_gained),0) AS views
               FROM earning_days WHERE user_id = ? AND day = CURDATE()',
            [$userId]
        ) ?: ['amount' => 0, 'views' => 0];
    } catch (Throwable $e) {
        $today = ['amount' => 0, 'views' => 0];
    }

    $every = max(10, (int)setting('autosync_minutes', 30)) * 60;
    $lastRun = (int)setting('autosync_last_at', 0);
    if ($lastRun > time() + 60) {
        $lastRun = 0; // stored time is in the future: the next page view will run the sync
    }
    $nextRead = min(time() + $every, max(time(), $lastRun + $every));

    return [
        'balance'      => round((float)$stats['balance'], 2),
        'in_review'    => round((float)$stats['in_review'], 2),
        'earned'       => round((float)$stats['earned'], 2),
        'paid'         => round((float)$stats['paid'], 2),
        'views'        => (int)$stats['views'],
        'clips_count'  => count($clips),
        'accruing'     => round($accruing, 2),
        'held'         => round($held, 2),
        'today_amount' => round((float)$today['amount'], 2),
        'today_views'  => (int)$today['views'],
        'min_payout'   => payout_min(),
        'rate'         => $rate,
        'mode'         => $mode,
        'currency'     => currency(),
        'last_read'    => $lastRead ? strtotime($lastRead) : null,
        'next_read'    => $nextRead > time() ? $nextRead : time(),
        'now'          => time(),
        'clips'        => $out,
    ];
}

/** Money and views per day for the last $days days (oldest first, zero-filled). */
function earning_series(int $userId, int $days = 30): array
{
    $out = [];
    for ($i = $days - 1; $i >= 0; $i--) {
        $out[date('Y-m-d', strtotime("-$i day"))] = ['amount' => 0.0, 'views' => 0];
    }
    try {
        $rows = fetch_all(
            'SELECT day, SUM(amount) AS amount, SUM(views_gained) AS views FROM earning_days
              WHERE user_id = ? AND day >= CURDATE() - INTERVAL ? DAY GROUP BY day',
            [$userId, $days - 1]
        );
        foreach ($rows as $r) {
            if (isset($out[$r['day']])) {
                $out[$r['day']] = ['amount' => (float)$r['amount'], 'views' => (int)$r['views']];
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Per-clip activity for recent days, newest day first, with clip titles. */
function earning_activity(int $userId, int $days = 14): array
{
    try {
        $rows = fetch_all(
            '(SELECT d.day, d.src, d.ref_id, d.views_gained, d.amount, l.platform, l.url, l.title, NULL AS campaign
                FROM earning_days d JOIN content_links l ON l.id = d.ref_id
               WHERE d.user_id = ? AND d.src = "link" AND d.day >= CURDATE() - INTERVAL ? DAY)
             UNION ALL
             (SELECT d.day, d.src, d.ref_id, d.views_gained, d.amount, s.platform, s.url, NULL AS title, c.title AS campaign
                FROM earning_days d JOIN campaign_submissions s ON s.id = d.ref_id JOIN campaigns c ON c.id = s.campaign_id
               WHERE d.user_id = ? AND d.src = "campaign" AND d.day >= CURDATE() - INTERVAL ? DAY)
             UNION ALL
             (SELECT d.day, d.src, d.ref_id, d.views_gained, d.amount, l.platform, l.url, l.title, a.reason AS campaign
                FROM earning_days d JOIN earning_adjustments a ON a.id = d.ref_id LEFT JOIN content_links l ON l.id = a.link_id
               WHERE d.user_id = ? AND d.src = "adjust" AND d.day >= CURDATE() - INTERVAL ? DAY)
             ORDER BY day DESC, amount DESC, views_gained DESC',
            [$userId, $days - 1, $userId, $days - 1, $userId, $days - 1]
        );
    } catch (Throwable $e) {
        return [];
    }
    $byDay = [];
    foreach ($rows as $r) {
        $byDay[$r['day']]['items'][] = $r;
        $byDay[$r['day']]['amount'] = ($byDay[$r['day']]['amount'] ?? 0) + (float)$r['amount'];
        $byDay[$r['day']]['views'] = ($byDay[$r['day']]['views'] ?? 0) + (int)$r['views_gained'];
    }
    return $byDay;
}

/** "Today", "Yesterday" or a short date. */
function day_label(string $day): string
{
    if ($day === date('Y-m-d')) {
        return 'Today';
    }
    if ($day === date('Y-m-d', strtotime('-1 day'))) {
        return 'Yesterday';
    }
    return date('D j M', strtotime($day));
}
