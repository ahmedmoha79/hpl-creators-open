<?php
/**
 * Offers that pop up (2026-11-10). The team writes a short message with one button (Admin > Offers) and
 * chooses who sees it and how:
 *   card = a small card that slides up from the bottom of Home, once per creator;
 *   line = a thin line at the top of Home (part of the page, covers nothing).
 * An offer is a MESSAGE: it never pays or changes money by itself. A real bonus is made in Rewards & bonuses
 * and the offer points people to it.
 * One offer at a time, and never on top of another pop-up (the welcome bonus, a join window...).
 */

function offer_audiences(): array
{
    return [
        'all' => 'Every creator',
        'no_test' => 'Has not passed the test yet',
        'no_tiktok' => 'Passed the test, TikTok not connected',
        'no_clip' => 'Ready, but has never sent a clip',
        'active' => 'Sent a clip in the last 7 days',
        'quiet' => 'Sent clips before, nothing for 7 days',
    ];
}

/** Does this creator belong to the audience? */
function offer_fits(array $user, string $aud): bool
{
    $uid = (int)$user['id'];
    $passed = ($user['assessment_status'] ?? '') === 'passed' || (int)($user['qualified'] ?? 0) === 1;
    if ($aud === 'all') { return true; }
    if ($aud === 'no_test') { return !$passed; }
    $hasTt = (bool)fetch_val('SELECT 1 FROM tiktok_accounts WHERE user_id = ? LIMIT 1', [$uid], 0);
    if ($aud === 'no_tiktok') { return $passed && !$hasTt; }
    $last = fetch_val('SELECT MAX(submitted_at) FROM campaign_submissions WHERE user_id = ?', [$uid], null);
    if ($aud === 'no_clip') { return $passed && $hasTt && $last === null; }
    if ($aud === 'active') { return $last !== null && strtotime((string)$last) > time() - 7 * 86400; }
    if ($aud === 'quiet') { return $last !== null && strtotime((string)$last) <= time() - 7 * 86400; }
    return false;
}

/** The one offer to show this creator now (newest first), or null. */
function offer_for(array $user): ?array
{
    try {
        $rows = fetch_all('SELECT o.* FROM offers o LEFT JOIN offer_views v ON v.offer_id = o.id AND v.user_id = ?
            WHERE o.is_active = 1 AND v.offer_id IS NULL AND (o.starts_at IS NULL OR o.starts_at <= NOW()) AND (o.ends_at IS NULL OR o.ends_at > NOW())
            ORDER BY o.id DESC LIMIT 5', [(int)$user['id']]);
        foreach ($rows as $o) {
            if (offer_fits($user, (string)$o['audience'])) {
                return $o;
            }
        }
    } catch (Throwable $e) {
    }
    return null;
}

/** A link inside this site only ("creator/..."), so an offer can never send people somewhere else. */
function offer_clean_url(string $url): string
{
    $url = ltrim(trim($url), '/');
    return preg_match('~^creator/[a-z0-9_]+\.php([?#][A-Za-z0-9_=&%#.-]*)?$~', $url) ? $url : '';
}

/** The offer's HTML for Home (nothing when there is none). */
function offer_render(array $user): string
{
    $o = offer_for($user);
    if (!$o) {
        return '';
    }
    $url = offer_clean_url((string)$o['btn_url']);
    $id = (int)$o['id'];
    $api = e(u('creator/offer.php'));
    $btn = ($url !== '' && $o['btn_label'] !== '') ? '<a class="hk-btn hk-btn--gold" data-offer-go href="' . e(u($url)) . '">' . e($o['btn_label']) . '</a>' : '';
    if ($o['style'] === 'line') {
        return '<div class="offer-line" data-offer="' . $id . '" data-api="' . $api . '"><span class="offer-line__txt"><strong>' . e($o['title']) . '</strong> ' . e($o['body']) . '</span>'
            . ($btn !== '' ? str_replace('hk-btn hk-btn--gold', 'offer-line__go', $btn) : '') . '<button type="button" class="offer-line__x" data-offer-x aria-label="Close">&times;</button></div>';
    }
    return '<div class="offer-card" data-offer="' . $id . '" data-api="' . $api . '" role="dialog" aria-label="' . e($o['title']) . '" hidden>'
        . '<button type="button" class="offer-card__x" data-offer-x aria-label="Close">&times;</button>'
        . '<span class="offer-card__ico">' . icon('award') . '</span><div class="offer-card__txt"><strong>' . e($o['title']) . '</strong><span>' . e($o['body']) . '</span></div>'
        . ($btn !== '' ? '<div class="offer-card__btns">' . $btn . '</div>' : '') . '</div>';
}
