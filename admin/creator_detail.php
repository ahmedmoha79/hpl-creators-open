<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/account_delete.php';
$user = require_admin();
$pageTitle = 'Creator details';

$id = (int)get('id');
$creator = fetch_one('SELECT * FROM users WHERE id = ? AND role = "creator"', [$id]);
if (!$creator) {
    flash('error', 'Creator not found.');
    redirect('admin/creators.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'update_profile') {
        $name = trim(post('full_name'));
        $email = strtolower(trim(post('email')));
        $phone = trim(post('phone'));
        $country = trim(post('country'));
        $social = trim(post('social_handle'));
        $avatar = trim(post('avatar_url'));
        $bio = mb_substr(trim(post('bio')), 0, 2000);
        $followers = max(0, (int)post('followers_count'));
        $newPass = (string)post('new_password');
        if ($name === '' || $email === '') {
            flash('error', 'Name and email are required.');
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address.');
        } elseif ((int)fetch_val('SELECT COUNT(*) FROM users WHERE email = ? AND id <> ?', [$email, $id], 0) > 0) {
            flash('error', 'That email is already used by another account.');
        } elseif ($newPass !== '' && strlen($newPass) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } else {
            $avatarUrl = $avatar === '' ? null : normalize_url($avatar);
            q('UPDATE users SET full_name = ?, email = ?, phone = ?, country = ?, social_handle = ?, avatar_url = ?, bio = ?, followers_count = ? WHERE id = ?',
                [$name, $email, $phone, $country, $social === '' ? null : $social, $avatarUrl, $bio === '' ? null : $bio, $followers, $id]);
            if ($newPass !== '') {
                q('UPDATE users SET password = ? WHERE id = ?', [password_hash($newPass, PASSWORD_DEFAULT), $id]);
            }
            flash('success', 'Creator profile updated.');
        }
    } elseif ($action === 'link_add') {
        $platform = post('platform');
        $url = trim(post('url'));
        $title = mb_substr(trim(post('title')), 0, 200);
        $views = max(0, (int)post('views'));
        $earned = max(0, (float)post('earned'));
        if (!array_key_exists($platform, platforms())) {
            flash('error', 'Choose a valid platform.');
        } elseif ($url === '') {
            flash('error', 'Link URL is required.');
        } elseif (normalize_url($url) === null) {
            flash('error', 'Enter a valid link URL.');
        } elseif ((require_once __DIR__ . '/../includes/verification.php') && ($platformErr = hpl_url_platform_error($platform, normalize_url($url))) !== null) {
            flash('error', $platformErr);
        } else {
            $url = normalize_url($url);
            q('INSERT INTO content_links (user_id, platform, url, video_key, title, views, credited_views, earned) VALUES (?,?,?,?,?,?,?,0)',
                [$id, $platform, $url, hpl_video_key($platform, $url), $title === '' ? null : $title, $views, $views]);
            $newLink = (int)db()->lastInsertId();
            // Money goes through the earnings ledger: recorded with a reason, in the history, and the creator is told.
            $adjErr = $earned > 0 ? earnings_adjust((int)$user['id'], $newLink, $earned, 'Added by the HPL team') : null;
            flash($adjErr ? 'warning' : 'success', 'Link added to this creator.' . ($adjErr ? ' Money not added: ' . $adjErr : ''));
        }
    } elseif ($action === 'link_update') {
        $lid = (int)post('link_id');
        $views = max(0, (int)post('views'));
        $earned = max(0, (float)post('earned'));
        $status = post('status') === 'flagged' ? 'flagged' : 'active';
        // credited_views never goes down, so lowered (fake) views are not paid again by the next view check.
        q('UPDATE content_links SET views = ?, credited_views = GREATEST(credited_views, ?), status = ? WHERE id = ? AND user_id = ?',
            [$views, $views, $status, $lid, $id]);
        // A money change goes through the earnings ledger (reason, history, creator told, and never
        // below money the creator already withdrew).
        $oldEarned = (float)fetch_val('SELECT earned FROM content_links WHERE id = ? AND user_id = ?', [$lid, $id], 0);
        $delta = round($earned - $oldEarned, 2);
        $adjErr = abs($delta) >= 0.01 ? earnings_adjust((int)$user['id'], $lid, $delta, 'Corrected by the HPL team') : null;
        flash($adjErr ? 'warning' : 'success', 'Link updated.' . ($adjErr ? ' Money not changed: ' . $adjErr : ''));
    } elseif ($action === 'clip_status') {
        // Approve or reject one of this creator's campaign clips (same rules as the Campaigns page).
        $sid = (int)post('submission_id');
        $st = (string)post('submission_status');
        $own = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE id = ? AND user_id = ?', [$sid, $id], 0) > 0;
        if ($own && campaign_submission_set_status($sid, $st)) {
            flash('success', $st === 'rejected' ? 'Clip rejected. It earns nothing.' . campaign_debt_note($sid) : 'Clip approved. Its money is now unlocked for the creator.');
        }
    } elseif ($action === 'clip_views') {
        // Record a clip's views by hand when automatic reading is not available (e.g. Facebook, or a creator who has not connected TikTok).
        // The normal rules apply: only views after it was sent earn, at the campaign's rate.
        require_once __DIR__ . '/../includes/view_fetcher.php';
        $sid = (int)post('submission_id');
        $views = max(0, (int)post('views'));
        $sub = fetch_one('SELECT id, start_views FROM campaign_submissions WHERE id = ? AND user_id = ?', [$sid, $id]);
        if ($sub) {
            $start = trim((string)post('start_views'));
            if ($sub['start_views'] === null && $start !== '' && ctype_digit($start) && (int)$start <= $views) {
                q('UPDATE campaign_submissions SET start_views = ?, credited_views = ? WHERE id = ? AND start_views IS NULL', [(int)$start, (int)$start, $sid]);
            }
            $wasUnread = $sub['start_views'] === null && !($start !== '' && ctype_digit($start) && (int)$start <= $views);
            $paid = credit_campaign_submission($sid, $views);
            flash('success', 'Views saved: ' . num($views) . '.' . ($wasUnread ? ' This was the first count for this clip, so it is the starting point: only views above ' . num($views) . ' earn.' : '') . ($paid > 0 ? ' ' . money($paid) . ' added for new views.' : ''));
        }
    } elseif ($action === 'tiktok_help') {
        require_once __DIR__ . '/../includes/tiktok.php';
        $sentOk = tiktok_send_connect_help($id);
        flash($sentOk ? 'success' : 'error', $sentOk ? 'Sent: a message in their inbox with a "Connect my TikTok" button, and a notification.' : 'Could not send the message.');
    } elseif ($action === 'sync') {
        require_once __DIR__ . '/../includes/view_fetcher.php';
        $s = sync_views($id);
        $c = sync_campaign_submissions($id);
        flash('success', 'Views read: ' . ((int)$s['processed'] + (int)$c['processed']) . ' clip(s) updated, ' . money((float)$s['credited'] + (float)$c['credited']) . ' credited.');
    } elseif ($action === 'qualify') {
        q('UPDATE users SET qualified = 1, portfolio_status = "approved", qualified_at = NOW(), rejection_note = NULL WHERE id = ?', [$id]);
        creator_decision_notify($id, true);
        flash('success', 'Creator qualified. They got a notification and an email.');
    } elseif ($action === 'revoke') {
        q('UPDATE users SET qualified = 0, portfolio_status = "rejected", rejection_note = ? WHERE id = ?', ['Your approval was removed by our team.', $id]);
        creator_decision_notify($id, false, 'Your approval was removed by our team.');
        flash('warning', 'Qualification revoked. They can send a new video to be checked again.');
    } elseif ($action === 'delete_account') {
        $err = hpl_delete_account($id);
        if ($err === null) {
            flash('warning', $creator['full_name'] . ' and everything they created was deleted.');
            redirect('admin/creators.php');
        }
        flash('error', $err);
    } elseif ($action === 'clip_pause') {
        require_once __DIR__ . '/../includes/ready_clips.php';
        $why = trim((string)post('reason'));
        if ($why === '') {
            flash('error', 'Write the reason: the creator reads it.');
        } else {
            $len = max(1, (int)post('length', (int)post('days', 3)));
            $until = creator_clip_pause($id, post('unit') === 'hours' ? $len : $len * 24, $why);
            flash('success', 'Paused from clips until ' . date('D j M, H:i', strtotime($until)) . '. The creator was told why.');
        }
    } elseif ($action === 'clip_unpause') {
        q('UPDATE users SET clip_ban_until = NULL, clip_ban_reason = NULL WHERE id = ?', [$id]);
        notif_add($id, 'info', 'You can take and send clips again', 'The HPL team lifted your pause.', 'creator/index.php');
        flash('success', 'Pause lifted. The creator was told.');
    } elseif ($action === 'suspend') {
        q('UPDATE users SET status = "suspended" WHERE id = ?', [$id]);
        flash('warning', 'Account suspended.');
    } elseif ($action === 'activate') {
        q('UPDATE users SET status = "active" WHERE id = ?', [$id]);
        flash('success', 'Account reactivated.');
    } elseif ($action === 'reset_assessment') {
        q('UPDATE users SET assessment_status = "not_taken", assessment_score = NULL WHERE id = ?', [$id]);
        flash('success', 'Assessment reset.');
    } elseif ($action === 'portfolio_approve' || $action === 'portfolio_reject') {
        $pid = (int)post('portfolio_id');
        $note = mb_substr(post('admin_note'), 0, 250);
        $link = fetch_one('SELECT * FROM portfolio_links WHERE id = ? AND user_id = ?', [$pid, $id]);
        if ($link) {
            if ($action === 'portfolio_approve') {
                q('UPDATE portfolio_links SET status = "approved", admin_note = ? WHERE id = ?', [$note, $pid]);
                q('UPDATE users SET qualified = 1, portfolio_status = "approved", qualified_at = NOW(), rejection_note = NULL WHERE id = ?', [$id]);
                creator_decision_notify($id, true);
                flash('success', 'Work approved. Creator is now qualified and was told by notification and email.');
            } else {
                q('UPDATE portfolio_links SET status = "rejected", admin_note = ? WHERE id = ?', [$note, $pid]);
                portfolio_after_reject($id, (string)$note);
                flash('warning', 'Work submission rejected.');
            }
        }
    }
    redirect('admin/creator_detail.php?id=' . $id);
}

$stats = creator_stats($id);
$attempts = fetch_all('SELECT * FROM assessments WHERE user_id = ? ORDER BY taken_at DESC', [$id]);
$portfolio = fetch_all('SELECT * FROM portfolio_links WHERE user_id = ? ORDER BY created_at DESC', [$id]);
$links = fetch_all('SELECT * FROM content_links WHERE user_id = ? ORDER BY views DESC', [$id]);
$clips = fetch_all('SELECT s.*, c.title AS campaign_title, c.platforms AS campaign_platforms FROM campaign_submissions s JOIN campaigns c ON c.id = s.campaign_id WHERE s.user_id = ? ORDER BY s.submitted_at DESC', [$id]);
$withdrawals = fetch_all('SELECT * FROM withdrawals WHERE user_id = ? ORDER BY requested_at DESC', [$id]);

require __DIR__ . '/../includes/admin_header.php';
?>

<div class="flex-between mb-3 flex-wrap">
    <div class="flex">
        <div class="avatar" style="width:56px;height:56px;font-size:1.3rem"><?= e(strtoupper(mb_substr($creator['full_name'], 0, 1))) ?></div>
        <div>
            <h1 style="margin-bottom:2px"><?= e($creator['full_name']) ?></h1>
            <p class="muted mb-0"><?= e($creator['email']) ?> &middot; <?= e($creator['phone']) ?> &middot; <?= e($creator['country'] ?: '—') ?></p>
            <?php require_once __DIR__ . '/../includes/signup_source.php'; $srcLine = signup_source_line($creator); ?>
            <p class="muted small mb-0">Heard about us: <?= e($srcLine !== '' ? $srcLine : 'not answered yet') ?></p>
            <?php require_once __DIR__ . '/../includes/brain_watch.php'; if (!empty($creator['brain_tag']) && isset(brain_tag_labels()[$creator['brain_tag']])): [$bn, $bt] = brain_tag_labels()[$creator['brain_tag']]; ?>
                <p class="small mb-0">Brain: <span class="badge <?= e($bt) ?>"><?= e($bn) ?></span> <span class="muted"><?= e((string)$creator['brain_tag_why']) ?></span></p>
            <?php endif; ?>
            <?php require_once __DIR__ . '/../includes/brain_mind.php';
            $nextStep = mind_next_step(['qualified' => (int)$creator['qualified'], 'age_days' => (int)floor((time() - strtotime((string)$creator['created_at'])) / 86400),
                'has_tiktok' => (int)(bool)fetch_val('SELECT 1 FROM tiktok_accounts WHERE user_id = ? LIMIT 1', [(int)$creator['id']], 0),
                'clips_ever' => (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE user_id = ?', [(int)$creator['id']], 0), 'brain_tag' => (string)($creator['brain_tag'] ?? '')]); ?>
            <p class="small mb-0">Next step that would help: <strong><?= e($nextStep) ?></strong></p>
            <?php if (!empty($creator['clip_ban_until']) && strtotime((string)$creator['clip_ban_until']) > time()): ?>
                <p class="small mb-0"><span class="badge bad">No clips until <?= e(date('D j M, H:i', strtotime((string)$creator['clip_ban_until']))) ?></span> <span class="muted"><?= e((string)($creator['clip_ban_reason'] ?? '')) ?></span></p>
            <?php endif; ?>
            <?php if ((int)($creator['clip_trust'] ?? 0) === 1 || ($creator['clip_trust_override'] ?? null) !== null): ?>
                <p class="small mb-0">Clips a day: <strong><?= ($creator['clip_trust_override'] ?? null) !== null ? ((int)$creator['clip_trust_override'] === 1 ? '1 (set by you)' : 'normal (set by you)') : '1 for now' ?></strong> <span class="muted"><?= e((string)($creator['clip_trust_why'] ?? '')) ?></span></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="flex flex-wrap">
        <a class="btn btn--primary btn--sm" href="<?= e(u('admin/messages.php?user=' . (int)$creator['id'])) ?>">Message</a>
        <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn btn--primary btn--sm" type="submit">&#8635; Sync views</button></form>
        <?php if ((int)$creator['qualified'] !== 1): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="qualify"><button class="btn btn--ok btn--sm" type="submit">Qualify</button></form>
        <?php else: ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="revoke"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Revoke qualification?">Revoke</button></form>
        <?php endif; ?>
        <?php if (!empty($creator['clip_ban_until']) && strtotime((string)$creator['clip_ban_until']) > time()): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="clip_unpause"><button class="btn btn--ghost btn--sm" type="submit">Lift clip pause</button></form>
        <?php endif; ?>
        <?php if ($creator['status'] === 'suspended'): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="activate"><button class="btn btn--sm" type="submit">Activate</button></form>
        <?php else: ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="suspend"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Suspend this account?">Suspend</button></form>
        <?php endif; ?>
    </div>
</div>

<details class="panel mb-3" style="padding:0" id="clipPause">
    <summary class="panel__head" style="cursor:pointer"><h3>Pause from clips</h3><span class="muted small">for breaking the clip rules; money is not touched</span></summary>
    <div class="panel__body">
        <form method="post" class="flex flex-wrap" style="gap:10px;align-items:flex-end">
            <?= csrf_field() ?><input type="hidden" name="action" value="clip_pause">
            <div class="field" style="margin:0"><label class="label" for="plen">How long</label><input class="input" id="plen" type="number" name="length" min="1" max="720" value="3" style="width:90px"></div>
            <div class="field" style="margin:0"><label class="label" for="punit">&nbsp;</label><select class="input" id="punit" name="unit"><option value="days">days</option><option value="hours">hours</option></select></div>
            <div class="field" style="margin:0;flex:1;min-width:240px"><label class="label" for="preason">Reason (the creator reads this)</label><input class="input" id="preason" name="reason" maxlength="480" required></div>
            <button class="btn btn--danger btn--sm" type="submit" data-confirm="Pause this creator from taking and sending clips?">Pause</button>
        </form>
    </div>
</details>

<div class="stats-grid mb-3">
    <div class="stat-card stat-card--accent"><div class="stat-card__label">Total earned</div><div class="stat-card__value"><?= money($stats['earned']) ?></div><div class="stat-card__meta">balance <?= money($stats['balance']) ?></div></div>
    <div class="stat-card"><div class="stat-card__label">Views</div><div class="stat-card__value"><?= e(num($stats['views'])) ?></div><div class="stat-card__meta"><?= e(num($stats['links'])) ?> links</div></div>
    <div class="stat-card"><div class="stat-card__label">Assessment</div><div class="stat-card__value"><?= $creator['assessment_score'] !== null ? (int)$creator['assessment_score'] . '%' : '—' ?></div><div class="stat-card__meta"><?= e(str_replace('_', ' ', $creator['assessment_status'])) ?></div></div>
    <div class="stat-card"><div class="stat-card__label">Status</div><div class="stat-card__value" style="font-size:1.2rem"><?= (int)$creator['qualified'] === 1 ? 'Qualified' : 'Pending' ?></div><div class="stat-card__meta"><?= e($creator['status']) ?></div></div>
</div>

<?php
// TikTok page score (the creator sees the same on their Profile).
$psJ = !empty($creator['page_score_json']) ? (json_decode((string)$creator['page_score_json'], true) ?: null) : null;
if ($psJ): ?>
<div class="panel"><div class="panel__head"><h3>TikTok page score</h3><span class="badge <?= ($psJ['score'] ?? 0) >= 60 ? 'ok' : (($psJ['score'] ?? null) === null ? '' : (($psJ['score'] ?? 0) >= 40 ? 'warn' : 'bad')) ?>"><?= ($psJ['score'] ?? null) === null ? 'No score yet' : (int)$psJ['score'] . ' / 100 · ' . e((string)$psJ['label']) ?></span></div>
    <div class="panel__body">
        <p class="small" style="margin-top:0"><?= e((string)($psJ['summary'] ?? '')) ?></p>
        <?php foreach ((array)($psJ['parts'] ?? []) as $pp): ?>
            <div class="flex-between small" style="gap:10px;padding:5px 0;border-bottom:1px solid var(--ui-line)"><span><strong><?= e((string)$pp['name']) ?></strong><br><span class="muted"><?= e((string)$pp['text']) ?></span></span><strong style="white-space:nowrap"><?= (int)$pp['pts'] ?> / <?= (int)$pp['max'] ?></strong></div>
        <?php endforeach; ?>
        <p class="small muted" style="margin-bottom:0">From the last 20 videos on their TikTok page<?= !empty($creator['page_score_at']) ? ', read ' . e(time_ago((string)$creator['page_score_at'])) : '' ?>.</p>
    </div>
</div>
<?php endif; ?>
<?php
$clipCount = count($clips);
$clipViews = 0; $clipEarned = 0.0; $clipLocked = 0.0; $clipPending = 0;
foreach ($clips as $cl) {
    $clipViews += max(0, (int)$cl['views'] - (int)($cl['start_views'] ?? $cl['views']));
    if ($cl['status'] === 'approved') { $clipEarned += (float)$cl['earned']; }
    if ($cl['status'] === 'pending') { $clipLocked += (float)$cl['earned']; $clipPending++; }
}
require_once __DIR__ . '/../includes/tiktok.php';
$cTt = tiktok_configured() ? tiktok_creator_status($id) : null;
?>
<?php if ($cTt): ?>
    <p class="small mb-2"><?= icon('tiktok') ?> TikTok:
        <?php foreach ($cTt['accounts'] as $tx): ?>
            <span class="badge <?= $tx['problem'] === null ? 'ok' : 'bad' ?>">@<?= e($tx['row']['username'] ?: '?') ?>: <?= $tx['problem'] === null ? 'working' : 'must reconnect (' . e(tiktok_problem_label($tx['problem'])) . ')' ?></span>
            <?php if (array_key_exists('bio', $tx['row'])): require_once __DIR__ . '/../includes/ready_clips.php'; ?>
                <span class="small">Bio as TikTok gives it<?= !empty($tx['row']['bio_checked_at']) ? ' (read ' . e(time_ago((string)$tx['row']['bio_checked_at'])) . ')' : '' ?>: <q><?= e($tx['row']['bio'] === null ? 'not read yet' : (trim((string)$tx['row']['bio']) === '' ? 'empty' : mb_substr((string)$tx['row']['bio'], 0, 200))) ?></q>
                <span class="badge <?= ready_bio_ok((string)$tx['row']['bio']) ? 'ok' : 'warn' ?>"><?= ready_bio_ok((string)$tx['row']['bio']) ? 'tag found' : 'no tag' ?></span></span>
            <?php endif; ?>
        <?php endforeach; ?>
        <?php if (!$cTt['accounts']): ?><span class="badge warn">Not connected</span><?php endif; ?>
        <?php if ($cTt['unreadable'] > 0): ?><span class="muted"><?= $cTt['unreadable'] ?> TikTok clip<?= $cTt['unreadable'] === 1 ? '' : 's' ?> cannot be read. <?= e(implode(' ', tiktok_needs_text($cTt['needs']))) ?> They see this on their Home page.</span><?php endif; ?>
        <?php if ($cTt['unreadable'] > 0 || !$cTt['accounts']): ?>
            <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="tiktok_help"><button class="btn btn--tiktok btn--sm" type="submit">Send them a "Connect TikTok" message</button></form>
        <?php endif; ?>
    </p>
<?php endif; ?>
<div class="panel mb-3" id="clips">
    <div class="panel__head">
        <h3>Campaign clips</h3>
        <span class="small muted"><?= e(num($clipCount)) ?> clip<?= $clipCount === 1 ? '' : 's' ?> &middot; <?= e(num($clipViews)) ?> views counted &middot; <?= money($clipEarned) ?> earned<?= $clipPending ? ' &middot; ' . e(num($clipPending)) . ' waiting for you (' . money($clipLocked) . ' locked)' : '' ?></span>
    </div>
    <div class="panel__body panel__body--flush">
        <?php if (!$clips): ?>
            <div class="empty"><p class="muted mb-0">No campaign clips sent yet.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Video</th><th>Campaign</th><th>Sent</th><th class="num">Views</th><th class="num">Money</th><th>Status</th><th>Update</th></tr></thead>
                <tbody>
                <?php foreach ($clips as $cl):
                    $start = $cl['start_views'];
                    $counted = max(0, (int)$cl['views'] - (int)($start ?? $cl['views'])); ?>
                    <tr>
                        <td>
                            <span class="flex"><?= platform_icon($cl['platform']) ?> <?= safe_link((string)$cl['url'], 'Watch video') ?></span>
                            <div class="small muted truncate" style="max-width:260px"><?= e(preg_replace('~^https?://(www\.)?~', '', (string)$cl['url'])) ?></div>
                        </td>
                        <td class="small"><?= e($cl['campaign_title']) ?></td>
                        <td class="small muted"><?= e(date('j M, H:i', strtotime((string)$cl['submitted_at']))) ?></td>
                        <td class="num">
                            <strong><?= e(num((int)$cl['views'])) ?></strong>
                            <div class="small muted"><?= $start === null ? 'not read yet' : e(num($counted)) . ' counted' . ((int)$start > 0 ? ' (had ' . e(num((int)$start)) . ' when sent)' : '') ?></div>
                            <?php if ($cl['last_fetched_at']): ?><div class="small muted">checked <?= e(time_ago($cl['last_fetched_at'])) ?></div><?php endif; ?>
                        </td>
                        <td class="num"><?= money((float)$cl['earned']) ?><?php if ($cl['status'] === 'pending' && (float)$cl['earned'] > 0): ?><div class="small muted">locked until approved</div><?php endif; ?></td>
                        <td>
                            <span class="badge <?= status_class($cl['status']) ?>"><?= e($cl['status'] === 'pending' ? 'waiting' : $cl['status']) ?></span>
                            <div class="flex mt-1" style="gap:6px;flex-wrap:wrap">
                                <?php if ($cl['status'] !== 'approved'): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clip_status"><input type="hidden" name="submission_id" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="submission_status" value="approved"><button class="btn btn--ok btn--sm" type="submit">Approve</button></form>
                                <?php endif; ?>
                                <?php if ($cl['status'] !== 'rejected'): ?>
                                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="clip_status"><input type="hidden" name="submission_id" value="<?= (int)$cl['id'] ?>"><input type="hidden" name="submission_status" value="rejected"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Reject this clip? It will earn nothing.">Reject</button></form>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <?php if ($cl['status'] !== 'rejected'): ?>
                            <form method="post" class="flex" style="gap:6px;flex-wrap:wrap;align-items:flex-end">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="clip_views">
                                <input type="hidden" name="submission_id" value="<?= (int)$cl['id'] ?>">
                                <label class="small">Views now<br><input class="input input--sm" type="number" name="views" min="<?= (int)$cl['views'] ?>" value="<?= (int)$cl['views'] ?>" style="width:110px" required></label>
                                <?php if ($start === null): ?>
                                    <label class="small">Views when sent<br><input class="input input--sm" type="number" name="start_views" min="0" placeholder="if known" style="width:110px" title="Leave empty to start counting from the views you type now (only views after today earn)"></label>
                                <?php endif; ?>
                                <button class="btn btn--primary btn--sm" type="submit">Save</button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small muted" style="padding:10px 18px;margin:0;border-top:1px solid var(--line)">Views are read automatically where the platform allows it (TikTok: only after the creator connects TikTok on their Home page). For a clip that is not read, type the views you see on the platform and press Save. Only views gained after the clip was sent earn money, and money stays locked until you approve the clip.</p>
        <?php endif; ?>
    </div>
</div>

<div class="panel mb-3">
    <div class="panel__head"><h3>Edit creator profile</h3><span class="small muted">Shown in the creator's dashboard</span></div>
    <div class="panel__body">
        <form method="post" class="form-grid">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="field">
                <label class="label" for="full_name">Full name</label>
                <input class="input" type="text" id="full_name" name="full_name" value="<?= e($creator['full_name']) ?>" required>
            </div>
            <div class="field">
                <label class="label" for="email">Email</label>
                <input class="input" type="email" id="email" name="email" value="<?= e($creator['email']) ?>" required>
            </div>
            <div class="field">
                <label class="label" for="phone">Phone</label>
                <input class="input" type="text" id="phone" name="phone" value="<?= e($creator['phone']) ?>">
            </div>
            <div class="field">
                <label class="label" for="country">Country</label>
                <input class="input" type="text" id="country" name="country" value="<?= e($creator['country'] ?? '') ?>">
            </div>
            <div class="field">
                <label class="label" for="social_handle">Social handle</label>
                <input class="input" type="text" id="social_handle" name="social_handle" value="<?= e($creator['social_handle'] ?? '') ?>" placeholder="@handle">
            </div>
            <div class="field">
                <label class="label" for="avatar_url">Avatar URL</label>
                <input class="input" type="url" id="avatar_url" name="avatar_url" value="<?= e($creator['avatar_url'] ?? '') ?>" placeholder="https://...">
            </div>
            <div class="field">
                <label class="label" for="followers_count">Followers</label>
                <input class="input" type="number" id="followers_count" name="followers_count" min="0" value="<?= (int)$creator['followers_count'] ?>">
            </div>
            <div class="field">
                <label class="label" for="new_password">New password <span class="muted small">(leave blank to keep)</span></label>
                <input class="input" type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8">
            </div>
            <div class="field" style="grid-column:1/-1">
                <label class="label" for="bio">Bio</label>
                <textarea class="textarea" id="bio" name="bio" rows="3"><?= e($creator['bio'] ?? '') ?></textarea>
            </div>
            <div class="form-actions" style="grid-column:1/-1">
                <button class="btn btn--primary" type="submit">Save profile</button>
            </div>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>Work submissions</h3><span class="badge"><?= e(num(count($portfolio))) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$portfolio): ?>
            <div class="empty"><p class="muted mb-0">No work submitted yet.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Platform</th><th>Link</th><th>Status</th><th>Submitted</th><th>Decision</th></tr></thead>
                <tbody>
                <?php foreach ($portfolio as $p): ?>
                    <tr>
                        <td><span class="flex"><?= platform_icon($p['platform']) ?> <span class="small"><?= e(platform_label($p['platform'])) ?></span></span></td>
                        <td class="truncate"><?= safe_link((string)$p['url']) ?>
                            <?php if ($p['note']): ?><div class="small muted"><?= e($p['note']) ?></div><?php endif; ?>
                            <?php if ($p['admin_note']): ?><div class="small" style="color:#92400e">Admin: <?= e($p['admin_note']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="badge <?= status_class($p['status']) ?>"><?= e($p['status']) ?></span></td>
                        <td class="small muted"><?= e(time_ago($p['created_at'])) ?></td>
                        <td>
                            <?php if ($p['status'] === 'pending'): ?>
                            <div class="flex" style="gap:6px">
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="portfolio_approve"><input type="hidden" name="portfolio_id" value="<?= (int)$p['id'] ?>"><button class="btn btn--ok btn--sm" type="submit">Approve</button></form>
                                <form method="post" style="display:inline" class="flex"><?= csrf_field() ?><input type="hidden" name="action" value="portfolio_reject"><input type="hidden" name="portfolio_id" value="<?= (int)$p['id'] ?>"><input class="input" name="admin_note" placeholder="Reason" style="width:130px;padding:6px 10px"><button class="btn btn--danger btn--sm" type="submit">Reject</button></form>
                            </div>
                            <?php else: ?><span class="small muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>Submitted video links</h3><span class="badge"><?= e(num($stats['links'])) ?></span></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$links): ?>
            <div class="empty"><p class="muted mb-0">No content links submitted.</p></div>
        <?php else: ?>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Platform</th><th>Link</th><th class="num">Views</th><th class="num">Earned</th><th>Status</th><th>Last checked</th><th>Manage</th></tr></thead>
                <tbody>
                <?php foreach ($links as $l): ?>
                    <tr>
                        <td><span class="flex"><?= platform_icon($l['platform']) ?> <span class="small"><?= e(platform_label($l['platform'])) ?></span></span></td>
                        <td class="truncate"><?= safe_link((string)$l['url'], (string)($l['title'] ?: $l['url'])) ?></td>
                        <td class="num"><?= e(num($l['views'])) ?></td>
                        <td class="num"><?= money($l['earned']) ?></td>
                        <td><span class="badge <?= status_class($l['status']) ?>"><?= e($l['status']) ?></span></td>
                        <td class="small muted"><?= e(time_ago($l['last_fetched_at'])) ?></td>
                        <td>
                            <details>
                                <summary class="small">Edit</summary>
                                <form method="post" class="mt-1" style="display:flex;gap:6px;flex-wrap:wrap">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="link_update">
                                    <input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>">
                                    <input class="input input--sm" type="number" name="views" min="0" value="<?= (int)$l['views'] ?>" style="width:100px" title="Views">
                                    <input class="input input--sm" type="number" name="earned" min="0" step="0.01" value="<?= e(number_format((float)$l['earned'], 2, '.', '')) ?>" style="width:100px" title="Earned">
                                    <select class="select select--sm" name="status" style="width:auto">
                                        <option value="active" <?= $l['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                        <option value="flagged" <?= $l['status'] === 'flagged' ? 'selected' : '' ?>>Flagged</option>
                                    </select>
                                    <button class="btn btn--ghost btn--sm" type="submit">Save</button>
                                </form>
                            </details>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <div style="padding:14px 18px;border-top:1px solid var(--line)">
            <details>
                <summary class="small">Add a link manually</summary>
                <form method="post" class="form-grid mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="link_add">
                    <div class="field">
                        <label class="label" for="add_platform">Platform</label>
                        <select class="select" id="add_platform" name="platform">
                            <?php foreach (platforms() as $k => $label): ?>
                                <option value="<?= e($k) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label class="label" for="add_url">Link URL</label>
                        <input class="input" type="url" id="add_url" name="url" placeholder="https://..." required>
                    </div>
                    <div class="field">
                        <label class="label" for="add_title">Title</label>
                        <input class="input" type="text" id="add_title" name="title" placeholder="Optional">
                    </div>
                    <div class="field">
                        <label class="label" for="add_views">Views</label>
                        <input class="input" type="number" id="add_views" name="views" min="0" value="0">
                    </div>
                    <div class="field">
                        <label class="label" for="add_earned">Earned (K)</label>
                        <input class="input" type="number" id="add_earned" name="earned" min="0" step="0.01" value="0">
                    </div>
                    <div class="form-actions" style="grid-column:1/-1">
                        <button class="btn btn--primary btn--sm" type="submit">Add link</button>
                    </div>
                </form>
            </details>
        </div>
    </div>
</div>

<div class="grid-2">
    <div class="panel">
        <div class="panel__head"><h3>Assessment attempts</h3><form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="action" value="reset_assessment"><button class="btn btn--ghost btn--sm" type="submit" data-confirm="Reset assessment?">Reset</button></form></div>
        <div class="panel__body panel__body--flush">
            <?php if (!$attempts): ?><div class="empty"><p class="muted mb-0">No attempts.</p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($attempts as $a): ?>
                <tr><td class="small muted"><?= e(date('M j, Y g:ia', strtotime($a['taken_at']))) ?></td><td class="num"><?= e($a['percentage']) ?>%</td><td><span class="badge <?= $a['passed'] ? 'ok' : 'bad' ?>"><?= $a['passed'] ? 'Passed' : 'Failed' ?></span></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Withdrawals</h3><a class="small" href="<?= e(u('admin/withdrawals.php')) ?>">Manage</a></div>
        <div class="panel__body panel__body--flush">
            <?php if (!$withdrawals): ?><div class="empty"><p class="muted mb-0">No withdrawal requests.</p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table"><tbody>
            <?php foreach ($withdrawals as $w): ?>
                <tr><td class="num"><?= money($w['amount']) ?></td><td class="small"><?= e($w['method']) ?></td><td><span class="badge <?= status_class($w['status']) ?>"><?= e($w['status']) ?></span></td><td class="small muted"><?= e(time_ago($w['requested_at'])) ?></td></tr>
            <?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($creator['status'] === 'suspended'): $delBlock = hpl_account_delete_blocker($creator); ?>
<div class="panel" style="border-color:#f3c7c2">
    <div class="panel__head"><h3>Delete account</h3></div>
    <div class="panel__body">
        <p class="small muted mb-2">Removes this account and everything it created: clips, earnings, messages, community posts, comments and notifications. Use it for demo, test and spam accounts. This cannot be undone.</p>
        <?php if ($delBlock !== null): ?>
            <p class="small mb-0" style="color:#92400e"><?= e($delBlock) ?></p>
        <?php else: ?>
            <form method="post" data-confirm="Delete this account and everything it created? This cannot be undone."><?= csrf_field() ?><input type="hidden" name="action" value="delete_account"><button class="btn btn--danger btn--sm" type="submit">Delete account for good</button></form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
