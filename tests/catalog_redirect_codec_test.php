<?php
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

$expectedEncoders = [
    'uz' => 'legacy-uz-rle+bwt+mtf+huffman',
    'uz2' => 'epic-uz2-zlib',
    'uz3' => 'epic-uz3-rle+bwt+mtf+rle+huffman',
];
$expectedDecoders = [
    'uz' => 'legacy-uz-huffman+mtf+bwt+rle',
    'uz2' => 'epic-uz2-zlib',
    'uz3' => 'epic-uz3-huffman+rle+mtf+bwt+rle',
];

foreach (['uz', 'uz2', 'uz3'] as $extension) {
    $compressed = catalog_redirect_archive_compress_data(
        $package,
        'TestPackage.u',
        $extension,
        9,
        4096
    );

    redirect_codec_test_assert((string)$compressed['filename'] === 'TestPackage.u.' . $extension, $extension . ' output filename is incorrect.');
    redirect_codec_test_assert((string)$compressed['encoder'] === $expectedEncoders[$extension], $extension . ' used the wrong encoder.');
    redirect_codec_test_assert((int)$compressed['uncompressed_bytes'] === strlen($package), $extension . ' source size metadata is incorrect.');
    redirect_codec_test_assert((int)$compressed['bytes'] === strlen((string)$compressed['data']), $extension . ' compressed size metadata is incorrect.');
    redirect_codec_test_assert((int)$compressed['chunks'] > 0, $extension . ' did not report any compressed chunks.');

    if ($extension === 'uz') {
        $signature = unpack('V', substr((string)$compressed['data'], 0, 4));
        redirect_codec_test_assert((int)($signature[1] ?? 0) === 1234, 'UZ signature is not 1234.');
        redirect_codec_test_assert((string)$compressed['embedded_filename'] === 'TestPackage.u', 'UZ embedded filename is incorrect.');
    } elseif ($extension === 'uz3') {
        $signature = unpack('V', substr((string)$compressed['data'], 0, 4));
        redirect_codec_test_assert((int)($signature[1] ?? 0) === 5678, 'UZ3 signature is not 5678.');
        redirect_codec_test_assert((string)$compressed['embedded_filename'] === 'TestPackage.u', 'UZ3 embedded filename is incorrect.');
    }

    $decompressed = catalog_redirect_archive_decompress_data(
        (string)$compressed['data'],
        $extension,
        1024 * 1024
    );
    redirect_codec_test_assert(is_array($decompressed), $extension . ' round-trip decompression failed.');
    redirect_codec_test_assert((string)$decompressed['decoder'] === $expectedDecoders[$extension], $extension . ' used the wrong decoder.');
    redirect_codec_test_assert(hash_equals(hash('sha256', $package), hash('sha256', (string)$decompressed['data'])), $extension . ' round-trip bytes differ.');
}

$uz = catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'uz', 9, 4096);
$uz3 = catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'uz3', 9, 4096);
redirect_codec_test_assert(catalog_redirect_archive_decompress_data((string)$uz['data'], 'uz3', 1024 * 1024) === null, 'UZ data was accepted as UZ3.');
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
        'uz3',
        9,
        4096
    );
    redirect_codec_test_assert(is_file((string)$tempResult['path']), 'Temporary UZ3 file was not created.');
    redirect_codec_test_assert((string)$tempResult['filename'] === 'TestPackage.u.uz3', 'Temporary UZ3 filename is incorrect.');
    $tempData = file_get_contents((string)$tempResult['path']);
    redirect_codec_test_assert(is_string($tempData), 'Temporary UZ3 file could not be read.');
    $tempDecoded = catalog_redirect_archive_decompress_data($tempData, 'uz3', 1024 * 1024);
    redirect_codec_test_assert(is_array($tempDecoded), 'Temporary UZ3 file could not be decompressed.');
    redirect_codec_test_assert(hash_equals(hash('sha256', $package), hash('sha256', (string)$tempDecoded['data'])), 'Temporary UZ3 round-trip bytes differ.');
    @unlink((string)$tempResult['path']);
} finally {
    @unlink($sourcePath);
}

$badSourceRejected = false;
try {
    catalog_redirect_archive_compress_data('not an Unreal package', 'bad.u', 'uz3');
} catch (RuntimeException) {
    $badSourceRejected = true;
}
redirect_codec_test_assert($badSourceRejected, 'Non-package input was accepted for compression.');

$badExtensionRejected = false;
try {
    catalog_redirect_archive_compress_data($package, 'TestPackage.u', 'zip');
} catch (InvalidArgumentException) {
    $badExtensionRejected = true;
}
redirect_codec_test_assert($badExtensionRejected, 'Unsupported redirect extension was accepted.');

echo "catalog_redirect_codec_test: OK\n";
