/**
 * TaskStick — Service Worker
 * Strategy:
 *   - App shell (HTML, fonts, icons, manifest): network-first, cache as offline fallback
 *   - API calls (/api/, /auth/): always network — never cache auth or live data
 */

const CACHE_VERSION = 'taskstick-v3';

const SHELL_URLS = [
  '/',
  '/manifest.json',
  '/icons/icon-192.png?v=2',
  '/icons/icon-512.png?v=2',
  '/icons/icon-180.png?v=2',
];

// ── Install: pre-cache the app shell ──────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_VERSION)
      .then(cache => cache.addAll(SHELL_URLS))
      .then(() => self.skipWaiting())   // activate immediately
  );
});

// ── Activate: purge old caches ─────────────────────────────────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(
        keys
          .filter(k => k !== CACHE_VERSION)
          .map(k => caches.delete(k))
      )
    ).then(() => self.clients.claim())
  );
});

// ── Fetch: network-first for API/auth, cache-first for shell ──────────────────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Always go to network for API calls, auth, and non-GET requests
  if (
    url.pathname.startsWith('/api/') ||
    url.pathname.startsWith('/auth/') ||
    event.request.method !== 'GET'
  ) {
    event.respondWith(fetch(event.request));
    return;
  }

  // Network-first for shell assets — always shows the latest deploy when
  // online; only falls back to the cached copy when the network is
  // unavailable (offline support), so deploys never get stuck behind a
  // stale cached index.html/icons.
  event.respondWith(
    fetch(event.request).then(response => {
      if (response && response.status === 200 && response.type === 'basic') {
        const toCache = response.clone();
        caches.open(CACHE_VERSION).then(cache => cache.put(event.request, toCache));
      }
      return response;
    }).catch(() => caches.match(event.request))
  );
});
