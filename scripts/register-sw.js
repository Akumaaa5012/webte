document.addEventListener("DOMContentLoaded", () => {
    if ("serviceWorker" in navigator) {
        navigator.serviceWorker
            .register("../sw.js")
            .then((registration) => {
                console.log("SproutRoute PWA active — offline caching enabled for: ", registration.scope);
            })
            .catch((error) => {
                console.error("Service Worker registration failed: ", error);
            })
    }
})