<?php
/**
 * Creator Home (2026-11-08 redesign): the small things that make a creator open the app again.
 * - money and views since the last visit
 * - posting streak (days in a row with a clip sent) and this week's days
 * - this week's rank among creators, and how far to the next one
 * - the creator's own weekly goal
 * - clip milestones (1K, 5K, 10K... counted views) and clip thumbnails
 * Everything here only reads money figures; money itself still moves only through the ledgers.
 */

/** View counts a clip is congratulated for. */
function clip_milestones(): array
{
    return [1000, 5000, 10000, 25000, 50000, 100000, 250000, 500000, 1000000];
}

/** Highest milestone a view count has reached (0 when none). */
function clip_milestone_reached(int $views): int
{
    $hit = 0;
    foreach (clip_milestones() as $m) {
        if ($views >= $m) {
            $hit = $m;
        }
    }
    return $hit;
}

/** "1K", "25K", "1M" for a milestone badge. */
function short_count(int $n): string
{
    if ($n >= 1000000) {
        return rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.') . 'M';
    }
    if ($n >= 1000) {
        return rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K';
    }
    return (string)$n;
}

/**
 * Money and views gained since this creator last opened Home, then remember now.
 * Returns null on the first visit (nothing to compare with) or when nothing changed.
 */
function home_since_last_visit(array $user, array $live): ?array
{
    $uid = (int)$user['id'];
    $earned = round((float)$live['earned'], 2);
    $views = (int)$live['views'];
    $out = null;
    if (array_key_exists('home_seen_at', $user) && $user['home_seen_at'] !== null) {
        $dm = round($earned - (float)$user['home_seen_earned'], 2);
        $dv = $views - (int)$user['home_seen_views'];
        if ($dm > 0 || $dv > 0) {
            $out = ['money' => max(0, $dm), 'views' => max(0, $dv), 'since' => (string)$user['home_seen_at']];
        }
    }
    // Move the marker forward only after a real gap. What was found is kept for 20 minutes in the
    // session, so going back to Home or refreshing still shows it.
    $last = $user['home_seen_at'] ?? null;
    if ($last === null || strtotime((string)$last) < time() - 20 * 60) {
        try {
            q('UPDATE users SET home_seen_earned = ?, home_seen_views = ?, home_seen_at = NOW() WHERE id = ?', [$earned, $views, $uid]);
        } catch (Throwable $e) {
        }
        $_SESSION['hk_since'] = $out ? $out + ['at' => time(), 'uid' => $uid] : null;
        return $out;
    }
    $kept = $_SESSION['hk_since'] ?? null;
    if (is_array($kept) && (int)($kept['uid'] ?? 0) === $uid && (int)$kept['at'] > time() - 20 * 60) {
        return $kept;
    }
    return null;
}

/** Monday of this week (Y-m-d). */
function week_start(): string
{
    return date('Y-m-d', strtotime('monday this week'));
}

/**
 * This week for one creator: money, views, posting streak, the days posted, rank and goal.
 */
function creator_week(array $user): array
{
    $uid = (int)$user['id'];
    $mon = week_start();
    $out = ['money' => 0.0, 'views' => 0, 'streak' => 0, 'posted_today' => false, 'days' => [],
            'rank' => 0, 'ranked' => 0, 'ahead' => null, 'leader' => null, 'goal' => 0.0];
    try {
        $w = fetch_one('SELECT COALESCE(SUM(amount),0) a, COALESCE(SUM(views_gained),0) v FROM earning_days WHERE user_id = ? AND day >= ?', [$uid, $mon]);
        $out['money'] = round((float)($w['a'] ?? 0), 2);
        $out['views'] = (int)($w['v'] ?? 0);
    } catch (Throwable $e) {
    }

    // Days with a clip sent (campaign clips, not rejected), last 60 days.
    $posted = [];
    try {
        foreach (fetch_all('SELECT DISTINCT DATE(submitted_at) d FROM campaign_submissions WHERE user_id = ? AND status <> "rejected" AND submitted_at >= CURDATE() - INTERVAL 60 DAY', [$uid]) as $r) {
            $posted[(string)$r['d']] = true;
        }
    } catch (Throwable $e) {
    }
    $today = date('Y-m-d');
    $out['posted_today'] = isset($posted[$today]);
    // The streak counts back from today, or from yesterday when nothing was sent yet today (still alive).
    $d = $out['posted_today'] ? time() : strtotime('-1 day');
    while (isset($posted[date('Y-m-d', $d)])) {
        $out['streak']++;
        $d = strtotime('-1 day', $d);
    }
    for ($i = 0; $i < 7; $i++) {
        $day = date('Y-m-d', strtotime($mon . ' +' . $i . ' day'));
        $out['days'][] = ['label' => date('D', strtotime($day)), 'letter' => substr(date('D', strtotime($day)), 0, 1),
                          'posted' => isset($posted[$day]), 'today' => $day === $today, 'future' => $day > $today];
    }

    // Rank this week among active creators who earned something.
    try {
        $rows = fetch_all(
            'SELECT d.user_id, SUM(d.amount) a, u.full_name FROM earning_days d JOIN users u ON u.id = d.user_id
              WHERE d.day >= ? AND u.role = "creator" AND u.status = "active"
              GROUP BY d.user_id HAVING a > 0 ORDER BY a DESC, d.user_id ASC LIMIT 500',
            [$mon]
        );
        $out['ranked'] = count($rows);
        $short = function (string $n): string {
            $p = preg_split('/\s+/', trim($n)) ?: ['Creator'];
            return $p[0] . (count($p) > 1 ? ' ' . mb_strtoupper(mb_substr(end($p), 0, 1)) . '.' : '');
        };
        if ($rows) {
            $out['leader'] = ['name' => $short((string)$rows[0]['full_name']), 'money' => round((float)$rows[0]['a'], 2)];
        }
        foreach ($rows as $i => $r) {
            if ((int)$r['user_id'] === $uid) {
                $out['rank'] = $i + 1;
                if ($i > 0) {
                    $out['ahead'] = ['name' => $short((string)$rows[$i - 1]['full_name']), 'gap' => round((float)$rows[$i - 1]['a'] - (float)$r['a'], 2)];
                }
                break;
            }
        }
    } catch (Throwable $e) {
    }

    $out['goal'] = round((float)($user['weekly_goal'] ?? 0), 2);
    return $out;
}

/** Ready-clip picture for campaign clips that came from a ready clip: [submission id => poster url]. */
function clip_thumbs(int $userId): array
{
    $out = [];
    try {
        // First choice: the picture TikTok itself shows for the post (kept fresh at every views read, see
        // tiktok_query_views). It exists for every TikTok clip, ready clip or own edit.
        if (hpl_column_exists('campaign_submissions', 'cover_url')) {
            foreach (fetch_all('SELECT id, cover_url FROM campaign_submissions WHERE user_id = ? AND cover_url IS NOT NULL AND cover_at > NOW() - INTERVAL 5 HOUR', [$userId]) as $r) {
                $out[(int)$r['id']] = (string)$r['cover_url'];
            }
        }
        // Otherwise our own still picture of the ready clip, when the server has one.
        require_once __DIR__ . '/ready_clips.php';
        foreach (fetch_all('SELECT s.id, s.ready_clip_id, r.file_name FROM campaign_submissions s JOIN ready_clips r ON r.id = s.ready_clip_id
                             WHERE s.user_id = ? AND s.ready_clip_id IS NOT NULL AND r.is_active = 1', [$userId]) as $r) {
            if (!isset($out[(int)$r['id']]) && is_file(ready_clips_dir() . '/' . pathinfo(basename((string)$r['file_name']), PATHINFO_FILENAME) . '.jpg')) {
                $out[(int)$r['id']] = u('creator/ready_clip.php?id=' . (int)$r['ready_clip_id'] . '&poster=1');
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** "MTN", "Airtel", "Zamtel" for a payment provider key. */
function provider_label(string $p): string
{
    $map = ['mtn' => 'MTN', 'airtel' => 'Airtel', 'zamtel' => 'Zamtel'];
    return $map[strtolower($p)] ?? ($p !== '' ? ucfirst($p) : 'mobile money');
}
