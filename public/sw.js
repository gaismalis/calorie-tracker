// Service worker: lets browsers install the app, and shows /offline.html when a page can't load at
// all (no connection). Nothing else is cached, so the app is never shown out of date.
// Bump VERSION when offline.html or the icon changes.
const VERSION = 'v1';
const CACHE = `calorie-tracker-${VERSION}`;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png'])));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return; // form posts go straight to the server

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE_URL)));
    } else if (new URL(request.url).pathname === '/icons/icon-192.png') {
        event.respondWith(fetch(request).catch(() => caches.match(request))); // the offline page's icon
    }
});
