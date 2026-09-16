const { app, BrowserWindow, dialog, ipcMain, net, session, shell } = require('electron');
const path = require('node:path');
const fs = require('node:fs/promises');
const os = require('node:os');
const { spawn } = require('node:child_process');

// Emergency alarms must start as soon as the signed-in clinic UI receives an alert.
app.commandLine.appendSwitch('autoplay-policy', 'no-user-gesture-required');

const DEFAULT_CLINIC_URL = 'http://localhost:8081/public/';
const HEALTH_TIMEOUT_MS = 5000;
const EXTERNAL_BACKUP_MARKER = '.cliniq-external-backup-drive.json';
let mainWindow = null;

function normalizedClinicUrl() {
    const configuredUrl = String(process.env.CLINIQ_CLINIC_URL || DEFAULT_CLINIC_URL).trim();
    let parsedUrl;

    try {
        parsedUrl = new URL(configuredUrl);
    } catch (error) {
        parsedUrl = new URL(DEFAULT_CLINIC_URL);
    }

    if (!['http:', 'https:'].includes(parsedUrl.protocol)) {
        parsedUrl = new URL(DEFAULT_CLINIC_URL);
    }

    if (!parsedUrl.pathname.endsWith('/')) parsedUrl.pathname += '/';
    return parsedUrl;
}

const clinicBaseUrl = normalizedClinicUrl();
const clinicStartUrl = new URL('index.php', clinicBaseUrl);
const healthUrl = new URL('api/health.php', clinicBaseUrl);
const browserOnlyClinicPaths = [
    new URL('emergency.php', clinicBaseUrl).pathname.toLowerCase(),
];
const patientPortalPath = new URL('../patient-portal/', clinicBaseUrl).pathname.toLowerCase();

function desktopIconPath() {
    return app.isPackaged
        ? path.join(process.resourcesPath, 'cliniq-desktop-icon.ico')
        : path.join(__dirname, 'assets', 'cliniq-desktop-icon.ico');
}

function dockerProjectRoot() {
    const configuredRoot = String(process.env.CLINIQ_PROJECT_ROOT || '').trim();
    const candidate = configuredRoot || path.resolve(__dirname, '..');
    return path.resolve(candidate);
}

function isSettingsPage(sender) {
    try {
        const current = new URL(sender.getURL());
        const settingsPath = new URL('settings/', clinicBaseUrl).pathname.toLowerCase();
        return current.origin === clinicBaseUrl.origin && current.pathname.toLowerCase().startsWith(settingsPath);
    } catch (error) {
        return false;
    }
}

function isPrivateIpv4(address) {
    return /^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/.test(address);
}

function runPowerShell(command) {
    return new Promise((resolve, reject) => {
        const child = spawn('powershell.exe', ['-NoProfile', '-NonInteractive', '-Command', command], {
            windowsHide: true,
            stdio: ['ignore', 'pipe', 'pipe'],
        });
        let output = '';
        let errorOutput = '';
        child.stdout.on('data', (chunk) => { output += chunk.toString(); });
        child.stderr.on('data', (chunk) => { errorOutput += chunk.toString(); });
        child.on('error', reject);
        child.on('close', (code) => code === 0 ? resolve(output.trim()) : reject(new Error(errorOutput.trim() || `PowerShell exited with code ${code}.`)));
    });
}

async function preferredRoutedIpv4() {
    const command = "$items = Get-NetIPConfiguration | Where-Object { $_.IPv4DefaultGateway -and $_.IPv4Address } | ForEach-Object { [pscustomobject]@{ address = ($_.IPv4Address | Select-Object -First 1 -ExpandProperty IPAddress); gateway = ($_.IPv4DefaultGateway | Select-Object -First 1 -ExpandProperty NextHop) } }; $items | ConvertTo-Json -Compress";
    try {
        const output = await runPowerShell(command);
        if (output === '') return null;
        const parsed = JSON.parse(output);
        const candidates = Array.isArray(parsed) ? parsed : [parsed];
        return candidates.find((candidate) => candidate && isPrivateIpv4(String(candidate.address || '')))?.address || null;
    } catch (error) {
        return null;
    }
}

async function clinicServerIdentity() {
    const routedAddress = await preferredRoutedIpv4();
    if (routedAddress) {
        return { hostname: os.hostname(), ip: routedAddress };
    }
    const addresses = Object.values(os.networkInterfaces())
        .flat()
        .filter((address) => address && address.family === 'IPv4' && !address.internal && address.address !== '127.0.0.1');
    const privateAddress = addresses.find((address) => isPrivateIpv4(address.address));
    const selected = privateAddress || addresses[0];
    if (!selected) {
        throw new Error('No active local IPv4 network connection was found on this computer. Connect the clinic network, then try again.');
    }
    return { hostname: os.hostname(), ip: selected.address };
}

async function getClinicServerIdentity(event) {
    if (!isSettingsPage(event.sender)) {
        throw new Error('Clinic server setup is only available from CLINiQ Settings.');
    }
    return clinicServerIdentity();
}

async function writeComposeEnvironmentValue(envPath, key, value) {
    let original = '';
    let existed = true;
    try {
        original = await fs.readFile(envPath, 'utf8');
    } catch (error) {
        if (error.code !== 'ENOENT') throw error;
        existed = false;
    }
    const normalizedValue = String(value).replace(/[\r\n]/g, '').replace(/\\/g, '/');
    const assignment = `${key}=${normalizedValue}`;
    const matcher = new RegExp(`^${key.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}=`);
    const lines = original.split(/\r?\n/);
    let updated = false;
    const next = lines.map((line) => {
        if (!matcher.test(line)) return line;
        updated = true;
        return assignment;
    });
    if (!updated) next.push(assignment);
    await fs.writeFile(envPath, `${next.join('\n').replace(/\n+$/, '')}\n`, 'utf8');
    return { original, existed };
}

async function restoreEnvironmentFile(envPath, previous) {
    if (previous.existed) {
        await fs.writeFile(envPath, previous.original, 'utf8');
        return;
    }
    await fs.unlink(envPath).catch((error) => {
        if (error.code !== 'ENOENT') throw error;
    });
}

function runDockerCompose(projectRoot, args) {
    return new Promise((resolve, reject) => {
        const process = spawn('docker', ['compose', ...args], { cwd: projectRoot, windowsHide: true, stdio: ['ignore', 'pipe', 'pipe'] });
        let output = '';
        process.stdout.on('data', (chunk) => { output += chunk.toString(); });
        process.stderr.on('data', (chunk) => { output += chunk.toString(); });
        process.on('error', reject);
        process.on('close', (code) => code === 0 ? resolve(output) : reject(new Error(output.trim() || `Docker Compose exited with code ${code}.`)));
    });
}

async function requireAdminBackupAccess(event) {
    if (!isSettingsPage(event.sender)) {
        throw new Error('External backup locations can only be changed from CLINiQ Settings.');
    }

    const authorizationUrl = new URL('api/desktop_backup_authorization.php', clinicBaseUrl).href;
    let response;
    try {
        response = await event.sender.session.fetch(authorizationUrl, {
            headers: { Accept: 'application/json' },
            cache: 'no-store',
        });
    } catch (error) {
        throw new Error('Unable to confirm your backup-administrator access.');
    }
    if (!response.ok) {
        throw new Error('Only signed-in CLINiQ administrators can change the Docker backup destination.');
    }
    const authorization = await response.json().catch(() => null);
    if (!authorization || authorization.authorized !== true) {
        throw new Error('Only signed-in CLINiQ administrators can change the Docker backup destination.');
    }
}

async function prepareExternalBackupDestination(destination) {
    const resolvedDestination = path.resolve(destination);
    const root = path.parse(resolvedDestination).root;
    if (resolvedDestination.toLowerCase() === root.toLowerCase() && root.toLowerCase() === 'c:\\') {
        throw new Error('The server C: drive cannot be used as the external backup destination. Choose a dedicated backup folder.');
    }

    const markerPath = path.join(resolvedDestination, EXTERNAL_BACKUP_MARKER);
    let original = '';
    let existed = true;
    try {
        original = await fs.readFile(markerPath, 'utf8');
    } catch (error) {
        if (error.code !== 'ENOENT') throw error;
        existed = false;
    }
    const marker = {
        format_version: 1,
        purpose: 'CLINiQ external backup drive',
        prepared_at: new Date().toISOString(),
        storage_class: 'operator-selected',
    };
    await fs.writeFile(markerPath, `${JSON.stringify(marker, null, 2)}\n`, 'utf8');
    return { markerPath, original, existed };
}

async function restoreExternalBackupMarker(previous) {
    if (previous.existed) {
        await fs.writeFile(previous.markerPath, previous.original, 'utf8');
        return;
    }
    await fs.unlink(previous.markerPath).catch((error) => {
        if (error.code !== 'ENOENT') throw error;
    });
}

async function isPreparedExternalBackupDestination(destination) {
    try {
        const markerPath = path.join(destination, EXTERNAL_BACKUP_MARKER);
        const marker = JSON.parse(await fs.readFile(markerPath, 'utf8'));
        return marker?.format_version === 1 && marker?.purpose === 'CLINiQ external backup drive';
    } catch (error) {
        return false;
    }
}

async function selectExternalBackupDestination(event) {
    await requireAdminBackupAccess(event);

    const projectRoot = dockerProjectRoot();
    const composePath = path.join(projectRoot, 'compose.yaml');
    const composeEnvPath = path.join(projectRoot, '.env');
    const appEnvPath = path.join(projectRoot, 'docker', '.env');
    try {
        await fs.access(composePath);
        await fs.access(appEnvPath);
    } catch (error) {
        throw new Error('Docker deployment files were not found. Set CLINIQ_PROJECT_ROOT to the CLINiQ project folder.');
    }

    const selection = await dialog.showOpenDialog(BrowserWindow.fromWebContents(event.sender), {
        title: 'Select external backup folder', buttonLabel: 'Use this folder', properties: ['openDirectory', 'createDirectory'],
    });
    if (selection.canceled || selection.filePaths.length === 0) return { canceled: true };

    const destination = path.resolve(selection.filePaths[0]);
    const previousMarker = await prepareExternalBackupDestination(destination);
    event.sender.send('cliniq:external-backup-restarting', { destination });
    const previousComposeEnv = await writeComposeEnvironmentValue(composeEnvPath, 'BACKUP_EXTERNAL_HOST_PATH', destination);
    const previousAppEnv = await writeComposeEnvironmentValue(appEnvPath, 'BACKUP_EXTERNAL_HOST_PATH', destination);
    try {
        await runDockerCompose(projectRoot, ['up', '-d', '--force-recreate', 'app', 'backup']);
        await waitForClinicReady();
    } catch (error) {
        await Promise.all([
            restoreEnvironmentFile(composeEnvPath, previousComposeEnv),
            restoreEnvironmentFile(appEnvPath, previousAppEnv),
            restoreExternalBackupMarker(previousMarker),
        ]);
        throw new Error(`Docker could not mount the selected folder. ${error.message}`);
    }
    return { canceled: false, destination };
}

async function externalBackupDestinationStatus(event) {
    if (!isSettingsPage(event.sender)) throw new Error('External backup status is only available from CLINiQ Settings.');

    const projectRoot = dockerProjectRoot();
    const envPath = path.join(projectRoot, '.env');
    let contents;
    try {
        contents = await fs.readFile(envPath, 'utf8');
    } catch (error) {
        return { configured: false, available: false, message: 'External backup drive has not been configured.' };
    }

    const line = contents.split(/\r?\n/).find((value) => value.startsWith('BACKUP_EXTERNAL_HOST_PATH='));
    const configuredPath = line ? line.slice('BACKUP_EXTERNAL_HOST_PATH='.length).trim() : '';
    if (configuredPath === '') {
        return { configured: false, available: false, message: 'External backup drive has not been configured.' };
    }

    const destination = path.resolve(projectRoot, configuredPath);
    try {
        const details = await fs.stat(destination);
        if (!details.isDirectory()) throw new Error('The selected location is not a folder.');
        const prepared = await isPreparedExternalBackupDestination(destination);
        return prepared
            ? { configured: true, available: true, prepared: true, destination, message: 'Connected and ready for backups.' }
            : { configured: true, available: false, prepared: false, destination, message: 'Selected folder has not been prepared for CLINiQ backups. Choose it again from an admin desktop session.' };
    } catch (error) {
        return { configured: true, available: false, destination, message: 'Disconnected or unavailable. Reconnect the drive, then choose it again if its drive letter changed.' };
    }
}

function isAllowedClinicUrl(value) {
    try {
        const candidate = new URL(value);
        return candidate.origin === clinicBaseUrl.origin
            && candidate.pathname.toLowerCase().startsWith(clinicBaseUrl.pathname.toLowerCase())
            && !isBrowserOnlyUrl(candidate.href);
    } catch (error) {
        return false;
    }
}

function isBrowserOnlyUrl(value) {
    try {
        const candidate = new URL(value);
        if (candidate.origin !== clinicBaseUrl.origin) return false;

        const pathname = candidate.pathname.toLowerCase();
        return browserOnlyClinicPaths.includes(pathname) || pathname.startsWith(patientPortalPath);
    } catch (error) {
        return false;
    }
}

function isSafeExternalUrl(value) {
    try {
        return ['http:', 'https:', 'mailto:'].includes(new URL(value).protocol);
    } catch (error) {
        return false;
    }
}

async function openExternal(value) {
    if (!isSafeExternalUrl(value)) return false;
    await shell.openExternal(value);
    return true;
}

async function clinicIsReady() {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), HEALTH_TIMEOUT_MS);

    try {
        const response = await net.fetch(healthUrl.href, {
            cache: 'no-store',
            signal: controller.signal,
        });
        if (!response.ok) return false;
        const result = await response.json();
        return result && result.status === 'ready';
    } catch (error) {
        return false;
    } finally {
        clearTimeout(timeout);
    }
}

async function waitForClinicReady(timeoutMs = 30000) {
    const deadline = Date.now() + timeoutMs;
    while (Date.now() < deadline) {
        if (await clinicIsReady()) return;
        await new Promise((resolve) => setTimeout(resolve, 500));
    }
    throw new Error('CLINiQ did not become ready after connecting the external drive.');
}

async function showOfflineScreen() {
    if (!mainWindow || mainWindow.isDestroyed()) return;
    await mainWindow.loadFile(path.join(__dirname, 'offline.html'));
}

async function loadClinicWhenReady() {
    if (!mainWindow || mainWindow.isDestroyed()) return false;
    mainWindow.webContents.send('cliniq:connection-status', 'checking');

    if (!(await clinicIsReady())) {
        await showOfflineScreen();
        mainWindow.webContents.send('cliniq:connection-status', 'unavailable');
        return false;
    }

    await mainWindow.loadURL(clinicStartUrl.href);
    return true;
}

function createMainWindow() {
    mainWindow = new BrowserWindow({
        title: 'CLINiQ Clinic',
        width: 1440,
        height: 900,
        minWidth: 1000,
        minHeight: 700,
        show: false,
        autoHideMenuBar: true,
        backgroundColor: '#f6fbf8',
        icon: desktopIconPath(),
        webPreferences: {
            preload: path.join(__dirname, 'preload.js'),
            contextIsolation: true,
            nodeIntegration: false,
            sandbox: true,
            webSecurity: true,
            allowRunningInsecureContent: false,
        },
    });

    const defaultUserAgent = mainWindow.webContents.getUserAgent();
    mainWindow.webContents.setUserAgent(`${defaultUserAgent} CLINiQElectron/${app.getVersion()}`);

    mainWindow.once('ready-to-show', () => {
        mainWindow?.maximize();
        mainWindow?.show();
    });

    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        if (isBrowserOnlyUrl(url)) {
            void openExternal(url);
        } else if (isAllowedClinicUrl(url)) {
            mainWindow?.loadURL(url);
        } else {
            void openExternal(url);
        }
        return { action: 'deny' };
    });

    mainWindow.webContents.on('will-navigate', (event, url) => {
        if (isBrowserOnlyUrl(url)) {
            event.preventDefault();
            void openExternal(url);
            return;
        }
        if (isAllowedClinicUrl(url) || url.startsWith('file:')) return;
        event.preventDefault();
        void openExternal(url);
    });

    mainWindow.webContents.on('did-fail-load', (_event, errorCode, _description, validatedUrl, isMainFrame) => {
        if (!isMainFrame || errorCode === -3 || !isAllowedClinicUrl(validatedUrl)) return;
        void showOfflineScreen();
    });

    mainWindow.on('closed', () => {
        mainWindow = null;
    });

    void loadClinicWhenReady();
}

if (!app.requestSingleInstanceLock()) {
    app.quit();
} else {
    app.on('second-instance', () => {
        if (!mainWindow) return;
        if (mainWindow.isMinimized()) mainWindow.restore();
        mainWindow.focus();
    });

    app.whenReady().then(() => {
        session.defaultSession.setPermissionRequestHandler((_webContents, _permission, callback) => callback(false));
        session.defaultSession.setPermissionCheckHandler(() => false);

        ipcMain.handle('cliniq:open-external', (_event, url) => openExternal(String(url || '')));
        ipcMain.handle('cliniq:select-external-backup-destination', selectExternalBackupDestination);
        ipcMain.handle('cliniq:external-backup-destination-status', externalBackupDestinationStatus);
        ipcMain.handle('cliniq:clinic-server-identity', getClinicServerIdentity);
        ipcMain.handle('cliniq:retry-connection', () => loadClinicWhenReady());
        ipcMain.handle('cliniq:runtime-info', () => ({
            clinicUrl: clinicStartUrl.href,
            clinicBaseUrl: clinicBaseUrl.href,
            healthUrl: healthUrl.href,
            version: app.getVersion(),
        }));

        createMainWindow();

        app.on('activate', () => {
            if (BrowserWindow.getAllWindows().length === 0) createMainWindow();
        });
    });
}

app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') app.quit();
});
