// Booking manager service worker: shows push notifications and opens the right booking when tapped.
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = { body: e.data ? e.data.text() : '' }; }
  const jobs = [self.registration.showNotification(d.title || 'Perfect Beauty', {
    body: d.body || 'You have a new booking update.',
    icon: '/admin/icon-192.png',
    badge: '/admin/icon-192.png',
    tag: d.tag || undefined,
    data: { url: d.url || '/admin/' },
  })];
  if (typeof d.badge === 'number' && self.navigator.setAppBadge) {
    jobs.push((d.badge ? self.navigator.setAppBadge(d.badge) : self.navigator.clearAppBadge()).catch(() => {}));
  }
  e.waitUntil(Promise.all(jobs));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = (e.notification.data && e.notification.data.url) || '/admin/';
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(wins => {
    for (const w of wins) {
      if (w.url.includes('/admin/')) { w.postMessage({ open: url }); return w.focus(); }
    }
    return self.clients.openWindow(url);
  }));
});
