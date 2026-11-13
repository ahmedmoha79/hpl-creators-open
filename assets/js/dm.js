/* HPL Messages (creator + admin), 2026-10-28.
 * Conversation list + open chat. Fits the visible screen (keyboard aware on phones),
 * groups messages into bubbles, sends instantly with retry, shows "Seen",
 * refreshes live while open, searches and filters, starts new conversations. */
(function () {
    "use strict";
    var root = document.getElementById("dm");
    var seedEl = document.getElementById("dmSeed");
    if (!root || !seedEl) return;
    var S;
    try { S = JSON.parse(seedEl.textContent); } catch (e) { return; }

    var $ = function (id) { return document.getElementById(id); };
    var feedEl = $("dmFeed"), scroller = $("dmScroll"), form = $("dmForm"), input = $("dmInput"), jump = $("dmJump");
    var statusEl = $("dmStatus"), listEl = $("dmList"), search = $("dmSearch");
    var sendBtn = form ? form.querySelector(".dm__send") : null;
    var clockOffset = (S.now || 0) * 1000 - Date.now();
    var mobileQ = window.matchMedia("(max-width: 900px)");
    var TICK = '<svg class="dm-tick" viewBox="0 0 24 24" aria-label="HPL team" role="img"><path fill="currentColor" d="M12 1.8l2.6 1.9 3.2-.1 1 3 2.6 1.9-1 3 1 3-2.6 1.9-1 3-3.2-.1L12 22.2l-2.6-1.9-3.2.1-1-3-2.6-1.9 1-3-1-3 2.6-1.9 1-3 3.2.1z"/><path fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" d="M8 12.3l2.7 2.7L16.2 9.5"/></svg>';
    var ICON_X = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>';
    var ICON_BIN = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3"/></svg>';
    var ICON_COMMUNITY = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

    var messages = (S.messages || []).slice();
    var pending = [];
    var lastId = 0, otherRead = 0;
    messages.forEach(function (m) { if (m.id > lastId) lastId = m.id; });

    /* ---------- helpers ---------- */
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function nowS() { return (Date.now() + clockOffset) / 1000; }
    var crcTable = null;
    function hue(name) { // same colour as the server: crc32(lowercase name) % 360
        if (!crcTable) {
            crcTable = [];
            for (var n = 0; n < 256; n++) { var c = n; for (var k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; crcTable[n] = c >>> 0; }
        }
        var bytes = unescape(encodeURIComponent(String(name || "").toLowerCase())), crc = 0xFFFFFFFF;
        for (var i = 0; i < bytes.length; i++) crc = crcTable[(crc ^ bytes.charCodeAt(i)) & 0xFF] ^ (crc >>> 8);
        return ((crc ^ 0xFFFFFFFF) >>> 0) % 360;
    }
    function avatar(name, url, team, size) {
        var a = el("span", "dm-av" + (size ? " dm-av--" + size : "") + (team ? " is-team" : ""));
        a.setAttribute("aria-hidden", "true");
        if (!team) a.style.setProperty("--h", hue(name));
        if (url) { var img = el("img"); img.src = url; img.alt = ""; img.loading = "lazy"; a.appendChild(img); }
        else a.textContent = team ? "H" : (String(name || "?").trim().charAt(0) || "?").toUpperCase();
        return a;
    }
    function dayKey(ts) { var d = new Date(ts * 1000); return d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate(); }
    function dayLabel(ts) {
        var d = new Date(ts * 1000), t = new Date(nowS() * 1000), y = new Date(t.getTime() - 86400000);
        if (d.toDateString() === t.toDateString()) return "Today";
        if (d.toDateString() === y.toDateString()) return "Yesterday";
        return d.toLocaleDateString(undefined, { weekday: "long", day: "numeric", month: "long", year: d.getFullYear() === t.getFullYear() ? undefined : "numeric" });
    }
    function clock(ts) { return new Date(ts * 1000).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" }); }
    function ago(ts) {
        var s = Math.max(0, Math.round(nowS() - ts));
        if (s < 60) return "now";
        var m = Math.round(s / 60); if (m < 60) return m + "m";
        var h = Math.round(m / 60); if (h < 24) return h + "h";
        var d = Math.round(h / 24); if (d < 7) return d + "d";
        return new Date(ts * 1000).toLocaleDateString(undefined, { day: "numeric", month: "short" });
    }
    function linkify(node, text) {
        var re = /(https?:\/\/[^\s<]+)/g, last = 0, m;
        while ((m = re.exec(text))) {
            if (m.index > last) node.appendChild(document.createTextNode(text.slice(last, m.index)));
            var a = el("a", null, m[1]); a.href = m[1]; a.target = "_blank"; a.rel = "noopener nofollow";
            node.appendChild(a); last = m.index + m[1].length;
        }
        if (last < text.length) node.appendChild(document.createTextNode(text.slice(last)));
    }
    function toast(msg, type) { if (window.hplToast) window.hplToast(msg, type); }

    /* ---------- fit to the visible screen ---------- */
    function fit() {
        var vv = window.visualViewport;
        var vh = vv ? vv.height : window.innerHeight;
        var mobile = mobileQ.matches;
        if (mobile && window.scrollY) window.scrollTo(0, 0);
        var top = Math.max(0, root.getBoundingClientRect().top + (mobile ? 0 : window.scrollY));
        var dock = document.querySelector(".mobile-dock");
        var dockH = dock && getComputedStyle(dock).display !== "none" ? dock.getBoundingClientRect().height : 0;
        var h = Math.floor(vh - top - dockH - (mobile ? 0 : 28));
        root.style.height = Math.max(mobile ? 280 : 480, h) + "px";
    }
    fit();
    window.addEventListener("resize", fit);
    if (window.visualViewport) window.visualViewport.addEventListener("resize", function () { var b = nearBottom(); fit(); if (b) toBottom(); });

    /* ---------- conversation ---------- */
    function nearBottom() { return scroller ? (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight) < 120 : true; }
    function toBottom() { if (scroller) scroller.scrollTop = scroller.scrollHeight; if (jump) { jump.hidden = true; jump.classList.remove("has-new"); } }
    function showWho(m) { return !m.mine && (m.team || m.bot || !S.member); }

    function bubble(m) {
        var b = el("div", "dm-b" + (m.html ? " dm-b--html" : "") + (m.bot ? " dm-b--bot" : "") + (m.pending ? " is-pending" : "") + (m.failed ? " is-failed" : ""));
        b.setAttribute("data-id", m.id);
        if (m.src === "community") {
            var src = el("div", "dm-b__src");
            src.innerHTML = ICON_COMMUNITY;
            src.appendChild(el("span", null, m.mine ? "You asked in Community" : "Asked in Community"));
            var open = el("a", null, "Open"); open.href = S.community; src.appendChild(open);
            b.appendChild(src);
        }
        var body = el("span", "dm-b__text");
        if (m.html) body.innerHTML = m.text; else linkify(body, m.text);
        b.appendChild(body);
        var t = el("span", "dm-b__time", m.pending ? "…" : clock(m.ts));
        b.appendChild(t);
        if (S.admin && !m.pending && !m.failed && m.id > 0) {
            var x = el("button", "dm-b__del");
            x.type = "button"; x.title = "Delete message"; x.setAttribute("aria-label", "Delete message");
            x.innerHTML = ICON_BIN;
            x.addEventListener("click", function (e) { e.stopPropagation(); removeMsg(m.id); });
            b.appendChild(x);
            b.addEventListener("click", function (e) { // phones: tap a message to show its remove button
                if (e.target.closest("a, button")) return;
                var was = b.classList.contains("is-picked");
                root.querySelectorAll(".dm-b.is-picked").forEach(function (n) { n.classList.remove("is-picked"); });
                if (!was) b.classList.add("is-picked");
            });
        }
        return b;
    }

    function render() {
        if (!feedEl) return;
        var stick = nearBottom();
        var all = messages.concat(pending);
        feedEl.textContent = "";
        if (S.team) {
            var intro = el("div", "dm-intro");
            intro.appendChild(el("strong", null, "This is your direct line to the HPL team"));
            intro.appendChild(el("span", null, "Ask about payouts, views or your account. Tap a common question below for an instant answer."));
            feedEl.appendChild(intro);
        } else if (!all.length) {
            var start = el("div", "dm-intro");
            var who = root.querySelector(".dm__who strong span");
            start.appendChild(el("strong", null, "No messages yet"));
            start.appendChild(el("span", null, S.member ? "Say hello to " + (who ? who.textContent : "them") + "." : "Nothing has been said here."));
            feedEl.appendChild(start);
        }
        var prev = null, col = null, lastMine = null, lastMineCol = null;
        all.forEach(function (m) {
            var newDay = !prev || dayKey(prev.ts) !== dayKey(m.ts);
            if (newDay) feedEl.appendChild(el("div", "dm-day", dayLabel(m.ts)));
            var same = !newDay && prev && prev.sid === m.sid && prev.bot === m.bot && prev.mine === m.mine && (m.ts - prev.ts) < 600;
            if (!same) {
                var grp = el("div", "dm-grp" + (m.mine ? " dm-grp--mine" : ""));
                var named = showWho(m);
                if (named) {
                    var av = el("div", "dm-grp__av");
                    av.appendChild(avatar(m.name, m.avatar, m.team, "sm"));
                    grp.appendChild(av);
                }
                col = el("div", "dm-grp__col");
                if (named) {
                    var who2 = el("div", "dm-grp__who", m.bot ? "HPL Assistant" : m.name);
                    who2.appendChild(el("small", null, m.bot ? "Automatic reply" : (m.team ? "HPL team" : "")));
                    col.appendChild(who2);
                }
                grp.appendChild(col);
                feedEl.appendChild(grp);
            }
            col.appendChild(bubble(m));
            if (m.failed) {
                var f = el("div", "dm-fail", "Not sent.");
                var retry = el("button", null, "Try again");
                retry.type = "button";
                retry.addEventListener("click", function () { resend(m); });
                f.appendChild(retry);
                col.appendChild(f);
            }
            if (m.mine && !m.pending && !m.failed) { lastMine = m; lastMineCol = col; }
            prev = m;
        });
        if (lastMine && otherRead && otherRead >= lastMine.ts && S.member) {
            lastMineCol.appendChild(el("div", "dm-seen", "Seen"));
        }
        if (stick) toBottom();
    }

    function merge(fresh) {
        var added = 0;
        fresh.forEach(function (m) {
            if (messages.some(function (x) { return x.id === m.id; })) return;
            messages.push(m); added++;
            if (m.id > lastId) lastId = m.id;
        });
        messages.sort(function (a, b) { return a.id - b.id; });
        return added;
    }

    function removeMsg(id) {
        var go = window.hplConfirm ? window.hplConfirm("Delete this message for everyone?", { ok: "Delete" }) : Promise.resolve(window.confirm("Delete this message for everyone?"));
        go.then(function (yes) {
            if (!yes) return;
            var fd = new FormData();
            fd.append("csrf", S.csrf); fd.append("action", "delete"); fd.append("thread", S.thread); fd.append("id", id); fd.append("ajax", "1");
            fetch(S.base, { method: "POST", credentials: "same-origin", body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.ok) throw new Error();
                    messages = messages.filter(function (m) { return m.id !== id; });
                    render();
                    toast("Message deleted", "ok");
                })
                .catch(function () { toast("Could not delete that message.", "error"); });
        });
    }

    var sending = false;
    function send(text, existing) {
        var temp = existing || { id: -Date.now(), sid: S.me, mine: true, bot: false, name: "", team: false, text: text, html: false, src: "", ts: nowS() };
        temp.pending = true; temp.failed = false;
        if (!existing) pending.push(temp);
        render(); toBottom();
        var fd = new FormData();
        fd.append("csrf", S.csrf); fd.append("thread", S.thread); fd.append("message", text);
        sending = true;
        return fetch(S.feed, { method: "POST", credentials: "same-origin", body: fd })
            .then(function (r) { return r.json().catch(function () { return { ok: false }; }); })
            .then(function (d) {
                sending = false;
                if (!d.ok) {
                    temp.pending = false; temp.failed = true; render();
                    toast(d.error || "Could not send. Try again.", "error");
                    return;
                }
                pending = pending.filter(function (p) { return p !== temp; });
                var list = [];
                if (d.message) list.push(d.message);
                if (d.bot) list.push(d.bot);
                merge(list);
                render(); toBottom();
                refreshList();
            })
            .catch(function () {
                sending = false;
                temp.pending = false; temp.failed = true; render();
                toast("No connection. Your message was not sent.", "error");
            });
    }
    function resend(m) { send(m.text, m); }

    function grow() {
        if (!input) return;
        input.style.height = "auto";
        input.style.height = Math.min(input.scrollHeight, 140) + "px";
        input.style.overflowY = input.scrollHeight > 140 ? "auto" : "hidden";
        if (sendBtn) sendBtn.disabled = !input.value.trim();
    }
    if (form && input) {
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var text = input.value.trim();
            if (!text || sending) return;
            input.value = ""; grow(); input.focus();
            send(text);
        });
        input.addEventListener("input", grow);
        input.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && !e.shiftKey && !e.isComposing && !mobileQ.matches) {
                e.preventDefault();
                if (form.requestSubmit) form.requestSubmit(); else form.dispatchEvent(new Event("submit", { cancelable: true }));
            }
        });
        input.addEventListener("focus", function () { setTimeout(function () { fit(); toBottom(); }, 250); });
        grow();
    }
    var quick = $("dmQuick");
    if (quick) quick.addEventListener("click", function (e) {
        var b = e.target.closest("button[data-q]");
        if (b && !sending) send(b.getAttribute("data-q"));
    });
    if (scroller && jump) {
        scroller.addEventListener("scroll", function () { if (nearBottom()) { jump.hidden = true; jump.classList.remove("has-new"); } else if (scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight > 400) jump.hidden = false; });
        jump.addEventListener("click", toBottom);
    }

    /* ---------- conversation list ---------- */
    var filter = "all";
    function applyFilter() {
        if (!listEl) return;
        var q = search ? search.value.trim().toLowerCase() : "", shown = 0;
        Array.prototype.forEach.call(listEl.children, function (li) {
            var a = li.querySelector(".dm-row");
            if (!a) return;
            var hit = !q || (a.getAttribute("data-name") || "").indexOf(q) !== -1;
            if (filter === "unread") hit = hit && a.classList.contains("is-unread");
            if (filter === "community") hit = hit && a.getAttribute("data-community") === "1";
            if (li.hasAttribute("data-pinned") && filter !== "all") hit = false;
            li.hidden = !hit; if (hit) shown++;
        });
        var nm = $("dmNoMatch");
        if (nm) nm.hidden = !((q || filter !== "all") && shown === 0);
    }
    if (search) search.addEventListener("input", applyFilter);
    root.querySelectorAll(".dm__filters [data-filter]").forEach(function (b) {
        b.addEventListener("click", function () {
            filter = b.getAttribute("data-filter");
            root.querySelectorAll(".dm__filters [data-filter]").forEach(function (x) {
                var on = x === b; x.classList.toggle("is-on", on); x.setAttribute("aria-selected", on ? "true" : "false");
            });
            applyFilter();
        });
    });

    function itemHref(id) { return S.base + "?" + (S.scope === "all" ? "scope=all&" : "") + "thread=" + id; }
    function renderList(list) {
        if (!listEl) return;
        var pinned = listEl.querySelector("li[data-pinned]");
        if (!S.admin) list.sort(function (a, b) { return (b.support - a.support) || (b.ts - a.ts); });
        var hasTeam = list.some(function (c) { return c.support; });
        listEl.textContent = "";
        if (pinned && !hasTeam) listEl.appendChild(pinned);
        list.forEach(function (c) {
            var isTeam = c.support && !S.admin;
            var unread = c.unread > 0 && c.id !== S.thread;
            var li = el("li");
            var a = el("a", "dm-row" + (c.id === S.thread ? " is-on" : "") + (unread ? " is-unread" : ""));
            a.href = itemHref(c.id);
            a.setAttribute("data-id", c.id); a.setAttribute("data-name", String(c.title).toLowerCase()); a.setAttribute("data-community", c.community ? "1" : "0");
            var avw = el("span", "dm-row__av");
            avw.appendChild(avatar(c.lead.name, c.lead.avatar, isTeam));
            if (c.lead.active && !isTeam) { var dot = el("i", "dm-online"); dot.setAttribute("aria-label", "Online"); avw.appendChild(dot); }
            var body = el("span", "dm-row__body");
            var l1 = el("span", "dm-row__line");
            l1.appendChild(el("strong", null, c.title));
            if (isTeam) l1.insertAdjacentHTML("beforeend", TICK);
            if (S.admin && c.community) l1.appendChild(el("em", "dm-chip", "Community"));
            var t = el("time", null, ago(c.ts)); t.setAttribute("data-ts", c.ts); l1.appendChild(t);
            var l2 = el("span", "dm-row__line");
            l2.appendChild(el("span", "dm-row__preview", c.preview));
            if (unread) l2.appendChild(el("b", "dm-badge", c.unread > 99 ? "99+" : String(c.unread)));
            body.appendChild(l1); body.appendChild(l2);
            a.appendChild(avw); a.appendChild(body);
            li.appendChild(a); listEl.appendChild(li);
        });
        var empty = $("dmEmpty");
        if (empty && S.admin) empty.hidden = list.length > 0;
        applyFilter();
    }
    function tickTimes() {
        if (!listEl) return;
        listEl.querySelectorAll("time[data-ts]").forEach(function (t) { t.textContent = ago(+t.getAttribute("data-ts")); });
    }

    /* ---------- new message sheet ---------- */
    var sheet = $("dmSheet"), peopleQ = $("dmPeopleQ"), peopleEl = $("dmPeople"), peopleHint = $("dmPeopleHint"), lastFocus = null, peopleTimer = null, peopleSeq = 0;
    function openSheet() {
        if (!sheet) return;
        lastFocus = document.activeElement;
        sheet.hidden = false;
        requestAnimationFrame(function () { sheet.classList.add("is-in"); });
        loadPeople("");
        setTimeout(function () { if (peopleQ && !mobileQ.matches) peopleQ.focus(); }, 60);
        document.addEventListener("keydown", sheetKey, true);
    }
    function closeSheet() {
        if (!sheet || sheet.hidden) return;
        sheet.classList.remove("is-in");
        document.removeEventListener("keydown", sheetKey, true);
        setTimeout(function () { sheet.hidden = true; if (peopleQ) peopleQ.value = ""; if (lastFocus && lastFocus.focus) lastFocus.focus(); }, 180);
    }
    function sheetKey(e) { if (e.key === "Escape") { e.preventDefault(); closeSheet(); } }
    function loadPeople(q) {
        if (!peopleEl) return;
        var seq = ++peopleSeq;
        if (peopleHint) peopleHint.textContent = "Loading…";
        fetch(S.feed + "?people=" + encodeURIComponent(q), { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (seq !== peopleSeq) return;
                peopleEl.textContent = "";
                (d.people || []).forEach(function (p) {
                    var li = el("li");
                    var a = el("a", "dm-row");
                    a.href = S.base + "?user=" + p.id;
                    var avw = el("span", "dm-row__av");
                    avw.appendChild(avatar(p.name, p.avatar, false));
                    if (p.active) avw.appendChild(el("i", "dm-online"));
                    var body = el("span", "dm-row__body");
                    var l1 = el("span", "dm-row__line"); l1.appendChild(el("strong", null, p.name));
                    var l2 = el("span", "dm-row__line"); l2.appendChild(el("span", "dm-row__preview", p.active ? "Online now" : "Creator"));
                    body.appendChild(l1); body.appendChild(l2);
                    a.appendChild(avw); a.appendChild(body); li.appendChild(a); peopleEl.appendChild(li);
                });
                if (peopleHint) peopleHint.textContent = (d.people || []).length ? "" : (q ? "No creator called “" + q + "”." : "No other creators yet.");
            })
            .catch(function () { if (seq === peopleSeq && peopleHint) peopleHint.textContent = "Could not load people. Check your connection."; });
    }
    if (sheet) {
        sheet.addEventListener("click", function (e) { if (e.target === sheet || e.target.closest("[data-dm-close]")) closeSheet(); });
        if (peopleQ) peopleQ.addEventListener("input", function () {
            clearTimeout(peopleTimer);
            var q = peopleQ.value.trim();
            peopleTimer = setTimeout(function () { loadPeople(q); }, 250);
        });
    }
    document.querySelectorAll("[data-dm-compose]").forEach(function (b) { b.addEventListener("click", openSheet); });

    /* ---------- live updates ---------- */
    var timer = null, failures = 0, stopped = false, busy = false, listTick = 0;
    function delay() {
        if (failures) return Math.min(60000, 4000 * Math.pow(2, failures - 1));
        return S.thread ? 3500 : 12000;
    }
    function schedule(ms) { if (stopped) return; clearTimeout(timer); timer = setTimeout(tick, ms == null ? delay() : ms); }
    function signedOut() { stopped = true; toast("You were signed out. Refresh the page to sign in again.", "error"); }
    function getJSON(url) {
        return fetch(url, { credentials: "same-origin", headers: { "Accept": "application/json" } }).then(function (r) {
            if (r.status === 401 || r.status === 403) { signedOut(); throw new Error("auth"); }
            if (!r.ok) throw new Error("http " + r.status);
            return r.json();
        });
    }
    function refreshList() {
        return getJSON(S.feed + "?list=1" + (S.scope === "all" ? "&scope=all" : "") + (S.thread ? "&keep=" + S.thread : "")).then(function (d) { renderList(d.list || []); }).catch(function () {});
    }
    function applyPeople(people) {
        var p = (people || [])[0];
        var read = 0;
        (people || []).forEach(function (x) { if (x.read > read) read = x.read; });
        var changed = read !== otherRead;
        otherRead = read;
        if (statusEl && p && !S.team) {
            statusEl.textContent = p.active ? "Online" : (statusEl.getAttribute("data-off") || "Offline");
            statusEl.classList.toggle("is-on", !!p.active);
        }
        return changed;
    }
    function tick() {
        if (stopped) return;
        if (document.hidden) { schedule(30000); return; }
        if (busy) { schedule(); return; }
        busy = true;
        var jobs = [];
        if (S.thread) {
            jobs.push(getJSON(S.feed + "?thread=" + S.thread + "&since=" + lastId).then(function (d) {
                var stick = nearBottom();
                var added = merge(d.messages || []);
                var readChanged = applyPeople(d.people);
                if (added || readChanged) render();
                if (added && !stick && jump) { jump.hidden = false; jump.classList.add("has-new"); }
            }));
        }
        listTick++;
        if (!S.thread || listTick % 4 === 0) jobs.push(refreshList());
        Promise.all(jobs).then(function () { busy = false; failures = 0; schedule(); })
            .catch(function () { busy = false; if (!stopped) { failures++; schedule(); } });
    }
    document.addEventListener("visibilitychange", function () { if (!document.hidden && !stopped) schedule(300); });
    setInterval(tickTimes, 30000);

    render();
    toBottom();
    if (input && !mobileQ.matches) input.focus();
    schedule(S.thread ? 600 : 12000); // first read also fetches "Seen"
})();
