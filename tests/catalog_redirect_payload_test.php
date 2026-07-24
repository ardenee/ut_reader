<?php
declare(strict_types=1);

require_once __DIR__ . '/../catalog/lib/CatalogRedirectArchivePayload.php';

function redirect_payload_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Redirect payload test failed: ' . $message);
    }
}

/*
 * Small signature-1234 fixture generated with Epic's UE1 codec chain:
 * RLE -> BWT -> MTF -> Huffman. Its payload is an ELF-style non-package file.
 */
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
    $result = catalog_redirect_archive_decompress_payload_to_temp($legacyPath, 'renamed.so.uz', 1024 * 1024);
    redirect_payload_assert((string)$result['filename'] === 'Sample.so', 'embedded legacy output name was not used.');
    redirect_payload_assert((int)$result['bytes'] === strlen($legacyOutput), 'legacy output size is incorrect.');
    redirect_payload_assert($result['is_unreal_package'] === false, 'legacy ELF payload was marked as an Unreal package.');
    redirect_payload_assert(hash_equals($legacyOutput, (string)file_get_contents((string)$result['path'])), 'legacy temporary output differs.');
    @unlink((string)$result['path']);
} finally {
    @unlink($legacyPath);
}

/* Exact UE2 UZ2 records can also contain text/support files rather than packages. */
$textOutput = "Language=English\nObject=(Name=Example)\n";
$compressed = gzcompress($textOutput, 9);
redirect_payload_assert(is_string($compressed), 'could not prepare UZ2 text fixture.');
$uz2 = pack('V2', strlen($compressed), strlen($textOutput)) . $compressed;
$decodedUz2 = catalog_redirect_archive_decode_payload($uz2, 'uz2', 1024 * 1024);
redirect_payload_assert(is_array($decodedUz2), 'non-package UZ2 record stream was rejected.');
redirect_payload_assert((string)$decodedUz2['decoder'] === 'epic-uz2-zlib', 'UZ2 text used the wrong decoder.');
redirect_payload_assert(hash_equals($textOutput, (string)$decodedUz2['data']), 'UZ2 text output differs.');

/*
 * Some redirect archives use a declared total size followed by concatenated zlib
 * members. This is valid package data but is not the strict repeated Epic UZ2
 * record layout. Bucket uploads must fall back to the compatibility decoders.
 */
$compatPackage = "\xC1\x83\x2A\x9E" . str_repeat('Compatible UZ2 package payload.', 3000);
$compatMembers = '';
foreach (str_split($compatPackage, 32768) as $block) {
    $member = gzcompress($block, 9);
    redirect_payload_assert(is_string($member), 'could not prepare compatibility UZ2 member.');
    $compatMembers .= $member;
}
$compatUz2 = pack('V', strlen($compatPackage)) . $compatMembers;
redirect_payload_assert(catalog_redirect_archive_epic_uz2_payload($compatUz2, 2 * 1024 * 1024) === null, 'compatibility fixture unexpectedly matched the strict Epic layout.');
$decodedCompat = catalog_redirect_archive_decode_payload($compatUz2, 'uz2', 2 * 1024 * 1024);
redirect_payload_assert(is_array($decodedCompat), 'compatible non-record UZ2 wrapper was rejected.');
redirect_payload_assert(str_starts_with((string)$decodedCompat['decoder'], 'concatenated-'), 'compatible UZ2 wrapper did not use the fallback decoder.');
redirect_payload_assert(hash_equals($compatPackage, (string)$decodedCompat['data']), 'compatible UZ2 package bytes differ.');

$compatPath = tempnam(sys_get_temp_dir(), 'redirect-compat-');
redirect_payload_assert(is_string($compatPath), 'could not allocate compatibility fixture path.');
file_put_contents($compatPath, $compatUz2);
try {
    $result = catalog_redirect_archive_decompress_payload_to_temp($compatPath, 'Compatible.utx.uz2', 2 * 1024 * 1024);
    redirect_payload_assert($result['is_unreal_package'] === true, 'compatible UZ2 package was not identified as an Unreal package.');
    redirect_payload_assert((string)$result['filename'] === 'Compatible.utx', 'compatible UZ2 output name is incorrect.');
    redirect_payload_assert(hash_equals(hash('sha256', $compatPackage), (string)hash_file('sha256', (string)$result['path'])), 'compatible UZ2 temporary output differs.');
    @unlink((string)$result['path']);
} finally {
    @unlink($compatPath);
}

echo "catalog_redirect_payload_test: OK\n";
