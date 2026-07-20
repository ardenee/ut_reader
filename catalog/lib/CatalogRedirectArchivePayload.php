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
        $decoded = catalog_redirect_archive_inflate_epic_zlib(
            $payload,
            $limit - strlen($output),
            $uncompressed
        );
        if ($decoded === null) {
            return null;
        }

        $output .= $decoded['data'];
        $chunks++;
    }

    if ($chunks === 0 || $position !== $length || $output === '') {
        return null;
    }

    return [
        'data' => $output,
        'decoder' => 'epic-uz2-zlib',
        'chunks' => $chunks,
        'expected_bytes' => strlen($output),
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

    $data = @file_get_contents($sourcePath);
    if (!is_string($data) || $data === '') {
        throw new RuntimeException('Could not read redirect compressed file: ' . basename($sourceName));
    }

    $sourceExtension = catalog_redirect_archive_extension($sourceName);
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    $decoded = catalog_redirect_archive_decode_payload($data, $sourceExtension, $limit);
    if (!is_array($decoded)) {
        throw new RuntimeException('Could not completely decompress Unreal redirect archive: ' . basename($sourceName));
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
        'is_unreal_package' => catalog_redirect_archive_has_package_tag($output),
    ];
    if (isset($decoded['wrapper_signature'])) {
        $result['wrapper_signature'] = (int)$decoded['wrapper_signature'];
    }

    return $result;
}
