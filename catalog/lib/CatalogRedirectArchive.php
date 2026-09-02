<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog redirect archive.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogLegacyUz.php';

const CATALOG_EPIC_UZ2_BLOCK_BYTES = 32768;
const CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES = 33096;

function catalog_redirect_archive_extension(string $filename): string
{
    $extension = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($extension, ['uz', 'uz2', 'uz3'], true) ? $extension : '';
}

function catalog_redirect_archive_is_supported_filename(string $filename): bool
{
    return catalog_redirect_archive_extension($filename) !== '';
}

function catalog_redirect_archive_output_name(string $filename): string
{
    $filename = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $filename));
    $extension = catalog_redirect_archive_extension($filename);
    if ($extension === '') {
        return catalog_clean_unreal_filename($filename);
    }
    $base = substr($filename, 0, -strlen('.' . $extension));
    return catalog_clean_unreal_filename($base !== false && $base !== '' ? $base : 'package');
}

function catalog_redirect_archive_has_package_tag(string $data): bool
{
    return \UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedBytes($data);
}

function catalog_redirect_archive_read_u32(string $data, int $offset, string $endian): int
{
    $bytes = substr($data, $offset, 4);
    if (strlen($bytes) !== 4) {
        return -1;
    }
    $value = unpack($endian === 'be' ? 'N' : 'V', $bytes);
    return (int)($value[1] ?? -1);
}

function catalog_redirect_archive_output_limit(int $maxOutputBytes): int
{
    if ($maxOutputBytes > 0) {
        return $maxOutputBytes;
    }

    $environmentLimit = (int)(getenv('UNREALDB_REDIRECT_MAX_OUTPUT_BYTES') ?: 0);
    if ($environmentLimit > 0) {
        return max(1024 * 1024, min($environmentLimit, 2 * 1024 * 1024 * 1024));
    }

    try {
        $configuredLimit = (int)(catalog_config()['max_upload_bytes'] ?? 0);
        if ($configuredLimit > 0) {
            return $configuredLimit;
        }
    } catch (Throwable) {
    }

    return 256 * 1024 * 1024;
}

function catalog_redirect_archive_is_padding(string $data, int $offset): bool
{
    if ($offset >= strlen($data)) {
        return true;
    }
    $tail = substr($data, $offset);
    return strlen($tail) <= 16 && trim($tail, "\0") === '';
}

/** @return array{data:string,consumed:int,encoding:string}|null */
function catalog_redirect_archive_inflate(string $payload, int $limit, ?int $expected = null, bool $exact = false): ?array
{
    if ($payload === '' || !function_exists('inflate_init') || !function_exists('inflate_add')) {
        return null;
    }
    foreach (['zlib' => ZLIB_ENCODING_DEFLATE, 'gzip' => ZLIB_ENCODING_GZIP, 'raw' => ZLIB_ENCODING_RAW] as $name => $encoding) {
        $context = @inflate_init($encoding);
        if ($context === false) {
            continue;
        }
        $decoded = @inflate_add($context, $payload, ZLIB_FINISH);
        if (!is_string($decoded) || $decoded === '') {
            continue;
        }
        $consumed = function_exists('inflate_get_read_len') ? (int)inflate_get_read_len($context) : strlen($payload);
        $status = function_exists('inflate_get_status') ? (int)inflate_get_status($context) : ZLIB_STREAM_END;
        if ($consumed <= 0 || $status !== ZLIB_STREAM_END || strlen($decoded) > $limit) {
            continue;
        }
        if ($expected !== null && strlen($decoded) !== $expected) {
            continue;
        }
        if ($exact && !catalog_redirect_archive_is_padding($payload, $consumed)) {
            continue;
        }
        return ['data' => $decoded, 'consumed' => $consumed, 'encoding' => $name];
    }
    return null;
}

/** @return array{data:string,consumed:int,encoding:string}|null */
function catalog_redirect_archive_inflate_epic_zlib(string $payload, int $limit, int $expected): ?array
{
    if ($payload === '' || $expected <= 0 || !function_exists('inflate_init') || !function_exists('inflate_add')) {
        return null;
    }
    $context = @inflate_init(ZLIB_ENCODING_DEFLATE);
    if ($context === false) {
        return null;
    }
    $decoded = @inflate_add($context, $payload, ZLIB_FINISH);
    if (!is_string($decoded)) {
        return null;
    }
    $consumed = function_exists('inflate_get_read_len') ? (int)inflate_get_read_len($context) : strlen($payload);
    $status = function_exists('inflate_get_status') ? (int)inflate_get_status($context) : ZLIB_STREAM_END;
    if (
        $status !== ZLIB_STREAM_END
        || $consumed !== strlen($payload)
        || strlen($decoded) !== $expected
        || strlen($decoded) > $limit
    ) {
        return null;
    }
    return ['data' => $decoded, 'consumed' => $consumed, 'encoding' => 'zlib'];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_epic_uz2(string $data, int $limit): ?array
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

    if ($chunks === 0 || $position !== $length || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    return [
        'data' => $output,
        'decoder' => 'epic-uz2-zlib',
        'chunks' => $chunks,
        'expected_bytes' => strlen($output),
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_pair_records(string $data, int $offset, string $endian, bool $compressedFirst, int $limit, int $expectedTotal = 0): ?array
{
    $position = $offset;
    $length = strlen($data);
    $output = '';
    $chunks = 0;
    $encodings = [];
    while ($position < $length) {
        if (catalog_redirect_archive_is_padding($data, $position)) {
            break;
        }
        if ($position + 8 > $length) {
            return null;
        }
        $first = catalog_redirect_archive_read_u32($data, $position, $endian);
        $second = catalog_redirect_archive_read_u32($data, $position + 4, $endian);
        $position += 8;
        [$compressed, $uncompressed] = $compressedFirst ? [$first, $second] : [$second, $first];
        if ($compressed <= 0 || $uncompressed <= 0 || $compressed > $length - $position || $uncompressed > $limit - strlen($output)) {
            return null;
        }
        $payload = substr($data, $position, $compressed);
        $position += $compressed;
        $decoded = $compressed === $uncompressed
            ? ['data' => $payload, 'consumed' => $compressed, 'encoding' => 'stored']
            : catalog_redirect_archive_inflate($payload, $limit - strlen($output), $uncompressed, true);
        if ($decoded === null) {
            return null;
        }
        $output .= $decoded['data'];
        $chunks++;
        $encodings[$decoded['encoding']] = true;
        if (($expectedTotal > 0 && strlen($output) > $expectedTotal) || strlen($output) > $limit) {
            return null;
        }
    }
    if ($chunks === 0 || !catalog_redirect_archive_is_padding($data, $position) || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    if ($expectedTotal > 0 && strlen($output) !== $expectedTotal) {
        return null;
    }
    return ['data' => $output, 'decoder' => 'pair-record-' . implode('+', array_keys($encodings)), 'chunks' => $chunks, 'expected_bytes' => $expectedTotal];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_concatenated(string $data, int $offset, int $limit, int $expectedTotal = 0): ?array
{
    $position = $offset;
    $length = strlen($data);
    $output = '';
    $chunks = 0;
    $encodings = [];
    while ($position < $length) {
        if (catalog_redirect_archive_is_padding($data, $position)) {
            break;
        }
        $decoded = catalog_redirect_archive_inflate(substr($data, $position), $limit - strlen($output));
        if ($decoded === null) {
            return null;
        }
        $output .= $decoded['data'];
        $position += $decoded['consumed'];
        $chunks++;
        $encodings[$decoded['encoding']] = true;
        if (($expectedTotal > 0 && strlen($output) > $expectedTotal) || strlen($output) > $limit) {
            return null;
        }
    }
    if ($chunks === 0 || !catalog_redirect_archive_is_padding($data, $position) || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    if ($expectedTotal > 0 && strlen($output) !== $expectedTotal) {
        return null;
    }
    return ['data' => $output, 'decoder' => 'concatenated-' . implode('+', array_keys($encodings)), 'chunks' => $chunks, 'expected_bytes' => $expectedTotal];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_size_records(string $data, int $offset, string $endian, bool $compressedSize, int $limit, int $expectedTotal = 0): ?array
{
    $position = $offset;
    $length = strlen($data);
    $output = '';
    $chunks = 0;
    $encodings = [];
    while ($position < $length) {
        if (catalog_redirect_archive_is_padding($data, $position)) {
            break;
        }
        $size = catalog_redirect_archive_read_u32($data, $position, $endian);
        $position += 4;
        if ($size <= 0 || $size > $limit) {
            return null;
        }
        if ($compressedSize) {
            if ($size > $length - $position) {
                return null;
            }
            $decoded = catalog_redirect_archive_inflate(substr($data, $position, $size), $limit - strlen($output), null, true);
            $position += $size;
        } else {
            $decoded = catalog_redirect_archive_inflate(substr($data, $position), $limit - strlen($output), $size);
            if ($decoded !== null) {
                $position += $decoded['consumed'];
            }
        }
        if ($decoded === null) {
            return null;
        }
        $output .= $decoded['data'];
        $chunks++;
        $encodings[$decoded['encoding']] = true;
        if (($expectedTotal > 0 && strlen($output) > $expectedTotal) || strlen($output) > $limit) {
            return null;
        }
    }
    if ($chunks === 0 || !catalog_redirect_archive_is_padding($data, $position) || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    if ($expectedTotal > 0 && strlen($output) !== $expectedTotal) {
        return null;
    }
    return ['data' => $output, 'decoder' => ($compressedSize ? 'compressed' : 'uncompressed') . '-size-record-' . implode('+', array_keys($encodings)), 'chunks' => $chunks, 'expected_bytes' => $expectedTotal];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_chunk_table(string $data, int $offset, string $endian, bool $compressedFirst, int $limit): ?array
{
    if ($offset < 0 || $offset + 16 > strlen($data) || !catalog_redirect_archive_has_package_tag(substr($data, $offset, 4))) {
        return null;
    }
    $blockSize = catalog_redirect_archive_read_u32($data, $offset + 4, $endian);
    $first = catalog_redirect_archive_read_u32($data, $offset + 8, $endian);
    $second = catalog_redirect_archive_read_u32($data, $offset + 12, $endian);
    [$totalCompressed, $totalUncompressed] = $compressedFirst ? [$first, $second] : [$second, $first];
    if ($blockSize <= 0 || $blockSize > 16 * 1024 * 1024 || $totalCompressed <= 0 || $totalUncompressed <= 0 || $totalUncompressed > $limit) {
        return null;
    }
    $count = (int)ceil($totalUncompressed / $blockSize);
    $tableOffset = $offset + 16;
    $payloadOffset = $tableOffset + $count * 8;
    if ($count <= 0 || $count > 100000 || $payloadOffset > strlen($data)) {
        return null;
    }
    $records = [];
    $sumCompressed = 0;
    $sumUncompressed = 0;
    for ($index = 0; $index < $count; $index++) {
        $a = catalog_redirect_archive_read_u32($data, $tableOffset + $index * 8, $endian);
        $b = catalog_redirect_archive_read_u32($data, $tableOffset + $index * 8 + 4, $endian);
        [$compressed, $uncompressed] = $compressedFirst ? [$a, $b] : [$b, $a];
        if ($compressed <= 0 || $uncompressed <= 0 || $uncompressed > $blockSize) {
            return null;
        }
        $records[] = [$compressed, $uncompressed];
        $sumCompressed += $compressed;
        $sumUncompressed += $uncompressed;
    }
    if ($sumUncompressed !== $totalUncompressed || ($totalCompressed !== $sumCompressed && $totalCompressed !== $sumCompressed + $count * 8 + 16)) {
        return null;
    }
    $position = $payloadOffset;
    $output = '';
    $encodings = [];
    foreach ($records as [$compressed, $uncompressed]) {
        if ($compressed > strlen($data) - $position) {
            return null;
        }
        $payload = substr($data, $position, $compressed);
        $position += $compressed;
        $decoded = $compressed === $uncompressed
            ? ['data' => $payload, 'encoding' => 'stored']
            : catalog_redirect_archive_inflate($payload, $limit - strlen($output), $uncompressed, true);
        if ($decoded === null) {
            return null;
        }
        $output .= $decoded['data'];
        $encodings[$decoded['encoding']] = true;
    }
    if (!catalog_redirect_archive_is_padding($data, $position) || strlen($output) !== $totalUncompressed || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    return ['data' => $output, 'decoder' => 'chunk-table-' . implode('+', array_keys($encodings)), 'chunks' => $count, 'expected_bytes' => $totalUncompressed];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int,embedded_filename?:string}|null */
function catalog_redirect_archive_decode_data(string $data, int $maxOutputBytes = 0): ?array
{
    if ($data === '') {
        return null;
    }
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);

    $legacy = catalog_legacy_uz_decode($data, $limit);
    if ($legacy !== null) {
        return $legacy;
    }

    $epicUz2 = catalog_redirect_archive_epic_uz2($data, $limit);
    if ($epicUz2 !== null) {
        return $epicUz2;
    }

    foreach ([0, 4, 8, 12, 16, 20, 32] as $offset) {
        foreach (['le', 'be'] as $endian) {
            foreach ([true, false] as $compressedFirst) {
                $result = catalog_redirect_archive_chunk_table($data, $offset, $endian, $compressedFirst, $limit);
                if ($result !== null) {
                    return $result;
                }
            }
        }
    }

    foreach (['le', 'be'] as $endian) {
        $expected = catalog_redirect_archive_read_u32($data, 0, $endian);
        if ($expected <= 0 || $expected > $limit) {
            continue;
        }
        $candidates = [catalog_redirect_archive_concatenated($data, 4, $limit, $expected)];
        foreach ([true, false] as $compressedSize) {
            $candidates[] = catalog_redirect_archive_size_records($data, 4, $endian, $compressedSize, $limit, $expected);
        }
        foreach ([true, false] as $compressedFirst) {
            $candidates[] = catalog_redirect_archive_pair_records($data, 4, $endian, $compressedFirst, $limit, $expected);
        }
        foreach ($candidates as $result) {
            if ($result !== null) {
                return $result;
            }
        }
        $firstMember = catalog_redirect_archive_inflate(substr($data, 4), $limit);
        if ($firstMember !== null && catalog_redirect_archive_has_package_tag($firstMember['data'])) {
            return null;
        }
    }

    foreach ([0, 4, 8, 12, 16, 20, 32] as $offset) {
        $result = catalog_redirect_archive_concatenated($data, $offset, $limit);
        if ($result !== null) {
            return $result;
        }
        foreach (['le', 'be'] as $endian) {
            foreach ([true, false] as $compressedSize) {
                $result = catalog_redirect_archive_size_records($data, $offset, $endian, $compressedSize, $limit);
                if ($result !== null) {
                    return $result;
                }
            }
            foreach ([true, false] as $compressedFirst) {
                $result = catalog_redirect_archive_pair_records($data, $offset, $endian, $compressedFirst, $limit);
                if ($result !== null) {
                    return $result;
                }
            }
        }
    }
    return null;
}

/**
 * Compatibility adapter retained for existing scanners and indexers.
 * All production format selection and decompression is owned by the shared processor.
 *
 * @return array{path:string,filename:string,bytes:int,compressed_bytes:int,source_extension:string,decoder:string,chunks:int,expected_bytes:int,is_unreal_package:bool}
 */
function catalog_redirect_archive_decompress_to_temp(string $sourcePath, string $sourceName, int $maxOutputBytes = 0): array
{
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    return (new \UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor([
        'max_redirect_output_bytes' => $limit,
    ]))->decompressToTemp($sourcePath, $sourceName, null, true);
}
