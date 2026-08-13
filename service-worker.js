const CACHE_NAME = "app-lamp-v2";
const APP_SHELL = [
  "/",
  "/login.php",
  "/css/style.css",
  "/js/app.js",
  "/manifest.json",
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

// /api/data.php stands in for the Node version's static /data/*.json
// files, but unlike a static file it changes whenever an admin edits
// the archive — a cache-first (stale-while-revalidate) strategy meant
// an edit wouldn't show up until a *second* page load (the first load
// serves the stale cached copy while quietly refreshing it for next
// time). Network-first here instead: always try the network, only fall
// back to cache when actually offline.
const NETWORK_FIRST_PREFIXES = ["/api/data.php"];

self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (NO_CACHE_PREFIXES.some((p) => url.pathname.startsWith(p))) return;

  if (NETWORK_FIRST_PREFIXES.some((p) => url.pathname.startsWith(p))) {
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

  // Cache-first (stale-while-revalidate) for the app shell — this is
  // fine here since JS/CSS/icons only change on a deploy, not on every
  // admin edit, and cache-first is what makes the PWA feel instant and
  // work offline.
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
