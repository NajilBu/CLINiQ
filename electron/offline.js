const retryButton = document.getElementById('retryButton');
const connectionStatus = document.getElementById('connectionStatus');
const statusDot = document.getElementById('statusDot');
const clinicAddress = document.getElementById('clinicAddress');

async function showRuntimeAddress() {
    const runtime = await window.cliniqDesktop.getRuntimeInfo();
    clinicAddress.textContent = `Local address: ${runtime.clinicUrl}`;
}

function updateStatus(status) {
    const checking = status === 'checking';
    statusDot.classList.toggle('checking', checking);
    connectionStatus.textContent = checking
        ? 'Checking Apache and MySQL…'
        : 'Apache or MySQL is not available yet';
}

retryButton.addEventListener('click', async () => {
    retryButton.disabled = true;
    retryButton.textContent = 'Checking…';
    updateStatus('checking');

    const connected = await window.cliniqDesktop.retryConnection();
    if (!connected) {
        updateStatus('unavailable');
        retryButton.disabled = false;
        retryButton.textContent = 'Retry Connection';
    }
});

window.cliniqDesktop.onConnectionStatus(updateStatus);
void showRuntimeAddress();
