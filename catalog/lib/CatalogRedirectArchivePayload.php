<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogRedirectArchive.php';

/**
 * Decode exact, signed Unreal redirect formats without assuming the payload is
 * an Unreal package. Redirect servers also distribute text files, native
 * libraries and other game support files.
 *
 * @return array{
 *   data:string,
 *   decoder:string,
 *   chunks:int,
 *   expected_bytes:int,
 *   embedded_filename:string,
 *   wrapper_signature:int
 * }|null
 */
function catalog_redirect_archive_legacy_payload(string $data, int $maxOutputBytes): ?array
{
    $header = catalog_legacy_uz_header($data);
    if ($header === null) {
        return null;
    }

    try {
        $limit = max(1, $maxOutputBytes);
        $stageLimit = $limit + intdiv($limit, 4) + 16 * 1024 * 1024;
        $huffman = catalog_legacy_uz_decode_huffman(substr($data, $header['offset']), $stageLimit);

        if ($header['signature'] === 5678) {
            $rle = catalog_legacy_uz_decode_rle($huffman, $stageLimit);
            unset($huffman);
            $mtf = catalog_legacy_uz_decode_mtf($rle);
            unset($rle);
        } else {
            $mtf = catalog_legacy_uz_decode_mtf($huffman);
            unset($huffman);
        }

        $bwt = catalog_legacy_uz_decode_bwt($mtf, $stageLimit);
        unset($mtf);
        $output = catalog_legacy_uz_decode_rle($bwt['data'], $limit);

        return [
            'data' => $output,
            'decoder' => $header['signature'] === 5678
                ? 'epic-uz3-huffman+rle+mtf+bwt+rle'
                : 'legacy-uz-huffman+mtf+bwt+rle',
            'chunks' => (int)$bwt['chunks'],
            'expected_bytes' => strlen($output),
            'embedded_filename' => (string)$header['filename'],
            'wrapper_signature' => (int)$header['signature'],
        ];
    } catch (Throwable) {
        return null;
    }
}

/** @return array{data:string,encoding:string}|null */
function catalog_redirect_archive_uz2_inflate_chunk(string $payload, int $expectedBytes): ?array
{
    if ($payload === '' || $expectedBytes <= 0) {
        return null;
    }

    $strict = catalog_redirect_archive_inflate_epic_zlib($payload, $expectedBytes, $expectedBytes);
    if ($strict !== null) {
        return ['data' => (string)$strict['data'], 'encoding' => 'zlib'];
    }

    $attempts = [
        'zlib' => 'gzuncompress',
        'zlib-decode' => 'zlib_decode',
        'raw-deflate' => 'gzinflate',
        'gzip' => 'gzdecode',
    ];
    foreach ($attempts as $encoding => $function) {
        if (!function_exists($function)) {
            continue;
        }
        try {
            $decoded = @$function($payload, $expectedBytes);
        } catch (Throwable) {
            $decoded = false;
        }
        if (is_string($decoded) && strlen($decoded) === $expectedBytes) {
            return ['data' => $decoded, 'encoding' => $encoding];
        }
    }

    // A few third-party redirect tools store incompressible blocks verbatim.
    if (strlen($payload) === $expectedBytes) {
        return ['data' => $payload, 'encoding' => 'stored'];
    }

    return null;
}

/**
 * Decode Epic's exact UE2 UZ2 record stream without requiring package magic.
 *
 * @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null
 */
function catalog_redirect_archive_epic_uz2_payload(string $data, int $limit): ?array
{
    $position = 0;
    $length = strlen($data);
    $output = '';
    $chunks = 0;
    $encodings = [];

    while ($position < $length) {
        if ($position + 8 > $length) {
            return catalog_redirect_archive_is_padding($data, $position) && $chunks > 0
                ? [
                    'data' => $output,
                    'decoder' => 'epic-uz2-' . implode('+', array_keys($encodings)),
                    'chunks' => $chunks,
                    'expected_bytes' => strlen($output),
                ]
                : null;
        }

        $compressed = catalog_redirect_archive_read_u32($data, $position, 'le');
        $uncompressed = catalog_redirect_archive_read_u32($data, $position + 4, 'le');
        $position += 8;

        if ($compressed === 0 && $uncompressed === 0 && catalog_redirect_archive_is_padding($data, $position)) {
            break;
        }
        if (
            $compressed <= 0
            || $compressed > CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES
            || $uncompressed <= 0
            || $uncompressed > CATALOG_EPIC_UZ2_BLOCK_BYTES
            || $compressed > $length - $position
            || $uncompressed > $limit - strlen($output)
        ) {
            return null;
        }

        $payload = substr($data, $position, $compressed);
        $position += $compressed;
        $decoded = catalog_redirect_archive_uz2_inflate_chunk($payload, $uncompressed);
        if ($decoded === null) {
            return null;
        }

        $output .= $decoded['data'];
        $encodings[$decoded['encoding']] = true;
        $chunks++;
    }

    if ($chunks === 0 || !catalog_redirect_archive_is_padding($data, $position) || $output === '') {
        return null;
    }

    return [
        'data' => $output,
        'decoder' => 'epic-uz2-' . implode('+', array_keys($encodings)),
        'chunks' => $chunks,
        'expected_bytes' => strlen($output),
    ];
}

/** @return string|null */
function catalog_redirect_archive_stream_read_exact($handle, int $bytes): ?string
{
    if (!is_resource($handle) || $bytes < 0) {
        return null;
    }
    $data = '';
    while (strlen($data) < $bytes && !feof($handle)) {
        $chunk = fread($handle, $bytes - strlen($data));
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $data .= $chunk;
    }
    return strlen($data) === $bytes ? $data : null;
}

function catalog_redirect_archive_stream_write_all($handle, string $data): bool
{
    if (!is_resource($handle)) {
        return false;
    }
    $offset = 0;
    $length = strlen($data);
    while ($offset < $length) {
        $written = fwrite($handle, substr($data, $offset));
        if (!is_int($written) || $written <= 0) {
            return false;
        }
        $offset += $written;
    }
    return true;
}

/**
 * Stream an exact UE2 UZ2 record file directly into durable staging. This path
 * deliberately does not inherit the ordinary package-upload size limit: bucket
 * uploads are already chunked, and the decompressed package may be much larger
 * than its redirect wrapper.
 *
 * @return array{
 *   path:string,
 *   filename:string,
 *   bytes:int,
 *   compressed_bytes:int,
 *   source_extension:string,
 *   decoder:string,
 *   chunks:int,
 *   expected_bytes:int,
 *   is_unreal_package:bool
 * }|null
 */
function catalog_redirect_archive_stream_epic_uz2_to_temp(
    string $sourcePath,
    string $sourceName,
    int $maxOutputBytes,
    ?string &$failure = null
): ?array {
    $failure = null;
    $sourceBytes = (int)(filesize($sourcePath) ?: 0);
    if ($sourceBytes < 8) {
        $failure = 'UZ2 source is too short to contain a chunk header.';
        return null;
    }

    $input = @fopen($sourcePath, 'rb');
    if (!is_resource($input)) {
        $failure = 'UZ2 source could not be opened for streaming.';
        return null;
    }
    $tmp = tempnam(dirname($sourcePath), '.ue_redirect_');
    if ($tmp === false) {
        fclose($input);
        $failure = 'UZ2 durable staging file could not be allocated.';
        return null;
    }
    $output = @fopen($tmp, 'wb');
    if (!is_resource($output)) {
        fclose($input);
        @unlink($tmp);
        $failure = 'UZ2 durable staging file could not be opened.';
        return null;
    }

    $position = 0;
    $outputBytes = 0;
    $chunks = 0;
    $encodings = [];
    $firstBytes = '';
    $limit = $maxOutputBytes > 0 ? $maxOutputBytes : PHP_INT_MAX;

    try {
        while ($position < $sourceBytes) {
            $remaining = $sourceBytes - $position;
            if ($remaining < 8) {
                $tail = catalog_redirect_archive_stream_read_exact($input, $remaining);
                if (!is_string($tail) || strlen($tail) > 16 || trim($tail, "\0") !== '') {
                    $failure = 'UZ2 has a truncated chunk header at compressed offset ' . $position . '.';
                    return null;
                }
                $position += $remaining;
                break;
            }

            $header = catalog_redirect_archive_stream_read_exact($input, 8);
            if (!is_string($header)) {
                $failure = 'UZ2 chunk header could not be read at compressed offset ' . $position . '.';
                return null;
            }
            $position += 8;
            $sizes = unpack('Vcompressed/Vuncompressed', $header);
            $compressed = (int)($sizes['compressed'] ?? 0);
            $uncompressed = (int)($sizes['uncompressed'] ?? 0);

            if ($compressed === 0 && $uncompressed === 0) {
                $tailBytes = $sourceBytes - $position;
                $tail = $tailBytes > 0 ? catalog_redirect_archive_stream_read_exact($input, $tailBytes) : '';
                if (!is_string($tail) || strlen($tail) > 16 || trim($tail, "\0") !== '') {
                    $failure = 'UZ2 contains data after its zero-size terminator.';
                    return null;
                }
                $position = $sourceBytes;
                break;
            }
            if ($compressed <= 0 || $compressed > CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES) {
                $failure = 'UZ2 chunk ' . ($chunks + 1) . ' declares invalid compressed size ' . $compressed . '.';
                return null;
            }
            if ($uncompressed <= 0 || $uncompressed > CATALOG_EPIC_UZ2_BLOCK_BYTES) {
                $failure = 'UZ2 chunk ' . ($chunks + 1) . ' declares invalid uncompressed size ' . $uncompressed . '.';
                return null;
            }
            if ($compressed > $sourceBytes - $position) {
                $failure = 'UZ2 chunk ' . ($chunks + 1) . ' is truncated: expected ' . $compressed . ' compressed byte(s).';
                return null;
            }
            if ($uncompressed > $limit - $outputBytes) {
                $failure = 'UZ2 decompressed output exceeds the configured redirect limit of ' . catalog_bytes($limit) . '.';
                return null;
            }

            $payload = catalog_redirect_archive_stream_read_exact($input, $compressed);
            if (!is_string($payload)) {
                $failure = 'UZ2 chunk ' . ($chunks + 1) . ' compressed payload could not be read completely.';
                return null;
            }
            $position += $compressed;

            $decoded = catalog_redirect_archive_uz2_inflate_chunk($payload, $uncompressed);
            if ($decoded === null) {
                $available = [];
                foreach (['inflate_init', 'gzuncompress', 'zlib_decode', 'gzinflate', 'gzdecode'] as $function) {
                    if (function_exists($function)) {
                        $available[] = $function;
                    }
                }
                $failure = 'UZ2 chunk ' . ($chunks + 1) . ' could not be inflated ('
                    . $compressed . ' compressed / ' . $uncompressed . ' expected byte(s)); available zlib functions: '
                    . ($available !== [] ? implode(', ', $available) : 'none') . '.';
                return null;
            }

            if ($firstBytes === '') {
                $firstBytes = substr($decoded['data'], 0, 4);
            }
            if (!catalog_redirect_archive_stream_write_all($output, $decoded['data'])) {
                $failure = 'UZ2 decompressed chunk could not be written to durable staging.';
                return null;
            }
            $outputBytes += $uncompressed;
            $encodings[$decoded['encoding']] = true;
            $chunks++;
        }

        if ($chunks === 0 || $position !== $sourceBytes || $outputBytes <= 0) {
            $failure = 'UZ2 did not contain a complete chunk stream.';
            return null;
        }
        if (!fflush($output)) {
            $failure = 'UZ2 durable staging output could not be flushed.';
            return null;
        }
    } finally {
        fclose($input);
        fclose($output);
        if ($failure !== null) {
            @unlink($tmp);
        }
    }

    return [
        'path' => $tmp,
        'filename' => catalog_redirect_archive_output_name($sourceName),
        'bytes' => $outputBytes,
        'compressed_bytes' => $sourceBytes,
        'source_extension' => 'uz2',
        'decoder' => 'epic-uz2-stream-' . implode('+', array_keys($encodings)),
        'chunks' => $chunks,
        'expected_bytes' => $outputBytes,
        'is_unreal_package' => catalog_redirect_archive_has_package_tag($firstBytes),
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename?:string,wrapper_signature?:int}|null */
function catalog_redirect_archive_decode_payload(string $data, string $sourceExtension, int $maxOutputBytes = 0): ?array
{
    if ($data === '') {
        return null;
    }

    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);

    // Signatures 1234 and 5678 are self-identifying and include an embedded
    // filename, so they can safely contain package or non-package payloads.
    $legacy = catalog_redirect_archive_legacy_payload($data, $limit);
    if ($legacy !== null) {
        return $legacy;
    }

    // UZ2 is an exact sequence of bounded records whose zlib streams and
    // declared lengths must all match, so package magic is not needed.
    if ($sourceExtension === 'uz2') {
        $uz2 = catalog_redirect_archive_epic_uz2_payload($data, $limit);
        if ($uz2 !== null) {
            return $uz2;
        }
    }

    // Keep the existing package-oriented compatibility decoders for ambiguous
    // historical wrappers. Those heuristics still require Unreal package magic.
    return catalog_redirect_archive_decode_data($data, $limit);
}

/**
 * @return array{
 *   path:string,
 *   filename:string,
 *   bytes:int,
 *   compressed_bytes:int,
 *   source_extension:string,
 *   decoder:string,
 *   chunks:int,
 *   expected_bytes:int,
 *   wrapper_signature?:int,
 *   is_unreal_package:bool
 * }
 */
function catalog_redirect_archive_decompress_payload_to_temp(
    string $sourcePath,
    string $sourceName,
    int $maxOutputBytes = 0
): array {
    if (!catalog_redirect_archive_is_supported_filename($sourceName)) {
        throw new RuntimeException('Not an Unreal redirect compressed file: ' . basename($sourceName));
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Redirect compressed source file is missing.');
    }

    $sourceExtension = catalog_redirect_archive_extension($sourceName);
    $streamFailure = null;
    if ($sourceExtension === 'uz2') {
        $streamed = catalog_redirect_archive_stream_epic_uz2_to_temp(
            $sourcePath,
            $sourceName,
            $maxOutputBytes > 0 ? catalog_redirect_archive_output_limit($maxOutputBytes) : PHP_INT_MAX,
            $streamFailure
        );
        if (is_array($streamed)) {
            return $streamed;
        }
    }

    $data = @file_get_contents($sourcePath);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException('Could not read redirect compressed file: ' . basename($sourceName));
    }

    // Compatibility wrappers still require in-memory decoding. Give UZ2 bucket
    // uploads a useful floor instead of inheriting a small ordinary-upload cap.
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    if ($sourceExtension === 'uz2' && $maxOutputBytes <= 0) {
        $limit = max($limit, 512 * 1024 * 1024);
    }
    $decoded = catalog_redirect_archive_decode_payload($data, $sourceExtension, $limit);
    if (!is_array($decoded)) {
        $detail = $streamFailure !== null ? ' ' . $streamFailure : '';
        throw new RuntimeException(
            'Could not completely decompress Unreal redirect archive: ' . basename($sourceName) . '.' . $detail
        );
    }

    $output = (string)($decoded['data'] ?? '');
    $outputBytes = strlen($output);
    if ($outputBytes <= 0 || $outputBytes > $limit) {
        throw new RuntimeException('Bad decompressed redirect file size: ' . catalog_bytes($outputBytes));
    }

    $outputName = catalog_redirect_archive_output_name($sourceName);
    $embeddedName = trim((string)($decoded['embedded_filename'] ?? ''));
    if ($embeddedName !== '') {
        $embeddedName = catalog_clean_unreal_filename(basename(str_replace('\\', '/', $embeddedName)));
        if ($embeddedName !== '') {
            $outputName = $embeddedName;
        }
    }

    // Use durable staging rather than the operating-system temporary folder.
    // Synology DSM may reject a later rename from /volume1/@tmp into the web
    // shared folder with "Operation not permitted".
    $tmp = tempnam(dirname($sourcePath), '.ue_redirect_');
    if ($tmp === false || @file_put_contents($tmp, $output) !== $outputBytes) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RuntimeException('Could not write decompressed redirect file in catalog staging.');
    }

    $result = [
        'path' => $tmp,
        'filename' => $outputName,
        'bytes' => $outputBytes,
        'compressed_bytes' => strlen($data),
        'source_extension' => $sourceExtension,
        'decoder' => (string)$decoded['decoder'],
        'chunks' => (int)$decoded['chunks'],
        'expected_bytes' => (int)$decoded['expected_bytes'],
        'is_unreal_package' => catalog_redirect_archive_has_package_tag($output),
    ];
    if (isset($decoded['wrapper_signature'])) {
        $result['wrapper_signature'] = (int)$decoded['wrapper_signature'];
    }

    return $result;
}
