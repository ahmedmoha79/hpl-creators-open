<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/earnings.php';
require_once __DIR__ . '/../includes/rewards.php';
require_once __DIR__ . '/../includes/email_verify.php';
$user = require_creator();
$uid = (int)$user['id'];
$pageTitle = 'Rewards';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = strpos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false;
    $token = (string)($_POST['csrf'] ?? '');
    if ($token === '' || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
        $res = ['ok' => false, 'error' => 'Your session expired. Refresh the page and try again.'];
    } elseif (!email_is_verified($user)) {
        $res = ['ok' => false, 'error' => 'Confirm your email address first, then try again.'];
    } elseif (($user['status'] ?? '') !== 'active') {
        $res = ['ok' => false, 'error' => 'Your account cannot receive rewards right now. Please contact support.'];
    } elseif (post('action') === 'redeem') {
        $res = reward_redeem_code($user, (string)post('code'));
    } elseif (post('action') === 'claim') {
        $res = achievement_claim($user, (string)post('key'));
    } else {
        $res = ['ok' => false, 'error' => 'Unknown action.'];
    }
    if (!empty($res['ok'])) {
        $bal = (float)creator_stats($uid)['balance'];
        $res['amount_text'] = money((float)$res['amount']);
        $res['balance_text'] = money($bal);
    }
    if ($wantsJson) {
        json_response($res, !empty($res['ok']) ? 200 : 400);
    }
    flash(!empty($res['ok']) ? 'success' : 'error', !empty($res['ok']) ? $res['amount_text'] . ' added to your wallet.' : $res['error']);
    redirect('creator/rewards.php');
}

$board = achievement_board($uid);
$history = reward_history($uid, 30);
$totalRewards = array_sum(array_map(function ($r) { return (float)$r['amount']; }, $history));
$unlockedCount = count(array_filter($board, function ($a) { return $a['unlocked']; }));
$claimable = count(array_filter($board, function ($a) { return $a['claimable']; }));
$fmtVal = function (array $a): string {
    if ($a['metric'] === 'earned') {
        return money(min($a['value'], $a['target'])) . ' / ' . money($a['target']);
    }
    $unit = ['views' => ' views', 'clips' => ' clips'][$a['metric']] ?? '';
    return num((int)min($a['value'], $a['target'])) . ' / ' . num((int)$a['target']) . $unit;
};

require __DIR__ . '/../includes/creator_header.php';
?>
<link rel="stylesheet" href="<?= e(u('assets/css/rewards.css?v=20261108d')) ?>">
<div class="ui-page rw">
    <div class="ui-page-head">
        <div>
            <h1>Rewards</h1>
            <p>Bonus codes and achievements. Every reward goes straight into your wallet.</p>
        </div>
    </div>

    <div class="rw-summary">
        <div class="rw-sum"><span><?= icon('wallet') ?></span><div><b><?= e(money($totalRewards)) ?></b><small>rewards received</small></div></div>
        <div class="rw-sum"><span><?= icon('award') ?></span><div><b><?= (int)$unlockedCount ?> / <?= count($board) ?></b><small>badges unlocked</small></div></div>
        <?php if ($claimable > 0): ?><a class="rw-sum rw-sum--hot" href="#rwBadges"><span><?= icon('sparkles') ?></span><div><b><?= (int)$claimable ?> to claim</b><small>tap Claim below</small></div></a><?php endif; ?>
    </div>

    <!-- Bonus code -->
    <section class="rw-code" id="rwCode">
        <div class="rw-code__art" aria-hidden="true">&#127873;</div>
        <div class="rw-code__main">
            <h2>Have a bonus code?</h2>
            <p>The HPL team shares codes in announcements, the community and on our socials. Enter one to get a cash bonus.</p>
            <form class="rw-code__form" method="post" id="rwCodeForm" autocomplete="off">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="redeem">
                <input class="input rw-code__input" name="code" id="rwCodeInput" maxlength="40" placeholder="e.g. HPL-7KQ3X" autocapitalize="characters" spellcheck="false" required>
                <button class="btn btn--primary" type="submit" id="rwCodeBtn" data-busy="Checking…">Redeem</button>
            </form>
            <p class="rw-msg" id="rwCodeMsg" role="status" hidden></p>
        </div>
    </section>

    <!-- Achievements -->
    <section class="ui-card" id="rwBadges">
        <div class="ui-card__head"><h2><?= icon('award') ?>Achievements</h2><span class="ui-card__sub">Unlock badges as you grow<?= array_filter($board, function ($a) { return $a['amount'] > 0; }) ? '. Some come with cash.' : '.' ?></span></div>
        <div class="rw-grid">
            <?php foreach ($board as $a):
                $state = $a['claimed'] ? 'claimed' : ($a['claimable'] ? 'claim' : ($a['unlocked'] ? 'done' : 'locked'));
            ?>
                <article class="rw-badge rw-badge--<?= $state ?>" data-key="<?= e($a['key']) ?>">
                    <div class="rw-badge__ico"><?= icon($a['icon']) ?></div>
                    <h3><?= e($a['title']) ?></h3>
                    <p><?= e($a['desc']) ?></p>
                    <?php if (!$a['unlocked']): ?>
                        <div class="rw-bar" role="progressbar" aria-valuenow="<?= (int)$a['pct'] ?>" aria-valuemin="0" aria-valuemax="100"><i style="width:<?= (int)$a['pct'] ?>%"></i></div>
                        <small class="rw-badge__prog"><?= e($fmtVal($a)) ?></small>
                    <?php endif; ?>
                    <div class="rw-badge__foot">
                        <?php if ($state === 'claim'): ?>
                            <form method="post" class="rw-claim"><?= csrf_field() ?><input type="hidden" name="action" value="claim"><input type="hidden" name="key" value="<?= e($a['key']) ?>">
                                <button class="btn btn--primary btn--sm rw-claim__btn" type="submit" data-busy="Claiming…">Claim <?= e(money($a['amount'])) ?></button></form>
                        <?php elseif ($state === 'claimed'): ?>
                            <span class="rw-chip rw-chip--ok"><?= icon('check') ?><?= e(money($a['amount'])) ?> received</span>
                        <?php elseif ($state === 'done'): ?>
                            <span class="rw-chip rw-chip--ok"><?= icon('check') ?>Unlocked</span>
                        <?php else: ?>
                            <span class="rw-chip"><?= $a['amount'] > 0 ? e(money($a['amount'])) . ' reward' : 'Badge' ?></span>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- History -->
    <section class="ui-card">
        <div class="ui-card__head"><h2><?= icon('clock') ?>Reward history</h2></div>
        <?php if (!$history): ?>
            <div class="ui-card__body"><p class="muted mb-0">No rewards yet. Redeem a code or unlock an achievement to get your first one.</p></div>
        <?php else: ?>
            <ul class="rw-hist">
                <?php foreach ($history as $h): ?>
                    <li><span class="rw-hist__ico"><?= icon(strpos((string)$h['kind'], 'ach:') === 0 ? 'award' : 'sparkles') ?></span>
                        <div><b><?= e(reward_label($h)) ?></b><small><?= e(time_ago((string)$h['claimed_at'])) ?></small></div>
                        <strong>+<?= e(money((float)$h['amount'])) ?></strong></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>
<canvas class="rw-confetti" id="rwConfetti" aria-hidden="true"></canvas>
<script src="<?= e(u('assets/js/confetti.js?v=20261029a')) ?>"></script>
<script>
(function () {
    function post(form) {
        return fetch(location.pathname, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); });
    }
    function setBalance(t) { document.querySelectorAll('[data-tb-balance]').forEach(function (el) { el.textContent = t; }); }
    var form = document.getElementById('rwCodeForm'), msg = document.getElementById('rwCodeMsg'), btn = document.getElementById('rwCodeBtn'), input = document.getElementById('rwCodeInput');
    input.addEventListener('input', function () { var p = input.selectionStart; input.value = input.value.toUpperCase(); input.setSelectionRange(p, p); });
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        btn.disabled = true; btn.textContent = 'Checking…'; msg.hidden = true;
        post(form).then(function (d) {
            msg.hidden = false;
            if (d.ok) {
                msg.className = 'rw-msg rw-msg--ok';
                msg.innerHTML = '&#127881; <b>' + d.amount_text + '</b> added to your wallet! New balance: ' + d.balance_text;
                setBalance(d.balance_text); input.value = '';
                if (window.hplConfetti) hplConfetti(document.getElementById('rwConfetti'), 180);
                setTimeout(function () { location.reload(); }, 3500);
            } else {
                msg.className = 'rw-msg rw-msg--bad'; msg.textContent = d.error || 'That did not work. Try again.';
                form.classList.remove('is-shake'); void form.offsetWidth; form.classList.add('is-shake');
            }
        }).catch(function () { msg.hidden = false; msg.className = 'rw-msg rw-msg--bad'; msg.textContent = 'Network problem. Try again.'; })
          .finally(function () { btn.disabled = false; btn.textContent = 'Redeem'; });
    });
    document.querySelectorAll('.rw-claim').forEach(function (f) {
        f.addEventListener('submit', function (e) {
            e.preventDefault();
            var b = f.querySelector('button'); b.disabled = true; b.textContent = 'Claiming…';
            post(f).then(function (d) {
                if (!d.ok) { b.disabled = false; b.textContent = 'Try again'; if (window.hplToast) hplToast(d.error, 'error'); return; }
                var card = f.closest('.rw-badge');
                card.className = 'rw-badge rw-badge--claimed is-pop';
                f.outerHTML = '<span class="rw-chip rw-chip--ok">&#10003; ' + d.amount_text + ' received</span>';
                setBalance(d.balance_text);
                if (window.hplConfetti) hplConfetti(document.getElementById('rwConfetti'), 150);
                if (window.hplToast) hplToast(d.amount_text + ' added to your wallet!', 'success');
            }).catch(function () { b.disabled = false; b.textContent = 'Try again'; });
        });
    });
})();
</script>
<?php require __DIR__ . '/../includes/creator_footer.php'; ?>
