/* Service worker — « Les 6 étapes »
 *
 * Objectifs :
 *  - le solo est hors-ligne par défaut (cache-first sur index.php + assets) ;
 *  - le réseau ne sert qu'à la compétition ouverte via un lien (/s/CODE,
 *    session.php, index.php?session=, classement) — jamais mis en cache ;
 *  - les POST (scores, progression, e-mail) et l'API passent toujours réseau ;
 *  - mises à jour maîtrisées : « skipWaiting » depuis assets/pwa.js hors partie.
 *
 * Le fichier est servi à la racine de l'application ; tous les chemins sont
 * relatifs à ce dossier (fonctionne à la racine d'un domaine ou en sous-dossier).
 */

const VERSION = new URL(self.location.href).searchParams.get("v") || "dev";
const SHELL_CACHE = `six-etapes-shell-${VERSION}`;
const RUNTIME_CACHE = "six-etapes-runtime-v1";
const BASE = new URL("./", self.location.href);

const SHELL_URLS = [
  "./",
  "./index.php",
  "./index.php?src=pwa",
  "./?src=pwa",
  "./assets/app.css",
  "./assets/app.js",
  "./assets/pwa.js",
  "./manifest.php",
  "./icon.php?size=192",
  "./icon.php?size=512",
  "./icon.php?size=180",
].map((path) => new URL(path, BASE).href);

const NETWORK_TIMEOUT_MS = 4000;

self.addEventListener("install", (event) => {
  event.waitUntil(
    caches.open(SHELL_CACHE).then(async (cache) => {
      // Précache tolérant : une ressource manquante ne bloque pas l'installation.
      await Promise.all(
        SHELL_URLS.map(async (url) => {
          try {
            const response = await fetch(url, { credentials: "same-origin", cache: "no-cache" });
            if (response.ok) await cache.put(url, response);
          } catch {
            // Ignoré : sera mis en cache au premier passage réseau.
          }
        })
      );
    })
  );
});

self.addEventListener("activate", (event) => {
  event.waitUntil(
    (async () => {
      const keys = await caches.keys();
      await Promise.all(
        keys
          .filter((key) => key.startsWith("six-etapes-shell-") && key !== SHELL_CACHE)
          .map((key) => caches.delete(key))
      );
      await self.clients.claim();
    })()
  );
});

self.addEventListener("message", (event) => {
  if (event.data === "skipWaiting") self.skipWaiting();
});

function isSameOrigin(url) {
  return url.origin === self.location.origin;
}

function isInScope(url) {
  return isSameOrigin(url) && url.pathname.startsWith(BASE.pathname);
}

function pathInApp(url) {
  return url.pathname.slice(BASE.pathname.length).replace(/^\/+/, "");
}

function isNeverCached(url) {
  const path = pathInApp(url);
  return (
    path.startsWith("api/") ||
    path.startsWith("admin") ||
    path.startsWith("session_") ||
    path === "session.php" ||
    path === "leaderboard.php" ||
    path === "qr_code.php" ||
    path.startsWith("export_") ||
    path === "logout.php" ||
    path === "email_correction.php" ||
    /^s\/[A-Za-z0-9]+\/?$/.test(path)
  );
}

function isCompetitionNavigation(url) {
  const path = pathInApp(url);
  if (
    path.startsWith("admin") ||
    path.startsWith("export_") ||
    path === "logout.php" ||
    path === "qr_code.php"
  ) {
    return false;
  }
  if (
    path === "session.php" ||
    path === "leaderboard.php" ||
    path.startsWith("session_") ||
    path.startsWith("api/") ||
    /^s\/[A-Za-z0-9]+\/?$/.test(path)
  ) {
    return true;
  }
  return url.searchParams.has("session") || url.searchParams.has("code");
}

function isStaticAsset(url) {
  const path = url.pathname.slice(BASE.pathname.length);
  return path.startsWith("assets/") || path === "manifest.php" || path === "icon.php";
}

function isFont(url) {
  return url.hostname === "fonts.googleapis.com" || url.hostname === "fonts.gstatic.com";
}

async function cacheFirst(request, cacheName, fallbackUrl) {
  const cache = await caches.open(cacheName);
  const cached = (await cache.match(request)) || (await cache.match(request, { ignoreSearch: true }));
  if (cached) {
    fetch(request)
      .then((response) => {
        if (response.ok && !response.redirected) cache.put(request, response.clone()).catch(() => {});
      })
      .catch(() => {});
    return cached;
  }
  try {
    const response = await fetch(request);
    if (response.ok && !response.redirected) cache.put(request, response.clone()).catch(() => {});
    return response;
  } catch {
    if (fallbackUrl) {
      const fallback = await cache.match(fallbackUrl);
      if (fallback) return fallback;
    }
    return soloOfflineResponse();
  }
}

async function networkOnlyOrOffline(request) {
  try {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), NETWORK_TIMEOUT_MS);
    const response = await fetch(request, { signal: controller.signal });
    clearTimeout(timer);
    return response;
  } catch {
    return competitionOfflineResponse();
  }
}

async function staleWhileRevalidate(request, cacheName) {
  const cache = await caches.open(cacheName);
  const cached = await cache.match(request);
  const network = fetch(request)
    .then((response) => {
      if (response.ok || response.type === "opaque") cache.put(request, response.clone()).catch(() => {});
      return response;
    })
    .catch(() => cached);
  return cached || network;
}

function soloOfflineResponse() {
  const html = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Hors ligne</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif;background:#f3f5f9;color:#0f172a;text-align:center;padding:24px}
main{max-width:360px}h1{font-size:1.25rem;margin:0 0 8px}p{margin:0 0 18px;color:#64748b;line-height:1.5}button{display:block;width:100%;box-sizing:border-box;padding:14px;border-radius:12px;font:inherit;font-weight:700;border:0;background:#1D5BD4;color:#fff}</style></head>
<body><main><h1>Ouvre le jeu une première fois</h1><p>Ensuite, le solo reste disponible sans Internet. La compétition de classe, elle, passe uniquement par le lien de l’enseignant.</p>
<button onclick="location.reload()">Réessayer</button></main></body></html>`;
  return new Response(html, { status: 503, headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" } });
}

function competitionOfflineResponse() {
  const html = `<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Séance hors ligne</title>
<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,sans-serif;background:#f3f5f9;color:#0f172a;text-align:center;padding:24px}
main{max-width:360px}h1{font-size:1.25rem;margin:0 0 8px}p{margin:0 0 18px;color:#64748b;line-height:1.5}a,button{display:block;width:100%;box-sizing:border-box;padding:14px;border-radius:12px;font:inherit;font-weight:700;border:0;text-decoration:none;margin-top:10px}
.p{background:#1D5BD4;color:#fff}.s{background:#fff;color:#0f172a;border:1px solid #d7dde8}</style></head>
<body><main><h1>La compétition a besoin d’Internet</h1><p>Le lien de séance (classement, live) ne marche que connecté. Le jeu solo, lui, fonctionne hors ligne.</p>
<a class="p" href="./">Jouer en solo</a><button class="s" onclick="location.reload()">Réessayer</button></main></body></html>`;
  return new Response(html, { status: 503, headers: { "Content-Type": "text/html; charset=utf-8", "Cache-Control": "no-store" } });
}

self.addEventListener("fetch", (event) => {
  const { request } = event;
  if (request.method !== "GET") return;

  const url = new URL(request.url);

  if (isFont(url)) {
    event.respondWith(staleWhileRevalidate(request, RUNTIME_CACHE));
    return;
  }

  if (!isInScope(url)) return;

  if (request.mode === "navigate" && isCompetitionNavigation(url)) {
    event.respondWith(networkOnlyOrOffline(request));
    return;
  }

  if (isNeverCached(url)) return;

  if (isStaticAsset(url)) {
    event.respondWith(staleWhileRevalidate(request, SHELL_CACHE));
    return;
  }

  if (request.mode === "navigate") {
    const fallback = new URL("./index.php", BASE).href;
    event.respondWith(cacheFirst(request, SHELL_CACHE, fallback));
  }
});
