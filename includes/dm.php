<?php
/**
 * Direct messages: data for the messenger screens (creator + admin).
 * Rendering happens in includes/dm_view.php + assets/js/dm.js.
 */

/** One message as the messenger script expects it. */
function dm_message_json(array $m, int $me): array
{
    $bot = (int)($m['is_bot'] ?? 0) === 1;
    $name = $bot ? 'HPL Help' : (string)($m['full_name'] ?? 'Unknown');
    return [
        'id'     => (int)$m['id'],
        'sid'    => (int)($m['sender_id'] ?? 0),
        'mine'   => !$bot && (int)($m['sender_id'] ?? 0) === $me,
        'bot'    => $bot,
        'name'   => $name,
        'team'   => $bot || ($m['role'] ?? '') === 'admin',
        'avatar' => $bot ? '' : (string)($m['avatar_url'] ?? ''),
        'text'   => (string)($m['message'] ?? ''),
        'html'   => $bot, // support answers are written by the team and may contain links
        'src'    => (string)($m['source'] ?? ''),
        'ts'     => strtotime((string)($m['created_at'] ?? '')) ?: time(),
    ];
}

/** Messages of a thread for the messenger (oldest first), with avatars. */
function dm_thread_messages(int $threadId, int $me, int $sinceId = 0, int $limit = 150): array
{
    $limit = max(1, min(200, $limit));
    $rows = $sinceId > 0
        ? fetch_all('SELECT m.*, u.full_name, u.role, u.avatar_url FROM dm_messages m JOIN users u ON u.id = m.sender_id
                     WHERE m.thread_id = ? AND m.id > ? ORDER BY m.id ASC LIMIT ' . $limit, [$threadId, $sinceId])
        : array_reverse(fetch_all('SELECT m.*, u.full_name, u.role, u.avatar_url FROM dm_messages m JOIN users u ON u.id = m.sender_id
                     WHERE m.thread_id = ? ORDER BY m.id DESC LIMIT ' . $limit, [$threadId]));
    return array_map(function ($m) use ($me) { return dm_message_json($m, $me); }, $rows);
}

/** People in a thread (id, name, role, avatar, active). */
function dm_thread_people(int $threadId): array
{
    return fetch_all(
        'SELECT u.id, u.full_name, u.role, u.avatar_url, (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active, p.last_read_at
         FROM dm_participants p JOIN users u ON u.id = p.user_id WHERE p.thread_id = ? ORDER BY u.role = "admin", u.full_name',
        [presence_window_minutes(), $threadId]
    );
}

/**
 * Conversation list.
 *  mine: threads the viewer is in (with unread counts)
 *  all:  every conversation on the site (admin oversight, read-only)
 * Empty conversations are hidden, except $keepId (the one being opened).
 * $humanOnly also hides conversations where nobody but the assistant wrote.
 */
function dm_list(int $me, string $scope = 'mine', int $keepId = 0, bool $humanOnly = false): array
{
    $win = presence_window_minutes();
    // For the team: skip conversations where only the automatic assistant has spoken.
    $has = $humanOnly ? 'EXISTS (SELECT 1 FROM dm_messages m WHERE m.thread_id = t.id AND (m.is_bot = 0 OR m.source = "tt_help"))' : 'EXISTS (SELECT 1 FROM dm_messages m WHERE m.thread_id = t.id)';
    try {
        if ($scope === 'all') {
            $rows = fetch_all(
                'SELECT t.id, t.is_support, t.updated_at, 0 AS unread,
                        (SELECT COUNT(*) FROM dm_messages m WHERE m.thread_id = t.id) AS total
                 FROM dm_threads t
                 WHERE ' . $has . ' OR t.id = ?
                 ORDER BY t.updated_at DESC LIMIT 200',
                [$keepId]
            );
        } else {
            $rows = fetch_all(
                'SELECT t.id, t.is_support, t.updated_at,
                        (SELECT COUNT(*) FROM dm_messages m WHERE m.thread_id = t.id AND m.sender_id <> ? AND m.is_bot = 0
                           AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)) AS unread,
                        (SELECT COUNT(*) FROM dm_messages m WHERE m.thread_id = t.id) AS total
                 FROM dm_threads t JOIN dm_participants p ON p.thread_id = t.id AND p.user_id = ?
                 WHERE ' . $has . ' OR t.id = ?
                 ORDER BY t.updated_at DESC LIMIT 100',
                [$me, $me, $keepId]
            );
        }
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        $tid = (int)$r['id'];
        $last = fetch_one('SELECT m.* FROM dm_messages m WHERE m.thread_id = ? ORDER BY m.id DESC LIMIT 1', [$tid]);
        $people = fetch_all(
            'SELECT u.id, u.full_name, u.role, u.avatar_url, (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active
             FROM dm_participants p JOIN users u ON u.id = p.user_id WHERE p.thread_id = ? ORDER BY u.role = "admin", u.full_name',
            [$win, $tid]
        );
        $others = array_values(array_filter($people, function ($p) use ($me) { return (int)$p['id'] !== $me; }));
        if (!$others) {
            continue;
        }
        if ($scope === 'all') {
            // Show the creator side first; for creator-to-creator chats show both names.
            $creators = array_values(array_filter($people, function ($p) { return $p['role'] !== 'admin'; }));
            $named = ((int)$r['is_support'] === 1 && $creators) ? $creators : $people;
            $title = implode(' & ', array_map(function ($p) { return $p['full_name']; }, $named));
            $lead = $named[0];
        } else {
            $lead = $others[0];
            $title = ((int)$r['is_support'] === 1 && $lead['role'] === 'admin') ? 'HPL team' : $lead['full_name'];
        }
        $preview = '';
        if ($last) {
            $plain = trim(preg_replace('/\s+/', ' ', strip_tags((string)$last['message'])));
            $who = (int)$last['is_bot'] === 1 ? 'Assistant: ' : ((int)$last['sender_id'] === $me ? 'You: ' : '');
            if (($last['source'] ?? '') === 'community') {
                $who = 'Community: ';
            }
            $preview = $who . $plain;
        }
        $out[] = [
            'id'      => $tid,
            'support' => (int)$r['is_support'] === 1,
            'title'   => (string)$title,
            'lead'    => ['id' => (int)$lead['id'], 'name' => (string)$lead['full_name'], 'role' => (string)$lead['role'],
                          'avatar' => (string)($lead['avatar_url'] ?? ''), 'active' => (int)$lead['is_active'] === 1],
            'preview' => mb_substr($preview, 0, 140),
            'ts'      => $last ? (strtotime((string)$last['created_at']) ?: 0) : (strtotime((string)$r['updated_at']) ?: 0),
            'unread'  => (int)$r['unread'],
            'total'   => (int)$r['total'],
            'member'  => $scope !== 'all' || in_array($me, array_map('intval', array_column($people, 'id')), true),
            'community' => (int)fetch_val('SELECT COUNT(*) FROM dm_messages WHERE thread_id = ? AND source = "community" AND created_at >= (NOW() - INTERVAL 14 DAY)', [$tid], 0) > 0,
        ];
    }
    return $out;
}

/** Tell the other people in a thread about a new message (only if they are not online right now). */
function dm_notify_others(int $threadId, int $senderId, string $senderName, string $text, string $role): void
{
    try {
        $win = presence_window_minutes();
        $rows = fetch_all(
            'SELECT u.id, u.role FROM dm_participants p JOIN users u ON u.id = p.user_id
             WHERE p.thread_id = ? AND p.user_id <> ? AND (u.last_seen IS NULL OR u.last_seen < (NOW() - INTERVAL ? MINUTE))
               AND (p.last_read_at IS NULL OR p.last_read_at < (NOW() - INTERVAL 20 SECOND))',
            [$threadId, $senderId, $win]
        );
        foreach ($rows as $r) {
            $url = $r['role'] === 'admin' ? 'admin/messages.php?thread=' . $threadId : 'creator/messages.php?thread=' . $threadId;
            // One unread notification per conversation is enough.
            if ((int)fetch_val('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND url = ? AND is_read = 0', [(int)$r['id'], $url], 0) > 0) {
                continue;
            }
            $title = ($role === 'admin' ? 'The HPL team' : $senderName) . ' sent you a message';
            notif_add((int)$r['id'], 'message', $title, mb_substr(trim($text), 0, 120), $url);
        }
    } catch (Throwable $e) {
        // Notifications are best-effort.
    }
}

/** Compact time for the conversation list: now, 5m, 3h, 2d, 14 Oct. */
function dm_short_ago(int $ts): string
{
    $s = max(0, time() - $ts);
    if ($s < 60) { return 'now'; }
    if ($s < 3600) { return (int)round($s / 60) . 'm'; }
    if ($s < 86400) { return (int)round($s / 3600) . 'h'; }
    if ($s < 7 * 86400) { return (int)round($s / 86400) . 'd'; }
    return date('j M', $ts);
}

/** A display name that is safe to suggest to other people (no links or markup from spam sign-ups). */
function dm_name_ok(string $name): bool
{
    $n = trim($name);
    return $n !== '' && mb_strlen($n) <= 60 && !preg_match('~[<>{}]|https?:|www\.|\.(com|org|net|xyz|ru)\b|\*\s*\*~i', $n);
}

/**
 * People you can start a conversation with.
 * Empty search: people active in the Community lately. With a search: matching names.
 */
function dm_people(int $me, string $q = '', int $limit = 20): array
{
    $q = trim($q);
    try {
        if ($q === '') {
            $rows = fetch_all(
                'SELECT u.id, u.full_name, u.role, u.avatar_url, (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active
                 FROM users u
                 WHERE u.id <> ? AND u.role = "creator" AND u.status = "active"
                 ORDER BY (u.last_seen IS NULL), u.last_seen DESC LIMIT 60',
                [presence_window_minutes(), $me]
            );
        } else {
            $rows = fetch_all(
                'SELECT u.id, u.full_name, u.role, u.avatar_url, (u.last_seen >= (NOW() - INTERVAL ? MINUTE)) AS is_active
                 FROM users u
                 WHERE u.id <> ? AND u.role = "creator" AND u.status = "active" AND u.full_name LIKE ?
                 ORDER BY (u.full_name LIKE ?) DESC, u.full_name LIMIT 60',
                [presence_window_minutes(), $me, '%' . $q . '%', $q . '%']
            );
        }
    } catch (Throwable $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $r) {
        if (!dm_name_ok((string)$r['full_name'])) {
            continue;
        }
        $out[] = ['id' => (int)$r['id'], 'name' => (string)$r['full_name'], 'avatar' => (string)($r['avatar_url'] ?? ''),
                  'active' => (int)$r['is_active'] === 1];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Community posts that need the team go straight to the team inbox:
 * when the creator mentions @team / @admin / @hpl, or asks a question about
 * money, views, their account or a problem. Returns true when it was sent on.
 */
function dm_forward_from_community(int $userId, int $communityId, string $text): bool
{
    $t = mb_strtolower($text);
    $mention = preg_match('/@(team|admin|admins|hpl|support)\b/', $t) === 1;
    $question = strpos($t, '?') !== false
        || preg_match('/^(how|why|when|where|what|who|can|could|is|are|does|do|did|has|have|will|should)\b/', trim($t)) === 1;
    $topic = preg_match('/withdraw|payout|paid|payment|\bpay\b|money|earn|balance|\bviews?\b|account|verif|approv|banned|blocked|problem|issue|error|not working|help|campaign|airtel|mtn|zamtel/', $t) === 1;
    if (!$mention && !($question && $topic)) {
        return false;
    }
    try {
        // Keep it useful: at most 3 forwarded posts per creator per hour.
        $recent = (int)fetch_val(
            'SELECT COUNT(*) FROM dm_messages WHERE sender_id = ? AND source = "community" AND created_at >= (NOW() - INTERVAL 1 HOUR)',
            [$userId], 0
        );
        if ($recent >= 3) {
            return false;
        }
        $tid = support_thread_for($userId);
        if ($tid <= 0) {
            return false;
        }
        q('INSERT INTO dm_messages (thread_id, sender_id, message, source, source_ref) VALUES (?, ?, ?, "community", ?)', [$tid, $userId, trim($text), $communityId]); require_once __DIR__ . '/fastlane.php'; fl_bump('dm');
        q('UPDATE dm_threads SET updated_at = NOW() WHERE id = ?', [$tid]);
        $name = (string)fetch_val('SELECT full_name FROM users WHERE id = ?', [$userId], 'A creator');
        notif_admins('message', $name . ' asked the team in Community', mb_substr(trim($text), 0, 120), 'admin/messages.php?thread=' . $tid);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
