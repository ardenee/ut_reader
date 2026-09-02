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

    // Prefer the streaming path because it verifies that the complete input
    // is exactly one zlib stream and that the declared output size matches.
    $strict = catalog_redirect_archive_inflate_epic_zlib($payload, $limit, $expectedBytes);
    if ($strict !== null) {
        return ['data' => (string)$strict['data'], 'decoder' => 'zlib-inflate'];
    }

    // Compatibility fallback for PHP builds without the streaming zlib API.
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

    return null;
}

/**
 * Decode Epic's FCodec redirect wrapper used by `.uz` files.
 * Signature 1234 is the classic layout. Signature 5678 is the later `.uz`
 * variant with an additional RLE stage. Both serialize the original filename
 * immediately after the little-endian signature.
 *
 * 1234 decode: Huffman -> MTF -> BWT -> RLE
 * 5678 decode: Huffman -> RLE -> MTF -> BWT -> RLE
 *
 * The 5678 `.uz` wrapper is not the UT3 `.uz3` format. `.uz3` also starts
 * with 5678, but its extension and complete header identify a different
 * whole-file zlib format.
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
                ? 'epic-uz-5678-huffman+rle+mtf+bwt+rle'
                : 'epic-uz-1234-huffman+mtf+bwt+rle',
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
 * Decode the UT3 `.uz3` wrapper produced by `UT3.exe Compress`:
 *
 * - little-endian tag 5678
 * - little-endian total uncompressed file size
 * - one zlib `compress()` stream containing the complete original file
 *
 * UZ3 does not serialize the original filename and does not use the FCodec
 * Huffman/RLE/MTF/BWT chain used by `.uz`.
 *
 * @return array{data:string,decoder:string,chunks:int,expected_bytes:int,wrapper_signature:int}|null
 */
function catalog_redirect_archive_epic_uz3_payload(string $data, int $limit): ?array
{
    if (strlen($data) <= 8) {
        return null;
    }

    $tag = catalog_redirect_archive_read_u32($data, 0, 'le');
    $expectedBytes = catalog_redirect_archive_read_u32($data, 4, 'le');
    if ($tag !== 5678 || $expectedBytes <= 0 || $expectedBytes > $limit) {
        return null;
    }

    $payload = substr($data, 8);
    $decoded = catalog_redirect_archive_uncompress_epic_zlib($payload, $limit, $expectedBytes);
    if ($decoded === null) {
        return null;
    }

    return [
        'data' => (string)$decoded['data'],
        'decoder' => 'epic-uz3-' . (string)$decoded['decoder'],
        'chunks' => 1,
        'expected_bytes' => $expectedBytes,
        'wrapper_signature' => 5678,
    ];
}

/**
 * Decode content presented with a .uz3 transport suffix.
 *
 * The canonical UT3 format above is always attempted first. Some historic
 * redirect mirrors contain files named .uz3 whose bytes are instead Epic's
 * older signature-5678 FCodec wrapper (signature + serialized filename +
 * Huffman/RLE/MTF/BWT/RLE payload). Those files are content-compatible with
 * the engine FCodec implementation and must not be interpreted as a gigantic
 * UT3 uncompressed-size field merely because the suffix says .uz3.
 *
 * @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename?:string,wrapper_signature?:int}|null
 */
function catalog_redirect_archive_compatible_uz3_payload(string $data, int $limit): ?array
{
    $official = catalog_redirect_archive_epic_uz3_payload($data, $limit);
    if ($official !== null) {
        return $official;
    }

    $legacyHeader = catalog_legacy_uz_header($data, 5678);
    if ($legacyHeader === null) {
        return null;
    }

    $legacy = catalog_redirect_archive_legacy_payload($data, $limit);
    if ($legacy === null) {
        return null;
    }

    $legacy['decoder'] = 'uz3-compat-' . (string)$legacy['decoder'];
    return $legacy;
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename?:string,wrapper_signature?:int}|null */
function catalog_redirect_archive_decode_payload(string $data, string $sourceExtension, int $maxOutputBytes = 0): ?array
{
    if ($data === '') {
        return null;
    }

    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);

    return match ($sourceExtension) {
        'uz' => catalog_redirect_archive_legacy_payload($data, $limit),
        'uz2' => catalog_redirect_archive_epic_uz2_payload($data, $limit),
        'uz3' => catalog_redirect_archive_compatible_uz3_payload($data, $limit),
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
        return 'Cannot decompress/unpack Unreal redirect: ' . $name
            . ' (format=' . strtoupper($sourceExtension)
            . ', compressed_size=' . strlen($data)
            . ', output_limit=' . $limit . ').';
    }

    $length = strlen($data);
    if ($length < 8) {
        return 'UZ3 file is incomplete/cut by ' . (8 - $length) . ' header bytes: ' . $name
            . ' (actual_file_size=' . $length . ', required_header_bytes=8).';
    }

    $tag = catalog_redirect_archive_read_u32($data, 0, 'le');
    if ($tag !== 5678) {
        return 'Invalid UZ3 format: ' . $name
            . ' (actual_tag=' . $tag
            . ', actual_tag_hex=' . sprintf('0x%08X', $tag)
            . ', expected_tag=5678, expected_tag_hex=0x0000162E).';
    }

    $legacyHeader = catalog_legacy_uz_header($data, 5678);
    if ($legacyHeader !== null) {
        return 'Cannot decompress UZ3 compatibility FCodec wrapper: ' . $name
            . ' (signature=5678'
            . ', embedded_filename=' . (string)$legacyHeader['filename']
            . ', compressed_size=' . $length
            . ', output_limit=' . $limit . ').';
    }

    $declared = catalog_redirect_archive_read_u32($data, 4, 'le');
    if ($declared <= 0) {
        return 'Invalid UZ3 format: ' . $name . ' (uncompressed_size=' . $declared . ', minimum_size=1).';
    }
    if ($declared > $limit) {
        return 'Cannot decompress UZ3 because output exceeds the configured limit: ' . $name
            . ' (uncompressed_size=' . $declared . ', output_limit=' . $limit . ').';
    }

    $payload = substr($data, 8);
    if ($payload === '') {
        return 'UZ3 file is incomplete/cut; compressed payload is missing: ' . $name
            . ' (header_bytes=8, compressed_payload_bytes=0, uncompressed_size=' . $declared . ').';
    }

    return 'Cannot decompress UZ3: ' . $name
        . ' (tag=5678, uncompressed_size=' . $declared
        . ', compressed_payload_bytes=' . strlen($payload)
        . ', payload_head_hex=' . bin2hex(substr($payload, 0, 8)) . ').';
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
        throw new \UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveValidationException(
            catalog_redirect_archive_decode_failure_message($data, $sourceExtension, $limit, $sourceName),
            $sourceExtension . '.decompression_failed',
            [
                'format' => strtoupper($sourceExtension),
                'compressed_size' => strlen($data),
                'output_limit' => $limit,
            ]
        );
    }

    $output = (string)($decoded['data'] ?? '');
    $outputBytes = strlen($output);
    if ($outputBytes <= 0 || $outputBytes > $limit) {
        throw new \UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveValidationException(
            'Invalid decompressed redirect size: ' . basename($sourceName)
            . ' (output_size=' . $outputBytes . ', output_limit=' . $limit . ').',
            $sourceExtension . '.invalid_output_size',
            ['output_size' => $outputBytes, 'output_limit' => $limit]
        );
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
