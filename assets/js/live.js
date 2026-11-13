/* Live earnings on the creator dashboard (2026-10-27).
 * Reads the same numbers the server shows, refreshes them while the page is
 * open, animates changes, and lets the creator ask for a fresh view read. */
(function () {
    "use strict";
    var root = document.getElementById("liveEarn");
    var seed = document.getElementById("liveData");
    if (!root || !seed) return;

    var endpoint = root.getAttribute("data-endpoint");
    var csrf = root.getAttribute("data-csrf");
    var withdrawUrl = root.getAttribute("data-withdraw");
    var state;
    try { state = JSON.parse(seed.textContent); } catch (e) { return; }
    var clockOffset = (state.now || 0) * 1000 - Date.now();
    var timer = null, busy = false, stopped = false, failures = 0;
    var reduceMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;

    var nf2 = new Intl.NumberFormat("en-US", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    var nf0 = new Intl.NumberFormat("en-US", { maximumFractionDigits: 0 });
    function money(n) { return (state.currency || "") + nf2.format(n); }
    function count(n) { return nf0.format(n); }
    function signed(n, isMoney) { return (n > 0 ? "+" : "") + (isMoney ? money(n) : count(n)); }

    function animate(el, from, to, isMoney, sign) {
        var fmt = function (v) { return sign ? signed(v, isMoney) : (isMoney ? money(v) : count(Math.round(v))); };
        if (reduceMotion || from === to) { el.textContent = fmt(to); return; }
        var start = null, dur = 900;
        function step(ts) {
            if (start === null) start = ts;
            var p = Math.min(1, (ts - start) / dur);
            var eased = 1 - Math.pow(1 - p, 3);
            var v = from + (to - from) * eased;
            el.textContent = fmt(isMoney ? Math.round(v * 100) / 100 : Math.round(v));
            if (p < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    function ago(ts) {
        if (!ts) return null;
        var s = Math.max(0, Math.round((Date.now() + clockOffset) / 1000 - ts));
        if (s < 60) return "just now";
        var m = Math.round(s / 60);
        if (m < 60) return m + " min ago";
        var h = Math.round(m / 60);
        return h + (h === 1 ? " hour ago" : " hours ago");
    }

    function renderSync() {
        var el = root.querySelector("[data-live-sync]");
        if (!el) return;
        var last = ago(state.last_read);
        var nowS = (Date.now() + clockOffset) / 1000;
        var next = state.next_read ? Math.max(0, Math.round((state.next_read - nowS) / 60)) : null;
        var parts = [];
        parts.push(last ? "Views last read " + last + "." : "Your views have not been read yet.");
        if (next !== null) parts.push(next <= 1 ? "Next automatic read in about a minute." : "Next automatic read in about " + next + " minutes.");
        el.textContent = parts.join(" ");
        var stale = !state.last_read || (nowS - state.last_read) > 2 * 3600;
        root.classList.toggle("is-stale", stale);
    }

    function renderGoal() {
        var bar = root.querySelector("[data-goal-bar]");
        var text = root.querySelector("[data-goal-text]");
        var min = state.min_payout || 0;
        var pct = min > 0 ? Math.min(100, Math.floor(state.balance / min * 100)) : 100;
        if (bar) bar.style.width = pct + "%";
        if (!text) return;
        if (state.balance >= min) {
            text.innerHTML = "";
            text.appendChild(document.createTextNode("Ready to withdraw. "));
            var a = document.createElement("a");
            a.href = withdrawUrl; a.textContent = "Cash out to mobile money";
            text.appendChild(a);
        } else {
            text.textContent = money(Math.max(0, min - state.balance)) + " to go until you can withdraw (minimum " + money(min) + ")";
        }
    }

    function renderClips(prev) {
        var before = {};
        (prev.clips || []).forEach(function (c) { before[c.key] = c; });
        (state.clips || []).forEach(function (c) {
            var row = document.querySelector('[data-clip="' + c.key + '"]');
            if (!row) return;
            var old = before[c.key] || c;
            var v = row.querySelector("[data-clip-views]");
            var e = row.querySelector("[data-clip-earned]");
            var b = row.querySelector("[data-clip-bar]");
            var n = row.querySelector("[data-clip-next]");
            if (v) animate(v, old.views, c.views, false, false);
            if (e) animate(e, old.earned, c.earned, true, false);
            if (b) b.style.width = c.pct + "%";
            if (n) n.textContent = count(c.to_next) + " views to your next " + money(c.next_amount) + (c.accruing > 0 ? " \u00b7 " + money(c.accruing) + " building up" : "");
            if (c.views > old.views || c.earned > old.earned) {
                row.classList.remove("is-up"); void row.offsetWidth; row.classList.add("is-up");
            }
        });
    }

    function apply(next) {
        var prev = state;
        state = next;
        clockOffset = (next.now || 0) * 1000 - Date.now();
        document.querySelectorAll("[data-live]").forEach(function (el) {
            var key = el.getAttribute("data-live");
            if (!(key in next)) return;
            var isMoney = el.hasAttribute("data-money");
            var sign = el.hasAttribute("data-sign");
            animate(el, Number(prev[key]) || 0, Number(next[key]) || 0, isMoney, sign);
        });
        var amount = root.querySelector(".ui-live__amount");
        if (next.balance > prev.balance && amount) {
            amount.classList.add("is-up");
            setTimeout(function () { amount.classList.remove("is-up"); }, 2200);
            if (window.hplToast) window.hplToast("+" + money(next.balance - prev.balance) + " added to your balance", "ok");
        }
        renderGoal();
        renderClips(prev);
        renderSync();
    }

    function visible() { return !document.hidden && root.offsetParent !== null; }
    function schedule(ms) {
        if (stopped) return;
        clearTimeout(timer);
        timer = setTimeout(tick, ms);
    }
    function nextDelay() {
        if (failures) return Math.min(300000, 30000 * Math.pow(2, failures));
        return visible() ? 30000 : 120000;
    }
    function tick() {
        if (stopped) return;
        if (document.hidden) { schedule(60000); return; }
        load();
    }
    function load() {
        if (busy) return;
        busy = true;
        fetch(endpoint, { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (r) {
                if (r.status === 401) { stopped = true; throw new Error("auth"); }
                if (!r.ok) throw new Error("http " + r.status);
                return r.json();
            })
            .then(function (d) { busy = false; failures = 0; apply(d); schedule(nextDelay()); })
            .catch(function () { busy = false; if (stopped) return; failures++; schedule(nextDelay()); });
    }

    var btn = root.querySelector("[data-live-refresh]");
    if (btn) {
        btn.addEventListener("click", function () {
            if (btn.disabled) return;
            btn.disabled = true;
            btn.classList.add("is-busy");
            var label = btn.querySelector("span");
            var oldLabel = label ? label.textContent : "";
            if (label) label.textContent = "Reading your views";
            var fd = new FormData();
            fd.append("csrf", csrf);
            fd.append("action", "refresh");
            fetch(endpoint, { method: "POST", credentials: "same-origin", body: fd })
                .then(function (r) { return r.json().then(function (d) { return { status: r.status, d: d }; }); })
                .then(function (res) {
                    var d = res.d || {};
                    if (!d.ok) {
                        if (window.hplToast) window.hplToast(d.error || "Could not refresh right now. Try again in a minute.", "error");
                        return;
                    }
                    var gained = d.views_gained || 0;
                    var credited = d.credited || 0;
                    var hadBalanceToast = d.balance > state.balance;
                    apply(d);
                    if (!window.hplToast) return;
                    if (credited > 0 && !hadBalanceToast) {
                        // Money on clips still waiting for approval is locked, not in the balance yet.
                        window.hplToast("+" + money(credited) + " earned. It unlocks when our team approves your clip.", "ok");
                    } else if (gained > 0 && credited <= 0) {
                        window.hplToast("+" + count(gained) + " new views read", "ok");
                    } else if (!d.refreshed) {
                        window.hplToast("Your views were read in the last 5 minutes. They are up to date.", "info");
                    } else if (gained <= 0) {
                        window.hplToast("Your views are up to date.", "info");
                    }
                })
                .catch(function () {
                    if (window.hplToast) window.hplToast("Network error. Check your connection and try again.", "error");
                })
                .then(function () {
                    btn.classList.remove("is-busy");
                    if (label) label.textContent = oldLabel;
                    setTimeout(function () { btn.disabled = false; }, 20000);
                    schedule(nextDelay());
                });
        });
    }

    document.addEventListener("visibilitychange", function () { if (!document.hidden && !stopped) schedule(500); });
    setInterval(renderSync, 20000);
    renderSync();
    schedule(nextDelay());
})();
