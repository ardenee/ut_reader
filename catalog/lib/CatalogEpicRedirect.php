<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogRedirectArchive.php';
require_once __DIR__ . '/../src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogRedirectArchiveStream;

/**
 * Strict, extension-aware Unreal redirect decompression.
 *
 * .uz  (UE1): 1234 header + embedded filename + Epic FCodec chain.
 * .uz2 (UE2): repeated LE compressed-size/uncompressed-size records containing
 *              exact zlib data, with the equal-size verbatim record path.
 * .uz3 (UE3): LE 5678 tag + LE total output size + one zlib stream.
 *
 * No gzip, raw-deflate, byte-order, record-order, concatenated-stream, or
 * declared-total compatibility wrappers are used here. A wrapper must match the
 * format selected by its own extension.
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
 *   wrapper_signature?:int,
 *   is_unreal_package:bool
 * }
 */
function catalog_epic_redirect_decompress_to_temp(
    string $sourcePath,
    string $sourceName,
    int $maxOutputBytes = 0,
    bool $requirePackageTag = true
): array {
    $extension = catalog_redirect_archive_extension($sourceName);
    if ($extension === '') {
        throw new RuntimeException('Not an Unreal redirect compressed file: ' . basename($sourceName));
    }
    if (!is_file($sourcePath)) {
        throw new RuntimeException('Redirect compressed source file is missing.');
    }

    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    if ($extension === 'uz2') {
        return CatalogRedirectArchiveStream::decompressUz2(
            $sourcePath,
            $sourceName,
            $limit,
            null,
            $requirePackageTag
        );
    }

    // UE1 and UE3 are decoded as complete in-memory payloads. Keep their limit
    // inside a signed 32-bit file-size range so intermediate length arithmetic
    // cannot overflow when Upload Bucket passes PHP_INT_MAX.
    $limit = min($limit, 2147483647);
    $archive = @file_get_contents($sourcePath);
    if (!is_string($archive) || $archive === '') {
        throw new RuntimeException('Could not read redirect compressed file: ' . basename($sourceName));
    }

    if ($extension === 'uz') {
        $decoded = catalog_epic_uz_decode($archive, $limit);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Epic UE1 UZ 1234/FCodec archive: ' . basename($sourceName));
        }
        $outputName = trim((string)($decoded['embedded_filename'] ?? ''));
        if ($outputName === '') {
            $outputName = catalog_redirect_archive_output_name($sourceName);
        } else {
            $outputName = catalog_clean_unreal_filename(basename(str_replace('\\', '/', $outputName)));
        }
    } else {
        $decoded = catalog_epic_uz3_decode($archive, $limit);
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid Epic UE3 UZ3 5678/zlib archive: ' . basename($sourceName));
        }
        $outputName = catalog_redirect_archive_output_name($sourceName);
    }

    $output = (string)$decoded['data'];
    $isPackage = catalog_redirect_archive_has_package_tag($output);
    if ($requirePackageTag && !$isPackage) {
        throw new RuntimeException('Redirect archive did not contain an Unreal package: ' . basename($sourceName));
    }

    $temporary = tempnam(dirname($sourcePath), '.ue_redirect_');
    if ($temporary === false || @file_put_contents($temporary, $output) !== strlen($output)) {
        if (is_string($temporary)) {
            @unlink($temporary);
        }
        throw new RuntimeException('Could not write decompressed redirect file in catalog staging.');
    }

    $result = [
        'path' => $temporary,
        'filename' => $outputName,
        'bytes' => strlen($output),
        'compressed_bytes' => strlen($archive),
        'source_extension' => $extension,
        'decoder' => (string)$decoded['decoder'],
        'chunks' => (int)$decoded['chunks'],
        'expected_bytes' => (int)$decoded['expected_bytes'],
        'is_unreal_package' => $isPackage,
    ];
    if (isset($decoded['wrapper_signature'])) {
        $result['wrapper_signature'] = (int)$decoded['wrapper_signature'];
    }
    return $result;
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename:string,wrapper_signature:int}|null */
function catalog_epic_uz_decode(string $archive, int $limit): ?array
{
    $header = catalog_legacy_uz_header($archive, 1234);
    if ($header === null) {
        return null;
    }

    try {
        $quarter = intdiv($limit, 4);
        $headroom = 16 * 1024 * 1024;
        $stageLimit = $limit > PHP_INT_MAX - $quarter - $headroom
            ? PHP_INT_MAX
            : $limit + $quarter + $headroom;
        $huffman = catalog_legacy_uz_decode_huffman(substr($archive, $header['offset']), $stageLimit);
        $mtf = catalog_legacy_uz_decode_mtf($huffman);
        unset($huffman);
        $bwt = catalog_legacy_uz_decode_bwt($mtf, $stageLimit);
        unset($mtf);
        $output = catalog_legacy_uz_decode_rle($bwt['data'], $limit);
    } catch (Throwable) {
        return null;
    }

    return [
        'data' => $output,
        'decoder' => 'epic-uz-huffman+mtf+bwt+rle',
        'chunks' => (int)$bwt['chunks'],
        'expected_bytes' => strlen($output),
        'embedded_filename' => (string)$header['filename'],
        'wrapper_signature' => 1234,
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,wrapper_signature:int}|null */
function catalog_epic_uz3_decode(string $archive, int $limit): ?array
{
    if (strlen($archive) <= 8) {
        return null;
    }

    $signature = catalog_redirect_archive_read_u32($archive, 0, 'le');
    $expected = catalog_redirect_archive_read_u32($archive, 4, 'le');
    if ($signature !== 5678 || $expected <= 0 || $expected > $limit) {
        return null;
    }

    $payload = substr($archive, 8);
    $decoded = catalog_redirect_archive_inflate_epic_zlib($payload, $limit, $expected);
    if ($decoded === null) {
        return null;
    }

    return [
        'data' => (string)$decoded['data'],
        'decoder' => 'epic-uz3-zlib',
        'chunks' => 1,
        'expected_bytes' => $expected,
        'wrapper_signature' => 5678,
    ];
}
