/* Maki root service worker: web remains the source of truth. */
/*
 * v3 drops every v2 entry. The static cache key never changed, so build assets from
 * every past deployment accumulated in it — three generations of app.js were still held.
 */
const VERSION = 'maki-shell-v4';
const SHELL_CACHE = `${VERSION}:static`;
const SHELL_FILES = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/pwa-192x192.png',
    '/icons/pwa-512x512.png',
    '/icons/pwa-maskable-512x512.png',
    '/icons/apple-touch-icon.png',
];
const LEGACY_PRIVATE_CACHES = new Set([
    'timeline-cache',
    'variants-cache',
    'trip-plans-cache',
    'calendar-cache',
]);

self.addEventListener('install', event => {
    /*
     * No skipWaiting here, deliberately.
     *
     * The page runs its own update flow: it watches for a waiting worker, tells the
     * person, and sends SKIP_WAITING when they accept — and its controllerchange handler
     * reloads the tab. Calling skipWaiting here bypasses all of that, so every deployment
     * reloads the page under whoever is using it, mid-upload or mid-sentence.
     *
     * A worker that waits is the correct behaviour; being stuck behind one is a problem
     * to solve in the page, where somebody can be asked.
     */
    /*
     * Cached one at a time rather than with addAll.
     *
     * addAll is all-or-nothing: one file answering anything but 200 — a redirect from a
     * middleware, a proxy hiccup, an icon renamed — rejects the whole promise, install
     * fails, and the worker never activates. The previous one then keeps serving forever,
     * which is exactly how a fix ships and changes nothing.
     *
     * A missing icon is not worth blocking the shell for. What actually matters offline
     * is offline.html, and losing an icon costs nothing anybody notices.
     */
    event.waitUntil((async () => {
        const cache = await caches.open(SHELL_CACHE);

        await Promise.all(SHELL_FILES.map(async file => {
            try {
                await cache.add(file);
            } catch (error) {
                console.warn('[sw] nepodařilo se uložit do cache:', file, error);
            }
        }));
    })());
});

self.addEventListener('activate', event => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(keys
            .filter(key => (key.startsWith('maki-shell-') && key !== SHELL_CACHE) || LEGACY_PRIVATE_CACHES.has(key))
            .map(key => caches.delete(key)));
        await self.clients.claim();
    })());
});

self.addEventListener('message', event => {
    if (event.data?.type === 'SKIP_WAITING') self.skipWaiting();
});

self.addEventListener('fetch', event => {
    const request = event.request;
    if (request.method !== 'GET') return;

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Authenticated HTML is always fetched from the server. Offline mode uses
    // a public shell only, never an old page containing another user's data.
    if (request.mode === 'navigate') {
        /*
         * The request is passed through untouched, and deliberately so.
         *
         * Supplying an init object — this used to pass { cache: 'no-store' } — rebuilds
         * the Request, and 'navigate' mode cannot survive that: it degrades to 'cors'.
         * A navigation carries redirect: 'manual', so any redirecting URL answers with an
         * opaqueredirect, which respondWith() only accepts for a genuine navigation. The
         * rebuilt request was no longer one, so the promise rejected, fell into the catch
         * below, and served the offline page.
         *
         * Every redirecting address was affected: the site root while it redirected to
         * the login form, sign-up while registration is closed, any page behind auth for
         * a signed-out visitor, and the return from the payment gateway.
         *
         * Freshness does not depend on this: authenticated HTML is never put in the cache
         * and the response headers govern the browser's own caching.
         */
        /*
         * One retry before giving up. A single failed request is usually a moment of no
         * signal rather than a device that is offline, and answering the first stumble
         * with "waiting for connection" is how a working app looks broken.
         */
        event.respondWith(
            fetch(request).catch(() => fetch(request)).catch(() => caches.match('/offline.html')),
        );
        return;
    }

    // Hashed build assets and public app artwork are immutable/safe to cache.
    const isStaticAsset = url.pathname.startsWith('/build/assets/')
        || url.pathname.startsWith('/icons/')
        || url.pathname === '/manifest.webmanifest'
        || url.pathname === '/favicon.ico';
    if (!isStaticAsset) return;

    event.respondWith((async () => {
        const cached = await caches.match(request);
        if (cached) return cached;
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(SHELL_CACHE);
            await cache.put(request, response.clone());
        }
        return response;
    })());
});

/*
 * Push delivery. The payload is JSON produced by WebPushService; anything unexpected
 * still shows a generic notification rather than being dropped silently.
 */
self.addEventListener('push', event => {
    let data = { title: 'Maki', body: 'Máte novou připomínku.', url: '/', tag: 'maki' };
    try {
        if (event.data) data = { ...data, ...event.data.json() };
    } catch (error) {
        if (event.data) data.body = event.data.text();
    }

    event.waitUntil(self.registration.showNotification(data.title, {
        body: data.body,
        icon: '/icons/pwa-192x192.png',
        badge: '/icons/pwa-192x192.png',
        // Same tag replaces an earlier notification instead of stacking.
        tag: data.tag,
        data: { url: data.url },
    }));
});

self.addEventListener('notificationclick', event => {
    event.notification.close();
    const target = event.notification.data?.url || '/';

    event.waitUntil((async () => {
        const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        // Reuse an open tab where possible rather than piling up new ones.
        for (const client of windows) {
            if (new URL(client.url).origin === self.location.origin) {
                await client.focus();
                return client.navigate(target);
            }
        }
        return self.clients.openWindow(target);
    })());
});
