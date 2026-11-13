<?php
/**
 * Rewards: bonus codes and achievements.
 *
 * Every reward that pays money is written to the user_bonuses ledger (same as the
 * sign-up bonus), so it lands in the wallet balance through creator_stats().
 * user_bonuses has UNIQUE(user_id, kind), so each code / achievement can pay a
 * creator at most once, even with double taps or parallel requests.
 *   kind 'code:<id>'  -> bonus code redemption
 *   kind 'ach:<key>'  -> achievement reward
 *
 * Admins manage both in Admin > Rewards. Nothing here is hardcoded money:
 * achievement rewards default to K0 (badge only) until the admin sets an amount.
 */
require_once __DIR__ . '/bonus.php';
require_once __DIR__ . '/earnings.php';

/* ------------------------------------------------------------------ ledger */

/**
 * Credit a one-off reward. Returns true when credited, false when this creator
 * already received this kind.
 */
function reward_credit(int $userId, string $kind, float $amount, string $label): bool
{
    $amount = round(max(0.0, $amount), 2);
    $st = q('INSERT IGNORE INTO user_bonuses (user_id, kind, amount, status, label, claimed_at) VALUES (?, ?, ?, "claimed", ?, NOW())',
        [$userId, mb_substr($kind, 0, 30), $amount, mb_substr($label, 0, 120)]);
    return $st->rowCount() === 1;
}

/** A creator's credited rewards, newest first. */
function reward_history(int $userId, int $limit = 50): array
{
    try {
        return fetch_all('SELECT * FROM user_bonuses WHERE user_id = ? AND status = "claimed" ORDER BY claimed_at DESC, id DESC LIMIT ' . (int)$limit, [$userId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Human label for a ledger row. */
function reward_label(array $row): string
{
    if (!empty($row['label'])) {
        return (string)$row['label'];
    }
    return $row['kind'] === 'signup' ? 'Sign-up bonus' : 'Bonus';
}

/* --------------------------------------------------------------- bonus codes */

function reward_code_normalize(string $code): string
{
    return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', trim($code)));
}

/** A fresh, easy-to-read random code like HPL-7KQ3. */
function reward_code_suggest(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < 5; $i++) {
        $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return 'HPL-' . $s;
}

/** Why a code cannot be used right now, or null if it can. */
function reward_code_problem(array $c): ?string
{
    if ((int)$c['active'] !== 1) {
        return 'This code is no longer active.';
    }
    if (!empty($c['starts_at']) && strtotime((string)$c['starts_at']) > time()) {
        return 'This code is not active yet.';
    }
    if (!empty($c['expires_at']) && strtotime((string)$c['expires_at']) <= time()) {
        return 'This code has expired.';
    }
    if ((int)$c['max_uses'] > 0 && (int)$c['uses'] >= (int)$c['max_uses']) {
        return 'This code has been fully used. Watch announcements for the next one!';
    }
    return null;
}

/**
 * Redeem a bonus code. Returns ['ok' => true, 'amount' => float, 'label' => string]
 * or ['ok' => false, 'error' => string].
 */
function reward_redeem_code(array $user, string $raw): array
{
    $uid = (int)$user['id'];
    $code = reward_code_normalize($raw);
    if (strlen($code) < 4) {
        return ['ok' => false, 'error' => 'Enter the bonus code exactly as you received it.'];
    }
    // Slow down guessing: at most 8 wrong codes per 15 minutes per account.
    // Counted in the database per account and per network address, so signing in again does not reset it.
    $tkUser = 'code:u:' . $uid;
    $tkIp = 'code:ip:' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if (throttle_blocked($tkUser, 8, 900) || throttle_blocked($tkIp, 20, 900)) {
        return ['ok' => false, 'error' => 'Too many wrong codes. Please wait 15 minutes and try again.'];
    }
    $c = fetch_one('SELECT * FROM reward_codes WHERE code = ?', [$code]);
    if (!$c) {
        throttle_fail($tkUser, 900);
        throttle_fail($tkIp, 900);
        return ['ok' => false, 'error' => 'That code does not exist. Check the spelling and try again.'];
    }
    if (($problem = reward_code_problem($c)) !== null) {
        return ['ok' => false, 'error' => $problem];
    }
    if ((int)$c['new_only'] === 1 && strtotime((string)$user['created_at']) < strtotime((string)$c['created_at'])) {
        return ['ok' => false, 'error' => 'This code is only for creators who joined after it was released.'];
    }
    $kind = 'code:' . (int)$c['id'];
    if (fetch_one('SELECT id FROM user_bonuses WHERE user_id = ? AND kind = ?', [$uid, $kind])) {
        return ['ok' => false, 'error' => 'You already used this code. Each code works once per creator.'];
    }
    $amount = (float)$c['amount'];
    $label = 'Bonus code ' . $c['code'];
    try {
    $ok = hpl_tx(function () use ($c, $uid, $kind, $amount, $label) {
        // Take one use only if one is still available (safe with many people redeeming at once).
        $st = q('UPDATE reward_codes SET uses = uses + 1 WHERE id = ? AND active = 1 AND (max_uses = 0 OR uses < max_uses)
                   AND (expires_at IS NULL OR expires_at > NOW())', [(int)$c['id']]);
        if ($st->rowCount() !== 1) {
            return 'used_up';
        }
        if (!reward_credit($uid, $kind, $amount, $label)) {
            throw new RuntimeException('duplicate');
        }
        return 'ok';
    });
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'You already used this code. Each code works once per creator.'];
    }
    if ($ok === 'used_up') {
        return ['ok' => false, 'error' => 'This code has just been fully used. Watch announcements for the next one!'];
    }
    notif_add($uid, 'bonus', 'Bonus code redeemed', money($amount) . ' from code ' . $c['code'] . ' was added to your wallet.', 'creator/rewards.php');
    return ['ok' => true, 'amount' => $amount, 'label' => $label];
}

/* -------------------------------------------------------------- achievements */

/** Achievement definitions: what counts and the goal. Rewards are set by the admin. */
function achievement_defs(): array
{
    $cur = function_exists('currency') ? currency() : 'K';
    return [
        'verified'   => ['Verified creator', 'Verify your first social media page', 'shield', 'verified', 1],
        'joined'     => ['Team player', 'Join your first campaign', 'target', 'joined', 1],
        'academy'    => ['Academy graduate', 'Pass the Creator Academy quiz', 'graduation', 'academy', 1],
        'first_clip' => ['First clip', 'Submit your first clip', 'film', 'clips', 1],
        'clips_10'   => ['On a roll', 'Submit 10 clips', 'trending', 'clips', 10],
        'views_1k'   => ['1K views', 'Reach 1,000 views on your clips', 'eye', 'views', 1000],
        'views_10k'  => ['10K club', 'Reach 10,000 views on your clips', 'sparkles', 'views', 10000],
        'views_100k' => ['100K legend', 'Reach 100,000 views on your clips', 'award', 'views', 100000],
        'earned_100' => ['First ' . $cur . '100', 'Earn ' . $cur . '100 from views', 'banknote', 'earned', 100],
        'cashout'    => ['Cashed out', 'Receive your first payout', 'wallet', 'paid', 1],
    ];
}

/** Admin rules per achievement: ['on' => bool, 'amount' => float]. */
function achievement_rules(): array
{
    $saved = json_decode((string)setting('achievement_rules', ''), true);
    $saved = is_array($saved) ? $saved : [];
    $out = [];
    foreach (achievement_defs() as $k => $d) {
        $r = $saved[$k] ?? [];
        $out[$k] = ['on' => !isset($r['on']) || (bool)$r['on'], 'amount' => max(0.0, round((float)($r['amount'] ?? 0), 2))];
    }
    return $out;
}

/** The numbers achievements are measured on. */
function achievement_metrics(int $uid): array
{
    // Achievements pay money that can be withdrawn straight away, so they only count
    // money and views that are already unlocked: approved campaign clips and active
    // clips. Clips waiting for review or rejected do not count.
    // "My clips" views only count while those clips can earn (otherwise their views include views
    // from before sending, unlike campaign clips).
    $link = general_clips_enabled()
        ? (fetch_one('SELECT COALESCE(SUM(views),0) AS v, COALESCE(SUM(earned),0) AS e FROM content_links WHERE user_id = ? AND status = "active"', [$uid]) ?: ['v' => 0, 'e' => 0])
        : ['v' => 0, 'e' => (float)fetch_val('SELECT COALESCE(SUM(earned),0) FROM content_links WHERE user_id = ? AND status = "active"', [$uid], 0)];
    $camp = fetch_one('SELECT COALESCE(SUM(GREATEST(CAST(views AS SIGNED) - CAST(COALESCE(start_views, views) AS SIGNED), 0)),0) AS v, COALESCE(SUM(earned),0) AS e FROM campaign_submissions WHERE user_id = ? AND status = "approved"', [$uid]) ?: ['v' => 0, 'e' => 0];
    $m = [
        'views'    => (int)$link['v'] + (int)$camp['v'],
        'earned'   => max(0.0, round((float)$link['e'] + (float)$camp['e'], 2)),
        'clips'    => 0,
        'verified' => 0,
        'joined'   => 0,
        'academy'  => 0,
        'paid'     => 0,
    ];
    try {
        $m['clips'] = (int)fetch_val('SELECT (SELECT COUNT(*) FROM content_links WHERE user_id = ? AND status = "active") + (SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND status = "approved")', [$uid, $uid], 0);
        $m['verified'] = (int)fetch_val('SELECT COUNT(*) FROM social_pages WHERE user_id = ? AND status = "approved"', [$uid], 0);
        $m['joined'] = (int)fetch_val('SELECT COUNT(*) FROM campaign_joins WHERE user_id = ?', [$uid], 0);
        $m['academy'] = (string)fetch_val('SELECT assessment_status FROM users WHERE id = ?', [$uid], '') === 'passed' ? 1 : 0;
        $m['paid'] = (int)fetch_val('SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND status = "paid"', [$uid], 0);
    } catch (Throwable $e) {
    }
    return $m;
}

/**
 * Every enabled achievement with the creator's progress:
 * key, title, desc, icon, target, value, pct, unlocked, amount, claimed, claimable.
 */
function achievement_board(int $uid): array
{
    $m = achievement_metrics($uid);
    $rules = achievement_rules();
    $claimed = [];
    try {
        foreach (fetch_all('SELECT kind, amount FROM user_bonuses WHERE user_id = ? AND kind LIKE "ach:%"', [$uid]) as $r) {
            $claimed[substr((string)$r['kind'], 4)] = (float)$r['amount'];
        }
    } catch (Throwable $e) {
    }
    $out = [];
    foreach (achievement_defs() as $k => [$title, $desc, $icon, $metric, $target]) {
        if (!$rules[$k]['on']) {
            continue;
        }
        $value = $m[$metric] ?? 0;
        $unlocked = $value >= $target;
        $isClaimed = array_key_exists($k, $claimed);
        $amount = $isClaimed ? $claimed[$k] : $rules[$k]['amount'];
        $out[] = [
            'key' => $k, 'title' => $title, 'desc' => $desc, 'icon' => $icon, 'metric' => $metric,
            'target' => $target, 'value' => $value, 'pct' => $target > 0 ? min(100, (int)floor($value / $target * 100)) : 100,
            'unlocked' => $unlocked, 'amount' => $amount, 'claimed' => $isClaimed,
            'claimable' => $unlocked && !$isClaimed && $rules[$k]['amount'] > 0,
        ];
    }
    return $out;
}

/** Number of achievement rewards waiting to be claimed. */
function achievement_claimable_count(int $uid): int
{
    return count(array_filter(achievement_board($uid), function ($a) { return $a['claimable']; }));
}

/** Claim an achievement reward. Same result shape as reward_redeem_code(). */
function achievement_claim(array $user, string $key): array
{
    $uid = (int)$user['id'];
    foreach (achievement_board($uid) as $a) {
        if ($a['key'] !== $key) {
            continue;
        }
        if ($a['claimed']) {
            return ['ok' => false, 'error' => 'You already claimed this reward.'];
        }
        if (!$a['unlocked']) {
            return ['ok' => false, 'error' => 'Keep going! This achievement is not unlocked yet.'];
        }
        if ($a['amount'] <= 0) {
            return ['ok' => false, 'error' => 'This achievement is a badge without a cash reward.'];
        }
        if (!reward_credit($uid, 'ach:' . $key, $a['amount'], 'Achievement: ' . $a['title'])) {
            return ['ok' => false, 'error' => 'You already claimed this reward.'];
        }
        notif_add($uid, 'bonus', 'Achievement reward: ' . $a['title'], money($a['amount']) . ' was added to your wallet. Well done!', 'creator/rewards.php');
        return ['ok' => true, 'amount' => (float)$a['amount'], 'label' => $a['title']];
    }
    return ['ok' => false, 'error' => 'That achievement is not available.'];
}
