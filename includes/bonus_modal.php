<?php
/**
 * Sign-up bonus celebration (creator dashboard). Shows only while the creator
 * has an unclaimed sign-up bonus. Expects $user.
 */
require_once __DIR__ . '/bonus.php';
require_once __DIR__ . '/email_verify.php';
$bnRow = isset($user['id']) ? bonus_pending((int)$user['id']) : null;
if (!$bnRow) {
    return;
}
$bnVerified = email_is_verified($user);
$bnFirst = trim(explode(' ', trim((string)($user['full_name'] ?? '')))[0] ?? '');
?>
<style>
.bn-overlay{position:fixed;inset:0;z-index:2000;display:grid;place-items:center;padding:20px;background:rgba(28,20,14,.62);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);animation:bnFade .25s ease-out}
.bn-overlay[hidden]{display:none}
.bn-canvas{position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:2001}
.bn-card{position:relative;z-index:2002;width:100%;max-width:380px;border-radius:24px;overflow:hidden;background:var(--ui-surface,#fffaf3);box-shadow:0 24px 60px rgba(0,0,0,.35);text-align:center;animation:bnPop .5s cubic-bezier(.2,1.4,.4,1)}
.bn-top{position:relative;padding:28px 20px 22px;background:radial-gradient(120% 90% at 50% 0%,#5a3a1f 0%,#2b1b0f 70%);color:#fbf3e8}
.bn-top::after{content:"";position:absolute;inset:0;background:conic-gradient(from 0deg,transparent 0 20deg,rgba(255,214,120,.10) 20deg 40deg,transparent 40deg 60deg,rgba(255,214,120,.10) 60deg 80deg,transparent 80deg 100deg,rgba(255,214,120,.10) 100deg 120deg,transparent 120deg 140deg,rgba(255,214,120,.10) 140deg 160deg,transparent 160deg 180deg,rgba(255,214,120,.10) 180deg 200deg,transparent 200deg 220deg,rgba(255,214,120,.10) 220deg 240deg,transparent 240deg 260deg,rgba(255,214,120,.10) 260deg 280deg,transparent 280deg 300deg,rgba(255,214,120,.10) 300deg 320deg,transparent 320deg 340deg,rgba(255,214,120,.10) 340deg 360deg);animation:bnSpin 18s linear infinite;pointer-events:none}
.bn-gift{position:relative;z-index:1;width:76px;height:76px;margin:0 auto 12px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(145deg,#ffd978,#d9a23c);box-shadow:0 0 0 8px rgba(255,217,120,.15),0 10px 24px rgba(0,0,0,.35);font-size:38px;animation:bnBob 2.4s ease-in-out infinite}
.bn-kicker{position:relative;z-index:1;margin:0;font-size:.8125rem;font-weight:700;letter-spacing:.14em;text-transform: none;color:#f3c873}
.bn-overlay .bn-title{position:relative;z-index:1;margin:6px 0 0;font-size:1.55rem;line-height:1.15;font-weight:800;color:#fff;letter-spacing:-.01em}
.bn-body{padding:22px 22px 20px}
.bn-amount{font-size:3rem;line-height:1;font-weight:800;letter-spacing:-.02em;color:var(--ui-text,#2b1b0f)}
.bn-amount small{display:block;margin-top:6px;font-size:.82rem;font-weight:600;letter-spacing:0;color:var(--ui-muted,#8a7563)}
.bn-copy{margin:14px 0 18px;font-size:.95rem;line-height:1.5;color:var(--ui-text-2,#5b4636)}
.bn-btn{display:flex;align-items:center;justify-content:center;gap:8px;width:100%;height:54px;border:0;border-radius:14px;font:inherit;font-size:1.05rem;font-weight:800;color:#2b1b0f;background:linear-gradient(180deg,#ffd978,#e3ab45);box-shadow:0 6px 0 #b98226,0 12px 24px rgba(185,130,38,.35);cursor:pointer;transition:transform .08s,box-shadow .08s;text-decoration:none}
.bn-btn:active{transform:translateY(4px);box-shadow:0 2px 0 #b98226,0 6px 14px rgba(185,130,38,.3)}
.bn-btn[disabled]{opacity:.75;cursor:default}
.bn-btn--done{background:linear-gradient(180deg,#5fcf8a,#2e9e5b);color:#fff;box-shadow:0 6px 0 #1f7a43,0 12px 24px rgba(31,122,67,.3)}
.bn-err{margin:10px 0 0;font-size:.86rem;color:var(--ui-bad,#b42318)}
.bn-later{margin-top:12px;background:none;border:0;font:inherit;font-size:.86rem;color:var(--ui-muted,#8a7563);cursor:pointer;text-decoration:underline}
.bn-balance{margin-top:12px;font-size:.88rem;color:var(--ui-ok,#1f7a43);font-weight:700}
@keyframes bnFade{from{opacity:0}to{opacity:1}}
@keyframes bnPop{0%{opacity:0;transform:scale(.7) translateY(20px)}100%{opacity:1;transform:none}}
@keyframes bnBob{0%,100%{transform:translateY(0) rotate(-4deg)}50%{transform:translateY(-6px) rotate(4deg)}}
@keyframes bnSpin{to{transform:rotate(360deg)}}
@keyframes bnPulse{0%{transform:scale(1)}40%{transform:scale(1.18)}100%{transform:scale(1)}}
.bn-amount.is-pulse{animation:bnPulse .6s ease-out}
@media (prefers-reduced-motion:reduce){.bn-overlay,.bn-card,.bn-gift,.bn-top::after,.bn-amount.is-pulse{animation:none}}
</style>

<div class="bn-overlay" id="bnOverlay" role="dialog" aria-modal="true" aria-labelledby="bnTitle">
    <canvas class="bn-canvas" id="bnCanvas" aria-hidden="true"></canvas>
    <div class="bn-card">
        <div class="bn-top">
            <div class="bn-gift" aria-hidden="true">&#127873;</div>
            <p class="bn-kicker">Sign-up reward</p>
            <h2 class="bn-title" id="bnTitle">Congratulations<?= $bnFirst !== '' ? ', ' . e($bnFirst) : '' ?>!</h2>
        </div>
        <div class="bn-body">
            <div class="bn-amount" id="bnAmount"><?= e(money((float)$bnRow['amount'])) ?><small>welcome bonus</small></div>
            <p class="bn-copy" id="bnCopy">Thanks for joining HPL Creators. This bonus is yours. Tap <strong>Receive</strong> and it goes straight into your wallet.</p>
            <?php if ($bnVerified): ?>
                <button type="button" class="bn-btn" id="bnReceive">Receive <?= e(money((float)$bnRow['amount'])) ?></button>
            <?php else: ?>
                <a class="bn-btn" href="<?= e(u('creator/verify_email.php')) ?>">Confirm email to receive</a>
                <p class="bn-copy" style="margin:12px 0 0;font-size:.84rem">One quick step: confirm your email address, then come back and tap Receive.</p>
            <?php endif; ?>
            <p class="bn-err" id="bnErr" role="alert" hidden></p>
            <div class="bn-balance" id="bnBalance" hidden></div>
            <button type="button" class="bn-later" id="bnLater">Later</button>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('bnOverlay');
    if (!overlay) return;
    // "Later" on the confirm-email version keeps it away for a day, so it does not cover "Your next step" on every visit.
    var canReceive = !!document.getElementById('bnReceive');
    var laterKey = 'hplBnLater';
    try {
        if (!canReceive && Date.now() - parseInt(localStorage.getItem(laterKey) || '0', 10) < 86400000) { overlay.hidden = true; return; }
    } catch (e) {}
    var canvas = document.getElementById('bnCanvas');
    var ctx = canvas.getContext('2d');
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var parts = [], raf = null;
    var colors = ['#ffd978', '#e3ab45', '#ffffff', '#5fcf8a', '#f48a5c', '#8fc7ff'];
    function size() { canvas.width = innerWidth * devicePixelRatio; canvas.height = innerHeight * devicePixelRatio; }
    size(); addEventListener('resize', size);
    function burst(n, fromY) {
        if (reduce) return;
        var W = canvas.width, H = canvas.height;
        for (var i = 0; i < n; i++) {
            var side = i % 2 ? 1 : -1;
            parts.push({
                x: side > 0 ? W * 0.05 : W * 0.95, y: H * (fromY || 0.75),
                vx: -side * (4 + Math.random() * 9) * devicePixelRatio, vy: -(10 + Math.random() * 12) * devicePixelRatio,
                w: (6 + Math.random() * 6) * devicePixelRatio, h: (8 + Math.random() * 10) * devicePixelRatio,
                r: Math.random() * 6.28, vr: (Math.random() - 0.5) * 0.4,
                c: colors[i % colors.length], life: 0
            });
        }
        if (!raf) raf = requestAnimationFrame(tick);
    }
    function tick() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        parts = parts.filter(function (p) { return p.y < canvas.height + 40 && p.life < 420; });
        parts.forEach(function (p) {
            p.life++; p.vy += 0.32 * devicePixelRatio; p.vx *= 0.99; p.x += p.vx; p.y += p.vy; p.r += p.vr;
            ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r);
            ctx.fillStyle = p.c; ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h * Math.abs(Math.cos(p.r * 2)) + 1);
            ctx.restore();
        });
        raf = parts.length ? requestAnimationFrame(tick) : null;
    }
    var claimed = false;
    function close() { overlay.hidden = true; document.body.style.overflow = ''; if (claimed) { location.reload(); } }
    document.body.style.overflow = 'hidden';
    setTimeout(function () { burst(90, 0.8); }, 250);

    document.getElementById('bnLater').addEventListener('click', function () {
        if (!canReceive) { try { localStorage.setItem(laterKey, String(Date.now())); } catch (e) {} }
        close();
    });

    var btn = document.getElementById('bnReceive');
    if (!btn) return;
    var err = document.getElementById('bnErr');
    btn.addEventListener('click', function () {
        if (btn.classList.contains('bn-btn--done')) { close(); return; }
        btn.disabled = true; btn.textContent = 'Adding to your wallet…'; err.hidden = true;
        var fd = new FormData();
        fd.append('csrf', <?= json_encode(csrf_token()) ?>);
        fetch(<?= json_encode(u('creator/bonus_claim.php')) ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d || !d.ok) { throw new Error((d && d.error) || 'Something went wrong. Please try again.'); }
                var amt = document.getElementById('bnAmount');
                amt.classList.remove('is-pulse'); void amt.offsetWidth; amt.classList.add('is-pulse');
                document.getElementById('bnCopy').innerHTML = d.already ? 'This bonus is already in your wallet.' : '<strong>' + d.amount_text + '</strong> is now in your wallet. Start clipping to earn more!';
                var bal = document.getElementById('bnBalance');
                bal.hidden = false; bal.textContent = 'Wallet balance: ' + d.balance_text;
                document.querySelectorAll('[data-tb-balance]').forEach(function (el) { el.textContent = d.balance_text; });
                claimed = true;
                btn.disabled = false; btn.classList.add('bn-btn--done'); btn.textContent = '✓ Added to your wallet';
                document.getElementById('bnLater').textContent = 'Close';
                burst(160, 0.7); setTimeout(function () { burst(90, 0.6); }, 450);
            })
            .catch(function (e) {
                btn.disabled = false; btn.textContent = btn.getAttribute('data-label') || 'Try again';
                err.textContent = e.message; err.hidden = false;
            });
    });
})();
</script>
