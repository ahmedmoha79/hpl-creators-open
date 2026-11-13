<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/verification.php';
$user = require_creator();
$pageTitle = 'Submit your work';

$plats = platforms();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $platform = post('platform');
    $url = normalize_url(post('url'));
    $note = mb_substr(post('note'), 0, 250);

    if (($user['assessment_status'] ?? '') !== 'passed' && (int)$user['qualified'] !== 1) {
        flash('warning', 'First pass the short test. Then send us your video.');
        redirect('creator/assessment.php');
    } elseif ((int)$user['qualified'] !== 1 && ($user['portfolio_status'] ?? '') === 'pending') {
        flash('warning', 'We are already checking your video. You will get a notification soon, usually within 24 hours.');
        redirect('creator/index.php');
    } elseif (!array_key_exists($platform, $plats)) {
        flash('error', 'Please choose a valid platform.');
    } elseif (!$url) {
        flash('error', 'Please enter a valid link to your video or social post.');
    } elseif (($platformErr = hpl_url_platform_error($platform, hpl_resolve_url($url))) !== null) {
        flash('error', $platformErr);
    } else {
        q(
            'INSERT INTO portfolio_links (user_id, platform, url, note, status) VALUES (?,?,?,?, "pending")',
            [(int)$user['id'], $platform, $url, $note]
        );
        $newPid = (int)db()->lastInsertId();
        notif_admins('portfolio', 'New work submitted', $user['full_name'] . ' submitted a ' . $platform . ' link for review.', 'admin/portfolio.php', ['type' => 'work', 'id' => $newPid]);
        if ((int)$user['qualified'] !== 1) {
            q('UPDATE users SET portfolio_status = "pending", rejection_note = NULL WHERE id = ?', [(int)$user['id']]);
        }
        flash('success', 'Work submitted! Our team will review it shortly.');
        redirect('creator/index.php');
    }
}

$links = fetch_all('SELECT * FROM portfolio_links WHERE user_id = ? ORDER BY created_at DESC', [(int)$user['id']]);

require __DIR__ . '/../includes/creator_header.php';
?>

<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Submit your work</h1>
        <p class="muted mb-0">Share a link to any video or social post you've created. Approved work qualifies your account.</p>
    </div>
    <?php
    $ps = $user['portfolio_status'];
    $cls = $ps === 'approved' ? 'ok' : ($ps === 'pending' ? 'warn' : ($ps === 'rejected' ? 'bad' : 'muted'));
    ?>
    <span class="badge <?= $cls ?>" style="padding:8px 14px"><?= e(ucfirst($ps)) ?></span>
</div>

<?php if ((int)$user['qualified'] === 1): ?>
    <div class="alert alert--ok">
        <h3>&#10003; You're qualified!</h3>
        <p class="mb-0">Your account is approved. Open <a href="<?= e(u('creator/index.php?tab=campaigns')) ?>">Campaigns</a>, join one, download its footage and start earning.</p>
    </div>
<?php elseif ($user['portfolio_status'] === 'pending'): ?>
    <div class="alert alert--warn">
        <p class="mb-0"><strong>Under review.</strong> Our team is checking your submission. You'll be notified in your dashboard once it's approved.</p>
    </div>
<?php elseif ($user['portfolio_status'] === 'rejected'): ?>
    <div class="alert alert--warn">
        <p class="mb-0"><strong>Not approved.</strong> <?= e($user['rejection_note'] ?: 'Please send a short video (15 to 60 seconds) that you edited yourself, showing the most exciting moment.') ?> You can submit another link below.</p>
    </div>
<?php endif; ?>

<div class="grid-2 grid-2--wide">
    <div class="panel">
        <div class="panel__head"><h3>Add a work sample</h3></div>
        <div class="panel__body">
            <?php if ((int)$user['qualified'] !== 1 && ($user['assessment_status'] ?? '') !== 'passed'): ?>
                <p class="mb-0">First pass the short test. Then you can send us your video here.</p>
                <a class="btn btn--primary btn--block mt-2" href="<?= e(u('creator/assessment.php')) ?>">Take the test</a>
            <?php elseif ((int)$user['qualified'] !== 1 && ($user['portfolio_status'] ?? '') === 'pending'): ?>
                <p class="mb-0">We are already checking your video. You will get a notification soon, usually within 24 hours.</p>
            <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <div class="field">
                    <label class="label" for="platform">Platform</label>
                    <select class="select" id="platform" name="platform">
                        <?php foreach ($plats as $k => $label): ?>
                            <option value="<?= e($k) ?>"><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label class="label" for="url">Link to your video / post</label>
                    <input class="input" type="text" id="url" name="url" placeholder="https://..." required>
                    <div class="field__hint">Paste a public link we can view without logging in.</div>
                </div>
                <div class="field">
                    <label class="label" for="note">Short description <span class="muted">(optional)</span></label>
                    <textarea class="textarea" id="note" name="note" rows="3" placeholder="What did you create? What results did it get?"></textarea>
                </div>
                <button class="btn btn--primary btn--block" type="submit">Submit for approval</button>
            </form>
            <?php endif; ?>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head"><h3>Your submissions</h3><span class="badge"><?= count($links) ?></span></div>
        <div class="panel__body panel__body--flush">
            <?php if (!$links): ?>
                <div class="empty"><div class="empty__icon"><?= icon('folder') ?></div><p>No work samples submitted yet.</p></div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="table">
                        <thead><tr><th>Platform</th><th>Link</th><th>Status</th><th>Submitted</th></tr></thead>
                        <tbody>
                        <?php foreach ($links as $l): ?>
                            <tr>
                                <td><span class="flex"><?= platform_icon($l['platform']) ?> <span class="small"><?= e(platform_label($l['platform'])) ?></span></span></td>
                                <td class="truncate"><?= safe_link((string)$l['url']) ?>
                                    <?php if ($l['note']): ?><div class="small muted"><?= e($l['note']) ?></div><?php endif; ?>
                                </td>
                                <td><span class="badge <?= status_class($l['status']) ?>"><?= e($l['status']) ?></span></td>
                                <td class="small muted"><?= e(time_ago($l['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
