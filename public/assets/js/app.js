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
        <div class="border rounded p-3 mb-2 bg-light">
            <div class="d-flex justify-content-between">
                <strong>${escapeHtml(alert.concern)}</strong>
                <span class="badge text-bg-danger">${escapeHtml(alert.status)}</span>
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
