<?php
/**
 * Permanently delete a creator account and everything it created (clips, chats, comments,
 * notifications...). Used for demo, test and spam accounts.
 *
 * Safety: only suspended creator accounts, and never one with payout history
 * (pending or paid withdrawals are money records and must be kept).
 */
require_once __DIR__ . '/earnings.php'; // hpl_tx()

/** Why this account cannot be deleted, or null when it can. */
function hpl_account_delete_blocker(array $u): ?string
{
    if (($u['role'] ?? '') !== 'creator') {
        return 'Only creator accounts can be deleted.';
    }
    if (($u['status'] ?? '') !== 'suspended') {
        return 'Suspend the account first, then delete it.';
    }
    $w = (int)fetch_val('SELECT COUNT(*) FROM withdrawals WHERE user_id = ? AND status <> "rejected"', [(int)$u['id']], 0);
    if ($w > 0) {
        return 'This account has payout history, so it is kept for your records. Leave it suspended.';
    }
    return null;
}

/** Delete the account. Returns null on success or an error message. */
function hpl_delete_account(int $id): ?string
{
    $u = fetch_one('SELECT * FROM users WHERE id = ?', [$id]);
    if (!$u) {
        return 'Account not found.';
    }
    $block = hpl_account_delete_blocker($u);
    if ($block !== null) {
        return $block;
    }
    $try = function (string $sql, array $p = []) {
        try { q($sql, $p); } catch (Throwable $e) { /* table may not exist on older installs */ }
    };
    try {
        hpl_tx(function () use ($id, $try) {
        // Give campaign budgets back what this account was credited.
        $try('UPDATE campaigns c JOIN (SELECT campaign_id, SUM(earned) s FROM campaign_submissions WHERE user_id = ? GROUP BY campaign_id) x ON x.campaign_id = c.id
              SET c.paid_amount = GREATEST(c.paid_amount - x.s, 0)', [$id]);
        // Keep announcement counters honest.
        $try('UPDATE ann_posts SET like_count = GREATEST(like_count - 1, 0) WHERE id IN (SELECT post_id FROM ann_likes WHERE user_id = ?)', [$id]);
        $try('UPDATE ann_posts SET view_count = GREATEST(view_count - 1, 0) WHERE id IN (SELECT post_id FROM ann_views WHERE user_id = ?)', [$id]);
        // Direct messages: every conversation this account was part of.
        $threads = [];
        try { $threads = array_map('intval', array_column(fetch_all('SELECT thread_id FROM dm_participants WHERE user_id = ?', [$id]), 'thread_id')); } catch (Throwable $e) {}
        if ($threads) {
            $ph = implode(',', array_fill(0, count($threads), '?'));
            $try('DELETE FROM dm_messages WHERE thread_id IN (' . $ph . ')', $threads);
            $try('DELETE FROM dm_participants WHERE thread_id IN (' . $ph . ')', $threads);
            $try('DELETE FROM dm_threads WHERE id IN (' . $ph . ')', $threads);
        }
        $try('DELETE FROM dm_messages WHERE sender_id = ?', [$id]);
        // Ready clips they took: unposted ones are free again; posted ones are hidden (already on TikTok).
        $try('UPDATE ready_clips SET claimed_by = NULL, claimed_at = NULL WHERE claimed_by = ? AND submission_id IS NULL', [$id]);
        $try('UPDATE ready_clips SET is_active = 0, submission_id = NULL WHERE claimed_by = ?', [$id]);
        foreach (['ann_likes', 'ann_views', 'ann_comments', 'assessments', 'campaign_joins', 'campaign_submissions', 'community_messages',
                  'content_links', 'course_progress', 'earning_adjustments', 'earning_days', 'feedback', 'footage_downloads',
                  'lesson_comment_votes', 'lesson_comments', 'notifications', 'portfolio_links', 'social_pages', 'tiktok_accounts',
                  'user_bonuses', 'withdrawals'] as $t) {
            $try('DELETE FROM ' . $t . ' WHERE user_id = ?', [$id]);
        }
        q('DELETE FROM users WHERE id = ? AND role = "creator"', [$id]);
    });
    } catch (Throwable $e) {
        error_log('HPL account delete ' . $id . ': ' . $e->getMessage());
        return 'The account could not be deleted (' . mb_substr($e->getMessage(), 0, 160) . '). Nothing was removed.';
    }
    return null;
}
