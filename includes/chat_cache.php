<?php
/**
 * Community chat at scale (2026-11-08).
 *
 * Hundreds of people keep the chat open and ask "anything new?" every few seconds. Most of those
 * answers are "no", and most of the rest (who is online, reaction counts, who is typing, removed
 * messages) are the same for everyone. So:
 *   - chat_version(): a tiny file holding the time of the last change (new message, delete,
 *     reaction, edit). A poll that already has this version gets a short "nothing new" answer.
 *   - chat_shared(): one shared snapshot of what everyone sees, rebuilt at most every 2 seconds
 *     by one request (the others read the file), with "who is online" refreshed every 15 seconds.
 * Only the viewer's own reactions are looked up per person, and only when something changed.
 */

function chat_cache_dir(): string
{
    $d = BASE_PATH . '/storage/cache';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
    }
    return $d;
}

/** Can the chat keep its small files? If not, every poll does the full (slower) check. */
function chat_cache_ok(): bool
{
    static $ok = null;
    if ($ok === null) {
        $d = chat_cache_dir();
        $ok = is_dir($d) && is_writable($d);
        if ($ok && !is_file($d . '/chat.ver')) {
            chat_bump();
            $ok = is_file($d . '/chat.ver');
        }
    }
    return $ok;
}

/** Version of the chat: changes whenever messages or reactions change. */
function chat_version(): string
{
    $v = @file_get_contents(chat_cache_dir() . '/chat.ver');
    return $v !== false && $v !== '' ? trim($v) : '0';
}

/** Mark the chat as changed (call after any write to chat messages or their reactions). */
function chat_bump(): void
{
    @file_put_contents(chat_cache_dir() . '/chat.ver', sprintf('%.0f', microtime(true) * 1000) . bin2hex(random_bytes(2)), LOCK_EX);
}

/** The part of a chat poll that is the same for everyone. */
function chat_shared(): array
{
    $dir = chat_cache_dir();
    $file = $dir . '/chat_shared.json';
    $ver = chat_version();
    $raw = @file_get_contents($file);
    $s = $raw !== false ? json_decode($raw, true) : null;
    $now = microtime(true);
    if (is_array($s) && ($s['ver'] ?? '') === $ver && $now - (float)($s['built'] ?? 0) < 2.0) {
        return $s;
    }
    // One request rebuilds; the others keep using the last snapshot meanwhile.
    $lock = @fopen($dir . '/chat_shared.lock', 'c');
    $locked = $lock && flock($lock, LOCK_EX | LOCK_NB);
    if (!$locked && is_array($s)) {
        if ($lock) { fclose($lock); }
        return $s;
    }
    try {
        $fresh = chat_shared_build($ver, is_array($s) ? $s : null);
        if ($locked) {
            $tmp = $file . '.' . getmypid() . '.tmp';
            if (@file_put_contents($tmp, json_encode($fresh)) !== false) {
                @rename($tmp, $file);
            }
        }
        return $fresh;
    } finally {
        if ($locked) { flock($lock, LOCK_UN); }
        if ($lock) { fclose($lock); }
    }
}

function chat_shared_build(string $ver, ?array $old): array
{
    $out = ['ver' => $ver, 'built' => microtime(true), 'at' => [], 'active' => [], 'active_at' => 0, 'typing' => [], 'removed' => [], 'rx' => [], 'ids' => [], 'last' => [], 'prev' => []];

    // Who is online: every 15 seconds is plenty.
    if ($old && time() - (int)($old['active_at'] ?? 0) < 15) {
        $out['active'] = $old['active'];
        $out['active_at'] = (int)$old['active_at'];
        $out['online'] = (int)($old['online'] ?? count($old['active']));
    } else {
        $au = active_users();
        $ppl = community_people(array_column($au, 'id'));
        foreach ($au as $a) {
            $p = $ppl[(int)$a['id']] ?? null;
            $out['active'][] = ['id' => (int)$a['id'], 'name' => $a['full_name'], 'role' => $a['role'],
                                'avatar' => $p['avatar'] ?? '', 'tier' => $p['tier'] ?? 'bronze'];
        }
        $out['active_at'] = time();
        $out['online'] = count($out['active']);
        if ($out['online'] >= 100) {
            try {
                $out['online'] = (int)fetch_val('SELECT COUNT(*) FROM users WHERE status = "active" AND last_seen >= NOW() - INTERVAL ? MINUTE', [presence_window_minutes()], 0);
            } catch (Throwable $e) {
            }
        }
    }

    try {
        $rooms = function_exists('chat_has_rooms') && chat_has_rooms();
        foreach (fetch_all('SELECT t.user_id, u.full_name' . ($rooms ? ', t.channel' : '') . ' FROM community_typing t JOIN users u ON u.id = t.user_id WHERE t.typed_at >= NOW() - INTERVAL 6 SECOND LIMIT 30') as $r) {
            $out['typing'][] = ['id' => (int)$r['user_id'], 'name' => explode(' ', trim((string)$r['full_name']))[0] ?: 'Someone', 'ch' => (string)($r['channel'] ?? 'general')];
        }
        // Newest message of every room, so each browser can show which rooms have something unread.
        if ($rooms && function_exists('chat_room_last')) {
            $rl = chat_room_last();
            $out['last'] = $rl['last'];
            $out['prev'] = $rl['prev'];
            $out['at'] = $rl['at'];
        }
    } catch (Throwable $e) {
    }

    if (community_has_soft_delete()) {
        try {
            $out['removed'] = array_map('intval', array_column(fetch_all('SELECT id FROM community_messages WHERE deleted_at >= NOW() - INTERVAL 15 MINUTE'), 'id'));
        } catch (Throwable $e) {
        }
    }

    // Reaction counts on the latest 200 messages (whose reactions are the viewer's comes per person).
    if (community_ready()) {
        try {
            $ids = array_map('intval', array_column(fetch_all('SELECT id FROM community_messages ORDER BY id DESC LIMIT 200'), 'id'));
            $out['ids'] = $ids ? [min($ids), max($ids)] : [];
            if ($ids) {
                $emoji = community_reactions();
                $in = implode(',', array_fill(0, count($ids), '?'));
                foreach (fetch_all("SELECT target_id, emoji, COUNT(*) n, MIN(id) first_id FROM community_reactions
                                     WHERE kind = 'chat' AND target_id IN ($in) GROUP BY target_id, emoji ORDER BY first_id", $ids) as $r) {
                    if (isset($emoji[$r['emoji']])) {
                        $out['rx'][(int)$r['target_id']][] = ['k' => $r['emoji'], 'e' => $emoji[$r['emoji']], 'n' => (int)$r['n']];
                    }
                }
            }
        } catch (Throwable $e) {
        }
    }
    return $out;
}

/** Reaction counts for the viewer: the shared counts plus which ones are theirs. */
function chat_rx_for(array $shared, int $uid): array
{
    $mine = [];
    if (!empty($shared['ids'])) {
        try {
            foreach (fetch_all("SELECT target_id, emoji FROM community_reactions WHERE kind = 'chat' AND user_id = ? AND target_id BETWEEN ? AND ?",
                [$uid, (int)$shared['ids'][0], (int)$shared['ids'][1]]) as $r) {
                $mine[(int)$r['target_id'] . '|' . $r['emoji']] = true;
            }
        } catch (Throwable $e) {
        }
    }
    $out = [];
    foreach ($shared['rx'] as $id => $list) {
        foreach ($list as $x) {
            $x['mine'] = isset($mine[$id . '|' . $x['k']]);
            $out[$id][] = $x;
        }
    }
    return $out;
}
