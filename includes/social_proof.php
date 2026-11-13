<?php
/**
 * HPL social proof + creator levels (2026-10-26)
 * - Real data by default (paid withdrawals, top clip of the last 7 days).
 * - Add ?demo=1 to the page URL to preview with sample data (clearly labelled).
 * - Edit hpl_levels() to change level names, thresholds or perks.
 */

function sp_demo(): bool
{
    return isset($_GET['demo']) && $_GET['demo'] === '1';
}

/** "Chanda Mwila" -> "Chanda M." (privacy: first name + initial only). */
function sp_short_name(string $full): string
{
    $parts = preg_split('/\s+/', trim($full));
    if (!$parts || $parts[0] === '') {
        return 'HPL creator';
    }
    $first = mb_strtoupper(mb_substr($parts[0], 0, 1)) . mb_substr($parts[0], 1);
    $last = count($parts) > 1 ? mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : '';
    return trim($first . ' ' . $last);
}

/** Recent paid withdrawals for the payout strip. */
function sp_recent_payouts(int $limit = 12): array
{
    if (sp_demo()) {
        return [
            ['name' => 'Chanda M.',  'amount' => 150.00, 'method' => 'Airtel Money', 'ago' => '2 hours ago'],
            ['name' => 'Mutale B.',  'amount' => 62.50,  'method' => 'MTN MoMo',     'ago' => '5 hours ago'],
            ['name' => 'Joseph K.',  'amount' => 245.00, 'method' => 'Airtel Money', 'ago' => 'yesterday'],
            ['name' => 'Natasha P.', 'amount' => 80.00,  'method' => 'Zamtel Kwacha','ago' => 'yesterday'],
            ['name' => 'Bwalya S.',  'amount' => 500.00, 'method' => 'MTN MoMo',     'ago' => '2 days ago'],
            ['name' => 'Mwila T.',   'amount' => 55.00,  'method' => 'Airtel Money', 'ago' => '2 days ago'],
            ['name' => 'Grace N.',   'amount' => 120.00, 'method' => 'MTN MoMo',     'ago' => '3 days ago'],
            ['name' => 'Kelvin L.',  'amount' => 310.00, 'method' => 'Airtel Money', 'ago' => '4 days ago'],
        ];
    }
    try {
        $rows = fetch_all(
            'SELECT u.full_name, w.amount, w.method, w.payment_provider,
                    COALESCE(w.resolved_at, w.requested_at) AS paid_at
               FROM withdrawals w
               JOIN users u ON u.id = w.user_id
              WHERE w.status = "paid"
              ORDER BY paid_at DESC
              LIMIT ' . (int)$limit
        );
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'name'   => sp_short_name((string)$r['full_name']),
            'amount' => (float)$r['amount'],
            'method' => (string)(mobile_providers()[(string)$r['payment_provider']]['label'] ?? ($r['method'] ?: 'Mobile money')),
            'ago'    => time_ago($r['paid_at']),
        ];
    }
    return $out;
}

/**
 * Real money only, for the sign-up page (owner, 2026-11-12): paid withdrawals, then what creators earned from views
 * in the last 30 days. First name and initial only. Never sample data (the ?demo=1 preview does not apply here).
 * Each item: [kind paid|earned, name, amount, text, ago].
 */
/** Real payouts, then real earnings (last 30 days). $earnMin/$earnN: the front page shows small amounts too (owner, 2026-11-13). */
function sp_signup_proof(int $limit = 10, float $earnMin = 1.0, int $earnN = 8): array
{
    $out = [];
    try {
        foreach (fetch_all('SELECT u.full_name, w.amount, w.method, w.payment_provider, COALESCE(w.resolved_at, w.requested_at) paid_at
                            FROM withdrawals w JOIN users u ON u.id = w.user_id
                            WHERE w.status = "paid" AND u.role = "creator" AND u.is_demo = 0 ORDER BY paid_at DESC LIMIT 6') as $r) {
            $how = (string)(mobile_providers()[(string)$r['payment_provider']]['label'] ?? ($r['method'] ?: 'mobile money'));
            $out[] = ['kind' => 'paid', 'name' => sp_short_name((string)$r['full_name']), 'amount' => (float)$r['amount'],
                'text' => 'was paid to ' . $how, 'ago' => time_ago($r['paid_at'])];
        }
        foreach (fetch_all('SELECT u.full_name, SUM(e.amount) a, MAX(e.day) last_day FROM earning_days e JOIN users u ON u.id = e.user_id
                            WHERE e.day >= CURDATE() - INTERVAL 30 DAY AND u.role = "creator" AND u.is_demo = 0
                            GROUP BY u.id, u.full_name HAVING a >= ' . (float)$earnMin . ' ORDER BY last_day DESC, a DESC LIMIT ' . max(1, min(40, $earnN))) as $r) {
            $days = (int)floor((time() - strtotime((string)$r['last_day'])) / 86400);
            $out[] = ['kind' => 'earned', 'name' => sp_short_name((string)$r['full_name']), 'amount' => round((float)$r['a'], 2),
                'text' => 'earned from clip views', 'ago' => $days <= 0 ? 'today' : ($days === 1 ? 'yesterday' : ($days < 7 ? 'this week' : 'this month'))];
        }
    } catch (Throwable $e) {
        return [];
    }
    return array_slice($out, 0, $limit);
}

/** Most-viewed active clip submitted in the last 7 days (needs at least 1,000 views). */
function sp_clip_of_week(): ?array
{
    if (sp_demo()) {
        return [
            'name' => 'Chanda M.', 'platform' => 'TikTok', 'url' => '#',
            'title' => 'Contestant 4 finds gold in 2 minutes with the sambaza method',
            'views' => 48200, 'earned' => 241.00,
        ];
    }
    try {
        $r = fetch_one(
            'SELECT c.platform, c.url, c.title, c.views, c.earned, u.full_name
               FROM content_links c
               JOIN users u ON u.id = c.user_id
              WHERE c.status = "active" AND c.submitted_at >= NOW() - INTERVAL 7 DAY
              ORDER BY c.views DESC
              LIMIT 1'
        );
    } catch (Throwable $e) {
        return null;
    }
    if (!$r || (int)$r['views'] < 1000) {
        return null;
    }
    return [
        'name' => sp_short_name((string)$r['full_name']), 'platform' => ucfirst((string)$r['platform']),
        'url' => (string)$r['url'], 'title' => (string)($r['title'] ?: 'HPL clip'),
        'views' => (int)$r['views'], 'earned' => (float)$r['earned'],
    ];
}

/** Creator levels, by total verified views. Perks are promises: edit them to match what HPL will honour. */
function hpl_levels(): array
{
    return [
        ['key' => 'bronze',  'name' => 'Bronze',  'min' => 0,       'icon' => icon('award'),
         'perks' => ['Footage of every campaign you join', 'Standard rate per 1,000 views', 'Community chat access']],
        ['key' => 'silver',  'name' => 'Silver',  'min' => 25000,   'icon' => icon('award'),
         'perks' => ['Silver badge on your profile', 'Early access to new footage', 'Monthly clip feedback from HPL']],
        ['key' => 'gold',    'name' => 'Gold',    'min' => 100000,  'icon' => icon('award'),
         'perks' => ['Gold badge on your profile', 'Featured in Clip of the Week', 'Priority payout review']],
        ['key' => 'diamond', 'name' => 'Diamond', 'min' => 500000,  'icon' => icon('award'),
         'perks' => ['Diamond badge on your profile', 'Free HPL field day with the team', 'First pick of paid campaigns']],
    ];
}

/** Current level, next level and progress (0-100) for a view count. */
function hpl_level_for(int $views): array
{
    $levels = hpl_levels();
    $cur = $levels[0];
    $next = null;
    foreach ($levels as $i => $lv) {
        if ($views >= $lv['min']) {
            $cur = $lv;
            $next = $levels[$i + 1] ?? null;
        }
    }
    $pct = 100;
    $toGo = 0;
    if ($next) {
        $span = max(1, $next['min'] - $cur['min']);
        $pct = (int)floor(min(100, max(0, ($views - $cur['min']) / $span * 100)));
        $toGo = max(0, $next['min'] - $views);
    }
    return ['current' => $cur, 'next' => $next, 'pct' => $pct, 'to_go' => $toGo, 'views' => $views];
}
