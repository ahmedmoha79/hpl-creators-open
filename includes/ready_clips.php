<?php
/**
 * Ready clips (owner decision 2026-11-05).
 *
 * Besides the raw footage (for creators who edit), each campaign can have finished clips the
 * HPL team uploads. A creator who cannot edit takes one, downloads it, posts it on their own
 * verified page and sends the link, like any campaign clip.
 *  - Each clip goes to ONE creator only (identical videos on many accounts get hidden by TikTok).
 *  - Creators who passed the test may use ready clips; raw footage still needs full approval.
 *  - Same pay rate and rules as an edited clip (it is an ordinary campaign submission).
 * Files live in uploads/ready_clips/ (never served directly; streamed by PHP to allowed users).
 */
require_once __DIR__ . '/earnings.php';
require_once __DIR__ . '/tiktok.php';

function ready_clips_dir(): string
{
    $dir = UPLOAD_PATH . '/ready_clips';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_file($dir . '/.htaccess')) {
        @file_put_contents($dir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    }
    if (!is_file($dir . '/index.html')) {
        @file_put_contents($dir . '/index.html', '');
    }
    return $dir;
}

/** Passed the test (or fully approved): may take and post ready clips. */
function creator_can_post_ready(array $user): bool
{
    return (int)($user['qualified'] ?? 0) === 1 || ($user['assessment_status'] ?? '') === 'passed';
}

/** How many taken clips a creator may hold before posting them (so nobody takes them all). */
/** Days a creator has to send the link of a clip they took (owner decision: 3). */
function ready_clip_days(): int
{
    return max(1, (int)setting('ready_clip_days', '3'));
}

/** When a taken clip goes back to the others if no link was sent. */
function ready_clip_deadline(?string $claimedAt): int
{
    return $claimedAt ? strtotime($claimedAt) + ready_clip_days() * 86400 : 0;
}

/**
 * Taken clips with no link after N days go back to the free list, and the creator gets a warning.
 * One day before, they get a reminder. Runs with the background view sync (about every 30 minutes).
 */
function ready_clips_expire(): array
{
    $done = ['reminded' => 0, 'returned' => 0];
    $days = ready_clip_days();
    try {
        // Reminder: one day left.
        foreach (fetch_all('SELECT r.id, r.claimed_by, r.claimed_at, r.campaign_id, c.title FROM ready_clips r JOIN campaigns c ON c.id = r.campaign_id
                WHERE r.claimed_by IS NOT NULL AND r.submission_id IS NULL AND r.is_active = 1 AND r.reminded_at IS NULL
                AND r.claimed_at <= NOW() - INTERVAL ? HOUR AND r.claimed_at > NOW() - INTERVAL ? DAY', [$days * 24 - 24, $days]) as $r) {
            if (q('UPDATE ready_clips SET reminded_at = NOW() WHERE id = ? AND reminded_at IS NULL AND submission_id IS NULL', [(int)$r['id']])->rowCount() === 1) {
                notif_add((int)$r['claimed_by'], 'reminder', 'Ready clip #' . (int)$r['id'] . ': 1 day left',
                    'Post it on TikTok (tag HPL: type @hpl and tap ' . ready_tag_name() . ') and send the link by ' . date('D j M, H:i', ready_clip_deadline($r['claimed_at'])) . ', or it goes back and you get a warning.',
                    'creator/campaign.php?id=' . (int)$r['campaign_id'] . '#campReady');
                $done['reminded']++;
            }
        }
        // Time is up: the clip goes back, the creator is warned.
        foreach (fetch_all('SELECT r.id, r.claimed_by, r.campaign_id, c.title FROM ready_clips r JOIN campaigns c ON c.id = r.campaign_id
                WHERE r.claimed_by IS NOT NULL AND r.submission_id IS NULL AND r.is_active = 1 AND r.claimed_at <= NOW() - INTERVAL ? DAY', [$days]) as $r) {
            $uid = (int)$r['claimed_by'];
            $ok = q('UPDATE ready_clips SET claimed_by = NULL, claimed_at = NULL, reminded_at = NULL WHERE id = ? AND claimed_by = ? AND submission_id IS NULL', [(int)$r['id'], $uid])->rowCount() === 1;
            if (!$ok) {
                continue;
            }
            q('UPDATE users SET ready_warnings = ready_warnings + 1 WHERE id = ?', [$uid]);
            $n = (int)fetch_val('SELECT ready_warnings FROM users WHERE id = ?', [$uid], 1);
            notif_add($uid, 'warning', 'Warning: ready clip #' . (int)$r['id'] . ' was taken back',
                'No link in ' . $days . ' days, so it went back. Do not post it now. (Warning ' . $n . ')',
                'creator/campaign.php?id=' . (int)$r['campaign_id'] . '#campReady');
            $done['returned']++;
        }
        $done['freed'] = ready_clips_free_deleted();
    } catch (Throwable $e) {
        error_log('ready_clips_expire: ' . $e->getMessage());
    }
    return $done;
}

/**
 * A ready clip whose TikTok post was deleted goes back to the free list (owner, 2026-11-11). "Deleted" = the post has been
 * gone from TikTok for 2 hours over 3 reads (integrity_clip_missing() sets miss_count 1000). The old link and its money are
 * left as they are (a person decides on those); only the clip itself is given back so another creator can post it.
 */
function ready_clips_free_deleted(int $onlySubId = 0): int
{
    $n = 0;
    try {
        $rows = fetch_all('SELECT r.id, r.campaign_id, s.user_id FROM ready_clips r JOIN campaign_submissions s ON s.id = r.submission_id
                           WHERE r.is_active = 1 AND s.miss_count >= 1000' . ($onlySubId > 0 ? ' AND s.id = ' . (int)$onlySubId : '') . ' LIMIT 200');
        foreach ($rows as $r) {
            $ok = q('UPDATE ready_clips r JOIN campaign_submissions s ON s.id = r.submission_id
                     SET r.claimed_by = NULL, r.claimed_at = NULL, r.reminded_at = NULL, r.submission_id = NULL
                     WHERE r.id = ? AND s.miss_count >= 1000', [(int)$r['id']])->rowCount() > 0;
            if (!$ok) {
                continue;
            }
            $n++;
            notif_add((int)$r['user_id'], 'warning', 'Ready clip #' . (int)$r['id'] . ' went back to the free list',
                'Its post is no longer on your TikTok, so another creator can take it now. Do not post this clip again. You can take a new clip when your wait is over.',
                'creator/campaign.php?id=' . (int)$r['campaign_id'] . '#campReady');
        }
    } catch (Throwable $e) {
        error_log('ready_clips_free_deleted: ' . $e->getMessage());
    }
    return $n;
}

function ready_clip_hold_max(): int
{
    return max(1, (int)setting('ready_clip_hold_max', '1'));
}

/**
 * Minutes a creator must wait between taking one ready clip and taking the next.
 * Spacing posts out keeps TikTok's algorithm happy: a burst of clips posted at once
 * gets throttled, so each one reaches fewer people. Owner decision 2026-11-07.
 */
function ready_clip_cooldown_mins(): int
{
    return max(0, (int)setting('ready_clip_cooldown_mins', '240'));
}

/**
 * Most campaign clips a creator may post in one day (owner, 2026-11-12: 2 a day, 4 hours apart, then come back tomorrow;
 * it keeps out people who only spam clips and wait for money). 0 = no limit. The day is Lusaka's calendar day.
 */
function ready_clips_per_day(): int
{
    return max(0, (int)setting('ready_clips_per_day', '2'));
}

/** This creator's clips a day: the normal number, or 1 while their recent clips are weak (includes/clip_trust.php). */
function ready_clips_per_day_for(int $userId): int
{
    static $cache = [];
    if (!isset($cache[$userId])) {
        $normal = ready_clips_per_day();
        require_once __DIR__ . '/clip_trust.php';
        $cache[$userId] = $normal > 0 ? clip_trust_per_day($userId, $normal) : 0;
    }
    return $cache[$userId];
}

/** Campaign clip links this creator sent today (rejected ones do not count, so a fixed link can be sent again). */
function clips_sent_today(int $userId): int
{
    try {
        return (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND status <> "rejected" AND submitted_at >= ?', [$userId, date('Y-m-d 00:00:00')], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** True when today's clips are used up: links sent today plus the clip still held count. */
function ready_day_full(int $userId): bool
{
    $max = ready_clips_per_day_for($userId);
    return $max > 0 && clips_sent_today($userId) + ready_clips_unposted($userId) >= $max;
}

/** What a creator reads when today's clips are used up. */
function ready_day_full_text(int $userId = 0): string
{
    $n = $userId > 0 ? ready_clips_per_day_for($userId) : ready_clips_per_day();
    $why = '';
    if ($userId > 0 && $n < ready_clips_per_day()) {
        require_once __DIR__ . '/clip_trust.php';
        $why = ' ' . clip_trust_line($userId);
    }
    return 'You posted ' . $n . ' clip' . ($n === 1 ? '' : 's') . ' today, the most for one day. Come back tomorrow for your next clip.' . $why;
}

/**
 * The unix time when this creator may take their next ready clip (0 = now).
 * Measured from their most recent take, so takes (and therefore posts) are spaced apart.
 */
function ready_clip_next_take_at(int $userId): int
{
    $mins = ready_clip_cooldown_mins();
    $dayFull = ready_day_full($userId) ? strtotime('tomorrow') : 0; // today's clips used up: next one tomorrow
    if ($mins <= 0) {
        return $dayFull;
    }
    try {
        // Counted from the LATER of taking a clip and sending a clip's link (2026-11-11): counting from the take
        // alone let a creator post one clip late and the next one at once, two posts an hour apart.
        $last = fetch_val('SELECT MAX(claimed_at) FROM ready_clips WHERE claimed_by = ? AND is_active = 1 AND claimed_at IS NOT NULL', [$userId], null);
        $sent = fetch_val('SELECT MAX(submitted_at) FROM campaign_submissions WHERE user_id = ? AND ready_clip_id IS NOT NULL', [$userId], null);
    } catch (Throwable $e) {
        return 0;
    }
    $t = max($last ? strtotime((string)$last) : 0, $sent ? strtotime((string)$sent) : 0);
    return max($dayFull, $t > 0 ? $t + $mins * 60 : 0);
}

/** Short human wait text for N seconds: "2h 5m", "45 min". */
function ready_clip_wait_text(int $seconds): string
{
    $mins = max(1, (int)ceil($seconds / 60));
    $h = intdiv($mins, 60);
    $m = $mins % 60;
    return $h > 0 ? ($h . 'h' . ($m ? ' ' . $m . 'm' : '')) : ($m . ' min');
}

/** Clips this creator took and has not sent a link for yet. */
/** A taken clip still needs a (new) link: none sent yet, or the link sent was rejected. */
const READY_HELD_SQL = '(r.submission_id IS NULL OR EXISTS (SELECT 1 FROM campaign_submissions s WHERE s.id = r.submission_id AND s.status = "rejected"))';

function ready_clips_unposted(int $userId): int
{
    return (int)fetch_val('SELECT COUNT(*) FROM ready_clips r WHERE r.claimed_by = ? AND r.is_active = 1 AND ' . READY_HELD_SQL, [$userId], 0);
}

/** Counts per campaign: ['total' => n, 'free' => n]. */
function ready_clip_counts(): array
{
    $out = [];
    try {
        foreach (fetch_all('SELECT campaign_id, COUNT(*) total, SUM(claimed_by IS NULL) free FROM ready_clips WHERE is_active = 1 GROUP BY campaign_id') as $r) {
            $out[(int)$r['campaign_id']] = ['total' => (int)$r['total'], 'free' => (int)$r['free']];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * Free clips of a campaign for this creator, and the ones they took.
 * 2026-11-10: with hundreds of creators on the page at once, everyone seeing the same first clips
 * means they all tap the same one and most get "someone else took it". So each creator gets their
 * own mix (stable for them), 12 at a time; $page moves to the next 12. 'free_total' is the real count.
 */
/**
 * Clip styles (owner, 2026-11-11): the admin labels each ready clip when uploading (Podcast clip, Animation...),
 * creators see the label on top of the clip and can show one style only. The list is setting `ready_clip_styles`;
 * a new style typed while uploading is added to it.
 */
function ready_clip_styles(): array
{
    $raw = (string)setting('ready_clip_styles', "Podcast clip\nAnimation\nStreet interview\nIn the field\nProduct demo\nFunny");
    $out = [];
    foreach (preg_split('/\R/', $raw) as $l) {
        $l = ready_clip_style_clean($l);
        if ($l !== '' && !in_array(mb_strtolower($l), array_map('mb_strtolower', $out), true)) { $out[] = $l; }
    }
    return $out;
}

/** A style name as stored: plain words, at most 40 characters. Matches an existing style whatever its capitals. */
function ready_clip_style_clean(string $s): string
{
    $s = trim(preg_replace('/\s+/u', ' ', preg_replace('/[<>"\\\\]/u', '', $s)));
    return mb_substr($s, 0, 40);
}

/** Save a style picked or typed by the admin; a new one joins the list. '' = no style. */
function ready_clip_style_pick(string $s): string
{
    $s = ready_clip_style_clean($s);
    if ($s === '') { return ''; }
    $list = ready_clip_styles();
    foreach ($list as $have) {
        if (mb_strtolower($have) === mb_strtolower($s)) { return $have; }
    }
    $list[] = $s;
    set_setting('ready_clip_styles', implode("\n", $list));
    return $s;
}

/** Free clips of a campaign per style, for the style buttons on the campaign page: [style => count]. */
function ready_clip_style_counts(int $campaignId): array
{
    $out = [];
    try {
        foreach (fetch_all('SELECT COALESCE(style, "") st, COUNT(*) n FROM ready_clips WHERE campaign_id = ? AND is_active = 1 AND claimed_by IS NULL GROUP BY st ORDER BY n DESC', [$campaignId]) as $r) {
            $out[(string)$r['st']] = (int)$r['n'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

function ready_clips_for(int $campaignId, int $userId, int $page = 0, int $per = 12, string $style = ''): array
{
    try {
        $ids = array_map('intval', array_column($style !== ''
            ? fetch_all('SELECT id FROM ready_clips WHERE campaign_id = ? AND is_active = 1 AND claimed_by IS NULL AND style = ? ORDER BY id LIMIT 3000', [$campaignId, $style])
            : fetch_all('SELECT id FROM ready_clips WHERE campaign_id = ? AND is_active = 1 AND claimed_by IS NULL ORDER BY id LIMIT 3000', [$campaignId]), 'id'));
        $total = count($ids);
        usort($ids, function ($x, $y) use ($userId) { return crc32($userId . ':' . $x) <=> crc32($userId . ':' . $y); });
        $pages = max(1, (int)ceil($total / max(1, $per)));
        $page = (($page % $pages) + $pages) % $pages;
        $pick = array_slice($ids, $page * $per, $per);
        $free = [];
        if ($pick) {
            $rows = [];
            foreach (fetch_all('SELECT * FROM ready_clips WHERE id IN (' . implode(',', $pick) . ') AND claimed_by IS NULL AND is_active = 1') as $r) {
                $rows[(int)$r['id']] = $r;
            }
            foreach ($pick as $i) {
                if (isset($rows[$i])) { $free[] = $rows[$i]; }
            }
        }
        $mine = fetch_all('SELECT r.*, s.url AS posted_url, s.status AS sub_status FROM ready_clips r LEFT JOIN campaign_submissions s ON s.id = r.submission_id
                            WHERE r.campaign_id = ? AND r.claimed_by = ? ORDER BY r.claimed_at DESC', [$campaignId, $userId]);
    } catch (Throwable $e) {
        return ['free' => [], 'mine' => [], 'free_total' => 0, 'page' => 0, 'pages' => 1];
    }
    return ['free' => $free, 'mine' => $mine, 'free_total' => $total, 'page' => $page, 'pages' => $pages];
}

/** The src/poster/preload attributes of a clip's <video>, already escaped. With no picture the phone loads just enough to show the first frame. */
function ready_clip_video_attrs(array $urls): string
{
    return 'src="' . e($urls['video']) . '#t=0.5" ' . ($urls['poster'] !== '' ? 'poster="' . e($urls['poster']) . '" preload="none"' : 'preload="metadata"');
}

/**
 * Addresses for a clip's video and its still picture on a page. With fast files on these are the
 * direct file addresses (no PHP at all); otherwise the checked PHP address. Only call this for
 * clips the viewer is allowed to watch.
 */
function ready_clip_urls(array $clip): array
{
    $id = (int)$clip['id'];
    $file = basename((string)$clip['file_name']);
    $v = ready_clip_public($file);
    $jpg = pathinfo($file, PATHINFO_FILENAME) . '.jpg';
    $has = is_file(ready_clips_dir() . '/' . $jpg);
    $p = ($v !== null && $has) ? ready_clip_public($jpg) : null;
    // No still picture on the server (the browser that uploaded the clip could not make one): 'poster' is
    // empty and the page lets the phone draw the first frame of the video itself (ready_clip_video_attrs()).
    return [
        'video' => $v !== null ? u($v) : u('creator/ready_clip.php?id=' . $id),
        'poster' => !$has ? '' : ($p !== null ? u($p) : u('creator/ready_clip.php?id=' . $id . '&poster=1')),
    ];
}

/**
 * Give a free clip to a creator. Returns null on success, or an error message.
 * Locked, so two creators tapping the same clip at once cannot both get it.
 */
function ready_clip_take(int $clipId, array $user): ?string
{
    $uid = (int)$user['id'];
    return hpl_tx(function () use ($clipId, $uid) {
        // Lock the creator first (before any other read), so parallel takes by the same creator
        // queue up and the hold limit below always counts the latest takes.
        fetch_one('SELECT id FROM users WHERE id = ? FOR UPDATE', [$uid]);
        $clip = fetch_one('SELECT * FROM ready_clips WHERE id = ? AND is_active = 1 FOR UPDATE', [$clipId]);
        if (!$clip) {
            return 'That clip is no longer available.';
        }
        if ((int)$clip['claimed_by'] === $uid) {
            return null; // already theirs
        }
        if ($clip['claimed_by'] !== null) {
            return 'Someone else just took that clip. Pick another one.';
        }
        if (!fetch_one('SELECT id FROM campaign_joins WHERE campaign_id = ? AND user_id = ?', [(int)$clip['campaign_id'], $uid])) {
            return 'Join the campaign first.';
        }
        $camp = fetch_one('SELECT status, camp_status, total_budget, paid_amount FROM campaigns WHERE id = ?', [(int)$clip['campaign_id']]);
        if (!$camp || ($camp['status'] ?? 'active') !== 'active' || $camp['camp_status'] !== 'accepting' || (float)$camp['total_budget'] - (float)$camp['paid_amount'] <= 0) {
            return 'This campaign is not accepting clips now.';
        }
        $max = ready_clip_hold_max();
        if (ready_clips_unposted($uid) >= $max) {
            return $max === 1
                ? 'Post the clip you already have and send its link first, then you can take the next one.'
                : 'You already have ' . $max . ' clips waiting. Post them and send their links first, then take more.';
        }
        if (ready_day_full($uid)) {
            return ready_day_full_text($uid);
        }
        $next = ready_clip_next_take_at($uid);
        if ($next > time()) {
            return 'Space your posts out so TikTok shows each one to more people. You can take your next clip in ' . ready_clip_wait_text($next - time()) . '.';
        }
        q('UPDATE ready_clips SET claimed_by = ?, claimed_at = NOW() WHERE id = ?', [$uid, $clipId]);
        return null;
    });
}

/** A clip this creator took and has not posted yet (for the submit form). */
function ready_clip_owned_unposted(int $clipId, int $userId, int $campaignId): ?array
{
    return fetch_one('SELECT r.* FROM ready_clips r WHERE r.id = ? AND r.claimed_by = ? AND r.campaign_id = ? AND r.is_active = 1 AND ' . READY_HELD_SQL, [$clipId, $userId, $campaignId]) ?: null;
}

/**
 * Give a taken clip back (owner, 2026-11-13): only while the creator still holds it (no link, or the link was rejected).
 * No warning. A rejected link stays on record; the clip itself goes back to the free list. Returns an error or null.
 */
function ready_clip_give_back(int $clipId, int $userId): ?string
{
    $clip = fetch_one('SELECT r.* FROM ready_clips r WHERE r.id = ? AND r.claimed_by = ? AND ' . READY_HELD_SQL, [$clipId, $userId]);
    if (!$clip) {
        return 'This clip is not yours to give back, or its link was already sent.';
    }
    $ok = q('UPDATE ready_clips SET claimed_by = NULL, claimed_at = NULL, reminded_at = NULL, submission_id = NULL WHERE id = ? AND claimed_by = ?', [$clipId, $userId])->rowCount() === 1;
    return $ok ? null : 'Could not give it back. Reload the page and try again.';
}

/** The creator who holds this clip without a live link (took it, no link yet or the link was rejected), or 0. */
function ready_clip_holder(array $clip): int
{
    if ($clip['claimed_by'] === null) {
        return 0;
    }
    if ($clip['submission_id'] === null) {
        return (int)$clip['claimed_by'];
    }
    $st = (string)fetch_val('SELECT status FROM campaign_submissions WHERE id = ?', [(int)$clip['submission_id']], '');
    return ($st === '' || $st === 'rejected') ? (int)$clip['claimed_by'] : 0;
}

/** Tell a creator the team removed a clip they took (no warning: it was not their fault). */
function ready_clip_tell_removed(int $userId, array $clip): void
{
    notif_add($userId, 'info', 'Ready clip #' . (int)$clip['id'] . ' was removed by the team',
        'Please do not post it. This is not a warning, and you can take another clip.', 'creator/campaign.php?id=' . (int)$clip['campaign_id'] . '#campReady');
}

/** Tell creators that new ready clips are there: those in the campaign every time, everyone else once a day. */
function ready_clips_notify(int $campaignId, int $count): void
{
    try {
        $c = fetch_one('SELECT id, title FROM campaigns WHERE id = ?', [$campaignId]);
        if (!$c || $count <= 0) {
            return;
        }
        try {
            require_once __DIR__ . '/community.php';
            $by = function_exists('current_user') ? current_user() : null;
            $byId = $by && ($by['role'] ?? '') === 'admin' ? (int)$by['id'] : (int)fetch_val('SELECT id FROM users WHERE role = "admin" AND status = "active" ORDER BY id LIMIT 1', [], 0);
            chat_event($byId, 'announcements', 'added ' . $count . ' new ready clip' . ($count === 1 ? '' : 's') . ' to ' . $c['title'] . '. Take one, post it, send the link');
        } catch (Throwable $e) {
        }
        $n = $count . ' new ready clip' . ($count === 1 ? '' : 's');
        $url = 'creator/campaign.php?id=' . $campaignId . '#campReady';
        foreach (fetch_all('SELECT user_id FROM campaign_joins WHERE campaign_id = ?', [$campaignId]) as $j) {
            notif_add((int)$j['user_id'], 'footage', $n . ' for ' . $c['title'],
                'No editing needed: take a clip, post it on your page and send the link.', $url);
        }
        // Everyone else too (owner, 2026-11-10: a creator who has not joined the campaign yet must also hear
        // about it, or new campaigns stay empty). At most once a day per campaign, so several uploads in a day
        // do not fill people's bells. One statement for all of them, then their phones.
        $key = 'rc_told_all_' . $campaignId;
        if ((int)setting($key, '0') < time() - 86400) {
            set_setting($key, (string)time());
            q('INSERT INTO notifications (user_id, type, title, body, url)
               SELECT u.id, "footage", ?, ?, ? FROM users u
                WHERE u.role = "creator" AND u.status = "active" AND NOT EXISTS (SELECT 1 FROM campaign_joins j WHERE j.campaign_id = ? AND j.user_id = u.id)',
                [mb_substr('New ready clips: ' . $c['title'], 0, 190), $n . ' to post, no editing needed. Join the campaign, take a clip, post it on TikTok and send the link.', $url, $campaignId]);
            try {
                q('UPDATE users SET push_pending_at = NOW() WHERE push_on = 1 AND role = "creator" AND status = "active"');
                require_once __DIR__ . '/push.php';
                push_flush_later();
            } catch (Throwable $e) {
            }
        }
    } catch (Throwable $e) {
    }
}

/*
 * Automatic check of a ready-clip link (2026-11-10).
 * When a link is sent the website already knows the video is on the creator's connected TikTok, the caption
 * has the tag and the bio has the tag. Two more things show that the post really is the clip they took:
 * it was posted after they took the clip, and it is as long as our clip (TikTok tells us the length).
 * All good = "pass". Anything we cannot confirm = "review" (the team looks at it by hand, as before).
 * With the setting auto_approve_ready on (Admin > Ready clips, owner's choice), a "pass" is approved at once.
 */

/** Length of an MP4/MOV file in seconds, read from its header (no ffmpeg on the hosting). Null when unknown. */
function mp4_duration(string $path): ?float
{
    $fh = @fopen($path, 'rb');
    if (!$fh) {
        return null;
    }
    try {
        $size = filesize($path);
        $pos = 0;
        for ($i = 0; $i < 200 && $pos + 8 <= $size; $i++) {
            fseek($fh, $pos);
            $head = fread($fh, 16);
            if (strlen($head) < 8) {
                break;
            }
            $len = unpack('N', substr($head, 0, 4))[1];
            $type = substr($head, 4, 4);
            $hdr = 8;
            if ($len === 1 && strlen($head) >= 16) {
                $p = unpack('N2', substr($head, 8, 8));
                $len = $p[1] * 4294967296 + $p[2];
                $hdr = 16;
            } elseif ($len === 0) {
                $len = $size - $pos;
            }
            if ($len < $hdr) {
                break;
            }
            if ($type === 'moov') {
                fseek($fh, $pos + $hdr);
                $moov = fread($fh, (int)min($len - $hdr, 4 * 1048576));
                $at = strpos($moov, 'mvhd');
                if ($at === false) {
                    return null;
                }
                $b = substr($moov, $at + 4, 32);
                if (strlen($b) < 32) {
                    return null;
                }
                if (ord($b[0]) === 1) {
                    $ts = unpack('N', substr($b, 20, 4))[1];
                    $d = unpack('N2', substr($b, 24, 8));
                    $dur = $d[1] * 4294967296 + $d[2];
                } else {
                    $ts = unpack('N', substr($b, 12, 4))[1];
                    $dur = unpack('N', substr($b, 16, 4))[1];
                }
                return $ts > 0 ? round($dur / $ts, 2) : null;
            }
            $pos += $len;
        }
    } catch (Throwable $e) {
    } finally {
        fclose($fh);
    }
    return null;
}

/** Length of a ready clip in seconds (worked out once, then kept). */
function ready_clip_duration(array $clip): ?float
{
    if (isset($clip['duration']) && $clip['duration'] !== null) {
        return (float)$clip['duration'];
    }
    $d = mp4_duration(ready_clips_dir() . '/' . basename((string)$clip['file_name']));
    if ($d !== null && $d > 0) {
        try { q('UPDATE ready_clips SET duration = ? WHERE id = ?', [$d, (int)$clip['id']]); } catch (Throwable $e) {}
        return $d;
    }
    return null;
}

/**
 * Compare the posted TikTok video ($info from tiktok_video_info) with the ready clip the creator took.
 * Returns ['pass'|'review', short note for the team].
 */
/**
 * Posts must be spaced out (owner, 2026-11-11): a ready clip posted on TikTok less than the cooldown after the
 * creator's previous campaign clip is refused when its link is sent. Returns the refusal text, or null if fine.
 */
function ready_post_gap_error(int $userId, int $createTime): ?string
{
    $mins = ready_clip_cooldown_mins();
    if ($createTime <= 0) {
        return null;
    }
    // Two a day (owner, 2026-11-12): count the creator's other clips posted on TikTok that same day.
    $perDay = ready_clips_per_day_for($userId);
    if ($perDay > 0) {
        $day = date('Y-m-d', $createTime);
        try {
            $sameDay = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND status <> "rejected" AND posted_at >= ? AND posted_at < ?',
                [$userId, $day . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($day . ' +1 day'))], 0);
        } catch (Throwable $e) {
            $sameDay = 0;
        }
        if ($sameDay >= $perDay) {
            return 'You already posted ' . $perDay . ' clip' . ($perDay === 1 ? '' : 's') . ' on TikTok that day, the most for one day. Delete this post, then post the clip again tomorrow and send the new link.';
        }
    }
    if ($mins <= 0) {
        return null;
    }
    try {
        $near = fetch_val('SELECT MAX(posted_at) FROM campaign_submissions WHERE user_id = ? AND status <> "rejected" AND posted_at IS NOT NULL
                           AND posted_at > FROM_UNIXTIME(?) AND posted_at < FROM_UNIXTIME(?)', [$userId, $createTime - $mins * 60, $createTime + $mins * 60], null);
    } catch (Throwable $e) {
        return null;
    }
    if (!$near) {
        return null;
    }
    $h = round($mins / 60, 1);
    $hTxt = ($h == (int)$h ? (int)$h : $h) . ' hour' . ($h == 1 ? '' : 's');
    $ok = strtotime((string)$near) + $mins * 60;
    return 'This video was posted less than ' . $hTxt . ' after your last clip. Posts must be at least ' . $hTxt
        . ' apart so TikTok shows each one to more people. Delete this post, then post the clip again after ' . date('H:i', $ok)
        . ' (' . date('D j M', $ok) . ') and send the new link.';
}

/** Below 1080 pixels high, a clip looks blurry on TikTok (owner, 2026-11-11). 0 = unknown. */
function ready_low_quality(int $height, int $width = 0): bool
{
    $short = $width > 0 ? min($height, $width) : $height;
    return $height > 0 && $short > 0 && $short < 1080;
}

/** How to keep a clip sharp: shown on the campaign page under each taken clip. */
function ready_quality_tip(): string
{
    return 'Keep it sharp: use our Download button (full quality, not a screen recording or a saved copy from WhatsApp). On TikTok, before you post, open "More options" and turn on "Allow high-quality uploads". Also turn off Data Saver in Settings and privacy.';
}

function ready_clip_auto_check(array $clip, ?array $info): array
{
    if (!$info) {
        return ['review', 'TikTok did not give the video details'];
    }
    $taken = !empty($clip['claimed_at']) ? strtotime((string)$clip['claimed_at']) : 0;
    if ((int)$info['create_time'] <= 0 || ($taken > 0 && (int)$info['create_time'] < $taken - 120)) {
        return ['review', 'Posted before the clip was taken'];
    }
    $ours = ready_clip_duration($clip);
    $theirs = $info['duration'] ?? null;
    if ($ours === null || $theirs === null || $theirs <= 0) {
        return ['review', 'Video length could not be compared'];
    }
    $diff = abs($ours - (float)$theirs);
    if ($diff > max(2.0, $ours * 0.06)) {
        return ['review', 'Length is different: ours ' . round($ours) . 's, the post ' . round((float)$theirs) . 's'];
    }
    if (ready_low_quality((int)($info['height'] ?? 0), (int)($info['width'] ?? 0))) {
        return ['review', 'Low quality: TikTok shows it at ' . (int)($info['width'] ?? 0) . 'x' . (int)($info['height'] ?? 0) . ' (ours is 1080x1920)'];
    }
    return ['pass', 'Own account, tags in caption and bio, posted after taking, same length (' . round($ours) . 's)'];
}

/** Above this many different bits (of 64) the post's picture is "not our clip". Setting ready_picture_max (default 22). */
function ready_picture_max_distance(): int
{
    return max(8, min(40, (int)setting('ready_picture_max', '22')));
}

/** 64-bit picture fingerprint (difference hash of the middle 9:16 part, 9x8 grey) as a 64-char 0/1 string, or null. */
function ready_picture_hash(string $bytes): ?string
{
    if ($bytes === '' || !function_exists('imagecreatefromstring')) {
        return null;
    }
    $im = @imagecreatefromstring($bytes);
    if (!$im) {
        return null;
    }
    $w = imagesx($im);
    $h = imagesy($im);
    // Same framing for both: the middle 9:16 part (TikTok covers and our clips are tall; a wide picture is cut to it).
    $cw = $w;
    $ch = (int)round($w * 16 / 9);
    if ($ch > $h) { $ch = $h; $cw = (int)round($h * 9 / 16); }
    $small = imagecreatetruecolor(9, 8);
    imagecopyresampled($small, $im, 0, 0, (int)(($w - $cw) / 2), (int)(($h - $ch) / 2), 9, 8, $cw, $ch);
    $bits = '';
    for ($y = 0; $y < 8; $y++) {
        $prev = null;
        for ($x = 0; $x < 9; $x++) {
            $c = imagecolorat($small, $x, $y);
            $g = (($c >> 16) & 255) * 0.299 + (($c >> 8) & 255) * 0.587 + ($c & 255) * 0.114;
            if ($prev !== null) {
                $bits .= $g > $prev ? '1' : '0';
            }
            $prev = $g;
        }
    }
    imagedestroy($small);
    imagedestroy($im);
    return $bits;
}

/**
 * How different the TikTok post's cover is from our clip's still picture (0 = same, 64 = nothing alike), or null when
 * it cannot be compared (no picture of ours, no cover, download failed). TikTok's cover is a frame of the post; a creator
 * can pick another frame of OUR clip, which stays close; a different video is far.
 */
function ready_clip_picture_distance(array $clip, string $coverUrl): ?int
{
    try {
        if ($coverUrl === '' || !preg_match('~^https://~i', $coverUrl)) {
            return null;
        }
        $ours = ready_clips_dir() . '/' . pathinfo(basename((string)$clip['file_name']), PATHINFO_FILENAME) . '.jpg';
        if (!is_file($ours)) {
            return null;
        }
        $ch = curl_init($coverUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 6, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_USERAGENT => 'Mozilla/5.0 HPL clip check']);
        $img = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($img) || $code !== 200 || strlen($img) > 5000000) {
            return null;
        }
        $a = ready_picture_hash($img);
        $b = ready_picture_hash((string)file_get_contents($ours));
        if ($a === null || $b === null) {
            return null;
        }
        $d = 0;
        for ($i = 0; $i < 64; $i++) {
            $d += $a[$i] !== $b[$i] ? 1 : 0;
        }
        return $d;
    } catch (Throwable $e) {
        return null;
    }
}

/*
 * Fast downloads (2026-11-10, launch with hundreds of creators).
 * Sending a video through PHP keeps one PHP process busy for the whole download; on a slow phone
 * that is minutes, and the hosting only allows a few dozen at once. So each clip also gets a
 * hard-to-guess file name in uploads/rc (a link to the same file, no second copy), and after the
 * usual permission checks the browser is sent there: the web server delivers the file itself.
 * If the link cannot be made, the old PHP way is used.
 */
function ready_clips_public_dir(): string
{
    $dir = UPLOAD_PATH . '/rc';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    if (!is_file($dir . '/index.html') || filesize($dir . '/index.html') === 0) {
        @file_put_contents($dir . '/index.html', '<!doctype html><title>HPL</title>');
    }
    $ht = $dir . '/.htaccess';
    if (!is_file($ht)) {
        // "?dl=1" saves the file instead of playing it; clips never change, so phones may keep them.
        @file_put_contents($ht, "Options -Indexes\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteCond %{QUERY_STRING} (^|&)dl=1\nRewriteRule .* - [E=HPLDL:1]\n</IfModule>\n"
            . "<IfModule mod_headers.c>\nHeader set Content-Disposition attachment env=HPLDL\nHeader set X-Robots-Tag noindex\nHeader set Cache-Control \"private, max-age=604800\"\n</IfModule>\n");
    }
    return $dir;
}

/** Make $dst point at the same file as $src (hard link, else symlink). Hosts may switch these functions off. */
function ready_link_file(string $src, string $dst): bool
{
    try {
        if (function_exists('link') && @link($src, $dst)) {
            return true;
        }
        return function_exists('symlink') && @symlink($src, $dst);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Does the web server deliver files from uploads/rc? Asked once (and from Admin > Ready clips):
 * the site fetches its own uploads/rc/index.html. The answer is saved in the setting ready_fast_files
 * (1 = fast files, 0 = through PHP as before). Any doubt means 0, the way that always works.
 */
function ready_fast_files_check(): bool
{
    $ok = false;
    try {
        ready_clips_public_dir();
        set_setting('ready_fast_files', '0'); // while the check runs, other requests use the safe way
        $ch = curl_init(abs_url('uploads/rc/index.html'));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOBODY => false]);
        curl_exec($ch);
        $ok = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        $probe = ready_clips_public_dir() . '/.probe';
        $ok = $ok && @file_put_contents($probe . '.src', '1') !== false && ready_link_file($probe . '.src', $probe . '.lnk');
        @unlink($probe . '.lnk');
        @unlink($probe . '.src');
        set_setting('ready_fast_files', $ok ? '1' : '0');
    } catch (Throwable $e) {
    }
    return $ok;
}

/** Web path (inside uploads/rc) of the public link for a stored clip file or its picture, or null. */
function ready_clip_public(string $fileName): ?string
{
    $on = (string)setting('ready_fast_files', '');
    if ($on === '') {
        $on = ready_fast_files_check() ? '1' : '0';
    }
    if ($on !== '1') {
        return null;
    }
    $fileName = basename($fileName);
    $src = ready_clips_dir() . '/' . $fileName;
    if (!is_file($src)) {
        return null;
    }
    $salt = (string)setting('rc_link_salt', '');
    if ($salt === '') {
        $salt = bin2hex(random_bytes(16));
        try { set_setting('rc_link_salt', $salt); } catch (Throwable $e) { return null; }
    }
    $name = substr(hash('sha256', $salt . '|' . $fileName), 0, 40) . '.' . strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $dst = ready_clips_public_dir() . '/' . $name;
    clearstatcache(true, $dst);
    if (!is_file($dst)) {
        if (is_link($dst)) {
            @unlink($dst); // link to a file that is gone
        }
        if (!ready_link_file($src, $dst)) {
            return null;
        }
    }
    return 'uploads/rc/' . $name;
}

/** Delete a stored clip, its picture and their public links. */
function ready_clip_delete_files(string $fileName): void
{
    $fileName = basename($fileName);
    if ($fileName === '') {
        return;
    }
    $salt = (string)setting('rc_link_salt', '');
    foreach ([$fileName, pathinfo($fileName, PATHINFO_FILENAME) . '.jpg'] as $f) {
        if ($salt !== '') {
            @unlink(UPLOAD_PATH . '/rc/' . substr(hash('sha256', $salt . '|' . $f), 0, 40) . '.' . strtolower(pathinfo($f, PATHINFO_EXTENSION)));
        }
        @unlink(ready_clips_dir() . '/' . $f);
    }
}

/** Send a video file to the browser, with byte ranges so phones can preview and seek. */
function ready_clip_send(string $path, bool $download, string $name): void
{
    $size = filesize($path);
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm', 'm4v' => 'video/mp4'][$ext] ?? 'application/octet-stream';
    while (ob_get_level()) {
        ob_end_clean();
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // a long download must not block the creator's other pages
    }
    @set_time_limit(0);
    $start = 0;
    $end = $size - 1;
    if (!$download && isset($_SERVER['HTTP_RANGE']) && preg_match('~bytes=(\d*)-(\d*)~', (string)$_SERVER['HTTP_RANGE'], $m)) {
        if ($m[1] === '' && $m[2] !== '') {
            $start = max(0, $size - (int)$m[2]);
        } else {
            $start = (int)$m[1];
            if ($m[2] !== '') {
                $end = min($end, (int)$m[2]);
            }
        }
        if ($start > $end || $start >= $size) {
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $size);
            exit;
        }
        header('HTTP/1.1 206 Partial Content');
        header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    }
    header('Content-Type: ' . ($download ? 'application/octet-stream' : $type));
    header('Accept-Ranges: bytes');
    header('Content-Length: ' . ($end - $start + 1));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $name . '"');
    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh) && !connection_aborted()) {
        $chunk = fread($fh, (int)min(1048576, $left));
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        flush();
        $left -= strlen($chunk);
    }
    fclose($fh);
    exit;
}

/*
 * TikTok rules for ready clips (owner decision 2026-11-05)
 * --------------------------------------------------------
 * Ready clips are posted on TikTok only, by creators who:
 *  1. connected their TikTok account on HPL (so we can read views, bio and captions);
 *  2. show the tag @hpldetectors.zambia in their TikTok bio (2026-11-10: the tag itself, not just
 *     the word HPL or the WhatsApp number), checked when taking a clip AND when sending its link,
 *     and again about once a day afterwards (ready_bio_watch);
 *  3. tag @hpldetectors.zambia (or name HPL Gold Detectors) in the caption of EVERY ready clip.
 * Without 1 and 2 they cannot take clips; a link whose caption misses the tag is not accepted.
 */
/** The sound rule (owner, 2026-11-11): the clip is posted as we made it, nothing played over the voices. */
function ready_sound_rule(): string
{
    // Owner, 2026-11-12: the clip is posted exactly as it was given (a creator cut a clip, added a filter and music).
    return 'Post the clip exactly as you got it: do not cut it, do not add a filter, and do not add music or any other sound over it. Upload it in the highest quality. Write your own caption and tag HPL in it and in your bio: ' . ready_tag_how() . '.';
}

function ready_tag_handle(): string
{
    return ltrim(strtolower(trim((string)setting('ready_tag_handle', 'hpldetectors.zambia'))), '@');
}

function ready_whatsapp(): string
{
    return trim((string)setting('ready_whatsapp', '0966499575'));
}

/**
 * The HPL tag must be a REAL tag, one people can tap (owner, 2026-11-13 evening: "mentioning is completely different to tagging";
 * many bios showed HPL as text that cannot be tapped, because creators copied and pasted it). Checked on live data the same night:
 * a tag tapped from TikTok's @ list reaches us as "@" + our page's NAME ("@HPL Gold Detectors Zambia"); text typed or pasted stays
 * the handle ("@hpldetectors.zambia") and is not a link. So the page name is the tag; the handle as text is only a mention.
 * Since setting tag_tap_from (+ tag_tap_grace_hours, 48) the plain-text handle no longer counts in bios, nor in captions of clips
 * taken after that time. We never hand out text to copy any more (TikTok may treat pasted captions as a bot).
 */
function ready_tag_name(): string
{
    foreach (preg_split('/\R/', (string)setting('ready_tag_names', 'HPL Gold Detectors Zambia')) as $n) {
        if (trim($n) !== '') return trim($n);
    }
    return 'HPL Gold Detectors Zambia';
}

/** How to tag, in plain words (used everywhere we ask for the tag). */
function ready_tag_how(): string
{
    return 'type @hpl and tap "' . ready_tag_name() . '" in the list that opens, so it turns into a link';
}

/** Is there a REAL (tapped) HPL tag in this text? */
function ready_tag_tapped(string $text): bool
{
    $b = ready_bio_clean($text);
    // Our page's name was renamed and mistyped over time ("@HPL Gold Detector Zambia", "@HPL Gold Dedetor Zambia", seen live
    // 2026-11-13; the owner confirmed these are real tags of our page). "@HPL Gold <one word>" is our page.
    if (preg_match('/@\s?hpl\s+gold\s+\p{L}+/iu', $b)) {
        return true;
    }
    foreach (preg_split('/\R/', (string)setting('ready_tag_names', 'HPL Gold Detectors Zambia')) as $name) {
        $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if ($words && preg_match('/@\s?' . implode('\s+', array_map(fn($w) => preg_quote($w, '/'), $words)) . '(?![\w])/iu', $b)) {
            return true;
        }
    }
    return false;
}

/** Is our handle there only as TYPED text (not a link)? */
function ready_tag_typed(string $text): bool
{
    return !ready_tag_tapped($text) && stripos(ready_bio_clean($text), ready_tag_handle()) !== false;
}

/** From when the typed handle stops counting (0 = not yet switched on). */
function ready_tag_strict_at(): int
{
    $from = (int)setting('tag_tap_from', '0');
    return $from > 0 ? $from + max(0, (int)setting('tag_tap_grace_hours', '48')) * 3600 : 0;
}

/**
 * Does this bio carry the HPL tag? It must be a real tag that people can tap (see ready_tag_tapped()).
 * Until ready_tag_strict_at() the old typed handle still passes, so nobody is blocked before they were told.
 */
function ready_bio_ok(string $bio): bool
{
    if (ready_tag_tapped($bio)) {
        return true;
    }
    $strict = ready_tag_strict_at();
    return ready_tag_typed($bio) && ($strict === 0 || time() < $strict);
}

/** The bio as plain text: same letters whatever keyboard typed them, no invisible characters. */
function ready_bio_clean(string $bio): string
{
    if (class_exists('Normalizer')) {
        $n = Normalizer::normalize($bio, Normalizer::FORM_KC);
        if (is_string($n)) {
            $bio = $n;
        }
    }
    $bio = preg_replace('/[\x{200B}-\x{200F}\x{2060}-\x{2064}\x{FEFF}\x{00AD}]/u', '', $bio) ?? $bio;
    return str_replace(["\u{FF20}", "\u{FE6B}"], '@', $bio);
}

/** Why a bio does not pass (or will soon stop passing), in plain words for the creator ('' when it is a real tag). */
function ready_bio_hint(?string $bio): string
{
    if ($bio === null) {
        return '';
    }
    $b = trim(ready_bio_clean($bio));
    if (ready_tag_tapped($b)) {
        return '';
    }
    $how = 'Edit profile > Bio: type @hpl, tap ' . ready_tag_name() . ', Save.';
    if ($b === '') {
        return 'Your bio is empty. ' . $how;
    }
    if (ready_tag_typed($b)) {
        $strict = ready_tag_strict_at();
        return 'Your HPL tag is text, not a link. Delete it, type @hpl, tap ' . ready_tag_name() . ', Save.'
            . ($strict > time() ? ' By ' . date('D j M, H:i', $strict) . '.' : '');
    }
    return 'No HPL tag in your bio. ' . $how;
}

/** What is wrong with the tag in a caption, in plain words ('' when fine). */
function ready_caption_hint(string $caption): string
{
    if (ready_tag_tapped($caption)) {
        return '';
    }
    if (ready_tag_typed($caption)) {
        return 'Your caption has HPL as text, not a link. Edit the post: delete it, type @hpl, tap ' . ready_tag_name() . '.';
    }
    return 'No HPL tag in your caption. Edit the post: type @hpl, tap ' . ready_tag_name() . '.';
}

/** Days a creator has to put the tag back after the warning (owner decision 2026-11-10; setting ready_bio_grace_days, default 2). */
function ready_bio_grace_days(): int
{
    return max(1, (int)setting('ready_bio_grace_days', '2'));
}

/** Days without clips when the tag is still missing after the warning (setting ready_bio_ban_days, default 3; 0 = never pause). */
function ready_bio_ban_days(): int
{
    return max(0, (int)setting('ready_bio_ban_days', '3'));
}

/**
 * Is this creator paused from taking and sending clips right now? Returns the unix time it ends (0 = not paused).
 * Their money, withdrawals and old clips are not touched: this only stops new clips for a few days.
 */
function creator_clip_ban(array $user): int
{
    $t = !empty($user['clip_ban_until']) ? strtotime((string)$user['clip_ban_until']) : 0;
    return $t > time() ? $t : 0;
}

/** What a paused creator is told. */
function creator_clip_ban_text(array $user): string
{
    $t = creator_clip_ban($user);
    return trim('You cannot take or send clips until ' . date('D j M, H:i', $t) . '. ' . trim((string)($user['clip_ban_reason'] ?? '')));
}

/**
 * The team pauses a creator from taking and sending clips (owner, 2026-11-12: a clip was cut, filtered and given music after a
 * warning). Their money, withdrawals and clips already sent are not touched. The creator is told why and what to do.
 */
function creator_clip_pause(int $uid, int $hours, string $reason): string
{
    $hours = max(1, min(720, $hours));
    $until = date('Y-m-d H:i:s', time() + $hours * 3600);
    $reason = trim(preg_replace('~\s+~', ' ', $reason));
    $was = creator_clip_ban(fetch_one('SELECT clip_ban_until FROM users WHERE id = ?', [$uid]) ?: []);
    q('UPDATE users SET clip_ban_until = ?, clip_ban_reason = ? WHERE id = ? AND role = "creator"', [$until, mb_substr($reason, 0, 480), $uid]);
    $len = ($hours % 24 === 0 && $hours >= 48) ? ($hours / 24) . ' days' : $hours . ' hour' . ($hours === 1 ? '' : 's');
    notif_add($uid, 'warning', $was ? 'Your clip pause is now ' . $len : 'You cannot take or send clips for ' . $len,
        mb_substr($reason, 0, 150) . ' You can clip again on ' . date('D j M, H:i', strtotime($until)) . '. Next time post the clip exactly as you got it. Your money is not affected.', 'creator/index.php?tab=campaigns');
    return $until;
}

/** The pause notice with a live countdown (data-countdown is ticked by home.js, or the small script on the campaign page). */
function creator_clip_ban_html(array $user): string
{
    $t = creator_clip_ban($user);
    if (!$t) return '';
    $left = $t - time();
    return '<strong>You are paused from clips.</strong> ' . e(trim((string)($user['clip_ban_reason'] ?? '')))
        . ' You can take and send clips again in <b data-countdown="' . $t . '">' . e(intdiv(max(0, $left), 3600) . 'h ' . intdiv(max(0, $left) % 3600, 60) . 'm') . '</b>'
        . ' (' . e(date('D j M, H:i', $t)) . ').';
}

/** The short "fix your bio" text shown wherever the bio tag is missing. */
function ready_bio_fix_text(): string
{
    return 'Tag HPL in your TikTok bio: Profile > Edit profile > Bio, ' . ready_tag_how() . '. People who open your page can then tap through to HPL.';
}

/**
 * Keep the tag in the bio AFTER the link is sent (owner decision 2026-11-10).
 * Run by the background sync. Accounts with a ready clip sent in the last 30 days have their bio
 * read again about once a day (a few accounts per run, so TikTok is never flooded). When the tag is
 * gone the creator gets a warning; if it is still gone after ready_bio_grace_days (2) they cannot take or
 * send clips for ready_bio_ban_days (3). Money already earned is never touched. Listed in Admin > Ready clips.
 */
function ready_bio_watch(int $max = 12): array
{
    $done = ['checked' => 0, 'missing' => 0];
    if (!function_exists('tiktok_configured')) {
        require_once __DIR__ . '/tiktok.php';
    }
    if (!tiktok_configured() || !hpl_column_exists('tiktok_accounts', 'bio_missing_since')) {
        return $done;
    }
    try {
        $rows = fetch_all("SELECT DISTINCT a.* FROM tiktok_accounts a
              JOIN campaign_submissions s ON s.user_id = a.user_id AND s.ready_clip_id IS NOT NULL
                   AND s.platform = 'tiktok' AND s.submitted_at >= NOW() - INTERVAL 30 DAY AND s.status <> 'rejected'
             WHERE (a.bio_checked_at IS NULL OR a.bio_checked_at < NOW() - INTERVAL 20 HOUR)
               AND (a.bio_missing_since IS NULL OR a.bio_missing_since <= NOW())
             ORDER BY a.bio_checked_at IS NOT NULL, a.bio_checked_at ASC LIMIT " . max(1, min(40, $max)));
        foreach ($rows as $acc) {
            if (tiktok_account_problem($acc) !== null) {
                q('UPDATE tiktok_accounts SET bio_checked_at = NOW() WHERE id = ?', [(int)$acc['id']]);
                continue;
            }
            $live = tiktok_user_profile($acc);
            q('UPDATE tiktok_accounts SET bio_checked_at = NOW() WHERE id = ?', [(int)$acc['id']]);
            if ($live === null || $live['bio'] === null) {
                continue; // TikTok did not answer: never count that against the creator
            }
            $done['checked']++;
            q('UPDATE tiktok_accounts SET bio = ? WHERE id = ?', [$live['bio'], (int)$acc['id']]);
            if (ready_bio_ok((string)$live['bio'])) {
                if (!empty($acc['bio_missing_since'])) {
                    q('UPDATE tiktok_accounts SET bio_missing_since = NULL, bio_warned_at = NULL WHERE id = ?', [(int)$acc['id']]);
                }
                continue;
            }
            $done['missing']++;
            $grace = ready_bio_grace_days();
            $banDays = ready_bio_ban_days();
            $since = !empty($acc['bio_missing_since']) ? strtotime((string)$acc['bio_missing_since']) : 0;
            if (!$since) {
                // First time we see it gone: a warning, with the date by which it must be back.
                q('UPDATE tiktok_accounts SET bio_missing_since = NOW(), bio_warned_at = NOW() WHERE id = ?', [(int)$acc['id']]);
                notif_add((int)$acc['user_id'], 'warning', 'Warning: tag HPL in your TikTok bio',
                    'Your bio of @' . $acc['username'] . ' has no HPL tag that people can tap. ' . ucfirst(ready_tag_how()) . '. Do it within ' . $grace . ' day' . ($grace === 1 ? '' : 's')
                    . ($banDays > 0 ? ', or you will not be able to take or send clips for ' . $banDays . ' days.' : '.'),
                    'creator/tiktok_ready.php?recheck=1');
            } elseif ($banDays > 0 && $since <= time() - $grace * 86400) {
                // Warned, time is up, still missing: no clips for a few days. The count starts again after that.
                $until = date('Y-m-d H:i:s', time() + $banDays * 86400);
                q('UPDATE users SET clip_ban_until = ?, clip_ban_reason = ? WHERE id = ? AND (clip_ban_until IS NULL OR clip_ban_until < NOW())',
                    [$until, 'Your TikTok bio had no HPL tag that people can tap, and it was not fixed after our warning. Tag HPL in your bio now (' . ready_tag_how() . '), so you are ready when the pause ends.', (int)$acc['user_id']]);
                q('UPDATE tiktok_accounts SET bio_missing_since = ?, bio_warned_at = NOW() WHERE id = ?', [$until, (int)$acc['id']]);
                notif_add((int)$acc['user_id'], 'warning', 'You cannot take or send clips for ' . $banDays . ' days',
                    'Your TikTok bio still had no HPL tag that people can tap after our warning. Tag HPL in your bio now: ' . ready_tag_how() . '. You can clip again on ' . date('D j M, H:i', strtotime($until)) . '.',
                    'creator/tiktok_ready.php?recheck=1');
                $done['banned'] = ($done['banned'] ?? 0) + 1;
                try { notif_admins('warning', 'Creator paused for ' . $banDays . ' days (bio tag removed)', '@' . $acc['username'] . ' did not put the HPL tag back after the warning.', 'admin/ready_clips.php'); } catch (Throwable $e) {}
            }
        }
    } catch (Throwable $e) {
        error_log('ready_bio_watch: ' . $e->getMessage());
    }
    return $done;
}

/** Does this caption tag HPL Gold Detectors? */
function ready_caption_ok(string $caption, int $takenAt = 0): bool
{
    // A real (tapped) tag always passes. The typed handle still passes for clips taken before tag_tap_from (those creators
    // were given a caption to paste); for anything newer it passes only until ready_tag_strict_at().
    if (ready_tag_tapped($caption)) {
        return true;
    }
    if (!ready_tag_typed($caption)) {
        return false;
    }
    $from = (int)setting('tag_tap_from', '0');
    if ($from === 0) {
        return true;
    }
    return $takenAt > 0 ? $takenAt < $from : time() < ready_tag_strict_at();
}

/**
 * Where a creator stands with the ready-clip rules.
 * ['acc' => working TikTok account or null, 'bio' => text|null, 'bio_ok' => bool, 'reason' => what to fix or null]
 * The bio is read from TikTok at most every 30 minutes, unless $fresh.
 */
function ready_tiktok_status(int $userId, bool $fresh = false, ?string $handle = null): array
{
    $out = ['acc' => null, 'bio' => null, 'bio_ok' => false, 'reason' => null];
    if (!function_exists('tiktok_configured') || !tiktok_configured()) {
        $out['reason'] = 'TikTok is not switched on yet. Ask the HPL team.';
        return $out;
    }
    $acc = null;
    foreach (tiktok_accounts_for_user($userId) as $a) {
        if (tiktok_account_problem($a) !== null || (string)($a['username'] ?? '') === '') {
            continue;
        }
        if ($handle === null || strtolower((string)$a['username']) === strtolower($handle)) {
            $acc = $a;
            break;
        }
    }
    if (!$acc) {
        $out['reason'] = 'connect';
        return $out;
    }
    $bio = $acc['bio'] ?? null;
    // Plain page views re-read the bio at most every 30 minutes and wait 4 seconds at most, so hundreds
    // of creators opening a campaign never queue up behind TikTok. Taking a clip and sending a link
    // ($fresh) always read it live.
    $old = empty($acc['bio_checked_at']) || strtotime((string)$acc['bio_checked_at']) < time() - 1800;
    if ($fresh || $old || $bio === null) {
        if (!$fresh) {
            $GLOBALS['hpl_tt_timeout'] = 4;
            // Mark it checked first: if TikTok is slow, the next page views use the saved bio instead of asking again.
            try { q('UPDATE tiktok_accounts SET bio_checked_at = NOW() WHERE id = ?', [(int)$acc['id']]); } catch (Throwable $e) {}
        }
        // One TikTok call reads the bio and the account's numbers (followers, following, videos).
        $live = function_exists('tiktok_user_profile') ? tiktok_user_profile($acc) : null;
        if ($live !== null) {
            if ($live['bio'] !== null) {
                $bio = $live['bio'];
            }
            $set = ['bio_checked_at = NOW()'];
            $args = [];
            if ($live['bio'] !== null) { $set[] = 'bio = ?'; $args[] = $bio; }
            foreach (['follower_count', 'following_count', 'video_count', 'likes_count'] as $k) {
                if ($live[$k] !== null) { $set[] = $k . ' = ?'; $args[] = $live[$k]; $acc[$k] = $live[$k]; }
            }
            $args[] = (int)$acc['id'];
            try { q('UPDATE tiktok_accounts SET ' . implode(', ', $set) . ' WHERE id = ?', $args); } catch (Throwable $e) {}
        }
        unset($GLOBALS['hpl_tt_timeout']);
        if ($fresh && ($live === null || $live['bio'] === null) && $bio !== null && !ready_bio_ok((string)$bio)) {
            // TikTok did not answer just now. The saved bio is from before, maybe before the creator added
            // the tag, so it must not be used to refuse them.
            $out['acc'] = $acc;
            $out['bio'] = $bio;
            $out['reason'] = 'We could not check your TikTok bio just now. Wait a minute and try again.';
            return $out;
        }
    }
    $out['acc'] = $acc;
    $out['bio'] = $bio;
    if ($bio === null) {
        $sc = (string)($acc['scope'] ?? '');
        $out['reason'] = ($sc !== '' && strpos($sc, 'user.info.profile') === false)
            ? 'We cannot read your TikTok bio. Connect TikTok again on your Home page and allow "profile" access.'
            : 'We could not read your TikTok bio right now. Try again in a few minutes.';
        return $out;
    }
    $out['bio_ok'] = ready_bio_ok((string)$bio);
    if (!$out['bio_ok']) {
        $out['reason'] = 'bio';
    }
    return $out;
}

/*
 * "TikTok ready" (owner decision 2026-11-07)
 * ------------------------------------------
 * Ready clips are finished for the creator, so the only work left is posting them on a REAL,
 * ACTIVE TikTok account. TikTok shows few people the videos of new, silent or bot-like accounts,
 * so before the first ready clip the account must:
 *   - be connected and show @hpldetectors.zambia (or HPL) in its bio (rules above);
 *   - follow some accounts (ready_min_following, default 10): a sign the person uses TikTok;
 *   - be warmed up: connected on HPL for ready_warmup_days (default 3) while the creator watches,
 *     likes and comments every day. Accounts already in daily use (follow 50+ accounts) or with an
 *     audience (10+ videos and 100+ followers) skip the wait (2026-11-07).
 * Numbers TikTok does not share with us (permission not given) never block anyone.
 * The follow and warm-up rules apply only to TAKING a clip. The bio tag is also checked when the link is sent.
 * Creators who edit the raw footage themselves do not need them.
 */
function ready_warmup_days(): int
{
    return max(0, (int)setting('ready_warmup_days', '3'));
}

function ready_min_following(): int
{
    return max(0, (int)setting('ready_min_following', '10'));
}

/**
 * Accounts TikTok already sees as a real, active person skip the warm-up wait:
 *  - a personal account used every day to watch videos follows many accounts
 *    (ready_active_following, default 50); TikTok does not tell us watch time or account age,
 *    so the number of accounts followed is the best sign we get;
 *  - or an account that already posts and has an audience (10+ videos and 100+ followers).
 * Only new or empty accounts have to warm up.
 */
function ready_account_established(array $acc): bool
{
    $following = $acc['following_count'] ?? null;
    $active = (int)setting('ready_active_following', '50');
    if ($following !== null && $active > 0 && (int)$following >= $active) {
        return true;
    }
    $v = $acc['video_count'] ?? null;
    $f = $acc['follower_count'] ?? null;
    return $v !== null && $f !== null
        && (int)$v >= (int)setting('ready_established_videos', '10')
        && (int)$f >= (int)setting('ready_established_followers', '100');
}

/** When the warm-up ends for this account (unix time; 0 = done). */
function ready_warmup_until(array $acc): int
{
    $days = ready_warmup_days();
    if ($days <= 0 || ready_account_established($acc) || empty($acc['connected_at'])) {
        return 0;
    }
    // First clip the same day (owner, 2026-11-12, ad visitors left when told to wait 3 days): the warm-up only
    // applies from the second ready clip.
    if (!empty($acc['user_id']) && ready_clips_ever((int)$acc['user_id']) === 0) {
        return 0;
    }
    $end = strtotime((string)$acc['connected_at']) + $days * 86400;
    return $end > time() ? $end : 0;
}

/** Ready clips this creator ever took or sent a link for (0 = their first clip is still to come). */
function ready_clips_ever(int $userId): int
{
    try {
        return (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ? AND ready_clip_id IS NOT NULL', [$userId], 0)
            + (int)fetch_val('SELECT COUNT(*) FROM ready_clips WHERE claimed_by = ? AND submission_id IS NULL', [$userId], 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/** "2 days 4h", "5h 10m", "20 min" for a wait in seconds. */
function ready_long_wait_text(int $seconds): string
{
    $seconds = max(60, $seconds);
    $d = intdiv($seconds, 86400);
    $h = intdiv($seconds % 86400, 3600);
    if ($d > 0) {
        return $d . ' day' . ($d === 1 ? '' : 's') . ($h ? ' ' . $h . 'h' : '');
    }
    return ready_clip_wait_text($seconds);
}

/**
 * The creator's "TikTok ready" checklist for ready clips.
 * $tt is ready_tiktok_status(). Returns ['ok' => bool, 'items' => [key => [done, title, text, href, btn]],
 * 'next' => first unfinished key or null, 'warm_until' => int].
 * Every item is one short action, so the page can show just the next one.
 */
function ready_account_check(array $user, array $tt): array
{
    $acc = $tt['acc'] ?? null;
    // First clip skips the follow rule too (owner, 2026-11-13: 12 people connected TikTok and never took a clip).
    $minFollow = ready_clips_ever((int)$user['id']) === 0 ? 0 : ready_min_following();
    $following = $acc['following_count'] ?? null;
    $warmUntil = $acc ? ready_warmup_until($acc) : 0;
    $tag = '@' . ready_tag_handle();
    $items = [
        'test' => [
            'done' => creator_can_post_ready($user),
            'title' => 'Pass the short test',
            'text' => '5 short questions.',
            'href' => 'creator/assessment.php', 'btn' => 'Take the test',
        ],
        'connect' => [
            'done' => $acc !== null,
            'title' => 'Connect your TikTok',
            'text' => 'Sign in with TikTok.',
            'href' => 'creator/connect_tiktok.php', 'btn' => 'Connect TikTok',
        ],
        'bio' => [
            'done' => $acc !== null && !empty($tt['bio_ok']),
            'title' => 'Tag HPL in your bio',
            'text' => 'Type @hpl, tap ' . ready_tag_name() . '. Never paste it.',
            'href' => 'creator/tiktok_ready.php?recheck=1', 'btn' => 'I added it, check',
        ],
        'follow' => [
            'done' => $acc !== null && ($minFollow <= 0 || $following === null || (int)$following >= $minFollow),
            'title' => 'Follow ' . $minFollow . ' accounts',
            'text' => $following !== null && $acc !== null
                ? 'You follow ' . (int)$following . '. Follow gold and Zambia pages.'
                : 'Follow gold and Zambia pages.',
            'href' => 'creator/tiktok_ready.php?recheck=1', 'btn' => 'I followed, check',
        ],
        'warm' => [
            'done' => $acc !== null && $warmUntil === 0,
            'title' => 'Warm up your account',
            'text' => $acc !== null && $warmUntil > 0
                ? 'Watch, like, comment daily. Ready in ' . ready_long_wait_text($warmUntil - time()) . '.'
                : 'Watch, like, comment for ' . ready_warmup_days() . ' days.',
            'href' => 'creator/tiktok_ready.php', 'btn' => 'How to warm up',
        ],
    ];
    if (ready_warmup_days() <= 0) {
        unset($items['warm']);
    }
    if ($minFollow <= 0) {
        unset($items['follow']);
    }
    $next = null;
    foreach ($items as $k => $it) {
        if (!$it['done']) { $next = $k; break; }
    }
    return ['ok' => $next === null && ($tt['reason'] ?? null) === null, 'items' => $items, 'next' => $next, 'warm_until' => $warmUntil];
}
