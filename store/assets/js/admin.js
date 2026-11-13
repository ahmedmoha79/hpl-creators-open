/* HPL Store admin: language tabs, spec rows, chunked video upload with auto cover, live inbox. */
(function () {
  "use strict";
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
  var CSRF = document.body.getAttribute("data-csrf");

  /* language tabs inside forms */
  $$("[data-langtabs]").forEach(function (w) {
    $$("[data-lang-tab]", w).forEach(function (b) {
      b.addEventListener("click", function () {
        var l = b.getAttribute("data-lang-tab");
        $$("[data-lang-tab]", w).forEach(function (x) { x.setAttribute("aria-selected", String(x === b)); });
        $$("[data-lang-pane]", w).forEach(function (p) { p.hidden = p.getAttribute("data-lang-pane") !== l; });
      });
    });
  });

  /* add spec row */
  $$("[data-add-spec]").forEach(function (addSpec) { addSpec.addEventListener("click", function () {
    var box = $("[data-specs]", addSpec.parentNode), row = $(".a-spec", box).cloneNode(true);
    $$("input", row).forEach(function (i) { i.value = ""; });
    box.appendChild(row);
    $("input", row).focus();
  }); });

  /* ---- video upload in pieces ---- */
  var vf = $("[data-video-form]");
  if (vf) {
    var input = $("[data-video-file]", vf), bar = $("[data-progress]", vf), fileField = $("[data-file-field]", vf);
    var posterField = $("[data-poster-field]", vf), preview = $("[data-preview]", vf), posterPrev = $("[data-poster-preview]", vf), save = $("[data-video-save]", vf);
    var uploading = false;
    function setBar(p, txt) { bar.hidden = false; $("span", bar).style.width = p + "%"; $("b", bar).textContent = txt; }
    function grabPoster(file) {
      // Take a cover picture from the video itself (second 2, or the middle of short clips).
      var url = URL.createObjectURL(file);
      preview.src = url;
      preview.hidden = false;
      preview.addEventListener("loadedmetadata", function () {
        var portrait = preview.videoHeight > preview.videoWidth;
        var r = $("input[name=orientation][value=" + (portrait ? "portrait" : "landscape") + "]", vf);
        if (r) r.checked = true;
        preview.currentTime = Math.min(2, (preview.duration || 4) / 2);
      }, { once: true });
      preview.addEventListener("seeked", function () {
        try {
          var c = document.createElement("canvas"), max = 1280, w = preview.videoWidth, h = preview.videoHeight, s = Math.min(1, max / Math.max(w, h));
          c.width = Math.round(w * s); c.height = Math.round(h * s);
          c.getContext("2d").drawImage(preview, 0, 0, c.width, c.height);
          posterField.value = c.toDataURL("image/jpeg", 0.82);
          posterPrev.src = posterField.value; posterPrev.hidden = false;
        } catch (e) {}
      }, { once: true });
    }
    function send(file, id, i, total, size) {
      var start = i * size, fd = new FormData();
      fd.append("csrf", CSRF); fd.append("upload_id", id); fd.append("index", i); fd.append("total", total); fd.append("offset", start);
      fd.append("chunk", file.slice(start, start + size), "chunk");
      return fetch(vf.getAttribute("data-upload") || "upload.php", { method: "POST", body: fd, credentials: "same-origin" })
        .then(function (r) { return r.json().then(function (j) {
          if (r.status === 409 && typeof j.have === "number" && j.have === start + Math.min(size, file.size - start)) return { ok: true, next: i + 1, done: false, resumed: true };
          if (!r.ok || !j.ok) throw new Error(j.error || "Upload failed"); return j; }); });
    }
    input.addEventListener("change", function () {
      var file = input.files[0];
      if (!file) return;
      grabPoster(file);
      var size = parseInt(vf.getAttribute("data-chunk") || "1900000", 10), total = Math.ceil(file.size / size), id = Array.from(crypto.getRandomValues(new Uint8Array(16))).map(function (b) { return ("0" + b.toString(16)).slice(-2); }).join("");
      uploading = true; save.disabled = true;
      var i = 0, tries = 0;
      (function next() {
        setBar(Math.round(i / total * 100), "Uploading " + Math.round(i / total * 100) + "% (" + (file.size / 1048576).toFixed(0) + " MB)");
        send(file, id, i, total, size).then(function (j) {
          tries = 0;
          if (j.done) { fileField.value = j.file; setBar(100, "Uploaded. Now press Save video."); uploading = false; save.disabled = false; return; }
          i = j.next; next();
        }).catch(function (e) {
          if (++tries <= 3) { setTimeout(next, 1500 * tries); return; } // weak connection: retry the same piece
          setBar(0, e.message + " Pick the file again to retry.");
          uploading = false; save.disabled = false;
        });
      })();
    });
    window.addEventListener("beforeunload", function (e) { if (uploading) { e.preventDefault(); e.returnValue = ""; } });
  }

  /* ---- unread count in the menu on every admin page + desktop alert ---- */
  var countEl = $("[data-count-chats]");
  var last = -1;
  function notify(n) {
    if (n > last && last >= 0) {
      try { new Audio("data:audio/wav;base64,UklGRjQAAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YRAAAAB/f39/gICAgH9/f3+AgICA").play(); } catch (e) {}
      if (window.Notification && Notification.permission === "granted") new Notification("HPL store: new chat message", { body: "Open the inbox to reply." });
    }
    last = n;
    if (countEl) { countEl.hidden = !n; countEl.textContent = n; }
    document.title = (n ? "(" + n + ") " : "") + document.title.replace(/^\(\d+\) /, "");
  }

  var inbox = $("#inbox");
  if (!inbox) {
    var api = "api.php";
    (function tick() {
      var fd = new FormData(); fd.append("csrf", CSRF); fd.append("action", "list");
      fetch(api, { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) { return r.json(); }).then(function (j) { if (j.ok) notify(j.unread_total); }).catch(function () {});
      setTimeout(tick, 20000);
    })();
    return;
  }

  /* ---- live inbox ---- */
  var API = inbox.getAttribute("data-api");
  var listEl = $("[data-list]", inbox), conv = $("[data-conv]", inbox), empty = $("[data-empty]", inbox), body = $("[data-c-body]", inbox);
  var form = $("[data-c-form]", inbox), input = $("[data-c-input]", inbox), filter = $("[data-filter]", inbox);
  var current = parseInt(inbox.getAttribute("data-open") || "0", 10), lastId = 0, timer = null, chats = [], typingSent = 0, lastDay = "";
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function linkify(s) { return esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>'); }
  var tick2 = '<svg class="ticks2" viewBox="0 0 16 10" aria-hidden="true"><path d="M1 5.5 3.8 8.3 10 2M6.5 8.3 12.7 2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>';

  function call(data, file) {
    var fd = new FormData(); fd.append("csrf", CSRF);
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    if (file) fd.append("photo", file);
    return fetch(API, { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok || !j.ok) { var e = new Error(j.error || "Failed"); throw e; } return j; });
    });
  }
  // Replies and actions count as reading the chat.
  var _call = call;
  call = function (data, file) { if (data.chat && data.action !== "poll") data.seen = "1"; return _call(data, file); };

  function renderList() {
    var q = (filter.value || "").toLowerCase();
    listEl.innerHTML = chats.filter(function (c) { return !q || (c.name + " " + c.country).toLowerCase().indexOf(q) > -1; }).map(function (c) {
      return '<li><button type="button" data-id="' + c.id + '"' + (c.id === current ? ' aria-current="true"' : "") + ' class="' + (c.unread ? "is-unread" : "") + '">' +
        '<span class="n">' + (c.online ? '<i class="dot" title="On the site now"></i>' : "") + esc(c.name) + (c.country ? ' <span class="muted small">· ' + esc(c.country) + "</span>" : "") + "</span>" +
        '<span class="t">' + esc(c.ago) + (c.unread ? ' <span class="a-count">' + c.unread + "</span>" : "") + "</span>" +
        '<span class="p">' + (c.status === "closed" ? "✓ " : "") + esc(c.preview) + "</span></button></li>";
    }).join("") || '<li class="muted" style="padding:16px">No chats yet.</li>';
  }

  function addMsg(m, seenUpto) {
    if (document.getElementById("am" + m.id)) return;
    var day = new Date(m.at * 1000).toDateString();
    if (day !== lastDay) { lastDay = day; var d = document.createElement("div"); d.className = "chat__day"; d.textContent = new Date(m.at * 1000).toLocaleDateString(); body.appendChild(d); }
    var el = document.createElement("div");
    el.id = "am" + m.id;
    el.className = "msg " + (m.from === "admin" ? "msg--out" : m.from === "system" ? "msg--sys" : "msg--in");
    el.setAttribute("data-at", m.at);
    el.innerHTML = (m.img ? '<a href="' + esc(m.img) + '" target="_blank"><img src="' + esc(m.img) + '" alt=""></a>' : "") + (m.body ? linkify(m.body) : "") +
      '<span class="msg__meta">' + esc(m.time) + (m.from === "admin" ? tick2 : "") + "</span>";
    body.appendChild(el);
    if (m.id > lastId) lastId = m.id;
  }

  function apply(j) {
    chats = j.list || chats;
    renderList();
    notify(j.unread_total || 0);
    if (!j.chat || j.chat.id !== current) return;
    var c = j.chat;
    conv.hidden = false; empty.hidden = true; inbox.classList.add("is-conv");
    $("[data-c-name]", inbox).textContent = c.name;
    $("[data-c-meta]", inbox).textContent = [c.typing ? "typing…" : (c.online ? "on the site now" : ""), c.country, c.phone, c.lang ? c.lang.toUpperCase() : "", c.product ? "asked about " + c.product : ""].filter(Boolean).join(" · ");
    var wa = $("[data-c-wa]", inbox); wa.hidden = !c.wa; if (c.wa) wa.href = "https://wa.me/" + c.wa;
    $("[data-c-status]", inbox).value = c.status;
    $("[data-c-orders]", inbox).innerHTML = (c.orders || []).map(function (o) { return '<a class="a-st a-st--' + esc(o.status) + '" href="orders.php?id=' + o.id + '">' + esc(o.ref) + " · " + esc(o.product) + " · " + esc(o.status.replace("_", " ")) + "</a>"; }).join(" ");
    var near = body.scrollHeight - body.scrollTop - body.clientHeight < 120;
    var before = lastId;
    (j.messages || []).forEach(function (m) { addMsg(m); });
    $$(".msg--out", body).forEach(function (el) { var t = $(".ticks2", el); if (t) t.classList.toggle("is-seen", c.seen_upto && parseInt(el.getAttribute("data-at"), 10) <= c.seen_upto); });
    if (lastId !== before && (near || before === 0)) body.scrollTop = body.scrollHeight;
  }

  function load(id) {
    current = id; lastId = 0; lastDay = ""; body.innerHTML = "";
    history.replaceState(null, "", "chat.php?id=" + id);
    poll();
    input.focus();
  }

  function poll() {
    clearTimeout(timer);
    call({ action: "poll", chat: current || "", after: lastId, seen: current && !document.hidden && document.hasFocus() ? "1" : "0" }).then(apply).catch(function () {}).then(function () { timer = setTimeout(poll, document.hidden ? 15000 : 3000); });
  }

  listEl.addEventListener("click", function (e) { var b = e.target.closest("[data-id]"); if (b) load(parseInt(b.getAttribute("data-id"), 10)); });
  filter.addEventListener("input", renderList);
  $("[data-back]", inbox).addEventListener("click", function () { inbox.classList.remove("is-conv"); current = 0; history.replaceState(null, "", "chat.php"); conv.hidden = true; empty.hidden = false; });
  $("[data-c-status]", inbox).addEventListener("change", function (e) { call({ action: "status", chat: current, status: e.target.value, after: lastId }).then(apply); });
  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var t = input.value.trim(); if (!t || !current) return;
    input.value = ""; input.style.height = "";
    call({ action: "send", chat: current, body: t, after: lastId }).then(function (j) { apply(j); body.scrollTop = body.scrollHeight; }).catch(function (err) { input.value = t; alert("Not sent: " + err.message); });
  });
  input.addEventListener("keydown", function (e) { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); form.requestSubmit(); } });
  input.addEventListener("input", function () {
    input.style.height = "auto"; input.style.height = Math.min(input.scrollHeight, 160) + "px";
    if (current && Date.now() - typingSent > 4000) { typingSent = Date.now(); call({ action: "typing", chat: current }).catch(function () {}); }
  });
  $("[data-c-photo]", inbox).addEventListener("change", function (e) {
    var f = e.target.files[0]; e.target.value = "";
    if (f && current) call({ action: "photo", chat: current, after: lastId }, f).then(function (j) { apply(j); body.scrollTop = body.scrollHeight; }).catch(function (err) { alert("Photo not sent: " + err.message); });
  });
  $$("[data-tpl]", inbox).forEach(function (b) {
    b.addEventListener("click", function () {
      if (!current) return;
      if (!confirm("Send the saved " + b.textContent.replace("Send ", "") + " to this buyer?")) return;
      call({ action: "template", which: b.getAttribute("data-tpl"), chat: current, after: lastId }).then(function (j) { apply(j); body.scrollTop = body.scrollHeight; }).catch(function (err) { alert(err.message); });
    });
  });
  var nb = $("[data-notify]", inbox);
  if (!window.Notification || Notification.permission === "granted") nb.hidden = true;
  nb.addEventListener("click", function () { Notification.requestPermission().then(function (p) { if (p === "granted") nb.hidden = true; }); });
  document.addEventListener("visibilitychange", function () { if (!document.hidden) poll(); });
  if (current) load(current); else poll();
})();
