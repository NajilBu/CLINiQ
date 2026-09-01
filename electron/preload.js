const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('cliniqDesktop', Object.freeze({
    openExternal: (url) => ipcRenderer.invoke('cliniq:open-external', String(url || '')),
    retryConnection: () => ipcRenderer.invoke('cliniq:retry-connection'),
    getRuntimeInfo: () => ipcRenderer.invoke('cliniq:runtime-info'),
    onConnectionStatus: (listener) => {
        if (typeof listener !== 'function') return () => {};
        const handler = (_event, status) => listener(String(status || ''));
        ipcRenderer.on('cliniq:connection-status', handler);
        return () => ipcRenderer.removeListener('cliniq:connection-status', handler);
    },
}));
