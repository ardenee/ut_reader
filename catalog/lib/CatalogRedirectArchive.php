<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

/**
 * Handles Unreal redirect-server compressed files before they enter the catalog.
 *
 * The catalog must store/index the real package file, not the .uz/.uz2/.uz3
 * transport wrapper. Decoding is intentionally strict: a candidate output must
 * begin with an Unreal package tag before it is accepted.
 */

function catalog_redirect_archive_extension(string $filename): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['uz', 'uz2', 'uz3'], true) ? $ext : '';
}

function catalog_redirect_archive_is_supported_filename(string $filename): bool
{
    return catalog_redirect_archive_extension($filename) !== '';
}

function catalog_redirect_archive_output_name(string $filename): string
{
    $filename = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], $filename));
    $redirectExt = catalog_redirect_archive_extension($filename);
    if ($redirectExt === '') {
        return catalog_clean_unreal_filename($filename);
    }

    $suffix = '.' . $redirectExt;
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

function catalog_redirect_archive_decode_payload(string $payload): ?string
{
    if ($payload === '') {
        return null;
    }

    $candidates = [
        @zlib_decode($payload),
        @gzuncompress($payload),
        @gzinflate($payload),
        @gzdecode($payload),
    ];

    foreach ($candidates as $decoded) {
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }

    return null;
}

function catalog_redirect_archive_try_whole_file(string $data): ?string
{
    foreach ([0, 4, 8, 12, 16, 20, 32] as $offset) {
        if ($offset >= strlen($data)) {
            continue;
        }
        $decoded = catalog_redirect_archive_decode_payload(substr($data, $offset));
        if (is_string($decoded) && catalog_redirect_archive_has_package_tag($decoded)) {
            return $decoded;
        }
    }

    return null;
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

function catalog_redirect_archive_try_chunk_stream(string $data, int $offset, string $endian, string $order): ?string
{
    $len = strlen($data);
    $pos = $offset;
    $out = '';
    $chunkCount = 0;

    while ($pos < $len) {
        if ($len - $pos <= 16 && trim(substr($data, $pos), "\0") === '') {
            break;
        }
        if ($pos + 8 > $len) {
            return null;
        }

        $first = catalog_redirect_archive_read_u32($data, $pos, $endian);
        $second = catalog_redirect_archive_read_u32($data, $pos + 4, $endian);
        $pos += 8;

        if ($order === 'compressed-uncompressed') {
            $compressedSize = $first;
            $uncompressedSize = $second;
        } else {
            $uncompressedSize = $first;
            $compressedSize = $second;
        }

        if ($compressedSize <= 0 || $uncompressedSize <= 0 || $compressedSize > ($len - $pos)) {
            return null;
        }
        if ($uncompressedSize > 268435456) {
            return null;
        }

        $payload = substr($data, $pos, $compressedSize);
        $pos += $compressedSize;

        if ($compressedSize === $uncompressedSize && catalog_redirect_archive_has_package_tag($payload)) {
            $decoded = $payload;
        } else {
            $decoded = catalog_redirect_archive_decode_payload($payload);
        }

        if (!is_string($decoded) || strlen($decoded) !== $uncompressedSize) {
            return null;
        }

        $out .= $decoded;
        $chunkCount++;
    }

    return $chunkCount > 0 && catalog_redirect_archive_has_package_tag($out) ? $out : null;
}

function catalog_redirect_archive_try_chunked_file(string $data): ?string
{
    foreach ([0, 4, 8, 12, 16, 20, 32] as $offset) {
        foreach (['le', 'be'] as $endian) {
            foreach (['compressed-uncompressed', 'uncompressed-compressed'] as $order) {
                $decoded = catalog_redirect_archive_try_chunk_stream($data, $offset, $endian, $order);
                if (is_string($decoded)) {
                    return $decoded;
                }
            }
        }
    }

    return null;
}

/** @return array{path:string,filename:string,bytes:int,source_extension:string} */
function catalog_redirect_archive_decompress_to_temp(string $sourcePath, string $sourceName): array
{
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

    $decoded = catalog_redirect_archive_try_whole_file($data);
    if (!is_string($decoded)) {
        $decoded = catalog_redirect_archive_try_chunked_file($data);
    }
    if (!is_string($decoded) || !catalog_redirect_archive_has_package_tag($decoded)) {
        throw new RuntimeException('Could not decompress Unreal redirect archive: ' . basename($sourceName));
    }

    $tmp = tempnam(sys_get_temp_dir(), 'ue_redirect_');
    if ($tmp === false || @file_put_contents($tmp, $decoded) === false) {
        if (is_string($tmp)) {
            @unlink($tmp);
        }
        throw new RuntimeException('Could not write decompressed redirect package.');
    }

    return [
        'path' => $tmp,
        'filename' => catalog_redirect_archive_output_name($sourceName),
        'bytes' => strlen($decoded),
        'source_extension' => catalog_redirect_archive_extension($sourceName),
    ];
}
