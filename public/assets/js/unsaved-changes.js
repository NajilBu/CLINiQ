(function () {
    'use strict';

    const dirtyForms = new Set();
    const isTrackedForm = (form) => form instanceof HTMLFormElement
        && (form.getAttribute('method') || 'get').toLowerCase() === 'post'
        && !form.hasAttribute('data-no-discard-warning');
    const isEditable = (field) => field instanceof HTMLInputElement
        || field instanceof HTMLSelectElement
        || field instanceof HTMLTextAreaElement;
    const hasUnsavedChanges = (root = document) => Array.from(dirtyForms).some((form) => {
        if (!form.isConnected) {
            dirtyForms.delete(form);
            return false;
        }
        return root === document ? true : root.contains(form);
    });
    const clearUnsavedChanges = (root = document) => {
        dirtyForms.forEach((form) => {
            if (root !== document && !root.contains(form)) return;
            form.reset();
            form.dispatchEvent(new CustomEvent('cliniq:discarded'));
            dirtyForms.delete(form);
        });
    };
    window.cliniqMarkChangesSaved = (root = document) => {
        dirtyForms.forEach((form) => {
            if (root === document || root.contains(form)) dirtyForms.delete(form);
        });
    };

    window.cliniqConfirmDiscardChanges = (root, onDiscard) => {
        if (!hasUnsavedChanges(root)) {
            onDiscard();
            return true;
        }

        const discard = () => {
            clearUnsavedChanges(root);
            onDiscard();
        };
        const title = 'Discard unsaved changes?';
        const message = 'Your edits have not been saved. Discard them?';
        if (typeof window.confirmAction === 'function') {
            window.confirmAction(title, message, discard, 'danger', 'Discard');
        } else if (window.confirm(`${title}\n\n${message}`)) {
            discard();
        }
        return false;
    };

    document.addEventListener('input', (event) => {
        const field = event.target;
        const form = isEditable(field) ? field.form : null;
        if (isTrackedForm(form)) dirtyForms.add(form);
    }, true);
    document.addEventListener('change', (event) => {
        const field = event.target;
        const form = isEditable(field) ? field.form : null;
        if (isTrackedForm(form)) dirtyForms.add(form);
    }, true);
    document.addEventListener('reset', (event) => {
        const form = event.target;
        if (isTrackedForm(form)) window.setTimeout(() => dirtyForms.delete(form));
    }, true);
    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!isTrackedForm(form)) return;
        queueMicrotask(() => {
            if (!event.defaultPrevented) dirtyForms.delete(form);
        });
    }, true);

    window.addEventListener('beforeunload', (event) => {
        if (!hasUnsavedChanges()) return;
        event.preventDefault();
        event.returnValue = '';
    });

    document.addEventListener('click', (event) => {
        const close = event.target.closest('[data-discard-close]');
        if (!close) return;
        const modal = document.getElementById(close.dataset.discardClose || '');
        if (!modal) return;
        event.preventDefault();
        window.cliniqConfirmDiscardChanges(modal, () => modal.classList.add('hidden'));
    });

    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || event.defaultPrevented || !hasUnsavedChanges()
            || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
            || link.target === '_blank' || link.hasAttribute('download')
            || link.hasAttribute('data-no-discard-warning')) return;
        const url = new URL(link.href, window.location.href);
        if (url.origin !== window.location.origin || url.href === window.location.href || url.hash !== '' && url.pathname === window.location.pathname && url.search === window.location.search) return;
        event.preventDefault();
        window.cliniqConfirmDiscardChanges(document, () => window.location.assign(url.href));
    });
})();
