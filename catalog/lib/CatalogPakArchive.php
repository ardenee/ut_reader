<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog PAK archive.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

const CATALOG_PAK_MAGIC = 0x5A6F12E1;
const CATALOG_PAK_FOOTER_SCAN_BYTES = 16777216; // 16 MB; allows signed/tacked-on data after the FPakInfo footer.

function catalog_pak_archive_extension(string $filename): string
{
    $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
    return $ext === 'pak' ? 'pak' : '';
}

function catalog_pak_archive_is_supported_filename(string $filename): bool
{
    return catalog_pak_archive_extension($filename) !== '';
}

function catalog_pak_archive_config(array $config): array
{
    $pak = $config['pak'] ?? $config['pak_extract'] ?? [];
    return is_array($pak) ? $pak : [];
}

function catalog_pak_archive_max_files(array $config): int
{
    $pak = catalog_pak_archive_config($config);
    return max(1, (int)($pak['max_extracted_files'] ?? 20000));
}

function catalog_pak_archive_max_bytes(array $config): int
{
    $pak = catalog_pak_archive_config($config);
    return max(1, (int)($pak['max_extracted_bytes'] ?? (8 * 1024 * 1024 * 1024)));
}

function catalog_pak_archive_temp_dir(string $prefix = 'ue_pak_'): string
{
    $base = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(8));
    if (!mkdir($base, 0775, true) && !is_dir($base)) {
        throw new RuntimeException('Could not create temporary PAK extraction folder.');
    }
    return $base;
}

function catalog_pak_archive_delete_tree(string $path): void
{
    $real = realpath($path);
    if ($real === false || !is_dir($real)) {
        return;
    }

    $tmpRoot = realpath(sys_get_temp_dir());
    if ($tmpRoot === false || !str_starts_with($real, rtrim($tmpRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
        return;
    }

    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($real, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        if ($item instanceof SplFileInfo) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
    }
    @rmdir($real);
}

function catalog_pak_archive_relative_path(string $base, string $path): string
{
    $baseReal = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $pathReal = str_replace('\\', '/', realpath($path) ?: $path);
    if (str_starts_with($pathReal, $baseReal)) {
        return ltrim(substr($pathReal, strlen($baseReal)), '/');
    }
    return basename($path);
}

function catalog_pak_read_bytes(string $path, int $offset, int $length): string
{
    if ($length < 0 || $offset < 0) {
        throw new RuntimeException('Invalid PAK read range.');
    }
    $fh = fopen($path, 'rb');
    if (!$fh) {
        throw new RuntimeException('Could not open PAK for reading.');
    }
    try {
        if (fseek($fh, $offset) !== 0) {
            throw new RuntimeException('Could not seek PAK file.');
        }
        $data = $length === 0 ? '' : fread($fh, $length);
        if (!is_string($data) || strlen($data) !== $length) {
            throw new RuntimeException('Could not read PAK data block.');
        }
        return $data;
    } finally {
        fclose($fh);
    }
}

function catalog_pak_u32(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 4 > strlen($data)) {
        throw new RuntimeException('PAK u32 read overrun.');
    }
    return (int)(unpack('V', substr($data, $offset, 4))[1] ?? 0);
}

function catalog_pak_i32(string $data, int $offset): int
{
    $v = catalog_pak_u32($data, $offset);
    return ($v & 0x80000000) ? $v - 0x100000000 : $v;
}

function catalog_pak_i64(string $data, int $offset): int
{
    if ($offset < 0 || $offset + 8 > strlen($data)) {
        throw new RuntimeException('PAK i64 read overrun.');
    }
    $p = unpack('Vlo/Vhi', substr($data, $offset, 8));
    $lo = (int)($p['lo'] ?? 0);
    $hi = (int)($p['hi'] ?? 0);
    if (PHP_INT_SIZE >= 8) {
        return ($hi << 32) | $lo;
    }
    if ($hi !== 0) {
        throw new RuntimeException('PAK offset is too large for this PHP build.');
    }
    return $lo;
}

function catalog_pak_read_fstring(string $data, int &$offset): string
{
    $len = catalog_pak_i32($data, $offset);
    $offset += 4;
    if ($len === 0) {
        return '';
    }
    if ($len > 0) {
        if ($offset + $len > strlen($data)) {
            throw new RuntimeException('PAK FString read overrun.');
        }
        $raw = substr($data, $offset, $len);
        $offset += $len;
        return rtrim($raw, "\0");
    }

    $bytes = (-$len) * 2;
    if ($offset + $bytes > strlen($data)) {
        throw new RuntimeException('PAK wide FString read overrun.');
    }
    $raw = substr($data, $offset, $bytes);
    $offset += $bytes;
    if (substr($raw, -2) === "\0\0") {
        $raw = substr($raw, 0, -2);
    }
    $text = @mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE');
    return is_string($text) ? $text : '';
}

function catalog_pak_probe_bytes(string $path): array
{
    $size = (int)(filesize($path) ?: 0);
    $head = $size > 0 ? catalog_pak_read_bytes($path, 0, min(16, $size)) : '';
    $tailLen = $size > 0 ? min(32, $size) : 0;
    $tail = $tailLen > 0 ? catalog_pak_read_bytes($path, $size - $tailLen, $tailLen) : '';
    $text = static function (string $bytes): string {
        return preg_replace('/[^\x20-\x7E]/', '.', $bytes) ?? '';
    };

    return [
        'size' => $size,
        'head_hex' => strtoupper(bin2hex($head)),
        'head_text' => $text($head),
        'tail_hex' => strtoupper(bin2hex($tail)),
        'tail_text' => $text($tail),
    ];
}

function catalog_pak_footer_candidates(string $path): array
{
    $size = filesize($path);
    if (!$size || $size < 44) {
        throw new RuntimeException('PAK file is too small.');
    }

    $scanSize = min(CATALOG_PAK_FOOTER_SCAN_BYTES, (int)$size);
    $scanOffset = (int)$size - $scanSize;
    $scan = catalog_pak_read_bytes($path, $scanOffset, $scanSize);
    $magicBytes = pack('V', CATALOG_PAK_MAGIC);
    $candidates = [];
    $seen = [];
    $pos = -1;

    while (($pos = strpos($scan, $magicBytes, $pos + 1)) !== false) {
        $absolute = $scanOffset + $pos;
        foreach ([
            ['layout' => 'magic_first', 'magic' => $absolute],
            ['layout' => 'magic_last', 'magic' => $absolute],
        ] as $candidate) {
            try {
                $footer = catalog_pak_parse_footer_candidate($path, (int)$size, $candidate['layout'], (int)$candidate['magic']);
                if ($footer === null) {
                    continue;
                }
                $key = $footer['layout'] . ':' . $footer['version'] . ':' . $footer['index_offset'] . ':' . $footer['index_size'];
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $candidates[] = $footer;
                }
            } catch (Throwable) {
                // Keep trying other plausible footer layouts.
            }
        }
    }

    usort($candidates, static fn(array $a, array $b): int => $b['version'] <=> $a['version']);
    return $candidates;
}

function catalog_pak_parse_footer_candidate(string $path, int $fileSize, string $layout, int $magicOffset): ?array
{
    if ($layout === 'magic_first') {
        if ($magicOffset + 44 > $fileSize) {
            return null;
        }
        $data = catalog_pak_read_bytes($path, $magicOffset, 44);
        $version = catalog_pak_i32($data, 4);
        $indexOffset = catalog_pak_i64($data, 8);
        $indexSize = catalog_pak_i64($data, 16);
        $hash = substr($data, 24, 20);
    } else {
        if ($magicOffset < 40) {
            return null;
        }
        $data = catalog_pak_read_bytes($path, $magicOffset - 40, 44);
        $hash = substr($data, 0, 20);
        $indexOffset = catalog_pak_i64($data, 20);
        $indexSize = catalog_pak_i64($data, 28);
        $version = catalog_pak_i32($data, 36);
    }

    if ($version < 1 || $version > 20 || $indexOffset < 0 || $indexSize <= 0 || $indexOffset + $indexSize > $fileSize) {
        return null;
    }

    return [
        'layout' => $layout,
        'version' => $version,
        'index_offset' => $indexOffset,
        'index_size' => $indexSize,
        'index_hash' => bin2hex($hash),
        'magic_offset' => $magicOffset,
    ];
}

function catalog_pak_parse_entry(string $data, int &$offset, int $version): array
{
    $start = $offset;
    $entryOffset = catalog_pak_i64($data, $offset); $offset += 8;
    $size = catalog_pak_i64($data, $offset); $offset += 8;
    $uncompressedSize = catalog_pak_i64($data, $offset); $offset += 8;
    $compressionMethod = catalog_pak_u32($data, $offset); $offset += 4;

    $timestamp = null;
    if ($version > 0 && $version < 2) {
        $timestamp = catalog_pak_i64($data, $offset);
        $offset += 8;
    }

    if ($offset + 20 > strlen($data)) {
        throw new RuntimeException('PAK entry hash read overrun.');
    }
    $hash = substr($data, $offset, 20); $offset += 20;

    $blocks = [];
    $encrypted = false;
    $blockSize = 0;

    if ($version >= 3) {
        if ($compressionMethod !== 0) {
            $blockCount = catalog_pak_i32($data, $offset); $offset += 4;
            if ($blockCount < 0 || $blockCount > 65536) {
                throw new RuntimeException('Invalid compressed block count in PAK index.');
            }
            for ($i = 0; $i < $blockCount; $i++) {
                $blocks[] = [
                    'start' => catalog_pak_i64($data, $offset),
                    'end' => catalog_pak_i64($data, $offset + 8),
                ];
                $offset += 16;
            }
        }
        if ($offset < strlen($data)) {
            $encrypted = ord($data[$offset]) !== 0;
            $offset += 1;
        }
        if ($offset + 4 <= strlen($data)) {
            $blockSize = catalog_pak_u32($data, $offset);
            $offset += 4;
        }
    }

    return [
        'entry_bytes' => $offset - $start,
        'offset' => $entryOffset,
        'size' => $size,
        'uncompressed_size' => $uncompressedSize,
        'compression_method' => $compressionMethod,
        'hash' => bin2hex($hash),
        'blocks' => $blocks,
        'encrypted' => $encrypted,
        'compression_block_size' => $blockSize,
        'timestamp' => $timestamp,
        'version' => $version,
    ];
}

function catalog_pak_parse_index(string $path, array $footer): array
{
    $index = catalog_pak_read_bytes($path, (int)$footer['index_offset'], (int)$footer['index_size']);
    $offset = 0;
    $mount = catalog_pak_read_fstring($index, $offset);
    $count = catalog_pak_i32($index, $offset);
    $offset += 4;
    if ($count < 0 || $count > 1000000) {
        throw new RuntimeException('PAK index appears to be encrypted or unsupported.');
    }

    $entries = [];
    for ($i = 0; $i < $count; $i++) {
        $filename = catalog_pak_read_fstring($index, $offset);
        $entry = catalog_pak_parse_entry($index, $offset, (int)$footer['version']);
        $filename = trim(str_replace('\\', '/', $filename), '/');
        if ($filename !== '') {
            $entries[] = ['filename' => $filename] + $entry;
        }
    }

    return ['mount_point' => $mount, 'entries' => $entries];
}

function catalog_pak_archive_decode_payload(string $payload): ?string
{
    foreach ([@zlib_decode($payload), @gzuncompress($payload), @gzinflate($payload)] as $decoded) {
        if (is_string($decoded) && $decoded !== '') {
            return $decoded;
        }
    }
    return null;
}

function catalog_pak_entry_data_offset(string $path, array $entry): int
{
    $offset = (int)$entry['offset'];
    $head = catalog_pak_read_bytes($path, $offset, 4);
    if (\UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedBytes($head)) {
        return $offset;
    }

    $peekSize = min(1024, max(64, (int)((filesize($path) ?: 0) - $offset)));
    $peek = catalog_pak_read_bytes($path, $offset, $peekSize);
    try {
        $cursor = 0;
        catalog_pak_parse_entry($peek, $cursor, (int)($entry['version'] ?? 3));
        return $offset + $cursor;
    } catch (Throwable) {
        return $offset;
    }
}

function catalog_pak_write_entry_data(string $pakPath, array $entry, string $destPath): bool
{
    if (!empty($entry['encrypted'])) {
        return false;
    }

    $method = (int)$entry['compression_method'];
    $payload = '';
    if ($method === 0) {
        $dataOffset = catalog_pak_entry_data_offset($pakPath, $entry);
        $payload = catalog_pak_read_bytes($pakPath, $dataOffset, (int)$entry['size']);
    } else {
        $blocks = $entry['blocks'] ?? [];
        if (!is_array($blocks) || $blocks === []) {
            return false;
        }
        foreach ($blocks as $block) {
            $start = (int)$block['start'];
            $end = (int)$block['end'];
            if ($end <= $start) {
                return false;
            }
            $compressed = catalog_pak_read_bytes($pakPath, $start, $end - $start);
            $decoded = catalog_pak_archive_decode_payload($compressed);
            if (!is_string($decoded)) {
                return false;
            }
            $payload .= $decoded;
        }
        if (strlen($payload) !== (int)$entry['uncompressed_size']) {
            return false;
        }
    }

    $dir = dirname($destPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create extracted PAK output folder.');
    }
    return @file_put_contents($destPath, $payload) !== false;
}

/** @return list<array{path:string,relative:string,bytes:int}> */
function catalog_pak_archive_collect_files(array $config, string $extractDir): array
{
    $extractReal = realpath($extractDir);
    if ($extractReal === false) {
        throw new RuntimeException('PAK extraction folder disappeared.');
    }

    $maxFiles = catalog_pak_archive_max_files($config);
    $maxBytes = catalog_pak_archive_max_bytes($config);
    $files = [];
    $bytes = 0;

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($extractReal, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        $real = realpath($path);
        if ($real === false || !str_starts_with($real, rtrim($extractReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            continue;
        }

        $size = (int)$item->getSize();
        $bytes += $size;
        if (count($files) + 1 > $maxFiles) {
            throw new RuntimeException('PAK extraction produced too many files; limit is ' . $maxFiles . '.');
        }
        if ($bytes > $maxBytes) {
            throw new RuntimeException('PAK extraction exceeded byte limit: ' . catalog_bytes($maxBytes) . '.');
        }

        $files[] = [
            'path' => $real,
            'relative' => catalog_pak_archive_relative_path($extractReal, $real),
            'bytes' => $size,
        ];
    }

    return $files;
}

/** @return array{dir:string,files:list<array{path:string,relative:string,bytes:int}>,log:string,source_name:string} */
function catalog_pak_archive_extract_to_temp(array $config, string $pakPath, string $sourceName = ''): array
{
    if (!catalog_pak_archive_is_supported_filename($sourceName !== '' ? $sourceName : $pakPath)) {
        throw new RuntimeException('Not an Unreal PAK file: ' . basename($sourceName !== '' ? $sourceName : $pakPath));
    }
    if (!is_file($pakPath)) {
        throw new RuntimeException('PAK file is missing.');
    }

    $workDir = catalog_pak_archive_temp_dir('ue_pak_work_');
    $extractDir = $workDir . DIRECTORY_SEPARATOR . 'extract';
    if (!mkdir($extractDir, 0775, true) && !is_dir($extractDir)) {
        catalog_pak_archive_delete_tree($workDir);
        throw new RuntimeException('Could not create PAK extraction output folder.');
    }

    try {
        $footers = catalog_pak_footer_candidates($pakPath);
        if ($footers === []) {
            throw new RuntimeException('Unsupported PAK file: no Unreal PAK magic footer was found. UnrealDB can only extract standard Unreal Engine .pak archives.');
        }

        $lastError = '';
        foreach ($footers as $footer) {
            try {
                $index = catalog_pak_parse_index($pakPath, $footer);
                $extracted = 0;
                $skipped = 0;
                foreach ($index['entries'] as $entry) {
                    $relative = trim(str_replace(['..', '\\'], ['', '/'], (string)$entry['filename']), '/');
                    if ($relative === '') {
                        continue;
                    }
                    $dest = $extractDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                    if (catalog_pak_write_entry_data($pakPath, $entry, $dest)) {
                        $extracted++;
                    } else {
                        $skipped++;
                    }
                }

                $files = catalog_pak_archive_collect_files($config, $extractDir);
                if ($files === []) {
                    throw new RuntimeException('PAK index was read, but no entries could be extracted. Entries may be encrypted or use unsupported compression.');
                }

                return [
                    'dir' => $workDir,
                    'files' => $files,
                    'log' => 'PHP PAK extractor: version=' . (int)$footer['version'] . '; layout=' . $footer['layout'] . '; magic_offset=' . (int)($footer['magic_offset'] ?? -1) . '; mount=' . ($index['mount_point'] ?? '') . '; extracted=' . $extracted . '; skipped=' . $skipped,
                    'source_name' => basename($sourceName !== '' ? $sourceName : $pakPath),
                ];
            } catch (Throwable $error) {
                $lastError = $error->getMessage();
                catalog_pak_archive_delete_tree($extractDir);
                mkdir($extractDir, 0775, true);
            }
        }

        throw new RuntimeException('Could not parse PAK index with supported layouts.' . ($lastError !== '' ? ' Last error: ' . $lastError : ''));
    } catch (Throwable $error) {
        catalog_pak_archive_delete_tree($workDir);
        throw $error;
    }
}
