/* HPL Creators home-screen app (2026-11-05).
 * Only job: let the site be added to the home screen, and show a friendly page when there is no internet.
 * Pages are never stored, so nobody sees old earnings or someone else's data. */
const CACHE = 'hpl-shell-v1';
const SHELL = ['/offline.html', '/assets/img/app-192.png'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return; // everything else goes to the network as normal
  e.respondWith(fetch(e.request).catch(() => caches.match('/offline.html')));
});

/* Home-screen notifications (2026-11-10). The push itself is empty: we ask our own site what to show,
 * with the person's own sign-in, so no private text ever travels through Google or Apple. */
self.addEventListener('push', (e) => {
  const fallback = { title: 'HPL Creators', body: 'You have something new.', url: '/creator/index.php', tag: 'hpl-0' };
  e.waitUntil(
    fetch('/api/push.php?latest=1', { credentials: 'include', cache: 'no-store' })
      .then((r) => (r.ok ? r.json() : fallback))
      .catch(() => fallback)
      .then((d) => self.registration.showNotification(d.title || fallback.title, {
        body: d.body || '', tag: d.tag || 'hpl-0', renotify: true, silent: false, vibrate: [200, 100, 200],
        icon: '/assets/img/app-192.png', badge: '/assets/img/app-192.png', data: { url: d.url || fallback.url },
      }))
  );
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/creator/index.php';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) { if ('focus' in c) { c.navigate(url).catch(() => {}); return c.focus(); } }
    return self.clients.openWindow(url);
  }));
});
