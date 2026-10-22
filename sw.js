/* FOMAXO Store System - offline support. Keeps the app itself on the device so it opens without internet.
   Data requests (api.php) always go to the server; the app handles offline sales itself. */
var VERSION = 'fomaxo-pos-v12';
var SHELL = [
  './', 'index.html', 'assets/app.css?v=12', 'assets/app.js?v=12', 'assets/qrcode.js?v=12',
  'manifest.webmanifest', 'assets/icon-192.png', 'assets/icon-512.png', 'assets/apple-touch-icon.png', 'assets/favicon-32.png'
];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSION; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin) return;
  var path = url.pathname;
  // Live data and server pages: never cached.
  if (/\.php$/.test(path) || path.indexOf('/data/') >= 0) return;

  if (req.mode === 'navigate') {
    // Newest app when online, saved copy when offline.
    e.respondWith(fetch(req, { cache: 'no-cache' }).then(function (res) {
      var copy = res.clone();
      caches.open(VERSION).then(function (c) { c.put('index.html', copy); });
      return res;
    }).catch(function () { return caches.match('index.html'); }));
    return;
  }

  // App files: always try the newest copy first (so updates show at once), saved copy when offline.
  e.respondWith(fetch(req, { cache: 'no-cache' }).then(function (res) {
    if (res.ok) { var copy = res.clone(); caches.open(VERSION).then(function (c) { c.put(req, copy); }); }
    return res;
  }).catch(function () {
    return caches.match(req, { ignoreSearch: true });
  }));
});
