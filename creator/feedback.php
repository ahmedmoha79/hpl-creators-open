<?php
require_once __DIR__ . '/../config.php';
$user = require_creator();
$uid = (int)$user['id'];
$pageTitle = 'Send feedback';
$types = ['idea' => 'Idea', 'bug' => 'Something is broken', 'other' => 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $type = array_key_exists(post('type'), $types) ? post('type') : 'other';
    $subject = trim((string)post('subject'));
    $message = trim((string)post('message'));
    $recent = (int)fetch_val('SELECT COUNT(*) FROM feedback WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 HOUR', [$uid], 0);
    if ($subject === '' || $message === '') {
        flash('error', 'Please add a short title and a few words about it.');
    } elseif ($recent >= 5) {
        flash('error', 'Thanks for all the feedback! Please wait a little before sending more.');
    } else {
        $title = '[' . $types[$type] . '] ' . mb_substr($subject, 0, 140);
        q('INSERT INTO feedback (user_id, subject, message) VALUES (?, ?, ?)', [$uid, mb_substr($title, 0, 160), mb_substr($message, 0, 4000)]);
        notif_admins('feedback', 'New feedback from ' . $user['full_name'], mb_substr($title, 0, 110), 'admin/feedback.php');
        flash('success', 'Thank you! The HPL team reads every message. You will be notified when it is handled.');
    }
    redirect('creator/feedback.php');
}

$mine = fetch_all('SELECT * FROM feedback WHERE user_id = ? ORDER BY created_at DESC LIMIT 20', [$uid]);
require __DIR__ . '/../includes/creator_header.php';
?>
<div class="ui-page">
    <div class="ui-page-head">
        <div>
            <h1>Send feedback</h1>
            <p>Have an idea to make HPL Creators better, or found something that does not work? Tell us here. For questions about your account, use <a href="<?= e(u('creator/support.php')) ?>">Help &amp; support</a>.</p>
        </div>
    </div>

    <section class="ui-card">
        <div class="ui-card__body">
            <form method="post">
                <?= csrf_field() ?>
                <div class="field">
                    <span class="label">What is it about?</span>
                    <div class="flex" style="gap:8px;flex-wrap:wrap">
                        <?php foreach ($types as $k => $label): ?>
                            <label class="btn btn--ghost btn--sm" style="gap:6px;cursor:pointer"><input type="radio" name="type" value="<?= e($k) ?>" <?= $k === 'idea' ? 'checked' : '' ?>> <?= e($label) ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="field">
                    <label class="label" for="subject">Title</label>
                    <input class="input" id="subject" name="subject" maxlength="140" placeholder="e.g. Let me download all footage at once" required>
                </div>
                <div class="field">
                    <label class="label" for="message">Tell us more</label>
                    <textarea class="textarea" id="message" name="message" rows="5" maxlength="4000" placeholder="What happened, or what would you like? The more detail, the faster we can help." required></textarea>
                </div>
                <button class="btn btn--primary" type="submit" data-busy="Sending…"><?= icon('send') ?>Send feedback</button>
            </form>
        </div>
    </section>

    <?php if ($mine): ?>
    <section class="ui-card">
        <div class="ui-card__head"><h2><?= icon('clock') ?>Your feedback</h2></div>
        <div class="ui-card__body">
            <?php foreach ($mine as $f): ?>
                <div style="padding:10px 0;border-top:1px solid var(--ui-line)">
                    <div class="flex" style="justify-content:space-between;gap:8px"><strong class="small"><?= e($f['subject']) ?></strong>
                        <span class="badge <?= $f['status'] === 'open' ? 'warn' : 'ok' ?>"><?= $f['status'] === 'open' ? 'Received' : 'Handled' ?></span></div>
                    <div class="small muted"><?= e(time_ago($f['created_at'])) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
