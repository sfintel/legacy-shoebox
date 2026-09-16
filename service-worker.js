const CACHE_NAME = "app-lamp-v7";
const APP_SHELL = [
  "/",
  "/login.php",
  "/css/style.css",
  "/js/app.js",
  // No manifest.json here — each subject now has its own
  // manifest-{slug}.json (see manifest_url() in includes/archive.php),
  // and this one static service worker file is shared across every
  // subject's origin, so no single path fits all of them.
  "/icons/icon-192.png",
  "/icons/icon-512.png",
];

// Endpoints that must always hit the network directly — auth state, chat,
// and admin actions should never be served from cache.
const NO_CACHE_PREFIXES = [
  "/api/login.php",
  "/api/logout.php",
  "/api/me.php",
  "/api/chat.php",
  "/api/signup.php",
  "/api/admin_confirm_action.php",
  "/api/admin/",
  // Passkey ceremonies are POST requests carrying one-time challenges —
  // aside from cache.put() throwing on non-GET requests anyway, a
  // cached response here would be actively wrong, not just stale.
  "/api/webauthn/",
];

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(APP_SHELL)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
    )
  );
  self.clients.claim();
});

// Everything else — every admin_*.php page and every JS/CSS file it
// loads (admin_content.js, account.js, date_format.js, etc.), plus
// /api/data.php — defaults to network-first, falling back to cache only
// when actually offline. This used to be cache-first (stale-while-
// revalidate) for anything not explicitly listed above, which is where
// /api/data.php's own special case came from — but that same default
// silently applied to every admin page and script too, so a deploy that
// changed e.g. admin_content.js could leave an already-installed PWA
// showing the OLD script for a full extra page load (or indefinitely,
// for a page that's rarely fully reloaded) — confirmed live in
// production after the 2.6.0 release (see CHANGELOG). Admin surfaces
// have no offline-use case to justify cache-first's staleness risk, so
// only APP_SHELL — the reader-facing experience this was actually
// designed for — gets cache-first treatment below; everything else
// prefers a fresh copy whenever the network is available.
self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (NO_CACHE_PREFIXES.some((p) => url.pathname.startsWith(p))) return;

  if (!APP_SHELL.includes(url.pathname)) {
    event.respondWith(
      fetch(event.request)
        .then((networkResponse) => {
          if (networkResponse && networkResponse.ok) {
            const clone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          }
          return networkResponse;
        })
        .catch(() => caches.match(event.request))
    );
    return;
  }

  // Cache-first (stale-while-revalidate) for the app shell only — this
  // is fine here since these specific files only change on a deploy,
  // not on every admin edit, and cache-first is what makes the reading
  // experience feel instant and work offline.
  event.respondWith(
    caches.match(event.request).then((cached) => {
      const fetchPromise = fetch(event.request)
        .then((networkResponse) => {
          if (networkResponse && networkResponse.ok) {
            const clone = networkResponse.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          }
          return networkResponse;
        })
        .catch(() => cached);
      return cached || fetchPromise;
    })
  );
});
