<?php
/**
 * TikTok page score (owner, 2026-11-10).
 *
 * Many clippers take a clip and post like a machine: several videos at once, no caption of their own, no replies.
 * TikTok treats such pages as spam and shows their videos to 10 or 20 people. The score tells every creator, in
 * plain words, how healthy their page is and exactly what to change. It is OPEN on purpose (unlike the integrity
 * checks): the creator sees every line, the points for it, and a tip.
 *
 * It reads the page's last 20 videos from TikTok (all of them, not only HPL clips: TikTok judges the whole page),
 * keeps the result for 6 hours, and never changes money or blocks anything by itself.
 *
 * 100 points:
 *   30  views a normal video gets (the middle one, so one lucky video does not hide ten dead ones)
 *   25  reactions: likes + comments + shares for every 100 views
 *   15  how many videos got past TikTok's first small test audience (300 views)
 *   20  posting rhythm: no bursts (videos minutes apart, more than 4 in a day), and posting on several days
 *   10  page set-up: HPL tag in the bio, follows people, has videos, nothing removed
 * Fewer than 3 videos in the last 30 days: no number yet, only the to-do list.
 */

/** Score from a list of videos. Pure (no database, no TikTok), so it can be tested with made-up pages. */
function page_score_calc(array $videos, array $acc = [], array $ctx = []): array
{
    $now = (int)($ctx['now'] ?? time());
    $recent = [];
    foreach ($videos as $v) {
        $t = (int)($v['create_time'] ?? 0);
        if ($t > $now - 30 * 86400 && $t <= $now) {
            $recent[] = ['t' => $t, 'views' => (int)($v['view_count'] ?? 0), 'likes' => (int)($v['like_count'] ?? 0),
                'comments' => (int)($v['comment_count'] ?? 0), 'shares' => (int)($v['share_count'] ?? 0)];
        }
    }
    usort($recent, function ($a, $b) { return $a['t'] <=> $b['t']; });
    // Views need time: a video younger than 12 hours is not judged on views yet (its timing still counts).
    $aged = array_values(array_filter($recent, function ($v) use ($now) { return $v['t'] <= $now - 12 * 3600; }));
    $parts = [];
    $part = function (string $key, string $name, int $pts, int $max, string $now_text, string $tip) use (&$parts) {
        $parts[] = ['key' => $key, 'name' => $name, 'pts' => max(0, min($max, $pts)), 'max' => $max, 'text' => $now_text, 'tip' => $pts >= $max ? '' : $tip];
    };
    $step = function (float $x, array $ladder): int { foreach ($ladder as $min => $pts) { if ($x >= $min) { return $pts; } } return 0; };

    // --- Page set-up (always scored, so a new creator sees what to do first)
    $setup = 0;
    $todo = [];
    $bioOk = !empty($ctx['bio_ok']);
    $setup += $bioOk ? 4 : 0;
    if (!$bioOk) { $todo[] = 'tag HPL in your bio (type @hpl and tap our name)'; }
    $following = isset($acc['following_count']) ? (int)$acc['following_count'] : null;
    if ($following === null || $following >= 10) { $setup += 2; } else { $todo[] = 'follow at least 10 accounts'; }
    $vc = isset($acc['video_count']) ? (int)$acc['video_count'] : count($videos);
    if ($vc >= 5) { $setup += 2; } else { $todo[] = 'have at least 5 videos on your page'; }
    if (empty($ctx['missing'])) { $setup += 2; } else { $todo[] = 'do not delete or hide clips you sent to HPL'; }
    $part('setup', 'Your page is set up', $setup, 10, $todo ? 'Still to do: ' . implode(', ', $todo) . '.' : 'Bio tag, following and videos are all there.',
        'A page that looks like a real person gets shown to more people.');

    if (count($recent) < 3 || count($aged) < 2) {
        return ['score' => null, 'level' => 'new', 'label' => 'No score yet', 'videos' => count($recent), 'parts' => $parts,
            'summary' => 'Post 3 videos on your TikTok (hours apart, not all at once). Your score shows about half a day after that.'];
    }

    // --- Views of a normal video
    $views = array_column($aged, 'views');
    sort($views);
    $mid = $views[(int)floor((count($views) - 1) / 2)];
    $part('views', 'Views on a normal video', $step($mid, [2000 => 30, 1000 => 26, 500 => 21, 300 => 16, 150 => 10, 60 => 5]), 30,
        'Your middle video has ' . number_format($mid) . ' views.',
        $mid < 150 ? 'Under 150 views means TikTok is only testing your videos on a few people. Slow down: 1 to 3 videos a day, hours apart, and reply to every comment.'
                   : 'Start with the most surprising line. Ask a question in the caption.');

    // --- Reactions
    $tv = array_sum($views);
    $re = array_sum(array_column($aged, 'likes')) + array_sum(array_column($aged, 'comments')) + array_sum(array_column($aged, 'shares'));
    $rate = $tv > 0 ? $re / $tv : 0;
    $per100 = rtrim(rtrim(number_format($rate * 100, 1), '0'), '.');
    // Under 500 views in total a few friends decide the ratio, so it is not judged yet (half points).
    $part('reactions', 'People react to your videos', $tv >= 500 ? $step($rate, ['0.08' => 25, '0.05' => 21, '0.03' => 15, '0.015' => 8, '0.001' => 3]) : 8, 25,
        $tv >= 500 ? 'About ' . $per100 . ' likes, comments or shares for every 100 views.' : 'Not enough views yet to measure this (under 500 in total).',
        'Ask a question in the caption ("Would you dig here?"), reply to every comment in the first hour, and share the video to your WhatsApp status.');

    // --- Past the first test audience
    $out = count(array_filter($views, function ($x) { return $x >= 300; }));
    $share = $out / count($views);
    $part('reach', 'Videos that got past the first test', $step($share, ['0.7' => 15, '0.5' => 11, '0.3' => 7, '0.1' => 3]), 15,
        $out . ' of your last ' . count($views) . ' videos passed 300 views.',
        'TikTok first shows a video to a small group. If they watch to the end, it goes further. Keep only the strong first 2 seconds, and never post the same clip twice.');

    // --- Rhythm (uses every recent video, also the young ones)
    $bursts = 0;
    for ($i = 1; $i < count($recent); $i++) {
        if ($recent[$i]['t'] - $recent[$i - 1]['t'] < 3600) { $bursts++; }
    }
    $perDay = [];
    $days14 = [];
    foreach ($recent as $v) {
        $d = date('Y-m-d', $v['t']);
        $perDay[$d] = ($perDay[$d] ?? 0) + 1;
        if ($v['t'] > $now - 14 * 86400) { $days14[$d] = true; }
    }
    $heavy = count(array_filter($perDay, function ($n) { return $n > 4; }));
    $spam = $bursts + $heavy;
    $r = ($spam === 0 ? 12 : ($spam <= 2 ? 6 : 0)) + (count($days14) >= 4 ? 8 : (count($days14) >= 2 ? 4 : 0));
    $part('rhythm', 'You post like a person, not a machine', $r, 20,
        ($spam === 0 ? 'No videos posted minutes apart.' : $bursts . ' time' . ($bursts === 1 ? '' : 's') . ' you posted two videos less than an hour apart' . ($heavy ? ', and ' . $heavy . ' day' . ($heavy === 1 ? '' : 's') . ' with more than 4 videos' : '') . '.')
        . ' You posted on ' . count($days14) . ' of the last 14 days.',
        $spam > 0 ? 'Posting many videos at once is the main reason TikTok hides a page. Leave at least 3 hours between videos and post no more than 3 a day.'
                  : 'Post on more days: one video a day beats seven videos on Sunday.');

    $score = array_sum(array_column($parts, 'pts'));
    $level = $score >= 80 ? 'strong' : ($score >= 60 ? 'good' : ($score >= 40 ? 'building' : 'low'));
    $labels = ['strong' => 'Strong page', 'good' => 'Good page', 'building' => 'Building up', 'low' => 'Needs work'];
    // The one thing to do next = the line that lost the most points.
    $worst = null;
    foreach ($parts as $p) {
        if ($p['tip'] !== '' && ($worst === null || ($p['max'] - $p['pts']) > ($worst['max'] - $worst['pts']))) { $worst = $p; }
    }
    return ['score' => $score, 'level' => $level, 'label' => $labels[$level], 'videos' => count($recent), 'parts' => $parts,
        'facts' => ['rate' => $tv >= 500 ? round($rate * 100, 1) : null, 'middle' => $mid, 'passed' => $out, 'judged' => count($views)],
        'summary' => $worst ? $worst['tip'] : 'Keep doing what you are doing.'];
}

/** The tips every creator can read, in the order that matters most. */
function page_score_tips(): array
{
    return [
        ['Do not post everything at once', 'Post 1 to 3 videos a day and leave at least 3 hours between them. Many videos in a few minutes looks like a robot, and TikTok then shows them to almost nobody.'],
        ['Win the first 2 seconds', 'People decide to stay or swipe in 2 seconds. Do not add a slow intro in front of the clip.'],
        ['Write your own caption', 'One line in your own words that asks a question, plus the tag and 3 to 5 hashtags like #zambia #gold #mining. Do not copy the same caption on every video.'],
        ['Reply to every comment', 'Replies in the first hour tell TikTok the video is alive. Ask people a question back.'],
        ['Use TikTok like a person', 'Before and after you post, spend a few minutes watching, liking and commenting on other gold and Zambia videos.'],
        ['Never delete and post again', 'Posting the same video twice counts as a copy. If a video is slow, leave it and make the next one better.'],
        ['Give a new page 2 days', 'A new account is tested slowly. Follow people, like and comment for a day or two before your first clip.'],
        ['0 views? Wait first', 'Sometimes the counter is late. Wait a day before you worry.'],
    ];
}

/**
 * This creator's score, read from TikTok at most every 6 hours (kept in users.page_score*).
 * $allowFetch = false returns only what is saved (used on busy pages). Never throws.
 */
function page_score_for(array $user, bool $allowFetch = true): ?array
{
    try {
        if (!hpl_column_exists('users', 'page_score_at')) {
            return null;
        }
        $uid = (int)$user['id'];
        $saved = !empty($user['page_score_json']) ? json_decode((string)$user['page_score_json'], true) : null;
        $age = !empty($user['page_score_at']) ? time() - strtotime((string)$user['page_score_at']) : PHP_INT_MAX;
        // Scores saved before the engagement circle existed (no 'facts') are read again once.
        if (is_array($saved) && (($age < 6 * 3600 && ($saved['score'] === null || isset($saved['facts']))) || !$allowFetch)) {
            return $saved;
        }
        if (!$allowFetch) {
            return null;
        }
        require_once __DIR__ . '/tiktok.php';
        require_once __DIR__ . '/ready_clips.php';
        $acc = tiktok_account_for_user($uid);
        if (!$acc || !tiktok_configured() || tiktok_account_problem($acc) !== null) {
            return is_array($saved) ? $saved : ['score' => null, 'level' => 'none', 'label' => 'No score yet', 'videos' => 0, 'parts' => [],
                'summary' => $acc ? 'Connect your TikTok again to see your score.' : 'Connect your TikTok to see your score.'];
        }
        // Mark first: if TikTok is slow, other page views use the saved score instead of asking again.
        q('UPDATE users SET page_score_at = NOW() WHERE id = ?', [$uid]);
        $GLOBALS['hpl_tt_timeout'] = 5;
        $videos = tiktok_recent_videos($acc, 20);
        unset($GLOBALS['hpl_tt_timeout']);
        if (!$videos && is_array($saved)) {
            return $saved; // TikTok did not answer: keep the last score
        }
        $res = page_score_calc($videos, $acc, [
            'bio_ok' => ready_bio_ok((string)($acc['bio'] ?? '')), 'tag' => ready_tag_handle(),
            'missing' => (bool)fetch_val('SELECT 1 FROM campaign_submissions WHERE user_id = ? AND miss_count >= 1000 AND status <> "rejected" LIMIT 1', [$uid], 0),
        ]);
        $res['at'] = time();
        q('UPDATE users SET page_score = ?, page_score_json = ?, page_score_at = NOW() WHERE id = ?', [$res['score'], json_encode($res), $uid]);
        return $res;
    } catch (Throwable $e) {
        error_log('page_score_for: ' . $e->getMessage());
        return null;
    }
}

/** Background: refresh a few of the oldest scores per run (creators who sent a clip in the last 30 days). */
function page_score_refresh(int $max = 6): int
{
    $n = 0;
    try {
        if (!hpl_column_exists('users', 'page_score_at')) {
            return 0;
        }
        foreach (fetch_all('SELECT u.* FROM users u WHERE u.role = "creator" AND u.status = "active"
              AND EXISTS (SELECT 1 FROM tiktok_accounts t WHERE t.user_id = u.id)
              AND (u.page_score_at IS NULL OR u.page_score_at < NOW() - INTERVAL 12 HOUR)
              ORDER BY u.page_score_at IS NOT NULL, u.page_score_at ASC LIMIT ' . max(1, min(20, $max))) as $u) {
            page_score_for($u, true);
            $n++;
        }
    } catch (Throwable $e) {
    }
    return $n;
}

/** Icon for each score line. */
function page_score_icon(string $key): string
{
    return icon(['setup' => 'user', 'views' => 'eye', 'reactions' => 'heart', 'reach' => 'trending', 'rhythm' => 'clock'][$key] ?? 'check');
}

/** An animated circle: $pct 0-100 of the ring is filled, $big is the number in the middle. */
function page_score_ring(float $pct, string $big, string $small, string $tone, string $label): string
{
    $c = 2 * M_PI * 42;
    $pct = max(0, min(100, $pct));
    return '<figure class="ps2__ring ps2__ring--' . e($tone) . '" aria-label="' . e($label) . '">'
        . '<svg viewBox="0 0 100 100" aria-hidden="true"><circle class="ps2__track" cx="50" cy="50" r="42"/>'
        . '<circle class="ps2__fill" cx="50" cy="50" r="42" stroke-dasharray="' . round($c, 2) . '" style="--to:' . round($c * (1 - $pct / 100), 2) . ';--from:' . round($c, 2) . '"/></svg>'
        . '<span class="ps2__num"><b data-count="' . e($big) . '">' . e($big) . '</b><small>' . e($small) . '</small></span>'
        . '<figcaption>' . e($label) . '</figcaption></figure>';
}

/** The score card for the creator's Profile: two circles, one next step, then the five lines (tap to open). */
function page_score_card(array $user): string
{
    $s = page_score_for($user, true);
    if (!$s) {
        return '';
    }
    $has = $s['score'] !== null;
    $tone = ['strong' => 'green', 'good' => 'lime', 'building' => 'gold', 'low' => 'red'][$s['level']] ?? 'grey';
    $rate = $s['facts']['rate'] ?? null;
    // The engagement circle is full at 8 reactions per 100 views (twice what is normal on TikTok).
    $rTone = $rate === null ? 'grey' : ($rate >= 4 ? 'green' : ($rate >= 2 ? 'gold' : 'red'));
    $h = '<section class="ps2" id="pageScore" aria-label="Your TikTok score">'
        . '<h2 class="ps2__title">Your TikTok score</h2>'
        . '<div class="ps2__rings">'
        . page_score_ring($has ? (float)$s['score'] : 0, $has ? (string)(int)$s['score'] : '–', $has ? 'of 100' : 'soon', $has ? $tone : 'grey', 'Score')
        . page_score_ring($rate === null ? 0 : min(100, $rate / 8 * 100), $rate === null ? '–' : rtrim(rtrim(number_format($rate, 1), '0'), '.') . '%', $rate === null ? 'soon' : ($rate >= 4 ? 'great' : ($rate >= 2 ? 'okay' : 'low')), $rTone, 'Engagement')
        . '</div>'
        . '<p class="ps2__label ps2__label--' . e($tone) . '">' . e($s['label']) . '</p>'
        . ($rate !== null ? '<p class="ps2__explain">' . e(rtrim(rtrim(number_format($rate, 1), '0'), '.')) . ' of every 100 viewers react. Normal is about 4.</p>' : '')
        . '<div class="ps2__next"><span>' . icon('sparkles') . '</span><p><strong>' . ($has ? 'Do this next' : 'To get your score') . '</strong>' . e($s['summary']) . '</p></div>';
    if (!empty($s['parts'])) {
        $h .= '<h3 class="ps2__sub">How your score is made</h3><div class="ps2__parts">';
        foreach ($s['parts'] as $p) {
            $pct = $p['max'] > 0 ? round($p['pts'] / $p['max'] * 100) : 0;
            $cls = $pct >= 70 ? 'good' : ($pct >= 40 ? 'mid' : 'low');
            $h .= '<details class="ps2__part ps2__part--' . $cls . '"><summary><span class="ps2__pico">' . page_score_icon((string)$p['key']) . '</span>'
                . '<span class="ps2__pname">' . e($p['name']) . '<i class="ps2__bar"><i style="--w:' . $pct . '%"></i></i></span>'
                . '<b class="ps2__pts">' . (int)$p['pts'] . '<small>/' . (int)$p['max'] . '</small></b></summary>'
                . '<p>' . e($p['text']) . ($p['tip'] !== '' ? '<br><strong>Tip:</strong> ' . e($p['tip']) : '') . '</p></details>';
        }
        $h .= '</div>';
    }
    $h .= '<details class="ps2__tips"><summary>How to get more views</summary><ol>';
    foreach (page_score_tips() as $t) {
        $h .= '<li><strong>' . e($t[0]) . '.</strong> ' . e($t[1]) . '</li>';
    }
    $h .= '</ol></details><p class="ps2__foot">From the last 20 videos on your TikTok page' . (!empty($s['at']) ? ', read ' . e(time_ago(date('Y-m-d H:i:s', (int)$s['at']))) : '') . '. It does not change your pay.</p>'
        . '<script>(function(){var c=document.getElementById("pageScore");if(!c)return;'
        . 'var still=window.matchMedia&&matchMedia("(prefers-reduced-motion: reduce)").matches;'
        . 'function go(){c.classList.add("is-on");if(still)return;[].forEach.call(c.querySelectorAll("[data-count]"),function(b){var t=b.getAttribute("data-count"),m=/^(\d+(?:\.\d)?)(%?)$/.exec(t);if(!m)return;var end=parseFloat(m[1]),dec=m[1].indexOf(".")>=0,t0=null;'
        . 'function f(ts){if(t0===null)t0=ts;var k=Math.min(1,(ts-t0)/1100),e=1-Math.pow(1-k,3),v=end*e;b.textContent=(dec?v.toFixed(1):Math.round(v))+m[2];if(k<1)requestAnimationFrame(f);}requestAnimationFrame(f);});}'
        . 'if("IntersectionObserver" in window){var o=new IntersectionObserver(function(es){if(es[0].isIntersecting){o.disconnect();go();}},{threshold:.3});o.observe(c);}else go();})();</script>'
        . '</section>';
    return $h;
}
