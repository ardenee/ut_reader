(function (root) {
    'use strict';

    // A filename is not an Unreal engine discriminator. Read bounded leading
    // bytes and an indexed footer candidate; do not scan or upload full files.
    async function inspect(file) {
        if (!file || !/\.pak$/i.test(String(file.name || ''))) return {kind: 'not_pak'};
        const total = Number(file.size || 0);
        if (!Number.isSafeInteger(total) || total < 8) return {kind: 'unknown', reason: 'too small'};
        const head = new DataView(await file.slice(0, Math.min(16, total)).arrayBuffer());
        if (head.byteLength >= 8 && head.getUint32(0, true) === 0x9E2A83C1) {
            const version = head.getUint16(4, true);
            return {kind: version === 512 ? 'ut3_package' : 'legacy_unreal_package',
                version: version};
        }
        if (total < 44) return {kind: 'unknown', reason: 'no Unreal package header or PAK footer'};
        const sampleLength = Math.min(256 * 1024, total);
        const data = new DataView(await file.slice(total - sampleLength, total).arrayBuffer());
        const base = total - sampleLength;
        const candidate = function (pos, magicLast) {
            const start = magicLast ? pos - 40 : pos;
            if (start < 0 || start + 44 > sampleLength) return false;
            const version = data.getInt32(magicLast ? pos - 4 : pos + 4, true);
            if (version < 1 || version > 20) return false;
            const offset = data.getBigUint64(magicLast ? pos - 20 : pos + 8, true);
            const length = data.getBigUint64(magicLast ? pos - 12 : pos + 16, true);
            return length > 0n && offset + length <= BigInt(total)
                && offset + length <= BigInt(base + start);
        };
        const tail = new Uint8Array(data.buffer);
        for (let p = sampleLength - 4; p >= 0; p--) {
            if (tail[p] === 0xE1 && tail[p + 1] === 0x12
                && tail[p + 2] === 0x6F && tail[p + 3] === 0x5A
                && (candidate(p, false) || candidate(p, true))) {
                return {kind: 'unrealpak', footer: true};
            }
        }
        return {kind: 'unknown', reason: 'no recognised footer in final 256 KiB'};
    }

    root.CatalogPakDetection = Object.freeze({inspect: inspect});
}(typeof window !== 'undefined' ? window : globalThis));
