const CACHE_NAME = "static-cache-v26";

const PRECACHE_ASSETS = [
    "./home/index.html",
    "./home/login.html",
    "./home/offline.html",
    "./buyer/browse.html",
    "./buyer/product.html",
    "./buyer/cart.html",
    "./buyer/checkout.html",
    "./buyer/order-confirmation.html",
    "./buyer/my-orders.html",
    "./buyer/recurring-deliveries.html",
    "./seller/seller-dashboard.html",
    "./seller/products.html",
    "./seller/orders.html",
    "./logistics/logistics-dashboard.html",
    "./css/style.css",
    "./scripts/app.js",
    "./scripts/buyer.js",
    "./scripts/buyer-orders.js",
    "./scripts/login.js",
    "./scripts/seller.js",
    "./scripts/logistics.js",
    "./scripts/offline-db.js",
    "./scripts/connection.js",
    "./scripts/register-sw.js",
    "./scripts/install-prompt.js",
    "./images/sproutroute-app-icon-192.png",
    "./images/sproutroute-app-icon-512.png",
    "./images/SRlogo.jpg",
    "./images/buyer.png",
    "./images/farmer.png",
    "./manifest.json"
];

const API_CACHE_NAME = "api-cache-v2";

const CACHABLE_API_PATHS = [
    "/api/products/read.php",
    "/api/orders/list.php"
];

function isCacheableApiRequest(url) {
    if (url.origin !== self.location.origin) {
        return false;
    }

    if (url.pathname.endsWith("/api/products/read.php") && url.searchParams.has("id")) {
        return false;
    }

    return CACHABLE_API_PATHS.some((path) => url.pathname.endsWith(path));
}

function isJsonResponse(response) {
    const contentType = response.headers.get("content-type") || "";
    return contentType.includes("application/json");
}

async function pruneApiCache(cache, request) {
    const requestPath = new URL(request.url).pathname;
    const cachedRequests = await cache.keys();

    for (let i = 0; i < cachedRequests.length; i += 1) {
        const cachedRequest = cachedRequests[i];
        const cachedPath = new URL(cachedRequest.url).pathname;

        if (cachedRequest.url !== request.url && cachedPath === requestPath) {
            await cache.delete(cachedRequest);
        }
    }
}

async function cacheApiResponse(request, response) {
    const cache = await caches.open(API_CACHE_NAME);
    await cache.put(request, response);
    await pruneApiCache(cache, request);
}

self.addEventListener("install", (event) => {
    event.waitUntil(
        caches
            .open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE_ASSETS))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) =>
                Promise.all(
                    keys
                        .filter((key) => key !== CACHE_NAME && key !== API_CACHE_NAME)
                        .map((key) => caches.delete(key))
                )
            )
            .then(() => self.clients.claim())
    );
});

self.addEventListener("fetch", (event) => {
    // Allow PATCH/POST/PUT for API requests (needed for delivery status updates)
    if (event.request.method !== "GET") {
        const url = new URL(event.request.url);
        if (url.pathname.includes("/api/")) {
            // For API non-GET requests, go directly to network without caching
            return;
        }
        return;
    }

    const url = new URL(event.request.url);

    // Ignore requests with unsupported schemes (chrome-extension://, chrome://, etc.)
    // These are typically browser extensions and cannot be cached
    if (!url.protocol.startsWith("http")) {
        return;
    }

    if (url.pathname.includes("/api/")) {
        if (!isCacheableApiRequest(url)) {
            return;
        }

        event.respondWith(
            fetch(event.request)
                .then(async (response) => {
                    if (response.ok && isJsonResponse(response)) {
                        try {
                            await cacheApiResponse(event.request, response.clone());
                        } catch (error) {
                            console.warn("Could not cache API response.", error);
                        }
                    }
                    return response;
                })
                .catch(() =>
                    caches.match(event.request).then((cached) => cached || Response.error())
                )
        );
        return;
    }

    if (
        url.pathname.endsWith("/home/login.html") ||
        url.pathname.endsWith("/scripts/login.js")
    ) {
        return;
    }

    // Page loads: network first (fresh HTML), cached copy as fallback, offline.html as last resort
    if (event.request.mode === "navigate") {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, copy).catch((error) => {
                                console.warn("Could not cache page:", error);
                            });
                        });
                    }
                    return response;
                })
                .catch(() =>
                    caches
                        .match(event.request)
                        .then((cached) => cached || caches.match("./home/offline.html"))
                )
        );
        return;
    }

    // JavaScript and HTML files: network first for fresh content, cached fallback for offline
    if (url.pathname.endsWith(".js") || url.pathname.endsWith(".html")) {
        event.respondWith(
            fetch(event.request)
                .then((response) => {
                    if (response.ok) {
                        const copy = response.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(event.request, copy).catch((error) => {
                                console.warn("Could not cache script:", error);
                            });
                        });
                    }
                    return response;
                })
                .catch(() => caches.match(event.request))
        );
        return;
    }

    // Other assets (CSS, images): cache first, network only when missing
    event.respondWith(
        caches.match(event.request).then((cached) => {
            if (cached) {
                return cached;
            }

            return fetch(event.request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(CACHE_NAME).then((cache) => {
                        cache.put(event.request, copy).catch((error) => {
                            console.warn("Could not cache asset:", error);
                        });
                    });
                }
                return response;
            });
        })
    );
});

function showSproutRouteNotification(title, body, url) {
    return self.registration.showNotification(title || "SproutRoute", {
        body: body || "You have a new update.",
        icon: "./images/sproutroute-app-icon-192.png",
        badge: "./images/sproutroute-app-icon-192.png",
        data: {
            url: url || "./seller/seller-dashboard.html"
        }
    });
}

self.addEventListener("message", (event) => {
    const data = event.data;

    if (!data || data.type !== "SHOW_NOTIFICATION") {
        return;
    }

    event.waitUntil(
        showSproutRouteNotification(data.title, data.body, data.url)
    );
});

self.addEventListener("push", (event) => {
    let payload = {};

    if (event.data) {
        try {
            payload = event.data.json();
        } catch (_) {
            payload = { body: event.data.text() };
        }
    }

    if (!payload || typeof payload !== "object") {
        payload = {};
    }

    event.waitUntil(
        showSproutRouteNotification(
            payload.title,
            payload.body || payload.message,
            payload.url
        )
    );
});

self.addEventListener("notificationclick", (event) => {
    event.notification.close();

    const data = event.notification.data || {};
    let targetUrl = new URL(
        data.url || "./seller/seller-dashboard.html",
        self.location.scope
    );

    if (targetUrl.origin !== self.location.origin) {
        targetUrl = new URL("./seller/seller-dashboard.html", self.location.scope);
    }

    event.waitUntil(
        self.clients.matchAll({ type: "window", includeUncontrolled: true }).then((clients) => {
            for (let i = 0; i < clients.length; i += 1) {
                if (clients[i].url === targetUrl.href && "focus" in clients[i]) {
                    return clients[i].focus();
                }
            }

            if (self.clients.openWindow) {
                return self.clients.openWindow(targetUrl.href);
            }

            return null;
        })
    );
});
