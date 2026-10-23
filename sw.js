/* FOMAXO Store System - offline support. Keeps the app itself on the device so it opens without internet.
   Data requests (api.php) always go to the server; the app handles offline sales itself. */
var VERSION = 'fomaxo-pos-v63';
var PHOTOS = 'fomaxo-pos-photos';  // kept across app updates
var SHELL = [
  './', 'index.html', 'assets/app.css', 'assets/app.js', 'assets/qrcode.js', 'assets/countries.js',
  'manifest.webmanifest', 'assets/icon-192.png', 'assets/icon-512.png', 'assets/apple-touch-icon.png', 'assets/favicon-32.png'
];

self.addEventListener('install', function (e) {
  // 'reload' skips the browser's own cache, so the saved copy is always this version's files.
  e.waitUntil(caches.open(VERSION).then(function (c) {
    return c.addAll(SHELL.map(function (u) { return new Request(u, { cache: 'reload' }); }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSION && k !== PHOTOS; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin) return;
  var path = url.pathname;
  // Product photos: from the phone's saved copy instantly, refreshed in the background.
  if (/\/image\.php$/.test(path)) {
    e.respondWith(caches.open(PHOTOS).then(function (c) {
      return c.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }).catch(function () { return hit; });
        return hit || net;
      });
    }));
    return;
  }
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

  // Versioned app files (app.js?v=63): they never change, so open them straight from the phone.
  if (/[?&]v=/.test(url.search)) {
    e.respondWith(caches.open(VERSION).then(function (c) {
      return c.match(req).then(function (hit) {
        return hit || fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; })
          .catch(function () { return caches.match(req, { ignoreSearch: true }); });
      });
    }));
    return;
  }
  // Other app files: newest copy first, saved copy when offline.
  e.respondWith(fetch(req, { cache: 'no-cache' }).then(function (res) {
    if (res.ok) { var copy = res.clone(); caches.open(VERSION).then(function (c) { c.put(req, copy); }); }
    return res;
  }).catch(function () {
    return caches.match(req, { ignoreSearch: true });
  }));
});
