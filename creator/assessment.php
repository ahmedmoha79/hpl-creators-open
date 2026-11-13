<?php
require_once __DIR__ . '/../config.php';
$user = require_creator();
$pageTitle = 'Assessment';

$questions = fetch_all('SELECT * FROM assessment_questions WHERE is_active = 1 ORDER BY id ASC');
$total = count($questions);
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $answers = $_POST['answers'] ?? [];
    $score = 0;
    $missing = 0;
    $detail = [];
    foreach ($questions as $qz) {
        $given = is_array($answers) ? ($answers[$qz['id']] ?? '') : '';
        if ($given === '') {
            $missing++;
        }
        $isCorrect = $given === $qz['correct_option'];
        if ($isCorrect) {
            $score++;
        }
        $detail[$qz['id']] = ['given' => $given, 'correct' => $qz['correct_option'], 'ok' => $isCorrect];
    }

    if ($missing > 0) {
        flash('warning', 'Please answer every question before submitting.');
    } else {
        $percentage = $total > 0 ? (int)round(($score / $total) * 100) : 0;
        $passed = $percentage >= 80;

        q(
            'INSERT INTO assessments (user_id, score, total, percentage, passed, answers) VALUES (?,?,?,?,?,?)',
            [(int)$user['id'], $score, $total, $percentage, $passed ? 1 : 0, json_encode($detail)]
        );

        if ($passed) {
            $best = max($percentage, (int)$user['assessment_score']);
            q('UPDATE users SET assessment_status = "passed", assessment_score = ? WHERE id = ?', [$best, (int)$user['id']]);
            if ($user['assessment_status'] !== 'passed') {
                try { require_once __DIR__ . '/../includes/community.php'; chat_event((int)$user['id'], 'general', 'passed the HPL test. Say hello'); } catch (Throwable $e) {}
            }
            flash('success', 'Congratulations! You passed with ' . $percentage . '%. Next: connect your TikTok, then take your first ready clip.');
            redirect('creator/portfolio.php');
        } else {
            if ($user['assessment_status'] !== 'passed') {
                q('UPDATE users SET assessment_status = "failed", assessment_score = GREATEST(COALESCE(assessment_score,0), ?) WHERE id = ?', [$percentage, (int)$user['id']]);
            }
            $missedNums = [];
            foreach (array_values($questions) as $i => $qz) {
                if (empty($detail[$qz['id']]['ok'])) {
                    $missedNums[] = $i + 1;
                }
            }
            flash('error', 'You got ' . $score . ' of ' . $total . ' right (' . $percentage . '%). You need 80% to pass. Look again at question'
                . (count($missedNums) > 1 ? 's ' : ' ') . implode(', ', $missedNums) . '. Read each question slowly: the answers are the rules on your campaign page. You can try again as many times as you want.');
            redirect('creator/assessment.php');
        }
    }
}

$attempts = fetch_all('SELECT * FROM assessments WHERE user_id = ? ORDER BY taken_at DESC LIMIT 5', [(int)$user['id']]);
$best = (int)$user['assessment_score'];
$passed = $user['assessment_status'] === 'passed';

require __DIR__ . '/../includes/creator_header.php';
?>

<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Creator assessment</h1>
        <p class="muted mb-0"><?= $total > 0 ? 'Answer all ' . e(num($total)) . ' questions. Score <strong>80% or higher</strong> to pass.' : 'The questions are being prepared.' ?></p>
    </div>
    <?php if ($passed): ?>
        <span class="badge ok" style="font-size:.85rem;padding:8px 14px">&#10003; Passed<?= (int)$best > 0 ? ' &middot; ' . e($best) . '%' : '' ?></span>
    <?php endif; ?>
</div>

<?php if (!$passed && $total > 0): ?>
    <p class="muted small" style="margin-top:-6px">Not sure of the answers? <a href="<?= e(u('creator/course_intro.php')) ?>">Watch the free lessons first</a>, then come back.</p>
<?php endif; ?>
<?php if ($passed): ?>
    <div class="alert alert--ok">
        <h3>You've passed the assessment <?= icon('award') ?></h3>
        <p class="mb-0"><?= (int)$best > 0 ? 'Your best score is <strong>' . e($best) . '%</strong>. ' : '' ?>Next: <a href="<?= e(u('creator/tiktok_ready.php')) ?>">connect your TikTok</a>, then take your first ready clip from a campaign.</p>
    </div>
<?php else: ?>
    <div class="alert alert--warn">
        <p class="mb-0">Tip: read each question carefully. You can retake the assessment until you pass. 80% is required to unlock the library and payouts.</p>
    </div>
<?php endif; ?>

<div class="panel">
    <div class="panel__head"><h3><?= $passed ? 'Retake the assessment' : 'Answer the questions' ?></h3><span class="badge">Pass mark 80%</span></div>
    <div class="panel__body">
        <?php if (!$questions): ?>
            <div class="empty"><div class="empty__icon"><?= icon('check') ?></div><p>No questions available yet. Please check back soon.</p></div>
        <?php else: ?>
        <form method="post" id="quizForm">
            <?= csrf_field() ?>
            <?php foreach ($questions as $i => $qz): ?>
                <div class="quiz-q" data-quiz-q>
                    <span class="quiz-q__num">Question <?= $i + 1 ?> of <?= $total ?></span>
                    <div class="quiz-q__title"><?= e($qz['question']) ?></div>
                    <?php foreach (['a', 'b', 'c', 'd'] as $opt): $key = 'option_' . $opt; ?>
                        <label class="quiz-option">
                            <input type="radio" name="answers[<?= (int)$qz['id'] ?>]" value="<?= $opt ?>">
                            <span><strong><?= strtoupper($opt) ?>.</strong> <?= e($qz[$key]) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            <div class="form-actions mt-3">
                <button class="btn btn--primary" type="submit">Submit assessment</button>
                <span class="muted small"><span id="quizDone">0</span> of <?= $total ?> answered. You need <?= e((string)ceil($total * 0.8)) ?>/<?= $total ?> correct answers to pass.</span>
            </div>
        </form>
        <style>.quiz-q.is-missing { outline: 2px solid #d9822b; outline-offset: 6px; border-radius: 10px; } .quiz-q__miss { color: #b4581b; font-weight: 700; display: block; margin: 4px 0; }</style>
        <script>
        /* Before 2026-11-12 a missed question sent the whole test back empty. Now the page shows which one is missing. */
        (function () {
            var form = document.getElementById('quizForm'); if (!form) return;
            var qs = form.querySelectorAll('[data-quiz-q]'), out = document.getElementById('quizDone');
            function answered(q) { return !!q.querySelector('input[type=radio]:checked'); }
            function count() { var n = 0; qs.forEach(function (q) { if (answered(q)) { n++; q.classList.remove('is-missing'); var m = q.querySelector('.quiz-q__miss'); if (m) m.remove(); } }); if (out) out.textContent = n; }
            form.addEventListener('change', count);
            form.addEventListener('submit', function (e) {
                var first = null;
                qs.forEach(function (q) {
                    if (answered(q)) return;
                    q.classList.add('is-missing');
                    if (!q.querySelector('.quiz-q__miss')) { var m = document.createElement('span'); m.className = 'quiz-q__miss'; m.textContent = 'Choose an answer for this question.'; q.insertBefore(m, q.children[1]); }
                    if (!first) first = q;
                });
                if (first) { e.preventDefault(); first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            });
            count();
        })();
        </script>
        <?php endif; ?>
    </div>
</div>

<?php if ($attempts): ?>
<div class="panel">
    <div class="panel__head"><h3>Recent attempts</h3></div>
    <div class="panel__body panel__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Date</th><th class="num">Score</th><th class="num">Percentage</th><th>Result</th></tr></thead>
                <tbody>
                <?php foreach ($attempts as $a): ?>
                    <tr>
                        <td class="small muted"><?= e(date('M j, Y g:ia', strtotime($a['taken_at']))) ?></td>
                        <td class="num"><?= e($a['score']) ?>/<?= e($a['total']) ?></td>
                        <td class="num"><?= e($a['percentage']) ?>%</td>
                        <td><span class="badge <?= $a['passed'] ? 'ok' : 'bad' ?>"><?= $a['passed'] ? 'Passed' : 'Failed' ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
