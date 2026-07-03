async function refreshAlerts() {
    const feed = document.querySelector('[data-alert-feed]');
    if (!feed) {
        return;
    }

    const response = await fetch(feed.dataset.alertFeed);
    const data = await response.json();
    const count = document.getElementById('pending-alert-count');

    if (count) {
        count.textContent = data.pending_count;
    }

    if (!data.alerts.length) {
        feed.innerHTML = '<p class="text-secondary mb-0">No pending alerts.</p>';
        return;
    }

    feed.innerHTML = data.alerts.map((alert) => `
        <div class="p-4 rounded-2xl bg-red-50 border border-red-100 mb-3">
            <div class="flex items-start justify-between gap-3">
                <strong class="text-sm text-red-900">${escapeHtml(alert.concern)}</strong>
                <span class="px-2 py-1 rounded-full bg-red-100 text-red-700 text-[10px] font-black">${escapeHtml(alert.status)}</span>
            </div>
            <div class="text-secondary small">${escapeHtml(alert.location)} · ${escapeHtml(alert.reporter_name)} · ${escapeHtml(alert.created_at)}</div>
        </div>
    `).join('');
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}

refreshAlerts();
setInterval(refreshAlerts, 5000);
