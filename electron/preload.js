const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('cliniqDesktop', Object.freeze({
    openExternal: (url) => ipcRenderer.invoke('cliniq:open-external', String(url || '')),
    selectExternalBackupDestination: () => ipcRenderer.invoke('cliniq:select-external-backup-destination'),
    getExternalBackupDestinationStatus: () => ipcRenderer.invoke('cliniq:external-backup-destination-status'),
    getClinicServerIdentity: () => ipcRenderer.invoke('cliniq:clinic-server-identity'),
    onExternalBackupRestarting: (listener) => {
        if (typeof listener !== 'function') return () => {};
        const handler = (_event, details) => listener(details || {});
        ipcRenderer.on('cliniq:external-backup-restarting', handler);
        return () => ipcRenderer.removeListener('cliniq:external-backup-restarting', handler);
    },
    retryConnection: () => ipcRenderer.invoke('cliniq:retry-connection'),
    getRuntimeInfo: () => ipcRenderer.invoke('cliniq:runtime-info'),
    onConnectionStatus: (listener) => {
        if (typeof listener !== 'function') return () => {};
        const handler = (_event, status) => listener(String(status || ''));
        ipcRenderer.on('cliniq:connection-status', handler);
        return () => ipcRenderer.removeListener('cliniq:connection-status', handler);
    },
}));
