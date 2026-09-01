'use strict';

const legacyUzDecoderUrl = new URL('legacy-uz-decoder.js', self.location.href);
legacyUzDecoderUrl.search = self.location.search;
importScripts(legacyUzDecoderUrl.href);

/* Browser-only ZIP/RAR/7z source reader. The archive File is mounted with
 * WORKERFS and is never copied into the WASM heap or uploaded. Each extraction
 * worker holds one Unreal member only and is terminated after skip/upload. */
const HASH_CHUNK_BYTES = 4 * 1024 * 1024;
const IO_CHUNK_BYTES = 1024 * 1024;
const MAX_ARCHIVE_ENTRIES = 50000;
const vendorScriptUrl = new URL('vendor/7z-wasm/7zz.umd.js', self.location.href);
const vendorWasmUrl = new URL('vendor/7z-wasm/7zz.wasm', self.location.href);
vendorScriptUrl.search = self.location.search;
vendorWasmUrl.search = self.location.search;
const VENDOR_SCRIPT = vendorScriptUrl.href;
const VENDOR_WASM = vendorWasmUrl.href;
let vendorLoaded = false;
let sevenZip = null;
let activeStream = null;
let activeSize = 0;
let nextReadOffset = 0;

const MD5_SHIFTS = new Uint8Array([
    7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22,
    5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20,
    4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23,
    6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21
]);
const MD5_CONSTANTS = new Int32Array(64);
for (let index = 0; index < 64; index++) {
    MD5_CONSTANTS[index] = Math.floor(Math.abs(Math.sin(index + 1)) * 0x100000000) | 0;
}

function hexByte(value) {
    return (value < 16 ? '0' : '') + value.toString(16);
}

function bytesHex(bytes, limit) {
    const length = Math.min(bytes.length, Math.max(0, Number(limit || bytes.length)));
    let value = '';
    for (let index = 0; index < length; index++) value += hexByte(bytes[index]);
    return value.toUpperCase();
}

function printableBytes(bytes, limit) {
    const length = Math.min(bytes.length, Math.max(0, Number(limit || bytes.length)));
    let value = '';
    for (let index = 0; index < length; index++) {
        value += bytes[index] >= 32 && bytes[index] <= 126 ? String.fromCharCode(bytes[index]) : '.';
    }
    return value;
}

function littleWordHex(value) {
    return hexByte(value & 0xff)
        + hexByte((value >>> 8) & 0xff)
        + hexByte((value >>> 16) & 0xff)
        + hexByte((value >>> 24) & 0xff);
}

function bigWordHex(value) {
    return hexByte((value >>> 24) & 0xff)
        + hexByte((value >>> 16) & 0xff)
        + hexByte((value >>> 8) & 0xff)
        + hexByte(value & 0xff);
}

function rotateLeft(value, bits) {
    return (value << bits) | (value >>> (32 - bits));
}

class Md5 {
    constructor() {
        this.a = 0x67452301 | 0;
        this.b = 0xefcdab89 | 0;
        this.c = 0x98badcfe | 0;
        this.d = 0x10325476 | 0;
        this.length = 0;
        this.buffer = new Uint8Array(64);
        this.bufferLength = 0;
        this.words = new Int32Array(16);
    }

    update(input) {
        const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
        this.length += bytes.length;
        let offset = 0;
        if (this.bufferLength) {
            const take = Math.min(64 - this.bufferLength, bytes.length);
            this.buffer.set(bytes.subarray(0, take), this.bufferLength);
            this.bufferLength += take;
            offset += take;
            if (this.bufferLength === 64) {
                this.process(this.buffer, 0);
                this.bufferLength = 0;
            }
        }
        while (offset + 64 <= bytes.length) {
            this.process(bytes, offset);
            offset += 64;
        }
        if (offset < bytes.length) {
            this.buffer.set(bytes.subarray(offset), 0);
            this.bufferLength = bytes.length - offset;
        }
    }

    process(block, blockOffset) {
        const words = this.words;
        const base = Number(blockOffset || 0);
        for (let index = 0; index < 16; index++) {
            const position = base + (index * 4);
            words[index] = (block[position]
                | (block[position + 1] << 8)
                | (block[position + 2] << 16)
                | (block[position + 3] << 24)) | 0;
        }
        let a = this.a;
        let b = this.b;
        let c = this.c;
        let d = this.d;
        for (let index = 0; index < 64; index++) {
            let functionValue;
            let wordIndex;
            if (index < 16) {
                functionValue = (b & c) | (~b & d);
                wordIndex = index;
            } else if (index < 32) {
                functionValue = (d & b) | (~d & c);
                wordIndex = (5 * index + 1) & 15;
            } else if (index < 48) {
                functionValue = b ^ c ^ d;
                wordIndex = (3 * index + 5) & 15;
            } else {
                functionValue = c ^ (b | ~d);
                wordIndex = (7 * index) & 15;
            }
            const previousD = d;
            d = c;
            c = b;
            const sum = (a + functionValue + MD5_CONSTANTS[index] + words[wordIndex]) | 0;
            b = (b + rotateLeft(sum, MD5_SHIFTS[index])) | 0;
            a = previousD;
        }
        this.a = (this.a + a) | 0;
        this.b = (this.b + b) | 0;
        this.c = (this.c + c) | 0;
        this.d = (this.d + d) | 0;
    }

    digestHex() {
        const totalBits = this.length * 8;
        const paddedBytes = this.bufferLength < 56 ? 64 : 128;
        const finalBlock = new Uint8Array(paddedBytes);
        finalBlock.set(this.buffer.subarray(0, this.bufferLength));
        finalBlock[this.bufferLength] = 0x80;
        const low = totalBits >>> 0;
        const high = Math.floor(totalBits / 0x100000000) >>> 0;
        const end = paddedBytes - 8;
        finalBlock[end] = low & 0xff;
        finalBlock[end + 1] = (low >>> 8) & 0xff;
        finalBlock[end + 2] = (low >>> 16) & 0xff;
        finalBlock[end + 3] = (low >>> 24) & 0xff;
        finalBlock[end + 4] = high & 0xff;
        finalBlock[end + 5] = (high >>> 8) & 0xff;
        finalBlock[end + 6] = (high >>> 16) & 0xff;
        finalBlock[end + 7] = (high >>> 24) & 0xff;
        for (let offset = 0; offset < paddedBytes; offset += 64) {
            this.process(finalBlock, offset);
        }
        return littleWordHex(this.a) + littleWordHex(this.b) + littleWordHex(this.c) + littleWordHex(this.d);
    }
}

class Sha1 {
    constructor() {
        this.h0 = 0x67452301 | 0;
        this.h1 = 0xefcdab89 | 0;
        this.h2 = 0x98badcfe | 0;
        this.h3 = 0x10325476 | 0;
        this.h4 = 0xc3d2e1f0 | 0;
        this.length = 0;
        this.buffer = new Uint8Array(64);
        this.bufferLength = 0;
        this.words = new Int32Array(80);
    }

    update(input) {
        const bytes = input instanceof Uint8Array ? input : new Uint8Array(input);
        this.length += bytes.length;
        let offset = 0;
        if (this.bufferLength) {
            const take = Math.min(64 - this.bufferLength, bytes.length);
            this.buffer.set(bytes.subarray(0, take), this.bufferLength);
            this.bufferLength += take;
            offset += take;
            if (this.bufferLength === 64) {
                this.process(this.buffer, 0);
                this.bufferLength = 0;
            }
        }
        while (offset + 64 <= bytes.length) {
            this.process(bytes, offset);
            offset += 64;
        }
        if (offset < bytes.length) {
            this.buffer.set(bytes.subarray(offset), 0);
            this.bufferLength = bytes.length - offset;
        }
    }

    process(block, blockOffset) {
        const words = this.words;
        const base = Number(blockOffset || 0);
        for (let index = 0; index < 16; index++) {
            const position = base + (index * 4);
            words[index] = ((block[position] << 24)
                | (block[position + 1] << 16)
                | (block[position + 2] << 8)
                | block[position + 3]) | 0;
        }
        for (let index = 16; index < 80; index++) {
            words[index] = rotateLeft(words[index - 3] ^ words[index - 8] ^ words[index - 14] ^ words[index - 16], 1);
        }
        let a = this.h0;
        let b = this.h1;
        let c = this.h2;
        let d = this.h3;
        let e = this.h4;
        for (let index = 0; index < 80; index++) {
            let functionValue;
            let constant;
            if (index < 20) {
                functionValue = (b & c) | (~b & d);
                constant = 0x5a827999;
            } else if (index < 40) {
                functionValue = b ^ c ^ d;
                constant = 0x6ed9eba1;
            } else if (index < 60) {
                functionValue = (b & c) | (b & d) | (c & d);
                constant = 0x8f1bbcdc;
            } else {
                functionValue = b ^ c ^ d;
                constant = 0xca62c1d6;
            }
            const next = (rotateLeft(a, 5) + functionValue + e + constant + words[index]) | 0;
            e = d;
            d = c;
            c = rotateLeft(b, 30);
            b = a;
            a = next;
        }
        this.h0 = (this.h0 + a) | 0;
        this.h1 = (this.h1 + b) | 0;
        this.h2 = (this.h2 + c) | 0;
        this.h3 = (this.h3 + d) | 0;
        this.h4 = (this.h4 + e) | 0;
    }

    digestHex() {
        const totalBits = this.length * 8;
        const paddedBytes = this.bufferLength < 56 ? 64 : 128;
        const finalBlock = new Uint8Array(paddedBytes);
        finalBlock.set(this.buffer.subarray(0, this.bufferLength));
        finalBlock[this.bufferLength] = 0x80;
        const low = totalBits >>> 0;
        const high = Math.floor(totalBits / 0x100000000) >>> 0;
        const end = paddedBytes - 8;
        finalBlock[end] = (high >>> 24) & 0xff;
        finalBlock[end + 1] = (high >>> 16) & 0xff;
        finalBlock[end + 2] = (high >>> 8) & 0xff;
        finalBlock[end + 3] = high & 0xff;
        finalBlock[end + 4] = (low >>> 24) & 0xff;
        finalBlock[end + 5] = (low >>> 16) & 0xff;
        finalBlock[end + 6] = (low >>> 8) & 0xff;
        finalBlock[end + 7] = low & 0xff;
        for (let offset = 0; offset < paddedBytes; offset += 64) {
            this.process(finalBlock, offset);
        }
        return bigWordHex(this.h0) + bigWordHex(this.h1) + bigWordHex(this.h2)
            + bigWordHex(this.h3) + bigWordHex(this.h4);
    }
}


function extensionOf(name) {
    const clean = String(name || '').replace(/\\/g, '/').split('/').pop() || '';
    const position = clean.lastIndexOf('.');
    return position >= 0 ? clean.slice(position + 1).trim().toLowerCase() : '';
}
function packageMagic(bytes) {
    return bytes.length >= 4 && (
        (bytes[0] === 0xc1 && bytes[1] === 0x83 && bytes[2] === 0x2a && bytes[3] === 0x9e)
        || (bytes[0] === 0x9e && bytes[1] === 0x2a && bytes[2] === 0x83 && bytes[3] === 0xc1)
    );
}
function readU32Le(bytes, offset) {
    if (offset < 0 || offset + 4 > bytes.length) return -1;
    return (bytes[offset] | (bytes[offset + 1] << 8) | (bytes[offset + 2] << 16)
        | (bytes[offset + 3] << 24)) >>> 0;
}
function legacyGuidFromHead(bytes) {
    if (!packageMagic(bytes) || bytes.length < 52) return '';
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const littleEndian = view.getUint32(0, true) === 0x9e2a83c1;
    const version = view.getUint32(4, littleEndian) & 0xffff;
    if (version < 1 || version >= 200) return '';
    const guidOffset = version < 68 ? 44 : 36;
    if (bytes.length < guidOffset + 16) return '';
    if (version < 68 && view.getInt32(16, littleEndian) < guidOffset + 16) return '';
    const parts = [];
    for (let offset = guidOffset; offset < guidOffset + 16; offset += 4) {
        parts.push(view.getUint32(offset, littleEndian).toString(16).toUpperCase().padStart(8, '0'));
    }
    return parts.join('-');
}
function normalizeMemberPath(value) {
    let path = String(value || '').replace(/\0/g, '').replace(/\\/g, '/');
    path = path.replace(/\/+/g, '/').replace(/^\.\//, '');
    if (!path || path.startsWith('/') || /^[A-Za-z]:/.test(path)) {
        throw new Error('Archive member path is absolute or empty.');
    }
    const parts = path.split('/');
    if (parts.some(function (part) { return part === '..' || part === ''; })) {
        throw new Error('Archive member path contains an unsafe path segment.');
    }
    return parts.join('/');
}
function basename(path) {
    const parts = normalizeMemberPath(path).split('/');
    return parts[parts.length - 1];
}
function emitProgress(id, phase, message, loaded, total) {
    self.postMessage({type:'progress', id:String(id || ''), phase:phase, message:String(message || ''),
        loaded:Math.max(0, Number(loaded || 0)), total:Math.max(0, Number(total || 0))});
}
function cleanSevenZipError(error, stderr) {
    const detail = Array.isArray(stderr) ? stderr.map(String).filter(Boolean).slice(-8).join(' · ') : '';
    const message = error && error.message ? String(error.message) : String(error || '');
    return (detail || message || '7-Zip WASM command failed.').slice(0, 2000);
}
async function loadVendor() {
    if (vendorLoaded) return;
    try { importScripts(VENDOR_SCRIPT); }
    catch (error) {
        throw new Error('The browser archive decoder is not installed. Run php catalog/bin/install-browser-archive-decoder.php on the server.');
    }
    if (typeof SevenZip !== 'function') throw new Error('The browser archive decoder did not expose SevenZip.');
    vendorLoaded = true;
}
async function createSevenZip(file, stdout, stderr) {
    await loadVendor();
    const module = await SevenZip({
        noExitRuntime:true,
        locateFile:function (path) { return String(path || '').endsWith('.wasm') ? VENDOR_WASM : path; },
        print:function (line) { stdout.push(String(line || '')); },
        printErr:function (line) { stderr.push(String(line || '')); }
    });
    module.FS.mkdir('/input');
    module.FS.mount(module.WORKERFS, {files:[file]}, '/input');
    return module;
}
async function callSevenZip(module, args, stderr) {
    try {
        const result = module.callMain(args);
        if (result && typeof result.then === 'function') await result;
    } catch (error) {
        if (Number(error && error.status) === 0) return;
        throw new Error(cleanSevenZipError(error, stderr));
    }
}
function parseTechnicalList(lines, maximumEntries) {
    const entries = [];
    let record = {};
    function flush() {
        if (!record.Path || record.Size === undefined) { record = {}; return; }
        let path;
        try { path = normalizeMemberPath(record.Path); } catch (ignore) { record = {}; return; }
        const size = Number(record.Size);
        const folder = String(record.Folder || '').trim() === '+';
        const linked = String(record['Symbolic Link'] || '').trim() !== ''
            || String(record['Hard Link'] || '').trim() !== '';
        if (!folder && Number.isFinite(size) && size >= 0) {
            entries.push({
                path:path, name:basename(path), size:size,
                packed_size:Math.max(0, Number(record['Packed Size'] || 0)),
                encrypted:String(record.Encrypted || '').trim() === '+',
                linked:linked, method:String(record.Method || ''), block:String(record.Block || '')
            });
            if (entries.length > maximumEntries) throw new Error('Archive contains too many file entries.');
        }
        record = {};
    }
    lines.forEach(function (line) {
        const text = String(line || '');
        if (text.trim() === '') { flush(); return; }
        const match = text.match(/^([^=]+?)\s=\s(.*)$/);
        if (!match) return;
        const key = match[1].trim();
        if (key === 'Path' && record.Path !== undefined) flush();
        record[key] = match[2];
    });
    flush();
    return entries;
}
function readAt(position, length) {
    if (!sevenZip || !activeStream || position < 0 || length < 0 || position + length > activeSize) {
        throw new Error('Archive member read is outside the extracted member.');
    }
    const bytes = new Uint8Array(length);
    const read = sevenZip.FS.read(activeStream, bytes, 0, length, position);
    if (read !== length) throw new Error('Archive member read stopped early.');
    return bytes;
}
async function inflateZlibBytes(bytes, expectedBytes) {
    if (typeof DecompressionStream !== 'function') throw new Error('This browser cannot decode zlib redirects inside archives.');
    let output;
    try {
        output = new Uint8Array(await new Response(
            new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate'))
        ).arrayBuffer());
    } catch (error) {
        throw new Error('Could not decode the zlib redirect record inside the archive.');
    }
    if (expectedBytes > 0 && output.length !== expectedBytes) {
        throw new Error('Decoded redirect size mismatch: expected=' + expectedBytes + ', decoded=' + output.length + '.');
    }
    return output;
}
async function inspectDirectPackage(id, name) {
    const head = readAt(0, Math.min(activeSize, 4096));
    if (!packageMagic(head)) {
        const magic = head.slice(0, Math.min(4, head.length));
        throw new Error('Magic not found: ' + name
            + ' (actual_magic_hex=' + (bytesHex(magic) || 'empty')
            + ', actual_magic_text=' + (printableBytes(magic) || 'empty')
            + ', expected_magic_hex=C1832A9E|9E2A83C1).');
    }
    const md5 = new Md5(), sha1 = new Sha1();
    let done = 0;
    while (done < activeSize) {
        const length = Math.min(HASH_CHUNK_BYTES, activeSize - done);
        const bytes = readAt(done, length);
        md5.update(bytes); sha1.update(bytes); done += length;
        emitProgress(id, 'hash', 'Hashing extracted package ' + name + '.', done, activeSize);
    }
    return {md5:md5.digestHex(), sha1:sha1.digestHex(), identity_size:activeSize,
        guid:legacyGuidFromHead(head), extension:extensionOf(name), redirect:false,
        header:{kind:'package', description:'Unreal package magic'}};
}
async function inspectUz2(id, name) {
    const md5 = new Md5(), sha1 = new Sha1();
    let offset = 0, decodedBytes = 0, records = 0;
    let firstDecoded = new Uint8Array(0);
    if (activeSize < 9) {
        throw new Error('UZ2 file is incomplete/cut by ' + (9 - activeSize) + ' bytes: ' + name
            + ' (actual_file_size=' + activeSize + ', minimum_file_size=9).');
    }
    while (offset < activeSize) {
        if (offset + 8 > activeSize) {
            const availableHeaderBytes = Math.max(0, activeSize - offset);
            throw new Error('UZ2 file is incomplete/cut by ' + (8 - availableHeaderBytes) + ' bytes: ' + name
                + ' (record=' + (records + 1)
                + ', record_offset=' + offset
                + ', required_header_bytes=8'
                + ', available_header_bytes=' + availableHeaderBytes
                + ', actual_file_size=' + activeSize + ').');
        }
        const header = readAt(offset, 8);
        const compressed = readU32Le(header, 0), uncompressed = readU32Le(header, 4);
        const recordOffset = offset;
        offset += 8;
        if (compressed < 1 || compressed > 33096 || uncompressed < 1 || uncompressed > 32768) {
            throw new Error('Invalid UZ2 format: ' + name
                + ' (record=' + (records + 1)
                + ', record_offset=' + recordOffset
                + ', compressed_size=' + compressed
                + ', uncompressed_size=' + uncompressed
                + ', max_compressed_size=33096'
                + ', max_uncompressed_size=32768).');
        }
        const availableBytes = Math.max(0, activeSize - offset);
        if (compressed > availableBytes) {
            const missingBytes = compressed - availableBytes;
            throw new Error('UZ2 file is incomplete/cut by ' + missingBytes + ' bytes: ' + name
                + ' (record=' + (records + 1)
                + ', record_offset=' + recordOffset
                + ', payload_offset=' + offset
                + ', compressed_size=' + compressed
                + ', uncompressed_size=' + uncompressed
                + ', available_bytes=' + availableBytes
                + ', actual_file_size=' + activeSize
                + ', required_file_size=' + (offset + compressed) + ').');
        }
        const payload = readAt(offset, compressed);
        let decoded;
        try {
            decoded = await inflateZlibBytes(payload, uncompressed);
        } catch (error) {
            throw new Error('Cannot decompress UZ2 record ' + (records + 1) + ': ' + name
                + ' (record_offset=' + recordOffset
                + ', payload_offset=' + (recordOffset + 8)
                + ', compressed_size=' + compressed
                + ', uncompressed_size=' + uncompressed
                + ', payload_head_hex=' + bytesHex(payload, 8) + ').');
        }
        offset += compressed;
        if (records === 0) {
            firstDecoded = decoded.slice(0, Math.min(decoded.length, 64));
            if (!packageMagic(firstDecoded)) {
                const magic = firstDecoded.slice(0, Math.min(4, firstDecoded.length));
                throw new Error('Magic not found: ' + name
                    + ' (record=1'
                    + ', redirect_format=UZ2'
                    + ', actual_magic_hex=' + (bytesHex(magic) || 'empty')
                    + ', actual_magic_text=' + (printableBytes(magic) || 'empty')
                    + ', expected_magic_hex=C1832A9E|9E2A83C1).');
            }
        }
        md5.update(decoded); sha1.update(decoded); decodedBytes += decoded.length; records++;
        emitProgress(id, 'redirect-hash', 'Decoding/hash identity for ' + name + '.', offset, activeSize);
    }
    return {md5:md5.digestHex(), sha1:sha1.digestHex(), identity_size:decodedBytes,
        guid:legacyGuidFromHead(firstDecoded), extension:'uz2', redirect:true,
        header:{kind:'redirect-uz2', description:'Epic UZ2 decoded package identity'}};
}
async function inspectUz3(id, name) {
    if (activeSize < 9) {
        throw new Error('UZ3 file is incomplete/cut by ' + (9 - activeSize) + ' bytes: ' + name
            + ' (actual_file_size=' + activeSize + ', minimum_file_size=9).');
    }
    const header = readAt(0, 8);
    const signature = readU32Le(header, 0), expected = readU32Le(header, 4);
    if (signature !== 5678) {
        throw new Error('Invalid UZ3 format: ' + name
            + ' (actual_tag=' + signature + ', expected_tag=5678, uncompressed_size=' + expected + ').');
    }
    if (expected < 1) {
        throw new Error('Invalid UZ3 format: ' + name + ' (uncompressed_size=' + expected + ', minimum_size=1).');
    }
    if (typeof DecompressionStream !== 'function' || typeof ReadableStream !== 'function') {
        throw new Error('This browser cannot decode .uz3 members inside archives.');
    }
    let compressedOffset = 8;
    const source = new ReadableStream({
        pull:function (controller) {
            if (compressedOffset >= activeSize) { controller.close(); return; }
            const length = Math.min(IO_CHUNK_BYTES, activeSize - compressedOffset);
            const bytes = readAt(compressedOffset, length);
            compressedOffset += length;
            controller.enqueue(bytes);
        }
    });
    const reader = source.pipeThrough(new DecompressionStream('deflate')).getReader();
    const md5 = new Md5(), sha1 = new Sha1();
    let outputBytes = 0;
    let firstDecoded = new Uint8Array(0);
    try {
        while (true) {
            const result = await reader.read();
            if (result.done) break;
            const decoded = result.value instanceof Uint8Array ? result.value : new Uint8Array(result.value || 0);
            if (!decoded.length) continue;
            if (firstDecoded.length < 64) {
                const take = Math.min(64 - firstDecoded.length, decoded.length);
                const joined = new Uint8Array(firstDecoded.length + take);
                joined.set(firstDecoded, 0); joined.set(decoded.subarray(0, take), firstDecoded.length);
                firstDecoded = joined;
            }
            md5.update(decoded); sha1.update(decoded); outputBytes += decoded.length;
            emitProgress(id, 'redirect-hash', 'Decoding/hash identity for ' + name + '.',
                Math.min(expected, outputBytes), expected);
        }
    } catch (error) {
        const payloadHead = readAt(8, Math.min(8, Math.max(0, activeSize - 8)));
        throw new Error('Cannot decompress UZ3: ' + name
            + ' (tag=5678, uncompressed_size=' + expected
            + ', compressed_payload_bytes=' + Math.max(0, activeSize - 8)
            + ', payload_head_hex=' + bytesHex(payloadHead, 8) + ').');
    }
    if (outputBytes !== expected) {
        throw new Error('Invalid decompressed UZ3 size: ' + name
            + ' (expected_size=' + expected + ', actual_size=' + outputBytes + ').');
    }
    if (!packageMagic(firstDecoded)) {
        const magic = firstDecoded.slice(0, Math.min(4, firstDecoded.length));
        throw new Error('Magic not found: ' + name
            + ' (redirect_format=UZ3'
            + ', actual_magic_hex=' + (bytesHex(magic) || 'empty')
            + ', actual_magic_text=' + (printableBytes(magic) || 'empty')
            + ', expected_magic_hex=C1832A9E|9E2A83C1).');
    }
    return {md5:md5.digestHex(), sha1:sha1.digestHex(), identity_size:outputBytes,
        guid:legacyGuidFromHead(firstDecoded), extension:'uz3', redirect:true,
        header:{kind:'redirect-uz3', description:'Epic UZ3 decoded package identity'}};
}
async function inspectUz(id, name, maxFileBytes) {
    const limit=Math.max(1,Number(maxFileBytes||(512*1024*1024)));
    let encoded=readAt(0,activeSize);
    emitProgress(id,'redirect-decode','Decoding FCodec identity for '+name+'.',0,activeSize);
    let decoded;
    try {
        decoded=self.UnrealDbLegacyUzDecoder.decode(encoded,limit);
    } catch (error) {
        throw new Error('Cannot decompress/unpack UZ redirect: ' + name
            + ' (compressed_size=' + activeSize
            + ', output_limit=' + limit
            + ', decoder_error=' + (error && error.message ? error.message : 'unknown') + ').');
    } finally {
        encoded=null;
    }

    const output=decoded.data;
    const md5=new Md5(), sha1=new Sha1();
    let done=0;
    while(done<output.length){
        const end=Math.min(output.length,done+HASH_CHUNK_BYTES);
        const chunk=output.subarray(done,end);
        md5.update(chunk); sha1.update(chunk); done=end;
        emitProgress(id,'redirect-hash','Hashing decoded FCodec identity for '+name+'.',done,output.length);
    }
    const firstDecoded=output.subarray(0,Math.min(output.length,64));
    const result={
        md5:md5.digestHex(),
        sha1:sha1.digestHex(),
        identity_size:output.length,
        guid:legacyGuidFromHead(firstDecoded),
        extension:'uz',
        redirect:true,
        embedded_filename:String(decoded.embedded_filename||''),
        wrapper_signature:Number(decoded.wrapper_signature||0),
        header:{kind:'redirect-uz',description:'Unreal FCodec signature '+decoded.wrapper_signature+'; decoded package identity calculated in browser'}
    };
    decoded.data=null;
    return result;
}

async function inspectActiveMember(id, name, maxFileBytes) {
    const extension = extensionOf(name);
    if (extension === 'uz') return inspectUz(id, name, maxFileBytes);
    if (extension === 'uz2') return inspectUz2(id, name);
    if (extension === 'uz3') return inspectUz3(id, name);
    return inspectDirectPackage(id, name);
}
function closeActive() {
    if (sevenZip && activeStream) {
        try { sevenZip.FS.close(activeStream); } catch (ignore) {}
    }
    activeStream = null; activeSize = 0; nextReadOffset = 0;
}
async function listArchive(id, file, maxEntries) {
    const stdout = [], stderr = [];
    const module = await createSevenZip(file, stdout, stderr);
    sevenZip = module;
    const archivePath = '/input/' + file.name;
    emitProgress(id, 'list', 'Reading archive directory.', 0, Number(file.size || 0));
    await callSevenZip(module, ['l', '-slt', '-ba', '-bd', archivePath], stderr);
    const entries = parseTechnicalList(stdout,
        Math.min(MAX_ARCHIVE_ENTRIES, Math.max(1, Number(maxEntries || MAX_ARCHIVE_ENTRIES))));
    emitProgress(id, 'list', 'Archive directory read.', Number(file.size || 0), Number(file.size || 0));
    return entries;
}
async function extractMember(id, file, memberPath, expectedSize, maxFileBytes) {
    const stdout = [], stderr = [];
    const normalized = normalizeMemberPath(memberPath);
    const name = basename(normalized);
    const module = await createSevenZip(file, stdout, stderr);
    sevenZip = module;
    module.FS.mkdir('/out');
    const archivePath = '/input/' + file.name;
    emitProgress(id, 'extract', 'Extracting ' + normalized + '.', 0, Number(expectedSize || 0));
    await callSevenZip(module, ['x', '-y', '-bd', '-bb0', '-spd', '-o/out', archivePath, normalized], stderr);
    const outputPath = '/out/' + normalized;
    let stat;
    try {
        const lstat = module.FS.lstat(outputPath);
        if (module.FS.isLink(lstat.mode)) throw new Error('Archive member is a symbolic link.');
        stat = module.FS.stat(outputPath);
    } catch (error) {
        throw new Error('7-Zip did not produce the requested archive member: ' + normalized + '.');
    }
    if (!module.FS.isFile(stat.mode)) throw new Error('Requested archive member is not a regular file.');
    const size = Math.max(0, Number(stat.size || 0));
    if (size < 1 || size > Math.max(1, Number(maxFileBytes || 0))) {
        throw new Error('Extracted archive member is outside the public upload file-size limit.');
    }
    if (Number(expectedSize || 0) > 0 && size !== Number(expectedSize)) {
        throw new Error('Archive member size changed between listing and extraction.');
    }
    activeSize = size;
    activeStream = module.FS.open(outputPath, 'r');
    nextReadOffset = 0;
    const inspection = await inspectActiveMember(id, name, maxFileBytes);
    emitProgress(id, 'extract', 'Archive member ready.', size, size);
    return {name:name, member_path:normalized, size:size, inspection:inspection};
}
self.addEventListener('message', async function (event) {
    const data = event.data || {};
    const id = String(data.id || '');
    try {
        if (data.type === 'list') {
            self.postMessage({type:'result', id:id, result:{entries:await listArchive(id, data.file, data.max_entries)}});
            return;
        }
        if (data.type === 'extract') {
            const result = await extractMember(id, data.file, data.member_path, data.expected_size, data.max_file_bytes);
            self.postMessage({type:'result', id:id, result:result});
            return;
        }
        if (data.type === 'read') {
            const offset = Math.max(0, Number(data.offset || 0));
            const length = Math.max(0, Number(data.length || 0));
            if (!activeStream || offset !== nextReadOffset || length < 1 || offset + length > activeSize) {
                throw new Error('Archive member chunk order mismatch: expected_offset=' + nextReadOffset
                    + ', received_offset=' + offset + ', length=' + length + '.');
            }
            const bytes = readAt(offset, length);
            nextReadOffset += bytes.length;
            self.postMessage({type:'result', id:id,
                result:{offset:offset, length:bytes.length, buffer:bytes.buffer}}, [bytes.buffer]);
            return;
        }
        if (data.type === 'dispose') {
            closeActive();
            self.postMessage({type:'result', id:id, result:{disposed:true}});
            return;
        }
        throw new Error('Unknown archive worker request.');
    } catch (error) {
        closeActive();
        self.postMessage({type:'error', id:id,
            message:error && error.message ? error.message : 'Browser archive processing failed.'});
    }
});
