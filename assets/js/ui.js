/* HPL Creators: in-app messages and confirmations (2026-10-27).
 * Replaces the browser's own pop-ups ("golddetectorinzambia.com says ...").
 *   hplToast('Saved', 'ok')                      short message at the bottom of the screen
 *   hplConfirm('Delete this?').then(function(yes){ ... })
 *   <form data-confirm="Delete this post?">       asks before submitting
 *   <a|button data-confirm="...">                 asks before following / clicking
 */
(function () {
    "use strict";
    if (window.hplToast) return;

    var css = ''
        + '.hpl-toasts{position:fixed;left:50%;bottom:calc(88px + env(safe-area-inset-bottom));transform:translateX(-50%);z-index:5000;display:flex;flex-direction:column;gap:8px;width:min(440px,calc(100vw - 24px));pointer-events:none}'
        + '@media(min-width:901px){.hpl-toasts{bottom:24px}}'
        + '.hpl-toast{pointer-events:auto;display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:12px;background:#1e140f;color:#f6f0e6;font:500 14px/1.45 Inter,system-ui,sans-serif;box-shadow:0 12px 32px rgba(30,20,15,.28);opacity:0;transform:translateY(8px);transition:opacity .18s ease,transform .18s ease}'
        + '.hpl-toast.is-in{opacity:1;transform:none}'
        + '.hpl-toast__dot{width:8px;height:8px;border-radius:50%;margin-top:6px;flex:none;background:#e2ad7b}'
        + '.hpl-toast--ok .hpl-toast__dot{background:#4ade80}.hpl-toast--error .hpl-toast__dot{background:#f87171}'
        + '.hpl-toast__msg{flex:1;min-width:0;overflow-wrap:anywhere}'
        + '.hpl-toast__x{border:0;background:none;color:#cdb9a3;font-size:18px;line-height:1;cursor:pointer;padding:0 2px}'
        + '.hpl-modal{position:fixed;inset:0;z-index:5001;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(30,20,15,.45);opacity:0;transition:opacity .15s ease}'
        + '.hpl-modal.is-in{opacity:1}'
        + '.hpl-modal__box{width:min(400px,100%);background:#fff;border-radius:16px;padding:22px;box-shadow:0 24px 60px rgba(30,20,15,.3);font:15px/1.5 Inter,system-ui,sans-serif;color:#1f1510}'
        + '.hpl-modal__title{font-weight:700;font-size:17px;margin:0 0 6px}'
        + '.hpl-modal__text{margin:0 0 18px;color:#4a3a2e}'
        + '.hpl-modal__row{display:flex;gap:8px;justify-content:flex-end}'
        + '.hpl-modal__btn{height:40px;padding:0 16px;border-radius:10px;font:600 14px Inter,system-ui,sans-serif;cursor:pointer;border:1px solid #dccdb9;background:#fff;color:#1f1510}'
        + '.hpl-modal__btn--go{background:#1e140f;border-color:#1e140f;color:#fff6ea}'
        + '.hpl-modal__btn--danger{background:#b42318;border-color:#b42318;color:#fff}';
    var style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    var stack = null;
    function toast(msg, type) {
        if (!document.body) { document.addEventListener('DOMContentLoaded', function () { toast(msg, type); }); return; }
        if (!stack) {
            stack = document.createElement('div');
            stack.className = 'hpl-toasts';
            stack.setAttribute('role', 'status');
            stack.setAttribute('aria-live', 'polite');
            document.body.appendChild(stack);
        }
        var t = document.createElement('div');
        t.className = 'hpl-toast hpl-toast--' + (type || 'info');
        t.innerHTML = '<span class="hpl-toast__dot"></span><span class="hpl-toast__msg"></span><button type="button" class="hpl-toast__x" aria-label="Close">&times;</button>';
        t.querySelector('.hpl-toast__msg').textContent = String(msg == null ? '' : msg);
        function close() { t.classList.remove('is-in'); setTimeout(function () { t.remove(); }, 200); }
        t.querySelector('.hpl-toast__x').addEventListener('click', close);
        stack.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('is-in'); });
        setTimeout(close, type === 'error' ? 6000 : 3500);
    }

    function confirmBox(msg, opts) {
        opts = opts || {};
        return new Promise(function (resolve) {
            var m = document.createElement('div');
            m.className = 'hpl-modal';
            m.innerHTML = '<div class="hpl-modal__box" role="alertdialog" aria-modal="true" aria-labelledby="hplModalTitle">'
                + '<p class="hpl-modal__title" id="hplModalTitle"></p><p class="hpl-modal__text"></p>'
                + '<div class="hpl-modal__row"><button type="button" class="hpl-modal__btn" data-no>Cancel</button>'
                + '<button type="button" class="hpl-modal__btn ' + (opts.danger === false ? 'hpl-modal__btn--go' : 'hpl-modal__btn--danger') + '" data-yes></button></div></div>';
            var text = String(msg || 'Are you sure?');
            m.querySelector('.hpl-modal__title').textContent = opts.title || (/delete|remove/i.test(text) ? 'Please confirm' : 'Are you sure?');
            m.querySelector('.hpl-modal__text').textContent = text;
            m.querySelector('[data-yes]').textContent = opts.ok || (/delete/i.test(text) ? 'Delete' : (/remove/i.test(text) ? 'Remove' : 'Continue'));
            var last = document.activeElement;
            function done(v) {
                m.classList.remove('is-in');
                document.removeEventListener('keydown', key, true);
                setTimeout(function () { m.remove(); if (last && last.focus) last.focus(); }, 150);
                resolve(v);
            }
            function key(e) { if (e.key === 'Escape') { e.preventDefault(); done(false); } }
            m.querySelector('[data-no]').addEventListener('click', function () { done(false); });
            m.querySelector('[data-yes]').addEventListener('click', function () { done(true); });
            m.addEventListener('click', function (e) { if (e.target === m) done(false); });
            document.addEventListener('keydown', key, true);
            document.body.appendChild(m);
            requestAnimationFrame(function () { m.classList.add('is-in'); m.querySelector('[data-yes]').focus(); });
        });
    }

    window.hplToast = toast;
    window.hplConfirm = confirmBox;
    // Safety net: any leftover alert() shows as an in-app message instead of a browser pop-up.
    window.alert = function (msg) { toast(msg, 'info'); };

    // Forms that need confirmation.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.hasAttribute || !form.hasAttribute('data-confirm') || form.__hplOk) return;
        e.preventDefault();
        var submitter = e.submitter || null;
        confirmBox(form.getAttribute('data-confirm')).then(function (yes) {
            if (!yes) return;
            form.__hplOk = true;
            if (submitter && submitter.name) {
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = submitter.name; h.value = submitter.value;
                form.appendChild(h);
            }
            if (window.hplSmoothSubmit && !form.hasAttribute('data-no-smooth')) window.hplSmoothSubmit(form, submitter); // admin: no page jump
            else HTMLFormElement.prototype.submit.call(form);
        });
    }, true);

    // Buttons and links that need confirmation.
    document.addEventListener('click', function (e) {
        var el = e.target.closest ? e.target.closest('a[data-confirm], button[data-confirm], input[data-confirm]') : null;
        if (!el || el.__hplOk) return;
        e.preventDefault();
        e.stopPropagation();
        confirmBox(el.getAttribute('data-confirm')).then(function (yes) {
            if (!yes) return;
            el.__hplOk = true;
            if (el.tagName === 'A' && el.href) { window.location.href = el.href; return; }
            var form = el.form;
            if (form) {
                if (el.name) {
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = el.name; h.value = el.value;
                    form.appendChild(h);
                }
                form.__hplOk = true;
                if (window.hplSmoothSubmit && !form.hasAttribute('data-no-smooth')) window.hplSmoothSubmit(form, el);
                else HTMLFormElement.prototype.submit.call(form);
            } else {
                el.click();
                el.__hplOk = false;
            }
        });
    }, true);

    // One tap = one submission. A slow action (sync, payout, save) must not run twice
    // because someone tapped again while waiting. Runs after page scripts, so forms that
    // handle submit themselves (preventDefault) are left alone.
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || e.defaultPrevented || (form.method || '').toLowerCase() !== 'post') return;
        if (form.__hplBusy) { e.preventDefault(); return; }
        form.__hplBusy = true;
        var buttons = form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
        setTimeout(function () {
            buttons.forEach(function (b) {
                b.disabled = true;
                if (b.hasAttribute('data-busy')) { b.__hplLabel = b.textContent; b.textContent = b.getAttribute('data-busy'); }
            });
        }, 0);
        // If the server never answers, let them try again.
        setTimeout(function () {
            form.__hplBusy = false;
            buttons.forEach(function (b) { b.disabled = false; if (b.__hplLabel) b.textContent = b.__hplLabel; });
        }, 25000);
    });
    // Coming back with the browser's Back button: buttons work again.
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return;
        document.querySelectorAll('form').forEach(function (f) {
            f.__hplBusy = false;
            f.querySelectorAll('button, input[type="submit"]').forEach(function (b) { b.disabled = false; if (b.__hplLabel) b.textContent = b.__hplLabel; });
        });
    });
})();
