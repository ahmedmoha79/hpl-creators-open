<?php
/**
 * Integrity: the website checks clips and accounts on the server and can hold a clip for the team to look at.
 * PUBLIC COPY: the scoring rules (what makes a clip look risky, and the thresholds) are NOT included in this copy,
 * on purpose: a rule people can read is a rule people can get around. integrity_score() below is a stub that always
 * says "no risk"; the real one lives only in the private repository. Everything else in this file is the real code.
 */

const HPL_ER_BENCH = 0.0385; // share of TikTok views that like, comment or share

/** Keep what TikTok said about a clip at this moment. At most one row every 10 minutes per clip. */
function integrity_record(int $subId, array $st): void
{
    try {
        $last = fetch_one('SELECT taken_at, views FROM clip_stats WHERE submission_id = ? ORDER BY id DESC LIMIT 1', [$subId]);
        if ($last && strtotime((string)$last['taken_at']) > time() - 600) {
            return;
        }
        q('INSERT INTO clip_stats (submission_id, views, likes, comments, shares) VALUES (?,?,?,?,?)',
            [$subId, max(0, (int)$st['views']), max(0, (int)($st['likes'] ?? 0)), max(0, (int)($st['comments'] ?? 0)), max(0, (int)($st['shares'] ?? 0))]);
    } catch (Throwable $e) {
        error_log('integrity_record: ' . $e->getMessage());
    }
}

/**
 * The risk score of one clip. Pure: no database, so it can be tested with made-up numbers.
 *
 * @param array $snaps    snapshots oldest first: [['t' => unix time, 'views','likes','comments','shares'], ...]
 * @param ?int  $followers followers of the TikTok account (null = unknown)
 * @param array $ctx      ['shared_device' => bool, 'start_views' => int]
 * @return array ['score' => 0..100, 'level' => ''|'medium'|'high', 'flags' => [[code, points, text], ...], 'facts' => [...]]
 */
function integrity_score(array $snaps, ?int $followers = null, array $ctx = []): array
{
    // PUBLIC COPY: the real rules are private. Same shape of answer, always "no risk".
    return ['score' => 0, 'level' => '', 'flags' => [], 'facts' => []];
}

/** Snapshots of a clip for scoring (at most the latest 300). */
function integrity_snaps(int $subId): array
{
    $rows = fetch_all('SELECT UNIX_TIMESTAMP(taken_at) t, views, likes, comments, shares FROM clip_stats WHERE submission_id = ? ORDER BY id DESC LIMIT 300', [$subId]);
    return array_reverse(array_map(function ($r) { return array_map('intval', $r); }, $rows));
}

/** Is one of this user's phones also used by another creator account? */
function integrity_shared_device(int $userId): bool
{
    try {
        return (bool)fetch_val('SELECT 1 FROM user_devices a JOIN user_devices b ON b.device = a.device AND b.user_id <> a.user_id
                                 JOIN users u ON u.id = b.user_id AND u.role = "creator" WHERE a.user_id = ? LIMIT 1', [$userId], 0);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Score a clip again and act on the result. Called after every read of its views.
 * High risk (and the team has not said "this one is fine"): the clip is held. An approved clip goes
 * back to waiting, so its money is locked but not lost; the creator is told it is being checked.
 */
function integrity_evaluate(int $subId): ?array
{
    try {
        $sub = fetch_one('SELECT s.*, c.title FROM campaign_submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE s.id = ?', [$subId]);
        if (!$sub || $sub['status'] === 'rejected') {
            return null;
        }
        $followers = null;
        if ($sub['platform'] === 'tiktok') {
            $f = fetch_val('SELECT follower_count FROM tiktok_accounts WHERE user_id = ? ORDER BY last_sync_at DESC LIMIT 1', [(int)$sub['user_id']], null);
            $followers = $f !== null ? (int)$f : null;
        }
        $res = integrity_score(integrity_snaps($subId), $followers, ['shared_device' => integrity_shared_device((int)$sub['user_id'])]);
        $was = (string)($sub['risk_level'] ?? '');
        q('UPDATE campaign_submissions SET risk_score = ?, risk_level = ?, risk_flags = ?, risk_at = NOW() WHERE id = ?',
            [$res['score'], $res['level'] !== '' ? $res['level'] : null, json_encode(['flags' => $res['flags'], 'facts' => $res['facts']]), $subId]);
        if ($res['level'] === 'high' && $was !== 'high' && (int)$sub['risk_ok'] === 0) {
            integrity_hold($sub, $res);
        }
        return $res;
    } catch (Throwable $e) {
        error_log('integrity_evaluate: ' . $e->getMessage());
        return null;
    }
}

/** Hold a high-risk clip and tell the team (and, without the reasons, the creator). */
function integrity_hold(array $sub, array $res): void
{
    $subId = (int)$sub['id'];
    $held = false;
    if ((string)setting('risk_auto_hold', '1') === '1') {
        if ($sub['status'] === 'approved') {
            require_once __DIR__ . '/earnings.php';
            $held = campaign_submission_set_status($subId, 'pending');
        } else {
            $held = true;
        }
        if ($held) {
            q('UPDATE campaign_submissions SET held_by_system = 1 WHERE id = ?', [$subId]);
            notif_add((int)$sub['user_id'], 'info', 'We are checking one of your clips',
                'Your ' . $sub['title'] . ' clip is being checked. Nothing to do.',
                'creator/clip.php?id=' . $subId);
        }
    }
    $why = implode('; ', array_slice(array_column($res['flags'], 2), 0, 3));
    $name = (string)fetch_val('SELECT full_name FROM users WHERE id = ?', [(int)$sub['user_id']], 'A creator');
    notif_admins('warning', 'Clip needs a look: risk ' . $res['score'] . '/100', $name . ', ' . $sub['title'] . '. ' . $why, 'admin/risk.php');
    try {
        if (setting('tg_owner_chat_id', '') !== '') {
            require_once __DIR__ . '/telegram.php';
            tg_send(htmlspecialchars("Clip needs a look (risk {$res['score']}/100)\n{$name}, {$sub['title']}\n{$why}"));
        }
    } catch (Throwable $e) {
    }
}

/**
 * TikTok answered, and the video was not there (deleted, private, or not on that account any more).
 * When it has been gone for 2 hours (at least 3 reads) the creator and the team are told once. It earns nothing meanwhile because
 * no new views can be read. When TikTok shows it again, everything goes back to normal by itself.
 */
function integrity_clip_missing(array $sub): void
{
    try {
        $subId = (int)$sub['id'];
        q('UPDATE campaign_submissions SET miss_count = miss_count + 1, missing_since = COALESCE(missing_since, NOW()) WHERE id = ? AND miss_count < 1000', [$subId]);
        // Told once, and only when it has been gone for 2 hours over at least 3 reads: a new post that TikTok
        // is still checking, or three quick taps on Refresh, must not raise a false alarm. 1000 = "already told".
        $n = (int)q('UPDATE campaign_submissions SET miss_count = 1000 WHERE id = ? AND miss_count >= 3 AND miss_count < 1000 AND missing_since <= NOW() - INTERVAL 2 HOUR', [$subId])->rowCount();
        if ($n === 1) {
            $title = (string)fetch_val('SELECT title FROM campaigns WHERE id = ?', [(int)$sub['campaign_id']], 'campaign');
            notif_add((int)$sub['user_id'], 'warning', 'We cannot see your clip on TikTok any more',
                'Your ' . $title . ' clip is deleted or private. It earns again when it is public.',
                'creator/clip.php?id=' . $subId);
            notif_admins('warning', 'A clip disappeared from TikTok', 'A ' . $title . ' clip can no longer be read. See Checks.', 'admin/risk.php?tab=missing');
            if (!empty($sub['ready_clip_id'])) {
                // A deleted ready clip goes back to the free list at once (owner, 2026-11-11).
                require_once __DIR__ . '/ready_clips.php';
                ready_clips_free_deleted($subId);
            }
        }
    } catch (Throwable $e) {
        error_log('integrity_clip_missing: ' . $e->getMessage());
    }
}

/** The clip was read fine: forget any "missing" count. */
function integrity_clip_seen(array $sub): void
{
    if ((int)($sub['miss_count'] ?? 0) > 0 || !empty($sub['missing_since'])) {
        try { q('UPDATE campaign_submissions SET miss_count = 0, missing_since = NULL WHERE id = ?', [(int)$sub['id']]); } catch (Throwable $e) {}
    }
}

/**
 * Remember which phone or computer this account uses (a random id kept in a cookie; no personal data).
 * Called once per sign-in session. Two creator accounts on one phone is the commonest way one person
 * tries to take more clips or more bonuses than allowed.
 */
function integrity_touch_device(int $userId): void
{
    try {
        if ($userId <= 0 || !empty($_SESSION['hpl_dev_ok']) || !empty($_SESSION['demo_admin_id']) || headers_sent()) {
            return;
        }
        require_once __DIR__ . '/demo.php';
        if (is_demo_user_id($userId)) {
            return; // the demo account is used on the team's own phones and computers
        }
        $dev = (string)($_COOKIE['hpl_dev'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $dev)) {
            $dev = bin2hex(random_bytes(16));
        }
        setcookie('hpl_dev', $dev, ['expires' => time() + 2 * 365 * 86400, 'path' => '/', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'httponly' => true, 'samesite' => 'Lax']);
        q('INSERT INTO user_devices (user_id, device, ip, ua) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_seen = NOW(), ip = VALUES(ip), ua = VALUES(ua)',
            [$userId, $dev, mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45), mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160)]);
        $_SESSION['hpl_dev_ok'] = 1;
    } catch (Throwable $e) {
    }
}

/** Other creator accounts seen on the same phones as this user: [['id','full_name','last_seen'], ...]. */
function integrity_linked_accounts(int $userId): array
{
    try {
        return fetch_all('SELECT u.id, u.full_name, MAX(b.last_seen) last_seen FROM user_devices a JOIN user_devices b ON b.device = a.device AND b.user_id <> a.user_id
                           JOIN users u ON u.id = b.user_id AND u.role = "creator" WHERE a.user_id = ? GROUP BY u.id, u.full_name ORDER BY last_seen DESC LIMIT 10', [$userId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Old snapshots are thinned out: after 21 days one per day is plenty. Run by the background sync. */
function integrity_prune(): void
{
    try {
        if ((int)setting('integrity_pruned_at', '0') > time() - 86400) {
            return;
        }
        set_setting('integrity_pruned_at', (string)time());
        q('DELETE a FROM clip_stats a JOIN clip_stats b ON b.submission_id = a.submission_id AND DATE(b.taken_at) = DATE(a.taken_at) AND b.id > a.id
            WHERE a.taken_at < NOW() - INTERVAL 21 DAY');
    } catch (Throwable $e) {
    }
}
