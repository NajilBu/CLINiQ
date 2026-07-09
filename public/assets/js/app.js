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
    if (!feed) {
        return;
    }

    try {
        const response = await fetch(feed.dataset.alertFeed);
        const data = await response.json();
        const count = document.getElementById('pending-alert-count');

        if (count) {
            count.textContent = data.pending_count;
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
// AUTO-INITIALIZATION
// ============================================================

document.addEventListener('DOMContentLoaded', () => {
    // Sidebar open/close control
    initSidebarToggle();

    // Initialize tabs from URL
    initTabsFromURL();

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
