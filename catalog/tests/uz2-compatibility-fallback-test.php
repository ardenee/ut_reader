<?php
declare(strict_types=1);

const CATALOG_EPIC_UZ2_BLOCK_BYTES = 32768;
const CATALOG_EPIC_UZ2_MAX_COMPRESSED_BYTES = 33096;

function catalog_redirect_archive_extension(string $filename): string
{
    return strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
}

function catalog_redirect_archive_output_limit(int $limit): int
{
    return $limit > 0 ? $limit : 32 * 1024 * 1024;
}

function catalog_redirect_archive_has_package_tag(string $data): bool
{
    return substr($data, 0, 4) === "\xC1\x83\x2A\x9E";
}

function catalog_redirect_archive_output_name(string $filename): string
{
    return preg_replace('/\.uz2$/i', '', basename($filename)) ?? basename($filename);
}

/** Force the incremental implementation fallback to fail so gzuncompress() is exercised. */
function catalog_redirect_archive_inflate_epic_zlib(string $payload, int $limit, int $expected): ?array
{
    return null;
}

require_once __DIR__ . '/../src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php';

use UnrealDb\Catalog\Infrastructure\Jobs\CatalogRedirectArchiveStream;

function uz2_epic_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(CatalogRedirectArchiveStream::class);
$decode = $reflection->getMethod('decodeRecord');
$decode->setAccessible(true);

$package = "\xC1\x83\x2A\x9E" . str_repeat('CelesteFX', 64);
$zlib = gzcompress($package, 9);
$raw = gzdeflate($package, 9);
$gzip = gzencode($package, 9);
uz2_epic_expect(is_string($zlib) && is_string($raw) && is_string($gzip), 'Could not build zlib fixtures.');

$decoded = $decode->invoke(null, $zlib, strlen($package), strlen($package));
uz2_epic_expect(is_array($decoded), 'Epic zlib record was rejected.');
uz2_epic_expect(hash_equals($package, (string)$decoded['data']), 'Epic zlib record changed output bytes.');
uz2_epic_expect((string)$decoded['decoder'] === 'zlib-uncompress', 'The Epic zlib uncompress path was not used.');
uz2_epic_expect($decode->invoke(null, $raw, strlen($package), strlen($package)) === null, 'Raw deflate was accepted as Epic UZ2.');
uz2_epic_expect($decode->invoke(null, $gzip, strlen($package), strlen($package)) === null, 'Gzip was accepted as Epic UZ2.');
uz2_epic_expect(
    $decode->invoke(null, $zlib, strlen($package) + 1, strlen($package) + 1) === null,
    'The decoder accepted the wrong declared output length.'
);

/* Equal compressed and uncompressed sizes still contain a zlib stream. */
$equalPackage = "\xC1\x83\x2A\x9E" . str_repeat("\x9E", 9);
$equalCompressed = hex2bin('78da3bd8ac350f0e0033be079b');
uz2_epic_expect(
    is_string($equalCompressed) && strlen($equalCompressed) === strlen($equalPackage),
    'Equal-size zlib fixture is invalid.'
);
$equalDecoded = $decode->invoke(null, $equalCompressed, 1024, strlen($equalPackage));
uz2_epic_expect(is_array($equalDecoded), 'Equal-size zlib record was rejected.');
uz2_epic_expect(hash_equals($equalPackage, (string)$equalDecoded['data']), 'Equal-size zlib record was treated as stored bytes.');

$wrapper = pack('V2', strlen($zlib), strlen($package)) . $zlib;
$source = tempnam(sys_get_temp_dir(), 'uz2_epic_');
uz2_epic_expect(is_string($source) && file_put_contents($source, $wrapper) === strlen($wrapper), 'Could not write wrapper fixture.');
try {
    $result = CatalogRedirectArchiveStream::decompressUz2($source, 'CelesteFX.uax.uz2', 1024 * 1024, null, true);
    uz2_epic_expect((string)$result['filename'] === 'CelesteFX.uax', 'Output filename is incorrect.');
    uz2_epic_expect((int)$result['bytes'] === strlen($package), 'Output byte count is incorrect.');
    uz2_epic_expect(hash_equals(md5($package), (string)$result['md5']), 'MD5 was not calculated from decoded bytes.');
    uz2_epic_expect(hash_equals(sha1($package), (string)$result['sha1']), 'SHA-1 was not calculated from decoded bytes.');
    uz2_epic_expect(hash_equals($package, (string)file_get_contents((string)$result['path'])), 'Streaming output differs from source bytes.');
    @unlink((string)$result['path']);
} finally {
    @unlink($source);
}

$badSource = tempnam(sys_get_temp_dir(), 'uz2_bad_');
$badWrapper = pack('V2', 4, 16) . "\x01\x02\x03\x04";
uz2_epic_expect(is_string($badSource) && file_put_contents($badSource, $badWrapper) === strlen($badWrapper), 'Could not write bad wrapper fixture.');
try {
    $message = '';
    try {
        CatalogRedirectArchiveStream::decompressUz2($badSource, 'bad.uax.uz2', 1024 * 1024, null, false);
    } catch (RuntimeException $error) {
        $message = $error->getMessage();
    }
    uz2_epic_expect(
        str_contains($message, 'record 1')
            && str_contains($message, 'payload=01020304')
            && str_contains($message, 'available decoders:')
            && !str_contains($message, 'gzinflate')
            && !str_contains($message, 'gzdecode'),
        'Malformed records do not report strict Epic zlib diagnostics.'
    );
} finally {
    @unlink($badSource);
}

echo "Epic UZ2 format tests passed.\n";
