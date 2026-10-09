(function () {
    'use strict';
    if (window.__cliniqSubmissionLoading) return;
    window.__cliniqSubmissionLoading = true;

    function showLoading(form) {
        if (document.querySelector('[data-cliniq-loading]')) return;
        const overlay = document.createElement('div');
        overlay.dataset.cliniqLoading = '1';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.innerHTML = '<div class="cliniq-loading-card"><span class="cliniq-loading-spinner" aria-hidden="true"></span><strong>' +
            (form?.querySelector('[data-loading-message]')?.dataset.loadingMessage || 'Processing…') +
            '</strong><span class="cliniq-loading-subtitle">Please wait.</span></div>';
        document.body.appendChild(overlay);
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
            if (button.disabled) return;
            button.disabled = true;
            button.dataset.cliniqLoadingDisabled = '1';
            if (button.tagName === 'BUTTON') {
                button.dataset.cliniqLoadingHtml = button.innerHTML;
                button.textContent = 'Please wait…';
            }
        });
    }

    function clearLoading() {
        document.querySelector('[data-cliniq-loading]')?.remove();
        document.querySelectorAll('[data-cliniq-loading-disabled="1"]').forEach((button) => {
            button.disabled = false;
            delete button.dataset.cliniqLoadingDisabled;
            if (button.tagName === 'BUTTON' && button.dataset.cliniqLoadingHtml !== undefined) {
                button.innerHTML = button.dataset.cliniqLoadingHtml;
                delete button.dataset.cliniqLoadingHtml;
            }
        });
    }

    function shouldShow(form) {
        if (!(form instanceof HTMLFormElement)) return false;
        if (form.matches('[data-no-loading]')) return false;
        if (form.dataset.loadingAfterConfirm === 'true' && form.dataset.confirmed !== '1') return false;
        if (form.dataset.noAjax !== 'true' && document.querySelector('[data-cliniq-page-content]') && form.closest('.app-main')) return false;
        if ((form.getAttribute('method') || 'get').toUpperCase() !== 'POST') return false;
        if (form.target && form.target !== '_self') return false;

        try {
            return new URL(form.getAttribute('action') || window.location.href, window.location.href).origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!shouldShow(form) || event.defaultPrevented) return;
        window.setTimeout(() => {
            if (!event.defaultPrevented) showLoading(form);
        }, 0);
    }, true);

    window.addEventListener('pageshow', clearLoading);

    const style = document.createElement('style');
    style.textContent = '[data-cliniq-loading]{position:fixed;inset:0;z-index:2147483647;display:grid;place-items:center;background:rgba(15,35,25,.34);backdrop-filter:blur(2px)}.cliniq-loading-card{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;max-width:min(90vw,360px);padding:1.1rem 1.35rem;border-radius:16px;background:#fff;color:#173326;box-shadow:0 16px 50px rgba(0,0,0,.2);font:600 15px/1.3 Inter,system-ui,sans-serif}.cliniq-loading-subtitle{width:100%;margin-left:2.2rem;color:#66786d;font-size:12px;font-weight:500}.cliniq-loading-spinner{width:20px;height:20px;border:3px solid #d9e8de;border-top-color:#398252;border-radius:50%;animation:cliniq-spin .8s linear infinite}@keyframes cliniq-spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
})();
