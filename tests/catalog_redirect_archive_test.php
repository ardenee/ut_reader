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
$blockCount = 342;
$expectedBytes = $blockSize * $blockCount;
$pattern = hash('sha256', 'UnrealDB redirect archive regression', true);
$package = "\xC1\x83\x2A\x9E" . substr(str_repeat($pattern, (int)ceil(($expectedBytes - 4) / strlen($pattern))), 0, $expectedBytes - 4);
$blocks = str_split($package, $blockSize);

$compressedMembers = [];
foreach ($blocks as $block) {
    $compressed = gzcompress($block, 9);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not prepare compressed regression block.');
    }
    $compressedMembers[] = $compressed;
}

$wrapper = pack('V', $expectedBytes) . implode('', $compressedMembers);
$decoded = catalog_redirect_archive_decode_data($wrapper, 32 * 1024 * 1024);
redirect_test_assert(is_array($decoded), '342-member wrapper was not decoded.');
redirect_test_assert((int)$decoded['chunks'] === $blockCount, 'not every compressed member was consumed.');
redirect_test_assert(strlen((string)$decoded['data']) === $expectedBytes, 'decoded byte count does not match the declared original size.');
redirect_test_assert(hash_equals(hash('sha256', $package), hash('sha256', (string)$decoded['data'])), 'decoded package bytes differ from the source package.');

$truncatedWrapper = pack('V', $expectedBytes) . $compressedMembers[0] . $compressedMembers[1];
redirect_test_assert(catalog_redirect_archive_decode_data($truncatedWrapper, 32 * 1024 * 1024) === null, 'truncated wrapper was accepted as a complete package.');

$sourcePath = tempnam(sys_get_temp_dir(), 'ue_redirect_test_');
if ($sourcePath === false || file_put_contents($sourcePath, $wrapper) !== strlen($wrapper)) {
    throw new RuntimeException('Could not create redirect archive regression fixture.');
}

try {
    $result = catalog_redirect_archive_decompress_to_temp($sourcePath, 'outdoortxt.utx.uz2', 32 * 1024 * 1024);
    redirect_test_assert((int)$result['bytes'] === $expectedBytes, 'temporary output size is incorrect.');
    redirect_test_assert((int)$result['chunks'] === $blockCount, 'temporary output metadata has the wrong block count.');
    redirect_test_assert((string)$result['filename'] === 'outdoortxt.utx', 'redirect extension was not removed from the output filename.');
    redirect_test_assert(hash_equals(hash('sha256', $package), (string)hash_file('sha256', (string)$result['path'])), 'temporary output bytes are incorrect.');
    @unlink((string)$result['path']);
} finally {
    @unlink($sourcePath);
}

echo "catalog_redirect_archive_test: OK\n";
