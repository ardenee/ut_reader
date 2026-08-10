'use strict';

// Browser-side duplicate preflight only. This digest is advisory: the server
// recalculates authoritative hashes for every file that is actually uploaded.
const CHUNK_BYTES = 4 * 1024 * 1024;
const SHA1_ABC = 'a9993e364706816aba3e25717850c26c9cd0d89d';
let cancelRequested = false;
let selfTestPassed = null;

function rotl(value, bits) {
    return ((value << bits) | (value >>> (32 - bits))) >>> 0;
}

class Sha1 {
    constructor() {
        this.h0 = 0x67452301;
        this.h1 = 0xEFCDAB89;
        this.h2 = 0x98BADCFE;
        this.h3 = 0x10325476;
        this.h4 = 0xC3D2E1F0;
        this.buffer = new Uint8Array(64);
        this.bufferLength = 0;
        this.bytesHashed = 0;
        this.words = new Uint32Array(80);
    }

    update(input) {
        if (!(input instanceof Uint8Array)) input = new Uint8Array(input);
        this.bytesHashed += input.length;
        let offset = 0;

        if (this.bufferLength > 0) {
            const needed = 64 - this.bufferLength;
            const take = Math.min(needed, input.length);
            this.buffer.set(input.subarray(0, take), this.bufferLength);
            this.bufferLength += take;
            offset += take;
            if (this.bufferLength === 64) {
                this.processBlock(this.buffer, 0);
                this.bufferLength = 0;
            }
        }

        while (offset + 64 <= input.length) {
            this.processBlock(input, offset);
            offset += 64;
        }

        if (offset < input.length) {
            const remaining = input.subarray(offset);
            this.buffer.set(remaining, 0);
            this.bufferLength = remaining.length;
        }
    }

    processBlock(data, offset) {
        const w = this.words;
        for (let i = 0; i < 16; i++) {
            const p = offset + i * 4;
            w[i] = (
                (data[p] << 24)
                | (data[p + 1] << 16)
                | (data[p + 2] << 8)
                | data[p + 3]
            ) >>> 0;
        }
        for (let i = 16; i < 80; i++) {
            w[i] = rotl((w[i - 3] ^ w[i - 8] ^ w[i - 14] ^ w[i - 16]) >>> 0, 1);
        }

        let a = this.h0;
        let b = this.h1;
        let c = this.h2;
        let d = this.h3;
        let e = this.h4;

        for (let i = 0; i < 80; i++) {
            let f;
            let k;
            if (i < 20) {
                f = ((b & c) | ((~b) & d)) >>> 0;
                k = 0x5A827999;
            } else if (i < 40) {
                f = (b ^ c ^ d) >>> 0;
                k = 0x6ED9EBA1;
            } else if (i < 60) {
                f = ((b & c) | (b & d) | (c & d)) >>> 0;
                k = 0x8F1BBCDC;
            } else {
                f = (b ^ c ^ d) >>> 0;
                k = 0xCA62C1D6;
            }

            const temp = (rotl(a, 5) + f + e + k + w[i]) >>> 0;
            e = d;
            d = c;
            c = rotl(b, 30);
            b = a;
            a = temp;
        }

        this.h0 = (this.h0 + a) >>> 0;
        this.h1 = (this.h1 + b) >>> 0;
        this.h2 = (this.h2 + c) >>> 0;
        this.h3 = (this.h3 + d) >>> 0;
        this.h4 = (this.h4 + e) >>> 0;
    }

    digestHex() {
        const bitLength = this.bytesHashed * 8;
        const tailLength = this.bufferLength < 56 ? 64 : 128;
        const tail = new Uint8Array(tailLength);
        tail.set(this.buffer.subarray(0, this.bufferLength), 0);
        tail[this.bufferLength] = 0x80;

        const high = Math.floor(bitLength / 0x100000000);
        const low = bitLength >>> 0;
        const view = new DataView(tail.buffer);
        view.setUint32(tailLength - 8, high >>> 0, false);
        view.setUint32(tailLength - 4, low, false);

        for (let offset = 0; offset < tail.length; offset += 64) {
            this.processBlock(tail, offset);
        }

        return [this.h0, this.h1, this.h2, this.h3, this.h4]
            .map(function (value) { return value.toString(16).padStart(8, '0'); })
            .join('');
    }
}

function verifySha1Implementation() {
    if (selfTestPassed !== null) return selfTestPassed;
    const probe = new Sha1();
    probe.update(new Uint8Array([0x61, 0x62, 0x63]));
    selfTestPassed = probe.digestHex() === SHA1_ABC;
    return selfTestPassed;
}

async function hashFile(id, file) {
    cancelRequested = false;
    if (!verifySha1Implementation()) {
        throw new Error('Client SHA-1 self-test failed; duplicate preflight has been disabled for this file.');
    }

    const sha1 = new Sha1();
    const total = Number(file.size || 0);
    let loaded = 0;
    const started = Date.now();

    while (loaded < total) {
        if (cancelRequested) {
            self.postMessage({type: 'cancelled', id: id});
            return;
        }
        const end = Math.min(total, loaded + CHUNK_BYTES);
        const buffer = await file.slice(loaded, end).arrayBuffer();
        if (cancelRequested) {
            self.postMessage({type: 'cancelled', id: id});
            return;
        }
        sha1.update(new Uint8Array(buffer));
        loaded = end;
        self.postMessage({
            type: 'progress',
            id: id,
            loaded: loaded,
            total: total,
            elapsed_ms: Math.max(1, Date.now() - started)
        });
    }

    if (total === 0) {
        self.postMessage({type: 'error', id: id, message: 'Cannot hash an empty file.'});
        return;
    }

    self.postMessage({type: 'done', id: id, sha1: sha1.digestHex(), size: total});
}

self.onmessage = function (event) {
    const message = event.data || {};
    if (message.type === 'cancel') {
        cancelRequested = true;
        return;
    }
    if (message.type !== 'hash' || !message.file) return;

    hashFile(String(message.id || ''), message.file).catch(function (error) {
        self.postMessage({
            type: 'error',
            id: String(message.id || ''),
            message: error && error.message ? error.message : 'Client hash failed.'
        });
    });
};
