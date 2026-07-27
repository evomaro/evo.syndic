const CACHE_PREFIX = "evosyndic-";
const CACHE = `${CACHE_PREFIX}static-20260722-2`;
let cacheWritesEnabled = true;
const pendingCacheWrites = new Set();
const SHELL = [
    "/offline.html",
    "/manifest.webmanifest",
    "/icons/icon-192.png",
    "/icons/icon-512.png",
    "/icons/icon-maskable-512.png",
    "/icons/apple-touch-icon.png",
];
const STATIC_PATH = /^\/build\/assets\/[A-Za-z0-9._-]+\.(?:js|css|woff2?)$/;
const cacheStaticResponse = async (request, response) => {
    if (!response.ok || response.type !== "basic" || !cacheWritesEnabled)
        return response;

    const write = (async () => {
        const cache = await caches.open(CACHE);
        if (cacheWritesEnabled) await cache.put(request, response.clone());
    })();
    pendingCacheWrites.add(write);
    try {
        await write;
    } finally {
        pendingCacheWrites.delete(write);
    }

    return response;
};
self.addEventListener("install", (event) =>
    event.waitUntil(
        caches
            .open(CACHE)
            .then((cache) => cache.addAll(SHELL))
            .then(() => self.skipWaiting()),
    ),
);
self.addEventListener("activate", (event) =>
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter(
                            (key) =>
                                key.startsWith(CACHE_PREFIX) && key !== CACHE,
                        )
                        .map((key) => caches.delete(key)),
                ),
            )
            .then(() => self.clients.claim()),
    ),
);
self.addEventListener("fetch", (event) => {
    const request = event.request;
    const url = new URL(request.url);
    if (
        request.method !== "GET" ||
        url.origin !== self.location.origin ||
        url.search
    )
        return;
    if (request.mode === "navigate") {
        event.respondWith(
            fetch(request).catch(() => caches.match("/offline.html")),
        );
        return;
    }
    if (STATIC_PATH.test(url.pathname) || SHELL.includes(url.pathname)) {
        event.respondWith(
            caches
                .match(request)
                .then(
                    (cached) =>
                        cached ||
                        fetch(request).then((response) =>
                            cacheStaticResponse(request, response),
                        ),
                ),
        );
    }
});
self.addEventListener("message", (event) => {
    if (event.data?.type !== "CLEAR_EVOSYNDIC_CACHES") return;
    cacheWritesEnabled = false;
    event.waitUntil(
        Promise.allSettled([...pendingCacheWrites])
            .then(() => caches.keys())
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key.startsWith(CACHE_PREFIX))
                        .map((key) => caches.delete(key)),
                ),
            )
            .then((results) =>
                event.ports[0]?.postMessage({
                    type: "EVOSYNDIC_CACHES_CLEARED",
                    deleted: results.filter(Boolean).length,
                }),
            ),
    );
});
