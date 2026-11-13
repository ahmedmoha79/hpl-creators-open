<?php
/**
 * Automatic view reading.
 *
 * YouTube: read from the public watch page (no key needed).
 * TikTok: read with the official API through the creator's connected TikTok
 *         account (includes/tiktok.php).
 * Instagram: Meta Business Discovery through HPL's business account
 *         (includes/meta.php). Creators' accounts must be Professional.
 * Facebook: not read yet; the team enters views in Admin > Video links.
 * Simulation is off.
 */
require_once __DIR__ . '/earnings.php';
require_once __DIR__ . '/verification.php';
require_once __DIR__ . '/tiktok.php';
require_once __DIR__ . '/home_hooks.php';
require_once __DIR__ . '/meta.php';

class ViewFetcher
{
    /** Try to read the real view count for a link. Returns int or null if unavailable. */
    public static function tryFetch(string $platform, string $url, ?int $userId = null): ?int
    {
        try {
            switch ($platform) {
                case 'youtube':
                    return self::youtube($url);
                case 'facebook':
                    return self::facebook($url);
                case 'instagram':
                    return self::instagram($url, $userId);
                case 'tiktok':
                    return self::tiktok($url, $userId);
                default:
                    return null;
            }
        } catch (Throwable $e) {
            error_log('ViewFetcher error: ' . $e->getMessage());
            return null;
        }
    }

    /** Read a public YouTube video's view count from its watch page. */
    public static function youtube(string $url): ?int
    {
        if (!preg_match('~(?:youtu\.be/|youtube\.com/(?:watch\?v=|shorts/|embed/))([A-Za-z0-9_-]{6,})~i', $url, $m)) {
            return null;
        }
        $id = $m[1];
        $html = self::httpGet('https://www.youtube.com/watch?v=' . $id . '&hl=en');
        if (!$html) {
            return null;
        }
        foreach (['"viewCount":"(\d+)"', '"viewCount":\s*"(\d+)"', 'viewCount&quot;:&quot;(\d+)'] as $pattern) {
            if (preg_match('~' . $pattern . '~', $html, $mm)) {
                return (int)$mm[1];
            }
        }
        return null;
    }

    /** Facebook video views (Graph API needs a page/user access token). */
    public static function facebook(string $url): ?int
    {
        $token = trim((string)setting('facebook_access_token', ''));
        if ($token === '') {
            return null;
        }
        // Placeholder for Graph API integration: /{video-id}?fields=views&access_token=...
        return null;
    }

    /** Instagram view counts through HPL's business account (Meta Business Discovery). */
    public static function instagram(string $url, ?int $userId = null): ?int
    {
        return meta_instagram_views($url, $userId);
    }

    /** TikTok view counts through the creator's connected TikTok account (official API). */
    public static function tiktok(string $url, ?int $userId = null): ?int
    {
        return tiktok_views_for_link($url, $userId);
    }

    /** Minimal HTTP GET helper. */
    private static function httpGet(string $url): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_TIMEOUT        => 12,
                CURLOPT_CONNECTTIMEOUT => 6,
                CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; HPLCreators/1.0)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => ['Accept-Language: en-US,en;q=0.9'],
            ]);
            $body = curl_exec($ch);
            curl_close($ch);
            return is_string($body) ? $body : null;
        }
        $ctx = stream_context_create(['http' => ['timeout' => 10, 'user_agent' => 'Mozilla/5.0 (compatible; HPLCreators/1.0)']]);
        $body = @file_get_contents($url, false, $ctx);
        return is_string($body) ? $body : null;
    }
}

/**
 * Deterministic simulated growth so the platform is fully testable before
 * live API tokens are connected.
 */
function simulate_views(array $link, int $dailyTarget): int
{
    $current = (int)$link['views'];
    $since = strtotime($link['last_fetched_at'] ?: $link['submitted_at']) ?: time();
    $elapsed = max(0, time() - $since);

    $seed = crc32((string)$link['url']);
    $factor = 0.55 + (($seed % 90) / 100);          // 0.55 .. 1.44
    $expected = ($elapsed / 86400) * $dailyTarget * $factor;
    $jitter = 0.7 + (mt_rand(0, 600) / 1000);        // 0.7 .. 1.3
    $floor = max(1, (int)round($dailyTarget * 0.015));
    $gain = (int)max($floor, round($expected * $jitter));

    return $current + $gain;
}

/**
 * Sync views and credit earnings. Pass a user id to limit to one creator.
 * Returns summary stats.
 */
function sync_views(?int $userId = null, int $minAgeSeconds = 0, int $timeBudget = 0): array
{
    // Campaigns-only mode: clips outside campaigns no longer earn (money already earned stays).
    if (!general_clips_enabled()) {
        return ['processed' => 0, 'credited' => 0.0, 'live_reads' => 0, 'simulated' => 0, 'paused' => true];
    }
    // Suspended accounts are skipped until the team reactivates them.
    $sql = 'SELECT * FROM content_links WHERE status = "active" AND user_id IN (SELECT id FROM users WHERE status = "active")';
    $params = [];
    if ($userId !== null) {
        $sql .= ' AND user_id = ?';
        $params[] = $userId;
    }
    if ($minAgeSeconds > 0) {
        // Skip links read very recently (protects the platforms' rate limits).
        $sql .= ' AND (last_fetched_at IS NULL OR last_fetched_at < NOW() - INTERVAL ' . (int)$minAgeSeconds . ' SECOND)';
    }
    // Stalest first, so with many clips every clip is still read in turn.
    $sql .= ' ORDER BY last_fetched_at IS NOT NULL, last_fetched_at ASC';
    $links = fetch_all($sql, $params);
    tiktok_prefetch_views($links);
    $started = microtime(true);

    $simulate = false; // 2026-10-26: simulated views disabled on the live site; never credit money for invented views.
    $dailyTarget = (int)setting('simulation_daily_views', 1200);

    $processed = 0;
    $credited = 0.0;
    $liveReads = 0;

    foreach ($links as $link) {
        if ($timeBudget > 0 && (microtime(true) - $started) > $timeBudget) {
            break; // the rest are read next time (they are now the stalest)
        }
        $views = ViewFetcher::tryFetch($link['platform'], $link['url'], (int)$link['user_id']);
        $source = 'api';
        if ($views === null) {
            if (!$simulate) {
                continue;
            }
            $views = simulate_views($link, $dailyTarget);
            $source = 'simulation';
        } else {
            $liveReads++;
        }

        // Views never go backwards.
        if ($views < (int)$link['views']) {
            $views = (int)$link['views'];
        }

        $credited += credit_link_earnings((int)$link['id'], (int)$views, $source);
        $processed++;
    }

    return [
        'processed'  => $processed,
        'credited'   => $credited,
        'live_reads' => $liveReads,
        'simulated'  => $processed - $liveReads,
    ];
}

/**
 * Sync views/earnings for campaign submissions (consumes campaign budget).
 */
function sync_campaign_submissions(?int $userId = null, int $minAgeSeconds = 0, int $timeBudget = 0): array
{
    $sql = 'SELECT cs.*, c.platforms AS cplatforms, c.total_budget, c.paid_amount, c.currency FROM campaign_submissions cs JOIN campaigns c ON c.id = cs.campaign_id JOIN users u ON u.id = cs.user_id WHERE cs.status IN ("pending","approved") AND u.status = "active"';
    $params = [];
    if ($userId !== null) {
        $sql .= ' AND cs.user_id = ?';
        $params[] = $userId;
    }
    if ($minAgeSeconds > 0) {
        $sql .= ' AND (cs.last_fetched_at IS NULL OR cs.last_fetched_at < NOW() - INTERVAL ' . (int)$minAgeSeconds . ' SECOND)';
    }
    $sql .= ' ORDER BY cs.last_fetched_at IS NOT NULL, cs.last_fetched_at ASC';
    $subs = fetch_all($sql, $params);
    tiktok_prefetch_views($subs);
    $started = microtime(true);

    $simulate = false; // 2026-10-26: simulated views disabled on the live site; never credit money for invented views.
    $dailyTarget = (int)setting('simulation_daily_views', 1200);

    $processed = 0;
    $credited = 0.0;

    foreach ($subs as $sub) {
        if ($timeBudget > 0 && (microtime(true) - $started) > $timeBudget) {
            break;
        }
        $platform = (string)$sub['platform'];
        $url = (string)$sub['url'];
        $views = ViewFetcher::tryFetch($platform, $url, (int)$sub['user_id']);
        $source = 'api';
        // Integrity: keep likes/comments/shares with the views, notice clips that disappeared, score the clip.
        $ttId = $platform === 'tiktok' ? tiktok_video_id($url) : null;
        if ($ttId !== null) {
            try {
                require_once __DIR__ . '/integrity.php';
                if ($views === null && !empty($GLOBALS['hpl_tt_missing'][$ttId])) {
                    integrity_clip_missing($sub);
                } elseif ($views !== null) {
                    integrity_clip_seen($sub);
                    if (isset($GLOBALS['hpl_tt_stats'][$ttId])) {
                        integrity_record((int)$sub['id'], $GLOBALS['hpl_tt_stats'][$ttId]);
                        // The picture TikTok shows for the post: the clip's thumbnail on Home and in the admin.
                        // TikTok's picture addresses stop working after some hours, so it is refreshed at every read.
                        $cover = (string)($GLOBALS['hpl_tt_stats'][$ttId]['cover'] ?? '');
                        if ($cover !== '' && strlen($cover) < 1000 && preg_match('~^https://[a-z0-9.-]+\.(tiktokcdn[a-z0-9-]*\.com|tiktokv\.com|tiktok\.com|ibyteimg\.com|byteimg\.com|muscdn\.com)/~i', $cover)) {
                            try { q('UPDATE campaign_submissions SET cover_url = ?, cover_at = NOW() WHERE id = ?', [$cover, (int)$sub['id']]); } catch (Throwable $e) {}
                        }
                    }
                }
            } catch (Throwable $e) {
                error_log('integrity hook: ' . $e->getMessage());
            }
        }
        if ($views === null) {
            if (!$simulate) { continue; }
            // simulate: base on daily target scaled
            $views = (int)$sub['views'] + (int)round($dailyTarget * (mt_rand(80,120)/100) * (1/3));
            $source = 'simulation';
        }
        $amount = credit_campaign_submission((int)$sub['id'], (int)$views);
        $credited += $amount;
        $processed++;
        if ($ttId !== null) {
            try { integrity_evaluate((int)$sub['id']); } catch (Throwable $e) {}
        }
    }

    return ['processed' => $processed, 'credited' => $credited];
}

/**
 * Credit one campaign submission from its current view count. Locks the submission
 * and its campaign, so concurrent reads can neither pay twice nor overspend the budget.
 * Applies the campaign's minimum payout (money is held until a clip reaches it) and
 * maximum payout per clip. Returns the amount credited.
 */
function credit_campaign_submission(int $subId, int $views): float
{
    return (float)hpl_tx(function () use ($subId, $views) {
        $sub = fetch_one('SELECT * FROM campaign_submissions WHERE id = ? FOR UPDATE', [$subId]);
        if (!$sub || !in_array($sub['status'], ['pending', 'approved'], true)) {
            return 0.0;
        }
        // The demo creator (includes/demo.php) never earns: its views are recorded, no money and no budget move.
        require_once __DIR__ . '/demo.php';
        if (is_demo_user_id((int)$sub['user_id'])) {
            q('UPDATE campaign_submissions SET views = GREATEST(views, ?), start_views = COALESCE(start_views, ?), last_fetched_at = NOW() WHERE id = ?', [$views, $views, $subId]);
            return 0.0;
        }
        $camp = fetch_one('SELECT * FROM campaigns WHERE id = ? FOR UPDATE', [(int)$sub['campaign_id']]);
        if (!$camp) {
            return 0.0;
        }
        // Only a live campaign with a budget pays. An ended campaign, or one without a budget,
        // still records the views (so nothing is lost if it is reopened) but pays nothing now.
        $payable = ($camp['status'] ?? 'active') === 'active' && (float)$camp['total_budget'] > 0;
        $oldViews = (int)$sub['views'];
        $views = max($views, $oldViews);
        $t = campaign_terms((string)$camp['platforms'], (string)$sub['platform']);

        // Only views the video gets AFTER it was submitted earn money. The first read of a new
        // submission sets that starting point (start_views). Each later read adds the new views
        // at the campaign's rate at that moment, so a rate change never re-prices old views and
        // a correction by the team is not undone by the next read.
        $accrued = (float)$sub['accrued'];
        if ($sub['start_views'] === null) {
            q('UPDATE campaign_submissions SET start_views = ? WHERE id = ?', [$views, $subId]);
            $creditedViews = $views;
        } else {
            $from = max((int)$sub['credited_views'], (int)$sub['start_views']);
            if ($views > $from) {
                $accrued = round($accrued + ($views - $from) * $t['rate'] / 1000, 4);
            }
            $creditedViews = max($views, (int)$sub['credited_views']);
        }
        $value = floor(round($accrued * 100, 4)) / 100;
        if ($t['max'] > 0) {
            $value = min($value, $t['max']);
        }
        // Below the campaign's minimum per clip the money is held until the clip reaches it.
        $payableTotal = ($t['min'] > 0 && $value < $t['min']) ? 0.0 : $value;
        $amount = $payable ? max(0.0, round($payableTotal - (float)$sub['earned'], 2)) : 0.0;
        // Never past the campaign budget: what is left = budget - what creators have earned so far.
        $amount = min($amount, max(0.0, round((float)$camp['total_budget'] - (float)$camp['paid_amount'], 2)));
        // Per-creator limit for this campaign: across all of this creator's clips in it.
        // The campaign row is locked above, so parallel credits cannot push past the limit.
        $cap = campaign_creator_cap($camp);
        if ($cap > 0 && $amount > 0) {
            $already = campaign_creator_earned((int)$camp['id'], (int)$sub['user_id']);
            $amount = min($amount, max(0.0, round($cap - $already, 2)));
        }

        q('UPDATE campaign_submissions SET views = ?, earned = earned + ?, credited_views = ?, accrued = ?, last_fetched_at = NOW() WHERE id = ?',
            [$views, $amount, $creditedViews, $accrued, $subId]);
        if ($amount > 0) {
            q('UPDATE campaigns SET paid_amount = paid_amount + ? WHERE id = ?', [$amount, (int)$camp['id']]);
        }
        // The first read only sets the starting point: views from before sending are not "gained".
        earning_day_add((int)$sub['user_id'], 'campaign', $subId, $sub['start_views'] === null ? 0 : $views - $oldViews, $amount);
        // Milestones: tell the creator when a clip passes 1K, 5K, 10K... counted views (a reason to come back).
        if ($sub['start_views'] !== null) {
            $before = max(0, $oldViews - (int)$sub['start_views']);
            $after = max(0, $views - (int)$sub['start_views']);
            foreach (array_reverse(clip_milestones()) as $m) {
                if ($before < $m && $after >= $m) {
                    notif_add((int)$sub['user_id'], 'milestone', 'Your clip passed ' . num($m) . ' views',
                        ($camp['title'] ?? 'Your clip') . ' now has ' . num($after) . ' counted views. Keep it going: reply to comments and post your next clip.',
                        'creator/clip.php?id=' . $subId);
                    // And tell the community (#wins), so others cheer and see what is possible.
                    try {
                        require_once __DIR__ . '/community.php';
                        chat_event((int)$sub['user_id'], 'wins', 'passed ' . num($m) . ' views on a clip for ' . ($camp['title'] ?? 'a campaign'));
                    } catch (Throwable $e) {
                    }
                    break;
                }
            }
        }
        return $amount;
    });
}
