(() => {
    "use strict";

    const backdrop = document.getElementById("pwa-modal-backdrop");
    const iframe = backdrop?.querySelector("iframe");
    const closeBtn = backdrop?.querySelector("button[data-modal-close]");

    if (!backdrop || !iframe || !closeBtn) {
        console.error("Modal interception disabled: required modal elements not found.");
        return;
    }

    const originalBodyOverflow = document.body.style.overflow || "";

    const closeModal = () => {
        backdrop.classList.remove("is-active");
        backdrop.setAttribute("aria-hidden", "true");
        iframe.src = "about:blank";
        document.body.style.overflow = originalBodyOverflow;
        const opener = document.body.querySelector('[data-modal-opener="true"]');
        if (opener) {
            opener.removeAttribute("data-modal-opener");
            if (typeof opener.focus === "function") {
                opener.focus({ preventScroll: true });
            }
        }
    };

    const openModal = (link) => {
        try {
            const href = new URL(link.href, window.location.href).toString();
            iframe.src = href;
            link.setAttribute("data-modal-opener", "true");
            backdrop.classList.add("is-active");
            backdrop.setAttribute("aria-hidden", "false");
            document.body.style.overflow = "hidden";
            closeBtn.focus({ preventScroll: true });
        } catch (error) {
            console.error("Failed to open link in modal:", error);
            window.open(link.href, "_self", "noopener,noreferrer");
        }
    };

    const shouldIntercept = (link) => {
        if (!link || !link.href) {
            return false;
        }
        if (link.dataset.allowNewWindow === "true") {
            return false;
        }
        if (link.hasAttribute("download")) {
            return false;
        }
        const target = (link.getAttribute("target") || "").toLowerCase();
        return target === "_blank";
    };

    document.addEventListener(
        "click",
        (event) => {
            const link = event.target.closest("a");
            if (!shouldIntercept(link)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            openModal(link);
        },
        true
    );

    closeBtn.addEventListener("click", closeModal);

    backdrop.addEventListener("click", (event) => {
        if (event.target === backdrop) {
            closeModal();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && backdrop.classList.contains("is-active")) {
            closeModal();
        }
    });
})();

