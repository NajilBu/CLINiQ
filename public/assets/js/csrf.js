(() => {
    'use strict';

    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    if (!token) return;

    const sameOrigin = (url) => {
        try {
            return new URL(url || window.location.href, window.location.href).origin === window.location.origin;
        } catch (_) {
            return false;
        }
    };

    const secureForm = (form) => {
        if (!(form instanceof HTMLFormElement) || form.method.toUpperCase() !== 'POST' || !sameOrigin(form.action)) return;
        let input = form.querySelector('input[name="_csrf"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_csrf';
            form.prepend(input);
        }
        input.value = token;
    };

    document.querySelectorAll('form').forEach(secureForm);
    document.addEventListener('submit', (event) => secureForm(event.target), true);
    new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach((node) => {
            if (!(node instanceof Element)) return;
            if (node.matches('form')) secureForm(node);
            node.querySelectorAll?.('form').forEach(secureForm);
        }));
    }).observe(document.documentElement, { childList: true, subtree: true });

    const originalFetch = window.fetch.bind(window);
    window.fetch = (input, init = {}) => {
        const request = input instanceof Request ? input : null;
        const method = String(init.method || request?.method || 'GET').toUpperCase();
        const url = request?.url || String(input);
        if (sameOrigin(url) && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            const headers = new Headers(init.headers || request?.headers || {});
            headers.set('X-CSRF-Token', token);
            init = { ...init, headers };
        }
        return originalFetch(input, init);
    };

    const originalOpen = XMLHttpRequest.prototype.open;
    const originalSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.open = function (method, url, ...rest) {
        this.__cliniqCsrf = ['POST', 'PUT', 'PATCH', 'DELETE'].includes(String(method).toUpperCase()) && sameOrigin(url);
        return originalOpen.call(this, method, url, ...rest);
    };
    XMLHttpRequest.prototype.send = function (...args) {
        if (this.__cliniqCsrf) this.setRequestHeader('X-CSRF-Token', token);
        return originalSend.apply(this, args);
    };
})();
