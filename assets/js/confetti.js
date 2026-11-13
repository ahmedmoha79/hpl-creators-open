/* Tiny confetti burst: hplConfetti(canvas, count). Respects reduced motion. */
(function () {
    var parts = [], raf = null, cv = null, ctx = null;
    var colors = ['#ffd978', '#e3ab45', '#ffffff', '#5fcf8a', '#f48a5c', '#8fc7ff'];
    function size() { if (!cv) return; cv.width = innerWidth * devicePixelRatio; cv.height = innerHeight * devicePixelRatio; }
    function tick() {
        ctx.clearRect(0, 0, cv.width, cv.height);
        parts = parts.filter(function (p) { return p.y < cv.height + 40 && p.life < 420; });
        parts.forEach(function (p) {
            p.life++; p.vy += 0.32 * devicePixelRatio; p.vx *= 0.99; p.x += p.vx; p.y += p.vy; p.r += p.vr;
            ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r);
            ctx.fillStyle = p.c; ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h * Math.abs(Math.cos(p.r * 2)) + 1);
            ctx.restore();
        });
        raf = parts.length ? requestAnimationFrame(tick) : null;
    }
    window.hplConfetti = function (canvas, n) {
        if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        if (cv !== canvas) { cv = canvas; ctx = cv.getContext('2d'); size(); addEventListener('resize', size); }
        var W = cv.width, H = cv.height;
        for (var i = 0; i < (n || 120); i++) {
            var side = i % 2 ? 1 : -1;
            parts.push({ x: side > 0 ? W * 0.05 : W * 0.95, y: H * 0.75, vx: -side * (4 + Math.random() * 9) * devicePixelRatio,
                vy: -(10 + Math.random() * 12) * devicePixelRatio, w: (6 + Math.random() * 6) * devicePixelRatio,
                h: (8 + Math.random() * 10) * devicePixelRatio, r: Math.random() * 6.28, vr: (Math.random() - 0.5) * 0.4,
                c: colors[i % colors.length], life: 0 });
        }
        if (!raf) raf = requestAnimationFrame(tick);
    };
})();
