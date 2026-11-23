(function() {
    "use strict";

    const DB_NAME = "sproutroute-offline";
    const DB_VERSION = 2;
    const STORE_NAME = "pending-orders";
    const SYNC_ENDPOINT = "../api/orders/sync.php";
    const ORDERS_CACHE_KEY = "sproutroute.ordersCache";
    const PRODUCTS_CACHE_KEY = "sproutroute.productsCache";

    const ORDER_STATE = {
        QUEUED: "queued",
        SYNCING: "syncing",
        FAILED: "failed",
        SYNCED: "synced"
    };

    let syncing = false;

    function openDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(DB_NAME, DB_VERSION);

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                let store;

                if (!db.objectStoreNames.contains(STORE_NAME)) {
                    store = db.createObjectStore(STORE_NAME, {
                        keyPath: "client_uuid",
                    });
                } else {
                    store = request.transaction.objectStore(STORE_NAME);
                }

                if (store.indexNames.contains("status")) {
                    store.deleteIndex("status");
                }
                if (!store.indexNames.contains("syncState")) {
                    store.createIndex("syncState", "syncState", {unique: false});
                }

                store.openCursor().onsuccess = function (event) {
                    const cursor = event.target.result;
                    if (!cursor) {
                        return;
                    }
                    const order = cursor.value;
                    let updated = false;

                    // Migrate old status field to syncState
                    if (!order.syncState && order.status) {
                        if (order.status === ORDER_STATE.QUEUED ||
                            order.status === ORDER_STATE.SYNCING ||
                            order.status === ORDER_STATE.FAILED ||
                            order.status === ORDER_STATE.SYNCED) {
                            order.syncState = order.status;
                            updated = true;
                        }
                    }

                    // Ensure user_id field exists for new orders
                    if (!order.user_id && order.syncState === ORDER_STATE.QUEUED) {
                        // Leave as null for legacy orders - they'll need manual review
                        updated = true;
                    }

                    if (updated) {
                        cursor.update(order);
                    }
                    cursor.continue();
                };
            };

            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    }

    function generateClientUuid() {
        if (window.crypto && typeof window.crypto.randomUUID === "function") {
            return window.crypto.randomUUID();
        }

        return "xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx".replace(/[xy]/g, function(c) {
            const r = (Math.random() * 16) | 0;
            const v = c === "x" ? r : (r & 0x3) | 0x8;
            return v.toString(16);
        });
    }

    function saveOrder(order) {
        return openDB().then((db) => {
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, "readwrite");
                const store = tx.objectStore(STORE_NAME);
                store.put(order);
                tx.oncomplete = () => resolve(order);
                tx.onerror = () => reject(tx.error);
            });
        });
    }

    function getOrder(clientUuid) {
        return openDB().then((db) => {
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, "readonly");
                const request = tx.objectStore(STORE_NAME).get(clientUuid);
                request.onsuccess = () => resolve(request.result || null);
                request.onerror = () => reject(request.error);
            });
        });
    }

    function listOrders(syncState) {
        return openDB().then((db) => {
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, "readonly");
                const store = tx.objectStore(STORE_NAME);
                const request = syncState
                    ? store.index("syncState").getAll(syncState)
                    : store.getAll();
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
        });
    }

    function countOrders(syncState) {
        return openDB().then((db) => {
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, "readonly");
                const store = tx.objectStore(STORE_NAME);
                const request = syncState
                    ? store.index("syncState").count(syncState)
                    : store.count();
                request.onsuccess = () => resolve(request.result);
                request.onerror = () => reject(request.error);
            });
        });
    }

    function removeOrder(clientUuid) {
        return openDB().then((db) => {
            return new Promise((resolve, reject) => {
                const tx = db.transaction(STORE_NAME, "readwrite");
                const store = tx.objectStore(STORE_NAME);
                store.delete(clientUuid);
                tx.oncomplete = () => resolve();
                tx.onerror = () => reject(tx.error);
            });
        });
    }

    function setOrderState(clientUuid, state, extra) {
        return getOrder(clientUuid).then((order) => {
            if (!order) {
                return null;
            }
            order.syncState = state;
            if (extra) {
                Object.assign(order, extra);
            }
            return saveOrder(order);
        });
    }

    function updateOrderRestaurantId(clientUuid, newRestaurantId) {
        return getOrder(clientUuid).then((order) => {
            if (!order) {
                return null;
            }
            order.restaurant_id = newRestaurantId;
            order.syncState = ORDER_STATE.QUEUED;
            order.lastError = "";
            return saveOrder(order);
        });
    }

    function listPending() {
        return listOrders().then(function(orders) {
            const pending = [];
            for (let i = 0; i < orders.length; i++) {
                const order = orders[i];
                if (order.syncState === ORDER_STATE.QUEUED ||
                    order.syncState === ORDER_STATE.SYNCING ||
                    order.syncState === ORDER_STATE.FAILED) {
                    pending.push(order);
                }
            }
            return pending;
        });
    }

    function countPending() {
        return listPending().then((orders) => orders.length);
    }

    function readJson(key, fallback) {
        try {
            const raw = window.localStorage.getItem(key);
            if (!raw) {
                return fallback;
            }
            const parsed = JSON.parse(raw);
            return parsed === null || parsed === undefined ? fallback : parsed;
        } catch (error) {
            return fallback;
        }
    }

    function writeJson(key, value) {
        try {
            window.localStorage.setItem(key, JSON.stringify(value));
        } catch (error) {
            return;
        }
    }

    function isOnline() {
        return navigator.onLine !== false;
    }

    function cacheOrders(orders) {
        writeJson(ORDERS_CACHE_KEY, {
            savedAt: new Date().toISOString(),
            orders: orders
        });
    }

    function getCachedOrders() {
        const cached = readJson(ORDERS_CACHE_KEY, null);
        return cached && Array.isArray(cached.orders) ? cached.orders : [];
    }

    function cacheProducts(products) {
        writeJson(PRODUCTS_CACHE_KEY, {
            savedAt: new Date().toISOString(),
            products: products
        });
    }

    function getCachedProducts() {
        const cached = readJson(PRODUCTS_CACHE_KEY, null);
        return cached && Array.isArray(cached.products) ? cached.products : [];
    }

    function queueOrder(order, userId) {
        const queued = Object.assign({}, order, {
            syncState: ORDER_STATE.QUEUED,
            queuedAt: order.queuedAt || new Date().toISOString(),
            lastError: "",
            user_id: userId || null
        });
        return saveOrder(queued).then(() => queued);
    }

    async function syncPendingOrders() {
        if (syncing) {
            return [];
        }
        syncing = true;

        let orders = [];
        try {
            orders = await listPending();
        } catch (error) {
            syncing = false;
            return [];
        }

        if (orders.length === 0) {
            syncing = false;
            return [];
        }

        if (!isOnline()) {
            syncing = false;
            return [];
        }

        for (let i = 0; i < orders.length; i++) {
            await setOrderState(orders[i].client_uuid, ORDER_STATE.SYNCING);
        }

        let results = [];
        try {
            const response = await fetch(SYNC_ENDPOINT, {
                method: "POST",
                credentials: "same-origin",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ orders: orders })
            });
            if (!response.ok) {
                throw new Error("Sync failed with status " + response.status);
            }

            const data = await response.json();
            results = data.results || [];

            for (let i = 0; i < results.length; i++) {
                const result = results[i];
                if (result.status === "created" || result.status === "duplicate") {
                    await removeOrder(result.client_uuid);
                } else {
                    let detail = "The order could not be synced.";
                    if (result.errors) {
                        if (result.errors.user_id) {
                            detail = result.errors.user_id;
                        } else {
                            detail = JSON.stringify(result.errors);
                        }
                    }
                    await setOrderState(result.client_uuid, ORDER_STATE.FAILED, {
                        lastError: detail
                    });
                }
            }
        } catch (error) {
            for (let i = 0; i < orders.length; i++) {
                await setOrderState(orders[i].client_uuid, ORDER_STATE.FAILED, {
                    lastError: error.message
                });
            }
            results = [];
        }

        syncing = false;
        return results;
    }

    window.SproutRouteOffline = {
        ORDER_STATE: ORDER_STATE,
        openDB: openDB,
        generateClientUuid: generateClientUuid,
        saveOrder: saveOrder,
        getOrder: getOrder,
        listOrders: listOrders,
        ListOrders: listOrders,
        countOrders: countOrders,
        removeOrder: removeOrder,
        setOrderState: setOrderState,
        updateOrderRestaurantId: updateOrderRestaurantId,
        listPending: listPending,
        countPending: countPending,
        queueOrder: queueOrder,
        retryAll: syncPendingOrders,
        isOnline: isOnline,
        cacheOrders: cacheOrders,
        getCachedOrders: getCachedOrders,
        cacheProducts: cacheProducts,
        getCachedProducts: getCachedProducts
    };
})();
