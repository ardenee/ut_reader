'use strict';

/*
 * Browser-only Unreal Setup UMOD-family directory reader.
 *
 * UMOD/UT2MOD/UT4MOD payload members are stored raw (not compressed), followed
 * by a compact-index directory and a 20-byte footer. Listing validates the
 * footer, full archive CRC and bounded directory table off the UI thread.
 * Individual members are later exposed by Blob.slice() from the original
 * browser File; the container itself is never uploaded.
 */
const FOOTER_BYTES = 20;
const UMOD_MAGIC = 0x9fe3c5a3;
const MAX_DIRECTORY_BYTES = 32 * 1024 * 1024;
const CRC_CHUNK_BYTES = 1024 * 1024;
const MAX_PATH_BYTES = 64 * 1024;
const MAX_SAFE_PATH_BYTES = 2048;

function emitProgress(id, phase, message, loaded, total) {
    self.postMessage({
        type:'progress',
        id:String(id || ''),
        phase:String(phase || ''),
        message:String(message || ''),
        loaded:Math.max(0, Number(loaded || 0)),
        total:Math.max(0, Number(total || 0))
    });
}

function readU32Le(bytes, offset) {
    if (offset < 0 || offset + 4 > bytes.length) {
        throw new Error('UMOD binary integer is truncated.');
    }
    return new DataView(bytes.buffer, bytes.byteOffset + offset, 4).getUint32(0, true);
}

function readCompactIndex(bytes, cursor) {
    if (cursor.offset >= bytes.length) {
        throw new Error('Unexpected end of UMOD compact index.');
    }
    const first = bytes[cursor.offset++];
    const negative = (first & 0x80) !== 0;
    let continuation = (first & 0x40) !== 0;
    let value = first & 0x3f;
    let shift = 6;
    let count = 1;
    while (continuation) {
        if (cursor.offset >= bytes.length || count >= 5) {
            throw new Error('Invalid UMOD compact index.');
        }
        const next = bytes[cursor.offset++];
        continuation = (next & 0x80) !== 0;
        value += (next & 0x7f) * Math.pow(2, shift);
        shift += 7;
        count++;
    }
    return negative ? -value : value;
}

function decodeAnsi(bytes) {
    if (typeof TextDecoder === 'function') {
        try {
            return new TextDecoder('utf-8', {fatal:true}).decode(bytes);
        } catch (ignore) {
        }
        try {
            return new TextDecoder('windows-1252', {fatal:false}).decode(bytes);
        } catch (ignore) {
        }
    }
    let output = '';
    for (let index = 0; index < bytes.length; index++) output += String.fromCharCode(bytes[index]);
    return output;
}

function decodeUtf16Le(bytes) {
    if (typeof TextDecoder === 'function') {
        try {
            return new TextDecoder('utf-16le', {fatal:false}).decode(bytes);
        } catch (ignore) {
        }
    }
    let output = '';
    for (let offset = 0; offset + 1 < bytes.length; offset += 2) {
        output += String.fromCharCode(bytes[offset] | (bytes[offset + 1] << 8));
    }
    return output;
}

function trimTrailingNuls(value) {
    return String(value || '').replace(/\0+$/g, '');
}

function readUe1String(bytes, cursor) {
    const length = readCompactIndex(bytes, cursor);
    if (length === 0) return '';
    if (length < 0) {
        const byteCount = (-length) * 2;
        if (byteCount > MAX_PATH_BYTES || cursor.offset + byteCount > bytes.length) {
            throw new Error('Unexpected end of Unicode UMOD archive string.');
        }
        const raw = bytes.subarray(cursor.offset, cursor.offset + byteCount);
        cursor.offset += byteCount;
        return trimTrailingNuls(decodeUtf16Le(raw));
    }
    if (length > MAX_PATH_BYTES || cursor.offset + length > bytes.length) {
        throw new Error('Unexpected end of UMOD archive string.');
    }
    const raw = bytes.subarray(cursor.offset, cursor.offset + length);
    cursor.offset += length;
    let end = raw.length;
    while (end > 0 && raw[end - 1] === 0) end--;
    return decodeAnsi(raw.subarray(0, end));
}

function safeMemberPath(value) {
    const raw = String(value || '');
    if (!raw || raw.indexOf('\0') >= 0 || /[\x00-\x1F\x7F]/.test(raw)) {
        throw new Error('UMOD member has an empty/control-character path.');
    }
    const path = raw.replace(/\\/g, '/');
    if (path.startsWith('/') || /^[A-Za-z]:\//.test(path)) {
        throw new Error('UMOD member path is absolute.');
    }
    const parts = [];
    path.split('/').forEach(function (part) {
        if (part === '' || part === '.') return;
        if (part === '..') throw new Error('UMOD member path contains parent-directory traversal.');
        const clean = part.replace(/[ .\t\r\n]+$/g, '');
        if (!clean) throw new Error('UMOD member path contains an empty component.');
        parts.push(clean);
    });
    const safe = parts.join('/');
    if (!safe || safe.length > MAX_SAFE_PATH_BYTES) {
        throw new Error('UMOD member path is empty or too long.');
    }
    return safe;
}

function basename(path) {
    const parts = String(path || '').split('/');
    return parts[parts.length - 1] || '';
}

const CRC_TABLE = new Uint32Array(256);
for (let index = 0; index < 256; index++) {
    let crc = (index << 24) >>> 0;
    for (let bit = 0; bit < 8; bit++) {
        crc = (crc & 0x80000000) !== 0
            ? (((crc << 1) ^ 0x04c11db7) >>> 0)
            : ((crc << 1) >>> 0);
    }
    CRC_TABLE[index] = crc >>> 0;
}

async function unrealMemCrcFile(id, file, length) {
    let crc = 0xffffffff >>> 0;
    let offset = 0;
    while (offset < length) {
        const end = Math.min(length, offset + CRC_CHUNK_BYTES);
        const bytes = new Uint8Array(await file.slice(offset, end).arrayBuffer());
        if (bytes.length !== end - offset) {
            throw new Error('UMOD CRC read stopped unexpectedly.');
        }
        for (let index = 0; index < bytes.length; index++) {
            const lookup = ((crc >>> 24) ^ bytes[index]) & 0xff;
            crc = ((((crc << 8) >>> 0) ^ CRC_TABLE[lookup]) >>> 0);
        }
        offset = end;
        emitProgress(id, 'crc', 'Validating UMOD archive CRC.', offset, length);
    }
    return (~crc) >>> 0;
}

async function readFooter(file) {
    const size = Math.max(0, Number(file && file.size || 0));
    if (!file || typeof file.slice !== 'function' || size < FOOTER_BYTES) {
        throw new Error('UMOD-family archive is too small.');
    }
    const bytes = new Uint8Array(await file.slice(size - FOOTER_BYTES, size).arrayBuffer());
    if (bytes.length !== FOOTER_BYTES) throw new Error('Could not completely read the UMOD footer.');
    const footer = {
        magic:readU32Le(bytes, 0),
        table:readU32Le(bytes, 4),
        size:readU32Le(bytes, 8),
        version:readU32Le(bytes, 12),
        crc:readU32Le(bytes, 16)
    };
    if (footer.magic !== UMOD_MAGIC) throw new Error('UMOD-family archive has invalid Unreal Setup magic.');
    if (footer.version !== 1) throw new Error('Unsupported UMOD-family archive version: ' + footer.version + '.');
    if (footer.size !== size) throw new Error('UMOD-family archive size footer does not match the selected file.');
    if (footer.table >= footer.size - FOOTER_BYTES) {
        throw new Error('UMOD-family archive has an invalid directory-table offset.');
    }
    return footer;
}

async function listArchive(id, file) {
    const footer = await readFooter(file);
    const tableEnd = footer.size - FOOTER_BYTES;
    const tableBytes = tableEnd - footer.table;
    if (tableBytes < 1 || tableBytes > MAX_DIRECTORY_BYTES) {
        throw new Error('UMOD directory size is invalid or exceeds the browser safety limit.');
    }

    emitProgress(id, 'directory', 'Reading UMOD directory table.', 0, tableBytes);
    const table = new Uint8Array(await file.slice(footer.table, tableEnd).arrayBuffer());
    if (table.length !== tableBytes) throw new Error('Could not completely read the UMOD directory table.');

    const cursor = {offset:0};
    const count = readCompactIndex(table, cursor);
    if (count < 0) throw new Error('UMOD contains an invalid negative entry count.');

    const entries = [];
    const format = String(file.name || '').split('.').pop().toLowerCase();
    for (let index = 0; index < count; index++) {
        const rawPath = readUe1String(table, cursor);
        if (cursor.offset + 12 > table.length) throw new Error('UMOD directory entry is truncated.');
        const itemOffset = readU32Le(table, cursor.offset);
        const itemSize = readU32Le(table, cursor.offset + 4);
        const flags = readU32Le(table, cursor.offset + 8);
        cursor.offset += 12;
        if (itemOffset + itemSize > footer.table) {
            throw new Error('UMOD member points outside the payload: ' + rawPath);
        }

        let path = '';
        let safe = true;
        let reason = '';
        try {
            path = safeMemberPath(rawPath);
        } catch (error) {
            safe = false;
            reason = error && error.message ? error.message : 'unsafe member path';
            path = String(rawPath || '').replace(/\\/g, '/');
        }

        entries.push({
            index:index,
            path:path,
            name:safe ? basename(path) : basename(String(path || 'unsafe')),
            size:itemSize,
            packed_size:itemSize,
            encrypted:false,
            linked:false,
            safe:safe,
            reason:reason,
            method:'umod',
            block:'',
            backend:'umod',
            format:format,
            offset:itemOffset,
            flags:flags,
            table_offset:footer.table
        });
    }

    emitProgress(id, 'crc', 'Validating UMOD archive CRC.', 0, tableEnd);
    const actualCrc = await unrealMemCrcFile(id, file, tableEnd);
    if ((actualCrc >>> 0) !== (footer.crc >>> 0)) {
        throw new Error(
            'UMOD-family archive CRC does not match its footer; expected='
                + (footer.crc >>> 0).toString(16).toUpperCase().padStart(8, '0')
                + '; actual=' + (actualCrc >>> 0).toString(16).toUpperCase().padStart(8, '0')
                + '; checked_bytes=' + tableEnd + '.'
        );
    }

    entries.sort(function (left, right) {
        const pathCompare = String(left.path || '').localeCompare(String(right.path || ''), undefined, {
            numeric:true,
            sensitivity:'base'
        });
        return pathCompare !== 0 ? pathCompare : Number(left.index || 0) - Number(right.index || 0);
    });
    emitProgress(id, 'directory', 'UMOD directory and CRC validated.', footer.size, footer.size);
    return entries;
}

self.addEventListener('message', async function (event) {
    const data = event.data || {};
    const id = String(data.id || '');
    try {
        if (data.type !== 'list') throw new Error('Unknown UMOD worker request.');
        const entries = await listArchive(id, data.file);
        self.postMessage({type:'result', id:id, result:{entries:entries}});
    } catch (error) {
        self.postMessage({
            type:'error',
            id:id,
            message:error && error.message ? error.message : 'Browser UMOD processing failed.'
        });
    }
});
