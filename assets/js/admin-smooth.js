/* Admin without page jumps (owner, 2026-11-13: "whenever I accept something it scrolls me to the top").
   Every admin form is sent in the background; the server still answers with the normal page (after its redirect), and
   only the page body (.app__content), the menu counts and the title are swapped in. You stay where you were, the result
   shows as a small message at the bottom, and you can go straight on to the next person.
   - Downloads (CSV) still download. Leaving the admin (sign-in page) loads normally.
   - Opt out with <form data-no-smooth>. "Are you sure?" boxes (ui.js) call window.hplSmoothSubmit after "Yes".
   - Inline scripts of the new body run again; pages that need to re-bind can listen for "hpl:swapped". */
(function () {
    'use strict';
    if (window.hplSmoothSubmit || !window.fetch || !window.DOMParser || !window.FormData) return;

    function adminUrl(u) {
        try { var a = new URL(u, location.href); return a.origin === location.origin && /\/admin\//.test(a.pathname) ? a : null; }
        catch (e) { return null; }
    }
    function usable(form) {
        if (!form || form.nodeName !== 'FORM' || form.hasAttribute('data-no-smooth')) return false;
        if (form.target && form.target !== '_self') return false;
        return !!adminUrl(form.getAttribute('action') || location.href);
    }
    function toast(text, type) {
        if (!text) return;
        if (window.hplToast) { try { window.hplToast(text, type); return; } catch (e) {} }
    }
    function runScripts(root) {
        root.querySelectorAll('script').forEach(function (old) {
            if (old.type && old.type !== 'text/javascript' && old.type !== 'module') return; // JSON data blocks stay as they are
            var s = document.createElement('script');
            for (var i = 0; i < old.attributes.length; i++) s.setAttribute(old.attributes[i].name, old.attributes[i].value);
            s.text = old.text;
            old.parentNode.replaceChild(s, old);
        });
    }
    function swap(html, url, keepScroll) {
        var doc = new DOMParser().parseFromString(html, 'text/html');
        var next = doc.querySelector('.app__content'), here = document.querySelector('.app__content');
        if (!next || !here) { location.href = url; return false; }
        // Stylesheets the new page needs that this one does not have yet.
        doc.querySelectorAll('head link[rel="stylesheet"]').forEach(function (l) {
            var href = l.getAttribute('href');
            if (href && !document.querySelector('head link[rel="stylesheet"][href="' + href.replace(/"/g, '\\"') + '"]')) document.head.appendChild(l.cloneNode(true));
        });
        var y = window.scrollY;
        here.innerHTML = next.innerHTML;
        ['.sidebar-nav'].forEach(function (sel) {
            var a = document.querySelector(sel), b = doc.querySelector(sel);
            if (a && b) a.innerHTML = b.innerHTML;
        });
        if (doc.title) document.title = doc.title;
        runScripts(here);
        // The result message is shown where the person is looking, not only at the top.
        var flashes = here.querySelectorAll('.flash');
        flashes.forEach(function (f) {
            var t = f.classList.contains('flash--error') ? 'error' : (f.classList.contains('flash--success') ? 'ok' : 'info');
            toast(f.textContent.trim(), t);
        });
        window.scrollTo(0, keepScroll ? y : 0);
        document.dispatchEvent(new CustomEvent('hpl:swapped', { detail: { url: url } }));
        return true;
    }
    function download(res) {
        var cd = res.headers.get('content-disposition') || '';
        var m = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(cd);
        return res.blob().then(function (b) {
            var a = document.createElement('a');
            a.href = URL.createObjectURL(b); a.download = m ? decodeURIComponent(m[1]) : 'download';
            document.body.appendChild(a); a.click();
            setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 2000);
        });
    }

    window.hplSmoothSubmit = function (form, submitter) {
        var method = (form.getAttribute('method') || 'get').toLowerCase();
        var fd = new FormData(form);
        if (submitter && submitter.name && !fd.has(submitter.name)) fd.append(submitter.name, submitter.value || '');
        var target = adminUrl(form.getAttribute('action') || location.href);
        if (!target) { HTMLFormElement.prototype.submit.call(form); return Promise.resolve(); }
        var init = { method: method === 'post' ? 'POST' : 'GET', credentials: 'same-origin', headers: { 'X-HPL-Smooth': '1' } };
        if (method === 'post') init.body = fd;
        else { target.search = new URLSearchParams(fd).toString(); }
        var buttons = form.querySelectorAll('button, input[type="submit"]');
        buttons.forEach(function (b) { b.disabled = true; });
        document.documentElement.classList.add('hpl-saving');
        function restore() {
            document.documentElement.classList.remove('hpl-saving');
            form.__hplBusy = false;
            buttons.forEach(function (b) { b.disabled = false; if (b.__hplLabel) b.textContent = b.__hplLabel; });
        }
        return fetch(target.toString(), init).then(function (res) {
            var ct = res.headers.get('content-type') || '';
            if (/attachment/i.test(res.headers.get('content-disposition') || '') || (ct && !/text\/html/i.test(ct))) {
                return download(res).then(restore);
            }
            return res.text().then(function (html) {
                var fin = res.url || target.toString();
                if (!adminUrl(fin)) { location.href = fin; return; }
                var samePage = new URL(fin).pathname === location.pathname;
                var keep = method === 'post' && samePage;
                if (swap(html, fin, keep)) {
                    if (fin !== location.href) history[method === 'get' || !samePage ? 'pushState' : 'replaceState']({ hplSmooth: 1 }, '', fin);
                }
                restore();
            });
        }).catch(function () {
            restore();
            HTMLFormElement.prototype.submit.call(form); // network trouble: fall back to the normal way
        });
    };

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (e.defaultPrevented || !usable(form)) return;
        e.preventDefault();
        window.hplSmoothSubmit(form, e.submitter || null);
    });
    // Back/forward after a background change: load that page for real.
    window.addEventListener('popstate', function () { location.reload(); });
})();
