'use strict';

/*
 * Compatibility wrapper around the established file inspector.
 *
 * Transport containers deliberately bypass package hashing:
 * - `.uz` accepts both historic FCodec signatures 1234 and 5678 while retaining
 *   the `.uz` identity. A 5678 `.uz` is NOT UT3 `.uz3`.
 * - `.zip`, `.7z` and `.rar` are unpack-only transport containers. Package
 *   identity is calculated later from each extracted Unreal member.
 *
 * Archive extensions are hints, not authoritative format declarations. ZIP and
 * self-extracting archives may contain a valid archive signature after a prepended
 * stub, while historic mirrors can also contain archives with the wrong suffix.
 * The browser therefore performs a bounded signature sniff for operator feedback
 * but never rejects an allowed archive extension solely because byte zero does
 * not match the suffix. The server archive parser remains authoritative.
 *
 * Keep the transport-container path independent from the full package inspector.
 * Large archive uploads do not need MD5/SHA package hashing in the browser, and
 * an unrelated load/runtime failure in the package inspector must not reject ZIP,
 * 7-Zip or RAR before the file even reaches durable server staging.
 */
const nativeAddEventListener = self.addEventListener;
const capturedMessageListeners = [];
const ARCHIVE_SNIFF_BYTES = 64 * 1024;
let inspectorLoaded = false;
let inspectorLoadError = null;

function ensureInspectorLoaded() {
    if (inspectorLoaded) return;
    if (inspectorLoadError) throw inspectorLoadError;

    const previousAddEventListener = self.addEventListener;
    self.addEventListener = function (type, listener, options) {
        if (type === 'message') {
            capturedMessageListeners.push({listener: listener, options: options});
            return;
        }
        nativeAddEventListener.call(self, type, listener, options);
    };

    try {
        // The wrapper itself is cache-busted by upload-bucket-v2.php. Carry the
        // same query string to the delegated inspector so a browser cannot keep
        // an old package-inspector script beside a new compatibility wrapper.
        // This wrapper revision also forces clients onto the allocation-bounded
        // inspector used for very large, long-running folder uploads.
        importScripts('upload-file-inspector-worker.js' + (self.location.search || ''));
        inspectorLoaded = true;
    } catch (error) {
        inspectorLoadError = error instanceof Error
            ? error
            : new Error(String(error || 'The package inspector could not be loaded.'));
        throw inspectorLoadError;
    } finally {
        self.addEventListener = previousAddEventListener;
    }
}

function dispatchToInspector(data) {
    ensureInspectorLoaded();
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

function indexOfSequence(bytes, sequence) {
    if (bytes.length < sequence.length) return -1;
    const last = bytes.length - sequence.length;
    outer:
    for (let offset = 0; offset <= last; offset++) {
        for (let index = 0; index < sequence.length; index++) {
            if (bytes[offset + index] !== sequence[index]) continue outer;
        }
        return offset;
    }
    return -1;
}

function archiveHeader(extension, bytes) {
    const signatures = [
        {extension: 'zip', label: 'ZIP', sequence: [0x50, 0x4b, 0x03, 0x04]},
        {extension: 'zip', label: 'ZIP', sequence: [0x50, 0x4b, 0x05, 0x06]},
        {extension: 'zip', label: 'ZIP', sequence: [0x50, 0x4b, 0x07, 0x08]},
        {extension: '7z', label: '7-Zip', sequence: [0x37, 0x7a, 0xbc, 0xaf, 0x27, 0x1c]},
        {extension: 'rar', label: 'RAR', sequence: [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x00]},
        {extension: 'rar', label: 'RAR', sequence: [0x52, 0x61, 0x72, 0x21, 0x1a, 0x07, 0x01, 0x00]}
    ];

    let detected = null;
    for (const signature of signatures) {
        const offset = indexOfSequence(bytes, signature.sequence);
        if (offset < 0) continue;
        if (!detected || offset < detected.offset) {
            detected = {extension: signature.extension, label: signature.label, offset: offset};
        }
    }

    if (detected) {
        const mismatch = detected.extension !== extension;
        const location = detected.offset === 0 ? 'at byte 0' : 'at byte ' + detected.offset;
        return {
            kind: 'archive-' + detected.extension,
            detected_extension: detected.extension,
            signature_offset: detected.offset,
            signature_verified: true,
            description: detected.label + ' transport container signature ' + location
                + (mismatch ? '; filename extension is .' + extension + ', server will use detected content' : '')
        };
    }

    const label = extension === '7z' ? '7-Zip' : extension.toUpperCase();
    return {
        kind: 'archive-' + extension + '-server-verify',
        detected_extension: '',
        signature_offset: -1,
        signature_verified: false,
        description: label + ' filename selected; no archive signature was found in the first '
            + bytes.length + ' bytes, so the server parser will validate the complete container'
    };
}

nativeAddEventListener.call(self, 'message', async function (event) {
    const data = event.data || {};
    const file = data.file;
    const readableFile = file && typeof file.slice === 'function' && typeof file.name === 'string' && file.name !== '';

    if (!readableFile) {
        try {
            dispatchToInspector(data);
        } catch (error) {
            self.postMessage({
                type: 'error',
                id: String(data.id || ''),
                message: error && error.message ? error.message : 'The package inspector could not be loaded.'
            });
        }
        return;
    }

    const name = String(file.name);
    const extension = name.includes('.') ? name.split('.').pop().toLowerCase() : '';
    const archive = ['zip', '7z', 'rar'].includes(extension);
    const legacyUz = extension === 'uz';
    if (!archive && !legacyUz) {
        try {
            dispatchToInspector(data);
        } catch (error) {
            self.postMessage({
                type: 'error',
                id: String(data.id || ''),
                message: error && error.message ? error.message : 'The package inspector could not be loaded.'
            });
        }
        return;
    }

    try {
        const readBytes = legacyUz ? 16 : Math.min(Math.max(16, Number(file.size || 0)), ARCHIVE_SNIFF_BYTES);
        const bytes = new Uint8Array(await file.slice(0, readBytes).arrayBuffer());
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