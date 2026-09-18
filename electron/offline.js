const retryButton = document.getElementById('retryButton');
const connectionStatus = document.getElementById('connectionStatus');
const statusDot = document.getElementById('statusDot');
const clinicAddress = document.getElementById('clinicAddress');
const themeToggle = document.getElementById('themeToggle');
const themeToggleLabel = document.getElementById('themeToggleLabel');
const themeToggleIcon = themeToggle?.querySelector('.theme-toggle-icon');
const themeKey = 'cliniq-desktop-dark-mode';

function syncTheme(enabled) {
    document.documentElement.dataset.theme = enabled ? 'dark' : 'light';
    themeToggle?.setAttribute('aria-pressed', enabled ? 'true' : 'false');
    themeToggle?.setAttribute('aria-label', enabled ? 'Switch to light mode' : 'Switch to dark mode');
    if (themeToggleLabel) themeToggleLabel.textContent = enabled ? 'Light mode' : 'Dark mode';
    if (themeToggleIcon) themeToggleIcon.textContent = enabled ? '☀' : '☾';
}

let darkMode = false;
try {
    const saved = localStorage.getItem(themeKey);
    darkMode = saved === '1' || (saved === null && window.matchMedia('(prefers-color-scheme: dark)').matches);
} catch (error) {}
syncTheme(darkMode);
themeToggle?.addEventListener('click', () => {
    darkMode = !darkMode;
    syncTheme(darkMode);
    try { localStorage.setItem(themeKey, darkMode ? '1' : '0'); } catch (error) {}
});

async function showRuntimeAddress() {
    const runtime = await window.cliniqDesktop.getRuntimeInfo();
    clinicAddress.textContent = `Local address: ${runtime.clinicUrl}`;
}

function updateStatus(status) {
    const checking = status === 'checking';
    statusDot.classList.toggle('checking', checking);
    connectionStatus.textContent = checking
        ? 'Checking the clinic system…'
        : 'The clinic system is still offline';
}

retryButton.addEventListener('click', async () => {
    retryButton.disabled = true;
        retryButton.textContent = 'Checking…';
    updateStatus('checking');

    const connected = await window.cliniqDesktop.retryConnection();
    if (!connected) {
        updateStatus('unavailable');
        retryButton.disabled = false;
        retryButton.textContent = 'Try again';
    }
});

window.cliniqDesktop.onConnectionStatus(updateStatus);
void showRuntimeAddress();
