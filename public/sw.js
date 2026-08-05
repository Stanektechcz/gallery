/* Maki root service worker: web remains the source of truth. */
/*
 * v3 drops every v2 entry. The static cache key never changed, so build assets from
 * every past deployment accumulated in it — three generations of app.js were still held.
 */
const VERSION = 'maki-shell-v3';
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
    event.waitUntil(caches.open(SHELL_CACHE).then(cache => cache.addAll(SHELL_FILES)));
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
        event.respondWith(fetch(request).catch(() => caches.match('/offline.html')));
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
