(function () {
    "use strict";

    // Promo video overlay play button
    var overlay = document.getElementById("promoOverlay");
    var video = document.getElementById("promoVideo");
    if (overlay && video) {
        overlay.addEventListener("click", function () {
            overlay.classList.add("is-hidden");
            video.play();
        });
        video.addEventListener("play", function () {
            overlay.classList.add("is-hidden");
        });
        video.addEventListener("ended", function () {
            overlay.classList.remove("is-hidden");
        });
    }

    // Sidebar toggle on mobile (creator/admin)
    var sidebar = document.querySelector(".app__sidebar");
    var sidebarOverlay = document.querySelector(".sidebar-overlay");
    if (sidebar && !sidebarOverlay) {
        sidebarOverlay = document.createElement("div");
        sidebarOverlay.className = "sidebar-overlay";
        sidebar.parentNode.insertBefore(sidebarOverlay, sidebar.nextSibling);
    }
    function closeSidebar() {
        if (sidebar) sidebar.classList.remove("is-open");
        if (sidebarOverlay) sidebarOverlay.classList.remove("is-active");
        document.body.classList.remove("sidebar-locked");
    }
    function openSidebar() {
        if (sidebar) sidebar.classList.add("is-open");
        if (sidebarOverlay) sidebarOverlay.classList.add("is-active");
        document.body.classList.add("sidebar-locked");
    }
    document.querySelectorAll("[data-sidebar-toggle]").forEach(function (btn) {
        btn.addEventListener("click", function () {
            if (sidebar && sidebar.classList.contains("is-open")) closeSidebar();
            else openSidebar();
        });
    });
    if (sidebarOverlay) sidebarOverlay.addEventListener("click", closeSidebar);
    // Close the mobile drawer when any sidebar link/tab is tapped.
    if (sidebar) {
        sidebar.addEventListener('click', function (e) {
            var t = e.target;
            while (t && t !== sidebar) {
                if (t.tagName === 'A' || t.classList.contains('sidebar-tab') || t.classList.contains('sidebar-close')) break;
                t = t.parentNode;
            }
            if (t && t !== sidebar && (t.tagName === 'A' || t.classList.contains('sidebar-tab'))) {
                closeSidebar();
            }
        }, true);
    }
    window.addEventListener("resize", function () { if (window.innerWidth > 900) closeSidebar(); });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") closeSidebar();
    });

    // Copy to clipboard helpers (one listener for the whole page, so it also works after the admin swaps a page in place)
    document.addEventListener("click", function (e) {
        var el = e.target.closest ? e.target.closest("[data-copy]") : null;
        if (!el || el.__hplCopyOwn) return;
        var text = el.getAttribute("data-copy");
        if (!text || !navigator.clipboard) return;
        navigator.clipboard.writeText(text).then(function () {
            if (el.children.length === 0) { var old = el.textContent; el.textContent = "Copied!"; setTimeout(function () { el.textContent = old; }, 1200); }
            if (window.hplToast) window.hplToast("Copied", "ok");
        });
    });

    // Confirmations for [data-confirm] are handled by assets/js/ui.js (in-app dialog, no browser pop-up).

    // Tutorial page: playlist controls the video player
    var tutVideo = document.getElementById("tutVideo");
    var tutItems = document.querySelectorAll(".tutorial-list li");
    if (tutVideo && tutItems.length) {
        // If the first playable clip is not already active, activate it now.
        var firstPlayable = null;
        tutItems.forEach(function (item, index) {
            if (!firstPlayable && !item.classList.contains("is-locked")) {
                firstPlayable = item;
                if (!document.querySelector(".tutorial-list li.is-active")) {
                    item.classList.add("is-active");
                }
            }
        });
        if (firstPlayable) {
            var initial = firstPlayable.getAttribute("data-src");
            if (initial && tutVideo && !tutVideo.getAttribute("src")) {
                tutVideo.src = initial;
            }
        }
        tutItems.forEach(function (item, index) {
            item.addEventListener("click", function () {
                if (item.classList.contains("is-locked")) return;
                tutItems.forEach(function (i) { i.classList.remove("is-active"); });
                item.classList.add("is-active");
                var src = item.getAttribute("data-src");
                if (src && tutVideo) {
                    tutVideo.src = src;
                    tutVideo.play();
                }
            });
            item.addEventListener("keydown", function (e) {
                if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault();
                    item.click();
                }
            });
        });
    }

    // Live "words remaining" counter for textareas with data-max-words
    function wordCounters() {
        document.querySelectorAll("[data-max-words]").forEach(function (area) {
            if (area.__hplWords) return; area.__hplWords = true;
            var max = parseInt(area.getAttribute("data-max-words"), 10);
            var out = document.querySelector(area.getAttribute("data-counter"));
            function update() {
                var words = area.value.trim().split(/\s+/).filter(Boolean).length;
                if (out) out.textContent = (max - words) + " words left";
            }
            area.addEventListener("input", update);
            update();
        });
    }
    wordCounters();
    document.addEventListener("hpl:swapped", wordCounters);
})();
