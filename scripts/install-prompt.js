(function () {
    "use strict";

    let deferredPrompt = null;

    function getInstallButton() {
        return document.querySelector("[data-install-button]");
    }

    function hideInstallButton() {
        const button = getInstallButton();
        if (button) {
            button.hidden = true;
        }
    }

    window.addEventListener("beforeinstallprompt", (event) => {
        event.preventDefault();
        deferredPrompt = event;

        const button = getInstallButton();
        if (button) {
            button.hidden = false;
        }
    });

    window.addEventListener("appinstalled", () => {
        deferredPrompt = null;
        hideInstallButton();
        console.log("SproutRoute was installed");
    });

    document.addEventListener("DOMContentLoaded", () => {
        const button = getInstallButton();
        if (!button) {
            return;
        }

        if (deferredPrompt) {
            button.hidden = false;
        }

        button.addEventListener("click", async () => {
            if (!deferredPrompt) {
                return;
            }

            const promptEvent = deferredPrompt;
            deferredPrompt = null;
            button.hidden = true;

            try {
                await promptEvent.prompt();
                const choice = await promptEvent.userChoice;

                if (choice.outcome === "accepted") {
                    console.log("User installed the app");
                } else {
                    console.log("User dismissed the install prompt");
                }
            } catch (error) {
                console.warn("Install prompt failed.", error);
            }
        });
    });
})();