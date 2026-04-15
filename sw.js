// PChat Service Worker v7
const CACHE = 'pchat-v7';
// Update these paths to match your actual file locations
const OFFLINE_URLS = ['index.html', 'login.html'];
// Note: CSS/JS paths added dynamically to avoid install failures

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(OFFLINE_URLS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE).map(k => caches.delete(k)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', e => {
  const url = e.request.url;
  // Skip caching for API requests (PHP files) and POST requests
  if (e.request.method !== 'GET' || url.includes('.php')) {
    e.respondWith(fetch(e.request).catch(() => new Response(
      JSON.stringify({success: false, message: 'You are offline.'}),
      {status: 503, headers: {'Content-Type': 'application/json'}}
    )));
    return;
  }
  // Network first, cache fallback for static assets
  e.respondWith(
    fetch(e.request)
      .then(res => {
        // Cache successful GET responses for static files
        if (res.ok && (url.includes('.html') || url.includes('.css') || url.includes('.js'))) {
          const clone = res.clone();
          caches.open(CACHE).then(c => c.put(e.request, clone));
        }
        return res;
      })
      .catch(() => caches.match(e.request).then(r => r || new Response('Offline', { status: 503 })))
  );
});

// ── BACKGROUND PUSH NOTIFICATIONS ──
// Fires even when the website tab is CLOSED (as long as browser is open)
self.addEventListener('push', e => {
  let data = {};
  try { data = e.data ? e.data.json() : {}; } catch {}

  const title = data.title || 'PChat 💬';
  const options = {
    body: data.body || 'You have a new message',
    icon: data.icon || '/favicon.ico',
    badge: data.badge || '/favicon.ico',
    tag: data.tag || 'pchat-msg',
    renotify: true,
    requireInteraction: false,
    vibrate: [200, 100, 200],
    data: { url: data.url || '/', sender: data.sender || '' },
    actions: [
      { action: 'open', title: '💬 Open' },
      { action: 'dismiss', title: '✖ Dismiss' }
    ]
  };

  e.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  if (e.action === 'dismiss') return;

  const targetUrl = (e.notification.data && e.notification.data.url) || '/';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(cs => {
      const existing = cs.find(w => w.url.includes('index.html') || w.url.endsWith('/'));
      if (existing) { existing.focus(); return; }
      return clients.openWindow(targetUrl);
    })
  );
});