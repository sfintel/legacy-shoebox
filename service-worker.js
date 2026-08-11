const CACHE_NAME = "app-lamp-v1";
const APP_SHELL = [
  "/",
  "/login.php",
  "/css/style.css",
  "/js/app.js",
  "/manifest.json",
  "/icons/icon-192.png",
  "/icons/icon-512.png",
  "/api/data.php?name=quotes",
  "/api/data.php?name=people",
  "/api/data.php?name=places",
  "/api/data.php?name=timeline",
  "/api/data.php?name=transcript",
  "/api/data.php?name=discrepancies",
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

// Network-first for auth/chat/admin calls (never cache these), cache-first
// for everything else — including /api/data.php, which stands in for the
// Node version's static /data/*.json files.
self.addEventListener("fetch", (event) => {
  const url = new URL(event.request.url);
  if (NO_CACHE_PREFIXES.some((p) => url.pathname.startsWith(p))) return;

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
