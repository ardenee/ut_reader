'use strict';

/*
 * Shared browser-side redirect reader.
 *
 * Format parsing belongs here. Callers provide only a byte source and the
 * environment-specific decompressor/progress callbacks, allowing direct File
 * uploads and members in the 7-Zip WASM filesystem to use identical rules.
 */
(function (scope) {
    if (scope.UnrealDbRedirectReader) return;

    const UZ2_HEADER_BYTES = 8;
    const UZ2_MINIMUM_FILE_BYTES = 9;
    const UZ2_MAX_COMPRESSED_BYTES = 33096;
    const UZ2_MAX_UNCOMPRESSED_BYTES = 32768;

    function readU32Le(bytes, offset) {
        if (!(bytes instanceof Uint8Array) || offset < 0 || offset + 4 > bytes.length) return -1;
        return (bytes[offset]
            | (bytes[offset + 1] << 8)
            | (bytes[offset + 2] << 16)
            | (bytes[offset + 3] << 24)) >>> 0;
    }

    function bytesHex(bytes, limit) {
        const length = Math.min(bytes.length, Math.max(0, Number(limit || bytes.length)));
        let value = '';
        for (let index = 0; index < length; index++) {
            value += bytes[index].toString(16).padStart(2, '0');
        }
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

    function packageMagic(bytes) {
        return bytes.length >= 4 && (
            (bytes[0] === 0xc1 && bytes[1] === 0x83 && bytes[2] === 0x2a && bytes[3] === 0x9e)
            || (bytes[0] === 0x9e && bytes[1] === 0x2a && bytes[2] === 0x83 && bytes[3] === 0xc1)
            || (bytes[0] === 0xc2 && bytes[1] === 0x83 && bytes[2] === 0x2a && bytes[3] === 0x9e)
        );
    }

    function requireMinimumUz2Size(fileSize, fileName) {
        if (fileSize >= UZ2_MINIMUM_FILE_BYTES) return;
        throw new Error('UZ2 file is incomplete/cut by ' + (UZ2_MINIMUM_FILE_BYTES - fileSize) + ' bytes: ' + fileName
            + ' (actual_file_size=' + fileSize + ', minimum_file_size=' + UZ2_MINIMUM_FILE_BYTES + ').');
    }

    function validateUz2Record(compressed, uncompressed, record, recordOffset, fileName) {
        if (compressed >= 1 && compressed <= UZ2_MAX_COMPRESSED_BYTES
            && uncompressed >= 1 && uncompressed <= UZ2_MAX_UNCOMPRESSED_BYTES) {
            return;
        }
        throw new Error('Invalid UZ2 format: ' + fileName
            + ' (record=' + record
            + ', record_offset=' + recordOffset
            + ', compressed_size=' + compressed
            + ', uncompressed_size=' + uncompressed
            + ', max_compressed_size=' + UZ2_MAX_COMPRESSED_BYTES
            + ', max_uncompressed_size=' + UZ2_MAX_UNCOMPRESSED_BYTES + ').');
    }

    function requireUz2Payload(compressed, uncompressed, availableBytes, record, recordOffset, payloadOffset, fileSize, fileName) {
        if (compressed <= availableBytes) return;
        const missingBytes = compressed - availableBytes;
        throw new Error('UZ2 file is incomplete/cut by ' + missingBytes + ' bytes: ' + fileName
            + ' (record=' + record
            + ', record_offset=' + recordOffset
            + ', payload_offset=' + payloadOffset
            + ', compressed_size=' + compressed
            + ', uncompressed_size=' + uncompressed
            + ', available_bytes=' + availableBytes
            + ', actual_file_size=' + fileSize
            + ', required_file_size=' + (payloadOffset + compressed) + ').');
    }

    function validateUz2Header(bytes, fileSize, fileName) {
        const size = Math.max(0, Number(fileSize || 0));
        const name = String(fileName || 'unknown.uz2');
        requireMinimumUz2Size(size, name);
        if (!(bytes instanceof Uint8Array) || bytes.length < UZ2_HEADER_BYTES) {
            const availableHeaderBytes = bytes instanceof Uint8Array ? bytes.length : 0;
            throw new Error('UZ2 file is incomplete/cut by ' + (UZ2_HEADER_BYTES - availableHeaderBytes) + ' bytes: ' + name
                + ' (record=1, record_offset=0, required_header_bytes=' + UZ2_HEADER_BYTES
                + ', available_header_bytes=' + availableHeaderBytes
                + ', actual_file_size=' + size + ').');
        }

        const compressed = readU32Le(bytes, 0);
        const uncompressed = readU32Le(bytes, 4);
        validateUz2Record(compressed, uncompressed, 1, 0, name);
        requireUz2Payload(compressed, uncompressed, size - UZ2_HEADER_BYTES, 1, 0, UZ2_HEADER_BYTES, size, name);

        const cmf = bytes[8];
        const flg = bytes[9];
        if (bytes.length < 10 || (cmf & 0x0f) !== 8 || (((cmf << 8) | flg) % 31) !== 0) {
            throw new Error('Cannot decompress UZ2 record 1: ' + name
                + ' (record_offset=0, payload_offset=8'
                + ', compressed_size=' + compressed
                + ', uncompressed_size=' + uncompressed
                + ', payload_head_hex=' + bytesHex(bytes.slice(8, Math.min(16, bytes.length))) + ').');
        }

        return {kind: 'redirect-uz2', description: 'Epic UZ2 zlib record header'};
    }

    async function readExact(source, offset, length) {
        const value = await source.read(offset, length);
        const bytes = value instanceof Uint8Array ? value : new Uint8Array(value || 0);
        if (bytes.length !== length) {
            throw new Error('Redirect byte source returned ' + bytes.length + ' bytes; expected ' + length + '.');
        }
        return bytes;
    }

    async function inflateZlibBytes(bytes, expectedBytes) {
        if (typeof DecompressionStream !== 'function') {
            throw new Error('This browser cannot decode zlib redirect records.');
        }
        const stream = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate'));
        const output = new Uint8Array(await new Response(stream).arrayBuffer());
        if (expectedBytes > 0 && output.length !== expectedBytes) {
            throw new Error('Decoded redirect size mismatch: expected=' + expectedBytes + ', decoded=' + output.length + '.');
        }
        return output;
    }

    async function readUz2(options) {
        const source = options && options.source;
        if (!source || typeof source.read !== 'function') {
            throw new Error('UZ2 reader requires a random-access byte source.');
        }
        const total = Math.max(0, Number(source.size || 0));
        const name = String(options.name || 'unknown.uz2');
        let offset = 0;
        let outputBytes = 0;
        let records = 0;
        let firstDecoded = new Uint8Array(0);
        requireMinimumUz2Size(total, name);

        while (offset < total) {
            if (offset + UZ2_HEADER_BYTES > total) {
                const availableHeaderBytes = Math.max(0, total - offset);
                throw new Error('UZ2 file is incomplete/cut by ' + (UZ2_HEADER_BYTES - availableHeaderBytes) + ' bytes: ' + name
                    + ' (record=' + (records + 1)
                    + ', record_offset=' + offset
                    + ', required_header_bytes=' + UZ2_HEADER_BYTES
                    + ', available_header_bytes=' + availableHeaderBytes
                    + ', actual_file_size=' + total + ').');
            }

            const recordOffset = offset;
            const header = await readExact(source, offset, UZ2_HEADER_BYTES);
            const compressed = readU32Le(header, 0);
            const uncompressed = readU32Le(header, 4);
            offset += UZ2_HEADER_BYTES;
            validateUz2Record(compressed, uncompressed, records + 1, recordOffset, name);

            const availableBytes = Math.max(0, total - offset);
            requireUz2Payload(
                compressed,
                uncompressed,
                availableBytes,
                records + 1,
                recordOffset,
                offset,
                total,
                name
            );

            const payload = await readExact(source, offset, compressed);
            let decoded;
            try {
                decoded = await inflateZlibBytes(payload, uncompressed);
            } catch (error) {
                throw new Error('Cannot decompress UZ2 record ' + (records + 1) + ': ' + name
                    + ' (record_offset=' + recordOffset
                    + ', payload_offset=' + (recordOffset + UZ2_HEADER_BYTES)
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
                        + ' (record=1, redirect_format=UZ2'
                        + ', actual_magic_hex=' + (bytesHex(magic) || 'empty')
                        + ', actual_magic_text=' + (printableBytes(magic) || 'empty')
                        + ', expected_magic_hex=C1832A9E|9E2A83C1|C2832A9E).');
                }
            }

            if (typeof options.onDecoded === 'function') {
                await options.onDecoded(decoded, {
                    record: records + 1,
                    recordOffset: recordOffset,
                    compressedSize: compressed,
                    uncompressedSize: uncompressed
                });
            }
            outputBytes += decoded.length;
            records++;
            if (typeof options.onProgress === 'function') {
                options.onProgress({
                    loaded: offset,
                    total: total,
                    output: outputBytes,
                    records: records
                });
            }
        }

        if (records < 1 || offset !== total || outputBytes < 1) {
            throw new Error('Incomplete Epic UZ2 redirect stream: records=' + records
                + ', compressed=' + offset + '/' + total
                + ', output=' + outputBytes + '.');
        }

        return {
            identitySize: outputBytes,
            records: records,
            firstDecoded: firstDecoded
        };
    }

    scope.UnrealDbRedirectReader = Object.freeze({
        readUz2: readUz2,
        validateUz2Header: validateUz2Header
    });
}(self));
