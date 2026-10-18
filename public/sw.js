/* Klok Ads Console — offline support.
 * - App files (build/, icons, fonts): cache first, they never change once built.
 * - Pages and page data: network first; the last copy is shown when offline.
 * Nothing is cached for public client pages, Stripe, exports, PDFs or the installer. */
const ASSETS = 'klok-assets-v1';
const PAGES = 'klok-pages-v1';
const BASE = new URL(self.registration.scope).pathname; // "/" or "/console/"
const SKIP = ['stripe/', 'install', 'logout', 'i/', 'card/', 'card-saved', 'money/export', 'cashflow/export', '/pdf', '/document', '/preview'];

self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        for (const key of await caches.keys()) {
            if (![ASSETS, PAGES].includes(key)) await caches.delete(key);
        }
        await self.clients.claim();
    })());
});

// The app asks for this on log out, so nothing private stays on the device.
self.addEventListener('message', (event) => {
    if (event.data === 'clear') event.waitUntil(caches.delete(PAGES));
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);

    if (url.origin === 'https://fonts.bunny.net') {
        event.respondWith(cacheFirst(req));
        return;
    }
    if (url.origin !== location.origin || !url.pathname.startsWith(BASE)) return;

    const path = url.pathname.slice(BASE.length);
    if (SKIP.some((s) => path.startsWith(s) || path.includes(s))) return;

    if (path.startsWith('build/') || /\.(png|svg|ico|webmanifest)$/.test(path)) {
        event.respondWith(cacheFirst(req));
        return;
    }

    const isPage = req.mode === 'navigate' || req.headers.get('X-Inertia') === 'true';
    // Partial reloads only carry part of a page; never keep them as "the page".
    if (isPage && !req.headers.has('X-Inertia-Partial-Component')) event.respondWith(networkFirst(req));
});

async function cacheFirst(req) {
    const cache = await caches.open(ASSETS);
    const hit = await cache.match(req);
    if (hit) return hit;
    const res = await fetch(req);
    if (res.ok) cache.put(req, res.clone());
    return res;
}

async function networkFirst(req) {
    const cache = await caches.open(PAGES);
    try {
        const res = await fetch(req);
        // Only real pages: not redirects to the login page, not errors.
        if (res.ok && !res.redirected) cache.put(req, res.clone());
        return res;
    } catch {
        const hit = await cache.match(req, { ignoreSearch: false });
        if (hit) return hit;
        if (req.mode === 'navigate') {
            for (const fallback of ['personal', 'dashboard', 'money']) {
                const page = await cache.match(new Request(BASE + fallback));
                if (page) return page;
            }
            return new Response(OFFLINE, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
        }
        return new Response(JSON.stringify({ message: 'offline' }), { status: 503, headers: { 'Content-Type': 'application/json' } });
    }
}

const OFFLINE = `<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Offline · Klok Ads</title>
<body style="margin:0;display:grid;place-items:center;min-height:100vh;background:#f6f7fb;font-family:Inter,system-ui,sans-serif;color:#131729">
<div style="text-align:center;padding:24px;max-width:340px">
<div style="font-size:40px">📡</div><h1 style="font-size:20px;margin:12px 0 6px">You're offline</h1>
<p style="color:#5b6280;font-size:14px;line-height:1.5">This page hasn't been opened on this device yet. Open it once while online and it will work offline next time.</p>
<button onclick="location.reload()" style="margin-top:12px;background:#5b45e8;color:#fff;border:0;border-radius:12px;padding:10px 18px;font-weight:600">Try again</button>
</div></body>`;
