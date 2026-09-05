// Minimal service worker — its only real job is satisfying the browser's
// PWA installability requirement (a registered SW with a fetch handler).
// English OS is a server-rendered Livewire app; there is no meaningful
// offline experience to build here (every step needs a live round-trip
// for AI checks, evidence saves, etc.), so this deliberately does NOT
// implement an offline-first cache strategy. It only caches the small set
// of static, rarely-changing assets below, and otherwise passes every
// request straight to the network — never serves a stale cached page.
const CACHE_NAME = 'englishos-shell-v1';
const SHELL_ASSETS = [
    '/favicon.svg',
    '/favicon.ico',
    '/icon-192.png',
    '/icon-512.png',
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
