const CACHE_NAME = 'stelfaro-static-v8';
const CORE_ASSETS = [
    '/manifest.json',
    '/offline.html',
    '/pwa/stelfaro-app-icon-v2.svg',
    '/pwa/stelfaro-logo-horizontal.svg',
    '/pwa/stelfaro-logo-horizontal-compact.svg',
    '/pwa/stelfaro-logo-monochrome.svg',
    '/pwa/stelfaro-wordmark-official.svg',
    '/pwa/stelfaro-mark-on-light.svg',
    '/pwa/stelfaro-mark-on-dark.svg',
    '/pwa/stelfaro-app-icon-v2-192.png',
    '/pwa/stelfaro-app-icon-v2-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request).catch(() => caches.match('/offline.html')),
        );
        return;
    }

    const isStaticAsset = url.pathname.startsWith('/build/')
        || url.pathname.startsWith('/pwa/');

    if (!isStaticAsset) return;

    event.respondWith(
        caches.match(request).then((cached) => {
            if (cached) return cached;

            return fetch(request).then((response) => {
                if (!response.ok || response.type !== 'basic') return response;
                const copy = response.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
                return response;
            });
        }),
    );
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});
