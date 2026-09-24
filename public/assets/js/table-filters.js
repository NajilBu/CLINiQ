(() => {
    'use strict';

    const controllers = new WeakMap();

    const buildUrl = (form) => {
        const url = new URL(form.action || window.location.href, window.location.href);
        const data = new FormData(form);
        url.search = '';
        for (const [key, value] of data.entries()) {
            if (String(value).trim() !== '') url.searchParams.append(key, value);
        }
        return url;
    };

    const replaceRegion = (form, html, url) => {
        const regionId = form.dataset.filterRegion;
        const current = regionId ? document.querySelector(`[data-filter-region="${CSS.escape(regionId)}"]`) : null;
        if (!current) return false;
        const parsed = new DOMParser().parseFromString(html, 'text/html');
        const replacement = parsed.querySelector(`[data-filter-region="${CSS.escape(regionId)}"]`);
        if (!replacement) return false;
        current.replaceWith(replacement);
        if (url) window.history.pushState({ tableFilter: true }, '', url.href);
        document.dispatchEvent(new CustomEvent('table-filter:updated', { detail: { regionId, url: url?.href || window.location.href } }));
        return true;
    };

    const load = async (form, { push = true, requestUrl = null } = {}) => {
        const regionId = form.dataset.filterRegion;
        const region = regionId ? document.querySelector(`[data-filter-region="${CSS.escape(regionId)}"]`) : null;
        if (!region) return false;
        const url = requestUrl ? new URL(requestUrl, window.location.href) : buildUrl(form);
        url.searchParams.set('format', 'fragment');
        const previous = region.innerHTML;
        const controller = new AbortController();
        const old = controllers.get(form);
        if (old) old.abort();
        controllers.set(form, controller);
        region.setAttribute('aria-busy', 'true');
        region.classList.add('opacity-60', 'pointer-events-none');
        try {
            const response = await fetch(url.href, { headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'text/html' }, signal: controller.signal });
            if (!response.ok) throw new Error(`Filter request failed (${response.status})`);
            const html = await response.text();
            const visibleUrl = new URL(url.href);
            visibleUrl.searchParams.delete('format');
            if (!replaceRegion(form, html, push ? visibleUrl : null)) throw new Error('Filter response did not contain the expected table region.');
            return true;
        } catch (error) {
            if (error.name === 'AbortError') return false;
            region.innerHTML = `${previous}<div class="mt-3 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-700" role="alert">Unable to refresh this table. <button type="button" class="underline font-bold" data-table-filter-retry>Retry</button></div>`;
            region.querySelector('[data-table-filter-retry]')?.addEventListener('click', () => load(form, { push, requestUrl }));
            return false;
        } finally {
            const active = document.querySelector(`[data-filter-region="${CSS.escape(regionId)}"]`);
            active?.removeAttribute('aria-busy');
            active?.classList.remove('opacity-60', 'pointer-events-none');
        }
    };

    const bind = (root = document) => {
        root.querySelectorAll('form[method="get"]:not([data-no-ajax]):not([data-table-filter])').forEach((form) => {
            const candidate = form.closest('section, details, .clinic-card');
            if (candidate?.querySelector('table')) {
                const regionId = candidate.id || `table-filter-${Math.random().toString(36).slice(2)}`;
                candidate.id = regionId;
                candidate.dataset.filterRegion = regionId;
                form.dataset.tableFilter = regionId;
                form.dataset.filterRegion = regionId;
            }
        });
        root.querySelectorAll('form[data-table-filter][data-filter-region], [data-filter-region] form').forEach((form) => {
            if (!form.dataset.filterRegion) {
                const region = form.closest('[data-filter-region]');
                if (!region) return;
                form.dataset.tableFilter = form.dataset.tableFilter || region.dataset.filterRegion;
                form.dataset.filterRegion = region.dataset.filterRegion;
            }
            if (form.dataset.tableFilterBound === '1') return;
            form.dataset.tableFilterBound = '1';
            let timer = null;
            form.addEventListener('submit', (event) => {
                event.preventDefault();
                load(form);
            });
            form.querySelectorAll('select, input[type="date"], input[type="radio"], input[type="checkbox"]').forEach((input) => input.addEventListener('change', () => form.requestSubmit()));
            form.querySelectorAll('input[type="search"], input[name="q"], input[name="search"]').forEach((input) => input.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(() => form.requestSubmit(), 300); }));
        });
        root.querySelectorAll('[data-table-filter-link]').forEach((link) => {
            if (link.dataset.tableFilterLinkBound === '1') return;
            const region = link.closest('[data-filter-region]');
            const form = region?.querySelector('form[data-table-filter], form[data-filter-region]');
            if (!form || !link.href) return;
            link.dataset.tableFilterLinkBound = '1';
            link.addEventListener('click', (event) => {
                if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                event.preventDefault();
                load(form, { requestUrl: link.href });
            });
        });
    };

    window.addEventListener('popstate', () => {
        document.querySelectorAll('form[data-table-filter][data-filter-region], [data-filter-region] form').forEach((form) => {
            if (!form.dataset.filterRegion) form.dataset.filterRegion = form.closest('[data-filter-region]')?.dataset.filterRegion || '';
            if (form.dataset.filterRegion) load(form, { push: false });
        });
    });
    document.addEventListener('table-filter:updated', () => bind());
    bind();
})();
