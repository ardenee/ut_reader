'use strict';

/*
 * Compatibility wrapper around the established file inspector. Historic redirect
 * mirrors sometimes use a 5678 FCodec wrapper while retaining the .uz suffix.
 * The server decoder already accepts both 1234 and 5678 signatures; this wrapper
 * makes the browser preflight follow the same rule without duplicating the hash
 * implementation.
 */
const nativeAddEventListener = self.addEventListener;
const capturedMessageListeners = [];

self.addEventListener = function (type, listener, options) {
    if (type === 'message') {
        capturedMessageListeners.push({listener: listener, options: options});
        return;
    }
    nativeAddEventListener.call(self, type, listener, options);
};

importScripts('upload-file-inspector-worker.js');
self.addEventListener = nativeAddEventListener;

function dispatchToInspector(data) {
    const event = new MessageEvent('message', {data: data});
    capturedMessageListeners.forEach(function (entry) {
        if (typeof entry.listener === 'function') {
            entry.listener.call(self, event);
        } else if (entry.listener && typeof entry.listener.handleEvent === 'function') {
            entry.listener.handleEvent(event);
        }
    });
}

nativeAddEventListener.call(self, 'message', async function (event) {
    const data = event.data || {};
    const file = data.file;
    if (!(file instanceof Blob) || !file.name || !/\.uz$/i.test(String(file.name))) {
        dispatchToInspector(data);
        return;
    }

    try {
        const bytes = new Uint8Array(await file.slice(0, 4).arrayBuffer());
        const signature = bytes.length >= 4
            ? (bytes[0] | (bytes[1] << 8) | (bytes[2] << 16) | (bytes[3] << 24)) >>> 0
            : -1;

        if (signature !== 1234 && signature !== 5678) {
            self.postMessage({
                type: 'error',
                id: String(data.id || ''),
                message: 'The .uz file does not contain a supported Unreal redirect signature. Expected 1234 or 5678; detected ' + signature + '.'
            });
            return;
        }

        if (signature === 5678) {
            const aliasName = String(file.name).replace(/\.uz$/i, '.uz3');
            const alias = new File([file], aliasName, {
                type: file.type || 'application/octet-stream',
                lastModified: Number(file.lastModified || Date.now())
            });
            dispatchToInspector(Object.assign({}, data, {file: alias}));
            return;
        }

        dispatchToInspector(data);
    } catch (error) {
        self.postMessage({
            type: 'error',
            id: String(data.id || ''),
            message: error && error.message ? error.message : 'The .uz redirect signature could not be inspected.'
        });
    }
});
