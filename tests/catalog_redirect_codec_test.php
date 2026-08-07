<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies catalog redirect codec behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

require_once __DIR__ . '/../catalog/lib/CatalogRedirectCodec.php';

function redirect_codec_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Redirect codec test failed: ' . $message);
    }
}

$package = "\xC1\x83\x2A\x9E";
for ($index = 0; $index < 20000; $index++) {
    $package .= chr(($index * 17 + intdiv($index, 13)) & 0xff);
}

$fixtures = [
    'uz-1234' => catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'uz', 9, 4096, 1234),
    'uz-5678' => catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'uz', 9, 4096, 5678),
    'uz2' => catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'uz2', 9, 4096),
    'uz3' => catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'uz3', 9, 4096),
];

$expectedEncoders = [
    'uz-1234' => 'epic-uz-1234-rle+bwt+mtf+huffman',
    'uz-5678' => 'epic-uz-5678-rle+bwt+mtf+rle+huffman',
    'uz2' => 'epic-uz2-zlib',
    'uz3' => 'epic-uz3-zlib',
];
$extensions = [
    'uz-1234' => 'uz',
    'uz-5678' => 'uz',
    'uz2' => 'uz2',
    'uz3' => 'uz3',
];

foreach ($fixtures as $name => $compressed) {
    $extension = $extensions[$name];
    redirect_codec_test_assert((string)$compressed['filename'] === 'TestPackage.u.' . $extension, $name . ' output filename is incorrect.');
    redirect_codec_test_assert((string)$compressed['encoder'] === $expectedEncoders[$name], $name . ' used the wrong encoder.');
    redirect_codec_test_assert((int)$compressed['uncompressed_bytes'] === strlen($package), $name . ' source size metadata is incorrect.');
    redirect_codec_test_assert((int)$compressed['bytes'] === strlen((string)$compressed['data']), $name . ' compressed size metadata is incorrect.');
    redirect_codec_test_assert((int)$compressed['chunks'] > 0, $name . ' did not report any compressed chunks.');

    $decompressed = catalog_redirect_archive_decompress_data(
        (string)$compressed['data'],
        $extension,
        1024 * 1024
    );
    redirect_codec_test_assert(is_array($decompressed), $name . ' round-trip decompression failed.');
    redirect_codec_test_assert(hash_equals(hash('sha256', $package), hash('sha256', (string)$decompressed['data'])), $name . ' round-trip bytes differ.');
}

$uz1234 = $fixtures['uz-1234'];
$uz5678 = $fixtures['uz-5678'];
$uz2 = $fixtures['uz2'];
$uz3 = $fixtures['uz3'];

$uz1234Header = unpack('Vsignature', substr((string)$uz1234['data'], 0, 4));
$uz5678Header = unpack('Vsignature', substr((string)$uz5678['data'], 0, 4));
redirect_codec_test_assert((int)($uz1234Header['signature'] ?? 0) === 1234, 'Standard UZ signature is not 1234.');
redirect_codec_test_assert((int)($uz5678Header['signature'] ?? 0) === 5678, 'Newer UZ signature is not 5678.');
redirect_codec_test_assert((string)$uz1234['embedded_filename'] === 'TestPackage.u', 'Standard UZ embedded filename is incorrect.');
redirect_codec_test_assert((string)$uz5678['embedded_filename'] === 'TestPackage.u', 'Newer UZ embedded filename is incorrect.');

$uz1234Decoded = catalog_redirect_archive_decompress_data((string)$uz1234['data'], 'uz', 1024 * 1024);
$uz5678Decoded = catalog_redirect_archive_decompress_data((string)$uz5678['data'], 'uz', 1024 * 1024);
redirect_codec_test_assert((string)($uz1234Decoded['decoder'] ?? '') === 'epic-uz-1234-huffman+mtf+bwt+rle', 'Standard UZ used the wrong decoder label.');
redirect_codec_test_assert((string)($uz5678Decoded['decoder'] ?? '') === 'epic-uz-5678-huffman+rle+mtf+bwt+rle', 'Newer UZ used the wrong decoder label.');

$uz2Decoded = catalog_redirect_archive_decompress_data((string)$uz2['data'], 'uz2', 1024 * 1024);
redirect_codec_test_assert(str_starts_with((string)($uz2Decoded['decoder'] ?? ''), 'epic-uz2-zlib-'), 'UZ2 did not use exact zlib record decoding.');

$uz3Header = unpack('Vsignature/Vuncompressed', substr((string)$uz3['data'], 0, 8));
redirect_codec_test_assert((int)($uz3Header['signature'] ?? 0) === 5678, 'UZ3 tag is not 5678.');
redirect_codec_test_assert((int)($uz3Header['uncompressed'] ?? 0) === strlen($package), 'UZ3 uncompressed size header is incorrect.');
redirect_codec_test_assert(!isset($uz3['embedded_filename']), 'UZ3 incorrectly contains embedded filename metadata.');
$uz3Decoded = catalog_redirect_archive_decompress_data((string)$uz3['data'], 'uz3', 1024 * 1024);
redirect_codec_test_assert(str_starts_with((string)($uz3Decoded['decoder'] ?? ''), 'epic-uz3-zlib-'), 'UZ3 did not use whole-file zlib decoding.');
redirect_codec_test_assert((int)($uz3Decoded['chunks'] ?? 0) === 1, 'UZ3 was not decoded as one compressed unit.');

redirect_codec_test_assert(catalog_redirect_archive_decompress_data((string)$uz1234['data'], 'uz3', 1024 * 1024) === null, 'UZ 1234 data was accepted as UZ3.');
redirect_codec_test_assert(catalog_redirect_archive_decompress_data((string)$uz5678['data'], 'uz3', 1024 * 1024) === null, 'UZ 5678 data was accepted as UZ3.');
redirect_codec_test_assert(catalog_redirect_archive_decompress_data((string)$uz3['data'], 'uz', 1024 * 1024) === null, 'UZ3 data was accepted as UZ.');
redirect_codec_test_assert(catalog_redirect_archive_decompress_data(substr((string)$uz3['data'], 0, -1), 'uz3', 1024 * 1024) === null, 'Truncated UZ3 data was accepted.');

$sourcePath = tempnam(sys_get_temp_dir(), 'ue_redirect_codec_source_');
if ($sourcePath === false || file_put_contents($sourcePath, $package) !== strlen($package)) {
    throw new RuntimeException('Could not create redirect compression source fixture.');
}
try {
    $tempResult = catalog_redirect_archive_compress_to_temp(
        $sourcePath,
        'TestPackage.u',
        'uz',
        9,
        4096,
        5678
    );
    redirect_codec_test_assert(is_file((string)$tempResult['path']), 'Temporary UZ file was not created.');
    redirect_codec_test_assert((int)$tempResult['wrapper_signature'] === 5678, 'Temporary UZ did not preserve selected signature 5678.');
    $tempData = file_get_contents((string)$tempResult['path']);
    redirect_codec_test_assert(is_string($tempData), 'Temporary UZ file could not be read.');
    $tempDecoded = catalog_redirect_archive_decompress_data($tempData, 'uz', 1024 * 1024);
    redirect_codec_test_assert(is_array($tempDecoded), 'Temporary UZ file could not be decompressed.');
    redirect_codec_test_assert(hash_equals(hash('sha256', $package), hash('sha256', (string)$tempDecoded['data'])), 'Temporary UZ round-trip bytes differ.');
    @unlink((string)$tempResult['path']);
} finally {
    @unlink($sourcePath);
}

$badSignatureRejected = false;
try {
    catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'uz', 9, 4096, 9999);
} catch (InvalidArgumentException) {
    $badSignatureRejected = true;
}
redirect_codec_test_assert($badSignatureRejected, 'Invalid UZ signature was accepted.');

$plainData = "plain redirect payload\0with binary bytes\x01\x02";
foreach ([
    ['uz', 1234],
    ['uz', 5678],
    ['uz2', 1234],
    ['uz3', 1234],
] as [$extension, $signature]) {
    $plainCompressed = catalog_redirect_archive_compress_data(
        $plainData,
        'ReadMe.txt',
        $extension,
        9,
        4096,
        $signature
    );
    $plainDecoded = catalog_redirect_archive_decompress_data(
        (string)$plainCompressed['data'],
        $extension,
        1024 * 1024
    );
    redirect_codec_test_assert(is_array($plainDecoded), $extension . ' rejected a non-package redirect payload.');
    redirect_codec_test_assert(hash_equals($plainData, (string)$plainDecoded['data']), $extension . ' changed non-package payload bytes.');
}

$badExtensionRejected = false;
try {
    catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'zip');
} catch (InvalidArgumentException) {
    $badExtensionRejected = true;
}
redirect_codec_test_assert($badExtensionRejected, 'Unsupported redirect extension was accepted.');

echo "catalog_redirect_codec_test: OK\n";
