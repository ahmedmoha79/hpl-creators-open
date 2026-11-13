/* Front page motion (owner, 2026-11-12/13: "I have to see motion", "make it flawless, easy to read, nice animation").
   Everything is readable without this file. With it: the headline rises in word by word, real payouts rotate in the hero,
   gold dust drifts, sections rise in as they scroll into view, numbers pop, money counts up, the photo row moves by itself
   until touched, and the "How to start" line draws itself. Reduced-motion phones get the still page. */
(function () {
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var io = 'IntersectionObserver' in window;

    // Pictures further down load only when they come near the screen (slow phone data: the first screen comes first).
    function fill(el) {
        if (el.dataset.lpSrc) { el.src = el.dataset.lpSrc; el.removeAttribute('data-lp-src'); }
        if (el.dataset.poster) { el.poster = el.dataset.poster; el.removeAttribute('data-poster'); }
    }
    var later = document.querySelectorAll('img[data-lp-src], .lp-strip video[data-poster]');
    if (io) {
        var near = new IntersectionObserver(function (es) {
            es.forEach(function (en) {
                if (!en.isIntersecting) return;
                near.unobserve(en.target);
                en.target.closest('.lp-strip').querySelectorAll('img[data-lp-src], video[data-poster]').forEach(fill);
            });
        }, { rootMargin: '300px 0px' });
        later.forEach(function (el) { near.observe(el); });
    } else later.forEach(fill);
    document.querySelectorAll('.lp-video').forEach(function (d) {
        d.addEventListener('toggle', function () { if (d.open) d.querySelectorAll('video[data-poster]').forEach(fill); });
    });

    // The field video: load and play only when seen, pause when gone (saves data).
    document.querySelectorAll('video[data-lp-play]').forEach(function (v) {
        if (!io) { v.src = v.getAttribute('data-src'); return; }
        new IntersectionObserver(function (es) {
            es.forEach(function (en) {
                if (en.isIntersecting) {
                    if (!v.src) v.src = v.getAttribute('data-src');
                    var p = v.play(); if (p && p.catch) p.catch(function () {});
                } else if (!v.paused) v.pause();
            });
        }, { threshold: 0.4 }).observe(v);
    });

    // Real payouts rotating under the hero button.
    var live = document.querySelectorAll('.lp-live__item');
    if (live.length > 1) {
        var li = 0;
        setInterval(function () {
            if (document.hidden) return;
            var cur = live[li]; li = (li + 1) % live.length; var nx = live[li];
            cur.classList.remove('is-on'); cur.classList.add('is-out');
            nx.classList.remove('is-out'); nx.classList.add('is-on');
            setTimeout(function () { cur.classList.remove('is-out'); }, 500);
        }, reduce ? 4000 : 2200); // owner, 2026-11-13: a new real figure every ~2 seconds, sliding in from the side
    }

    // Real money pop-ups (owner, 2026-11-13): the usual corner notice: slides up, stays 4.5 s, goes, next one 2 s later.
    // Waits 3 s after the page opens; the x stops them for this visit.
    var toast = document.getElementById('lpToast'), data = [];
    try { data = JSON.parse((document.getElementById('lpToasts') || {}).textContent || '[]'); } catch (e) {}
    if (toast && data.length) {
        var ti = 0, stopped = false, timer = null;
        try { stopped = sessionStorage.getItem('lpToastOff') === '1'; } catch (e) {}
        var who = document.getElementById('lpToastWho'), what = document.getElementById('lpToastWhat'), when = document.getElementById('lpToastWhen');
        function place(t) { return t && !/^(other|outside)/i.test(t) ? ' from ' + t : ''; }
        function showNext() {
            if (stopped) return;
            if (document.hidden) { timer = setTimeout(showNext, 2000); return; }
            var d = data[ti % data.length]; ti++;
            who.textContent = d.n + place(d.p);
            what.innerHTML = '';
            what.appendChild(document.createTextNode(d.k === 'paid' ? 'was paid ' : 'earned '));
            var b = document.createElement('b'); b.textContent = d.a; what.appendChild(b);
            if (d.k !== 'paid') what.appendChild(document.createTextNode(d.k === 'earned' ? ' from views' : ' from a clip'));
            when.textContent = (d.w ? d.w.charAt(0).toUpperCase() + d.w.slice(1) + ' \u00b7 ' : '') + (d.k === 'paid' ? 'Mobile money \u00b7 ' : '') + 'Verified by HPL';
            toast.hidden = false;
            requestAnimationFrame(function () { toast.classList.add('is-on'); });
            timer = setTimeout(function () {
                toast.classList.remove('is-on');
                timer = setTimeout(showNext, 2400);
            }, 4500);
        }
        document.getElementById('lpToastX').addEventListener('click', function () {
            stopped = true; clearTimeout(timer); toast.classList.remove('is-on');
            try { sessionStorage.setItem('lpToastOff', '1'); } catch (e) {}
        });
        timer = setTimeout(showNext, 3000);
    }

    if (reduce || !io) return;
    var root = document.documentElement;
    root.classList.add('lp-anim');

    // Headline: each word rises in, one after the other (the coloured part keeps its colour).
    var h1 = document.querySelector('.lp-hero h1'), wi = 0;
    function split(node) {
        Array.prototype.slice.call(node.childNodes).forEach(function (n) {
            if (n.nodeType === 3) {
                var frag = document.createDocumentFragment();
                n.textContent.split(/(\s+)/).forEach(function (w) {
                    if (w === '') return;
                    if (/^\s+$/.test(w)) { frag.appendChild(document.createTextNode(w)); return; }
                    var s = document.createElement('span'); s.className = 'lp-w'; s.textContent = w;
                    s.style.setProperty('--i', wi++); frag.appendChild(s);
                });
                n.parentNode.replaceChild(frag, n);
            } else if (n.nodeType === 1) split(n);
        });
    }
    if (h1) { h1.setAttribute('aria-label', h1.textContent); split(h1); root.style.setProperty('--lp-words', wi); }

    // Rise in, one after the other inside each group.
    var groups = ['.lp-photosteps > li', '.lp-paid li', '.lp-paid h2', '.lp-kind', '.lp-camp', '.lp-expect > li', '.lp-steps > li',
        '.lp-faq', '.lp-section h2', '.lp-field h2', '.lp-intro', '.lp-end h2', '.lp-end .lp-btn', '.lp-video'];
    var items = [];
    groups.forEach(function (sel) {
        document.querySelectorAll(sel).forEach(function (el, i) {
            el.classList.add('lp-rise'); el.style.setProperty('--d', (Math.min(i, 6) * 110) + 'ms'); items.push(el);
        });
    });
    document.querySelectorAll('.lp-steps, .lp-expect').forEach(function (el) { items.push(el); });
    var seen = new IntersectionObserver(function (es) {
        es.forEach(function (en) {
            if (!en.isIntersecting) return;
            en.target.classList.add('is-in'); seen.unobserve(en.target);
            en.target.querySelectorAll('[data-countup]').forEach(function (el) {
                setTimeout(function () { countUp(el); }, parseInt(getComputedStyle(en.target).getPropertyValue('--d'), 10) || 0);
            });
        });
    }, { rootMargin: '0px 0px -10% 0px', threshold: 0.15 });
    items.forEach(function (el) { seen.observe(el); });

    // Money counts up from 0 to the real amount (keeps the K and the decimals).
    function countUp(el) {
        var to = parseFloat(el.getAttribute('data-countup')); if (!(to > 0)) return;
        var txt = el.textContent, pre = (txt.match(/^[^0-9]*/) || [''])[0], t0 = null, ms = 1100;
        function step(t) {
            if (t0 === null) t0 = t;
            var k = Math.min(1, (t - t0) / ms), v = to * (1 - Math.pow(1 - k, 3));
            el.textContent = pre + v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            if (k < 1) requestAnimationFrame(step); else el.textContent = txt;
        }
        requestAnimationFrame(step);
    }
    document.querySelectorAll('.lp-paid li b').forEach(function (b) {
        var n = parseFloat(b.textContent.replace(/[^0-9.]/g, '')); if (n > 0) b.setAttribute('data-countup', n);
    });

    // The photo row moves to the next picture every few seconds while it is on screen, until the person touches it.
    var strip = document.querySelector('.lp-strip');
    if (strip && strip.scrollWidth > strip.clientWidth + 20) {
        var figs = strip.querySelectorAll('figure'), fi = 0, onScreen = false, stopped = false;
        ['touchstart', 'pointerdown', 'wheel'].forEach(function (ev) {
            strip.addEventListener(ev, function () { stopped = true; }, { passive: true });
        });
        new IntersectionObserver(function (es) { onScreen = es[0].isIntersecting; }, { threshold: 0.5 }).observe(strip);
        setInterval(function () {
            if (stopped || !onScreen || document.hidden) return;
            fi = (fi + 1) % figs.length;
            var f = figs[fi];
            strip.scrollTo({ left: fi === 0 ? 0 : f.offsetLeft - (strip.clientWidth - f.offsetWidth) / 2, behavior: 'smooth' });
        }, 2600);
    }

    // The arrow at the bottom of the hero fades once the page is scrolled.
    var down = document.querySelector('.lp-down');
    if (down) window.addEventListener('scroll', function () { down.classList.toggle('is-gone', window.scrollY > 60); }, { passive: true });
})();
