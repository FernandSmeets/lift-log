/* Lift Log service worker — lets the app start without a connection.
 *
 * Put this file next to index.html. It keeps a copy of the app, its icons
 * and fonts on the phone:
 *  - The app page itself: network first (so a new version on the server is
 *    picked up automatically when online), falling back to the saved copy
 *    when offline or when the network takes longer than a few seconds.
 *  - Icons / manifest: saved copy first.
 *  - Google Fonts: saved copy first, refreshed in the background.
 *  - Everything else (update checks, Dropbox/GitHub backups, later a
 *    database) goes straight to the network, untouched.
 * Your workouts are not stored here — they stay in the app's own storage.
 */
const CACHE = 'liftlog-v1';
const FONT_CACHE = 'liftlog-fonts-v1';
const PAGE_KEY = './index.html';
const ASSETS = [
  './manifest.json',
  './apple-touch-icon.png',
  './favicon-32.png',
  './favicon-16.png',
  './icon-192.png',
  './icon-512.png'
];
const NETWORK_TIMEOUT_MS = 3500;

self.addEventListener('install', event => {
  event.waitUntil((async () => {
    const cache = await caches.open(CACHE);
    // The page is essential; icons are nice-to-have (a missing one must not
    // stop offline support from being installed).
    await cache.add(new Request(PAGE_KEY, { cache: 'reload' }));
    await Promise.all(ASSETS.map(a => cache.add(new Request(a, { cache: 'reload' })).catch(() => {})));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', event => {
  event.waitUntil((async () => {
    const keep = [CACHE, FONT_CACHE];
    for (const key of await caches.keys()) if (!keep.includes(key)) await caches.delete(key);
    await self.clients.claim();
  })());
});

function timeout(ms) {
  return new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), ms));
}

// App page: try the network (fresh version), store it, fall back to the copy.
async function pageRequest(request) {
  const cache = await caches.open(CACHE);
  try {
    const res = await Promise.race([fetch(request), timeout(NETWORK_TIMEOUT_MS)]);
    if (res && res.ok) cache.put(PAGE_KEY, res.clone());
    return res;
  } catch (e) {
    const saved = await cache.match(PAGE_KEY);
    if (saved) return saved;
    throw e;
  }
}

async function cacheFirst(request, cacheName) {
  const cache = await caches.open(cacheName);
  const saved = await cache.match(request);
  if (saved) return saved;
  const res = await fetch(request);
  if (res && (res.ok || res.type === 'opaque')) cache.put(request, res.clone());
  return res;
}

async function fontRequest(request) {
  const cache = await caches.open(FONT_CACHE);
  const saved = await cache.match(request);
  const refresh = fetch(request).then(res => {
    if (res && (res.ok || res.type === 'opaque')) cache.put(request, res.clone());
    return res;
  }).catch(() => saved);
  return saved || refresh;
}

self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // Google Fonts (stylesheet + font files).
  if (url.hostname === 'fonts.googleapis.com' || url.hostname === 'fonts.gstatic.com') {
    event.respondWith(fontRequest(req));
    return;
  }
  if (url.origin !== self.location.origin) return; // other sites: untouched

  // The in-app "check for updates" asks the server directly — never answer
  // it from the saved copy, or it would always see the old version.
  if (url.searchParams.has('check')) return;

  const scopePath = new URL(self.registration.scope).pathname;
  const isPage = req.mode === 'navigate' ||
    url.pathname === scopePath || url.pathname === scopePath + 'index.html';
  if (isPage) {
    event.respondWith(pageRequest(req));
    return;
  }
  const rel = './' + url.pathname.slice(scopePath.length);
  if (ASSETS.includes(rel)) {
    event.respondWith(cacheFirst(req, CACHE));
  }
});
