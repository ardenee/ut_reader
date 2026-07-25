(function (global) {
    'use strict';

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
            const words = new Int32Array(16);
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
            const shifts = [
                7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22, 7, 12, 17, 22,
                5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20, 5, 9, 14, 20,
                4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23, 4, 11, 16, 23,
                6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21, 6, 10, 15, 21
            ];

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

                const constant = Math.floor(Math.abs(Math.sin(index + 1)) * 0x100000000) | 0;
                const previousD = d;
                d = c;
                c = b;
                const sum = (a + functionValue + constant + words[wordIndex]) | 0;
                b = (b + rotateLeft(sum, shifts[index])) | 0;
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
            const words = new Int32Array(80);
            for (let index = 0; index < 16; index++) {
                const position = index * 4;
                words[index] = ((block[position] << 24)
                    | (block[position + 1] << 16)
                    | (block[position + 2] << 8)
                    | block[position + 3]) | 0;
            }
            for (let index = 16; index < 80; index++) {
                words[index] = rotateLeft(
                    words[index - 3] ^ words[index - 8] ^ words[index - 14] ^ words[index - 16],
                    1
                );
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

    async function hashFile(file, onProgress) {
        if (!file || typeof file.slice !== 'function') {
            throw new Error('The selected browser file cannot be read for duplicate checking.');
        }

        const md5 = new Md5();
        const sha1 = new Sha1();
        const total = Math.max(0, Number(file.size || 0));
        const readBytes = 4 * 1024 * 1024;
        let done = 0;

        while (done < total) {
            const end = Math.min(total, done + readBytes);
            const buffer = await file.slice(done, end).arrayBuffer();
            const bytes = new Uint8Array(buffer);
            if (bytes.length !== end - done) {
                throw new Error('The selected file changed while its hashes were being calculated.');
            }
            md5.update(bytes);
            sha1.update(bytes);
            done = end;
            if (typeof onProgress === 'function') {
                onProgress(done, total);
            }
            await new Promise(function (resolve) { global.setTimeout(resolve, 0); });
        }

        return {
            md5: md5.digestHex(),
            sha1: sha1.digestHex()
        };
    }

    global.UnrealDbUploadHash = {
        hashFile: hashFile
    };
}(window));
