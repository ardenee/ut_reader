<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog redirect archive payload.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogRedirectArchive.php';

/** @return array{data:string,decoder:string}|null */
function catalog_redirect_archive_uncompress_epic_zlib(string $payload, int $limit, int $expectedBytes): ?array
{
    if ($payload === '' || $expectedBytes <= 0 || $expectedBytes > $limit) {
        return null;
    }

    if (function_exists('gzuncompress')) {
        try {
            $decoded = @gzuncompress($payload, $expectedBytes);
        } catch (Throwable) {
            $decoded = false;
        }
        if (is_string($decoded) && strlen($decoded) === $expectedBytes) {
            return ['data' => $decoded, 'decoder' => 'zlib-uncompress'];
        }
    }

    $strict = catalog_redirect_archive_inflate_epic_zlib($payload, $limit, $expectedBytes);
    if ($strict !== null) {
        return ['data' => (string)$strict['data'], 'decoder' => 'zlib-inflate'];
    }

    return null;
}

/**
 * Decode Epic's FCodec redirect wrapper. Signature 1234 is the classic .uz
 * format. Signature 5678 is the later five-codec wrapper used by Epic UZ3.
 * Both wrappers serialize the original filename immediately after the
 * little-endian signature.
 *
 * 1234 decode: Huffman -> MTF -> BWT -> RLE
 * 5678 decode: Huffman -> RLE -> MTF -> BWT -> RLE
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
                : 'epic-uz-huffman+mtf+bwt+rle',
            'chunks' => (int)$bwt['chunks'],
            'expected_bytes' => strlen($output),
            'embedded_filename' => (string)$header['filename'],
            'wrapper_signature' => (int)$header['signature'],
        ];
    } catch (Throwable) {
        return null;
    }
}

/**
 * Decode Epic's exact UE2 UZ2 record stream. Every record is little-endian
 * [compressed size, uncompressed size, zlib compress() payload].
 *
 * @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null
 */
function catalog_redirect_archive_epic_uz2_payload(string $data, int $limit): ?array
{
    $position = 0;
    $length = strlen($data);
    $output = '';
    $chunks = 0;
    $decoder = '';

    while ($position < $length) {
        if ($position + 8 > $length) {
            return null;
        }

        $compressed = catalog_redirect_archive_read_u32($data, $position, 'le');
        $uncompressed = catalog_redirect_archive_read_u32($data, $position + 4, 'le');
        $position += 8;

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
        $decoded = catalog_redirect_archive_uncompress_epic_zlib(
            $payload,
            $limit - strlen($output),
            $uncompressed
        );
        if ($decoded === null) {
            return null;
        }

        $output .= $decoded['data'];
        $decoder = (string)$decoded['decoder'];
        $chunks++;
    }

    if ($chunks === 0 || $position !== $length || $output === '') {
        return null;
    }

    return [
        'data' => $output,
        'decoder' => 'epic-uz2-' . $decoder,
        'chunks' => $chunks,
        'expected_bytes' => strlen($output),
    ];
}

/**
 * Decode Epic UZ3 using the signed 5678 FCodec wrapper. The wrapper is:
 * little-endian signature 5678, Unreal compact-index filename length,
 * serialized original filename, then the FCodec stream.
 *
 * @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename:string,wrapper_signature:int}|null
 */
function catalog_redirect_archive_epic_uz3_payload(string $data, int $limit): ?array
{
    $decoded = catalog_redirect_archive_legacy_payload($data, $limit);
    if (!is_array($decoded) || (int)($decoded['wrapper_signature'] ?? 0) !== 5678) {
        return null;
    }

    $decoded['decoder'] = 'epic-uz3-huffman+rle+mtf+bwt+rle';
    return $decoded;
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename?:string,wrapper_signature?:int}|null */
function catalog_redirect_archive_decode_payload(string $data, string $sourceExtension, int $maxOutputBytes = 0): ?array
{
    if ($data === '') {
        return null;
    }

    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);

    return match ($sourceExtension) {
        'uz' => (static function () use ($data, $limit): ?array {
            $decoded = catalog_redirect_archive_legacy_payload($data, $limit);
            return is_array($decoded) && (int)($decoded['wrapper_signature'] ?? 0) === 1234
                ? $decoded
                : null;
        })(),
        'uz2' => catalog_redirect_archive_epic_uz2_payload($data, $limit),
        'uz3' => catalog_redirect_archive_epic_uz3_payload($data, $limit),
        default => null,
    };
}

function catalog_redirect_archive_decode_failure_message(
    string $data,
    string $sourceExtension,
    int $limit,
    string $sourceName
): string {
    $name = basename($sourceName);
    if ($sourceExtension !== 'uz3') {
        return 'Could not completely decompress Unreal redirect archive: ' . $name;
    }

    $length = strlen($data);
    if ($length < 5) {
        return 'Invalid Epic UZ3 wrapper: ' . $name . ' is too small to contain the 5678 FCodec header.';
    }

    $tag = catalog_redirect_archive_read_u32($data, 0, 'le');
    if ($tag !== 5678) {
        return 'Invalid Epic UZ3 wrapper tag in ' . $name
            . ': expected 5678 (0x0000162E), got ' . $tag
            . ' (' . sprintf('0x%08X', $tag) . ').';
    }

    $header = catalog_legacy_uz_header($data);
    if ($header === null) {
        return 'Invalid Epic UZ3 FCodec header in ' . $name
            . ': signature 5678 is present but the embedded filename/header could not be decoded.';
    }

    return 'Epic UZ3 FCodec stream could not be completely decompressed in ' . $name
        . ': tag=5678, embedded_filename=' . basename((string)$header['filename'])
        . ', compressed_bytes=' . $length
        . ', decode=Huffman->RLE->MTF->BWT->RLE.';
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
 *   md5:string,
 *   sha1:string,
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

    $data = @file_get_contents($sourcePath);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException('Could not read redirect compressed file: ' . basename($sourceName));
    }

    $sourceExtension = catalog_redirect_archive_extension($sourceName);
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    $decoded = catalog_redirect_archive_decode_payload($data, $sourceExtension, $limit);
    if (!is_array($decoded)) {
        throw new RuntimeException(
            catalog_redirect_archive_decode_failure_message($data, $sourceExtension, $limit, $sourceName)
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

    $md5 = md5($output);
    $sha1 = sha1($output);
    $tmp = tempnam(sys_get_temp_dir(), 'ue_redirect_');
    if ($tmp === false || @file_put_contents($tmp, $output) !== $outputBytes) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RuntimeException('Could not write decompressed redirect file.');
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
        'md5' => $md5,
        'sha1' => $sha1,
        'is_unreal_package' => catalog_redirect_archive_has_package_tag($output),
    ];
    if (isset($decoded['wrapper_signature'])) {
        $result['wrapper_signature'] = (int)$decoded['wrapper_signature'];
    }

    return $result;
}
