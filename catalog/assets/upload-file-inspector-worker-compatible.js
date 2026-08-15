'use strict';

/*
 * Compatibility wrapper around the established file inspector.
 *
 * Two transport-container cases deliberately bypass package hashing:
 * - `.uz` accepts both historic FCodec signatures 1234 and 5678 while retaining
 *   the `.uz` identity. A 5678 `.uz` is NOT UT3 `.uz3`.
 * - `.zip`, `.7z` and `.rar` are unpack-only transport containers. Package
 *   identity is calculated later from each extracted Unreal member.
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

function littleU32(bytes, offset) {
    if (offset < 0 || offset + 4 > bytes.length) return -1;
    return (bytes[offset]
        | (bytes[offset + 1] << 8)
        | (bytes[offset + 2] << 16)
        | (bytes[offset + 3] << 24)) >>> 0;
}

function startsWith(bytes, sequence) {
    if (bytes.length < sequence.length) return false;
    for (let index = 0; index < sequence.length; index++) {
        if (bytes[index] !== sequence[index]) return false;
    }
    return true;
}

function archiveHeader(extension, bytes) {
    if (extension === 'zip') {
        const valid = startsWith(bytes, [0x50, 0x4b, 0x03, 0x04])
            || startsWith(bytes, [0x50, 0x4b, 0x05, 0x06])
            || startsWith(bytes, [0x50, 0x4b, 0x07, 0x08]);
        if (!valid) throw new Error('The .zip file does not contain a ZIP signature.');
        return {kind: 'archive-zip', description: 'ZIP transport container'};
    }
    if (extension === '7z') {
        if (!startsWith(bytes, [0x37, 0x7a, 0xbc, 0xaf, 0x27, 0x1c])) {
            throw new Error('The .7z file does not contain a 7-Zip signature.');
        }
        return {kind: 'archive-7z', description: '7-Zip transport container'};
    }
    if (extension === 'rar') {
        const rar4 = startsWith(bytes, [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00]);
        const rar5 = startsWith(bytes, [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00]);
        if (!rar4 && !rar5) throw new Error('The .rar file does not contain a RAR signature.');
        return {kind: 'archive-rar', description: 'RAR transport container'};
    }
    throw new Error('Unsupported archive extension.');
}

nativeAddEventListener.call(self, 'message', async function (event) {
    const data = event.data || {};
    const file = data.file;
    if (!(file instanceof Blob) || !file.name) {
        dispatchToInspector(data);
        return;
    }

    const name = String(file.name);
    const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
    const archive = ['zip', '7z', 'rar'].includes(extension);
    const legacyUz = extension === 'uz';
    if (!archive && !legacyUz) {
        dispatchToInspector(data);
        return;
    }

    try {
        const bytes = new Uint8Array(await file.slice(0, 16).arrayBuffer());
        if (legacyUz) {
            const signature = littleU32(bytes, 0);
            if (signature !== 1234 && signature !== 5678) {
                throw new Error(
                    'The .uz file does not contain a supported Unreal redirect signature. '
                    + 'Expected 1234 or 5678; detected ' + signature + '.'
                );
            }
            self.postMessage({
                type: 'result',
                id: String(data.id || ''),
                result: {
                    md5: '',
                    sha1: '',
                    extension: 'uz',
                    redirect: true,
                    archive: false,
                    header: {
                        kind: 'redirect-uz',
                        description: 'Unreal .uz FCodec signature ' + signature
                    }
                }
            });
            return;
        }

        const header = archiveHeader(extension, bytes);
        self.postMessage({
            type: 'result',
            id: String(data.id || ''),
            result: {
                md5: '',
                sha1: '',
                extension: extension,
                redirect: false,
                archive: true,
                header: header
            }
        });
    } catch (error) {
        self.postMessage({
            type: 'error',
            id: String(data.id || ''),
            message: error && error.message ? error.message : 'The transport-container header could not be inspected.'
        });
    }
});
