<?php
/**
 * Demo creator (owner, 2026-11-11): one ready-made creator account the team opens from Admin > Demo account,
 * to film screen recordings and tutorials without using a real creator.
 *
 * - Opened only from an admin session (no password is ever typed); "Back to admin" in the creator menu returns.
 * - Never earns money (credit_campaign_submission records views only), cannot claim bonuses or withdraw,
 *   is never warned or paused for inactivity and never appears in the weekly ranking (it earns nothing).
 * - Everything else works like a real creator: the test, connecting a TikTok, taking and sending clips, chat.
 *   Anything it writes in the chat groups is seen by everyone.
 * - "Reset" gives its taken ready clips back to the free list and removes its own clip links.
 */

const DEMO_EMAIL = 'demo.creator@golddetectorinzambia.com';

function is_demo_user(?array $u): bool
{
    return $u !== null && (int)($u['is_demo'] ?? 0) === 1;
}

function is_demo_user_id(int $uid): bool
{
    static $cache = [];
    if ($uid <= 0) {
        return false;
    }
    if (!array_key_exists($uid, $cache)) {
        try {
            $cache[$uid] = hpl_column_exists('users', 'is_demo') && (int)fetch_val('SELECT is_demo FROM users WHERE id = ?', [$uid], 0) === 1;
        } catch (Throwable $e) {
            $cache[$uid] = false;
        }
    }
    return $cache[$uid];
}

/** The demo creator's row, made the first time (passed the test, email confirmed, Lusaka). */
function demo_user(bool $create = true): ?array
{
    $u = fetch_one('SELECT * FROM users WHERE is_demo = 1 ORDER BY id LIMIT 1');
    if ($u || !$create) {
        return $u ?: null;
    }
    $taken = fetch_one('SELECT id FROM users WHERE email = ?', [DEMO_EMAIL]);
    if ($taken) {
        q('UPDATE users SET is_demo = 1 WHERE id = ?', [(int)$taken['id']]);
    } else {
        // A long random password nobody knows: the account is only ever opened from the admin side.
        q('INSERT INTO users (full_name, email, phone, password, role, status, country, town) VALUES (?,?,?,?, "creator", "active", "Zambia", "Lusaka")',
            ['Mutale Banda', DEMO_EMAIL, '0970000000', password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)]);
        q('UPDATE users SET is_demo = 1 WHERE email = ?', [DEMO_EMAIL]);
    }
    $id = (int)fetch_val('SELECT id FROM users WHERE email = ?', [DEMO_EMAIL], 0);
    foreach (['assessment_status = "passed"', 'qualified = 1', 'email_verified_at = NOW()'] as $set) {
        try { q('UPDATE users SET ' . $set . ' WHERE id = ?', [$id]); } catch (Throwable $e) {}
    }
    return fetch_one('SELECT * FROM users WHERE id = ?', [$id]) ?: null;
}

/** Switch this browser from the admin to the demo creator. */
function demo_enter(array $admin): bool
{
    $d = demo_user();
    if (!$d || ($admin['role'] ?? '') !== 'admin') {
        return false;
    }
    if ($d['status'] !== 'active') {
        q('UPDATE users SET status = "active", suspend_reason = NULL WHERE id = ?', [(int)$d['id']]);
    }
    session_regenerate_id(true);
    $_SESSION = ['demo_admin_id' => (int)$admin['id'], 'csrf' => bin2hex(random_bytes(16))];
    $_SESSION['user_id'] = (int)$d['id'];
    $_SESSION['role'] = 'creator';
    $_SESSION['hpl_dev_ok'] = 1; // do not record this computer as the demo's phone
    return true;
}

/** Back from the demo creator to the admin who opened it. */
function demo_leave(): bool
{
    $adminId = (int)($_SESSION['demo_admin_id'] ?? 0);
    $a = $adminId > 0 ? fetch_one('SELECT * FROM users WHERE id = ? AND role = "admin" AND status = "active"', [$adminId]) : null;
    if (!$a) {
        return false;
    }
    session_regenerate_id(true);
    $_SESSION = ['csrf' => bin2hex(random_bytes(16))];
    $_SESSION['user_id'] = (int)$a['id'];
    $_SESSION['role'] = 'admin';
    $_SESSION['pw_tag'] = (int)$a['id'] . ':' . substr(hash('sha256', (string)$a['password']), 0, 24);
    return true;
}

/** Give the demo's ready clips back and remove its own clip links. Returns [clips freed, links removed]. */
function demo_reset(): array
{
    $d = demo_user(false);
    if (!$d) {
        return [0, 0];
    }
    $uid = (int)$d['id'];
    $freed = 0;
    $removed = 0;
    try {
        $freed = q('UPDATE ready_clips SET claimed_by = NULL, claimed_at = NULL, reminded_at = NULL, submission_id = NULL WHERE claimed_by = ?', [$uid])->rowCount();
    } catch (Throwable $e) {
    }
    try {
        // The demo never earns, so removing its links never touches money or a campaign budget.
        $ids = array_map('intval', array_column(fetch_all('SELECT id FROM campaign_submissions WHERE user_id = ?', [$uid]), 'id'));
        if ($ids) {
            $in = implode(',', $ids);
            try { q("DELETE FROM clip_stats WHERE submission_id IN ($in)"); } catch (Throwable $e) {}
            try { q("DELETE FROM earning_days WHERE src = 'campaign' AND ref_id IN ($in)"); } catch (Throwable $e) {}
            $removed = q("DELETE FROM campaign_submissions WHERE id IN ($in) AND user_id = ?", [$uid])->rowCount();
        }
    } catch (Throwable $e) {
        error_log('demo_reset: ' . $e->getMessage());
    }
    return [$freed, $removed];
}
