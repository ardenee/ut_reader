'use strict';

const legacyUzDecoderUrl = new URL('legacy-uz-decoder.js', self.location.href);
legacyUzDecoderUrl.search = self.location.search;
importScripts(legacyUzDecoderUrl.href);

const HASH_CHUNK_BYTES = 4 * 1024 * 1024;
const HEADER_READ_BYTES = 4096;
const PAK_TAIL_READ_BYTES = 4096;
const LEGACY_PACKAGE_EXTENSIONS = new Set([
    'u', 'unr', 'utx', 'umx', 'uax', 'un2', 'ut2', 'usx', 'ukx', 'upx', 'ugx',
    'ut3', 'upk', 'uasset', 'umap'
]);
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

function readU32Le(bytes, offset) {
    if (offset < 0 || offset + 4 > bytes.length) return -1;
    return (bytes[offset]
        | (bytes[offset + 1] << 8)
        | (bytes[offset + 2] << 16)
        | (bytes[offset + 3] << 24)) >>> 0;
}

function hasSequence(bytes, sequence) {
    if (sequence.length === 0 || bytes.length < sequence.length) return false;
    outer: for (let offset = 0; offset <= bytes.length - sequence.length; offset++) {
        for (let index = 0; index < sequence.length; index++) {
            if (bytes[offset + index] !== sequence[index]) continue outer;
        }
        return true;
    }
    return false;
}

function packageExtension(extension) {
    return LEGACY_PACKAGE_EXTENSIONS.has(extension)
        || extension.endsWith('_uax')
        || extension.endsWith('_utx');
}

function validateHeader(extension, fileSize, head, tail) {
    if (extension === 'uz') {
        const signature = readU32Le(head, 0);
        if (signature !== 1234 && signature !== 5678) {
            throw new Error(
                'The .uz file does not contain a supported Unreal FCodec signature. '
                + 'Expected 1234 or 5678; detected ' + signature + '.'
            );
        }
        return {kind:'redirect-uz', description:'Unreal FCodec redirect signature ' + signature};
    }

    if (extension === 'uz3') {
        const signature = readU32Le(head, 0);
        if (signature !== 5678) {
            throw new Error('The .uz3 file does not contain the expected Unreal 5678 redirect signature.');
        }
        return {kind: 'redirect-uz3', description: 'Unreal redirect signature 5678'};
    }

    if (extension === 'uz2') {
        if (fileSize < 10 || head.length < 10) {
            throw new Error('The .uz2 file is too small to contain an Epic redirect record.');
        }
        const compressed = readU32Le(head, 0);
        const uncompressed = readU32Le(head, 4);
        if (compressed < 1 || compressed > 33096 || uncompressed < 1 || uncompressed > 32768 || 8 + compressed > fileSize) {
            throw new Error('The .uz2 file does not contain a valid first Epic redirect record.');
        }
        const cmf = head[8];
        const flg = head[9];
        if ((cmf & 0x0f) !== 8 || (((cmf << 8) | flg) % 31) !== 0) {
            throw new Error('The .uz2 first record does not begin with a valid zlib stream.');
        }
        return {kind: 'redirect-uz2', description: 'Epic UZ2 zlib record header'};
    }

    if (extension === 'pak') {
        const littleEndianMagic = [0xe1, 0x12, 0x6f, 0x5a];
        const bigEndianMagic = [0x5a, 0x6f, 0x12, 0xe1];
        if (!hasSequence(tail, littleEndianMagic) && !hasSequence(tail, bigEndianMagic)) {
            throw new Error('The .pak file does not contain an Unreal PAK footer magic in its final bytes.');
        }
        return {kind: 'pak', description: 'Unreal PAK footer magic'};
    }

    if (packageExtension(extension)) {
        const littleEndianMagic = head.length >= 4
            && head[0] === 0xc1 && head[1] === 0x83 && head[2] === 0x2a && head[3] === 0x9e;
        const bigEndianMagic = head.length >= 4
            && head[0] === 0x9e && head[1] === 0x2a && head[2] === 0x83 && head[3] === 0xc1;
        if (!littleEndianMagic && !bigEndianMagic) {
            throw new Error('The file extension identifies an Unreal package, but the Unreal package magic is missing.');
        }
        return {kind: 'package', description: 'Unreal package magic'};
    }

    return {kind: 'extension-only', description: 'No safe client-side magic rule is defined for this extension'};
}

function packageMagic(bytes) {
    return bytes.length >= 4 && (
        (bytes[0] === 0xc1 && bytes[1] === 0x83 && bytes[2] === 0x2a && bytes[3] === 0x9e)
        || (bytes[0] === 0x9e && bytes[1] === 0x2a && bytes[2] === 0x83 && bytes[3] === 0xc1)
    );
}

function legacyGuidFromDecodedHead(bytes) {
    if (!packageMagic(bytes) || bytes.length < 52) return '';
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const littleEndian = view.getUint32(0, true) === 0x9e2a83c1;
    const packed = view.getUint32(4, littleEndian);
    const version = packed & 0xffff;
    if (version < 1 || version >= 200) return '';
    const guidOffset = version < 68 ? 44 : 36;
    if (bytes.length < guidOffset + 16) return '';
    if (version < 68) {
        const nameOffset = view.getInt32(16, littleEndian);
        if (nameOffset < guidOffset + 16) return '';
    }
    const parts = [];
    for (let offset = guidOffset; offset < guidOffset + 16; offset += 4) {
        parts.push(view.getUint32(offset, littleEndian).toString(16).toUpperCase().padStart(8, '0'));
    }
    return parts.join('-');
}

async function inflateZlibBytes(bytes, expectedBytes) {
    if (typeof DecompressionStream !== 'function') {
        throw new Error(
            'This browser cannot decode Unreal zlib redirects for duplicate checking. '
            + 'Use a current browser or contribute the uncompressed package.'
        );
    }
    let output;
    try {
        const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate'));
        output = new Uint8Array(await new Response(stream).arrayBuffer());
    } catch (error) {
        throw new Error('Could not decode the Unreal zlib redirect record in the browser.');
    }
    if (expectedBytes > 0 && output.length !== expectedBytes) {
        throw new Error(
            'Decoded Unreal redirect size mismatch: expected ' + expectedBytes + ' bytes, got ' + output.length + '.'
        );
    }
    return output;
}

async function inspectUz2(id, file) {
    const total = Math.max(0, Number(file.size || 0));
    const md5 = new Md5();
    const sha1 = new Sha1();
    let offset = 0;
    let outputBytes = 0;
    let chunks = 0;
    let firstDecoded = new Uint8Array(0);

    while (offset < total) {
        if (offset + 8 > total) {
            throw new Error('Incomplete Epic UZ2 record header at byte ' + offset + '.');
        }
        const header = await readBytes(file, offset, offset + 8);
        const compressed = readU32Le(header, 0);
        const uncompressed = readU32Le(header, 4);
        const recordOffset = offset;
        offset += 8;

        if (compressed < 1 || compressed > 33096 || uncompressed < 1 || uncompressed > 32768 || offset + compressed > total) {
            throw new Error(
                'Invalid Epic UZ2 record ' + (chunks + 1)
                + ' (compressed=' + compressed
                + ', uncompressed=' + uncompressed
                + ', offset=' + recordOffset
                + ', remaining=' + Math.max(0, total - offset) + ').'
            );
        }

        const payload = await readBytes(file, offset, offset + compressed);
        offset += compressed;
        const decoded = await inflateZlibBytes(payload, uncompressed);
        if (chunks === 0) {
            firstDecoded = decoded.slice(0, Math.min(decoded.length, 64));
            if (!packageMagic(firstDecoded)) {
                throw new Error('Epic UZ2 decoded output does not begin with an Unreal package magic.');
            }
        }
        md5.update(decoded);
        sha1.update(decoded);
        outputBytes += decoded.length;
        chunks++;
        self.postMessage({
            type: 'progress',
            id: id,
            phase: 'redirect-hash',
            loaded: offset,
            total: total,
            output: outputBytes,
            chunks: chunks
        });
    }

    if (chunks < 1 || offset !== total || outputBytes < 1) {
        throw new Error(
            'Incomplete Epic UZ2 redirect stream: records=' + chunks
            + ', compressed=' + offset + '/' + total
            + ', output=' + outputBytes + '.'
        );
    }

    return {
        md5: md5.digestHex(),
        sha1: sha1.digestHex(),
        identity_size: outputBytes,
        guid: legacyGuidFromDecodedHead(firstDecoded),
        extension: 'uz2',
        redirect: true,
        header: {
            kind: 'redirect-uz2',
            description: 'Epic UZ2 zlib records; decoded package identity calculated in browser'
        }
    };
}

async function inspectUz3(id, file) {
    const total = Math.max(0, Number(file.size || 0));
    if (total < 10) {
        throw new Error('The .uz3 file is too small to contain an Epic redirect payload.');
    }
    const header = await readBytes(file, 0, 8);
    const signature = readU32Le(header, 0);
    const expected = readU32Le(header, 4);
    if (signature !== 5678 || expected < 1) {
        throw new Error(
            'The .uz3 file does not contain a valid Epic redirect header: signature='
            + signature + ', uncompressed=' + expected + '.'
        );
    }
    if (typeof DecompressionStream !== 'function') {
        throw new Error(
            'This browser cannot decode Unreal zlib redirects for duplicate checking. '
            + 'Use a current browser or contribute the uncompressed package.'
        );
    }

    const md5 = new Md5();
    const sha1 = new Sha1();
    let outputBytes = 0;
    let firstDecoded = new Uint8Array(0);
    let reader;
    try {
        reader = file.slice(8).stream().pipeThrough(new DecompressionStream('deflate')).getReader();
        while (true) {
            const result = await reader.read();
            if (result.done) break;
            const decoded = result.value instanceof Uint8Array ? result.value : new Uint8Array(result.value || 0);
            if (!decoded.length) continue;
            if (firstDecoded.length < 64) {
                const need = Math.min(64 - firstDecoded.length, decoded.length);
                const joined = new Uint8Array(firstDecoded.length + need);
                joined.set(firstDecoded, 0);
                joined.set(decoded.subarray(0, need), firstDecoded.length);
                firstDecoded = joined;
            }
            md5.update(decoded);
            sha1.update(decoded);
            outputBytes += decoded.length;
            self.postMessage({
                type: 'progress',
                id: id,
                phase: 'redirect-hash',
                loaded: Math.min(expected, outputBytes),
                total: expected,
                output: outputBytes,
                chunks: 1
            });
        }
    } catch (error) {
        throw new Error('Could not decode the Epic UZ3 zlib stream in the browser.');
    }

    if (outputBytes !== expected) {
        throw new Error(
            'Decoded Epic UZ3 size mismatch: expected ' + expected + ' bytes, got ' + outputBytes + '.'
        );
    }
    if (!packageMagic(firstDecoded)) {
        throw new Error('Epic UZ3 decoded output does not begin with an Unreal package magic.');
    }

    return {
        md5: md5.digestHex(),
        sha1: sha1.digestHex(),
        identity_size: outputBytes,
        guid: legacyGuidFromDecodedHead(firstDecoded),
        extension: 'uz3',
        redirect: true,
        header: {
            kind: 'redirect-uz3',
            description: 'Epic UZ3 zlib stream; decoded package identity calculated in browser'
        }
    };
}

async function readBytes(file, start, end) {
    const buffer = await file.slice(start, end).arrayBuffer();
    return new Uint8Array(buffer);
}

async function inspectUz(id, file, maxFileBytes) {
    const total=Math.max(0,Number(file.size||0));
    const limit=Math.max(1,Number(maxFileBytes||(512*1024*1024)));
    self.postMessage({type:'progress',id:id,phase:'redirect-decode',loaded:0,total:total});

    let encoded=await readBytes(file,0,total);
    let decoded;
    try {
        decoded=self.UnrealDbLegacyUzDecoder.decode(encoded,limit);
    } catch (error) {
        throw new Error('Could not decode legacy .uz FCodec redirect: '
            + (error && error.message ? error.message : 'unknown decoder error'));
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
        self.postMessage({type:'progress',id:id,phase:'redirect-hash',loaded:done,total:output.length});
    }
    const firstDecoded=output.subarray(0,Math.min(output.length,64));
    const result={
        md5:md5.digestHex(),
        sha1:sha1.digestHex(),
        identity_size:output.length,
        guid:legacyGuidFromDecodedHead(firstDecoded),
        extension:'uz',
        redirect:true,
        embedded_filename:String(decoded.embedded_filename||''),
        wrapper_signature:Number(decoded.wrapper_signature||0),
        header:{kind:'redirect-uz',description:'Unreal FCodec signature '+decoded.wrapper_signature+'; decoded package identity calculated in browser'}
    };
    decoded.data=null;
    return result;
}

async function inspectFile(id, file, maxFileBytes) {
    if (!file || typeof file.slice !== 'function') {
        throw new Error('The selected browser file cannot be read.');
    }
    const total = Math.max(0, Number(file.size || 0));
    if (total < 1) throw new Error('Empty files cannot be uploaded.');

    const extension = extensionOf(file.name);
    self.postMessage({type: 'progress', id: id, phase: 'header', loaded: 0, total: total});
    const head = await readBytes(file, 0, Math.min(total, HEADER_READ_BYTES));
    const tailStart = Math.max(0, total - PAK_TAIL_READ_BYTES);
    const tail = extension === 'pak' ? await readBytes(file, tailStart, total) : new Uint8Array(0);
    const header = validateHeader(extension, total, head, tail);
    self.postMessage({type: 'progress', id: id, phase: 'header', loaded: total, total: total, header: header});

    if (extension === 'uz2') {
        return inspectUz2(id, file);
    }
    if (extension === 'uz3') {
        return inspectUz3(id, file);
    }
    if (extension === 'uz') {
        return inspectUz(id, file, maxFileBytes);
    }

    const md5 = new Md5();
    const sha1 = new Sha1();
    let done = 0;
    while (done < total) {
        const end = Math.min(total, done + HASH_CHUNK_BYTES);
        const bytes = await readBytes(file, done, end);
        if (bytes.length !== end - done) {
            throw new Error('The selected file changed while it was being inspected.');
        }
        md5.update(bytes);
        sha1.update(bytes);
        done = end;
        self.postMessage({type: 'progress', id: id, phase: 'hash', loaded: done, total: total, header: header});
    }
    return {
        md5: md5.digestHex(),
        sha1: sha1.digestHex(),
        identity_size: total,
        guid: '',
        extension: extension,
        redirect: false,
        header: header
    };
}

self.addEventListener('message', async function (event) {
    const data = event.data || {};
    if (data.type !== 'inspect') return;
    const id = String(data.id || '');
    try {
        const result = await inspectFile(id, data.file, data.max_file_bytes);
        self.postMessage({type: 'result', id: id, result: result});
    } catch (error) {
        self.postMessage({type: 'error', id: id, message: error && error.message ? error.message : 'File inspection failed.'});
    }
});
