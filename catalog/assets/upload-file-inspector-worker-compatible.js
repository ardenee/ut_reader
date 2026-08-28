'use strict';

/*
 * Compatibility wrapper around the established file inspector.
 *
 * Transport containers deliberately bypass package hashing:
 * - `.uz` accepts both historic FCodec signatures 1234 and 5678 while retaining
 *   the `.uz` identity. A 5678 `.uz` is NOT UT3 `.uz3`.
 * - `.zip`, `.7z`, `.rar`, `.umod`, `.ut2mod` and `.ut4mod` are unpack-only
 *   transport containers. Package identity is calculated later from each
 *   extracted Unreal member.
 *
 * ZIP/7z/RAR extensions are hints, not authoritative format declarations. ZIP
 * and self-extracting archives may contain a valid archive signature after a
 * prepended stub, while historic mirrors can also contain archives with the wrong
 * suffix. UMOD-family packages are different: their Unreal Setup identity lives
 * in the 20-byte footer, so the browser validates that bounded footer instead of
 * hashing the whole container. The server parser remains authoritative.
 *
 * Keep the transport-container path independent from the full package inspector.
 * Large archive uploads do not need MD5/SHA package hashing in the browser, and
 * an unrelated load/runtime failure in the package inspector must not reject a
 * transport container before the file even reaches durable server staging.
 */
const nativeAddEventListener = self.addEventListener;
const capturedMessageListeners = [];
const ARCHIVE_SNIFF_BYTES = 64 * 1024;
const UMOD_FOOTER_BYTES = 20;
const UMOD_MAGIC = 0x9fe3c5a3;
const UMOD_EXTENSIONS = new Set(['umod', 'ut2mod', 'ut4mod']);
const TRANSPORT_ARCHIVE_EXTENSIONS = new Set(['zip', '7z', 'rar', 'umod', 'ut2mod', 'ut4mod']);
let inspectorLoaded = false;
let inspectorLoadError = null;
let activeRequestId = '';

function runtimeErrorMessage(event, fallback) {
    const message = event && typeof event.message === 'string' ? event.message.trim() : '';
    const file = event && typeof event.filename === 'string' ? event.filename.trim() : '';
    const line = event && Number(event.lineno || 0) > 0 ? Number(event.lineno) : 0;
    const column = event && Number(event.colno || 0) > 0 ? Number(event.colno) : 0;
    let detail = message || fallback;
    if (file) detail += ' at ' + file + (line ? ':' + line : '') + (column ? ':' + column : '');
    return detail;
}

nativeAddEventListener.call(self, 'error', function (event) {
    if (!activeRequestId) return;
    self.postMessage({
        type: 'error',
        id: activeRequestId,
        message: runtimeErrorMessage(event, 'Unhandled browser inspector runtime error.')
    });
    if (event && typeof event.preventDefault === 'function') event.preventDefault();
});

nativeAddEventListener.call(self, 'unhandledrejection', function (event) {
    if (!activeRequestId) return;
    const reason = event ? event.reason : null;
    const message = reason && reason.message
        ? String(reason.message)
        : String(reason || 'Unhandled browser inspector promise rejection.');
    self.postMessage({type: 'error', id: activeRequestId, message: message});
    if (event && typeof event.preventDefault === 'function') event.preventDefault();
});

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
        // The wrapper and delegated inspector share the page's cache-busting
        // version, which is derived from both files by upload-bucket-v2.php.
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

async function umodHeader(file, extension) {
    const total = Math.max(0, Number(file.size || 0));
    if (total < UMOD_FOOTER_BYTES) {
        throw new Error('The .' + extension + ' file is too small to contain an Unreal Setup footer.');
    }
    const footer = new Uint8Array(await file.slice(total - UMOD_FOOTER_BYTES, total).arrayBuffer());
    if (footer.length !== UMOD_FOOTER_BYTES) {
        throw new Error('The .' + extension + ' Unreal Setup footer could not be read completely.');
    }
    const magic = littleU32(footer, 0);
    const tableOffset = littleU32(footer, 4);
    const declaredSize = littleU32(footer, 8);
    const version = littleU32(footer, 12);
    if (magic !== UMOD_MAGIC) {
        throw new Error('The .' + extension + ' file does not contain the Unreal Setup UMOD-family footer magic.');
    }
    if (version !== 1) {
        throw new Error('The .' + extension + ' file uses unsupported Unreal Setup archive version ' + version + '.');
    }
    if (declaredSize !== total) {
        throw new Error('The .' + extension + ' footer size does not match the selected file size.');
    }
    if (tableOffset >= total - UMOD_FOOTER_BYTES) {
        throw new Error('The .' + extension + ' directory-table offset is outside the archive payload.');
    }
    return {
        kind: 'archive-' + extension,
        detected_extension: extension,
        signature_offset: total - UMOD_FOOTER_BYTES,
        signature_verified: true,
        description: extension.toUpperCase() + ' Unreal Setup footer verified; server will validate its directory and CRC'
    };
}

nativeAddEventListener.call(self, 'message', async function (event) {
    const data = event.data || {};
    activeRequestId = String(data.id || '');
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
    const archive = TRANSPORT_ARCHIVE_EXTENSIONS.has(extension);
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
        if (UMOD_EXTENSIONS.has(extension)) {
            const header = await umodHeader(file, extension);
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
            return;
        }

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
            throw new Error(
                'Legacy .uz FCodec redirects are not accepted by the public uploader yet because '
                + 'their decoded package identity cannot be calculated in the browser. '
                + 'The public uploader will not send a file that cannot be checked for an existing catalog duplicate.'
            );
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
