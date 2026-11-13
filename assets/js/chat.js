/* HPL community live chat (2026-10-27, upgraded 2026-11-08)
 * Grouped messages, day dividers, live times, optimistic sending, scroll-back, removal,
 * reactions, replies, stickers, emoji, "typing...", and polling that slows down when the chat is not on screen. */
(function () {
    "use strict";
    var root = document.getElementById("hplChat");
    var seedEl = document.getElementById("hcSeed");
    if (!root || !seedEl) return;

    var seed;
    try { seed = JSON.parse(seedEl.textContent); } catch (e) { return; }
    var feedUrl = root.getAttribute("data-feed");
    var csrf = root.getAttribute("data-csrf");
    var me = seed.me, isAdmin = !!seed.isAdmin;
    var clockOffset = (seed.now || 0) * 1000 - Date.now();
    var reactDefs = seed.reacts || [];
    var stickers = seed.stickers || [];
    var CM = window.hplCommunity || null;

    var scroller = document.getElementById("hcScroll");
    var list = document.getElementById("hcList");
    var olderBox = document.getElementById("hcOlder");
    var jump = document.getElementById("hcJump");
    var form = document.getElementById("hcForm");
    var input = document.getElementById("hcInput");
    var count = document.getElementById("hcCount");
    var sendBtn = form ? form.querySelector(".hc__send") : null;
    var people = document.getElementById("hcPeople");
    var onlineCount = document.getElementById("hcOnlineCount");
    var typingBox = document.getElementById("hcTyping");
    var replyBox = document.getElementById("hcReplying");
    var stickerBtn = document.getElementById("hcStickerBtn");
    var pop = document.getElementById("hcPop");

    var messages = [];
    var byId = {};
    var lastId = 0, firstId = 0, hasMore = !!seed.more;

    /* ---------- rooms (2026-11-10): the chat is split into groups, like WhatsApp groups ---------- */
    var cmxRoot = document.getElementById("cmx");
    var rooms = seed.rooms || [{ key: "general", name: "general", desc: "", group: "rooms", can: true }];
    var room = seed.room || "general";
    var roomAt = seed.at || {};              // time of the newest message of every group
    var roomPrev = seed.prev || {};          // "Mary: text" of the newest message of every room
    var roomLast = seed.last || {};          // newest message id of every room (from the server)
    var roomCache = {};                      // messages of rooms already opened, so switching back is instant
    var roomsBox = document.getElementById("hcRooms");
    var roomName = document.getElementById("hcRoomName");
    var roomDesc = document.getElementById("hcRoomDesc");
    var lockedBox = document.getElementById("hcLocked");
    var SEEN_KEY = "hplChatSeen";
    var seen = null;
    try { seen = JSON.parse(localStorage.getItem(SEEN_KEY) || "null"); } catch (e) {}
    if (!seen || typeof seen !== "object") { seen = {}; Object.keys(roomLast).forEach(function (k) { seen[k] = roomLast[k]; }); }
    function saveSeen() { try { localStorage.setItem(SEEN_KEY, JSON.stringify(seen)); } catch (e) {} }
    function roomDef(k) { return rooms.filter(function (r) { return r.key === k; })[0] || rooms[0]; }
    function phone() { return window.matchMedia && window.matchMedia("(max-width: 900px)").matches; }
    function roomIsOpen() { return !phone() || (cmxRoot && cmxRoot.classList.contains("is-room")); }
    function unread(k) { return (roomLast[k] || 0) > (seen[k] || 0); }
    function markSeen() {
        if (!visible() || !roomIsOpen()) return;
        var top = Math.max(roomLast[room] || 0, lastId || 0);
        if ((seen[room] || 0) < top) { seen[room] = top; saveSeen(); renderRooms(); }
    }
    function renderRooms() {
        if (!roomsBox) return;
        roomsBox.innerHTML = "";
        var groups = { rooms: "Groups", campaigns: "Campaign groups" };
        Object.keys(groups).forEach(function (g) {
            var inG = rooms.filter(function (r) { return r.group === g; });
            if (!inG.length) return;
            roomsBox.appendChild(el("h3", "hc__rooms-h", groups[g]));
            inG.forEach(function (r) {
                var b = el("button", "hc__room" + (r.key === room ? " is-on" : "") + (unread(r.key) && !(r.key === room && roomIsOpen()) ? " is-unread" : ""));
                b.type = "button"; b.setAttribute("data-room", r.key);
                var tile = el("span", "hc__room-hash hc__tone--" + (r.tone || "brown"));
                tile.innerHTML = r.icon || "";   // icon markup made by the server (includes/icons.php)
                b.appendChild(tile);
                var t = el("span", "hc__room-text");
                t.appendChild(el("b", null, r.name));
                t.appendChild(el("small", null, roomPrev[r.key] || r.desc));
                b.appendChild(t);
                var side = el("span", "hc__room-side");
                if (roomAt[r.key]) side.appendChild(el("time", null, shortTime(roomAt[r.key])));
                side.appendChild(el("i", "hc__room-dot"));
                b.appendChild(side);
                b.addEventListener("click", function () { openRoom(r.key); });
                roomsBox.appendChild(b);
            });
        });
        var any = rooms.some(function (r) { return unread(r.key) && !(r.key === room && roomIsOpen()); });
        var dot = document.getElementById("cmxChatDot");
        if (dot && cmxRoot && cmxRoot.getAttribute("data-tab") !== "chat") dot.hidden = !any;
    }
    function shortTime(ts) {
        var d = new Date(ts * 1000), t = new Date(now());
        if (d.toDateString() === t.toDateString()) return d.toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
        if (now() - ts * 1000 < 6 * 86400000) return d.toLocaleDateString(undefined, { weekday: "short" });
        return d.toLocaleDateString(undefined, { day: "numeric", month: "short" });
    }
    function applyRoomHead() {
        var d = roomDef(room);
        var ic = document.getElementById("hcRoomIcon");
        if (ic) { ic.innerHTML = d.icon || ""; ic.className = "hc__hash hc__tone--" + (d.tone || "brown"); }
        if (roomName) roomName.textContent = d.name;
        if (roomDesc) roomDesc.textContent = d.desc;
        if (lockedBox) lockedBox.hidden = !!d.can;
        if (form) form.hidden = !d.can;
        if (input) input.placeholder = "Message";
    }
    function openRoom(key, quiet) {
        if (cmxRoot) cmxRoot.classList.add("is-room");
        if (key !== room) {
            roomCache[room] = { messages: messages, byId: byId, lastId: lastId, firstId: firstId, hasMore: hasMore };
            room = roomDef(key).key;
            var c = roomCache[room];
            messages = c ? c.messages : []; byId = c ? c.byId : {}; lastId = c ? c.lastId : 0; firstId = c ? c.firstId : 0; hasMore = c ? c.hasMore : false;
            loadingRoom = !c;
            ver = ""; clearReply(); closeMention(); renderTyping([]);
            if (olderBox) olderBox.hidden = !hasMore;
            applyRoomHead(); render(); stuck = true; toBottom();
            schedule(0);
            if (!quiet && window.history && history.replaceState) {
                try { var u = new URL(location.href); u.searchParams.set("tab", "chat"); u.searchParams.set("room", room); history.replaceState(null, "", u); } catch (e) {}
            }
        } else { stuck = true; setTimeout(toBottom, 0); }
        markSeen(); renderRooms(); fitViewport(); syncBody();
    }
    function syncBody() { document.body.classList.toggle("hc-room-open", !!(cmxRoot && cmxRoot.classList.contains("is-room") && cmxRoot.getAttribute("data-tab") === "chat")); }
    function closeRoom() { if (cmxRoot) cmxRoot.classList.remove("is-room"); syncBody(); renderRooms(); }
    var loadingRoom = false;
    // Stickers already in the chat when the page opens stay still; new ones play their animation once.
    var bootMaxId = (seed.messages || []).reduce(function (m, x) { return Math.max(m, x.id || 0); }, 0);
    if (window.hplStickers) window.hplStickers.init({ packs: seed.packs, api: seed.api, csrf: (document.querySelector("#hcForm input[name=csrf]") || {}).value });
    var pendingSeq = 0;
    var replyTo = null;

    function visible() { return root.getClientRects().length > 0 && !document.hidden; }   // (offsetParent is null for the full-screen phone chat)
    function now() { return Date.now() + clockOffset; }
    function dayKey(ts) { var d = new Date(ts * 1000); return d.getFullYear() + "-" + d.getMonth() + "-" + d.getDate(); }
    function dayLabel(ts) {
        var d = new Date(ts * 1000), t = new Date(now());
        var y = new Date(now() - 86400000);
        if (d.toDateString() === t.toDateString()) return "Today";
        if (d.toDateString() === y.toDateString()) return "Yesterday";
        return d.toLocaleDateString(undefined, { weekday: "short", day: "numeric", month: "short", year: d.getFullYear() === t.getFullYear() ? undefined : "numeric" });
    }
    function timeLabel(ts) {
        var s = Math.max(0, Math.round(now() / 1000 - ts));
        if (s < 45) return "just now";
        if (s < 3600) return Math.round(s / 60) + " min ago";
        return new Date(ts * 1000).toLocaleTimeString(undefined, { hour: "numeric", minute: "2-digit" });
    }
    // A person's card (owner, 2026-11-12): tap a name or picture in the chat to message them.
    var person = null;
    function openPerson(m) {
        if (!seed.dm) return;
        if (!person) {
            person = el("div", "hc-person");
            person.hidden = true;
            person.setAttribute("role", "dialog"); person.setAttribute("aria-modal", "true");
            person.innerHTML = '<div class="hc-person__card"><button type="button" class="hc-person__x" aria-label="Close">&times;</button>'
                + '<div class="hc-person__av"></div><div class="hc-person__name"></div><div class="hc-person__tag"></div>'
                + '<a class="hc-person__btn hc-person__btn--main"></a><a class="hc-person__btn hc-person__btn--soft"></a></div>';
            document.body.appendChild(person);
            var close = function () { person.classList.remove("is-open"); setTimeout(function () { person.hidden = true; }, 150); };
            person.addEventListener("click", function (e) { if (e.target === person) close(); });
            person.querySelector(".hc-person__x").addEventListener("click", close);
            document.addEventListener("keydown", function (e) { if (!person.hidden && e.key === "Escape") close(); });
        }
        var av = person.querySelector(".hc-person__av");
        av.textContent = "";
        if (m.avatar) { var img = document.createElement("img"); img.src = m.avatar; img.alt = ""; img.referrerPolicy = "no-referrer"; img.onerror = function () { av.textContent = m.initial || (m.name || "?").charAt(0); }; av.appendChild(img); }
        else av.textContent = m.initial || (m.name || "?").charAt(0);
        person.querySelector(".hc-person__name").textContent = m.name || "";
        person.querySelector(".hc-person__tag").textContent = m.role === "admin" ? "HPL team" : "";
        var msg = person.querySelector(".hc-person__btn--main");
        msg.textContent = m.role === "admin" && !isAdmin ? "Message the HPL team" : "Send " + (m.name || "").split(" ")[0] + " a message";
        msg.href = seed.dm + "?user=" + encodeURIComponent(m.uid);
        var more = person.querySelector(".hc-person__btn--soft");
        more.hidden = !(isAdmin && seed.detail && m.role !== "admin");
        if (!more.hidden) { more.textContent = "Open their admin page"; more.href = seed.detail + "?id=" + encodeURIComponent(m.uid); }
        person.hidden = false;
        requestAnimationFrame(function () { person.classList.add("is-open"); msg.focus(); });
    }

    function el(tag, cls, text) {
        var n = document.createElement(tag);
        if (cls) n.className = cls;
        if (text != null) n.textContent = text;
        return n;
    }
    function svgBtn(cls, label, path) {
        var b = el("button", cls);
        b.type = "button"; b.setAttribute("aria-label", label); b.title = label;
        b.innerHTML = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + path + "</svg>";
        return b;
    }
    var P = {
        smile: '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5s1.3 2 3.5 2 3.5-2 3.5-2M9 9.5h.01M15 9.5h.01"/>',
        reply: '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 6 6v4"/>',
        trash: '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>'
    };
    function escRe(t) { return t.replace(/[.*+?^${}()|[\]\\]/g, "\\$&"); }
    /* Text with links, and @names shown as chips. */
    function fillText(node, text, mentions) {
        var names = ["team"];
        (mentions || []).forEach(function (p) { names.push(escRe(p.name)); names.push(escRe(p.name.split(" ")[0])); });
        names.sort(function (a, b) { return b.length - a.length; });
        var mre = new RegExp("@(" + names.join("|") + ")(?![\\w])", "gi"), at = 0, mm;
        while ((mm = mre.exec(text))) {
            if (mm.index > at) fillLinks(node, text.slice(at, mm.index));
            node.appendChild(el("span", "hc__at", mm[0]));
            at = mm.index + mm[0].length;
        }
        if (at < text.length) fillLinks(node, text.slice(at));
    }
    function fillLinks(node, text) {
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
    var stuck = true;
    function nearBottom() { return scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 140; }
    function toBottom() { scroller.scrollTop = scroller.scrollHeight; if (jump) jump.hidden = true; }
    function onlyEmoji(t) { return t && t.length <= 12 && /^(\p{Extended_Pictographic}|️|‍|\s)+$/u.test(t); }

    /* ---------- rendering ---------- */
    var openMsg = null;
    function touchUI() { return window.matchMedia && window.matchMedia("(hover: none)").matches; }
    function closeActs() { if (openMsg) { openMsg.classList.remove("is-open"); openMsg = null; } }
    document.addEventListener("click", function (e) { if (openMsg && !openMsg.contains(e.target)) closeActs(); });
    function render() {
        var stick = stuck && nearBottom();
        list.innerHTML = "";
        if (!messages.length) {
            var d0 = roomDef(room);
            var empty = el("div", "hc__empty");
            empty.appendChild(el("b", null, loadingRoom ? "Opening " + d0.name : d0.name));
            empty.appendChild(el("span", null, loadingRoom ? "" : (d0.can ? d0.desc + ". Be the first to write." : d0.desc + ". Nothing here yet.")));
            list.appendChild(empty);
            return;
        }
        var prev = null, group = null, lastDay = null;
        messages.forEach(function (m) {
            if (lastDay !== dayKey(m.ts)) {
                list.appendChild(el("div", "hc__day", dayLabel(m.ts)));
                lastDay = dayKey(m.ts);
                prev = null;
            }
            if (m.kind === "event") { list.appendChild(eventRow(m)); prev = null; return; }
            var startGroup = !prev || prev.uid !== m.uid || (m.ts - prev.ts) > 300 || m.reply;
            if (startGroup) {
                group = el("div", "hc__group" + (m.role === "admin" ? " hc__group--team" : "") + (m.uid === me ? " hc__group--mine" : ""));
                var av = el("div", "hc__avatar");
                if (m.avatar) {
                    var img = document.createElement("img");
                    img.src = m.avatar; img.alt = ""; img.loading = "lazy"; img.referrerPolicy = "no-referrer";
                    img.onerror = function () { av.textContent = m.initial; };
                    av.appendChild(img);
                } else {
                    av.textContent = m.initial;
                }
                var meta = el("div", "hc__meta");
                var nm = el("span", "hc__name", m.uid === me ? m.name + " (you)" : m.name);
                meta.appendChild(nm);
                if (m.uid && m.uid !== me) {
                    [av, nm].forEach(function (x) {
                        x.classList.add("hc__who"); x.setAttribute("role", "button"); x.setAttribute("tabindex", "0");
                        x.setAttribute("aria-label", "Open " + m.name);
                        x.addEventListener("click", function (e) { e.stopPropagation(); openPerson(m); });
                        x.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === " ") { e.preventDefault(); openPerson(m); } });
                    });
                }
                if (m.role === "admin") meta.appendChild(el("span", "hc__tag", "HPL team"));
                var t = el("span", "hc__time", timeLabel(m.ts));
                t.setAttribute("data-ts", m.ts);
                t.title = new Date(m.ts * 1000).toLocaleString();
                meta.appendChild(t);
                group.appendChild(av);
                group.appendChild(meta);
                list.appendChild(group);
            }
            var hitsMe = (m.mentions || []).some(function (p) { return p.id === me; });
            var body = el("div", "hc__msg" + (hitsMe ? " is-mention" : "") + (m.pending ? " is-pending" : "") + (m.failed ? " is-failed" : "") + ((m.sticker && !m.text && !m.reply) || (!m.sticker && !m.reply && onlyEmoji(m.text)) ? " is-sticker" : "") + (startGroup ? " is-first" : ""));
            body.setAttribute("data-id", m.id);
            if (m.reply) {
                var q = el("button", "hc__quote");
                q.type = "button";
                q.appendChild(el("b", null, m.reply.name));
                q.appendChild(el("span", null, m.reply.sticker && !m.reply.text ? "Sticker" : m.reply.text));
                q.addEventListener("click", function () { flash(m.reply.id); });
                body.appendChild(q);
            }
            if (m.text) {
                var tx = el("div", "hc__text" + (onlyEmoji(m.text) && !m.sticker ? " is-big" : ""));
                fillText(tx, m.text, m.mentions);
                body.appendChild(tx);
            }
            if (m.sticker) {
                var st = document.createElement("img");
                st.src = m.sticker; st.alt = "Sticker"; st.className = "hc__sticker"; st.loading = "lazy";
                if (m.temp || (m.id && m.id > bootMaxId)) { if (window.hplStickers && !m._played) { m._played = true; window.hplStickers.play(st, 4000); } }
                body.appendChild(st);
            }
            if (m.failed) {
                var retry = el("button", "hc__retry", (m.error ? m.error + " " : "Not sent. ") + "Retry");
                retry.type = "button";
                retry.addEventListener("click", function () { resend(m); });
                body.appendChild(retry);
            } else if (!m.pending) {
                var acts = el("div", "hc__acts");
                reactDefs.slice(0, 6).forEach(function (d) {
                    var qb = el("button", "hc__act hc__act--emo", d.e);
                    qb.type = "button"; qb.setAttribute("aria-label", "React " + (d.label || d.k));
                    qb.addEventListener("click", function (e) { e.stopPropagation(); react(m, d.k); closeActs(); });
                    acts.appendChild(qb);
                });
                acts.appendChild(el("span", "hc__act-sep"));
                var re = svgBtn("hc__act", "Reply", P.reply);
                re.addEventListener("click", function (e) { e.stopPropagation(); closeActs(); setReply(m); input && input.focus(); });
                acts.appendChild(re);
                if (isAdmin || (m.uid === me && now() / 1000 - m.ts < 600)) {
                    var del = svgBtn("hc__act hc__act--bad", "Remove", P.trash);
                    del.addEventListener("click", function (e) { e.stopPropagation(); closeActs(); removeMsg(m.id); });
                    acts.appendChild(del);
                }
                body.appendChild(acts);
            }
            if (m.reacts && m.reacts.length) {
                var bar = el("div", "hc__reacts");
                m.reacts.forEach(function (r) {
                    var chip = el("button", "hc__react" + (r.mine ? " is-mine" : ""));
                    chip.type = "button";
                    chip.setAttribute("aria-pressed", r.mine ? "true" : "false");
                    chip.appendChild(el("span", null, r.e));
                    chip.appendChild(el("b", null, String(r.n)));
                    chip.addEventListener("click", function () { react(m, r.k); });
                    bar.appendChild(chip);
                });
                body.appendChild(bar);
            }
            // Phones: tap a message to see quick reactions, reply and remove. Double-tap = thumbs up.
            body.addEventListener("click", function (e) {
                if (!touchUI() || m.pending || m.failed || e.target.closest("a,button")) return;
                var open = body.classList.contains("is-open");
                closeActs();
                if (!open) { body.classList.add("is-open"); openMsg = body; }
            });
            body.addEventListener("dblclick", function (e) { if (e.target.closest("a,button")) return; closeActs(); react(m, "like"); });
            group.appendChild(body);
            prev = m;
        });
        if (stick) toBottom();
    }
    /* An activity line: "Mary passed 10K views on a clip". Small, centred, and people can cheer. */
    function eventRow(m) {
        var row = el("div", "hc__event hc__msg");
        row.setAttribute("data-id", m.id);
        var line = el("div", "hc__event-line");
        var av = el("span", "hc__event-av");
        if (m.avatar) { var img = document.createElement("img"); img.src = m.avatar; img.alt = ""; img.referrerPolicy = "no-referrer"; img.onerror = function () { av.textContent = m.initial; }; av.appendChild(img); }
        else av.textContent = m.initial;
        line.appendChild(av);
        var tx = el("span", "hc__event-text");
        tx.appendChild(el("b", null, m.uid === me ? "You" : m.name));
        tx.appendChild(document.createTextNode(" " + m.text));
        line.appendChild(tx);
        row.appendChild(line);
        var bar = el("div", "hc__reacts");
        var mineClap = false;
        (m.reacts || []).forEach(function (r) {
            if (r.k === "clap" && r.mine) mineClap = true;
            var chip = el("button", "hc__react" + (r.mine ? " is-mine" : ""));
            chip.type = "button";
            chip.appendChild(el("span", null, r.e)); chip.appendChild(el("b", null, String(r.n)));
            chip.addEventListener("click", function () { react(m, r.k); });
            bar.appendChild(chip);
        });
        if (!mineClap && m.uid !== me) {
            var cheer = el("button", "hc__react hc__react--cheer", "\uD83D\uDC4F Cheer");
            cheer.type = "button";
            cheer.addEventListener("click", function () { react(m, "clap"); });
            bar.appendChild(cheer);
        }
        row.appendChild(bar);
        return row;
    }
    function flash(id) {
        var n = list.querySelector('.hc__msg[data-id="' + id + '"]');
        if (!n) { if (window.hplToast) window.hplToast("That message is further up. Tap 'Load earlier messages'.", "info"); return; }
        stuck = false;
        n.scrollIntoView({ behavior: "smooth", block: "center" });
        n.classList.add("is-flash");
        setTimeout(function () { n.classList.remove("is-flash"); }, 1400);
    }

    function add(list2) {
        var changed = false;
        list2.forEach(function (m) {
            if (byId[m.id]) return;
            byId[m.id] = m;
            messages.push(m);
            changed = true;
            if (m.id > lastId && m.id < 1e12) lastId = m.id;
            if ((!firstId || m.id < firstId) && m.id < 1e12) firstId = m.id;
        });
        if (changed) messages.sort(function (a, b) { return a.id - b.id; });
        return changed;
    }

    function tickTimes() {
        list.querySelectorAll("[data-ts]").forEach(function (t) { t.textContent = timeLabel(+t.getAttribute("data-ts")); });
    }

    function renderPeople(active) {
        if (onlineCount) onlineCount.textContent = active.length;
        document.dispatchEvent(new CustomEvent("hc:online", { detail: active }));
        if (!people) return;
        people.innerHTML = "";
        active.forEach(function (u) {
            var li = el("li");
            var av = el("span", "hc__av hc__av--" + (u.tier || "bronze"));
            if (u.avatar) { var img = document.createElement("img"); img.src = u.avatar; img.alt = ""; img.referrerPolicy = "no-referrer"; img.onerror = function () { av.textContent = (u.name || "?").charAt(0).toUpperCase(); }; av.appendChild(img); }
            else av.textContent = (u.name || "?").charAt(0).toUpperCase();
            li.appendChild(av);
            li.appendChild(el("span", null, u.name + (u.you ? " (you)" : "")));
            if (u.role === "admin") li.appendChild(el("em", null, "Team"));
            people.appendChild(li);
        });
    }
    function renderTyping(names) {
        if (!typingBox) return;
        typingBox.innerHTML = "";
        if (!names || !names.length) return;
        var dots = el("span", "hc__dots"); dots.appendChild(el("i")); dots.appendChild(el("i")); dots.appendChild(el("i"));
        typingBox.appendChild(dots);
        var who = names.length === 1 ? names[0] + " is typing" : names.length === 2 ? names[0] + " and " + names[1] + " are typing" : "Several people are typing";
        typingBox.appendChild(document.createTextNode(who));
    }

    /* ---------- reactions ---------- */
    function react(m, k) {
        var items = (m.reacts || []).map(function (x) { return { k: x.k, e: x.e, n: x.n, mine: x.mine }; });
        var hit = items.filter(function (x) { return x.k === k; })[0];
        if (hit) { hit.mine = !hit.mine; hit.n += hit.mine ? 1 : -1; if (hit.n <= 0) items = items.filter(function (x) { return x !== hit; }); }
        else { var def = reactDefs.filter(function (x) { return x.k === k; })[0]; items.push({ k: k, e: def ? def.e : "", n: 1, mine: true }); }
        m.reacts = items; render();
        var fd = new FormData();
        fd.append("csrf", csrf); fd.append("action", "react"); fd.append("kind", "chat"); fd.append("id", m.id); fd.append("emoji", k);
        fetch(seed.api, { method: "POST", credentials: "same-origin", body: fd })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (!d.ok) { if (window.hplToast) window.hplToast(d.error || "Could not react.", "error"); return; }
                m.reacts = d.reacts || []; render();
            }).catch(function () {});
    }

    /* ---------- replies ---------- */
    function setReply(m) {
        replyTo = m;
        if (!replyBox) return;
        replyBox.innerHTML = "";
        replyBox.hidden = false;
        var t = el("div", "hc__replying-text");
        t.appendChild(el("span", null, "Replying to "));
        t.appendChild(el("b", null, m.uid === me ? "yourself" : m.name));
        t.appendChild(el("em", null, m.text ? m.text.slice(0, 90) : "Sticker"));
        replyBox.appendChild(t);
        var x = el("button", "hc__replying-x", "Cancel"); x.type = "button";
        x.addEventListener("click", clearReply);
        replyBox.appendChild(x);
        input.focus();
    }
    function clearReply() { replyTo = null; if (replyBox) { replyBox.hidden = true; replyBox.innerHTML = ""; } }

    /* ---------- polling ---------- */
    // Busy hours: hundreds of people poll at once. Each poll sends the chat version it already has,
    // so the usual answer is a tiny "nothing new". Polls slow down when the chat is quiet, and a
    // little randomness keeps everyone from asking at the same instant.
    var timer = null, inFlight = false, failures = 0, stopped = false, ver = "", peopleAt = "", lastChange = Date.now();
    function schedule(ms) {
        if (stopped) return;
        clearTimeout(timer);
        var quiet = Date.now() - lastChange;
        var base = document.hidden ? 30000 : (!visible() ? 15000 : (quiet < 30000 ? 3000 : (quiet < 120000 ? 4500 : 7000)));
        base = Math.round(base * (0.85 + Math.random() * 0.3));
        timer = setTimeout(poll, ms != null ? ms : (failures ? Math.min(60000, 4000 * Math.pow(2, failures - 1)) : base));
    }
    function poll() {
        if (inFlight || stopped) return;
        if (document.hidden) { schedule(30000); return; }
        inFlight = true;
        var askRoom = room;
        fetch(feedUrl + "?ch=" + encodeURIComponent(room) + "&since=" + lastId + (ver ? "&v=" + encodeURIComponent(ver) : "") + (peopleAt ? "&a=" + encodeURIComponent(peopleAt) : ""), { credentials: "same-origin", headers: { Accept: "application/json" } })
            .then(function (r) {
                if (r.status === 401) { stopped = true; showNote("Your session ended. Refresh the page to sign in again."); throw new Error("auth"); }
                if (!r.ok) throw new Error("http " + r.status);
                return r.json();
            })
            .then(function (d) {
                inFlight = false; failures = 0;
                if (d.now) clockOffset = d.now * 1000 - Date.now();
                if (d.a != null) peopleAt = String(d.a);
                if (d.active) renderPeople(d.active);
                if (d.count != null && onlineCount) onlineCount.textContent = d.count;
                renderTyping(d.typing || []);
                if (askRoom !== room) { schedule(0); return; }   // the room was switched while this was on its way
                if (d.same) { schedule(); return; }
                if (d.v && d.v !== ver) { if (ver) lastChange = Date.now(); ver = d.v; }
                if (d.rooms) { roomLast = d.rooms; }
                if (d.prev) { roomPrev = d.prev; }
                if (d.at) { roomAt = d.at; }
                if (loadingRoom) { loadingRoom = false; hasMore = !!d.more; if (olderBox) olderBox.hidden = !hasMore; if (!(d.messages || []).length) render(); }
                var wasNear = stuck || nearBottom();
                var removed = (d.removed || []).filter(function (id) { return byId[id]; });
                removed.forEach(dropLocal);
                var fresh = (d.messages || []).filter(function (m) { return !byId[m.id]; });
                var got = add(fresh);
                // Reactions on recent messages.
                var rxChanged = false;
                if (d.rx) {
                    messages.forEach(function (m) {
                        if (m.pending || m.id >= 1e12) return;
                        var nv = d.rx[m.id] || [];
                        if (JSON.stringify(nv) !== JSON.stringify(m.reacts || [])) { m.reacts = nv; rxChanged = true; }
                    });
                }
                if (got || removed.length || rxChanged) {
                    render();
                    if (got && wasNear) toBottom();
                    else if (got && jump) jump.hidden = false;
                }
                if (got && fresh.some(function (m) { return m.uid !== me; })) document.dispatchEvent(new CustomEvent("hc:new"));
                if (got && roomIsOpen() && fresh.some(function (m) { return (m.mentions || []).some(function (p) { return p.id === me; }); }) && window.hplToast) window.hplToast("Someone mentioned you", "info");
                markSeen(); renderRooms();
                schedule();
            })
            .catch(function () { inFlight = false; if (stopped) return; failures++; schedule(); });
    }

    /* ---------- sending ---------- */
    function sendMsg(text, temp, sticker, reply) {
        var sendRoom = room;
        var fd = new FormData();
        fd.append("csrf", csrf);
        fd.append("message", text);
        fd.append("ch", sendRoom);
        var named = picked.filter(function (p) { return text.toLowerCase().indexOf("@" + p.name.split(" ")[0].toLowerCase()) !== -1; });
        picked = [];
        if (named.length) fd.append("mentions", named.map(function (p) { return p.id; }).join(","));
        if (sticker) fd.append("sticker", sticker.key);
        if (reply) fd.append("reply_to", reply.id);
        return fetch(feedUrl, { method: "POST", credentials: "same-origin", body: fd })
            .then(function (r) { return r.json().catch(function () { return { ok: false, error: "Could not send. Try again." }; }); })
            .then(function (d) {
                if (sendRoom !== room) {   // sent, but the person already moved to another room
                    var c = roomCache[sendRoom];
                    if (c) { delete c.byId[temp.id]; c.messages = c.messages.filter(function (m) { return m.id !== temp.id; }); if (d.ok && d.message && !c.byId[d.message.id]) { c.byId[d.message.id] = d.message; c.messages.push(d.message); c.lastId = Math.max(c.lastId, d.message.id); } }
                    if (!d.ok && window.hplToast) window.hplToast(d.error || "Could not send. Try again.", "error");
                    return;
                }
                dropLocal(temp.id);
                if (!d.ok || !d.message) {
                    temp.pending = false; temp.failed = true; temp.error = d.error;
                    byId[temp.id] = temp; messages.push(temp);
                    if (window.hplToast) window.hplToast(d.error || "Could not send. Try again.", "error");
                } else {
                    add([d.message]);
                    roomLast[room] = Math.max(roomLast[room] || 0, d.message.id); markSeen();
                    if (d.forwarded && window.hplToast) window.hplToast("Sent to the HPL team as well. They will reply in Messages.", "ok");
                }
                render(); toBottom();
                schedule(400);
            })
            .catch(function () {
                temp.pending = false; temp.failed = true;
                render();
                if (window.hplToast) window.hplToast("Network error. Your message was not sent.", "error");
            });
    }
    function resend(m) {
        dropLocal(m.id);
        submit(m.text, m._sticker || null);
    }
    function submit(text, sticker) {
        pendingSeq++;
        var reply = replyTo;
        var temp = { kind: "msg", mentions: picked.slice(), id: 1e12 + pendingSeq, uid: me, name: "You", role: isAdmin ? "admin" : "creator", initial: "", text: text, ts: Math.round(now() / 1000), pending: true,
            sticker: sticker ? sticker.url : null, _sticker: sticker, reply: reply ? { id: reply.id, name: reply.name, text: (reply.text || "").slice(0, 140), sticker: !!reply.sticker } : null, reacts: [] };
        var mine = messages.filter(function (x) { return x.uid === me && !x.pending; }).pop();
        if (mine) { temp.name = mine.name; temp.initial = mine.initial; temp.avatar = mine.avatar; }
        byId[temp.id] = temp; messages.push(temp);
        clearReply();
        render(); toBottom();
        return sendMsg(text, temp, sticker, reply);
    }
    function dropLocal(id) {
        if (!byId[id]) return;
        delete byId[id];
        messages = messages.filter(function (m) { return m.id !== id; });
    }
    function removeMsg(id) {
        var go = window.hplConfirm ? window.hplConfirm("Remove this message for everyone?") : Promise.resolve(true);
        go.then(function (ok) {
            if (!ok) return;
            var fd = new FormData();
            fd.append("csrf", csrf); fd.append("action", "delete"); fd.append("id", id); fd.append("ch", room);
            fetch(feedUrl, { method: "POST", credentials: "same-origin", body: fd })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d.ok) { dropLocal(id); render(); }
                    else if (window.hplToast) window.hplToast(d.error || "Could not remove the message.", "error");
                })
                .catch(function () {});
        });
    }

    /* ---------- composer ---------- */
    var lastTypingPing = 0;
    function grow() {
        input.style.height = "auto";
        input.style.height = Math.min(160, input.scrollHeight) + "px";
        input.style.overflowY = input.scrollHeight > 160 ? "auto" : "hidden";
        var n = input.value.length;
        if (count) {
            count.hidden = n < 800;
            count.textContent = (1000 - n) + " left";
            count.classList.toggle("is-near", n > 950);
        }
        if (sendBtn) sendBtn.disabled = input.value.trim() === "";
        if (form) form.classList.toggle("has-text", input.value.trim() !== "");
    }
    function pingTyping() {
        if (Date.now() - lastTypingPing < 4000 || !input.value.trim()) return;
        lastTypingPing = Date.now();
        var fd = new FormData(); fd.append("csrf", csrf); fd.append("action", "typing"); fd.append("ch", room);
        fetch(feedUrl, { method: "POST", credentials: "same-origin", body: fd }).catch(function () {});
    }
    /* ---------- @mentions: type @ and pick a person; they get a notification ---------- */
    var mentionBox = document.getElementById("hcMention");
    var picked = [];          // people picked for the message being written
    var knownPeople = [];     // who is online (from the server)
    function closeMention() { if (mentionBox) { mentionBox.hidden = true; mentionBox.innerHTML = ""; } }
    function mentionQuery() {
        var upto = input.value.slice(0, input.selectionStart || input.value.length);
        var m = /(^|\s)@([\w'-]{0,20})$/.exec(upto);
        return m ? m[2].toLowerCase() : null;
    }
    function mentionPeople(q) {
        var pool = {}, out = [];
        knownPeople.forEach(function (u) { if (u.id !== me) pool[u.id] = { id: u.id, name: u.name, team: u.role === "admin", avatar: u.avatar }; });
        messages.forEach(function (m) { if (m.uid !== me && !pool[m.uid] && m.uid) pool[m.uid] = { id: m.uid, name: m.name, team: m.role === "admin", avatar: m.avatar }; });
        Object.keys(pool).forEach(function (k) { var p = pool[k]; if (!q || p.name.toLowerCase().split(" ").some(function (w) { return w.indexOf(q) === 0; })) out.push(p); });
        out.sort(function (a, b) { return (b.team ? 1 : 0) - (a.team ? 1 : 0) || a.name.localeCompare(b.name); });
        return out.slice(0, 6);
    }
    function showMention() {
        if (!mentionBox) return;
        var q = mentionQuery();
        if (q === null) { closeMention(); return; }
        var ppl = mentionPeople(q);
        mentionBox.innerHTML = "";
        if ("team".indexOf(q) === 0) ppl.push({ id: 0, name: "team", team: true, hint: "Sends your message to the HPL team too" });
        if (!ppl.length) { closeMention(); return; }
        ppl.forEach(function (p) {
            var b = el("button", "hc__mention-item"); b.type = "button";
            var av = el("span", "hc__mention-av", (p.name || "?").charAt(0).toUpperCase());
            if (p.avatar) { var img = document.createElement("img"); img.src = p.avatar; img.alt = ""; img.referrerPolicy = "no-referrer"; av.textContent = ""; av.appendChild(img); }
            b.appendChild(av);
            b.appendChild(el("b", null, p.name));
            if (p.hint) b.appendChild(el("small", null, p.hint)); else if (p.team) b.appendChild(el("small", null, "HPL team"));
            b.addEventListener("mousedown", function (e) { e.preventDefault(); });
            b.addEventListener("click", function () {
                var pos = input.selectionStart || input.value.length;
                var before = input.value.slice(0, pos).replace(/@([\w'-]{0,20})$/, "@" + p.name + " ");
                input.value = before + input.value.slice(pos);
                input.selectionStart = input.selectionEnd = before.length;
                if (p.id) picked.push({ id: p.id, name: p.name });
                closeMention(); grow(); input.focus();
            });
            mentionBox.appendChild(b);
        });
        mentionBox.hidden = false;
    }
    document.addEventListener("hc:online", function (e) { if (Array.isArray(e.detail)) knownPeople = e.detail; });
    if (lockedBox) lockedBox.querySelector("button").addEventListener("click", function () { openRoom("general"); });

    if (form && input) {
        input.addEventListener("input", function () { grow(); pingTyping(); showMention(); });
        input.addEventListener("blur", function () { setTimeout(closeMention, 150); });
        input.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && !e.shiftKey && !e.isComposing && window.matchMedia("(min-width: 901px)").matches) {
                e.preventDefault();
                form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit", { cancelable: true }));
            }
            if (e.key === "Escape" && replyTo) clearReply();
            if (e.key === "Escape") closeMention();
        });
        form.addEventListener("submit", function (e) {
            e.preventDefault();
            var text = input.value.trim();
            if (!text) return;
            input.value = "";
            grow();
            submit(text, null);
            input.focus();
        });
        grow();
    }

    /* ---------- stickers (emoji come from the phone keyboard) ---------- */
    function togglePop(anchor) {
        if (!pop) return;
        if (!pop.hidden) { closeLocalPop(); return; }
        pop.innerHTML = "";
        pop.className = "cmx-pop hc__pop hc__pop--stickers";
        if (window.hplStickers) window.hplStickers.render(pop, function (st) { closeLocalPop(); submit("", st); });
        pop.hidden = false;
        root.classList.add("is-stk");
        if (anchor) anchor.setAttribute("aria-expanded", "true");
        if (input && touchUI()) input.blur();
        if (stuck) setTimeout(toBottom, 0);
    }
    function closeLocalPop() {
        if (!pop) return;
        pop.hidden = true;
        root.classList.remove("is-stk");
        if (stickerBtn) stickerBtn.setAttribute("aria-expanded", "false");
    }
    if (stickerBtn) stickerBtn.addEventListener("click", function (e) { e.stopPropagation(); togglePop(stickerBtn); });
    if (input) input.addEventListener("focus", function () { if (pop && !pop.hidden) closeLocalPop(); });
    document.addEventListener("click", function (e) { if (pop && !pop.hidden && !pop.contains(e.target) && e.target !== stickerBtn) closeLocalPop(); });

    /* ---------- phone: full screen, stays above the keyboard ---------- */
    var backBtn = document.getElementById("hcBack");
    if (backBtn) backBtn.addEventListener("click", closeRoom);
    var vv = window.visualViewport;
    function fitViewport() {
        if (!vv) return;
        root.style.setProperty("--hc-vh", vv.height + "px");
        root.style.setProperty("--hc-top", vv.offsetTop + "px");
        if (stuck) toBottom();
    }
    if (vv) { vv.addEventListener("resize", fitViewport); vv.addEventListener("scroll", fitViewport); fitViewport(); }

    /* ---------- older messages ---------- */
    function loadOlder() {
        var btn = olderBox.querySelector("button");
        btn.disabled = true; btn.textContent = "Loading";
        var olderRoom = room;
        fetch(feedUrl + "?ch=" + encodeURIComponent(room) + "&before=" + firstId, { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (olderRoom !== room) return;
                var anchor = null, offset = 0, box = scroller.getBoundingClientRect();
                var nodes = list.querySelectorAll(".hc__msg");
                for (var i = 0; i < nodes.length; i++) {
                    var r = nodes[i].getBoundingClientRect();
                    if (r.bottom > box.top) { anchor = nodes[i].getAttribute("data-id"); offset = r.top - box.top; break; }
                }
                stuck = false;
                add(d.messages || []);
                hasMore = !!d.more;
                olderBox.hidden = !hasMore;
                render();
                var again = anchor && list.querySelector('.hc__msg[data-id="' + anchor + '"]');
                if (again) scroller.scrollTop += (again.getBoundingClientRect().top - scroller.getBoundingClientRect().top) - offset;
            })
            .catch(function () {})
            .then(function () { btn.disabled = false; btn.textContent = "Load earlier messages"; olderBox.hidden = !hasMore; });
    }
    if (olderBox) olderBox.querySelector("button").addEventListener("click", loadOlder);

    function showNote(text) { if (window.hplToast) window.hplToast(text, "error"); }

    if (jump) jump.addEventListener("click", toBottom);
    scroller.addEventListener("scroll", function () { stuck = nearBottom(); if (jump && stuck) jump.hidden = true; }, { passive: true });
    if (window.ResizeObserver) {
        new ResizeObserver(function () { if (stuck) toBottom(); }).observe(list);
        new ResizeObserver(function () { if (stuck) toBottom(); }).observe(scroller);
    }
    window.addEventListener("load", function () { if (stuck) toBottom(); });
    document.addEventListener("visibilitychange", function () { if (!document.hidden && !stopped) schedule(200); });
    document.addEventListener("cmx:tab", function () { syncBody(); });
    document.addEventListener("cmx:tab", function (e) { if (e.detail === "chat") { stuck = true; setTimeout(toBottom, 0); schedule(100); markSeen(); renderRooms(); } });
    window.addEventListener("resize", function () { renderRooms(); });

    add(seed.messages || []);
    olderBox.hidden = !hasMore;
    if (seed.roomOpen && cmxRoot) cmxRoot.classList.add("is-room");
    syncBody();
    applyRoomHead();
    renderRooms();
    render();
    markSeen();
    toBottom();
    setInterval(tickTimes, 30000);
    schedule(visible() ? 1500 : 6000);
})();
