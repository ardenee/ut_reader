<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

/**
 * Handles Unreal redirect-server compressed files before they enter the catalog.
 *
 * The catalog stores and indexes the real package file, never the transport
 * wrapper. UE1/UE2 redirect archives commonly contain a little-endian original
 * size followed by one independent zlib member per 32 KiB source block. UE3
 * variants can use explicit chunk metadata. Every supported decoder must consume
 * the complete wrapper and reproduce a package beginning with the Unreal tag.
 */

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
    $redirectExtension = catalog_redirect_archive_extension($filename);
    if ($redirectExtension === '') {
        return catalog_clean_unreal_filename($filename);
    }

    $suffix = '.' . $redirectExtension;
    $base = substr($filename, 0, -strlen($suffix));
    return catalog_clean_unreal_filename($base !== false && $base !== '' ? $base : 'package');
}

function catalog_redirect_archive_has_package_tag(string $data): bool
{
    if (strlen($data) < 4) {
        return false;
    }
    $tag = substr($data, 0, 4);
    return $tag === "\xC1\x83\x2A\x9E" || $tag === "\x9E\x2A\x83\xC1";
}

function catalog_redirect_archive_read_u32(string $data, int $offset, string $endian): int
{
    $bytes = substr($data, $offset, 4);
    if (strlen($bytes) !== 4) {
        return -1;
    }
    $unpacked = unpack($endian === 'be' ? 'N' : 'V', $bytes);
    return (int)($unpacked[1] ?? -1);
}

function catalog_redirect_archive_output_limit(int $maxOutputBytes): int
{
    return $maxOutputBytes > 0 ? $maxOutputBytes : 2 * 1024 * 1024 * 1024;
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
function catalog_redirect_archive_inflate_stream(string $payload, int $maxOutputBytes, ?int $expectedBytes = null): ?array
{
    if ($payload === '' || !function_exists('inflate_init') || !function_exists('inflate_add')) {
        return null;
    }
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    $encodings = [
        'zlib' => ZLIB_ENCODING_DEFLATE,
        'gzip' => ZLIB_ENCODING_GZIP,
        'raw' => ZLIB_ENCODING_RAW,
    ];
    foreach ($encodings as $name => $encoding) {
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
        if ($consumed <= 0 || $status !== ZLIB_STREAM_END) {
            continue;
        }
        $decodedLength = strlen($decoded);
        if ($decodedLength > $limit || ($expectedBytes !== null && $decodedLength !== $expectedBytes)) {
            continue;
        }
        return ['data' => $decoded, 'consumed' => $consumed, 'encoding' => $name];
    }
    return null;
}

/** @return array{data:string,consumed:int,encoding:string}|null */
function catalog_redirect_archive_decode_exact_payload(string $payload, int $maxOutputBytes, ?int $expectedBytes = null): ?array
{
    $decoded = catalog_redirect_archive_inflate_stream($payload, $maxOutputBytes, $expectedBytes);
    if ($decoded !== null && catalog_redirect_archive_is_padding($payload, $decoded['consumed'])) {
        return $decoded;
    }
    if (!function_exists('inflate_init')) {
        foreach ([['zlib_decode', 'zlib'], ['gzuncompress', 'zlib'], ['gzinflate', 'raw'], ['gzdecode', 'gzip']] as [$function, $name]) {
            if (!function_exists($function)) {
                continue;
            }
            $value = @$function($payload);
            if (!is_string($value) || $value === '') {
                continue;
            }
            if ($expectedBytes !== null && strlen($value) !== $expectedBytes) {
                continue;
            }
            if (strlen($value) > catalog_redirect_archive_output_limit($maxOutputBytes)) {
                continue;
            }
            return ['data' => $value, 'consumed' => strlen($payload), 'encoding' => $name];
        }
    }
    return null;
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_try_concatenated_streams(string $data, int $offset, int $maxOutputBytes, int $expectedBytes = 0): ?array
{
    $length = strlen($data);
    if ($offset < 0 || $offset >= $length) {
        return null;
    }
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    $position = $offset;
    $output = '';
    $chunks = 0;
    $encodings = [];
    while ($position < $length) {
        if (catalog_redirect_archive_is_padding($data, $position)) {
            $position = $length;
            break;
        }
        $decoded = catalog_redirect_archive_inflate_stream(substr($data, $position), $limit - strlen($output));
        if ($decoded === null || $decoded['consumed'] <= 0) {
            return null;
        }
        $output .= $decoded['data'];
        $position += $decoded['consumed'];
        $chunks++;
        $encodings[$decoded['encoding']] = true;
        if (strlen($output) > $limit || ($expectedBytes > 0 && strlen($output) > $expectedBytes)) {
            return null;
        }
    }
    if ($chunks === 0 || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    if ($expectedBytes > 0 && strlen($output) !== $expectedBytes) {
        return null;
    }
    return [
        'data' => $output,
        'decoder' => 'concatenated-' . implode('+', array_keys($encodings)),
        'chunks' => $chunks,
        'expected_bytes' => $expectedBytes,
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_try_size_prefixed_streams(string $data, int $offset, string $endian, string $sizeKind, int $maxOutputBytes, int $expectedTotal = 0): ?array
{
    $length = strlen($data);
    $position = $offset;
    $output = '';
    $chunks = 0;
    $encodings = [];
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    while ($position < $length) {
        if (catalog_redirect_archive_is_padding($data, $position)) {
            $position = $length;
            break;
        }
        if ($position + 4 > $length) {
            return null;
        }
        $size = catalog_redirect_archive_read_u32($data, $position, $endian);
        $position += 4;
        if ($size <= 0 || $size > $limit) {
            return null;
        }
        if ($sizeKind === 'compressed') {
            if ($size > $length - $position) {
                return null;
            }
            $payload = substr($data, $position, $size);
            $position += $size;
            $decoded = catalog_redirect_archive_decode_exact_payload($payload, $limit - strlen($output));
        } else {
            $decoded = catalog_redirect_archive_inflate_stream(substr($data, $position), $limit - strlen($output), $size);
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
        if (strlen($output) > $limit || ($expectedTotal > 0 && strlen($output) > $expectedTotal)) {
            return null;
        }
    }
    if ($chunks === 0 || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    if ($expectedTotal > 0 && strlen($output) !== $expectedTotal) {
        return null;
    }
    return [
        'data' => $output,
        'decoder' => $sizeKind . '-size-prefix-' . implode('+', array_keys($encodings)),
        'chunks' => $chunks,
        'expected_bytes' => $expectedTotal,
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_try_pair_headers(string $data, int $offset, string $endian, string $order, int $maxOutputBytes, int $expectedTotal = 0): ?array
{
    $length = strlen($data);
    $position = $offset;
    $output = '';
    $chunks = 0;
    $encodings = [];
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    while ($position < $length) {
        if (catalog_redirect_archive_is_padding($data, $position)) {
            $position = $length;
            break;
        }
        if ($position + 8 > $length) {
            return null;
        }
        $first = catalog_redirect_archive_read_u32($data, $position, $endian);
        $second = catalog_redirect_archive_read_u32($data, $position + 4, $endian);
        $position += 8;
        [$compressedSize, $uncompressedSize] = $order === 'compressed-uncompressed' ? [$first, $second] : [$second, $first];
        if ($compressedSize <= 0 || $uncompressedSize <= 0 || $compressedSize > $length - $position || $uncompressedSize > $limit) {
            return null;
        }
        $payload = substr($data, $position, $compressedSize);
        $position += $compressedSize;
        if ($compressedSize === $uncompressedSize) {
            $decoded = ['data' => $payload, 'consumed' => $compressedSize, 'encoding' => 'stored'];
        } else {
            $decoded = catalog_redirect_archive_decode_exact_payload($payload, $limit - strlen($output), $uncompressedSize);
        }
        if ($decoded === null || strlen($decoded['data']) !== $uncompressedSize) {
            return null;
        }
        $output .= $decoded['data'];
        $chunks++;
        $encodings[$decoded['encoding']] = true;
        if (strlen($output) > $limit || ($expectedTotal > 0 && strlen($output) > $expectedTotal)) {
            return null;
        }
    }
    if ($chunks === 0 || !catalog_redirect_archive_has_package_tag($output)) {
        return null;
    }
    if ($expectedTotal > 0 && strlen($output) !== $expectedTotal) {
        return null;
    }
    return [
        'data' => $output,
        'decoder' => 'pair-header-' . implode('+', array_keys($encodings)),
        'chunks' => $chunks,
        'expected_bytes' => $expectedTotal,
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_try_unreal_chunk_table(string $data, int $offset, string $endian, string $order, int $maxOutputBytes): ?array
{
    $length = strlen($data);
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    if ($offset < 0 || $offset + 16 > $length || !catalog_redirect_archive_has_package_tag(substr($data, $offset, 4))) {
        return null;
    }
    $blockSize = catalog_redirect_archive_read_u32($data, $offset + 4, $endian);
    $first = catalog_redirect_archive_read_u32($data, $offset + 8, $endian);
    $second = catalog_redirect_archive_read_u32($data, $offset + 12, $endian);
    [$totalCompressed, $totalUncompressed] = $order === 'compressed-uncompressed' ? [$first, $second] : [$second, $first];
    if ($blockSize <= 0 || $blockSize > 16 * 1024 * 1024 || $totalCompressed <= 0 || $totalUncompressed <= 0 || $totalUncompressed > $limit) {
        return null;
    }
    $chunkCount = (int)ceil($totalUncompressed / $blockSize);
    if ($chunkCount <= 0 || $chunkCount > 100000) {
        return null;
    }
    $tableOffset = $offset + 16;
    $payloadOffset = $tableOffset + $chunkCount * 8;
    if ($payloadOffset > $length) {
        return null;
    }
    $chunks = [];
    $sumCompressed = 0;
    $sumUncompressed = 0;
    for ($index = 0; $index < $chunkCount; $index++) {
        $firstSize = catalog_redirect_archive_read_u32($data, $tableOffset + $index * 8, $endian);
        $secondSize = catalog_redirect_archive_read_u32($data, $tableOffset + $index * 8 + 4, $endian);
        [$compressedSize, $uncompressedSize] = $order === 'compressed-uncompressed' ? [$firstSize, $secondSize] : [$secondSize, $firstSize];
        if ($compressedSize <= 0 || $uncompressedSize <= 0 || $uncompressedSize > $blockSize || $payloadOffset + $sumCompressed + $compressedSize > $length) {
            return null;
        }
        $chunks[] = [$compressedSize, $uncompressedSize];
        $sumCompressed += $compressedSize;
        $sumUncompressed += $uncompressedSize;
    }
    if ($sumUncompressed !== $totalUncompressed) {
        return null;
    }
    if ($totalCompressed !== $sumCompressed && $totalCompressed !== $sumCompressed + $chunkCount * 8 + 16) {
        return null;
    }
    $position = $payloadOffset;
    $output = '';
    $encodings = [];
    foreach ($chunks as [$compressedSize, $uncompressedSize]) {
        $payload = substr($data, $position, $compressedSize);
        $position += $compressedSize;
        $decoded = $compressedSize === $uncompressedSize
            ? ['data' => $payload, 'consumed' => $compressedSize, 'encoding' => 'stored']
            : catalog_redirect_archive_decode_exact_payload($payload, $limit - strlen($output), $uncompressedSize);
        if ($decoded === null) {
            return null;
        }
        $output .= $decoded['data'];
        $encodings[$decoded['encoding']] = true;
    }
    if (!catalog_redirect_archive_has_package_tag($output) || strlen($output) !== $totalUncompressed || !catalog_redirect_archive_is_padding($data, $position)) {
        return null;
    }
    return [
        'data' => $output,
        'decoder' => 'unreal-chunk-table-' . implode('+', array_keys($encodings)),
        'chunks' => $chunkCount,
        'expected_bytes' => $totalUncompressed,
    ];
}

/** @return array{data:string,decoder:string,chunks:int,expected_bytes:int}|null */
function catalog_redirect_archive_decode_data(string $data, int $maxOutputBytes = 0): ?array
{
    $length = strlen($data);
    if ($length === 0) {
        return null;
    }
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);

    /* UE1/UE2: original size followed by independent 32 KiB zlib members. */
    $hasExpectedSizePrefix = false;
    foreach (['le', 'be'] as $endian) {
        $expected = catalog_redirect_archive_read_u32($data, 0, $endian);
        if ($expected > 0 && $expected <= $limit) {
            $hasExpectedSizePrefix = true;
            $result = catalog_redirect_archive_try_concatenated_streams($data, 4, $limit, $expected);
            if ($result !== null) {
                return $result;
            }
            foreach (['compressed', 'uncompressed'] as $kind) {
                $result = catalog_redirect_archive_try_size_prefixed_streams($data, 4, $endian, $kind, $limit, $expected);
                if ($result !== null) {
                    return $result;
                }
            }
            foreach (['compressed-uncompressed', 'uncompressed-compressed'] as $order) {
                $result = catalog_redirect_archive_try_pair_headers($data, 4, $endian, $order, $limit, $expected);
                if ($result !== null) {
                    return $result;
                }
            }
        }
    }

    /* Never ignore a credible declared size and accept only the first member. */
    if ($hasExpectedSizePrefix) {
        return null;
    }

    foreach ([0, 4, 8, 12, 16, 20, 32] as $offset) {
        foreach (['le', 'be'] as $endian) {
            foreach (['compressed-uncompressed', 'uncompressed-compressed'] as $order) {
                $result = catalog_redirect_archive_try_unreal_chunk_table($data, $offset, $endian, $order, $limit);
                if ($result !== null) {
                    return $result;
                }
            }
        }
    }

    foreach ([0, 4, 8, 12, 16, 20, 32] as $offset) {
        $result = catalog_redirect_archive_try_concatenated_streams($data, $offset, $limit);
        if ($result !== null) {
            return $result;
        }
        foreach (['le', 'be'] as $endian) {
            foreach (['compressed', 'uncompressed'] as $kind) {
                $result = catalog_redirect_archive_try_size_prefixed_streams($data, $offset, $endian, $kind, $limit);
                if ($result !== null) {
                    return $result;
                }
            }
            foreach (['compressed-uncompressed', 'uncompressed-compressed'] as $order) {
                $result = catalog_redirect_archive_try_pair_headers($data, $offset, $endian, $order, $limit);
                if ($result !== null) {
                    return $result;
                }
            }
        }
    }
    return null;
}

/**
 * @return array{path:string,filename:string,bytes:int,compressed_bytes:int,source_extension:string,decoder:string,chunks:int,expected_bytes:int}
 */
function catalog_redirect_archive_decompress_to_temp(
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

    $decoded = catalog_redirect_archive_decode_data($data, $maxOutputBytes);
    if (!is_array($decoded) || !catalog_redirect_archive_has_package_tag((string)$decoded['data'])) {
        throw new RuntimeException('Could not completely decompress Unreal redirect archive: ' . basename($sourceName));
    }

    $output = (string)$decoded['data'];
    $outputBytes = strlen($output);
    $limit = catalog_redirect_archive_output_limit($maxOutputBytes);
    if ($outputBytes <= 0 || $outputBytes > $limit) {
        throw new RuntimeException('Bad decompressed redirect package size: ' . catalog_bytes($outputBytes));
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ue_redirect_');
    if ($tmp === false || @file_put_contents($tmp, $output) !== $outputBytes) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RuntimeException('Could not write decompressed redirect package.');
    }

    return [
        'path' => $tmp,
        'filename' => catalog_redirect_archive_output_name($sourceName),
        'bytes' => $outputBytes,
        'compressed_bytes' => strlen($data),
        'source_extension' => catalog_redirect_archive_extension($sourceName),
        'decoder' => (string)$decoded['decoder'],
        'chunks' => (int)$decoded['chunks'],
        'expected_bytes' => (int)$decoded['expected_bytes'],
    ];
}
