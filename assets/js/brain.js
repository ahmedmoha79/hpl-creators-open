/* HPL Brain eyes (2026-11-12): tells the server when something breaks on a phone, so the owner sees bugs before
   people complain. No personal data: the error text, the page, and the kind of phone. At most 5 reports a page. */
(function () {
    var sent = 0, seen = {}, url = "/api/brain.php";
    function phone() {
        var u = navigator.userAgent || "";
        var os = /Android/.test(u) ? "Android" : /iPhone|iPad/.test(u) ? "iPhone" : /Windows/.test(u) ? "Windows" : /Mac/.test(u) ? "Mac" : "Other";
        var br = /SamsungBrowser/.test(u) ? "Samsung Internet" : /OPR|Opera/.test(u) ? "Opera" : /Edg/.test(u) ? "Edge" : /CriOS|Chrome/.test(u) ? "Chrome" : /Firefox/.test(u) ? "Firefox" : /Safari/.test(u) ? "Safari" : "Other";
        return os + " / " + br + ((window.matchMedia && matchMedia("(display-mode: standalone)").matches) ? " (app)" : "");
    }
    function report(kind, msg) {
        msg = String(msg || "").slice(0, 300);
        if (!msg || sent >= 5 || seen[kind + msg]) return;
        if (/ResizeObserver loop|Script error\.?$|extension:\/\/|chrome-extension|Java object is gone|navigation_performance_logger/.test(msg)) return; // browser noise, not our bug
        seen[kind + msg] = 1; sent++;
        var body = JSON.stringify({ k: kind, m: msg, p: location.pathname, d: phone() });
        try { if (navigator.sendBeacon) { navigator.sendBeacon(url, new Blob([body], { type: "application/json" })); return; } } catch (e) {}
        try { fetch(url, { method: "POST", body: body, headers: { "Content-Type": "application/json" }, keepalive: true, credentials: "same-origin" }); } catch (e) {}
    }
    window.addEventListener("error", function (e) {
        if (e && e.target && e.target !== window && (e.target.src || e.target.href)) return; // a picture failed: not a code bug
        // Only our own code: Facebook's and other apps' browsers add their own scripts that break by themselves (2026-11-12).
        if (!e || !e.filename || e.filename.indexOf(location.origin) !== 0) return;
        report("jserror", (e && e.message ? e.message : "error") + (e && e.filename ? " @" + e.filename.replace(location.origin, "").split("?")[0] + ":" + (e.lineno || 0) : ""));
    });
    window.addEventListener("unhandledrejection", function (e) { var r = e && e.reason; report("jserror", "Promise: " + (r && r.message ? r.message : String(r))); });
    // Our own requests that fail (server error, or no answer at all while online).
    if (window.fetch) {
        var f0 = window.fetch;
        window.fetch = function (input, init) {
            var u = typeof input === "string" ? input : (input && input.url) || "";
            var own = u.indexOf("http") !== 0 || u.indexOf(location.origin) === 0;
            return f0.apply(this, arguments).then(function (r) {
                if (own && r && r.status >= 500 && u.indexOf(url) < 0) report("failreq", r.status + " " + u.replace(location.origin, "").split("?")[0]);
                return r;
            }, function (err) {
                if (own && navigator.onLine && u.indexOf(url) < 0 && !(init && init.signal && init.signal.aborted)) report("failreq", "No answer " + u.replace(location.origin, "").split("?")[0]);
                throw err;
            });
        };
    }
    window.hplBrainReport = report;
})();
