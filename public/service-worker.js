'use strict';

var CACHE_NAME = 'midway-shell-v23';
var SCOPE_URL = self.registration.scope;
var SHELL_PATHS = [
    'manifest.webmanifest',
    'display/app.css?v=21',
    'display/app.js?v=16',
    'display/atmosphere.css?v=1',
    'display/atmosphere.js?v=1',
    'display/pwa.js?v=6',
    'display/themes/october-fall.css?v=2',
    'icons/mapbox-logo.svg',
    'vendor/leaflet/leaflet.css?v=2',
    'vendor/leaflet/leaflet.js?v=2',
    'icons/midway-weather.svg',
    'icons/midway-weather-192.png',
    'icons/midway-weather-512.png',
    'icons/midway-weather-maskable-512.png',
    'icons/apple-touch-icon.png',
    'icons/favicon-32.png'
];
var SHELL_URLS = SHELL_PATHS.map(function (path) {
    return new URL(path, SCOPE_URL).href;
});

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(function (cache) {
                // Cache entries independently. A transient failure or a stale
                // negative HTTP-cache entry must not strand the new worker in
                // its installing state and leave an older worker controlling
                // the dashboard indefinitely.
                return Promise.all(SHELL_URLS.map(function (url) {
                    return cache.add(url).catch(function () {
                        return false;
                    });
                }));
            })
            .then(function () {
                return self.skipWaiting();
            })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys()
            .then(function (cacheNames) {
                return Promise.all(cacheNames.map(function (cacheName) {
                    if (cacheName !== CACHE_NAME && cacheName.indexOf('midway-shell-') === 0) {
                        return caches.delete(cacheName);
                    }

                    return Promise.resolve(false);
                }));
            })
            .then(function () {
                return self.clients.claim();
            })
    );
});

self.addEventListener('fetch', function (event) {
    var request = event.request;

    if (request.method !== 'GET') return;

    var requestUrl = new URL(request.url);

    // Cross-origin map/radar tiles and metadata always stay on the network.
    if (requestUrl.origin !== self.location.origin) return;

    // Navigations and JSON/API requests are deliberately never cached. The
    // dashboard must not present an old weather snapshot as current data.
    if (request.mode === 'navigate'
        || requestUrl.pathname.indexOf('/api/') !== -1
        || (request.headers.get('accept') || '').indexOf('application/json') !== -1) {
        return;
    }

    if (SHELL_URLS.indexOf(request.url) === -1) return;

    // Network-first keeps deployed CSS/JS fresh; the versioned cache provides
    // a resilient fallback for the stable local shell assets only.
    event.respondWith(
        fetch(request)
            .then(function (response) {
                if (response.ok) {
                    var copy = response.clone();
                    caches.open(CACHE_NAME).then(function (cache) {
                        cache.put(request, copy);
                    });
                }

                return response;
            })
            .catch(function () {
                return caches.match(request);
            })
    );
});
