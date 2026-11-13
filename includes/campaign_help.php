<?php
/**
 * Campaign footage + "Start here" helpers.
 * Footage items can belong to a campaign (footage.campaign_id). Anyone who joined
 * that campaign can download them; the general library stays for qualified creators.
 */

/** Published footage attached to a campaign, newest first. */
function campaign_footage(int $campaignId): array
{
    try {
        return fetch_all('SELECT * FROM footage WHERE campaign_id = ? AND is_active = 1 ORDER BY uploaded_at DESC', [$campaignId]);
    } catch (Throwable $e) {
        return [];
    }
}

/** Published footage counts per campaign id. */
function campaign_footage_counts(): array
{
    $out = [];
    try {
        foreach (fetch_all('SELECT campaign_id, COUNT(*) n FROM footage WHERE campaign_id IS NOT NULL AND is_active = 1 GROUP BY campaign_id') as $r) {
            $out[(int)$r['campaign_id']] = (int)$r['n'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Can this creator download this footage item? */
function footage_user_can_download(array $user, array $item): bool
{
    // Only approved creators download footage (they passed the test and sent one good video),
    // and a campaign's footage only after joining that campaign (owner decision 2026-11-03).
    if ((int)($user['qualified'] ?? 0) !== 1) {
        return false;
    }
    $cid = (int)($item['campaign_id'] ?? 0);
    if ($cid <= 0) {
        return true;
    }
    return (int)fetch_val('SELECT COUNT(*) FROM campaign_joins WHERE user_id = ? AND campaign_id = ?', [(int)$user['id'], $cid], 0) > 0;
}

/** Platforms on which the creator has a verified (approved) page. */
function creator_verified_platforms(int $userId): array
{
    try {
        return array_column(fetch_all('SELECT DISTINCT platform FROM social_pages WHERE user_id = ? AND status = "approved"', [$userId]), 'platform');
    } catch (Throwable $e) {
        return [];
    }
}

/** Tell everyone who joined a campaign that new footage is ready. */
function campaign_notify_footage(int $campaignId, string $footageTitle): void
{
    try {
        $c = fetch_one('SELECT id, title FROM campaigns WHERE id = ?', [$campaignId]);
        if (!$c) {
            return;
        }
        foreach (fetch_all('SELECT user_id FROM campaign_joins WHERE campaign_id = ?', [$campaignId]) as $j) {
            notif_add((int)$j['user_id'], 'footage', 'New footage for ' . $c['title'], $footageTitle . ' is ready to download. Make your clip and submit the link.', 'creator/campaign.php?id=' . $campaignId . '#campFootage');
        }
    } catch (Throwable $e) {
    }
}
