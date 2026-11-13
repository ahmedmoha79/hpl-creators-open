/* Home-screen notifications (2026-11-10): the "turn on" switch. See includes/push.php for how it works.
 * window.hplPush.state() -> "on" | "off" | "blocked" | "needs-app" (iPhone: add to Home Screen first) | "unsupported"
 * window.hplPush.enable() / .disable() -> Promise<state> */
(function () {
    "use strict";
    var api = (document.querySelector('meta[name="hpl-push"]') || {}).content || "/api/push.php";
    var csrf = function () { return ((document.querySelector('meta[name="hpl-csrf"]') || {}).content) || ((document.querySelector("input[name=csrf]") || {}).value) || ""; };
    var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === "MacIntel" && navigator.maxTouchPoints > 1);
    var inApp = (window.matchMedia && matchMedia("(display-mode: standalone)").matches) || navigator.standalone === true;
    var supported = "serviceWorker" in navigator && "PushManager" in window && "Notification" in window;

    function keyBytes(b64) {
        var s = atob((b64 + "===".slice((b64.length + 3) % 4)).replace(/-/g, "+").replace(/_/g, "/"));
        var a = new Uint8Array(s.length);
        for (var i = 0; i < s.length; i++) a[i] = s.charCodeAt(i);
        return a;
    }
    function post(action, endpoint) {
        var fd = new FormData();
        fd.append("csrf", csrf()); fd.append("action", action); fd.append("endpoint", endpoint);
        return fetch(api, { method: "POST", credentials: "same-origin", body: fd }).then(function (r) { return r.json(); });
    }
    function sub() { return navigator.serviceWorker.ready.then(function (reg) { return reg.pushManager.getSubscription().then(function (s) { return { reg: reg, sub: s }; }); }); }

    function state() {
        if (!supported) return Promise.resolve(ios && !inApp ? "needs-app" : "unsupported");
        if (Notification.permission === "denied") return Promise.resolve("blocked");
        return sub().then(function (x) { return x.sub && Notification.permission === "granted" ? "on" : "off"; }).catch(function () { return "off"; });
    }
    function enable() {
        if (!supported) return state();
        return Notification.requestPermission().then(function (p) {
            if (p !== "granted") return p === "denied" ? "blocked" : "off";
            return fetch(api + "?key=1", { credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (d) {
                if (!d.ok || !d.key) throw new Error("no key");
                return sub().then(function (x) {
                    return x.sub || x.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(d.key) });
                });
            }).then(function (s) { return post("subscribe", s.endpoint); }).then(function (d) { return d.ok ? "on" : "off"; });
        }).catch(function () { return "off"; });
    }
    function disable() {
        return sub().then(function (x) {
            if (!x.sub) return "off";
            var ep = x.sub.endpoint;
            return x.sub.unsubscribe().then(function () { return post("unsubscribe", ep); }).then(function () { return "off"; });
        }).catch(function () { return "off"; });
    }
    // Keep the server's copy of this phone's address fresh (browsers change it now and then).
    if (supported && Notification.permission === "granted") {
        sub().then(function (x) {
            if (!x.sub) return;
            var k = "hplPushSent", day = String(new Date().toDateString());
            try { if (localStorage.getItem(k) === day + x.sub.endpoint.slice(-12)) return; localStorage.setItem(k, day + x.sub.endpoint.slice(-12)); } catch (e) {}
            var fd = new FormData(); fd.append("csrf", csrf()); fd.append("action", "refresh"); fd.append("endpoint", x.sub.endpoint);
            fetch(api, { method: "POST", credentials: "same-origin", body: fd }).catch(function () {});
        }).catch(function () {});
    }
    window.hplPush = { state: state, enable: enable, disable: disable };

    // Any element with data-push-card shows only while notifications are still off on this phone.
    function wire() {
        var card = document.querySelector("[data-push-card]");
        if (card) {
            var hideKey = "hplPushLater", later = 0;
            try { later = +localStorage.getItem(hideKey) || 0; } catch (e) {}
            state().then(function (s) {
                // One question at a time at the top of Home: the town question and the "add to home screen" line go first.
                // 2026-11-13: only 1 of 67 creators had phone notifications on: the card waited for the "add to home screen" bar
                // and the "how did you hear" question, so most people never saw it. Now only the path and town questions go first,
                // and "Not now" asks again after a day.
                var busy = document.querySelector(".hk-ask--town, .hk-path");
                if (s === "off" && !busy && Date.now() - later > 86400000) card.hidden = false;
            });
            var yes = card.querySelector("[data-push-yes]"), no = card.querySelector("[data-push-no]");
            if (yes) yes.addEventListener("click", function () {
                yes.disabled = true; yes.textContent = "Turning on...";
                enable().then(function (s) {
                    card.hidden = true;
                    if (window.hplToast) window.hplToast(s === "on" ? "Notifications are on." : (s === "blocked" ? "Notifications are blocked for this site in your phone's settings." : "Notifications were not turned on."), s === "on" ? "ok" : "info");
                });
            });
            if (no) no.addEventListener("click", function () { card.hidden = true; try { localStorage.setItem(hideKey, String(Date.now())); } catch (e) {} });
        }
        var row = document.querySelector("[data-push-row]");
        if (row) {
            var btn = row.querySelector("button"), note = row.querySelector("small");
            var paint = function (s) {
                var t = { on: ["Turn off", "On. You get new clips, payments and messages on this phone."], off: ["Turn on", "Get new clips, payments and messages on your phone, even when HPL is closed."],
                    blocked: ["Blocked", "Allow them in your phone's settings."],
                    "needs-app": ["How to", "On iPhone: first put HPL on your Home Screen (Share, then Add to Home Screen), then open it from there."],
                    unsupported: ["Not available", "This browser cannot show notifications. Try Chrome."] }[s];
                btn.textContent = t[0]; note.textContent = t[1];
                btn.disabled = s === "blocked" || s === "unsupported";
                btn.setAttribute("data-state", s);
            };
            state().then(paint);
            btn.addEventListener("click", function () {
                var s = btn.getAttribute("data-state");
                if (s === "needs-app") { location.href = row.getAttribute("data-app") || "/app.php"; return; }
                btn.disabled = true;
                (s === "on" ? disable() : enable()).then(paint);
            });
        }
    }
    if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", wire); else wire();
})();
