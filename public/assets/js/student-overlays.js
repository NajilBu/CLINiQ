(() => {
    const selector = '[data-student-overlay]';
    const focusable = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    const previousFocus = new WeakMap();

    const isOpen = (overlay) => overlay instanceof HTMLDialogElement
        ? overlay.open
        : !overlay.hidden && !overlay.classList.contains('hidden') && (overlay.classList.contains('active') || overlay.id === 'change-password-modal' || overlay.id === 'patient-profile-photo-modal' || overlay.id === 'ape-upload-confirm-modal');

    const focusFirst = (overlay) => {
        const target = overlay.querySelector('[autofocus], input:not([type="hidden"]), button, [href]');
        target?.focus({ preventScroll: true });
    };

    const rememberFocus = (overlay) => {
        if (!isOpen(overlay) || previousFocus.has(overlay)) return;
        previousFocus.set(overlay, document.activeElement instanceof HTMLElement ? document.activeElement : null);
        window.setTimeout(() => focusFirst(overlay));
    };

    const restoreFocus = (overlay) => {
        if (isOpen(overlay)) return;
        const trigger = previousFocus.get(overlay);
        previousFocus.delete(overlay);
        trigger?.focus?.({ preventScroll: true });
    };

    const sync = () => document.querySelectorAll(selector).forEach((overlay) => {
        const open = isOpen(overlay);
        if (!(overlay instanceof HTMLDialogElement) && overlay.getAttribute('aria-hidden') !== String(!open)) {
            overlay.setAttribute('aria-hidden', String(!open));
        }
        if (open) rememberFocus(overlay);
        else restoreFocus(overlay);
    });

    const observer = new MutationObserver(sync);
    document.querySelectorAll(selector).forEach((overlay) => observer.observe(overlay, { attributes: true, attributeFilter: ['class', 'hidden', 'open', 'aria-hidden'] }));
    document.addEventListener('toggle', sync, true);
    document.addEventListener('click', () => window.setTimeout(sync), true);
    document.addEventListener('keydown', (event) => {
        const overlay = [...document.querySelectorAll(selector)].reverse().find(isOpen);
        if (!overlay || event.key !== 'Tab') return;
        const controls = [...overlay.querySelectorAll(focusable)].filter((item) => item.offsetParent !== null);
        if (!controls.length) return;
        const first = controls[0];
        const last = controls.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    sync();
})();
