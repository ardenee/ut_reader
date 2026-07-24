<?php
declare(strict_types=1);

require_once __DIR__ . '/../catalog/lib/CatalogRedirectArchivePayload.php';

use UnrealDb\Catalog\Infrastructure\Redirect\CatalogRedirectArchiveProcessor;

function redirect_payload_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Redirect payload test failed: ' . $message);
    }
}

/* Signature-1234 UE1 fixture with a non-package ELF payload. */
$legacy = base64_decode(
    '0gQAAApTYW1wbGUuc28AMgAAAAMoYHr+CBwdhA0oCS4CBy/BQzSOtgQqLGRnBq01bXsTl5y6KhgEVdDtYvY47Lrku11fyVPtif9Ok/1EFG8+Ag==',
    true
);
$legacyOutput = "\x7fELF\x01\x01\x01\0Sample shared object payload\0";
redirect_payload_assert(is_string($legacy), 'signature-1234 fixture could not be decoded.');

$decoded = catalog_redirect_archive_decode_payload($legacy, 'uz', 1024 * 1024);
redirect_payload_assert(is_array($decoded), 'signature-1234 non-package payload was rejected.');
redirect_payload_assert((int)($decoded['wrapper_signature'] ?? 0) === 1234, 'signature 1234 was not preserved.');
redirect_payload_assert((string)($decoded['embedded_filename'] ?? '') === 'Sample.so', 'embedded filename was not preserved.');
redirect_payload_assert(hash_equals($legacyOutput, (string)$decoded['data']), 'signature-1234 payload bytes are incorrect.');
redirect_payload_assert(!catalog_redirect_archive_has_package_tag((string)$decoded['data']), 'non-package fixture unexpectedly has package magic.');

$legacyPath = tempnam(sys_get_temp_dir(), 'redirect-payload-');
redirect_payload_assert(is_string($legacyPath), 'could not allocate legacy fixture path.');
file_put_contents($legacyPath, $legacy);
try {
    $result = (new CatalogRedirectArchiveProcessor(['max_redirect_output_bytes' => 1024 * 1024]))
        ->decompressToTemp($legacyPath, 'renamed.so.uz', null, false);
    redirect_payload_assert((string)$result['filename'] === 'Sample.so', 'embedded legacy output name was not used.');
    redirect_payload_assert((int)$result['bytes'] === strlen($legacyOutput), 'legacy output size is incorrect.');
    redirect_payload_assert($result['is_unreal_package'] === false, 'legacy ELF payload was marked as an Unreal package.');
    redirect_payload_assert(hash_equals($legacyOutput, (string)file_get_contents((string)$result['path'])), 'legacy temporary output differs.');
    @unlink((string)$result['path']);
} finally {
    @unlink($legacyPath);
}

/* Exact UE2 UZ2 records can contain text/support files rather than packages. */
$textOutput = "Language=English\nObject=(Name=Example)\n";
$compressed = gzcompress($textOutput, 9);
redirect_payload_assert(is_string($compressed), 'could not prepare UZ2 text fixture.');
$uz2 = pack('V2', strlen($compressed), strlen($textOutput)) . $compressed;
$decodedUz2 = catalog_redirect_archive_decode_payload($uz2, 'uz2', 1024 * 1024);
redirect_payload_assert(is_array($decodedUz2), 'non-package UZ2 record stream was rejected.');
redirect_payload_assert((string)$decodedUz2['decoder'] === 'epic-uz2-zlib', 'UZ2 text used the wrong decoder.');
redirect_payload_assert(hash_equals($textOutput, (string)$decodedUz2['data']), 'UZ2 text output differs.');

/* The shared processor uses the same streamed UZ2 decoder as background imports. */
$packageOutput = "\xC1\x83\x2A\x9E" . str_repeat('Shared processor UZ2 package.', 2000);
$packageWrapper = '';
foreach (str_split($packageOutput, CATALOG_EPIC_UZ2_BLOCK_BYTES) as $block) {
    $member = gzcompress($block, 9);
    redirect_payload_assert(is_string($member), 'could not prepare package UZ2 member.');
    $packageWrapper .= pack('V2', strlen($member), strlen($block)) . $member;
}
$packagePath = tempnam(sys_get_temp_dir(), 'redirect-processor-');
redirect_payload_assert(is_string($packagePath), 'could not allocate processor fixture path.');
file_put_contents($packagePath, $packageWrapper);
try {
    $result = (new CatalogRedirectArchiveProcessor(['max_redirect_output_bytes' => 8 * 1024 * 1024]))
        ->decompressToTemp($packagePath, 'Shared.utx.uz2', null, true);
    redirect_payload_assert((string)$result['decoder'] === 'epic-uz2-zlib-stream', 'shared processor did not use the restored streamed UZ2 decoder.');
    redirect_payload_assert((string)$result['filename'] === 'Shared.utx', 'shared processor output filename is incorrect.');
    redirect_payload_assert(hash_equals($packageOutput, (string)file_get_contents((string)$result['path'])), 'shared processor UZ2 output differs.');
    @unlink((string)$result['path']);
} finally {
    @unlink($packagePath);
}

/* Equal declared sizes do not permit a non-zlib stored block in Epic UZ2. */
$storedPackage = "\xC1\x83\x2A\x9E" . hash('sha256', 'not an Epic zlib member', true);
$storedUz2 = pack('V2', strlen($storedPackage), strlen($storedPackage)) . $storedPackage;
redirect_payload_assert(
    catalog_redirect_archive_decode_payload($storedUz2, 'uz2', 1024 * 1024) === null,
    'non-zlib equal-size UZ2 record was accepted.'
);

/* A declared-size plus concatenated-zlib wrapper is not Epic UZ2. */
$nonEpicMembers = '';
foreach (str_split($packageOutput, CATALOG_EPIC_UZ2_BLOCK_BYTES) as $block) {
    $member = gzcompress($block, 9);
    redirect_payload_assert(is_string($member), 'could not prepare non-Epic member.');
    $nonEpicMembers .= $member;
}
$nonEpicUz2 = pack('V', strlen($packageOutput)) . $nonEpicMembers;
redirect_payload_assert(
    catalog_redirect_archive_decode_payload($nonEpicUz2, 'uz2', 8 * 1024 * 1024) === null,
    'non-Epic UZ2 fallback wrapper was accepted.'
);

echo "catalog_redirect_payload_test: OK\n";
