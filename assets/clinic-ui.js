(function () {
    function byId(id) {
        return document.getElementById(id);
    }

    function ensureToastContainer() {
        var container = byId("toast-container");
        if (!container) {
            container = document.createElement("div");
            container.id = "toast-container";
            container.className = "cc-toast-container";
            document.body.appendChild(container);
        }
        return container;
    }

    window.showModal = window.ccShowModal = function (id) {
        var modal = byId(id);
        if (modal) {
            modal.classList.add("show");
        }
    };

    window.hideModal = window.ccHideModal = function (id) {
        var modal = byId(id);
        if (modal) {
            modal.classList.remove("show");
        }
    };

    window.showToast = window.ccShowToast = function (message, title, type) {
        var knownTypes = ["success", "error", "warning"];
        var resolvedTitle = title || "Notification";
        var resolvedType = type || "success";

        if (knownTypes.indexOf(title) !== -1 && !type) {
            resolvedTitle = title === "error" ? "Action needed" : title === "warning" ? "Heads up" : "Success";
            resolvedType = title;
        }

        var icon = resolvedType === "error" ? "error" : resolvedType === "warning" ? "warning" : "check_circle";
        var iconClass = resolvedType === "error" ? "cc-icon-danger" : resolvedType === "warning" ? "cc-icon-warning" : "cc-icon-success";
        var container = ensureToastContainer();
        var toast = document.createElement("div");
        toast.className = "cc-toast";
        toast.innerHTML = [
            '<span class="cc-icon-sm ' + iconClass + '"><span class="material-symbols-outlined text-[18px]">' + icon + '</span></span>',
            '<span><strong class="block text-sm font-extrabold text-slate-800">' + resolvedTitle + '</strong>',
            '<span class="block text-xs font-bold text-slate-500">' + message + '</span></span>'
        ].join("");
        container.appendChild(toast);
        setTimeout(function () {
            toast.style.opacity = "0";
            toast.style.transform = "translateX(20px)";
            setTimeout(function () {
                toast.remove();
            }, 260);
        }, 3000);
    };

    window.switchTab = window.ccSwitchTab = function (target, group) {
        var panels = Array.prototype.slice.call(document.querySelectorAll(".cc-tab-panel, .tab-content")).filter(function (panel) {
            return !group || panel.dataset.tabGroup === group || panel.closest('[data-tab-group="' + group + '"]');
        });
        var tabs = Array.prototype.slice.call(document.querySelectorAll(".cc-tab, .tab-btn, .tab-link")).filter(function (tab) {
            return !group || tab.dataset.tabGroup === group || tab.closest('[data-tab-group="' + group + '"]');
        });

        panels.forEach(function (panel) {
            var matches = panel.id === target || panel.id === target + "-content" || panel.dataset.tabPanel === target;
            panel.classList.toggle("is-active", matches);
            panel.classList.toggle("active", matches);
        });

        tabs.forEach(function (tab) {
            var tabTarget = tab.dataset.tabTarget || tab.getAttribute("aria-controls") || "";
            var inlineTarget = "";
            var onclick = tab.getAttribute("onclick") || "";
            var match = onclick.match(/switchTab\(['"]([^'"]+)/);
            if (match) {
                inlineTarget = match[1];
            }
            var isActive = tabTarget === target || inlineTarget === target || tab.id === "tab-" + target;
            tab.classList.toggle("is-active", isActive);
            tab.classList.toggle("active", isActive);
            if (isActive) {
                tab.setAttribute("aria-selected", "true");
            } else {
                tab.setAttribute("aria-selected", "false");
            }
        });
    };

    document.addEventListener("click", function (event) {
        var closeButton = event.target.closest("[data-modal-close]");
        if (closeButton) {
            window.hideModal(closeButton.dataset.modalClose);
        }

        var backdrop = event.target.closest(".cc-modal-backdrop.show, .modal-backdrop.show");
        if (backdrop && event.target === backdrop && backdrop.id) {
            window.hideModal(backdrop.id);
        }

        var tab = event.target.closest("[data-tab-target]");
        if (tab) {
            window.switchTab(tab.dataset.tabTarget, tab.dataset.tabGroup || "");
        }
    });
})();
