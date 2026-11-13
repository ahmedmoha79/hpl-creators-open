/* Creator Home (2026-11-08): count-ups, countdown to the next ready clip, weekly goal form, a small celebration. */
(function () {
    "use strict";
    var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    var cur = (document.querySelector("[data-live='balance']") || {}).textContent || "K0.00";
    var symbol = (cur.match(/^[^\d-]*/) || ["K"])[0] || "K";

    function fmtMoney(n) { return symbol + n.toLocaleString("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtCount(n) { return Math.round(n).toLocaleString("en-US"); }

    // Numbers that count up from zero the first time they appear.
    document.querySelectorAll("[data-countup]").forEach(function (el) {
        var target = parseFloat(el.getAttribute("data-countup")) || 0;
        var money = el.hasAttribute("data-money");
        if (reduce || target <= 0) return;
        var start = null, dur = 1100;
        function step(t) {
            if (start === null) start = t;
            var p = Math.min(1, (t - start) / dur);
            var v = target * (1 - Math.pow(1 - p, 3));
            el.textContent = "+" + (money ? fmtMoney(v) : fmtCount(v));
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    });

    // The balance counts up the first time Home opens in a visit (quick, once).
    var bal = document.querySelector(".hk-wallet__amount[data-live='balance']");
    if (bal && !reduce) {
        var seen = false;
        try { seen = sessionStorage.getItem("hkBalShown") === "1"; sessionStorage.setItem("hkBalShown", "1"); } catch (e) { seen = true; }
        var target = parseFloat((bal.textContent || "").replace(/[^0-9.]/g, "")) || 0;
        if (!seen && target > 0) {
            var t0 = null, from = target * 0.6, html = bal.innerHTML;
            var tick = function (t) {
                if (t0 === null) t0 = t;
                var p = Math.min(1, (t - t0) / 900);
                bal.textContent = fmtMoney(from + (target - from) * (1 - Math.pow(1 - p, 3)));
                if (p < 1) requestAnimationFrame(tick); else bal.innerHTML = html;
            };
            requestAnimationFrame(tick);
        }
    }

    // Live countdown to the next ready clip.
    document.querySelectorAll("[data-countdown]").forEach(function (el) {
        var at = parseInt(el.getAttribute("data-countdown"), 10) * 1000;
        function tick() {
            var s = Math.max(0, Math.round((at - Date.now()) / 1000));
            if (s <= 0) { el.textContent = "now"; return; }
            var h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
            el.textContent = (h ? h + "h " : "") + (h || m ? m + "m " : "") + (h ? "" : sec + "s");
            setTimeout(tick, 1000);
        }
        tick();
    });

    // Weekly goal: open the small form in place.
    document.querySelectorAll("[data-goal-edit]").forEach(function (b) {
        b.addEventListener("click", function () {
            var form = b.closest(".hk-goal").querySelector(".hk-goalform");
            if (!form) return;
            form.hidden = !form.hidden;
            if (!form.hidden) { var first = form.querySelector(".hk-chip"); if (first) first.focus(); }
        });
    });

    // A short burst of gold when money came in since the last visit.
    var since = document.querySelector(".hk-since b[data-money]");
    if (since && !reduce) {
        var wallet = document.getElementById("liveEarn");
        if (!wallet) return;
        var box = document.createElement("div");
        box.className = "hk-burst";
        box.setAttribute("aria-hidden", "true");
        for (var i = 0; i < 14; i++) {
            var c = document.createElement("i");
            var a = (Math.PI * 2 * i) / 14 + Math.random() * 0.4;
            var d = 60 + Math.random() * 70;
            c.style.setProperty("--x", Math.round(Math.cos(a) * d) + "px");
            c.style.setProperty("--y", Math.round(Math.sin(a) * d - 30) + "px");
            c.style.animationDelay = Math.round(Math.random() * 120) + "ms";
            box.appendChild(c);
        }
        wallet.appendChild(box);
        setTimeout(function () { box.remove(); }, 1800);
    }
})();
