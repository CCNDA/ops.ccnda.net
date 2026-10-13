// 戰情室 Service Worker：只處理網頁推播（不做離線快取，頁面一律走網路）。參考 it.ccra.tw site/sw.js。
// 放在根目錄才能管到整個網站；Cloudflare Access 已對 /sw.js 開免登入（媽媽 10/13），瀏覽器更新它時不會被轉去登入頁。
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(self.clients.claim()));

self.addEventListener('push', e => {
  let m = {};
  try { m = e.data ? e.data.json() : {}; } catch (_) { m = { body: e.data ? e.data.text() : '' }; }
  const opt = {
    body: m.body || '',
    icon: '/assets/icon-192.png',
    badge: '/assets/icon-192.png',
    data: { url: m.url || '/' },
  };
  if (m.tag) { opt.tag = m.tag; opt.renotify = true; }
  e.waitUntil(self.registration.showNotification(m.title || '戰情室', opt));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = new URL((e.notification.data && e.notification.data.url) || '/', self.location.origin).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(ws => {
    for (const w of ws) if (w.url.startsWith(url) && 'focus' in w) return w.focus();
    return self.clients.openWindow(url);
  }));
});
