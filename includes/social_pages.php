<?php
/**
 * Social page verification: one place for the whole life of a submission.
 *
 *   pending  --(creator: Check finds the code / admin: Approve)--> approved
 *   pending  --(admin: Reject + reason)--------------------------> rejected
 *   pending  --(creator: Cancel)---------------------------------> removed
 *   rejected --(creator: Try again, same code)-------------------> pending
 *   rejected --(creator: Remove)---------------------------------> removed
 *   approved --(admin: Revoke + reason)--------------------------> rejected
 *   rejected --(admin: Approve, if nobody else owns the page)----> approved
 *
 * Every change is conditional on the status it expects (two admins, a double tap or a
 * creator cancelling at the same moment can never apply a decision twice), and one page
 * can only ever be approved for one HPL account.
 */
require_once __DIR__ . '/verification.php';

const SP_MAX_PENDING = 5;   // waiting at the same time, per creator
const SP_MAX_ACTIVE  = 10;  // pending + verified, per creator

/** Result message shown inside the "Your social pages" card (the page scrolls there, so a top-of-page flash would be missed). */
function sp_say(string $type, string $msg): void
{
    $_SESSION['sp_msg'] = ['type' => in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info', 'msg' => $msg];
}

/** Platforms a creator can add, with the profile link we expect. */
function sp_platforms(): array
{
    return [
        'tiktok'    => ['TikTok', 'https://www.tiktok.com/@yourname'],
        'instagram' => ['Instagram', 'https://www.instagram.com/yourname'],
        'facebook'  => ['Facebook', 'https://www.facebook.com/yourpage'],
        'youtube'   => ['YouTube', 'https://www.youtube.com/@yourchannel'],
        'twitter'   => ['X (Twitter)', 'https://x.com/yourname'],
    ];
}

/** Reasons the team can pick when a page is not approved (shown to the creator). */
function sp_reject_reasons(): array
{
    return [
        'code'      => 'We could not find your HPL code in the bio. Add it exactly as shown, save, then tap Try again.',
        'personal'  => 'Your Instagram is a personal account. Switch it to a free Professional (Creator) account, then tap Try again.',
        'private'   => 'Your page is private or could not be opened. Make it public, then tap Try again.',
        'not_owner' => 'This page does not appear to be yours. Only add pages you run yourself.',
        'link'      => 'The link does not open a profile. Open your profile, tap Share, Copy link, and add it again.',
        'other'     => '',
    ];
}

/** Stable identity of a page: platform + handle, or the cleaned link when there is no handle. */
function sp_identity(string $platform, string $url): string
{
    $h = hpl_handle_from_url($platform, $url);
    if ($h !== null) {
        return $platform . ':@' . $h;
    }
    $p = parse_url(strtolower(trim($url)));
    $host = preg_replace('/^(www\.|m\.|mobile\.|web\.)/', '', (string)($p['host'] ?? ''));
    $path = rtrim((string)($p['path'] ?? ''), '/');
    // facebook.com/profile.php?id=123 keeps its id
    if (!empty($p['query']) && preg_match('/(?:^|&)id=(\d+)/', $p['query'], $m)) {
        $path .= '?id=' . $m[1];
    }
    return $platform . ':' . $host . $path;
}

/** All submissions for the same page (any account, any status). */
function sp_same_page(string $platform, string $identity, int $exceptId = 0): array
{
    $out = [];
    foreach (fetch_all('SELECT * FROM social_pages WHERE platform = ? AND id <> ?', [$platform, $exceptId]) as $r) {
        if (sp_identity($platform, (string)$r['url']) === $identity) {
            $out[] = $r;
        }
    }
    return $out;
}

/** A short label for a page: @handle, or the page name / link. */
function sp_label(array $p): string
{
    $h = hpl_handle_from_url((string)$p['platform'], (string)$p['url']);
    if ($h !== null) {
        return '@' . $h;
    }
    return (string)($p['page_name'] ?: preg_replace('~^https?://(www\.)?~i', '', (string)$p['url']));
}

/** Serialise work on one page (two people adding it at the same second). */
function sp_lock(string $identity): bool
{
    try {
        return (int)fetch_val('SELECT GET_LOCK(?, 5)', ['hpl_sp_' . md5($identity)], 0) === 1;
    } catch (Throwable $e) {
        return true; // locks are a safety net, not a requirement
    }
}
function sp_unlock(string $identity): void
{
    try { fetch_val('SELECT RELEASE_LOCK(?)', ['hpl_sp_' . md5($identity)]); } catch (Throwable $e) {}
}

/**
 * Creator adds a page. Returns ['ok' => bool, 'type' => flash type, 'msg' => text].
 * Adding a page that was turned down before re-opens that same submission (same code).
 */
function sp_submit(array $user, string $platform, string $rawUrl): array
{
    $uid = (int)$user['id'];
    if (!array_key_exists($platform, sp_platforms())) {
        return ['ok' => false, 'type' => 'error', 'msg' => 'Choose the platform your page is on.'];
    }
    $url = normalize_url($rawUrl);
    if (!$url) {
        return ['ok' => false, 'type' => 'error', 'msg' => 'Paste the link to your profile, for example ' . sp_platforms()[$platform][1]];
    }
    $url = hpl_resolve_url($url);
    if (($err = hpl_url_platform_error($platform, $url)) !== null) {
        return ['ok' => false, 'type' => 'error', 'msg' => $err];
    }
    $handle = hpl_handle_from_url($platform, $url);
    if ($handle === null && in_array($platform, hpl_handle_platforms(), true)) {
        return ['ok' => false, 'type' => 'error', 'msg' => 'That is a link to a video or post. Paste your profile link instead, for example ' . sp_platforms()[$platform][1]];
    }
    $label = $handle !== null ? '@' . $handle : preg_replace('~^https?://(www\.)?~i', '', $url);
    $identity = sp_identity($platform, $url);

    sp_lock($identity);
    try {
        $mineAgain = null;
        foreach (sp_same_page($platform, $identity) as $r) {
            if ((int)$r['user_id'] === $uid) {
                if ($r['status'] === 'approved') {
                    return ['ok' => false, 'type' => 'info', 'msg' => $label . ' is already verified on your account.'];
                }
                if ($r['status'] === 'pending') {
                    return ['ok' => false, 'type' => 'info', 'msg' => $label . ' is already waiting for verification. Put the code in your bio and tap Check.'];
                }
                $mineAgain = $r; // turned down before: re-open it below
            } elseif (in_array($r['status'], ['pending', 'approved'], true)) {
                return ['ok' => false, 'type' => 'error', 'msg' => $label . ' is already linked to another HPL account. If this page is yours, message the HPL team in Messages.'];
            }
        }

        $counts = fetch_one('SELECT SUM(status = "pending") AS pending, SUM(status IN ("pending","approved")) AS active FROM social_pages WHERE user_id = ?', [$uid]);
        if ((int)($counts['pending'] ?? 0) >= SP_MAX_PENDING) {
            return ['ok' => false, 'type' => 'warning', 'msg' => 'You already have ' . SP_MAX_PENDING . ' pages waiting. Finish or cancel one of them first.'];
        }
        if ((int)($counts['active'] ?? 0) >= SP_MAX_ACTIVE) {
            return ['ok' => false, 'type' => 'warning', 'msg' => 'You can link up to ' . SP_MAX_ACTIVE . ' pages. Message the HPL team if you need more.'];
        }

        if ($mineAgain) {
            q('UPDATE social_pages SET url = ?, page_name = ?, status = "pending", admin_note = NULL, reviewed_at = NULL, reviewed_by = NULL, submitted_at = NOW() WHERE id = ? AND status = "rejected"',
                [$url, $label, (int)$mineAgain['id']]);
            $id = (int)$mineAgain['id'];
        } else {
            q('INSERT INTO social_pages (user_id, platform, url, page_name, status) VALUES (?, ?, ?, ?, "pending")', [$uid, $platform, $url, $label]);
            $id = (int)db()->lastInsertId();
        }
    } finally {
        sp_unlock($identity);
    }

    $code = hpl_social_code(['id' => $id, 'user_id' => $uid]);
    notif_add($uid, 'verification', 'Your verification code: ' . $code, 'Add ' . $code . ' to the bio of ' . $label . ', then tap Check on your dashboard.', 'creator/profile.php#socialPage');
    notif_admins('social', 'Page to verify: ' . $label, ($user['full_name'] ?? 'A creator') . ' added ' . $label . ' (' . platform_label($platform) . '). Code ' . $code . '.', 'admin/social_pages.php', ['type' => 'page', 'id' => $id]);
    return ['ok' => true, 'type' => 'success', 'msg' => ($mineAgain ? 'Sent again. ' : 'Added. ') . 'Now put ' . $code . ' in the bio of ' . $label . ', save, and tap Check.'];
}

/** Creator withdraws a page that is still waiting. */
function sp_cancel(int $uid, int $id): array
{
    $n = q('DELETE FROM social_pages WHERE id = ? AND user_id = ? AND status = "pending"', [$id, $uid])->rowCount();
    return $n
        ? ['type' => 'success', 'msg' => 'Submission cancelled. You can remove the code from your bio.']
        : ['type' => 'info', 'msg' => 'That submission was already reviewed, so it could not be cancelled.'];
}

/** Creator clears a page that was turned down. */
function sp_dismiss(int $uid, int $id): array
{
    $n = q('DELETE FROM social_pages WHERE id = ? AND user_id = ? AND status = "rejected"', [$id, $uid])->rowCount();
    return $n ? ['type' => 'success', 'msg' => 'Removed.'] : ['type' => 'info', 'msg' => 'Nothing to remove.'];
}

/** Creator sends a turned-down page for review again (same link, same code). */
function sp_retry(array $user, int $id): array
{
    $p = fetch_one('SELECT * FROM social_pages WHERE id = ? AND user_id = ?', [$id, (int)$user['id']]);
    if (!$p || $p['status'] !== 'rejected') {
        return ['type' => 'info', 'msg' => 'That page is not waiting for a retry.'];
    }
    return sp_submit($user, (string)$p['platform'], (string)$p['url']);
}

/**
 * Approve a page (team, or the creator's own Check when an official source confirmed the code).
 * $from: the statuses this decision may change. Returns ['ok', 'msg'].
 */
function sp_approve(int $id, ?int $adminId, string $note, array $from = ['pending']): array
{
    $p = fetch_one('SELECT * FROM social_pages WHERE id = ?', [$id]);
    if (!$p) {
        return ['ok' => false, 'msg' => 'That submission no longer exists. The creator may have cancelled it.'];
    }
    if (!in_array($p['status'], $from, true)) {
        return ['ok' => false, 'msg' => sp_label($p) . ' was already ' . $p['status'] . '. Nothing changed.'];
    }
    $identity = sp_identity((string)$p['platform'], (string)$p['url']);
    sp_lock($identity);
    try {
        foreach (sp_same_page((string)$p['platform'], $identity, $id) as $other) {
            if ($other['status'] === 'approved' && (int)$other['user_id'] !== (int)$p['user_id']) {
                $who = (string)fetch_val('SELECT full_name FROM users WHERE id = ?', [(int)$other['user_id']], 'another creator');
                return ['ok' => false, 'msg' => sp_label($p) . ' is already verified for ' . $who . '. Revoke that one first if this creator is the real owner.'];
            }
        }
        $in = implode(',', array_fill(0, count($from), '?'));
        $n = q("UPDATE social_pages SET status = 'approved', admin_note = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND status IN ($in)",
            array_merge([$note, $adminId, $id], $from))->rowCount();
        if (!$n) {
            return ['ok' => false, 'msg' => 'Someone else reviewed ' . sp_label($p) . ' at the same moment. Nothing changed.'];
        }
        // Other people still waiting on the same page cannot get it any more.
        foreach (sp_same_page((string)$p['platform'], $identity, $id) as $other) {
            if ($other['status'] === 'pending' && (int)$other['user_id'] !== (int)$p['user_id']) {
                q('UPDATE social_pages SET status = "rejected", admin_note = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND status = "pending"',
                    ['This page was verified for its owner on another account.', $adminId, (int)$other['id']]);
                notif_add((int)$other['user_id'], 'verification', sp_label($p) . ' was not approved', 'This page was verified for its owner on another account.', 'creator/profile.php#socialPage');
            }
        }
    } finally {
        sp_unlock($identity);
    }
    $code = hpl_social_code($p);
    notif_add((int)$p['user_id'], 'verification', 'Your ' . platform_label((string)$p['platform']) . ' page is verified',
        sp_label($p) . ' is verified. You can remove ' . $code . ' from your bio and start submitting clips.', 'creator/profile.php#socialPage');
    return ['ok' => true, 'msg' => sp_label($p) . ' approved. The creator has been told.'];
}

/** Turn a page down (pending), or revoke a verified one (approved). A reason is required. */
function sp_reject(int $id, int $adminId, string $reasonKey, string $extra, array $from = ['pending']): array
{
    $reasons = sp_reject_reasons();
    $text = trim(($reasons[$reasonKey] ?? '') . ' ' . trim($extra));
    if ($text === '') {
        return ['ok' => false, 'msg' => 'Choose a reason or write one, so the creator knows what to fix.'];
    }
    $text = mb_substr($text, 0, 255);
    $p = fetch_one('SELECT * FROM social_pages WHERE id = ?', [$id]);
    if (!$p) {
        return ['ok' => false, 'msg' => 'That submission no longer exists. The creator may have cancelled it.'];
    }
    $in = implode(',', array_fill(0, count($from), '?'));
    $n = q("UPDATE social_pages SET status = 'rejected', admin_note = ?, reviewed_at = NOW(), reviewed_by = ? WHERE id = ? AND status IN ($in)",
        array_merge([$text, $adminId, $id], $from))->rowCount();
    if (!$n) {
        return ['ok' => false, 'msg' => sp_label($p) . ' was already ' . $p['status'] . '. Nothing changed.'];
    }
    $revoked = $p['status'] === 'approved';
    notif_add((int)$p['user_id'], 'verification',
        sp_label($p) . ($revoked ? ' is no longer verified' : ' was not approved'), $text, 'creator/profile.php#socialPage');
    return ['ok' => true, 'msg' => sp_label($p) . ($revoked ? ' revoked.' : ' rejected.') . ' The creator has been told why.'];
}
