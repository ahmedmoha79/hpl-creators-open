<?php
/**
 * Email ownership check for password accounts (2026-10-27).
 *
 * - Accounts created with Google are verified by Google itself (Google only
 *   signs in addresses it has verified), so they are marked verified on sign-in.
 * - Accounts created with email + password receive a 6-digit code by email.
 *   The code is stored hashed, expires after 30 minutes and allows 5 tries.
 * - Withdrawals need a verified email.
 */
require_once __DIR__ . '/mailer.php';

function email_is_verified(array $user): bool
{
    return !empty($user['email_verified_at']);
}

/** Seconds until another code may be sent (0 = can send now). */
function email_code_wait(array $user): int
{
    if (empty($user['email_code_sent_at'])) {
        return 0;
    }
    $next = strtotime((string)$user['email_code_sent_at']) + 60;
    return max(0, $next - time());
}

/** Create and email a new code. Returns null on success, or an error message. */
function email_send_code(array $user): ?string
{
    if (email_is_verified($user)) {
        return null;
    }
    $wait = email_code_wait($user);
    if ($wait > 0) {
        return 'Please wait ' . $wait . ' seconds before asking for another code.';
    }
    $code = (string)random_int(100000, 999999);
    q('UPDATE users SET email_code_hash = ?, email_code_expires = NOW() + INTERVAL 30 MINUTE, email_code_attempts = 0, email_code_sent_at = NOW() WHERE id = ?',
        [password_hash($code, PASSWORD_DEFAULT), (int)$user['id']]);

    $first = explode(' ', trim((string)$user['full_name']))[0] ?: 'there';
    $html = '<p style="margin:0 0 14px;font-size:16px">Hi ' . e($first) . ',</p>'
        . '<p style="margin:0 0 18px;font-size:15px;line-height:1.6">Use this code to confirm your email address for your ' . e(APP_NAME) . ' account:</p>'
        . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;background:#fff7ec;border:1px solid #e4d4bf;border-radius:12px;padding:16px 0;text-align:center;color:#1e140f">' . $code . '</div>'
        . '<p style="margin:18px 0 0;font-size:14px;color:#6e5949">The code expires in 30 minutes. Never share it with anyone, HPL staff will never ask for it.</p>';
    $text = "Your " . APP_NAME . " verification code is $code. It expires in 30 minutes.";
    if (!app_mail((string)$user['email'], 'Your ' . APP_NAME . ' code: ' . $code, $html, $text)) {
        return 'We could not send the email right now. Please try again in a few minutes or contact support.';
    }
    return null;
}

/** Check a code. Returns null when verified, or an error message. */
function email_check_code(array $user, string $code): ?string
{
    if (email_is_verified($user)) {
        return null;
    }
    $code = preg_replace('/\D/', '', $code);
    $row = fetch_one('SELECT email_code_hash, email_code_expires, email_code_attempts FROM users WHERE id = ?', [(int)$user['id']]);
    if (!$row || empty($row['email_code_hash'])) {
        return 'Ask for a code first.';
    }
    if ((int)$row['email_code_attempts'] >= 5) {
        return 'Too many wrong codes. Ask for a new code.';
    }
    if (strtotime((string)$row['email_code_expires']) < time()) {
        return 'This code has expired. Ask for a new code.';
    }
    if (strlen($code) !== 6 || !password_verify($code, (string)$row['email_code_hash'])) {
        q('UPDATE users SET email_code_attempts = email_code_attempts + 1 WHERE id = ?', [(int)$user['id']]);
        $left = 4 - (int)$row['email_code_attempts'];
        return 'That code is not correct.' . ($left > 0 ? ' ' . $left . ' tries left.' : ' Ask for a new code.');
    }
    q('UPDATE users SET email_verified_at = NOW(), email_code_hash = NULL, email_code_expires = NULL, email_code_attempts = 0 WHERE id = ?', [(int)$user['id']]);
    return null;
}

/** Mark verified (used after a Google sign-in, because Google confirmed the address). */
function email_mark_verified(int $userId): void
{
    try {
        q('UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?', [$userId]);
    } catch (Throwable $e) {
        error_log('email_mark_verified: ' . $e->getMessage());
    }
}
