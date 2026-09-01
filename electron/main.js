const { app, BrowserWindow, ipcMain, net, session, shell } = require('electron');
const path = require('node:path');

const DEFAULT_CLINIC_URL = 'http://localhost/CLINiQ/public/';
const HEALTH_TIMEOUT_MS = 5000;
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
const clinicStartUrl = new URL('login.php', clinicBaseUrl);
const healthUrl = new URL('api/health.php', clinicBaseUrl);

function desktopIconPath() {
    return app.isPackaged
        ? path.join(process.resourcesPath, 'clinic-logo.png')
        : path.join(__dirname, '..', 'public', 'assets', 'img', 'clinic-logo.png');
}

function isAllowedClinicUrl(value) {
    try {
        const candidate = new URL(value);
        if (candidate.origin !== clinicBaseUrl.origin || !candidate.pathname.startsWith(clinicBaseUrl.pathname)) {
            return false;
        }

        const relativePath = candidate.pathname.slice(clinicBaseUrl.pathname.length).replace(/^\/+/, '').toLowerCase();
        const browserOnlyRoutes = new Set(['', 'index.php', 'visitor-registration.php', 'emergency.php']);
        return !browserOnlyRoutes.has(relativePath);
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

    mainWindow.once('ready-to-show', () => mainWindow?.show());

    mainWindow.webContents.setWindowOpenHandler(({ url }) => {
        if (isAllowedClinicUrl(url)) {
            mainWindow?.loadURL(url);
        } else {
            void openExternal(url);
        }
        return { action: 'deny' };
    });

    mainWindow.webContents.on('will-navigate', (event, url) => {
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
