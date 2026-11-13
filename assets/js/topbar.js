(function () {
    "use strict";

    var bar = document.querySelector("[data-hplbar]");
    if (!bar) return;

    var countsUrl = bar.getAttribute("data-tb-counts") || "";
    var searchUrl = bar.getAttribute("data-tb-search") || "";
    var readUrl = bar.getAttribute("data-tb-read") || "";
    var csrf = bar.getAttribute("data-tb-csrf") || "";

    var menus = Array.prototype.slice.call(bar.querySelectorAll(".hplmenu"));

    function panelOf(menu) {
        return menu ? menu.querySelector(".hplmenu__panel") : null;
    }

    function resetPanel(p) {
        if (!p) return;
        p.style.left = "";
        p.style.right = "";
        p.style.top = "";
        p.style.bottom = "";
    }

    function closeMenus(except) {
        menus.forEach(function (m) {
            if (m === except) return;
            var p = panelOf(m);
            var b = m.querySelector("[data-tb-menu-btn]");
            if (p) {
                p.hidden = true;
                resetPanel(p);
            }
            if (b) b.setAttribute("aria-expanded", "false");
            m.classList.remove("is-open");
        });
    }

    function positionPanel(p) {
        resetPanel(p);
        var pad = 8;
        var rect = p.getBoundingClientRect();
        if (rect.left < pad) {
            p.style.left = (pad - rect.left) + "px";
            p.style.right = "auto";
            rect = p.getBoundingClientRect();
        }
        if (rect.right > window.innerWidth - pad) {
            p.style.left = "auto";
            p.style.right = (rect.right - (window.innerWidth - pad)) + "px";
            rect = p.getBoundingClientRect();
        }
        if (rect.bottom > window.innerHeight - pad) {
            p.style.top = "auto";
            p.style.bottom = "calc(100% + 10px)";
        }
    }

    function openMenu(menu) {
        if (!menu) return;
        closeMenus(menu);
        var p = panelOf(menu);
        var b = menu.querySelector("[data-tb-menu-btn]");
        if (!p || !b) return;
        p.hidden = false;
        b.setAttribute("aria-expanded", "true");
        menu.classList.add("is-open");
        positionPanel(p);
        if (menu.getAttribute("data-tb-menu") === "updates") markUpdatesSeen();
        if (menu.getAttribute("data-tb-menu") === "notifications") markNotificationsSeen();
    }

    function toggleMenu(menu) {
        var p = panelOf(menu);
        if (p && !p.hidden) {
            closeMenus(null);
        } else {
            openMenu(menu);
        }
    }

    menus.forEach(function (menu) {
        var btn = menu.querySelector("[data-tb-menu-btn]");
        if (!btn) return;
        btn.addEventListener("click", function (e) {
            e.preventDefault();
            e.stopPropagation();
            toggleMenu(menu);
        });
        menu.addEventListener("keydown", function (e) {
            if (e.key === "Escape") {
                closeMenus(null);
                btn.focus();
            }
        });
    });

    document.addEventListener("click", function (e) {
        if (!e.target.closest(".hplmenu")) closeMenus(null);
    });
    window.addEventListener("resize", function () { closeMenus(null); });

    // Compact avatar-menu shortcuts (mobile): open the relevant menu.
    bar.querySelectorAll("[data-tb-open-updates]").forEach(function (el) {
        el.addEventListener("click", function (e) {
            e.preventDefault();
            openMenu(bar.querySelector('[data-tb-menu="updates"]'));
        });
    });

    // Contact support: reuse the dashboard modal when present.
    bar.querySelectorAll("[data-tb-support]").forEach(function (el) {
        el.addEventListener("click", function (e) {
            if (!document.getElementById("csOverlay")) return;
            var trigger = document.querySelector('[data-modal="contact-support"]');
            if (trigger) {
                e.preventDefault();
                closeMenus(null);
                trigger.click();
            }
        });
    });

    // What's new dot
    var UPDATES_KEY = "hpl_updates_seen_v1";
    var UPDATES_VERSION = "2026-10";
    var updateDot = bar.querySelector("[data-tb-update-dot]");

    function markUpdatesSeen() {
        if (updateDot) updateDot.hidden = true;
        try { localStorage.setItem(UPDATES_KEY, UPDATES_VERSION); } catch (err) {}
    }
    try {
        if (updateDot && localStorage.getItem(UPDATES_KEY) === UPDATES_VERSION) {
            updateDot.hidden = true;
        }
    } catch (err) {}

    // Mac keyboard hint
    var isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || "");
    if (isMac) {
        bar.querySelectorAll("[data-tb-mod]").forEach(function (el) { el.textContent = "\u2318"; });
    }

    // ---------------------------------------------------------
    // Badges + polling
    // ---------------------------------------------------------
    function paintBadge(key, n) {
        var badge = bar.querySelector('[data-tb-badge="' + key + '"]');
        if (!badge) return;
        if (n > 0) {
            badge.textContent = n > 9 ? "9+" : String(n);
            badge.classList.remove("is-empty");
        } else {
            badge.textContent = "";
            badge.classList.add("is-empty");
        }
    }

    function paintLabel(link, base, n) {
        if (!link) return;
        var label = base + (n > 0 ? ", " + n + " unread" : "");
        link.setAttribute("aria-label", label);
        if (link.hasAttribute("title")) link.setAttribute("title", base);
    }

    function painter(data) {
        if (!data) return;
        if (typeof data.unreadMessages === "number") {
            paintBadge("messages", data.unreadMessages);
            paintLabel(bar.querySelector('a[href$="messages.php"]'), "Messages", data.unreadMessages);
        }
        if (typeof data.unreadNotifications === "number") {
            paintBadge("notifications", data.unreadNotifications);
            var notifBtn = bar.querySelector('[data-tb-menu="notifications"] [data-tb-menu-btn]');
            paintLabel(notifBtn, "Notifications", data.unreadNotifications);
        }
        if (typeof data.balanceFormatted === "string") {
            var bal = bar.querySelector("[data-tb-balance]");
            if (bal) {
                bal.textContent = data.balanceFormatted;
                var pill = bal.closest(".hplbar__balance");
                if (pill) pill.setAttribute("aria-label", "Balance: " + data.balanceFormatted);
            }
        }
    }

    // ---------------------------------------------------------
    // New direct messages: pop-up card, short chime, count in the tab title,
    // and a phone/desktop alert when the site is in the background (if allowed).
    // ---------------------------------------------------------
    var store = {
        get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
        set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} }
    };
    var soundOn = store.get("hplDmSound") !== "off";
    var baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, "");
    var audioCtx = null;

    function unlockAudio() {
        try {
            var AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            if (!audioCtx) audioCtx = new AC();
            if (audioCtx.state === "suspended") audioCtx.resume();
        } catch (e) {}
    }
    ["pointerdown", "keydown", "touchstart"].forEach(function (ev) {
        document.addEventListener(ev, unlockAudio, { passive: true });
    });

    function chime() {
        if (!soundOn || !audioCtx || audioCtx.state !== "running") return;
        try {
            var t = audioCtx.currentTime;
            [[880, 0], [1320, 0.12]].forEach(function (n) {
                var o = audioCtx.createOscillator(), g = audioCtx.createGain();
                o.type = "sine";
                o.frequency.value = n[0];
                g.gain.setValueAtTime(0.0001, t + n[1]);
                g.gain.exponentialRampToValueAtTime(0.18, t + n[1] + 0.02);
                g.gain.exponentialRampToValueAtTime(0.0001, t + n[1] + 0.35);
                o.connect(g); g.connect(audioCtx.destination);
                o.start(t + n[1]); o.stop(t + n[1] + 0.4);
            });
        } catch (e) {}
    }

    // The message banner (2026-11-10): small, like a phone notification. One line of who, up to two lines of what.
    // The whole banner is the button (tap = open); a small x closes it; swipe it up to throw it away.
    // It waits while a pop-up window is open, so two things never sit on top of each other.
    // Owner, 2026-11-13: "like WhatsApp": a light card that drops from the top of the screen, app name and time on top,
    // the title in bold and the message under it (up to 3 lines, nothing cut mid-word on the first line), tap to open,
    // swipe up or x to close, it stays while a finger is on it.
    var popCss = ".hpldm{position:fixed;top:calc(8px + env(safe-area-inset-top));right:12px;z-index:5002;width:min(380px,calc(100vw - 24px));background:rgba(255,255,255,.97);-webkit-backdrop-filter:blur(14px);backdrop-filter:blur(14px);color:#1b1511;border-radius:20px;box-shadow:0 12px 34px rgba(20,12,6,.22),0 0 0 1px rgba(20,12,6,.06);font:14px/1.38 Inter,system-ui,sans-serif;opacity:0;transform:translateY(-130%);transition:opacity .2s ease,transform .38s cubic-bezier(.2,1.25,.35,1);touch-action:pan-x;overflow:hidden}"
        + ".hpldm.is-in{opacity:1;transform:none}"
        + "@media (max-width:720px){.hpldm{right:8px;left:8px;width:auto}}"
        + ".hpldm__link{display:block;padding:11px 44px 13px 14px;color:inherit!important;text-decoration:none!important}"
        + ".hpldm__app{display:flex;align-items:center;gap:7px;font-size:12px;color:#7b7064;margin-bottom:5px}"
        + ".hpldm__app img{width:18px;height:18px;border-radius:5px}.hpldm__app b{font-weight:600;color:#4b4036}"
        + ".hpldm__row{display:flex;gap:11px;align-items:flex-start}"
        + ".hpldm__ico{flex:none;width:38px;height:38px;border-radius:50%;background:#f2c14e;color:#1b1511;display:grid;place-items:center;font-weight:800;font-size:16px}"
        + ".hpldm__main{flex:1;min-width:0}.hpldm__from{font-weight:700;font-size:15px;color:#140d08;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}"
        + ".hpldm__text{color:#3b322b;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;overflow-wrap:anywhere;margin-top:1px}"
        + ".hpldm__x{position:absolute;top:6px;right:4px;width:36px;height:36px;border:0;background:none;color:#8a7f73;font-size:20px;line-height:1;cursor:pointer}"
        + ".hpldm__bar{position:absolute;left:0;bottom:0;height:3px;width:100%;background:#f2c14e;transform-origin:left;animation:hpldmbar 8s linear forwards}"
        + ".hpldm.is-held .hpldm__bar{animation-play-state:paused}"
        + "@keyframes hpldmbar{to{transform:scaleX(0)}}@media (prefers-reduced-motion:reduce){.hpldm{transition:opacity .2s}.hpldm__bar{display:none}}"
        + ":root[data-theme=dark] .hpldm{background:rgba(38,30,24,.97);color:#f3e9dc;box-shadow:0 12px 34px rgba(0,0,0,.5)}:root[data-theme=dark] .hpldm__from{color:#fff}:root[data-theme=dark] .hpldm__text{color:#d8cab9}:root[data-theme=dark] .hpldm__app b{color:#e8dccb}"
        + ".hplalerts{display:flex;gap:6px;flex-wrap:wrap;padding:0 14px 10px}"
        + ".hplalerts__btn{height:30px;padding:0 10px;border-radius:99px;border:1px solid var(--tb-line,#e9dfd1);background:var(--tb-chip,#fbf8f3);color:var(--tb-text,#1f1510);font:600 12px Inter,system-ui,sans-serif;cursor:pointer}";
    var popStyle = document.createElement("style");
    popStyle.textContent = popCss;
    document.head.appendChild(popStyle);

    var openPop = null;
    function showDmPopup(dm) {
        showPopup((dm.from || "?").charAt(0).toUpperCase(), (dm.from || "Someone") + " sent you a message", dm.text || "", dm.url, "Open chat");
    }
    /** Is a pop-up window (bonus, join, payout, confirm...) open right now? */
    function modalOpen() {
        var list = document.querySelectorAll('[role="dialog"][aria-modal="true"], .hpl-modal');
        for (var i = 0; i < list.length; i++) { if (list[i].getClientRects().length && getComputedStyle(list[i]).visibility !== "hidden") return true; }
        return false;
    }
    function showPopup(ico, head, text, url, cta, tries) {
        if (modalOpen() && (tries || 0) < 40) { setTimeout(function () { showPopup(ico, head, text, url, cta, (tries || 0) + 1); }, 3000); return; }
        if (openPop) openPop.remove();
        var box = document.createElement("div");
        box.className = "hpldm";
        box.setAttribute("role", "status");
        box.innerHTML = '<a class="hpldm__link"><span class="hpldm__app"><img alt="" src="' + (window.HPL_ICON || '/assets/img/app-192.png') + '"><b>HPL Creators</b><span>now</span></span>'
            + '<span class="hpldm__row"><span class="hpldm__ico"></span><span class="hpldm__main"><span class="hpldm__from"></span><span class="hpldm__text"></span></span></span></a>'
            + '<button type="button" class="hpldm__x" aria-label="Close">&times;</button><i class="hpldm__bar"></i>';
        box.querySelector(".hpldm__ico").textContent = ico;
        box.querySelector(".hpldm__from").textContent = head;
        box.querySelector(".hpldm__text").textContent = text;
        if (!text) box.querySelector(".hpldm__text").style.display = "none";
        box.querySelector(".hpldm__link").setAttribute("href", url);
        box.querySelector(".hpldm__link").setAttribute("aria-label", head + ". " + (cta || "Open"));
        var timer = null;
        function close() { clearTimeout(timer); box.classList.remove("is-in"); setTimeout(function () { box.remove(); }, 200); if (openPop === box) openPop = null; }
        box.querySelector(".hpldm__x").addEventListener("click", close);
        // Swipe up to dismiss.
        var y0 = null;
        box.addEventListener("touchstart", function (e) { y0 = e.touches[0].clientY; }, { passive: true });
        box.addEventListener("touchmove", function (e) { if (y0 !== null && y0 - e.touches[0].clientY > 24) { y0 = null; close(); } }, { passive: true });
        // A finger on it keeps it open (to read); letting go gives it 4 more seconds.
        box.addEventListener("touchstart", function () { clearTimeout(timer); box.classList.add("is-held"); }, { passive: true });
        box.addEventListener("touchend", function () { box.classList.remove("is-held"); clearTimeout(timer); timer = setTimeout(close, 4000); }, { passive: true });
        box.addEventListener("mouseenter", function () { clearTimeout(timer); box.classList.add("is-held"); });
        box.addEventListener("mouseleave", function () { box.classList.remove("is-held"); clearTimeout(timer); timer = setTimeout(close, 4000); });
        document.body.appendChild(box);
        openPop = box;
        requestAnimationFrame(function () { requestAnimationFrame(function () { box.classList.add("is-in"); }); });
        timer = setTimeout(close, 8000);
    }

    function systemAlert(dm) {
        try {
            if (!("Notification" in window) || Notification.permission !== "granted") return;
            var n = new Notification((dm.from || "Someone") + " sent you a message", { body: dm.text || "", tag: "hpl-dm-" + dm.thread });
            n.onclick = function () { window.focus(); location.href = dm.url; n.close(); };
        } catch (e) {}
    }

    function viewingThread(id) {
        if (!/messages\.php$/.test(location.pathname)) return false;
        try { return Number(new URLSearchParams(location.search).get("thread")) === Number(id); } catch (e) { return false; }
    }

    function paintTitle(n) {
        document.title = (n > 0 ? "(" + (n > 9 ? "9+" : n) + ") " : "") + baseTitle;
    }

    // Remembered per account, so an admin and a creator on the same phone do not mix.
    var seenKey = "hplDmSeen:" + (bar.getAttribute("data-tb-uid") || "0");
    function handleDm(data) {
        if (typeof data.unreadMessages === "number") paintTitle(data.unreadMessages);
        var dm = data.latestDm;
        if (!dm || !dm.id) {
            if (store.get(seenKey) === null) store.set(seenKey, "0"); // nothing unread yet: the next message alerts
            return;
        }
        var raw = store.get(seenKey);
        if (raw === null) { store.set(seenKey, String(dm.id)); return; } // first visit: no alert for old messages
        if (dm.id <= Number(raw)) return;
        store.set(seenKey, String(dm.id));
        if (viewingThread(dm.thread)) return; // the chat on screen shows it already
        chime();
        if (document.visibilityState === "hidden") { systemAlert(dm); pendingPop = dm; } // show it when they come back
        else showDmPopup(dm);
    }
    var pendingPop = null;

    // Reminders and announcements from the team pop up once, like messages.
    var ntKey = "hplNtSeen:" + (bar.getAttribute("data-tb-uid") || "0");
    var pendingNotice = null;
    function handleNotice(data) {
        if (!("latestNotice" in data)) return;
        var nt = data.latestNotice;
        if (!nt || !nt.id) { if (store.get(ntKey) === null) store.set(ntKey, "0"); return; }
        var raw = store.get(ntKey);
        if (raw === null) { store.set(ntKey, "0"); } // first visit: still show the newest unread reminder once
        if (nt.id <= Number(store.get(ntKey))) return;
        store.set(ntKey, String(nt.id));
        chime();
        if (document.visibilityState === "hidden") { pendingNotice = nt; return; }
        showPopup(nt.icon || "🔔", nt.title, nt.text, nt.url, "Open");
    }

    var lastCounts = 0;
    // Signed out (session ended, or the account was paused): stop asking every few seconds and show the sign-in
    // screen, which explains why. Before 2026-11-12 an open page kept asking and was told "Please sign in" forever.
    var signedOut = false;
    function checkSignedOut(r) {
        if (!r || r.status !== 401 || signedOut) return false;
        signedOut = true; countsUrl = ""; dmUrl = "";
        var go = function () { location.reload(); };
        if (document.visibilityState === "visible") go(); else document.addEventListener("visibilitychange", function () { if (document.visibilityState === "visible") go(); });
        return true;
    }
    function refreshCounts(force) {
        if (!countsUrl) return;
        var hidden = document.visibilityState === "hidden";
        var now = Date.now();
        if (!force && now - lastCounts < (hidden ? 55000 : 5000)) return;
        lastCounts = now;
        fetch(withOpen(countsUrl), { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (r) { return checkSignedOut(r) ? null : (r.ok ? r.json() : null); })
            .then(function (data) { if (!data) return; painter(data); handleDm(data); handleNotice(data); })
            .catch(function () {});
    }

    // Fast, light check for new messages (every 4 seconds while the site is open on screen;
    // once a minute in the background). The full refresh above runs every 30 seconds.
    var dmUrl = countsUrl ? countsUrl.replace("action=counts", "action=dm") : "";
    // On an open chat, tell the server which one: it is read already, so it never shows as new.
    var openThread = 0;
    try { if (/messages\.php$/.test(location.pathname)) openThread = Number(new URLSearchParams(location.search).get("thread")) || 0; } catch (e) {}
    function withOpen(url) { // only while the chat is actually on screen
        if (!openThread || document.visibilityState === "hidden") return url;
        return url + (url.indexOf("?") < 0 ? "?" : "&") + "open=" + openThread;
    }
    var dmBusy = false, lastDm = 0, lastDmFull = 0, dmVer = "";
    var pingUrl = dmUrl ? dmUrl.replace(/topbar\.php.*$/, "ping.php") : "";
    function pollDm(force) {
        if (!dmUrl || dmBusy) return;
        var now = Date.now();
        if (!force && now - lastDm < (document.visibilityState === "hidden" ? 55000 : 3500)) return;
        lastDm = now;
        dmBusy = true;
        // Fast lane (2026-11-11): ask the database-free ping first; fetch the details only when the
        // message version changed, on an open chat, or once a minute anyway.
        fetch(pingUrl, { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (r) { return r.ok ? r.json() : null; }).catch(function () { return null; })
        .then(function (pg) {
            var changed = !pg || !pg.ok || pg.dm !== dmVer;
            if (pg && pg.ok) dmVer = pg.dm;
            if (!changed && !force && !openThread && Date.now() - lastDmFull < 60000) { dmBusy = false; return; }
            lastDmFull = Date.now();
            return fetch(withOpen(dmUrl), { credentials: "same-origin", headers: { "Accept": "application/json" } })
            .then(function (r) { return checkSignedOut(r) ? null : (r.ok ? r.json() : null); })
            .then(function (data) {
                if (!data || !data.ok) return;
                paintBadge("messages", data.unreadMessages);
                paintLabel(bar.querySelector('a[href$="messages.php"]'), "Messages", data.unreadMessages);
                handleDm(data);
            })
            .catch(function () {})
            .then(function () { dmBusy = false; });
        });
    }

    setInterval(refreshCounts, 30000);
    setInterval(pollDm, 4000);
    setTimeout(function () { refreshCounts(true); }, 800);
    document.addEventListener("visibilitychange", function () {
        if (document.visibilityState !== "visible") return;
        if (pendingPop) { showDmPopup(pendingPop); pendingPop = null; }
        else if (pendingNotice) { showPopup(pendingNotice.icon || "🔔", pendingNotice.title, pendingNotice.text, pendingNotice.url, "Open"); pendingNotice = null; }
        refreshCounts(true);
        pollDm(true);
    });

    // Sound and phone-alert switches inside the bell menu.
    var soundBtn = bar.querySelector("[data-tb-sound]");
    function paintSound() {
        if (!soundBtn) return;
        soundBtn.textContent = "Message sound: " + (soundOn ? "on" : "off");
        soundBtn.setAttribute("aria-pressed", soundOn ? "true" : "false");
    }
    if (soundBtn) {
        paintSound();
        soundBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            soundOn = !soundOn;
            store.set("hplDmSound", soundOn ? "on" : "off");
            paintSound();
            unlockAudio();
            if (soundOn) setTimeout(chime, 60);
        });
    }
    var pushBtn = bar.querySelector("[data-tb-push]");
    if (pushBtn && "Notification" in window && Notification.permission === "default") {
        pushBtn.hidden = false;
        pushBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            try {
                Notification.requestPermission().then(function (p) {
                    pushBtn.hidden = true;
                    if (window.hplToast) hplToast(p === "granted" ? "Phone alerts are on for this browser." : "Alerts stay off. You can change this in your browser settings.", p === "granted" ? "ok" : "info");
                });
            } catch (err) { pushBtn.hidden = true; }
        });
    }

    // ---------------------------------------------------------
    // Opening the bell clears the badge (items stay highlighted until opened)
    // ---------------------------------------------------------
    function markNotificationsSeen() {
        var badge = bar.querySelector('[data-tb-badge="notifications"]');
        if (!badge || badge.classList.contains("is-empty")) return;
        paintBadge("notifications", 0);
        var notifBtn = bar.querySelector('[data-tb-menu="notifications"] [data-tb-menu-btn]');
        paintLabel(notifBtn, "Notifications", 0);
        var seenUrl = bar.getAttribute("data-tb-seen");
        if (!seenUrl) return;
        var body = new URLSearchParams();
        body.append("csrf", csrf);
        fetch(seenUrl, {
            method: "POST",
            credentials: "same-origin",
            headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded" },
            body: body.toString()
        }).catch(function () {});
    }

    // ---------------------------------------------------------
    // Notification "mark all as read"
    // ---------------------------------------------------------
    var readAll = bar.querySelector("[data-tb-readall]");
    if (readAll) {
        readAll.addEventListener("click", function () {
            if (!readUrl) return;
            var body = new URLSearchParams();
            body.append("csrf", csrf);
            readAll.disabled = true;
            fetch(readUrl, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Accept": "application/json", "Content-Type": "application/x-www-form-urlencoded" },
                body: body.toString()
            }).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) {
                if (data && data.ok) {
                    bar.querySelectorAll(".hplnotif.is-unread").forEach(function (el) {
                        el.classList.remove("is-unread");
                    });
                    paintBadge("notifications", 0);
                    var notifBtn = bar.querySelector('[data-tb-menu="notifications"] [data-tb-menu-btn]');
                    paintLabel(notifBtn, "Notifications", 0);
                }
            }).catch(function () {}).then(function () { readAll.disabled = false; });
        });
    }

    // ---------------------------------------------------------
    // Command palette
    // ---------------------------------------------------------
    var palette = document.querySelector("[data-tb-palette]");
    if (!palette) return;

    var input = palette.querySelector("[data-tb-palette-input]");
    var results = palette.querySelector("[data-tb-palette-results]");
    var opener = bar.querySelector("[data-tb-search-open]");
    var lastFocus = null;
    var activeIndex = -1;
    var debounceTimer = null;
    var requestSeq = 0;

    function paletteItems() {
        return Array.prototype.slice.call(results.querySelectorAll(".hplpalette__item"));
    }

    function setActive(index) {
        var items = paletteItems();
        if (!items.length) { activeIndex = -1; return; }
        if (index < 0) index = items.length - 1;
        if (index >= items.length) index = 0;
        activeIndex = index;
        items.forEach(function (el, i) {
            el.classList.toggle("is-active", i === activeIndex);
            if (i === activeIndex) {
                el.setAttribute("aria-selected", "true");
                var box = results.getBoundingClientRect();
                var r = el.getBoundingClientRect();
                if (r.top < box.top) results.scrollTop -= (box.top - r.top) + 6;
                else if (r.bottom > box.bottom) results.scrollTop += (r.bottom - box.bottom) + 6;
            } else {
                el.removeAttribute("aria-selected");
            }
        });
    }

    function icon(name) {
        var paths = {
            search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/>',
            campaign: '<path d="M4 10v4l12 5V5L4 10Z"/><path d="M16 8.5a3.5 3.5 0 0 1 0 7"/><path d="M7 15v4"/>',
            course: '<path d="M4 7.5 12 4l8 3.5-8 3.5-8-3.5Z"/><path d="M7 9.4V15c0 1.4 2.2 2.5 5 2.5s5-1.1 5-2.5V9.4"/>',
            post: '<path d="M5 4h14v16l-3-2-4 2-4-2-3 2V4Z"/><path d="M8.5 9h7M8.5 13h5"/>',
            people: '<circle cx="9" cy="8" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><path d="M16 6.2a3 3 0 0 1 0 5.6M17 19a5.5 5.5 0 0 0-2.4-4.5"/>',
            arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>'
        };
        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || paths.search) + "</svg>";
    }

    function escapeHtml(s) {
        return String(s == null ? "" : s)
            .replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;").replace(/'/g, "&#39;");
    }

    function renderGroups(groups, query) {
        results.innerHTML = "";
        activeIndex = -1;
        if (!groups || !groups.length) {
            results.innerHTML = '<div class="hplpalette__empty">No results for &ldquo;' + escapeHtml(query || "") + '&rdquo;. Try a different word.</div>';
            return;
        }
        groups.forEach(function (group) {
            if (!group.items || !group.items.length) return;
            var label = document.createElement("div");
            label.className = "hplpalette__group-label";
            label.textContent = group.label;
            results.appendChild(label);
            group.items.forEach(function (item) {
                var a = document.createElement("a");
                a.className = "hplpalette__item";
                a.href = item.url;
                a.setAttribute("role", "option");
                a.innerHTML =
                    '<span class="hplpalette__item-ico">' + icon(item.icon) + "</span>" +
                    '<span class="hplpalette__item-txt"><strong>' + escapeHtml(item.title) + "</strong>" +
                    (item.sub ? "<small>" + escapeHtml(item.sub) + "</small>" : "") + "</span>" +
                    '<span class="hplpalette__item-arrow">' + icon("arrow") + "</span>";
                a.addEventListener("mousemove", function () {
                    var items = paletteItems();
                    var i = items.indexOf(a);
                    if (i !== -1 && i !== activeIndex) setActive(i);
                });
                results.appendChild(a);
            });
        });
    }

    function load(query) {
        var seq = ++requestSeq;
        results.innerHTML = '<div class="hplpalette__loading">' + icon("search") + "<span>Searching...</span></div>";
        fetch(searchUrl + "&q=" + encodeURIComponent(query), {
            credentials: "same-origin",
            headers: { "Accept": "application/json" }
        }).then(function (r) { return r.ok ? r.json() : null; }).then(function (data) {
            if (seq !== requestSeq) return;
            renderGroups(data && data.groups ? data.groups : [], query);
        }).catch(function () {
            if (seq !== requestSeq) return;
            results.innerHTML = '<div class="hplpalette__empty">Search is unavailable right now.</div>';
        });
    }

    function openPalette() {
        lastFocus = document.activeElement;
        closeMenus(null);
        palette.hidden = false;
        if (opener) opener.setAttribute("aria-expanded", "true");
        document.body.style.overflow = "hidden";
        input.value = "";
        load("");
        setTimeout(function () { input.focus(); }, 30);
    }

    function closePalette() {
        palette.hidden = true;
        if (opener) opener.setAttribute("aria-expanded", "false");
        document.body.style.overflow = "";
        if (lastFocus && typeof lastFocus.focus === "function") lastFocus.focus();
    }

    function paletteOpen() {
        return !palette.hidden;
    }

    if (opener) {
        opener.addEventListener("click", function (e) {
            e.preventDefault();
            openPalette();
        });
    }
    palette.querySelectorAll("[data-tb-palette-close]").forEach(function (el) {
        el.addEventListener("click", closePalette);
    });

    input.addEventListener("input", function () {
        clearTimeout(debounceTimer);
        var q = input.value.trim();
        if (q.length === 1) {
            requestSeq++;
            results.innerHTML = '<div class="hplpalette__empty">Keep typing to search...</div>';
            return;
        }
        debounceTimer = setTimeout(function () { load(q); }, 140);
    });

    input.addEventListener("keydown", function (e) {
        if (e.key === "ArrowDown") {
            e.preventDefault();
            setActive(activeIndex + 1);
        } else if (e.key === "ArrowUp") {
            e.preventDefault();
            setActive(activeIndex - 1);
        } else if (e.key === "Enter") {
            var items = paletteItems();
            var target = activeIndex >= 0 ? items[activeIndex] : items[0];
            if (target) {
                e.preventDefault();
                window.location.href = target.getAttribute("href");
            }
        } else if (e.key === "Escape") {
            e.preventDefault();
            closePalette();
        }
    });

    document.addEventListener("keydown", function (e) {
        if ((e.ctrlKey || e.metaKey) && (e.key === "k" || e.key === "K")) {
            e.preventDefault();
            if (paletteOpen()) closePalette();
            else openPalette();
            return;
        }
        if (e.key === "Escape") {
            if (paletteOpen()) {
                closePalette();
            } else {
                closeMenus(null);
            }
        }
    });
})();
