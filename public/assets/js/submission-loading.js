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
    }

    function shouldShow(form) {
        if (!(form instanceof HTMLFormElement)) return false;
        if (form.dataset.noLoading === 'true' || form.hasAttribute('data-no-loading')) return false;
        return form.enctype === 'multipart/form-data' ||
            form.matches('[data-email-action], [data-loading-form]') ||
            !!form.querySelector('input[type="file"]') ||
            !!form.querySelector('input[name="action"][value*="code"], input[name="action"][value*="email"]');
    }

    document.addEventListener('submit', function (event) {
        const form = event.target;
        if (!shouldShow(form) || event.defaultPrevented) return;
        showLoading(form);
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.dataset.originalText = button.textContent;
            if (button.tagName === 'BUTTON') button.textContent = 'Please wait…';
        });
    }, true);

    const style = document.createElement('style');
    style.textContent = '[data-cliniq-loading]{position:fixed;inset:0;z-index:2147483647;display:grid;place-items:center;background:rgba(15,35,25,.34);backdrop-filter:blur(2px)}.cliniq-loading-card{display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;max-width:min(90vw,360px);padding:1.1rem 1.35rem;border-radius:16px;background:#fff;color:#173326;box-shadow:0 16px 50px rgba(0,0,0,.2);font:600 15px/1.3 Inter,system-ui,sans-serif}.cliniq-loading-subtitle{width:100%;margin-left:2.2rem;color:#66786d;font-size:12px;font-weight:500}.cliniq-loading-spinner{width:20px;height:20px;border:3px solid #d9e8de;border-top-color:#398252;border-radius:50%;animation:cliniq-spin .8s linear infinite}@keyframes cliniq-spin{to{transform:rotate(360deg)}}';
    document.head.appendChild(style);
})();
