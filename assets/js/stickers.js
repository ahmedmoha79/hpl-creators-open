/* HPL sticker packs (2026-11-09): one picker for the live chat and the feed.
 * Tabs per pack, locked packs show how to unlock (level, or pay once from the wallet).
 * Stickers sit still in the chat; they play their animation when they arrive and when tapped (light on phones). */
(function () {
    "use strict";
    var store = { packs: null, api: "", csrf: "" };
    var LS = "hplStickerTab";

    function el(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }
    function animUrl(url) { return url && !/\.a\.svg/.test(url) ? url.replace(/\.(svg|webp)(\?|$)/, ".a.svg$2") : url; }
    function stillUrl(url) { return url ? url.replace(/\.a\.svg(\?|$)/, ".svg$1") : url; }
    function toast(msg, kind) { if (window.hplToast) window.hplToast(msg, kind || "info"); }

    /** Play a sticker image's animation for a few seconds, then go back to the still picture. */
    function play(img, ms) {
        if (!img || !img.src || /\.a\.svg/.test(img.src)) return;
        if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
        var still = img.src;
        img.src = animUrl(still);
        clearTimeout(img._hplStill);
        img._hplStill = setTimeout(function () { img.src = still; }, ms || 3500);
    }

    function init(opts) {
        if (opts.packs) store.packs = opts.packs;
        if (opts.api) store.api = opts.api;
        if (opts.csrf) store.csrf = opts.csrf;
    }

    function openPacks() { return (store.packs || []).filter(function (p) { return p.open; }); }

    function buy(pack, btn, done) {
        if (!store.api) return;
        btn.disabled = true; btn.textContent = "Unlocking...";
        var fd = new FormData();
        fd.append("csrf", store.csrf || (document.querySelector("input[name=csrf]") || {}).value || "");
        fd.append("action", "buy_pack"); fd.append("pack", pack.key);
        fetch(store.api, { method: "POST", credentials: "same-origin", body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { toast(d.error || "Could not unlock the pack.", "error"); btn.disabled = false; btn.textContent = pack.why; return; }
                store.packs = d.packs;
                toast(pack.name + " unlocked. Enjoy!", "ok");
                done();
            })
            .catch(function () { toast("No connection. Try again.", "error"); btn.disabled = false; btn.textContent = pack.why; });
    }

    /** Fill `box` with the picker. onPick(sticker) is called with {key,label,url}. */
    function render(box, onPick) {
        var packs = store.packs || [];
        box.innerHTML = "";
        box.classList.add("stk");
        var cur = null;
        try { cur = localStorage.getItem(LS); } catch (e) {}
        if (!packs.some(function (p) { return p.key === cur; })) cur = packs.length ? packs[0].key : null;

        var tabs = el("div", "stk__tabs"); tabs.setAttribute("role", "tablist");
        var head = el("div", "stk__head");
        var grid = el("div", "stk__grid");
        box.appendChild(tabs); box.appendChild(head); box.appendChild(grid);

        function show(key) {
            cur = key;
            try { localStorage.setItem(LS, key); } catch (e) {}
            var p = packs.filter(function (x) { return x.key === key; })[0];
            if (!p) return;
            [].forEach.call(tabs.children, function (t) { t.setAttribute("aria-selected", t.getAttribute("data-k") === key ? "true" : "false"); });
            head.innerHTML = "";
            var t = el("div", "stk__title"); t.appendChild(el("strong", null, p.name)); t.appendChild(el("span", null, p.desc));
            head.appendChild(t);
            if (!p.open) {
                var lock = el("div", "stk__lock");
                if (p.lock === "buy") {
                    var b = el("button", "stk__buy", p.why); b.type = "button";
                    var armed = false;
                    b.addEventListener("click", function (e) {
                        e.stopPropagation();
                        if (!armed) { armed = true; b.textContent = "Tap again to pay " + p.why.replace(/^Unlock for /, "") + " from your wallet"; b.classList.add("is-armed"); return; }
                        buy(p, b, function () { packs = store.packs; render(box, onPick); });
                    });
                    lock.appendChild(b);
                    lock.appendChild(el("small", null, "Paid once. Yours to keep."));
                } else {
                    lock.appendChild(el("span", "stk__need", p.why));
                    lock.appendChild(el("small", null, "Your level grows with the views your clips get."));
                }
                head.appendChild(lock);
            }
            grid.innerHTML = "";
            grid.classList.toggle("is-locked", !p.open);
            p.stickers.forEach(function (s, i) {
                var b = el("button", "stk__item"); b.type = "button"; b.title = s.label;
                b.setAttribute("aria-label", (p.open ? "Send sticker: " : "Locked sticker: ") + s.label);
                var img = document.createElement("img"); img.src = s.url; img.alt = ""; img.loading = "lazy"; img.decoding = "async";
                img.width = 64; img.height = 64;
                b.style.setProperty("--i", Math.min(i, 20));
                b.appendChild(img);
                b.addEventListener("pointerenter", function () { if (p.open) img.src = animUrl(s.url); });
                b.addEventListener("pointerleave", function () { img.src = s.url; });
                b.addEventListener("click", function (e) {
                    e.stopPropagation();
                    if (!p.open) {
                        var l = head.querySelector(".stk__lock");
                        if (l) { l.classList.remove("is-nudge"); void l.offsetWidth; l.classList.add("is-nudge"); }
                        return;
                    }
                    onPick(s);
                });
                grid.appendChild(b);
            });
            grid.scrollTop = 0;
        }

        packs.forEach(function (p) {
            var t = el("button", "stk__tab" + (p.open ? "" : " is-locked")); t.type = "button";
            t.setAttribute("role", "tab"); t.setAttribute("data-k", p.key); t.title = p.name;
            t.setAttribute("aria-label", p.name + (p.open ? "" : " (locked)"));
            var img = document.createElement("img"); img.src = p.cover; img.alt = ""; img.width = 34; img.height = 34;
            t.appendChild(img);
            if (!p.open) t.appendChild(el("span", "stk__badge", p.lock === "buy" ? "K" : "★"));
            t.addEventListener("click", function (e) { e.stopPropagation(); show(p.key); });
            tabs.appendChild(t);
        });
        if (cur) show(cur);
    }

    // Tap a sticker in the chat or the feed to see it move.
    document.addEventListener("click", function (e) {
        var img = e.target.closest && e.target.closest(".hc__sticker, .cmx-post__sticker, .cmx-comment__sticker");
        if (img) play(img, 3500);
    });

    window.hplStickers = { init: init, render: render, play: play, animUrl: animUrl, stillUrl: stillUrl, openPacks: openPacks };
})();
