<?php
/**
 * Checks (2026-11-10): what the website noticed by itself, with its reasons, for the HPL team to decide.
 *  - Clips whose views do not look real (includes/integrity.php scores every clip each time views are read).
 *  - Clips that disappeared from TikTok.
 *  - Phones used by more than one creator account.
 * The website holds a high-risk clip (money locked, nothing removed). Only a person rejects.
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/integrity.php';
require_once __DIR__ . '/../includes/charts.php';
$user = require_admin();
$pageTitle = 'Checks';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sid = (int)post('submission_id');
    $action = post('action');
    if ($action === 'ok' && $sid > 0) {
        // "This one is fine": never held again by the formula, and approved.
        q('UPDATE campaign_submissions SET risk_ok = 1, held_by_system = 0 WHERE id = ?', [$sid]);
        campaign_submission_set_status($sid, 'approved');
        flash('success', 'Marked as fine and approved. The website will not hold this clip again.');
    } elseif ($action === 'reject' && $sid > 0) {
        if (campaign_submission_set_status($sid, 'rejected', 'The views on this clip did not pass our checks.')) {
            q('UPDATE campaign_submissions SET held_by_system = 0 WHERE id = ?', [$sid]);
            flash('success', 'Clip rejected. Its earnings were removed.' . (function_exists('campaign_debt_note') ? campaign_debt_note($sid) : ''));
        }
    } elseif ($action === 'settings') {
        set_setting('risk_auto_hold', post('risk_auto_hold') === '1' ? '1' : '0');
        set_setting('risk_high', (string)max(30, min(100, (int)post('risk_high'))));
        flash('success', 'Saved.');
    }
    redirect('admin/risk.php?tab=' . urlencode(get('tab', 'views')));
}

$tab = in_array(get('tab'), ['views', 'missing', 'phones'], true) ? get('tab') : 'views';
$base = 'SELECT s.*, u.full_name, c.title AS campaign_title FROM campaign_submissions s JOIN users u ON u.id = s.user_id JOIN campaigns c ON c.id = s.campaign_id';
$flagged = fetch_all($base . ' WHERE s.risk_level IS NOT NULL AND s.status <> "rejected" AND s.risk_ok = 0 ORDER BY s.risk_score DESC, s.id DESC LIMIT 100');
$missing = fetch_all($base . ' WHERE s.miss_count >= 1000 AND s.status <> "rejected" ORDER BY s.missing_since DESC LIMIT 100');
$phones = [];
try {
    foreach (fetch_all('SELECT d.device, COUNT(DISTINCT d.user_id) n, MAX(d.last_seen) last_seen FROM user_devices d JOIN users u ON u.id = d.user_id AND u.role = "creator"
                         GROUP BY d.device HAVING n > 1 ORDER BY n DESC, last_seen DESC LIMIT 60') as $d) {
        $d['users'] = fetch_all('SELECT u.id, u.full_name, u.email, u.status, (SELECT COALESCE(SUM(earned),0) FROM campaign_submissions s WHERE s.user_id = u.id AND s.status <> "rejected") earned
                                   FROM user_devices x JOIN users u ON u.id = x.user_id WHERE x.device = ? AND u.role = "creator" ORDER BY u.id', [$d['device']]);
        $phones[] = $d;
    }
} catch (Throwable $e) {
}
$counts = ['views' => count($flagged), 'missing' => count($missing), 'phones' => count($phones)];
$scored = (int)fetch_val('SELECT COUNT(*) FROM campaign_submissions WHERE risk_at IS NOT NULL', [], 0);

require __DIR__ . '/../includes/admin_header.php';
?>
<h1>Checks</h1>
<p class="muted">The website checks every clip each time it reads the views: how many people liked, commented and shared, and how the views arrived. Real views bring reactions with them; bought views arrive in blocks and bring nothing. Here is what it found. <strong>Creators never see these reasons.</strong></p>

<div class="flex" style="gap:8px;flex-wrap:wrap;margin:14px 0">
    <?php foreach (['views' => 'Views that look bought', 'missing' => 'Clips gone from TikTok', 'phones' => 'One phone, many accounts'] as $k => $label): ?>
        <a class="btn btn--sm <?= $tab === $k ? 'btn--primary' : 'btn--ghost' ?>" href="<?= e(u('admin/risk.php?tab=' . $k)) ?>"><?= e($label) ?><?= $counts[$k] ? ' (' . $counts[$k] . ')' : '' ?></a>
    <?php endforeach; ?>
</div>

<?php if ($tab === 'views'): ?>
    <?php if (!$flagged): ?>
        <div class="panel"><div class="panel__body"><strong>Nothing to look at.</strong> <span class="muted"><?= $scored ? num($scored) . ' clips checked so far, all look normal.' : 'Clips are checked after their views are read a few times.' ?></span></div></div>
    <?php endif; ?>
    <?php foreach ($flagged as $s):
        $info = json_decode((string)$s['risk_flags'], true) ?: ['flags' => [], 'facts' => []];
        $f = $info['facts'] + ['views' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'er' => null];
        $pts = array_column(fetch_all('SELECT views FROM clip_stats WHERE submission_id = ? ORDER BY id DESC LIMIT 60', [(int)$s['id']]), 'views');
        ?>
        <div class="panel" style="margin-bottom:14px">
            <div class="panel__head" style="flex-wrap:wrap;gap:8px">
                <h3 style="margin:0"><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$s['user_id'])) ?>"><?= e($s['full_name']) ?></a> <span class="muted small">&middot; <?= e($s['campaign_title']) ?></span></h3>
                <span class="badge <?= $s['risk_level'] === 'high' ? 'bad' : 'warn' ?>">Risk <?= (int)$s['risk_score'] ?>/100</span>
            </div>
            <div class="panel__body">
                <div class="flex" style="gap:18px;flex-wrap:wrap;align-items:center">
                    <div><div class="muted small">Views</div><strong><?= e(num((int)$f['views'])) ?></strong></div>
                    <div><div class="muted small">Likes</div><strong><?= e(num((int)$f['likes'])) ?></strong></div>
                    <div><div class="muted small">Comments</div><strong><?= e(num((int)$f['comments'])) ?></strong></div>
                    <div><div class="muted small">Shares</div><strong><?= e(num((int)$f['shares'])) ?></strong></div>
                    <div><div class="muted small">Reacted</div><strong><?= $f['er'] !== null ? e(rtrim(rtrim(number_format((float)$f['er'] * 100, 2), '0'), '.')) . '%' : '–' ?></strong> <span class="muted small">(normal about 4%)</span></div>
                    <div><div class="muted small">Earned</div><strong><?= money($s['earned']) ?></strong></div>
                    <div><?= chart_spark(array_reverse(array_map('intval', $pts))) ?></div>
                </div>
                <ul style="margin:12px 0 0;padding-left:18px">
                    <?php foreach ($info['flags'] as $fl): ?><li><?= e((string)$fl[2]) ?></li><?php endforeach; ?>
                </ul>
                <p class="small muted" style="margin:10px 0"><?= safe_link((string)$s['url']) ?> &middot; now <strong><?= e($s['status']) ?></strong><?= (int)$s['held_by_system'] === 1 ? ' (held by the website, money locked)' : '' ?> &middot; checked <?= e(time_ago((string)$s['risk_at'])) ?></p>
                <div class="flex" style="gap:8px;flex-wrap:wrap">
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="ok"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>"><button class="btn btn--primary btn--sm" type="submit">It is fine, approve</button></form>
                    <form method="post" onsubmit="return confirm('Reject this clip? Its earnings are removed.')"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>"><button class="btn btn--danger btn--sm" type="submit">Reject the clip</button></form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <details class="panel" style="padding:0">
        <summary class="panel__head" style="cursor:pointer"><h3>How strict</h3></summary>
        <div class="panel__body">
            <form method="post" class="flex" style="gap:14px;flex-wrap:wrap;align-items:flex-end">
                <?= csrf_field() ?><input type="hidden" name="action" value="settings">
                <label class="field" style="margin:0;display:flex;gap:10px;align-items:flex-start;flex-basis:100%"><input type="checkbox" name="risk_auto_hold" value="1" <?= (string)setting('risk_auto_hold', '1') === '1' ? 'checked' : '' ?> style="width:22px;height:22px">
                    <span><strong>Hold high-risk clips automatically</strong><br><span class="small muted">The clip's money is locked until you decide here. Nothing is removed and the creator is only told "we are checking".</span></span></label>
                <label class="field" style="margin:0">High risk starts at<input class="input" type="number" name="risk_high" min="30" max="100" value="<?= (int)setting('risk_high', '60') ?>" style="width:110px"></label>
                <button class="btn btn--primary btn--sm" type="submit">Save</button>
            </form>
        </div>
    </details>
<?php elseif ($tab === 'missing'): ?>
    <div class="panel"><div class="panel__body">
        <?php if (!$missing): ?><strong>No clip is missing.</strong> <span class="muted">When TikTok stops showing a clip (deleted or made private) it appears here and the creator is told.</span><?php else: ?>
        <div style="overflow-x:auto"><table class="table"><thead><tr><th>Creator</th><th>Campaign</th><th>Link</th><th>Gone since</th><th class="num">Earned</th><th></th></tr></thead><tbody>
        <?php foreach ($missing as $s): ?>
            <tr><td><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$s['user_id'])) ?>"><?= e($s['full_name']) ?></a></td><td class="small"><?= e($s['campaign_title']) ?></td><td class="truncate"><?= safe_link((string)$s['url']) ?></td>
                <td class="small"><?= e(time_ago((string)$s['missing_since'])) ?></td><td class="num"><?= money($s['earned']) ?></td>
                <td><form method="post" onsubmit="return confirm('Reject this clip? Its earnings are removed.')"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="submission_id" value="<?= (int)$s['id'] ?>"><button class="btn btn--danger btn--sm" type="submit">Reject</button></form></td></tr>
        <?php endforeach; ?>
        </tbody></table></div>
        <p class="small muted" style="margin-bottom:0">A missing clip earns nothing while it is gone. If the creator deleted it to keep the money, reject it.</p>
        <?php endif; ?>
    </div></div>
<?php else: ?>
    <div class="panel"><div class="panel__body">
        <?php if (!$phones): ?><strong>No phone is used by two creator accounts.</strong> <span class="muted">Families sometimes share a phone, so this is a reason to look, not proof.</span><?php else: ?>
        <p class="small muted" style="margin-top:0">Each box is one phone or computer that signed in to more than one creator account. Families and friends share phones, so look before you act: same payout number, same TikTok style, clips taken minutes apart.</p>
        <?php foreach ($phones as $d): ?>
            <div style="border:1px solid var(--ui-line,#e6ded3);border-radius:12px;padding:12px;margin-bottom:10px">
                <div class="small muted" style="margin-bottom:6px"><?= (int)$d['n'] ?> accounts &middot; last used <?= e(time_ago((string)$d['last_seen'])) ?></div>
                <?php foreach ($d['users'] as $x): ?>
                    <div class="flex" style="gap:10px;flex-wrap:wrap;padding:4px 0"><a href="<?= e(u('admin/creator_detail.php?id=' . (int)$x['id'])) ?>"><strong><?= e($x['full_name']) ?></strong></a><span class="small muted"><?= e($x['email']) ?></span><span class="small">earned <?= money($x['earned']) ?></span><?php if ($x['status'] !== 'active'): ?><span class="badge warn"><?= e($x['status']) ?></span><?php endif; ?></div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
