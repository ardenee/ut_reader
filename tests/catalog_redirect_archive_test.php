<?php
declare(strict_types=1);

require_once __DIR__ . '/../catalog/lib/CatalogRedirectArchive.php';

function redirect_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Redirect archive test failed: ' . $message);
    }
}

$blockSize = 32768;
$expectedBytes = 11204168;
$blockCount = (int)ceil($expectedBytes / $blockSize);
$pattern = hash('sha256', 'UnrealDB redirect archive regression', true);
$package = "\xC1\x83\x2A\x9E" . substr(str_repeat($pattern, (int)ceil(($expectedBytes - 4) / strlen($pattern))), 0, $expectedBytes - 4);

/* Real UZ2 layout: repeated [compressed size, uncompressed size, zlib payload] records. */
$wrapper = '';
foreach (str_split($package, $blockSize) as $block) {
    $compressed = gzcompress($block, 9);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not prepare compressed regression block.');
    }
    $wrapper .= pack('V2', strlen($compressed), strlen($block)) . $compressed;
}

$decoded = catalog_redirect_archive_decode_data($wrapper, 32 * 1024 * 1024);
redirect_test_assert(is_array($decoded), 'pair-record wrapper was not decoded.');
redirect_test_assert((int)$decoded['chunks'] === $blockCount, 'not every compressed record was consumed.');
redirect_test_assert(strlen((string)$decoded['data']) === $expectedBytes, 'decoded byte count is incorrect.');
redirect_test_assert(hash_equals(hash('sha256', $package), hash('sha256', (string)$decoded['data'])), 'decoded package bytes differ from the source package.');
redirect_test_assert(catalog_redirect_archive_decode_data(substr($wrapper, 0, -10), 32 * 1024 * 1024) === null, 'truncated pair-record wrapper was accepted.');

/* Preserve support for wrappers that declare total output size before concatenated members. */
$smallPackage = substr($package, 0, $blockSize * 3 - 123);
$members = '';
foreach (str_split($smallPackage, $blockSize) as $block) {
    $members .= gzcompress($block, 9);
}
$prefixed = pack('V', strlen($smallPackage)) . $members;
$prefixedDecoded = catalog_redirect_archive_decode_data($prefixed, 8 * 1024 * 1024);
redirect_test_assert(is_array($prefixedDecoded), 'declared-size wrapper was not decoded.');
redirect_test_assert(strlen((string)$prefixedDecoded['data']) === strlen($smallPackage), 'declared-size output is incorrect.');
$truncatedPrefixed = pack('V', strlen($smallPackage)) . gzcompress(substr($smallPackage, 0, $blockSize), 9);
redirect_test_assert(catalog_redirect_archive_decode_data($truncatedPrefixed, 8 * 1024 * 1024) === null, 'truncated declared-size wrapper was accepted.');

$sourcePath = tempnam(sys_get_temp_dir(), 'ue_redirect_test_');
if ($sourcePath === false || file_put_contents($sourcePath, $wrapper) !== strlen($wrapper)) {
    throw new RuntimeException('Could not create redirect archive regression fixture.');
}

try {
    $result = catalog_redirect_archive_decompress_to_temp($sourcePath, 'outdoortxt.utx.uz2', 32 * 1024 * 1024);
    redirect_test_assert((int)$result['bytes'] === $expectedBytes, 'temporary output size is incorrect.');
    redirect_test_assert((int)$result['chunks'] === $blockCount, 'temporary output metadata has the wrong record count.');
    redirect_test_assert((string)$result['filename'] === 'outdoortxt.utx', 'redirect extension was not removed from the output filename.');
    redirect_test_assert(hash_equals(hash('sha256', $package), (string)hash_file('sha256', (string)$result['path'])), 'temporary output bytes are incorrect.');
    @unlink((string)$result['path']);
} finally {
    @unlink($sourcePath);
}

echo "catalog_redirect_archive_test: OK\n";
