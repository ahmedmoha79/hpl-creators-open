/* HPL Community (2026-11-08): feed, reactions, comments, stickers, leaderboard, members, tabs.
 * All user text goes in with textContent. Icons come from the server as trusted SVG. */
(function () {
    "use strict";
    var root = document.getElementById("cmx");
    var seedEl = document.getElementById("cmxSeed");
    if (!root || !seedEl) return;
    var S;
    try { S = JSON.parse(seedEl.textContent); } catch (e) { return; }
    var csrf = root.getAttribute("data-csrf");
    if (window.hplStickers) window.hplStickers.init({ packs: S.packs, api: S.urls && S.urls.api, csrf: csrf });
    var I = S.icons || {};
    var me = S.me || {};
    var clockOffset = 0;

    /* ---------------- helpers ---------------- */
    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function ico(name) { var s = el("span", "cmx-ico"); s.innerHTML = I[name] || ""; return s; }
    function btn(cls, label, iconName, aria) {
        var b = el("button", cls);
        b.type = "button";
        if (iconName) b.appendChild(ico(iconName));
        if (label) b.appendChild(el("span", null, label));
        if (aria) b.setAttribute("aria-label", aria);
        return b;
    }
    function toast(msg, kind) { if (window.hplToast) window.hplToast(msg, kind || "error"); }
    function nowS() { return Math.round((Date.now() + clockOffset) / 1000); }
    function ago(ts) {
        var s = Math.max(0, nowS() - ts);
        if (s < 45) return "just now";
        if (s < 3600) return Math.round(s / 60) + "m";
        if (s < 86400) return Math.round(s / 3600) + "h";
        if (s < 7 * 86400) return Math.round(s / 86400) + "d";
        var d = new Date(ts * 1000);
        return d.toLocaleDateString(undefined, { day: "numeric", month: "short" });
    }
    function agoLong(ts) {
        var a = ago(ts);
        if (a === "just now") return a;
        if (/^\d+m$/.test(a)) return parseInt(a, 10) + " min ago";
        if (/^\d+h$/.test(a)) { var h = parseInt(a, 10); return h + (h === 1 ? " hour ago" : " hours ago"); }
        if (/^\d+d$/.test(a)) { var d = parseInt(a, 10); return d + (d === 1 ? " day ago" : " days ago"); }
        return a;
    }
    function fillText(node, text) {
        var re = /\bhttps?:\/\/[^\s<>"']+/gi, last = 0, m;
        while ((m = re.exec(text))) {
            if (m.index > last) node.appendChild(document.createTextNode(text.slice(last, m.index)));
            var url = m[0].replace(/[.,!?)]+$/, "");
            var a = el("a", null, url);
            a.href = url; a.target = "_blank"; a.rel = "noopener nofollow ugc";
            node.appendChild(a);
            last = m.index + url.length;
        }
        if (last < text.length) node.appendChild(document.createTextNode(text.slice(last)));
    }
    function avatar(p, size, withDot) {
        p = p || { initial: "?", tier: "bronze" };
        var a = el("span", "cmx-av" + (size ? " cmx-av--" + size : "") + " cmx-av--" + (p.tier || "bronze"));
        if (p.avatar) {
            var img = document.createElement("img");
            img.src = p.avatar; img.alt = ""; img.loading = "lazy"; img.referrerPolicy = "no-referrer";
            img.onerror = function () { a.textContent = p.initial || "?"; };
            a.appendChild(img);
        } else {
            a.textContent = p.initial || "?";
        }
        if (withDot && p.online) a.appendChild(el("i", "cmx-av__dot"));
        return a;
    }
    function tierBadge(p) {
        if (!p) return null;
        if (p.team) return el("span", "cmx-badge cmx-badge--team", "HPL team");
        if (p.tier && p.tier !== "bronze") return el("span", "cmx-badge cmx-badge--" + p.tier, p.tierName);
        return null;
    }
    function api(params) {
        var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + "=" + encodeURIComponent(params[k]); }).join("&");
        return fetch(S.urls.api + "?" + q, { credentials: "same-origin", headers: { Accept: "application/json" } })
            .then(function (r) { return r.json(); })
            .then(function (d) { if (d && d.now) clockOffset = d.now * 1000 - Date.now(); return d; });
    }
    // Photos are made smaller on the phone before sending (at most 1600 px, about 200-400 KB):
    // faster on mobile data, and the server never has to open a huge 50-megapixel photo.
    function shrinkPhoto(file) {
        return new Promise(function (resolve) {
            if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type) || !window.HTMLCanvasElement) { resolve(file); return; }
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                try {
                    var max = 1600, w = img.naturalWidth, h = img.naturalHeight;
                    var k = Math.min(1, max / Math.max(w, h));
                    if (k >= 1 && file.size < 1.5 * 1024 * 1024) { URL.revokeObjectURL(url); resolve(file); return; }
                    var c = document.createElement("canvas");
                    c.width = Math.max(1, Math.round(w * k)); c.height = Math.max(1, Math.round(h * k));
                    var g = c.getContext("2d");
                    g.fillStyle = "#fff"; g.fillRect(0, 0, c.width, c.height);
                    g.drawImage(img, 0, 0, c.width, c.height);
                    c.toBlob(function (b) {
                        URL.revokeObjectURL(url);
                        resolve(b && b.size > 0 && b.size < file.size ? new File([b], "photo.jpg", { type: "image/jpeg" }) : file);
                    }, "image/jpeg", 0.85);
                } catch (e) { URL.revokeObjectURL(url); resolve(file); }
            };
            img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
            img.src = url;
        });
    }
    function send(fields, file) {
        var fd = new FormData();
        fd.append("csrf", csrf);
        Object.keys(fields).forEach(function (k) { if (fields[k] != null) fd.append(k, fields[k]); });
        return shrinkPhoto(file).then(function (f) {
            if (f && f.size > 8 * 1024 * 1024) return { ok: false, error: "This photo is too big. Choose a smaller one." };
            if (f) fd.append("photo", f);
            return sendForm(fd);
        });
    }
    function sendForm(fd) {
        return fetch(S.urls.api, { method: "POST", credentials: "same-origin", body: fd })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: "Something went wrong. Try again." }; }); })
            .catch(function () { return { ok: false, error: "Network problem. Check your connection and try again." }; });
    }
    var channelInfo = {};
    (S.channels || []).forEach(function (c) { channelInfo[c.key] = c; });

    /* ---------------- popovers (reactions + stickers) ---------------- */
    var openPop = null;
    function closePop() { if (openPop) { openPop.remove(); openPop = null; } }
    document.addEventListener("click", function (e) { if (openPop && !openPop.contains(e.target) && !e.target.closest("[data-pop-owner]")) closePop(); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") { closePop(); closeLightbox(); } });
    function popAt(anchor, build) {
        closePop();
        var pop = el("div", "cmx-pop");
        build(pop);
        document.body.appendChild(pop);
        var r = anchor.getBoundingClientRect();
        var w = pop.offsetWidth, h = pop.offsetHeight;
        var left = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
        var top = r.top - h - 8;
        if (top < 8) top = r.bottom + 8;
        pop.style.left = left + window.scrollX + "px";
        pop.style.top = top + window.scrollY + "px";
        openPop = pop;
        return pop;
    }
    function reactionPicker(anchor, onPick) {
        popAt(anchor, function (pop) {
            pop.classList.add("cmx-pop--reacts");
            (S.reacts || []).forEach(function (r) {
                var b = el("button", "cmx-pop__emoji", r.e);
                b.type = "button"; b.setAttribute("aria-label", "React " + r.k);
                b.addEventListener("click", function () { closePop(); onPick(r.k); });
                pop.appendChild(b);
            });
        });
    }
    function stickerPicker(anchor, onPick) {
        popAt(anchor, function (pop) {
            pop.classList.add("cmx-pop--stickers");
            if (window.hplStickers) window.hplStickers.render(pop, function (s) { closePop(); onPick(s); });
        });
    }
    window.hplCommunity = { reactionPicker: reactionPicker, stickerPicker: stickerPicker, closePop: closePop };

    /* ---------------- lightbox ---------------- */
    var lb = document.getElementById("cmxLightbox");
    function openLightbox(src) { if (!lb) return; lb.querySelector("img").src = src; lb.hidden = false; }
    function closeLightbox() { if (lb && !lb.hidden) { lb.hidden = true; lb.querySelector("img").src = ""; } }
    if (lb) lb.addEventListener("click", closeLightbox);

    /* ---------------- reactions bar ---------------- */
    function reactsBar(kind, id, list, small) {
        var bar = el("div", "cmx-reacts" + (small ? " cmx-reacts--sm" : ""));
        function draw(items) {
            bar.innerHTML = "";
            (items || []).forEach(function (r) {
                var chip = el("button", "cmx-react" + (r.mine ? " is-mine" : ""));
                chip.type = "button";
                chip.setAttribute("aria-pressed", r.mine ? "true" : "false");
                chip.setAttribute("aria-label", r.n + " " + r.k + (r.mine ? ", tap to remove yours" : ""));
                chip.appendChild(el("span", "cmx-react__e", r.e));
                chip.appendChild(el("span", "cmx-react__n", String(r.n)));
                chip.addEventListener("click", function () { toggle(r.k); });
                bar.appendChild(chip);
            });
            var add = btn("cmx-react cmx-react--add", null, "smile", "Add a reaction");
            add.setAttribute("data-pop-owner", "1");
            add.addEventListener("click", function (e) { e.stopPropagation(); reactionPicker(add, toggle); });
            bar.appendChild(add);
        }
        function toggle(k) {
            // Optimistic: update right away, then trust the server.
            var items = (list || []).map(function (x) { return { k: x.k, e: x.e, n: x.n, mine: x.mine }; });
            var hit = items.filter(function (x) { return x.k === k; })[0];
            if (hit) { hit.mine = !hit.mine; hit.n += hit.mine ? 1 : -1; if (hit.n <= 0) items = items.filter(function (x) { return x !== hit; }); }
            else { var def = (S.reacts || []).filter(function (x) { return x.k === k; })[0]; items.push({ k: k, e: def ? def.e : "", n: 1, mine: true }); }
            list = items; draw(items);
            if (bar.animate) bar.animate([{ transform: "scale(1)" }, { transform: "scale(1.04)" }, { transform: "scale(1)" }], { duration: 180 });
            send({ action: "react", kind: kind, id: id, emoji: k }).then(function (d) {
                if (!d.ok) { toast(d.error || "Could not react."); return; }
                list = d.reacts || []; draw(list);
            });
        }
        draw(list);
        return bar;
    }

    /* ---------------- composer (new post) ---------------- */
    var composerBox = document.getElementById("cmxComposer");
    var feedBox = document.getElementById("cmxFeed");
    var channelsBox = document.getElementById("cmxChannels");
    var moreBtn = document.getElementById("cmxMore");
    var newerBtn = document.getElementById("cmxNewer");
    var state = { posts: S.posts || [], more: !!S.more, channel: "", loading: false, topId: 0 };

    function buildComposer() {
        if (!composerBox || !S.ready) return;
        var c = el("div", "cmx-card cmx-compose");
        var collapsed = el("button", "cmx-compose__start");
        collapsed.type = "button";
        collapsed.appendChild(avatar(me, "md"));
        collapsed.appendChild(el("span", null, "Share a win or ask something..."));
        var row = el("span", "cmx-compose__quick");
        row.appendChild(ico("image")); row.appendChild(ico("sticker"));
        collapsed.appendChild(row);

        var form = el("form", "cmx-compose__form");
        form.hidden = true;
        var head = el("div", "cmx-compose__head");
        head.appendChild(avatar(me, "md"));
        var who = el("div", "cmx-compose__who");
        who.appendChild(el("strong", null, me.name));
        var pick = el("div", "cmx-compose__chans");
        var channel = S.firstPost ? "intro" : "general";
        (S.channels || []).forEach(function (ch) {
            var b = el("button", "cmx-chip" + (ch.key === channel ? " is-on" : ""), ch.label);
            b.type = "button";
            b.addEventListener("click", function () {
                channel = ch.key;
                pick.querySelectorAll(".cmx-chip").forEach(function (x) { x.classList.toggle("is-on", x === b); });
                ta.placeholder = placeholderFor(channel);
            });
            pick.appendChild(b);
        });
        who.appendChild(pick);
        head.appendChild(who);
        form.appendChild(head);

        var ta = el("textarea", "cmx-compose__text");
        ta.rows = 3; ta.maxLength = 3000;
        ta.placeholder = placeholderFor(channel);
        ta.setAttribute("aria-label", "Write your post");
        form.appendChild(ta);

        var preview = el("div", "cmx-compose__preview");
        preview.hidden = true;
        form.appendChild(preview);

        var foot = el("div", "cmx-compose__foot");
        var tools = el("div", "cmx-compose__tools");
        var file = document.createElement("input");
        file.type = "file"; file.accept = "image/jpeg,image/png,image/webp,image/gif"; file.hidden = true;
        var photoBtn = btn("cmx-tool", "Photo", "image");
        photoBtn.addEventListener("click", function () { file.click(); });
        var stickerBtn = btn("cmx-tool", "Sticker", "sticker");
        stickerBtn.setAttribute("data-pop-owner", "1");
        tools.appendChild(photoBtn); tools.appendChild(stickerBtn); tools.appendChild(file);
        foot.appendChild(tools);
        var actions = el("div", "cmx-compose__actions");
        var cancel = btn("cmx-btn cmx-btn--ghost", "Cancel");
        var post = el("button", "cmx-btn cmx-btn--primary", "Post");
        post.type = "submit";
        actions.appendChild(cancel); actions.appendChild(post);
        foot.appendChild(actions);
        form.appendChild(foot);

        var chosenFile = null, chosenSticker = null;
        function showPreview() {
            preview.innerHTML = "";
            preview.hidden = !chosenFile && !chosenSticker;
            if (chosenFile) {
                var img = document.createElement("img"); img.alt = "";
                img.src = URL.createObjectURL(chosenFile);
                preview.appendChild(img);
            } else if (chosenSticker) {
                var s = document.createElement("img"); s.alt = chosenSticker.label; s.src = chosenSticker.url; s.className = "is-sticker";
                preview.appendChild(s);
            }
            if (!preview.hidden) {
                var x = btn("cmx-compose__unattach", null, "x", "Remove");
                x.addEventListener("click", function () { chosenFile = null; chosenSticker = null; file.value = ""; showPreview(); syncPost(); });
                preview.appendChild(x);
            }
        }
        function syncPost() { post.disabled = !ta.value.trim() && !chosenFile && !chosenSticker; }
        file.addEventListener("change", function () {
            var f = file.files && file.files[0];
            if (!f) return;
            if (f.size > 30 * 1024 * 1024) { toast("Choose a photo smaller than 30 MB."); file.value = ""; return; }
            chosenFile = f; chosenSticker = null; showPreview(); syncPost();
        });
        stickerBtn.addEventListener("click", function (e) {
            e.stopPropagation();
            stickerPicker(stickerBtn, function (s) { chosenSticker = s; chosenFile = null; file.value = ""; showPreview(); syncPost(); });
        });
        ta.addEventListener("input", function () {
            ta.style.height = "auto"; ta.style.height = Math.min(320, ta.scrollHeight) + "px"; syncPost();
        });
        function open() { collapsed.hidden = true; form.hidden = false; ta.focus(); syncPost(); }
        function close() { form.hidden = true; collapsed.hidden = false; ta.value = ""; chosenFile = null; chosenSticker = null; file.value = ""; showPreview(); }
        collapsed.addEventListener("click", open);
        cancel.addEventListener("click", close);
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            if (post.disabled) return;
            post.disabled = true; post.textContent = "Posting...";
            send({ action: "create", channel: channel, text: ta.value, sticker: chosenSticker ? chosenSticker.key : "" }, chosenFile).then(function (d) {
                post.textContent = "Post";
                if (!d.ok || !d.post) { syncPost(); toast(d.error || "Could not post."); return; }
                S.firstPost = false;
                close();
                if (!state.channel || state.channel === d.post.channel) {
                    state.posts = [d.post].concat(state.posts.filter(function (p) { return p.id !== d.post.id; }));
                    drawFeed();
                    var n = feedBox.querySelector('[data-post="' + d.post.id + '"]');
                    if (n) n.classList.add("is-new");
                }
                toast("Posted. The community can see it now.", "ok");
            });
        });
        c.appendChild(collapsed);
        c.appendChild(form);
        composerBox.appendChild(c);
    }
    function placeholderFor(ch) {
        return {
            wins: "Share a win: views, a payout, a clip that blew up...",
            questions: "What do you want to know? The HPL team and other clippers can answer.",
            tips: "Share something that works for you: hooks, posting times, editing tricks...",
            intro: "Say hi: your name, where you are in Zambia and what you clip.",
            general: "What's on your mind?"
        }[ch] || "What's on your mind?";
    }

    /* ---------------- channel filter ---------------- */
    function buildChannels() {
        if (!channelsBox) return;
        var all = [{ key: "", label: "All posts" }].concat(S.channels || []);
        all.forEach(function (ch) {
            var b = el("button", "cmx-chip cmx-chip--filter" + (ch.key === state.channel ? " is-on" : ""), ch.label);
            b.type = "button";
            b.setAttribute("aria-pressed", ch.key === state.channel ? "true" : "false");
            b.addEventListener("click", function () {
                if (state.channel === ch.key) return;
                state.channel = ch.key;
                channelsBox.querySelectorAll(".cmx-chip").forEach(function (x) {
                    var on = x === b; x.classList.toggle("is-on", on); x.setAttribute("aria-pressed", on ? "true" : "false");
                });
                loadFeed(true);
            });
            channelsBox.appendChild(b);
        });
    }

    /* ---------------- feed ---------------- */
    function computeTop() {
        state.topId = 0;
        state.posts.forEach(function (p) { if (p.id > state.topId) state.topId = p.id; });
    }
    function loadFeed(reset) {
        if (state.loading) return;
        state.loading = true;
        var params = { action: "feed", channel: state.channel };
        if (!reset && state.posts.length) {
            var oldest = state.posts.filter(function (p) { return !p.pinned; }).pop();
            if (oldest) params.before = oldest.id;
        }
        if (reset) { feedBox.innerHTML = ""; feedBox.appendChild(skeleton()); }
        if (moreBtn) { moreBtn.disabled = true; moreBtn.textContent = "Loading..."; }
        api(params).then(function (d) {
            state.loading = false;
            if (!d.ok) { toast(d.error || "Could not load posts."); return; }
            state.posts = reset ? d.posts : state.posts.concat(d.posts.filter(function (p) { return !state.posts.some(function (x) { return x.id === p.id; }); }));
            state.more = !!d.more;
            if (newerBtn) newerBtn.hidden = true;
            drawFeed();
        }).catch(function () { state.loading = false; toast("Could not load posts. Check your connection."); })
          .then(function () { if (moreBtn) { moreBtn.disabled = false; moreBtn.textContent = "Show older posts"; } });
    }
    function skeleton() {
        var s = el("div", "cmx-skel");
        for (var i = 0; i < 2; i++) s.appendChild(el("div", "cmx-card cmx-skel__card"));
        return s;
    }
    function drawFeed() {
        feedBox.innerHTML = "";
        computeTop();
        if (!state.posts.length) {
            feedBox.appendChild(emptyFeed());
        } else {
            state.posts.forEach(function (p) { feedBox.appendChild(postCard(p)); });
        }
        if (moreBtn) moreBtn.hidden = !state.more;
    }
    function emptyFeed() {
        var c = el("div", "cmx-card cmx-welcome");
        var stk = document.createElement("img");
        stk.src = (S.stickers.filter(function (s) { return s.key === "welcome"; })[0] || {}).url || ""; stk.alt = ""; stk.className = "cmx-welcome__sticker";
        c.appendChild(stk);
        var ch = state.channel ? channelInfo[state.channel] : null;
        c.appendChild(el("h2", null, ch ? "No posts in " + ch.label + " yet" : "Be the first to post"));
        c.appendChild(el("p", null, "Nothing here yet. Be the first."));
        var go = el("button", "cmx-btn cmx-btn--primary", "Write a post");
        go.type = "button";
        go.addEventListener("click", function () { var s = composerBox.querySelector(".cmx-compose__start"); if (s) s.click(); window.scrollTo({ top: composerBox.getBoundingClientRect().top + window.scrollY - 90, behavior: "smooth" }); });
        c.appendChild(go);
        return c;
    }
    function postMenu(p, card) {
        if (!p.canDelete && !p.canPin) return null;
        var b = btn("cmx-iconbtn", null, "more", "Post options");
        b.setAttribute("data-pop-owner", "1");
        b.addEventListener("click", function (e) {
            e.stopPropagation();
            popAt(b, function (pop) {
                pop.classList.add("cmx-pop--menu");
                if (p.canPin) {
                    var pin = btn("cmx-pop__item", p.pinned ? "Unpin" : "Pin to top", "pin");
                    pin.addEventListener("click", function () {
                        closePop();
                        send({ action: "pin", id: p.id }).then(function (d) {
                            if (!d.ok) { toast(d.error); return; }
                            p.pinned = d.pinned; toast(d.pinned ? "Pinned to the top." : "Unpinned.", "ok"); loadFeed(true);
                        });
                    });
                    pop.appendChild(pin);
                }
                if (p.canDelete) {
                    var del = btn("cmx-pop__item cmx-pop__item--bad", "Remove post", "trash");
                    del.addEventListener("click", function () {
                        closePop();
                        var go = window.hplConfirm ? window.hplConfirm("Remove this post for everyone?") : Promise.resolve(confirm("Remove this post?"));
                        go.then(function (ok) {
                            if (!ok) return;
                            send({ action: "remove", kind: "post", id: p.id }).then(function (d) {
                                if (!d.ok) { toast(d.error); return; }
                                state.posts = state.posts.filter(function (x) { return x.id !== p.id; });
                                card.remove();
                                if (!state.posts.length) drawFeed();
                            });
                        });
                    });
                    pop.appendChild(del);
                }
            });
        });
        return b;
    }
    function postCard(p) {
        var card = el("article", "cmx-card cmx-post" + (p.pinned ? " is-pinned" : ""));
        card.setAttribute("data-post", p.id);
        if (p.pinned) {
            var pin = el("div", "cmx-post__pin"); pin.appendChild(ico("pin")); pin.appendChild(el("span", null, "Pinned"));
            card.appendChild(pin);
        }
        var head = el("header", "cmx-post__head");
        head.appendChild(avatar(p.author, "md", true));
        var meta = el("div", "cmx-post__meta");
        var line1 = el("div", "cmx-post__who");
        line1.appendChild(el("strong", null, p.author ? p.author.name : "Member"));
        var bdg = tierBadge(p.author); if (bdg) line1.appendChild(bdg);
        meta.appendChild(line1);
        var line2 = el("div", "cmx-post__sub");
        var t = el("time", null, agoLong(p.ts)); t.setAttribute("data-ts", p.ts); t.title = new Date(p.ts * 1000).toLocaleString();
        line2.appendChild(t);
        var ch = channelInfo[p.channel];
        if (ch) {
            line2.appendChild(el("span", "cmx-dotsep", "·"));
            var chb = el("button", "cmx-post__chan cmx-post__chan--" + p.channel, ch.label);
            chb.type = "button";
            chb.addEventListener("click", function () {
                var f = channelsBox.querySelectorAll(".cmx-chip")[(S.channels || []).map(function (c) { return c.key; }).indexOf(p.channel) + 1];
                if (f) { f.click(); window.scrollTo({ top: channelsBox.getBoundingClientRect().top + window.scrollY - 90, behavior: "smooth" }); }
            });
            line2.appendChild(chb);
        }
        meta.appendChild(line2);
        head.appendChild(meta);
        var menu = postMenu(p, card); if (menu) head.appendChild(menu);
        card.appendChild(head);

        if (p.text) {
            var body = el("div", "cmx-post__text");
            fillText(body, p.text);
            card.appendChild(body);
            if (p.text.length > 420 || (p.text.match(/\n/g) || []).length > 6) {
                body.classList.add("is-clamped");
                var more = el("button", "cmx-link", "See more");
                more.type = "button";
                more.addEventListener("click", function () { body.classList.remove("is-clamped"); more.remove(); });
                card.appendChild(more);
            }
        }
        if (p.image) {
            var fig = el("button", "cmx-post__img");
            fig.type = "button"; fig.setAttribute("aria-label", "Open photo");
            var img = document.createElement("img"); img.src = p.image; img.alt = ""; img.loading = "lazy";
            fig.appendChild(img);
            fig.addEventListener("click", function () { openLightbox(p.image); });
            card.appendChild(fig);
        } else if (p.sticker) {
            var st = document.createElement("img"); st.src = p.sticker; st.alt = "Sticker"; st.className = "cmx-post__sticker"; st.loading = "lazy";
            card.appendChild(st);
        }

        var foot = el("footer", "cmx-post__foot");
        foot.appendChild(reactsBar("post", p.id, p.reacts));
        var cbtn = el("button", "cmx-post__cbtn");
        cbtn.type = "button";
        if (p.commenters && p.commenters.length) {
            var faces = el("span", "cmx-faces");
            p.commenters.slice(0, 3).forEach(function (c) { faces.appendChild(avatar(c, "xs")); });
            cbtn.appendChild(faces);
        }
        cbtn.appendChild(ico("message"));
        var clabel = el("span", null, p.comments ? p.comments + (p.comments === 1 ? " comment" : " comments") : "Comment");
        cbtn.appendChild(clabel);
        foot.appendChild(cbtn);
        card.appendChild(foot);

        var thread = el("div", "cmx-thread");
        thread.hidden = true;
        card.appendChild(thread);
        var loaded = false;
        function openThread(focus) {
            thread.hidden = false;
            cbtn.setAttribute("aria-expanded", "true");
            if (!loaded) {
                loaded = true;
                thread.appendChild(el("div", "cmx-thread__loading", "Loading comments..."));
                api({ action: "comments", id: p.id }).then(function (d) {
                    drawThread(d.ok ? d.comments : []);
                    if (focus) { var inp = thread.querySelector(".cmx-cform textarea"); if (inp) inp.focus(); }
                });
            } else if (focus) { var inp2 = thread.querySelector(".cmx-cform textarea"); if (inp2) inp2.focus(); }
        }
        cbtn.addEventListener("click", function () {
            if (!thread.hidden && loaded) { thread.hidden = true; cbtn.setAttribute("aria-expanded", "false"); return; }
            openThread(true);
        });
        card._openThread = openThread;

        function drawThread(list) {
            thread.innerHTML = "";
            var tops = list.filter(function (c) { return !c.parent; });
            tops.forEach(function (c) {
                var wrap = el("div", "cmx-cwrap");
                wrap.appendChild(commentRow(c, wrap));
                var kids = list.filter(function (k) { return k.parent === c.id; });
                if (kids.length) {
                    var rep = el("div", "cmx-replies");
                    kids.forEach(function (k) { rep.appendChild(commentRow(k, wrap)); });
                    wrap.appendChild(rep);
                }
                thread.appendChild(wrap);
            });
            thread.appendChild(commentForm(0));
        }
        function commentRow(c, wrap) {
            var row = el("div", "cmx-comment");
            row.appendChild(avatar(c.author, "sm"));
            var box = el("div", "cmx-comment__box");
            var bubble = el("div", "cmx-comment__bubble");
            var who = el("div", "cmx-comment__who");
            who.appendChild(el("strong", null, c.author ? c.author.name : "Member"));
            var b2 = tierBadge(c.author); if (b2) who.appendChild(b2);
            bubble.appendChild(who);
            if (c.text) { var tx = el("div", "cmx-comment__text"); fillText(tx, c.text); bubble.appendChild(tx); }
            if (c.sticker) { var si = document.createElement("img"); si.src = c.sticker; si.alt = "Sticker"; si.className = "cmx-comment__sticker"; bubble.appendChild(si); }
            box.appendChild(bubble);
            var acts = el("div", "cmx-comment__acts");
            var tm = el("time", null, ago(c.ts)); tm.setAttribute("data-ts-short", c.ts); acts.appendChild(tm);
            var reply = el("button", "cmx-link", "Reply"); reply.type = "button";
            reply.addEventListener("click", function () {
                var existing = wrap.querySelector(".cmx-cform--reply");
                if (existing) { existing.querySelector("textarea").focus(); return; }
                var f = commentForm(c.parent || c.id, c.author ? c.author.name : "");
                f.classList.add("cmx-cform--reply");
                wrap.appendChild(f);
                f.querySelector("textarea").focus();
            });
            acts.appendChild(reply);
            if (c.canDelete) {
                var del = el("button", "cmx-link cmx-link--muted", "Remove"); del.type = "button";
                del.addEventListener("click", function () {
                    send({ action: "remove", kind: "comment", id: c.id }).then(function (d) {
                        if (!d.ok) { toast(d.error); return; }
                        loaded = false; openThread(false);
                        api({ action: "post", id: p.id }).then(function (x) { if (x.ok) { p.comments = x.post.comments; clabel.textContent = p.comments ? p.comments + (p.comments === 1 ? " comment" : " comments") : "Comment"; } });
                    });
                });
                acts.appendChild(del);
            }
            acts.appendChild(reactsBar("comment", c.id, c.reacts, true));
            box.appendChild(acts);
            row.appendChild(box);
            return row;
        }
        function commentForm(parent, replyName) {
            var f = el("form", "cmx-cform");
            f.appendChild(avatar(me, "sm"));
            var field = el("div", "cmx-cform__field");
            var ta = el("textarea"); ta.rows = 1; ta.maxLength = 1500;
            ta.placeholder = replyName ? "Reply to " + replyName.split(" ")[0] + "..." : "Write a comment...";
            ta.setAttribute("aria-label", ta.placeholder);
            field.appendChild(ta);
            var sb = btn("cmx-iconbtn", null, "sticker", "Send a sticker");
            sb.setAttribute("data-pop-owner", "1");
            field.appendChild(sb);
            var go = btn("cmx-iconbtn cmx-iconbtn--send", null, "send", "Send comment");
            go.type = "submit";
            field.appendChild(go);
            f.appendChild(field);
            function grow() { ta.style.height = "auto"; ta.style.height = Math.min(160, ta.scrollHeight) + "px"; }
            ta.addEventListener("input", grow);
            ta.addEventListener("keydown", function (e) {
                if (e.key === "Enter" && !e.shiftKey && !e.isComposing && window.matchMedia("(min-width: 901px)").matches) { e.preventDefault(); submit(null); }
            });
            function submit(sticker) {
                var text = ta.value.trim();
                if (!text && !sticker) return;
                go.disabled = true;
                send({ action: "comment", id: p.id, text: text, sticker: sticker ? sticker.key : "", parent: parent || 0 }).then(function (d) {
                    go.disabled = false;
                    if (!d.ok) { toast(d.error || "Could not send your comment."); return; }
                    p.comments = d.count;
                    clabel.textContent = p.comments + (p.comments === 1 ? " comment" : " comments");
                    drawThread(d.comments || []);
                });
            }
            sb.addEventListener("click", function (e) { e.stopPropagation(); stickerPicker(sb, function (s) { submit(s); }); });
            f.addEventListener("submit", function (e) { e.preventDefault(); submit(null); });
            return f;
        }
        return card;
    }

    /* ---------------- live updates for the feed ---------------- */
    var pollTimer = null;
    function pollFeed() {
        clearTimeout(pollTimer);
        if (document.hidden) { pollTimer = setTimeout(pollFeed, 60000); return; }
        api({ action: "poll", since: state.topId, channel: state.channel }).then(function (d) {
            if (d && d.ok) {
                if (newerBtn) {
                    newerBtn.hidden = !d.newer;
                    newerBtn.textContent = d.newer === 1 ? "1 new post. Show it" : d.newer + " new posts. Show them";
                }
                setOnline(d.online || []);
            }
        }).catch(function () {}).then(function () { pollTimer = setTimeout(pollFeed, 25000); });
    }
    if (newerBtn) newerBtn.addEventListener("click", function () { loadFeed(true); window.scrollTo({ top: root.getBoundingClientRect().top + window.scrollY - 70, behavior: "smooth" }); });
    if (moreBtn) moreBtn.addEventListener("click", function () { loadFeed(false); });

    function setOnline(list) {
        S.online = list;
        var n = document.getElementById("cmxOnline"); if (n) n.textContent = list.length;
        var n2 = document.getElementById("cmxSideOnlineN"); if (n2) n2.textContent = list.length;
        var stack = document.getElementById("cmxStack");
        if (stack) { stack.innerHTML = ""; list.slice(0, 5).forEach(function (p) { var a = avatar(p, "sm"); a.title = p.name; stack.appendChild(a); }); }
        var side = document.getElementById("cmxSideOnline");
        if (side) drawOnline(side);
    }

    /* ---------------- sidebar ---------------- */
    var side = document.getElementById("cmxSide");
    function drawOnline(box) {
        box.innerHTML = "";
        if (!S.online.length) { box.appendChild(el("p", "cmx-muted", "Nobody else right now.")); return; }
        S.online.slice(0, 14).forEach(function (p) {
            var a = avatar(p, "sm", true); a.title = p.name + (p.id === me.id ? " (you)" : "");
            box.appendChild(a);
        });
        if (S.online.length > 14) box.appendChild(el("span", "cmx-more-n", "+" + (S.online.length - 14)));
    }
    function buildSide() {
        if (!side) return;
        var st = S.stats || {};
        var about = el("section", "cmx-card cmx-about");
        about.appendChild(el("h2", null, "HPL Creators"));
        about.appendChild(el("p", "cmx-muted", "Clippers in Zambia earning from HPL gold-mining videos. Be kind, help each other, no spam."));
        var nums = el("div", "cmx-about__nums");
        [[st.members, "members"], [st.online, "online now"], [st.posts_week, "posts this week"]].forEach(function (x, i) {
            var d = el("div"); var b = el("b", null, (x[0] || 0).toLocaleString()); if (i === 1) b.id = "cmxSideOnlineN";
            d.appendChild(b); d.appendChild(el("span", null, x[1])); nums.appendChild(d);
        });
        about.appendChild(nums);
        var online = el("div", "cmx-online"); online.id = "cmxSideOnline";
        about.appendChild(online);
        side.appendChild(about);
        drawOnline(online);

        var board = el("section", "cmx-card cmx-mini");
        var bh = el("div", "cmx-mini__head"); bh.appendChild(el("h2", null, "Top this week"));
        var all = el("button", "cmx-link", "See all"); all.type = "button"; all.addEventListener("click", function () { setTab("leaderboard"); });
        bh.appendChild(all); board.appendChild(bh);
        if (!S.board.length) {
            board.appendChild(el("p", "cmx-muted", "No points yet this week."));
        } else {
            var ol = el("ol", "cmx-mini__list");
            S.board.forEach(function (r) {
                var li = el("li");
                li.appendChild(el("span", "cmx-rank cmx-rank--" + r.rank, String(r.rank)));
                li.appendChild(avatar(r, "sm"));
                li.appendChild(el("span", "cmx-mini__name", r.name));
                li.appendChild(el("b", "cmx-pts", "+" + r.pts));
                ol.appendChild(li);
            });
            board.appendChild(ol);
        }
        side.appendChild(board);

        if (S.newbies && S.newbies.length) {
            var nb = el("section", "cmx-card cmx-mini");
            nb.appendChild(el("h2", null, "New members. Say hi"));
            var ul = el("ul", "cmx-mini__list cmx-mini__list--plain");
            S.newbies.forEach(function (p) {
                var li = el("li");
                li.appendChild(avatar(p, "sm", true));
                var w = el("span", "cmx-mini__name"); w.appendChild(el("span", null, p.name)); w.appendChild(el("small", null, "joined " + agoLong(p.joined))); li.appendChild(w);
                var a = el("a", "cmx-btn cmx-btn--soft cmx-btn--xs", "Say hi"); a.href = S.urls.dm + "?user=" + p.id;
                li.appendChild(a);
                ul.appendChild(li);
            });
            nb.appendChild(ul);
            side.appendChild(nb);
        }

        if (S.activity && S.activity.length) {
            var ac = el("section", "cmx-card cmx-mini");
            ac.appendChild(el("h2", null, "Happening on HPL"));
            var al = el("ul", "cmx-activity");
            S.activity.forEach(function (a) {
                var li = el("li", "cmx-activity__" + a.kind);
                li.appendChild(el("i"));
                var tx = el("span"); tx.appendChild(el("b", null, a.name.split(" ")[0])); tx.appendChild(document.createTextNode(" " + a.text));
                li.appendChild(tx);
                li.appendChild(el("time", null, ago(a.ts)));
                al.appendChild(li);
            });
            ac.appendChild(al);
            side.appendChild(ac);
        }
    }

    /* ---------------- leaderboard ---------------- */
    var boardBox = document.getElementById("cmxBoard");
    var boardDays = 7, boardLoaded = false;
    function loadBoard() {
        // Owner, 2026-11-13: the old podium (2-1-3 side by side, names cut off) was confusing. Now one plain ranked list:
        // 1, 2, 3 with a medal, then the rest, and your own place at the bottom. Clippers only (no HPL team).
        if (!boardBox) return;
        boardBox.innerHTML = "";
        var head = el("div", "cmx-board__head");
        var h = el("div");
        h.appendChild(el("h2", null, "Top clippers"));
        h.appendChild(el("p", "cmx-muted", "1 point for every reaction you get."));
        head.appendChild(h);
        var seg = el("div", "cmx-seg");
        [[7, "This week"], [30, "This month"], [0, "All time"]].forEach(function (o) {
            var b = el("button", "cmx-seg__btn" + (o[0] === boardDays ? " is-on" : ""), o[1]); b.type = "button";
            b.addEventListener("click", function () { boardDays = o[0]; loadBoard(); });
            seg.appendChild(b);
        });
        head.appendChild(seg);
        boardBox.appendChild(head);
        var body = el("div", "cmx-board__body"); body.appendChild(el("div", "cmx-thread__loading", "Loading..."));
        boardBox.appendChild(body);
        api({ action: "leaderboard", days: boardDays }).then(function (d) {
            body.innerHTML = "";
            var rows = (d.rows || []);
            if (!rows.length) { body.appendChild(el("p", "cmx-muted cmx-board__empty", "No points yet. Be the first.")); }
            var ol = el("ol", "cmx-rank-list");
            var mineShown = false;
            rows.forEach(function (r) {
                var li = el("li", "cmx-rank-row" + (r.rank <= 3 ? " cmx-rank-row--top cmx-rank-row--" + r.rank : "") + (r.id === me.id ? " is-me" : ""));
                if (r.id === me.id) mineShown = true;
                li.appendChild(el("span", "cmx-rank-row__n", r.rank <= 3 ? ["", "\uD83E\uDD47", "\uD83E\uDD48", "\uD83E\uDD49"][r.rank] : String(r.rank)));
                li.appendChild(avatar(r, r.rank <= 3 ? "md" : "sm"));
                var w = el("span", "cmx-rank-row__name"); w.appendChild(el("strong", null, r.id === me.id ? "You" : r.name));
                li.appendChild(w);
                li.appendChild(el("b", "cmx-rank-row__pts", r.pts + (r.pts === 1 ? " pt" : " pts")));
                ol.appendChild(li);
            });
            if (rows.length) body.appendChild(ol);
            if (!mineShown && !S.me.isAdmin) {
                var mine = el("div", "cmx-rank-row cmx-rank-row--me is-me");
                mine.appendChild(el("span", "cmx-rank-row__n", "\u2013"));
                mine.appendChild(avatar(me, "sm"));
                var mw = el("span", "cmx-rank-row__name"); mw.appendChild(el("strong", null, "You")); mine.appendChild(mw);
                mine.appendChild(el("b", "cmx-rank-row__pts", (d.me || 0) + ((d.me || 0) === 1 ? " pt" : " pts")));
                body.appendChild(mine);
            }
        });
        boardLoaded = true;
    }

    /* ---------------- members ---------------- */
    var memBox = document.getElementById("cmxMembers");
    var memFilter = "all", memQuery = "", memTimer = null, memBuilt = false, memList = null;
    function buildMembers() {
        if (!memBox || memBuilt) return;
        memBuilt = true;
        var head = el("div", "cmx-members__head");
        var h = el("div"); h.appendChild(el("h2", null, "Members")); h.appendChild(el("p", "cmx-muted", (S.stats.members || 0).toLocaleString() + " clippers"));
        head.appendChild(h);
        var search = el("label", "cmx-search");
        search.appendChild(ico("search"));
        var inp = el("input"); inp.type = "search"; inp.placeholder = "Search by name"; inp.setAttribute("aria-label", "Search members");
        search.appendChild(inp);
        head.appendChild(search);
        memBox.appendChild(head);
        var filters = el("div", "cmx-channels");
        [["all", "Everyone"], ["online", "Online now"], ["new", "New"], ["team", "HPL team"]].forEach(function (f) {
            var b = el("button", "cmx-chip cmx-chip--filter" + (f[0] === memFilter ? " is-on" : ""), f[1]); b.type = "button";
            b.addEventListener("click", function () {
                memFilter = f[0];
                filters.querySelectorAll(".cmx-chip").forEach(function (x) { x.classList.toggle("is-on", x === b); });
                loadMembers();
            });
            filters.appendChild(b);
        });
        memBox.appendChild(filters);
        memList = el("div", "cmx-mgrid");
        memBox.appendChild(memList);
        inp.addEventListener("input", function () { clearTimeout(memTimer); memTimer = setTimeout(function () { memQuery = inp.value.trim(); loadMembers(); }, 250); });
        loadMembers();
    }
    function loadMembers() {
        memList.innerHTML = "";
        memList.appendChild(el("div", "cmx-thread__loading", "Loading..."));
        api({ action: "members", q: memQuery, filter: memFilter }).then(function (d) {
            memList.innerHTML = "";
            var rows = d.rows || [];
            if (!rows.length) { memList.appendChild(el("p", "cmx-muted", memFilter === "online" ? "Nobody else is online right now." : "No members found.")); return; }
            rows.forEach(function (p) {
                var c = el("div", "cmx-mcard");
                c.appendChild(avatar(p, "lg", true));
                var w = el("div", "cmx-mcard__who");
                var n = el("div", "cmx-mcard__name"); n.appendChild(el("strong", null, p.name + (p.id === me.id ? " (you)" : ""))); w.appendChild(n);
                var bd = tierBadge(p); if (bd) w.appendChild(bd);
                w.appendChild(el("span", "cmx-mcard__seen" + (p.online ? " is-on" : ""), p.online ? "Online now" : (p.seen ? "Active " + agoLong(p.seen) : "Joined " + agoLong(p.joined))));
                c.appendChild(w);
                if (p.id !== me.id) {
                    var a = el("a", "cmx-btn cmx-btn--soft cmx-btn--sm cmx-mcard__msg"); a.href = S.urls.dm + "?user=" + p.id; a.setAttribute("aria-label", "Message " + p.name); a.appendChild(ico("message")); a.appendChild(el("span", null, "Message"));
                    c.appendChild(a);
                }
                memList.appendChild(c);
            });
        });
    }

    /* ---------------- tabs ---------------- */
    var tabs = root.querySelectorAll(".cmx-tab");
    function setTab(name, push) {
        root.setAttribute("data-tab", name);
        tabs.forEach(function (t) { var on = t.getAttribute("data-tab") === name; t.classList.toggle("is-on", on); t.setAttribute("aria-selected", on ? "true" : "false"); });
        root.querySelectorAll(".cmx-panel").forEach(function (p) { p.hidden = p.getAttribute("data-panel") !== name; });
        document.body.classList.toggle("cmx-chat-on", name === "chat");
        if (name === "leaderboard") loadBoard();
        if (name === "members") buildMembers();
        try {
            var url = new URL(window.location.href);
            url.searchParams.set("tab", name); url.searchParams.delete("post");
            history[push ? "pushState" : "replaceState"]({ tab: name }, "", url.toString());
        } catch (e) {}
        document.dispatchEvent(new CustomEvent("cmx:tab", { detail: name }));
        if (name === "chat") { var dot = document.getElementById("cmxChatDot"); if (dot) dot.hidden = true; }
    }
    tabs.forEach(function (t) { t.addEventListener("click", function () { setTab(t.getAttribute("data-tab"), true); if (window.scrollY > 200) window.scrollTo({ top: 0 }); }); });
    window.addEventListener("popstate", function (e) { if (e.state && e.state.tab) setTab(e.state.tab, false); });
    // The chat tells us about new messages while another tab is open.
    document.addEventListener("hc:new", function () {
        if (root.getAttribute("data-tab") !== "chat") { var dot = document.getElementById("cmxChatDot"); if (dot) dot.hidden = false; }
    });
    document.addEventListener("hc:online", function (e) { if (Array.isArray(e.detail)) { ["cmxOnline", "cmxSideOnlineN"].forEach(function (id) { var n = document.getElementById(id); if (n) n.textContent = e.detail.length; }); } });

    /* ---------------- start ---------------- */
    buildComposer();
    buildChannels();
    drawFeed();
    buildSide();
    setTab(S.tab || "feed", false);
    if (S.focus) {
        api({ action: "post", id: S.focus }).then(function (d) {
            if (!d.ok) { toast(d.error || "That post was removed."); return; }
            state.posts = [d.post].concat(state.posts.filter(function (p) { return p.id !== d.post.id; }));
            drawFeed();
            var card = feedBox.querySelector('[data-post="' + d.post.id + '"]');
            if (card) {
                card.classList.add("is-focus");
                if (card._openThread) card._openThread(false);
                setTimeout(function () { card.scrollIntoView({ behavior: "smooth", block: "start" }); }, 60);
            }
        });
    }
    setInterval(function () {
        root.querySelectorAll("[data-ts]").forEach(function (t) { t.textContent = agoLong(+t.getAttribute("data-ts")); });
        root.querySelectorAll("[data-ts-short]").forEach(function (t) { t.textContent = ago(+t.getAttribute("data-ts-short")); });
    }, 30000);
    pollTimer = setTimeout(pollFeed, 15000);
    document.addEventListener("visibilitychange", function () { if (!document.hidden) { clearTimeout(pollTimer); pollTimer = setTimeout(pollFeed, 500); } });
})();
