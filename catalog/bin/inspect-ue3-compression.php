#!/usr/bin/env php
<?php
/**
 * Read-only UE3 package compression inspector.
 *
 * Reads one package file directly and reports the serialized compression flags
 * and FCompressedChunk ranges. It does not enqueue/import/index or modify data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once dirname(__DIR__) . '/parsers/EpicUE3PackageReader.php';

$options = getopt('', ['path:']);
$path = trim((string)($options['path'] ?? ''));
if ($path === '') {
    fwrite(STDERR, "Usage: php catalog/bin/inspect-ue3-compression.php --path=PATH\n");
    exit(2);
}
if (!is_file($path)) {
    fwrite(STDERR, "File not found: " . $path . "\n");
    exit(2);
}

$reader = new CatalogUE3PackageReader($path);
$header = $reader->getHeader();
$issues = $reader->getIssues();
$physicalSize = filesize($path);
$physicalSize = $physicalSize === false ? 0 : (int)$physicalSize;
$chunks = is_array($header['chunks'] ?? null) ? $header['chunks'] : [];
$flags = (int)($header['compressionFlags'] ?? 0);
$methodCode = $flags & 0x0F;
$method = match ($methodCode) {
    0 => 'none',
    1 => 'zlib',
    2 => 'lzo',
    4 => 'lzx',
    default => 'unknown',
};

$chunkRows = [];
$boundsOk = true;
foreach ($chunks as $index => $chunk) {
    $uOff = (int)($chunk['uOff'] ?? 0);
    $uLen = (int)($chunk['uLen'] ?? 0);
    $cOff = (int)($chunk['cOff'] ?? 0);
    $cLen = (int)($chunk['cLen'] ?? 0);
    $cEnd = $cOff + $cLen;
    $fits = $cOff >= 0 && $cLen >= 0 && $cEnd <= $physicalSize;
    $boundsOk = $boundsOk && $fits;
    $chunkRows[] = [
        'index' => (int)$index,
        'uncompressed_offset' => $uOff,
        'uncompressed_size' => $uLen,
        'uncompressed_end' => $uOff + $uLen,
        'compressed_offset' => $cOff,
        'compressed_size' => $cLen,
        'compressed_end' => $cEnd,
        'fits_physical_file' => $fits,
    ];
}

$result = [
    'ok' => $issues === [],
    'read_only' => true,
    'path' => $path,
    'physical_size' => $physicalSize,
    'sha1' => sha1_file($path) ?: '',
    'package_version' => (int)($header['version'] ?? 0),
    'licensee_version' => (int)($header['licenseeVersion'] ?? 0),
    'compression_flags' => sprintf('0x%08X', $flags),
    'compression_method_code' => $methodCode,
    'compression_method' => $method,
    'compressed' => count($chunks) > 0,
    'chunk_count' => count($chunks),
    'chunk_bounds_ok' => $boundsOk,
    'chunks' => $chunkRows,
    'reader_issues' => $issues,
];

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit(0);
