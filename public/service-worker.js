const CACHE_NAME = 'stelfaro-static-v1';
const CORE_ASSETS = [
    '/manifest.webmanifest',
    '/pwa/stelfaro.svg',
    '/pwa/stelfaro-192.png',
    '/pwa/stelfaro-512.png',
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
