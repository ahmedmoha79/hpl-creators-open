/* HPL Store: menu, videos, gallery, tabs, copy, order form, live chat. No libraries. */
(function () {
  "use strict";
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* ---- menu ---- */
  var burger = $("[data-burger]"), nav = $("#nav");
  if (burger && nav) {
    burger.addEventListener("click", function () {
      var open = burger.getAttribute("aria-expanded") !== "true";
      burger.setAttribute("aria-expanded", String(open));
      nav.classList.toggle("is-open", open);
    });
    $$("a", nav).forEach(function (a) { a.addEventListener("click", function () { burger.setAttribute("aria-expanded", "false"); nav.classList.remove("is-open"); }); });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && nav.classList.contains("is-open")) { burger.click(); burger.focus(); } });
  }

  /* ---- video links play in place only when tapped (saves data) ---- */
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-embed-play]");
    if (!b) return;
    var box = b.closest("[data-embed]");
    var f = document.createElement("iframe");
    f.src = box.getAttribute("data-embed") + (box.getAttribute("data-embed").indexOf("?") > -1 ? "&" : "?") + "autoplay=1";
    f.allow = "autoplay; encrypted-media; picture-in-picture; fullscreen";
    f.allowFullscreen = true;
    f.title = b.getAttribute("aria-label") || "Video";
    box.innerHTML = "";
    box.appendChild(f);
  });
  /* ---- visitor analytics (our own server; see inc/track.php) ---- */
  var TRK = document.body.getAttribute("data-t"), pvId = 0, shown = Date.now(), hiddenFor = 0, hidAt = 0;
  function tsend(data, beacon) {
    if (!TRK) return;
    var body = JSON.stringify(data);
    try {
      if (beacon && navigator.sendBeacon) { navigator.sendBeacon(TRK, new Blob([body], { type: "text/plain;charset=UTF-8" })); return null; }
      return fetch(TRK, { method: "POST", headers: { "Content-Type": "application/json" }, body: body, credentials: "same-origin", keepalive: true });
    } catch (e) { return null; }
  }
  function tev(type, label) { tsend({ a: "ev", type: type, label: (label || "").slice(0, 190) }, true); }
  window.hplTrack = tev;
  (function () {
    var q = new URLSearchParams(location.search), d = {
      a: "pv", path: location.pathname, title: document.title, ref: document.referrer,
      product: document.body.getAttribute("data-product") || 0, screen: screen.width + "x" + screen.height,
      blang: navigator.language || "", tz: (Intl.DateTimeFormat().resolvedOptions().timeZone || "")
    };
    ["utm_source", "utm_medium", "utm_campaign"].forEach(function (k) { if (q.get(k)) d[k] = q.get(k); });
    // Sent after the page has finished loading, so it never competes with pictures and text.
    function go() {
      var r = tsend(d);
      if (r && r.then) r.then(function (x) { return x.json(); }).then(function (j) { pvId = j.pv || 0; }).catch(function () {});
    }
    if (document.readyState === "complete") setTimeout(go, 300); else window.addEventListener("load", function () { setTimeout(go, 300); });
  })();
  function tTime() { if (pvId) tsend({ a: "time", pv: pvId, secs: Math.round((Date.now() - shown - hiddenFor - (hidAt ? Date.now() - hidAt : 0)) / 1000) }, true); }
  document.addEventListener("visibilitychange", function () {
    if (document.visibilityState === "hidden") { tTime(); hidAt = Date.now(); }
    else if (hidAt) { hiddenFor += Date.now() - hidAt; hidAt = 0; }
  });
  window.addEventListener("pagehide", tTime);
  document.addEventListener("click", function (e) {
    var a = e.target.closest("a, [data-chat-open]");
    if (!a) return;
    if (a.hasAttribute("data-chat-open")) return tev("chat_open", location.pathname);
    var h = a.getAttribute("href") || "";
    if (h.indexOf("wa.me/") > -1) tev(h.indexOf("looking") > -1 || h.indexOf("cherche") > -1 || h.indexOf("natafuta") > -1 ? "request" : "whatsapp", location.pathname);
    else if (h.indexOf("tel:") === 0) tev("call", h.slice(4));
    else if (h.indexOf("mailto:") === 0) tev("email", h.slice(7));
    else if (h.indexOf("hayatiprospectors.com") > -1) tev("consult", location.pathname);
    else if (h.indexOf("google.com/maps") > -1) tev("map", location.pathname);
    else if (/tiktok|facebook/.test(h)) tev("social", h.replace(/^https?:\/\//, "").slice(0, 60));
  }, true);

  // Our own video files: swap the cover for the player only when tapped.
  document.addEventListener("click", function (e) {
    var b = e.target.closest("[data-video-play]");
    if (!b) return;
    tev("video", b.getAttribute("aria-label") || "");
    var v = document.createElement("video");
    v.src = b.getAttribute("data-src");
    v.controls = true; v.playsInline = true; v.autoplay = true; v.preload = "auto";
    // No download button, no picture-in-picture, no right-click "Save video as".
    v.setAttribute("controlsList", "nodownload noplaybackrate noremoteplayback");
    v.disablePictureInPicture = true;
    v.setAttribute("disablepictureinpicture", "");
    v.setAttribute("disableremoteplayback", "");
    v.setAttribute("playsinline", "");
    var img = $("img", b);
    if (img) v.poster = img.currentSrc || img.src;
    v.setAttribute("aria-label", b.getAttribute("aria-label") || "Video");
    b.parentNode.replaceChild(v, b);
    var p = v.play();
    if (p && p.catch) p.catch(function () {});
  });
  document.addEventListener("contextmenu", function (e) { if (e.target.closest("video, .vtile")) e.preventDefault(); });
  // Only one video plays at a time.
  document.addEventListener("play", function (e) {
    $$("video").forEach(function (v) { if (v !== e.target) v.pause(); });
  }, true);

  /* ---- tabs (video groups) ---- */
  $$("[data-tabs]").forEach(function (wrap) {
    var tabs = $$("[role=tab]", wrap);
    tabs.forEach(function (t) {
      t.addEventListener("click", function () {
        tabs.forEach(function (x) {
          var on = x === t;
          x.setAttribute("aria-selected", String(on));
          x.tabIndex = on ? 0 : -1;
          var p = document.getElementById(x.getAttribute("aria-controls"));
          if (p) { p.hidden = !on; if (!on) $$("video", p).forEach(function (v) { v.pause(); }); }
        });
      });
      t.addEventListener("keydown", function (e) {
        var i = tabs.indexOf(t);
        if (e.key === "ArrowRight" || e.key === "ArrowLeft") {
          var n = tabs[(i + (e.key === "ArrowRight" ? 1 : tabs.length - 1)) % tabs.length];
          n.focus(); n.click();
        }
      });
    });
  });

  /* ---- product gallery ---- */
  $$("[data-gallery]").forEach(function (g) {
    var box = $(".gallery__main", g), main = $("img", box);
    $$("[data-src]", g).forEach(function (b) {
      b.addEventListener("click", function () {
        main.src = b.getAttribute("data-src");
        box.classList.toggle("is-photo", b.hasAttribute("data-photo"));
        $$("[data-src]", g).forEach(function (x) { x.removeAttribute("aria-current"); });
        b.setAttribute("aria-current", "true");
      });
    });
  });

  /* ---- copy buttons ---- */
  $$("[data-copy]").forEach(function (b) {
    b.addEventListener("click", function () {
      var txt = b.getAttribute("data-copy"), old = b.textContent;
      var done = function () { b.textContent = b.getAttribute("data-copied") || "Copied"; setTimeout(function () { b.textContent = old; }, 1600); };
      if (navigator.clipboard) navigator.clipboard.writeText(txt).then(done, done); else done();
    });
  });

  /* ---- order form: show the right hint and button text for the payment chosen ---- */
  var of = $("[data-order-form]");
  if (of) {
    var sync = function () {
      var m = (of.querySelector("input[name=pay]:checked") || {}).value || "";
      $$("[data-pay-hint]", of).forEach(function (h) { h.hidden = h.getAttribute("data-pay-hint").split(" ").indexOf(m) < 0; });
      var btn = $("[data-order-submit]", of);
      if (btn) btn.textContent = m === "showroom" ? btn.getAttribute("data-t-send") : btn.getAttribute("data-t-request");
    };
    $$("input[name=pay]", of).forEach(function (r) { r.addEventListener("change", sync); });
    sync();
    of.addEventListener("submit", function () { var b = $("[data-order-submit]", of); if (b) { b.disabled = true; } });
  }

  /* ================= live chat ================= */
  var box = $("#hplchat");
  if (!box) return;
  var api = box.getAttribute("data-api");
  var csrf = box.getAttribute("data-csrf");
  var body = $("[data-chat-body]", box), form = $("[data-chat-form]", box), input = $("[data-chat-input]", box);
  var intro = $("[data-chat-intro]", box), statusEl = $("[data-chat-status]", box), photo = $("[data-chat-photo]", box);
  var badge = $("[data-chat-badge]"), hint = $("[data-chat-hint]");
  var T = function (k) { return box.getAttribute("data-t-" + k) || ""; };
  var lastId = 0, hasChat = null, isOpen = false, timer = null, pending = null, product = 0, seenUpto = 0, typingSent = 0;
  var store = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var readUpto = parseInt(store.get("hplchat_read") || "0", 10) || 0;
  var baseTitle = document.title, lastDay = "";

  function call(data, file) {
    var fd = new FormData();
    fd.append("csrf", csrf);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    if (file) fd.append("photo", file);
    return fetch(api, { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) {
      return r.json().then(function (j) {
        if (j && j.csrf) csrf = j.csrf;
        if (!r.ok || !j.ok) { var err = new Error(j && j.error || "http"); err.data = j; throw err; }
        return j;
      });
    });
  }

  var tickSvg = '<svg class="ticks2" viewBox="0 0 16 10" aria-hidden="true"><path d="M1 5.5 3.8 8.3 10 2M6.5 8.3 12.7 2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function linkify(s) { return esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener nofollow">$1</a>'); }

  function addMsg(m) {
    if (m.id && document.getElementById("m" + m.id)) return;
    var day = new Date(m.at * 1000).toDateString();
    if (day !== lastDay) {
      lastDay = day;
      var d = document.createElement("div");
      d.className = "chat__day";
      d.textContent = day === new Date().toDateString() ? T("today") : new Date(m.at * 1000).toLocaleDateString();
      body.appendChild(d);
    }
    var el = document.createElement("div");
    el.className = "msg " + (m.from === "visitor" ? "msg--out" : m.from === "system" ? "msg--sys" : "msg--in");
    if (m.id) el.id = "m" + m.id;
    el.setAttribute("data-at", m.at);
    var html = "";
    if (m.img) html += '<a href="' + esc(m.img) + '" target="_blank" rel="noopener"><img src="' + esc(m.img) + '" alt="" loading="lazy"></a>';
    if (m.body) html += linkify(m.body);
    html += '<span class="msg__meta">' + esc(m.time || "") + (m.from === "visitor" ? tickSvg : "") + "</span>";
    el.innerHTML = html;
    body.appendChild(el);
    if (m.id && m.id > lastId) lastId = m.id;
    return el;
  }

  function paintSeen() {
    $$(".msg--out", body).forEach(function (el) {
      var t = $(".ticks2", el);
      if (t) t.classList.toggle("is-seen", seenUpto && parseInt(el.getAttribute("data-at"), 10) <= seenUpto);
    });
  }

  function scrollDown() { body.scrollTop = body.scrollHeight; }

  function apply(j) {
    if (!j) return;
    if (j.chat === false) {
      hasChat = false;
      if (j.greeting && !$(".msg--in", body)) addMsg({ id: 0, from: "admin", body: j.greeting, at: Date.now() / 1000, time: "" });
    } else {
      hasChat = true;
      var nearBottom = body.scrollHeight - body.scrollTop - body.clientHeight < 80;
      var fresh = 0;
      (j.messages || []).forEach(function (m) { if (!document.getElementById("m" + m.id)) { addMsg(m); if (m.from !== "visitor") fresh++; } });
      seenUpto = j.seen_upto || 0;
      paintSeen();
      if (fresh && (nearBottom || isOpen)) scrollDown();
    }
    var typing = $(".chat__typing", body);
    if (j.typing && !typing) { typing = document.createElement("div"); typing.className = "chat__typing"; typing.textContent = T("typing"); body.appendChild(typing); scrollDown(); }
    if (!j.typing && typing) typing.remove();
    statusEl.textContent = j.typing ? T("typing") : (j.online ? T("online") : T("away"));
    updateBadge();
  }

  function unreadCount() {
    var n = 0;
    $$(".msg--in", body).forEach(function (el) { var id = parseInt((el.id || "m0").slice(1), 10); if (id > readUpto) n++; });
    return n;
  }
  function updateBadge() {
    if (isOpen) {
      readUpto = Math.max(readUpto, lastId);
      store.set("hplchat_read", String(readUpto));
    }
    var n = isOpen ? 0 : unreadCount();
    badge.hidden = n === 0;
    badge.textContent = String(n);
    document.title = n ? "(" + n + ") " + T("new") : baseTitle;
  }

  function poll() {
    clearTimeout(timer);
    if (document.hidden && !isOpen) { timer = setTimeout(poll, 30000); return; }
    call({ action: "poll", after: lastId, open: isOpen && !document.hidden ? "1" : "0" }).then(apply).catch(function () {}).then(function () {
      timer = setTimeout(poll, isOpen ? 3000 : (hasChat ? 20000 : 60000));
    });
  }

  function open(text, prod) {
    isOpen = true;
    box.hidden = false;
    if (hint) hint.hidden = true;
    if (prod) product = prod;
    intro.hidden = !pending; // the name form only appears when they send their first message
    if (text && !input.value) input.value = text;
    setTimeout(function () { (intro.hidden ? input : $("input", intro)).focus(); scrollDown(); }, 50);
    updateBadge();
    poll();
  }
  function close() {
    isOpen = false;
    box.hidden = true;
    var fab = $(".chatfab");
    if (fab) fab.focus();
    poll();
  }

  document.addEventListener("click", function (e) {
    var o = e.target.closest("[data-chat-open]");
    if (o) { e.preventDefault(); open(o.getAttribute("data-chat-text") || "", parseInt(o.getAttribute("data-chat-product") || "0", 10)); return; }
    if (e.target.closest("[data-chat-close]")) close();
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && isOpen) close(); });

  intro.addEventListener("submit", function (e) {
    e.preventDefault();
    var b = $("button", intro);
    b.disabled = true;
    call({ action: "start", name: intro.name.value, phone: intro.phone.value, country: intro.country.value, page: location.pathname, product: product || "" })
      .then(function (j) {
        body.innerHTML = '<div class="chat__safe">' + esc($(".chat__safe") ? $(".chat__safe").textContent : "") + "</div>";
        lastDay = ""; lastId = 0;
        apply(j);
        intro.hidden = true;
        if (pending) { var p = pending; pending = null; sendText(p); }
        input.focus();
      })
      .catch(function (err) {
        var p = $("[data-chat-intro-err]", intro);
        if (!p) { p = document.createElement("p"); p.setAttribute("data-chat-intro-err", ""); p.style.color = "#B3261E"; intro.insertBefore(p, b); }
        p.textContent = T("sent-fail");
      })
      .then(function () { b.disabled = false; });
  });

  function sendText(text) {
    if (hasChat === false) { pending = text; intro.hidden = false; $("input", intro).focus(); return; }
    var tmp = addMsg({ id: 0, from: "visitor", body: text, at: Date.now() / 1000, time: new Date().toTimeString().slice(0, 5) });
    scrollDown();
    call({ action: "send", body: text, after: lastId, product: product || "" }).then(function (j) {
      if (tmp) tmp.remove();
      apply(j);
      scrollDown();
    }).catch(function () {
      if (tmp) { tmp.classList.add("msg--fail"); $(".msg__meta", tmp).textContent = T("sent-fail"); tmp.title = T("sent-fail"); tmp.addEventListener("click", function () { tmp.remove(); sendText(text); }, { once: true }); }
    });
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = "";
    input.style.height = "";
    sendText(text);
  });
  input.addEventListener("keydown", function (e) {
    if (e.key === "Enter" && !e.shiftKey && !("ontouchstart" in window)) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event("submit")); }
  });
  input.addEventListener("input", function () {
    input.style.height = "auto";
    input.style.height = Math.min(input.scrollHeight, 120) + "px";
    if (hasChat && Date.now() - typingSent > 4000) { typingSent = Date.now(); call({ action: "typing" }).catch(function () {}); }
  });
  photo.addEventListener("change", function () {
    var f = photo.files && photo.files[0];
    photo.value = "";
    if (!f) return;
    if (hasChat === false) { intro.hidden = false; return; }
    if (f.size > 8 * 1024 * 1024) { addMsg({ id: 0, from: "system", body: T("photo-fail"), at: Date.now() / 1000 }); return; }
    call({ action: "photo", after: lastId }, f).then(function (j) { apply(j); scrollDown(); })
      .catch(function () { addMsg({ id: 0, from: "system", body: T("photo-fail"), at: Date.now() / 1000 }); scrollDown(); });
  });

  // Open straight away when a link asks for it (e.g. from the order page: #chat).
  if (location.hash === "#chat") setTimeout(function () { open(""); }, 300);
  // A gentle hint once per visitor after 25 s on the page.
  if (hint && !store.get("hplchat_hinted")) setTimeout(function () { if (!isOpen) { hint.hidden = false; store.set("hplchat_hinted", "1"); setTimeout(function () { hint.hidden = true; }, 10000); } }, 8000);
  document.addEventListener("visibilitychange", function () { if (!document.hidden) poll(); });
  // First check for chat messages once the page has loaded (only matters for people who already chatted).
  if (document.readyState === "complete") setTimeout(poll, 1200); else window.addEventListener("load", function () { setTimeout(poll, 1200); });
})();
