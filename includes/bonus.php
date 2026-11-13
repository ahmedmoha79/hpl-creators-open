<?php
/**
 * Creator bonuses (a small ledger, separate from view earnings).
 *
 * Sign-up bonus: every account created after the feature went live gets one
 * 'signup' bonus row (status pending). The creator taps "Receive" on the
 * dashboard and it is credited to their wallet at once (status claimed).
 * Amount comes from Admin > Settings > "Sign-up bonus" (0 turns it off).
 * One per account, enforced by a unique key, so it can never be paid twice.
 */

function bonus_signup_amount(): float
{
    return max(0.0, round((float)setting('signup_bonus_amount', 50), 2));
}

/** Called right after a new creator account is created. */
function bonus_grant_signup(int $userId): void
{
    $amount = bonus_signup_amount();
    if ($userId <= 0 || $amount <= 0) {
        return;
    }
    try {
        q('INSERT IGNORE INTO user_bonuses (user_id, kind, amount, status) VALUES (?, "signup", ?, "pending")', [$userId, $amount]);
    } catch (Throwable $e) {
        error_log('HPL signup bonus: ' . $e->getMessage());
    }
}

/** The creator's unclaimed sign-up bonus row, or null. */
function bonus_pending(int $userId): ?array
{
    try {
        return fetch_one('SELECT * FROM user_bonuses WHERE user_id = ? AND kind = "signup" AND status = "pending"', [$userId]) ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

/** Credit the pending sign-up bonus. Returns the amount credited, or null if there was nothing to claim. */
function bonus_claim_signup(int $userId): ?float
{
    $row = bonus_pending($userId);
    if (!$row) {
        return null;
    }
    // Conditional update: two taps at once still credit only once.
    $st = q('UPDATE user_bonuses SET status = "claimed", claimed_at = NOW() WHERE id = ? AND status = "pending"', [(int)$row['id']]);
    if ($st->rowCount() !== 1) {
        return null;
    }
    notif_add($userId, 'bonus', 'Sign-up bonus received', money((float)$row['amount']) . ' was added to your wallet. Welcome to HPL Creators!', 'creator/earnings.php');
    return (float)$row['amount'];
}

/** Total bonuses credited to a creator's wallet. */
function bonus_total(int $userId): float
{
    try {
        return (float)fetch_val('SELECT COALESCE(SUM(amount),0) FROM user_bonuses WHERE user_id = ? AND status = "claimed"', [$userId], 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}
