(function () {
    "use strict";

    async function pendingCount() {
        if (!window.SproutRouteOffline) {
            return 0;
        }

        if (window.SproutRouteOffline.countPending) {
            return window.SproutRouteOffline.countPending();
        }

        const queued = window.SproutRouteOffline.ORDER_STATE.QUEUED;
        return window.SproutRouteOffline.countOrders(queued);
    }

    async function updateBanner() {
        const banner = document.querySelector("[data-connection-banner]");
        if (!banner) {
            return;
        }

        const message = banner.querySelector("[data-connection-message]");
        banner.hidden = false;

        const isOnline = navigator.onLine;
        banner.setAttribute("data-connection-state", isOnline ? "online" : "offline");

        if (!message) {
            return;
        }

        let count = 0;
        try {
            count = await pendingCount();
        } catch (error) {
            count = 0;
        }

        const label = count === 1 ? "order" : "orders";
        if (isOnline && count === 0) {
            message.textContent = "You're online.";
        } else if (isOnline) {
            message.textContent = "You're online, " + count + " " + label + " waiting to sync.";
        } else if (count === 0) {
            message.textContent = "You're offline. Orders will be saved and synced later.";
        } else {
            message.textContent = "You're offline, " + count + " " + label + " will sync when you reconnect.";
        }
    }

    async function retryPendingOrders() {
        if (!window.SproutRouteOffline || !window.SproutRouteOffline.retryAll) {
            return;
        }

        try {
            await window.SproutRouteOffline.retryAll();
        } catch (error) {
            window.console.warn("Orders will retry later.", error);
        }

        await updateBanner();
        window.dispatchEvent(new Event("sproutroute:orders-synced"));
    }

    window.SproutRouteConnection = {
        updateBanner: updateBanner,
        retry: retryPendingOrders
    };

    document.addEventListener("DOMContentLoaded", function () {
        updateBanner();
        window.addEventListener("online", function () {
            updateBanner();
            retryPendingOrders();
        });
        retryPendingOrders();
    });
    window.addEventListener("offline", updateBanner);
})();
