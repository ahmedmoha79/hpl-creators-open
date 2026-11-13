<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/creator_stages.php';
$user = require_admin();
$pageTitle = 'Send a message';

$stages = creator_stages();
// Pages a message can open when the creator taps it.
$destinations = [
    'creator/index.php'                => 'Their home page',
    'creator/index.php?tab=campaigns'  => 'Campaigns',
    'creator/library.php'              => 'Footage to download',
    'creator/submit_links.php'         => 'My clips (send a clip link)',
    'creator/assessment.php'           => 'The HPL test',
    'creator/portfolio.php'            => 'Send a work sample',
    'creator/course.php'               => 'Creator Academy',
    'creator/earnings.php'             => 'Earnings',
    'creator/withdraw.php'             => 'Withdraw',
    'creator/index.php?tab=announcements' => 'Announcements',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'remind') {
        $stage = (string)post('stage');
        $sent = creators_send_reminders($stage);
        if ($sent === null) {
            flash('warning', 'That group was already reminded in the last 24 hours.');
        } else {
            flash('success', 'Reminder sent to ' . $sent . ' creator(s).');
        }
        redirect('admin/send.php');
    }
    if ($action === 'send') {
        $audience = (string)post('audience');
        if ($audience === 'one') {
            $who = fetch_one('SELECT id FROM users WHERE role = "creator" AND (email = ? OR phone = ?)', [strtolower(trim(post('one'))), trim(post('one'))]);
            $audience = $who ? 'user:' . (int)$who['id'] : '';
        }
        $title = trim(mb_substr(post('title'), 0, 120));
        $body = trim(mb_substr(post('body'), 0, 250));
        $path = array_key_exists(post('dest'), $destinations) ? post('dest') : 'creator/index.php';
        $email = post('email') === '1';
        if ($audience === '' || ($audience !== 'all' && strpos($audience, 'user:') !== 0 && !isset($stages[$audience]))) {
            flash('error', 'Choose who should get the message (for one creator, type their exact email or phone).');
        } elseif ($title === '' || $body === '') {
            flash('error', 'Write a title and a message.');
        } else {
            $n = creators_send_message($audience, $title, $body, $path, $email);
            $label = $audience === 'all' ? 'Everyone' : (strpos($audience, 'user:') === 0 ? 'One creator' : $stages[$audience]['label']);
            send_log_add($label, $title, $n, $email);
            flash('success', 'Sent to ' . $n . ' creator(s)' . ($email ? ', in the app and by email.' : ', in the app.'));
        }
        redirect('admin/send.php');
    }
}

$counts = ['all' => (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND status = "active"', [], 0)];
foreach ($stages as $k => $st) {
    $counts[$k] = (int)fetch_val('SELECT COUNT(*) FROM users WHERE role = "creator" AND ' . $st['sql'], [], 0);
}
$log = send_log();

require __DIR__ . '/../includes/admin_header.php';
?>

<h1 style="margin-bottom:4px">Send a message</h1>
<p class="muted mb-3">Reach creators in the app (the bell and a pop-up) and, if you want, by email too.</p>

<div class="sm-grid">
    <section class="panel">
        <div class="panel__head"><h3>1. Quick reminders</h3><span class="muted small">One tap. Already written for you.</span></div>
        <div class="panel__body">
            <ul class="sm-rem">
                <?php foreach ($stages as $k => $st): if (empty($st['next'])) continue;
                    $last = (int)setting('remind_' . $k . '_at', 0); $locked = $last > time() - 86400; ?>
                    <li>
                        <div class="sm-rem__txt">
                            <strong><?= e($st['label']) ?> <span class="badge <?= e($st['tone']) ?>"><?= (int)$counts[$k] ?></span></strong>
                            <span class="small muted"><?= e($st['hint']) ?></span>
                            <span class="small">&ldquo;<?= e($st['next'][0]) ?>&rdquo;</span>
                            <?php if ($last): ?><span class="small muted">Last sent <?= e(time_ago(date('Y-m-d H:i:s', $last))) ?></span><?php endif; ?>
                        </div>
                        <?php if ($counts[$k] === 0): ?>
                            <span class="small muted">Nobody here</span>
                        <?php elseif ($locked): ?>
                            <span class="small muted">Sent today</span>
                        <?php else: ?>
                            <form method="post" data-confirm="Remind <?= (int)$counts[$k] ?> creator(s): &quot;<?= e($st['next'][0]) ?>&quot;? They get it in the app and by email.">
                                <?= csrf_field() ?><input type="hidden" name="action" value="remind"><input type="hidden" name="stage" value="<?= e($k) ?>">
                                <button class="btn btn--primary btn--sm" type="submit"><?= icon('send', 'ico ico--sm') ?> Remind <?= (int)$counts[$k] ?></button>
                            </form>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </section>

    <section class="panel">
        <div class="panel__head"><h3>2. Write your own message</h3></div>
        <div class="panel__body">
            <form method="post" class="sm-form" data-confirm="Send this message now?">
                <?= csrf_field() ?><input type="hidden" name="action" value="send">
                <label class="label" for="smAud">Who gets it?</label>
                <select class="input" id="smAud" name="audience" data-sm-aud>
                    <option value="all">Everyone (<?= (int)$counts['all'] ?>)</option>
                    <?php foreach ($stages as $k => $st): if ($k === 'suspended') continue; ?>
                        <option value="<?= e($k) ?>"><?= e($st['label']) ?>: <?= e($st['hint']) ?> (<?= (int)$counts[$k] ?>)</option>
                    <?php endforeach; ?>
                    <option value="one">One creator (type email or phone)</option>
                </select>
                <input class="input mt-2" type="text" name="one" placeholder="Their email or phone" data-sm-one hidden>

                <label class="label mt-3" for="smTitle">Title</label>
                <input class="input" id="smTitle" name="title" maxlength="120" placeholder="e.g. New campaign is open" required>

                <label class="label mt-3" for="smBody">Message</label>
                <textarea class="input" id="smBody" name="body" rows="3" maxlength="250" placeholder="Short and clear. What should they do?" required></textarea>

                <label class="label mt-3" for="smDest">When they tap it, open</label>
                <select class="input" id="smDest" name="dest">
                    <?php foreach ($destinations as $path => $label): ?><option value="<?= e($path) ?>"><?= e($label) ?></option><?php endforeach; ?>
                </select>

                <label class="sm-check mt-3"><input type="checkbox" name="email" value="1" checked> Also send it by email</label>
                <button class="btn btn--primary mt-3" type="submit"><?= icon('send', 'ico ico--sm') ?> Send message</button>
            </form>
        </div>
    </section>
</div>

<section class="panel mt-3">
    <div class="panel__head"><h3>Sent recently</h3></div>
    <div class="panel__body panel__body--flush">
        <?php if (!$log): ?>
            <p class="muted" style="padding:16px 20px;margin:0">Nothing sent yet.</p>
        <?php else: ?>
            <div class="table-wrap"><table class="table"><tbody>
                <?php foreach ($log as $l): ?>
                    <tr>
                        <td><strong class="small"><?= e($l['title']) ?></strong><div class="small muted">To: <?= e($l['to']) ?></div></td>
                        <td class="small"><?= (int)$l['n'] ?> creator(s)<?= !empty($l['email']) ? ' &middot; app + email' : ' &middot; app' ?></td>
                        <td class="small muted"><?= e(time_ago(date('Y-m-d H:i:s', (int)$l['at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        <?php endif; ?>
    </div>
</section>

<style>
.sm-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 18px; align-items: start; }
@media (max-width: 900px) { .sm-grid { grid-template-columns: 1fr; } }
.sm-rem { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.sm-rem li { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px; border: 1px solid var(--ui-line, #eadfce); border-radius: 12px; }
.sm-rem__txt { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
.sm-rem form { margin: 0; flex: none; }
.sm-form .input { width: 100%; }
.sm-check { display: flex; align-items: center; gap: 8px; font-weight: 600; }
.sm-check input { width: 20px; height: 20px; }
.sm-form .btn { min-height: 46px; width: 100%; justify-content: center; }
</style>
<script>
(function () {
  var a = document.querySelector('[data-sm-aud]'), o = document.querySelector('[data-sm-one]');
  if (!a || !o) return;
  a.addEventListener('change', function () { o.hidden = a.value !== 'one'; o.required = a.value === 'one'; });
})();
</script>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
