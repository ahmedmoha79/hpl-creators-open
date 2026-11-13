<?php
require_once __DIR__ . '/../config.php';
$user = require_admin();
$pageTitle = 'Assessment questions';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = post('action');
    if ($action === 'save') {
        $id = (int)post('id');
        $data = [
            post('question'),
            post('option_a'), post('option_b'), post('option_c'), post('option_d'),
            post('correct_option'),
        ];
        if (in_array('', array_slice($data, 0, 5), true) || !in_array($data[5], ['a', 'b', 'c', 'd'], true)) {
            flash('error', 'Please fill the question, all four options and pick a correct answer.');
        } elseif ($id > 0) {
            q('UPDATE assessment_questions SET question=?, option_a=?, option_b=?, option_c=?, option_d=?, correct_option=? WHERE id=?', array_merge($data, [$id]));
            flash('success', 'Question updated.');
        } else {
            q('INSERT INTO assessment_questions (question, option_a, option_b, option_c, option_d, correct_option) VALUES (?,?,?,?,?,?)', $data);
            flash('success', 'Question added.');
        }
    } elseif ($action === 'toggle') {
        q('UPDATE assessment_questions SET is_active = 1 - is_active WHERE id = ?', [(int)post('id')]);
        flash('success', 'Question visibility updated.');
    } elseif ($action === 'delete') {
        q('DELETE FROM assessment_questions WHERE id = ?', [(int)post('id')]);
        flash('warning', 'Question deleted.');
    }
    redirect('admin/questions.php');
}

$edit = null;
if (($eid = (int)get('edit')) > 0) {
    $edit = fetch_one('SELECT * FROM assessment_questions WHERE id = ?', [$eid]);
}
$questions = fetch_all('SELECT * FROM assessment_questions ORDER BY id ASC');
$activeCount = (int)fetch_val('SELECT COUNT(*) FROM assessment_questions WHERE is_active = 1', [], 0);

require __DIR__ . '/../includes/admin_header.php';
?>

<div class="flex-between mb-3 flex-wrap">
    <div>
        <h1 style="margin-bottom:4px">Assessment questions</h1>
        <p class="muted mb-0"><?= e(num($activeCount)) ?> active question(s). Creators need 80% to pass.</p>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3><?= $edit ? 'Edit question #' . (int)$edit['id'] : 'Add a question' ?></h3><?php if ($edit): ?><a class="small" href="<?= e(u('admin/questions.php')) ?>">Cancel edit</a><?php endif; ?></div>
    <div class="panel__body">
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= $edit ? (int)$edit['id'] : 0 ?>">
            <div class="field">
                <label class="label" for="question">Question</label>
                <textarea class="textarea" id="question" name="question" rows="2" required><?= e($edit['question'] ?? '') ?></textarea>
            </div>
            <div class="form-grid">
                <?php foreach (['a', 'b', 'c', 'd'] as $o): ?>
                    <div class="field">
                        <label class="label" for="option_<?= $o ?>">Option <?= strtoupper($o) ?></label>
                        <input class="input" type="text" id="option_<?= $o ?>" name="option_<?= $o ?>" value="<?= e($edit['option_' . $o] ?? '') ?>" required>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="field" style="max-width:220px">
                <label class="label" for="correct_option">Correct answer</label>
                <select class="select" id="correct_option" name="correct_option">
                    <?php foreach (['a', 'b', 'c', 'd'] as $o): ?>
                        <option value="<?= $o ?>" <?= ($edit['correct_option'] ?? '') === $o ? 'selected' : '' ?>><?= strtoupper($o) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn--primary" type="submit"><?= $edit ? 'Save changes' : 'Add question' ?></button>
        </form>
    </div>
</div>

<div class="panel">
    <div class="panel__head"><h3>All questions</h3><span class="badge"><?= e(num(count($questions))) ?></span></div>
    <div class="panel__body panel__body--flush">
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>#</th><th>Question</th><th>Correct</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>
                <?php foreach ($questions as $qz): ?>
                    <tr>
                        <td class="small muted"><?= (int)$qz['id'] ?></td>
                        <td><strong class="small"><?= e($qz['question']) ?></strong>
                            <div class="small muted">A) <?= e($qz['option_a']) ?> &nbsp; B) <?= e($qz['option_b']) ?> &nbsp; C) <?= e($qz['option_c']) ?> &nbsp; D) <?= e($qz['option_d']) ?></div>
                        </td>
                        <td><span class="badge ok"><?= strtoupper(e($qz['correct_option'])) ?></span></td>
                        <td><span class="badge <?= $qz['is_active'] ? 'ok' : 'muted' ?>"><?= $qz['is_active'] ? 'Active' : 'Hidden' ?></span></td>
                        <td>
                            <div class="flex" style="gap:6px">
                                <a class="btn btn--ghost btn--sm" href="<?= e(u('admin/questions.php?edit=' . $qz['id'])) ?>">Edit</a>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$qz['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn btn--ghost btn--sm" type="submit"><?= $qz['is_active'] ? 'Hide' : 'Show' ?></button></form>
                                <form method="post" style="display:inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$qz['id'] ?>"><input type="hidden" name="action" value="delete"><button class="btn btn--danger btn--sm" type="submit" data-confirm="Delete this question?">Delete</button></form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../includes/admin_footer.php'; ?>
