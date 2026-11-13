<?php
/**
 * Clip allowance (owner, 2026-11-12): "if it notices a person just jumping on clips, not getting engagement, and with a bad
 * score, it should limit them to 1 video a day". Every creator's recent work is read and judged like a person would:
 *
 *   - views their clips gained after sending (the middle clip, so one lucky or unlucky clip does not decide);
 *   - reactions: likes + comments + shares for every 100 views (TikTok rewards this most);
 *   - their open TikTok page score (users.page_score);
 *   - clips rejected by the team;
 *   - ready clips taken and never posted (users.ready_warnings), i.e. jumping on clips.
 *
 * Two or more weak signs, or taking clips and not posting them, = 1 clip a day instead of the normal number. One good clip
 * (recover_views gained in the last 7 days) gives the normal number back at once: the limit is a nudge, never a punishment.
 * New creators (fewer than 2 judged clips) are never limited. Money is never touched; the team can override per creator.
 * Only OPEN numbers are used (views, reactions, page score, rejections): the hidden integrity checks stay out of this.
 * clip_trust_calc() is pure, so the rules can be tested without a database.
 */

/** The rule numbers, editable in Admin > Ready clips. */
function clip_trust_cfg(): array
{
    return [
        'on'            => setting('clip_trust_on', '1') === '1',
        // Learned (2026-11-12): "weak" = below what the bottom quarter of this month's clips on this site get, never above the
        // number set in Admin. With fewer than 20 clips to learn from, the Admin number is used.
        'min_views'     => (int)min((int)setting('clip_trust_min_views', '200'), max(50, (function () { require_once __DIR__ . '/brain_mind.php'; return mind_site_percentile('clip_views', 0.25, (float)setting('clip_trust_min_views', '200')); })())),
        'min_reactions' => max(0, (float)setting('clip_trust_min_reactions', '1.5')), // reactions per 100 views under this = weak
        'min_score'     => max(0, (int)setting('clip_trust_min_score', '40')),      // page score under this = weak
        'recover_views' => max(1, (int)setting('clip_trust_recover_views', '1000')),  // one clip this good = normal again
    ];
}

/**
 * Pure judgement. $s: judged (clips sent 1 to 21 days ago, not rejected), median_views, views_total, reactions_total,
 * page_score (null = unknown), rejected (21 days), abandoned (ready clips taken and lost), ready_sent, best_recent (best views
 * gained by a clip sent in the last 7 days). Returns ['limited' => bool, 'signs' => [key => creator-facing line], 'why' => text].
 */
function clip_trust_calc(array $s, array $cfg): array
{
    $signs = [];
    $judged = (int)($s['judged'] ?? 0);
    $abandoned = (int)($s['abandoned'] ?? 0);
    if ($judged >= 3 && (int)$s['median_views'] < $cfg['min_views']) {
        $signs['views'] = 'your clips get few views (a normal clip of yours: ' . number_format((int)$s['median_views']) . ' views)';
    }
    $vt = (int)($s['views_total'] ?? 0);
    if ($vt >= 500) {
        $per100 = $vt > 0 ? (int)$s['reactions_total'] / $vt * 100 : 0;
        if ($per100 < $cfg['min_reactions']) {
            $signs['reactions'] = 'few people like or comment (' . rtrim(rtrim(number_format($per100, 1), '0'), '.') . ' in every 100 viewers)';
        }
    }
    if (isset($s['page_score']) && $s['page_score'] !== null && (int)$s['page_score'] < $cfg['min_score']) {
        $signs['score'] = 'your TikTok page score is low (' . (int)$s['page_score'] . ' out of 100)';
    }
    if ((int)($s['rejected'] ?? 0) >= 2) {
        $signs['rejected'] = (int)$s['rejected'] . ' of your clips were not accepted lately';
    }
    if ($abandoned >= 2 && $abandoned >= (int)($s['ready_sent'] ?? 0)) {
        $signs['abandoned'] = 'you took ' . $abandoned . ' clips and did not post them';
    }
    $new = $judged < 2 && !isset($signs['abandoned']);
    $recovered = (int)($s['best_recent'] ?? 0) >= $cfg['recover_views'];
    $limited = !$new && !$recovered && (count($signs) >= 2 || isset($signs['abandoned']));
    $why = $limited ? implode('; ', $signs) : ($recovered ? 'a recent clip did well' : ($new ? 'new creator' : 'doing fine'));
    return ['limited' => $limited, 'signs' => $signs, 'why' => $why, 'recovered' => $recovered, 'new' => $new];
}

/** Read one creator's numbers. */
function clip_trust_stats(int $uid): array
{
    $rows = fetch_all('SELECT s.id, GREATEST(CAST(s.views AS SIGNED) - CAST(COALESCE(s.start_views, 0) AS SIGNED), 0) gained, s.views,
                              s.submitted_at,
                              (SELECT c.likes + c.comments + c.shares FROM clip_stats c WHERE c.submission_id = s.id ORDER BY c.id DESC LIMIT 1) reacts
                       FROM campaign_submissions s
                       WHERE s.user_id = ? AND s.status <> "rejected" AND s.submitted_at <= NOW() - INTERVAL 1 DAY AND s.submitted_at >= NOW() - INTERVAL 21 DAY', [$uid]);
    $gained = array_map(fn($r) => (int)$r['gained'], $rows);
    sort($gained);
    $n = count($gained);
    $median = $n ? ($n % 2 ? $gained[intdiv($n, 2)] : (int)round(($gained[$n / 2 - 1] + $gained[$n / 2]) / 2)) : 0;
    $vt = 0; $rt = 0;
    foreach ($rows as $r) {
        if ($r['reacts'] !== null) { $vt += (int)$r['views']; $rt += (int)$r['reacts']; }
    }
    $u = fetch_one('SELECT page_score, ready_warnings FROM users WHERE id = ?', [$uid]) ?: [];
    return [
        'judged' => $n, 'median_views' => $median, 'views_total' => $vt, 'reactions_total' => $rt,
        'page_score' => isset($u['page_score']) && $u['page_score'] !== null ? (int)$u['page_score'] : null,
        'rejected' => (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND status = "rejected" AND submitted_at >= NOW() - INTERVAL 21 DAY', [$uid], 0),
        'abandoned' => (int)($u['ready_warnings'] ?? 0),
        'ready_sent' => (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND ready_clip_id IS NOT NULL', [$uid], 0),
        'best_recent' => (int)fetch_val('SELECT MAX(GREATEST(CAST(views AS SIGNED) - CAST(COALESCE(start_views, 0) AS SIGNED), 0)) FROM campaign_submissions
                                        WHERE user_id = ? AND status <> "rejected" AND submitted_at >= NOW() - INTERVAL 7 DAY', [$uid], 0),
    ];
}

/**
 * Clips a day for this creator: the normal number, or 1 while limited. Judged again at most every 3 hours (or when $fresh).
 * The creator is told when the limit starts and when it ends.
 */
function clip_trust_per_day(int $uid, int $normal, bool $fresh = false): int
{
    if ($normal <= 1) return $normal;
    try {
        $u = fetch_one('SELECT clip_trust, clip_trust_at, clip_trust_override FROM users WHERE id = ?', [$uid]);
    } catch (Throwable $e) {
        return $normal; // before the columns exist
    }
    if (!$u) return $normal;
    if ($u['clip_trust_override'] !== null) {
        return (int)$u['clip_trust_override'] === 1 ? 1 : $normal;
    }
    $cfg = clip_trust_cfg();
    if (!$cfg['on']) return $normal;
    if (!$fresh && !empty($u['clip_trust_at']) && strtotime((string)$u['clip_trust_at']) > time() - 3 * 3600) {
        return (int)$u['clip_trust'] === 1 ? 1 : $normal;
    }
    $r = clip_trust_calc(clip_trust_stats($uid), $cfg);
    $was = (int)$u['clip_trust'] === 1;
    q('UPDATE users SET clip_trust = ?, clip_trust_why = ?, clip_trust_at = NOW() WHERE id = ?', [$r['limited'] ? 1 : 0, mb_substr($r['why'], 0, 400), $uid]);
    if ($r['limited'] && !$was) {
        notif_add($uid, 'warning', 'For now you can post 1 clip a day',
            'Because ' . $r['why'] . '. Get ' . $normal . ' a day back with one clip that reaches ' . number_format($cfg['recover_views'])
            . ' views: post the clip as it is, at a good time, and reply to every comment in the first hour.', 'creator/index.php');
    } elseif (!$r['limited'] && $was) {
        notif_add($uid, 'success', 'You can post ' . $normal . ' clips a day again', 'Well done: ' . $r['why'] . '. Keep it up.', 'creator/index.php');
    }
    return $r['limited'] ? 1 : $normal;
}

/** Plain line for the creator, when limited (shown next to the daily limit). */
function clip_trust_line(int $uid): string
{
    try {
        $u = fetch_one('SELECT clip_trust, clip_trust_why, clip_trust_override FROM users WHERE id = ?', [$uid]);
    } catch (Throwable $e) {
        return '';
    }
    if (!$u || ((int)$u['clip_trust'] !== 1 && (int)($u['clip_trust_override'] ?? 0) !== 1)) return '';
    $cfg = clip_trust_cfg();
    return 'You can post 1 clip a day for now' . (!empty($u['clip_trust_why']) && (int)$u['clip_trust'] === 1 ? ' because ' . $u['clip_trust_why'] : '')
        . '. One clip with ' . number_format($cfg['recover_views']) . ' views gives you 2 a day again.';
}

/** Background: judge creators who were active lately, oldest judgement first. */
function clip_trust_refresh(int $max = 40, int $normal = 2): int
{
    $n = 0;
    try {
        foreach (fetch_all('SELECT DISTINCT u.id FROM users u JOIN campaign_submissions s ON s.user_id = u.id
                            WHERE u.role = "creator" AND u.is_demo = 0 AND s.submitted_at >= NOW() - INTERVAL 21 DAY
                              AND (u.clip_trust_at IS NULL OR u.clip_trust_at < NOW() - INTERVAL 3 HOUR) ORDER BY u.clip_trust_at IS NOT NULL, u.clip_trust_at LIMIT ' . (int)$max) as $r) {
            clip_trust_per_day((int)$r['id'], $normal, true);
            $n++;
        }
    } catch (Throwable $e) {
        error_log('clip_trust_refresh: ' . $e->getMessage());
    }
    return $n;
}
