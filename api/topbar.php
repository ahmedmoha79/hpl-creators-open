<?php
/**
 * Top bar JSON API: counts, notification read state and command-palette search.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';

$user = current_user();
if (!$user) {
    json_response(['ok' => false, 'error' => 'Please sign in.'], 401);
}

// Home-screen notifications waiting to go out are sent after this answer (includes/push.php).
try { require_once __DIR__ . '/../includes/push.php'; push_flush_if_pending(); } catch (Throwable $e) {}

$uid     = (int)$user['id'];
$isAdmin = (($user['role'] ?? 'creator') === 'admin');
$action  = get('action', 'counts');
// The conversation open on screen right now: it is read, so it never counts as new.
$openThread = (int)get('open');
if ($openThread > 0 && in_array($action, ['dm', 'counts'], true)
    && fetch_val('SELECT 1 FROM dm_participants WHERE thread_id = ? AND user_id = ?', [$openThread, $uid], null) !== null) {
    dm_mark_read($openThread, $uid);
} else {
    $openThread = 0;
}

if ($action === 'read_all') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST required.'], 405);
    }
    csrf_check();
    notif_mark_all_read($uid);
    json_response(['ok' => true]);
}

if ($action === 'seen') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_response(['ok' => false, 'error' => 'POST required.'], 405);
    }
    csrf_check();
    notif_mark_seen($uid);
    json_response(['ok' => true]);
}

if ($action === 'open') {
    // Open one notification: mark it read, then go where it points (inside this site only).
    $n = notif_open($uid, (int)get('id'));
    $to = $n && !empty($n['url']) ? (string)$n['url'] : ($isAdmin ? 'admin/notifications.php' : 'creator/notifications.php');
    if (preg_match('~^(https?:)?//~i', $to) || strpos($to, '\\') !== false) {
        $to = $isAdmin ? 'admin/notifications.php' : 'creator/notifications.php';
    }
    redirect(ltrim($to, '/'));
}

/** Newest unread direct message for the pop-up and sound (null when none). */
function tb_latest_dm(int $uid, bool $isAdmin): ?array
{
    try {
        $m = fetch_one(
            'SELECT m.id, m.thread_id, m.message, u.full_name, u.role
               FROM dm_messages m
               JOIN dm_participants p ON p.thread_id = m.thread_id AND p.user_id = ?
               JOIN users u ON u.id = m.sender_id
              WHERE m.sender_id <> ? AND m.is_bot = 0 AND (p.last_read_at IS NULL OR m.created_at > p.last_read_at)
              ORDER BY m.id DESC LIMIT 1',
            [$uid, $uid]
        );
    } catch (Throwable $e) {
        return null;
    }
    if (!$m) {
        return null;
    }
    $name = (!$isAdmin && $m['role'] === 'admin') ? 'The HPL team' : (string)$m['full_name'];
    $text = trim(preg_replace('/\s+/', ' ', (string)$m['message']));
    return [
        'id'     => (int)$m['id'],
        'thread' => (int)$m['thread_id'],
        'from'   => mb_substr($name, 0, 60),
        'text'   => mb_strimwidth($text, 0, 120, '…'),
        'url'    => u(($isAdmin ? 'admin' : 'creator') . '/messages.php?thread=' . (int)$m['thread_id']),
    ];
}

/** Newest unread reminder/announcement from the team, for the pop-up (null when none). */
function tb_latest_notice(int $uid): ?array
{
    try {
        $n = fetch_one('SELECT id, type, title, body, url FROM notifications WHERE user_id = ? AND is_read = 0 AND type IN ("reminder","announcement","footage","chat","warning","earning") AND created_at > NOW() - INTERVAL 3 DAY ORDER BY id DESC LIMIT 1', [$uid]);
    } catch (Throwable $e) {
        return null;
    }
    if (!$n) {
        return null;
    }
    return [
        'id'    => (int)$n['id'],
        'title' => mb_strimwidth((string)$n['title'], 0, 90, '…'),
        'text'  => mb_strimwidth((string)$n['body'], 0, 160, '…'),
        'url'   => u('api/topbar.php?action=open&id=' . (int)$n['id']),
        'icon'  => ['chat' => '💬', 'warning' => '⚠️', 'earning' => '💰', 'footage' => '🎬', 'announcement' => '📣', 'reminder' => '⏰'][(string)$n['type']] ?? '🔔',
    ];
}

// Light check for new direct messages, polled every few seconds: one small query, no balance maths.
if ($action === 'dm') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // never hold the session lock while polling
    }
    json_response(['ok' => true, 'unreadMessages' => dm_unread_total($uid, $openThread), 'latestDm' => tb_latest_dm($uid, $isAdmin)]);
}

if ($action === 'counts') {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // read-only: do not block the visitor's other requests
    }
    $balance = $isAdmin ? 0.0 : (float)creator_stats($uid)['balance'];
    json_response([
        'ok'                  => true,
        'unreadMessages'      => dm_unread_total($uid, $openThread),
        'unreadNotifications' => notif_unread_count($uid),
        'balance'             => round($balance, 2),
        'balanceFormatted'    => money($balance),
        'hasNewUpdates'       => true,
        'latestDm'            => tb_latest_dm($uid, $isAdmin),
        'latestNotice'        => $isAdmin ? null : tb_latest_notice($uid),
    ]);
}

if ($action === 'search') {
    $raw = trim(get('q', ''));
    $q   = mb_substr($raw, 0, 80);
    $groups = [];

    if ($q === '') {
        if ($isAdmin) {
            $groups[] = ['label' => 'Quick links', 'items' => [
                ['title' => 'Home', 'sub' => 'What to do now', 'url' => u('admin/index.php'), 'icon' => 'chart'],
                ['title' => 'Send a message', 'sub' => 'Reminders and messages to creators', 'url' => u('admin/send.php'), 'icon' => 'message'],
                ['title' => 'Creators', 'sub' => 'All creators, by step', 'url' => u('admin/creators.php'), 'icon' => 'people'],
                ['title' => 'Campaigns', 'sub' => 'Active and past campaigns', 'url' => u('admin/campaigns.php'), 'icon' => 'campaign'],
                ['title' => 'Courses', 'sub' => 'Training library', 'url' => u('admin/courses.php'), 'icon' => 'course'],
                ['title' => 'Approve new creators', 'sub' => 'Work samples waiting', 'url' => u('admin/portfolio.php'), 'icon' => 'post'],
                ['title' => 'Pay withdrawals', 'sub' => 'Payout requests', 'url' => u('admin/withdrawals.php'), 'icon' => 'wallet'],
                ['title' => 'Messages', 'sub' => 'Creator conversations', 'url' => u('admin/messages.php'), 'icon' => 'message'],
                ['title' => 'Settings', 'sub' => 'Site configuration', 'url' => u('admin/settings.php'), 'icon' => 'help'],
            ]];
        } else {
            $groups[] = ['label' => 'Quick links', 'items' => [
                ['title' => 'Dashboard', 'sub' => 'Home, announcements and chats', 'url' => u('creator/index.php'), 'icon' => 'chart'],
                ['title' => 'Campaigns', 'sub' => 'Browse and join campaigns', 'url' => u('creator/index.php?tab=campaigns'), 'icon' => 'campaign'],
                ['title' => 'Creator Academy', 'sub' => 'Learn the workflow', 'url' => u('creator/course.php'), 'icon' => 'course'],
                ['title' => 'Wallet', 'sub' => 'Balance and payouts', 'url' => u('creator/earnings.php'), 'icon' => 'wallet'],
                ['title' => 'Messages', 'sub' => 'Chat with the team', 'url' => u('creator/messages.php'), 'icon' => 'message'],
                ['title' => 'Support', 'sub' => 'Answers and contact', 'url' => u('creator/support.php'), 'icon' => 'help'],
            ]];
        }
        json_response(['ok' => true, 'query' => '', 'groups' => $groups]);
    }

    $like = '%' . $q . '%';

    // Pages of the site (so "withdraw", "password" or "tiktok" find the right page).
    $pages = $isAdmin ? [
        ['Pay withdrawals', 'Payout requests', 'admin/withdrawals.php', 'wallet', 'withdraw payout pay money mobile'],
        ['Creators', 'All creators, by step', 'admin/creators.php', 'people', 'creator user member people suspend'],
        ['Approve new creators', 'Work samples waiting', 'admin/portfolio.php', 'post', 'approve sample video portfolio qualify'],
        ['Ready clips', 'Upload and manage ready clips', 'admin/ready_clips.php', 'campaign', 'ready clip upload tiktok'],
        ['Campaigns', 'Active and past campaigns', 'admin/campaigns.php', 'campaign', 'campaign clip review'],
        ['Community', 'Moderate the community', 'admin/community.php', 'message', 'community chat moderate'],
        ['Messages', 'Creator conversations', 'admin/messages.php', 'message', 'message chat inbox dm'],
        ['Settings', 'Site configuration', 'admin/settings.php', 'help', 'settings config setup'],
    ] : [
        ['Withdraw', 'Cash out to mobile money', 'creator/withdraw.php', 'wallet', 'withdraw cash out payout money mobile mtn airtel zamtel'],
        ['Wallet', 'Balance and views', 'creator/earnings.php', 'wallet', 'wallet earnings balance views money paid'],
        ['Campaigns', 'Ready clips and footage', 'creator/index.php?tab=campaigns', 'campaign', 'campaign ready clip join post'],
        ['Connect TikTok', 'So your views are counted', 'creator/connect_tiktok.php', 'campaign', 'tiktok connect account verify'],
        ['Get your TikTok ready', 'Bio, following, warm-up', 'creator/tiktok_ready.php', 'campaign', 'tiktok ready bio warm'],
        ['Community', 'Feed, live chat, leaderboard', 'creator/community.php', 'message', 'community chat feed leaderboard members friends'],
        ['Messages', 'Chat with the team and creators', 'creator/messages.php', 'message', 'message chat inbox dm help team'],
        ['HPL test', 'Pass it to start earning', 'creator/assessment.php', 'course', 'test quiz exam assessment pass'],
        ['Creator Academy', 'Free lessons', 'creator/course.php', 'course', 'academy course lesson learn video tutorial'],
        ['My clips', 'Clips you sent', 'creator/submit_links.php', 'post', 'clip link submit my clips'],
        ['Footage to download', 'Raw footage to edit', 'creator/library.php', 'post', 'footage download raw library'],
        ['Rewards', 'Bonuses and codes', 'creator/rewards.php', 'wallet', 'reward bonus code achievement'],
        ['Profile', 'Name, phone, password', 'creator/profile.php', 'help', 'profile name phone password account settings'],
        ['Confirm your email', 'Needed before your first payout', 'creator/verify_email.php', 'help', 'email confirm verify code'],
        ['Notifications', 'Everything that happened', 'creator/notifications.php', 'help', 'notification alert'],
        ['Help & support', 'Answers and contact', 'creator/support.php', 'help', 'help support question contact'],
    ];
    $needle = mb_strtolower($q);
    $items = [];
    foreach ($pages as [$title, $sub, $url, $ico, $words]) {
        $hay = mb_strtolower($title . ' ' . $sub . ' ' . $words);
        $hit = mb_strpos($hay, $needle) !== false;
        if (!$hit) {
            foreach (preg_split('/\s+/', $needle) as $w) {
                if (mb_strlen($w) >= 3 && mb_strpos($hay, mb_substr($w, 0, max(3, mb_strlen($w) - 2))) !== false) { $hit = true; break; }
            }
        }
        if ($hit) {
            $items[] = ['title' => $title, 'sub' => $sub, 'url' => u($url), 'icon' => $ico];
        }
    }
    if ($items) {
        $groups[] = ['label' => 'Pages', 'items' => array_slice($items, 0, 6)];
    }

    // Campaigns
    try {
        $sql = 'SELECT id, title, owner_name, status FROM campaigns WHERE (title LIKE ? OR description LIKE ? OR owner_name LIKE ?)';
        $params = [$like, $like, $like];
        if (!$isAdmin) {
            $sql .= ' AND status = "active"';
        }
        $sql .= ' ORDER BY status ASC, created_at DESC LIMIT 5';
        $rows = fetch_all($sql, $params);
        if ($rows) {
            $items = [];
            foreach ($rows as $r) {
                $bits = [];
                if ($r['owner_name']) $bits[] = $r['owner_name'];
                $bits[] = $r['status'] === 'active' ? 'Active' : 'Ended';
                $items[] = [
                    'title' => $r['title'],
                    'sub'   => implode(' · ', $bits),
                    'url'   => u($isAdmin ? 'admin/campaigns.php' : 'creator/campaign.php?id=' . (int)$r['id']),
                    'icon'  => 'campaign',
                ];
            }
            $groups[] = ['label' => 'Campaigns', 'items' => $items];
        }
    } catch (Throwable $e) {}

    // Courses
    try {
        $sql = 'SELECT id, title, description, published FROM courses WHERE (title LIKE ? OR description LIKE ?)';
        $params = [$like, $like];
        if (!$isAdmin) {
            $sql .= ' AND published = 1';
        }
        $sql .= ' ORDER BY sort_order ASC, created_at DESC LIMIT 5';
        $rows = fetch_all($sql, $params);
        if ($rows) {
            $items = [];
            foreach ($rows as $r) {
                $items[] = [
                    'title' => $r['title'],
                    'sub'   => $isAdmin ? ($r['published'] ? 'Course · published' : 'Course · draft') : 'Free clipping course',
                    'url'   => u($isAdmin ? 'admin/courses.php' : 'creator/course_intro.php?id=' . (int)$r['id']),
                    'icon'  => 'course',
                ];
            }
            $groups[] = ['label' => 'Courses', 'items' => $items];
        }
    } catch (Throwable $e) {}

    // Announcements / posts
    try {
        $rows = fetch_all(
            'SELECT p.id, p.body, p.created_at, u.full_name
               FROM ann_posts p LEFT JOIN users u ON u.id = p.author_id
              WHERE p.body LIKE ? ORDER BY p.created_at DESC LIMIT 5',
            [$like]
        );
        if ($rows) {
            $items = [];
            foreach ($rows as $r) {
                $snippet = trim(preg_replace('/\s+/', ' ', (string)$r['body']));
                $items[] = [
                    'title' => mb_substr($snippet, 0, 70) . (mb_strlen($snippet) > 70 ? '...' : ''),
                    'sub'   => 'Announcement · ' . ($r['full_name'] ?: 'HPL') . ' · ' . time_ago($r['created_at']),
                    'url'   => u($isAdmin ? 'admin/announcements.php' : 'creator/index.php?tab=announcements'),
                    'icon'  => 'post',
                ];
            }
            $groups[] = ['label' => 'Posts', 'items' => $items];
        }
    } catch (Throwable $e) {}

    // People (admins only)
    if ($isAdmin) {
        try {
            $rows = fetch_all(
                'SELECT id, full_name, email, role FROM users WHERE full_name LIKE ? OR email LIKE ? OR social_handle LIKE ? ORDER BY full_name ASC LIMIT 5',
                [$like, $like, $like]
            );
            if ($rows) {
                $items = [];
                foreach ($rows as $r) {
                    $items[] = [
                        'title' => $r['full_name'],
                        'sub'   => $r['email'] . ' · ' . ucfirst($r['role']),
                        'url'   => u($r['role'] === 'creator' ? 'admin/creator_detail.php?id=' . (int)$r['id'] : 'admin/creators.php'),
                        'icon'  => 'people',
                    ];
                }
                $groups[] = ['label' => 'People', 'items' => $items];
            }
        } catch (Throwable $e) {}
    }

    json_response(['ok' => true, 'query' => $q, 'groups' => $groups]);
}

json_response(['ok' => false, 'error' => 'Unknown action.'], 400);
