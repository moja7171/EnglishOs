// Minimal service worker — its jobs are satisfying the browser's PWA
// installability requirement (a registered SW with a fetch handler) and
// showing phone alerts (push events, below).
// English OS is a server-rendered Livewire app; there is no meaningful
// offline experience to build here (every step needs a live round-trip
// for AI checks, evidence saves, etc.), so this deliberately does NOT
// implement an offline-first cache strategy. It only caches the small set
// of static, rarely-changing assets below, and otherwise passes every
// request straight to the network — never serves a stale cached page.
const CACHE_NAME = 'englishos-shell-v2';
const SHELL_ASSETS = [
    '/favicon.svg',
    '/favicon.ico',
    '/icon-192.png',
    '/icon-512.png',
    '/icon-maskable-512.png',
    '/badge-96.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((names) => Promise.all(
            names.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))
        ))
    );
    self.clients.claim();
});

// Phone alerts (Web Push). The server sends the same text the in-app bell
// shows; `data.url` is where tapping the alert should land.
self.addEventListener('push', (event) => {
    let payload = {};

    try {
        payload = event.data ? event.data.json() : {};
    } catch (error) {
        payload = { body: event.data ? event.data.text() : '' };
    }

    const options = {
        body: payload.body,
        icon: payload.icon || '/icon-192.png',
        // Without a badge Android shows the browser's own logo in the status bar.
        badge: payload.badge || '/badge-96.png',
        tag: payload.tag,
        renotify: Boolean(payload.tag) && Boolean(payload.renotify),
        data: payload.data || {},
    };

    event.waitUntil(self.registration.showNotification(payload.title || 'English OS', options));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    // Only ever open a page on our own origin, whatever the payload says.
    const requested = new URL((event.notification.data && event.notification.data.url) || '/', self.location.origin);
    const target = requested.origin === self.location.origin ? requested.href : self.location.origin + '/';

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((client) => 'focus' in client);

            if (open) {
                return open.focus().then((client) => ('navigate' in client ? client.navigate(target) : client));
            }

            return self.clients.openWindow(target);
        })
    );
});

self.addEventListener('fetch', (event) => {
    const url = new URL(event.request.url);
    const isShellAsset = event.request.method === 'GET' && SHELL_ASSETS.includes(url.pathname);

    if (!isShellAsset) {
        return; // let the browser handle everything else normally
    }

    event.respondWith(
        caches.match(event.request).then((cached) => cached || fetch(event.request))
    );
});
