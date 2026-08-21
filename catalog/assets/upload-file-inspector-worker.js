'use strict';

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
                this.process(this.buffer);
                this.bufferLength = 0;
            }
        }
        while (offset + 64 <= bytes.length) {
            this.process(bytes.subarray(offset, offset + 64));
            offset += 64;
        }
        if (offset < bytes.length) {
            this.buffer.set(bytes.subarray(offset), 0);
            this.bufferLength = bytes.length - offset;
        }
    }

    process(block) {
        const words = this.words;
        for (let index = 0; index < 16; index++) {
            const position = index * 4;
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
            this.process(finalBlock.subarray(offset, offset + 64));
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
                this.process(this.buffer);
                this.bufferLength = 0;
            }
        }
        while (offset + 64 <= bytes.length) {
            this.process(bytes.subarray(offset, offset + 64));
            offset += 64;
        }
        if (offset < bytes.length) {
            this.buffer.set(bytes.subarray(offset), 0);
            this.bufferLength = bytes.length - offset;
        }
    }

    process(block) {
        const words = this.words;
        for (let index = 0; index < 16; index++) {
            const position = index * 4;
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
            this.process(finalBlock.subarray(offset, offset + 64));
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
        if (signature !== 1234) {
            throw new Error('The .uz file does not contain the expected Unreal 1234 redirect signature.');
        }
        return {kind: 'redirect-uz', description: 'Unreal redirect signature 1234'};
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

async function readBytes(file, start, end) {
    const buffer = await file.slice(start, end).arrayBuffer();
    return new Uint8Array(buffer);
}

async function inspectFile(id, file) {
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

    if (extension === 'uz' || extension === 'uz2' || extension === 'uz3') {
        return {md5: '', sha1: '', extension: extension, redirect: true, header: header};
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
        const result = await inspectFile(id, data.file);
        self.postMessage({type: 'result', id: id, result: result});
    } catch (error) {
        self.postMessage({type: 'error', id: id, message: error && error.message ? error.message : 'File inspection failed.'});
    }
});
