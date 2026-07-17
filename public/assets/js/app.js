/* ============================================================
   CLINiQ Design System — app.js
   Shared JavaScript utilities ported from wireframe screens 1-12
   ============================================================ */

// ============================================================
// MODAL SYSTEM
// ============================================================

/**
 * Open a modal by ID.
 * The modal element must have class="modal-backdrop" and contain
 * a child with class="modal-content".
 */
function showModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Trigger reflow then add .show for CSS transition
    requestAnimationFrame(() => {
        modal.classList.add('show');
    });

    // Close on backdrop click
    modal.addEventListener('click', function handler(e) {
        if (e.target === modal) {
            closeModal(modalId);
            modal.removeEventListener('click', handler);
        }
    });
}

/**
 * Close a modal by ID with animation.
 */
function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (!modal) return;

    modal.classList.remove('show');
    document.body.style.overflow = '';

    setTimeout(() => {
        modal.style.display = 'none';
    }, 300);
}

// Global ESC key handler for modals
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        const openModals = document.querySelectorAll('.modal-backdrop.show');
        openModals.forEach(modal => closeModal(modal.id));
    }
});


// ============================================================
// TOAST NOTIFICATION SYSTEM
// ============================================================

const TOAST_ICONS = {
    success: 'check_circle',
    error: 'error',
    info: 'info',
    warning: 'warning'
};

/**
 * Show a toast notification.
 * @param {string} message - The message to display.
 * @param {'success'|'error'|'info'|'warning'} type - Toast type.
 * @param {number} duration - Auto-dismiss duration in ms (default 3500).
 */
function showToast(message, type = 'success', duration = 3500) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast-item toast-${type}`;
    toast.innerHTML = `
        <span class="material-symbols-outlined" style="font-size:20px;">${TOAST_ICONS[type] || 'info'}</span>
        <span style="flex:1;">${escapeHtml(message)}</span>
        <button onclick="dismissToast(this.parentElement)" style="background:none;border:none;cursor:pointer;padding:4px;opacity:0.6;display:flex;">
            <span class="material-symbols-outlined" style="font-size:16px;">close</span>
        </button>
    `;

    container.appendChild(toast);

    // Auto-dismiss
    setTimeout(() => dismissToast(toast), duration);
}

/**
 * Dismiss a toast element with animation.
 */
function dismissToast(toastEl) {
    if (!toastEl || toastEl.classList.contains('removing')) return;
    toastEl.classList.add('removing');
    setTimeout(() => toastEl.remove(), 300);
}


// ============================================================
// TAB SYSTEM
// ============================================================

/**
 * Switch between tabs.
 * Expects buttons with id="tab-{name}" and content divs with id="{name}-content".
 * @param {string} tabName - The tab identifier to activate.
 */
function switchTab(tabName) {
    // Deactivate all tabs
    document.querySelectorAll('.tab-btn').forEach(btn => btn.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));

    // Activate selected
    const tabBtn = document.getElementById('tab-' + tabName);
    const tabContent = document.getElementById(tabName + '-content');

    if (tabBtn) tabBtn.classList.add('active');
    if (tabContent) tabContent.classList.add('active');

    // Update URL param without reload
    const url = new URL(window.location);
    url.searchParams.set('tab', tabName);
    window.history.replaceState({}, '', url);
}

/**
 * Initialize tabs from URL parameter on page load.
 */
function initTabsFromURL() {
    const params = new URLSearchParams(window.location.search);
    const tab = params.get('tab');
    if (tab) {
        const tabBtn = document.getElementById('tab-' + tab);
        if (tabBtn) switchTab(tab);
    }
}


// ============================================================
// SEARCH & FILTER SYSTEM
// ============================================================

/**
 * Initialize client-side search filtering for a table.
 * Rows must have a data-search attribute containing searchable text.
 * @param {string} inputId - ID of the search input.
 * @param {string} tbodySelector - CSS selector for the tbody to filter.
 */
function initTableSearch(inputId, tbodySelector) {
    const input = document.getElementById(inputId);
    if (!input) return;

    input.addEventListener('input', () => {
        const query = input.value.toLowerCase().trim();
        const rows = document.querySelectorAll(`${tbodySelector} tr[data-search]`);

        rows.forEach(row => {
            const searchText = (row.dataset.search || '').toLowerCase();
            row.style.display = searchText.includes(query) ? '' : 'none';
        });

        updateFilterCounts(tbodySelector);
    });
}

/**
 * Filter table rows by a status value.
 * Rows must have a data-status attribute.
 * @param {string} status - The status to filter by, or 'all' for no filter.
 * @param {string} tbodySelector - CSS selector for the tbody.
 */
function setStatusFilter(status, tbodySelector) {
    // Update tab UI
    document.querySelectorAll('.status-tab').forEach(tab => {
        tab.classList.toggle('active', tab.dataset.filter === status);
    });

    // Filter rows
    const rows = document.querySelectorAll(`${tbodySelector} tr[data-status]`);
    rows.forEach(row => {
        if (status === 'all') {
            row.style.display = '';
        } else {
            row.style.display = row.dataset.status === status ? '' : 'none';
        }
    });
}

/**
 * Update visible count badges after filtering.
 */
function updateFilterCounts(tbodySelector) {
    const rows = document.querySelectorAll(`${tbodySelector} tr[data-status]`);
    const counts = { all: 0 };

    rows.forEach(row => {
        if (row.style.display !== 'none') {
            counts.all++;
            const status = row.dataset.status;
            counts[status] = (counts[status] || 0) + 1;
        }
    });

    Object.keys(counts).forEach(key => {
        const badge = document.getElementById('count-' + key);
        if (badge) badge.textContent = counts[key];
    });
}


// ============================================================
// CONFIRMATION DIALOG
// ============================================================

/**
 * Show a confirmation modal and execute callback on confirm.
 * Creates a temporary modal if one doesn't exist.
 * @param {string} title - Confirmation title.
 * @param {string} message - Confirmation message.
 * @param {Function} onConfirm - Callback executed on confirm.
 * @param {'danger'|'primary'} type - Button type (default 'danger').
 */
function confirmAction(title, message, onConfirm, type = 'danger') {
    let modal = document.getElementById('confirmActionModal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'confirmActionModal';
        modal.className = 'modal-backdrop';
        document.body.appendChild(modal);
    }

    const btnClass = type === 'danger' ? 'btn-danger' : 'btn-primary';

    modal.innerHTML = `
        <div class="modal-content bg-white rounded-[2rem] p-8 w-full max-w-md shadow-2xl">
            <div class="flex items-start gap-4 mb-6">
                <div class="w-12 h-12 rounded-2xl ${type === 'danger' ? 'bg-red-50 text-red-600' : 'bg-blue-50 text-primary'} flex items-center justify-center shrink-0">
                    <span class="material-symbols-outlined">${type === 'danger' ? 'warning' : 'help'}</span>
                </div>
                <div>
                    <h3 class="font-headline text-lg font-extrabold text-[#1c2a59] mb-1">${escapeHtml(title)}</h3>
                    <p class="text-sm font-bold text-slate-500">${escapeHtml(message)}</p>
                </div>
            </div>
            <div class="flex justify-end gap-3">
                <button onclick="closeModal('confirmActionModal')" class="btn btn-ghost">Cancel</button>
                <button id="confirmActionBtn" class="btn ${btnClass}">Confirm</button>
            </div>
        </div>
    `;

    document.getElementById('confirmActionBtn').addEventListener('click', () => {
        closeModal('confirmActionModal');
        if (typeof onConfirm === 'function') onConfirm();
    });

    showModal('confirmActionModal');
}

function submitConfirmableAction(button) {
    if (!button || button.disabled) return;

    const formId = button.getAttribute('form');
    const form = formId ? document.getElementById(formId) : button.form;
    if (!form) return;

    if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
        return;
    }

    const title = button.dataset.confirmTitle || 'Confirm action?';
    const message = button.dataset.confirmMessage || 'Please confirm before continuing.';
    const type = button.dataset.confirmType || 'primary';
    const toast = button.dataset.confirmToast || '';

    confirmAction(title, message, () => {
        if (toast) {
            showToast(toast, 'info', 1200);
        }

        form.dataset.confirmed = '1';
        try {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit(button);
            } else {
                form.submit();
            }
        } catch (error) {
            form.submit();
        }
        setTimeout(() => {
            delete form.dataset.confirmed;
        }, 0);
    }, type);
}

document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-confirm-submit]');
    if (!button) return;

    event.preventDefault();
    submitConfirmableAction(button);
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!form.matches('[data-confirm-submit]') || form.dataset.confirmed === '1') return;

    event.preventDefault();
    const title = form.dataset.confirmTitle || 'Confirm action?';
    const message = form.dataset.confirmMessage || 'Please confirm before continuing.';
    const type = form.dataset.confirmType || 'primary';
    const toast = form.dataset.confirmToast || '';

    confirmAction(title, message, () => {
        if (toast) {
            showToast(toast, 'info', 1200);
        }

        form.dataset.confirmed = '1';
        try {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.submit();
            }
        } catch (error) {
            form.submit();
        }
        setTimeout(() => {
            delete form.dataset.confirmed;
        }, 0);
    }, type);
});


// ============================================================
// AVATAR HELPER
// ============================================================

const AVATAR_COLORS = ['avatar-blue', 'avatar-emerald', 'avatar-amber', 'avatar-red', 'avatar-indigo', 'avatar-slate'];

/**
 * Get a deterministic avatar color class from a name string.
 * @param {string} name
 * @returns {string} CSS class name
 */
function getAvatarColor(name) {
    let hash = 0;
    for (let i = 0; i < name.length; i++) {
        hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    return AVATAR_COLORS[Math.abs(hash) % AVATAR_COLORS.length];
}

/**
 * Get initials from a name string (max 2 chars).
 * @param {string} name
 * @returns {string}
 */
function getInitials(name) {
    return name.split(/\s+/).filter(Boolean).map(w => w[0]).slice(0, 2).join('').toUpperCase();
}


// ============================================================
// UTILITY FUNCTIONS
// ============================================================

function initSidebarToggle() {
    const shell = document.querySelector('.app-shell');
    const toggle = document.getElementById('sidebarToggle');
    if (!shell || !toggle) return;

    const icon = toggle.querySelector('.material-symbols-outlined');
    const storageKey = 'cliniq_sidebar_collapsed';

    function syncToggleState(collapsed) {
        shell.classList.toggle('sidebar-collapsed', collapsed);
        toggle.setAttribute('aria-expanded', String(!collapsed));
        toggle.setAttribute('aria-label', collapsed ? 'Open sidebar' : 'Collapse sidebar');
        if (icon) {
            icon.textContent = collapsed ? 'left_panel_open' : 'left_panel_close';
        }
    }

    syncToggleState(localStorage.getItem(storageKey) === 'true');

    toggle.addEventListener('click', () => {
        const collapsed = !shell.classList.contains('sidebar-collapsed');
        localStorage.setItem(storageKey, String(collapsed));
        syncToggleState(collapsed);
    });
}

function initSettingsTabs() {
    if (document.documentElement.dataset.cliniqSettingsTabsReady === '1') return;
    document.documentElement.dataset.cliniqSettingsTabsReady = '1';

    document.addEventListener('click', (event) => {
        const button = event.target.closest('[data-settings-tab]');
        if (!button) return;

        event.preventDefault();
        const tab = button.dataset.settingsTab;
        document.querySelectorAll('[data-settings-tab]').forEach((item) => {
            item.classList.toggle('active', item === button);
        });
        document.querySelectorAll('.settings-tab-panel').forEach((panel) => {
            panel.classList.toggle('active', panel.id === 'settings-' + tab);
        });

        const url = new URL(window.location.href);
        url.searchParams.set('tab', tab);
        window.history.replaceState({}, '', url);
    });
}

/**
 * Escape HTML entities to prevent XSS.
 */
function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

/**
 * Format a date string for display.
 * @param {string} dateStr - ISO date string or MySQL datetime.
 * @returns {string}
 */
function formatDate(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

/**
 * Format a datetime string for display.
 * @param {string} dateStr
 * @returns {string}
 */
function formatDateTime(dateStr) {
    if (!dateStr) return '';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
        + ' ' + d.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit' });
}


// ============================================================
// AJAX ALERT POLLING (existing functionality)
// ============================================================

async function refreshAlerts() {
    const feed = document.querySelector('[data-alert-feed]');
    const statusUrl = document.body ? document.body.dataset.alertStatusUrl : '';
    const requestUrl = statusUrl || (feed ? feed.dataset.alertFeed : '');
    if (!requestUrl) {
        return;
    }

    try {
        const response = await fetch(requestUrl);
        const data = await response.json();
        const count = document.getElementById('pending-alert-count');
        const pendingCount = Number(data.pending_count || 0);
        const criticalCount = Number(data.critical_count || 0);

        if (document.body) {
            document.body.classList.toggle('has-active-alerts', pendingCount > 0);
            document.body.dataset.activeAlertCount = String(pendingCount);
            document.body.dataset.criticalAlertCount = String(criticalCount);
        }

        document.querySelectorAll('.app-alert-link.has-alerts').forEach((link) => {
            link.classList.toggle('has-active-alerts', pendingCount > 0);
        });

        if (count) {
            count.textContent = data.pending_count;
        }

        if (!feed) {
            return;
        }

        if (!data.alerts.length) {
            feed.innerHTML = '<p class="text-sm font-bold text-slate-500 mb-0">No pending alerts.</p>';
            return;
        }

        feed.innerHTML = data.alerts.map((alert) => `
            <div class="p-4 rounded-2xl bg-red-50 border border-red-100 mb-3">
                <div class="flex items-start justify-between gap-3">
                    <strong class="text-sm text-red-900">${escapeHtml(alert.concern)}</strong>
                    <span class="badge badge-pending">${escapeHtml(alert.status)}</span>
                </div>
                <p class="text-xs font-bold text-red-700 mb-0 mt-1">${escapeHtml(alert.location)} · ${escapeHtml(alert.reporter_name)} · ${escapeHtml(alert.created_at)}</p>
            </div>
        `).join('');
    } catch (e) {
        // Silently fail on network errors
    }
}


// ============================================================
// SAME-PAGE AJAX NAVIGATION
// ============================================================

function cliniqIsSameOrigin(url) {
    return url.origin === window.location.origin;
}

function cliniqIsSamePage(url) {
    return cliniqIsSameOrigin(url) && url.pathname === window.location.pathname;
}

function cliniqShowFetchedFlashes(doc) {
    doc.querySelectorAll('#flash-toasts [data-flash]').forEach((flash) => {
        showToast(flash.dataset.message || '', flash.dataset.flash || 'info');
    });
}

function cliniqReplaceTopbarState(doc) {
    const nextMeta = doc.querySelector('.app-topbar-meta');
    const currentMeta = document.querySelector('.app-topbar-meta');
    if (nextMeta && currentMeta) {
        currentMeta.replaceWith(nextMeta);
    }

    const nextNav = doc.querySelector('.app-nav');
    const currentNav = document.querySelector('.app-nav');
    if (nextNav && currentNav) {
        currentNav.replaceWith(nextNav);
    }
}

function cliniqCloseOpenModals() {
    document.querySelectorAll('.modal-backdrop.show').forEach((modal) => {
        if (modal.id === 'confirmActionModal') {
            modal.remove();
            return;
        }
        if (modal.id && typeof closeModal === 'function') {
            closeModal(modal.id);
        } else {
            modal.classList.remove('show');
            modal.style.display = 'none';
        }
    });
}

function cliniqRemoveStaleConfirmModal() {
    const modal = document.getElementById('confirmActionModal');
    if (modal) {
        modal.remove();
    }
}

function cliniqAfterContentSwap(root) {
    if (typeof window.cliniqInitAgGrids === 'function') {
        window.cliniqInitAgGrids(root || document);
    }
    initStudentIdFormatting(root || document);
    initTabsFromURL();
    refreshAlerts();
    document.dispatchEvent(new CustomEvent('cliniq:page-content-replaced', {
        detail: { root: root || document }
    }));
}

function cliniqRenderFetchedPage(html, finalUrl, options = {}) {
    const doc = new DOMParser().parseFromString(html, 'text/html');
    const nextContent = doc.querySelector('[data-cliniq-page-content]');
    const currentContent = document.querySelector('[data-cliniq-page-content]');

    if (!nextContent || !currentContent) {
        window.location.assign(finalUrl);
        return;
    }

    currentContent.replaceWith(nextContent);
    cliniqReplaceTopbarState(doc);
    cliniqShowFetchedFlashes(doc);
    cliniqCloseOpenModals();
    cliniqRemoveStaleConfirmModal();

    const title = doc.querySelector('title');
    if (title) {
        document.title = title.textContent;
    }

    if (options.pushHistory) {
        window.history.pushState({ cliniqAjax: true }, '', finalUrl);
    } else {
        window.history.replaceState({ cliniqAjax: true }, '', finalUrl);
    }

    cliniqAfterContentSwap(nextContent);
}

async function cliniqFetchPage(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
    });
    const html = await response.text();
    cliniqRenderFetchedPage(html, response.url || url, options);
}

function cliniqFormData(form, submitter) {
    try {
        return submitter ? new FormData(form, submitter) : new FormData(form);
    } catch (error) {
        const formData = new FormData(form);
        if (submitter && submitter.name && !submitter.disabled) {
            formData.append(submitter.name, submitter.value || '');
        }
        return formData;
    }
}

async function cliniqSubmitFormAjax(form, submitter) {
    const method = (form.method || 'GET').toUpperCase();
    let url = new URL(form.action || window.location.href, window.location.href);
    const formData = cliniqFormData(form, submitter);
    const fetchOptions = {
        method,
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'fetch' }
    };

    if (method === 'GET') {
        const params = new URLSearchParams(formData);
        url.search = params.toString();
    } else {
        fetchOptions.body = formData;
    }

    const response = await fetch(url, fetchOptions);
    const finalUrl = new URL(response.url || url.href, window.location.href);

    if (!cliniqIsSamePage(finalUrl)) {
        window.location.assign(finalUrl.href);
        return;
    }

    cliniqRenderFetchedPage(await response.text(), finalUrl.href, { pushHistory: method === 'GET' });
}

function initSamePageAjax() {
    if (document.documentElement.dataset.cliniqSamePageAjax === 'ready') return;
    document.documentElement.dataset.cliniqSamePageAjax = 'ready';

    document.addEventListener('click', (event) => {
        if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;

        const link = event.target.closest('a[href]');
        if (!link || link.target || link.hasAttribute('download') || link.dataset.noAjax === 'true') return;

        const url = new URL(link.href, window.location.href);
        if (!cliniqIsSamePage(url) || url.hash) return;

        event.preventDefault();
        cliniqFetchPage(url.href, { pushHistory: true }).catch(() => {
            window.location.assign(url.href);
        });
    });

    document.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || event.defaultPrevented || form.dataset.noAjax === 'true' || form.target) return;
        if (!document.querySelector('[data-cliniq-page-content]') || !form.closest('.app-main')) return;

        event.preventDefault();
        cliniqSubmitFormAjax(form, event.submitter).catch(() => {
            form.submit();
        });
    });

    window.addEventListener('popstate', () => {
        cliniqFetchPage(window.location.href, { pushHistory: false }).catch(() => window.location.reload());
    });
}

function formatStudentId(value) {
    const digits = String(value || '').replace(/\D/g, '').slice(0, 7);
    if (digits.length <= 2) {
        return digits;
    }
    return `${digits.slice(0, 2)}-${digits.slice(2)}`;
}

function studentIdFormatIsActive(input) {
    const sourceId = input.dataset.studentCategorySource;
    if (!sourceId) {
        return true;
    }

    const source = document.getElementById(sourceId);
    return !source || source.value === 'Student';
}

function initStudentIdFormatting(root = document) {
    const scope = root.querySelectorAll ? root : document;
    scope.querySelectorAll('[data-student-id-format], #student-id').forEach((input) => {
        if (!(input instanceof HTMLInputElement) || input.dataset.studentIdFormatterReady === '1') {
            return;
        }

        input.dataset.studentIdFormatterReady = '1';
        input.dataset.originalMaxLength = input.getAttribute('maxlength') || '';
        input.inputMode = 'numeric';
        input.placeholder = input.placeholder || '00-00000';

        const syncPattern = () => {
            const active = studentIdFormatIsActive(input);
            input.pattern = active ? '\\d{2}-\\d{5}' : '';
            input.title = active ? 'Use the format 00-00000.' : '';
            input.inputMode = active ? 'numeric' : 'text';
            if (active) {
                input.maxLength = 8;
            } else if (input.dataset.originalMaxLength) {
                input.maxLength = Number(input.dataset.originalMaxLength);
            } else {
                input.removeAttribute('maxlength');
            }
        };

        input.addEventListener('input', () => {
            if (studentIdFormatIsActive(input)) {
                input.value = formatStudentId(input.value);
            }
        });

        const sourceId = input.dataset.studentCategorySource;
        if (sourceId) {
            document.getElementById(sourceId)?.addEventListener('change', syncPattern);
        }

        syncPattern();
    });
}


// ============================================================
// AUTO-INITIALIZATION
// ============================================================

document.addEventListener('DOMContentLoaded', () => {
    // Sidebar open/close control
    initSidebarToggle();

    // Remove any stale confirmation shell left by an interrupted form action.
    cliniqRemoveStaleConfirmModal();

    // Settings page vertical tab controls
    initSettingsTabs();

    // Initialize tabs from URL
    initTabsFromURL();

    // Same-page links/forms update content without a full browser reload
    initSamePageAjax();

    // Student IDs use 00-00000 format.
    initStudentIdFormatting();

    // Start alert polling
    refreshAlerts();
    setInterval(refreshAlerts, 5000);

    // Auto-show flash toasts (rendered by PHP into data attributes)
    const flashContainer = document.getElementById('flash-toasts');
    if (flashContainer) {
        const toasts = flashContainer.querySelectorAll('[data-flash]');
        toasts.forEach(el => {
            showToast(el.dataset.message, el.dataset.flash);
            el.remove();
        });
        flashContainer.remove();
    }
});
