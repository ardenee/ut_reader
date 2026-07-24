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

/* Exact UE2 UZ2 records can contain text/support files rather than packages. */
$textOutput = "Language=English\nObject=(Name=Example)\n";
$compressed = gzcompress($textOutput, 9);
redirect_payload_assert(is_string($compressed), 'could not prepare UZ2 text fixture.');
$uz2 = pack('V2', strlen($compressed), strlen($textOutput)) . $compressed;
$decodedUz2 = catalog_redirect_archive_decode_payload($uz2, 'uz2', 1024 * 1024);
redirect_payload_assert(is_array($decodedUz2), 'non-package UZ2 record stream was rejected.');
redirect_payload_assert((string)$decodedUz2['decoder'] === 'epic-uz2-zlib', 'UZ2 text used the wrong decoder.');
redirect_payload_assert(hash_equals($textOutput, (string)$decodedUz2['data']), 'UZ2 text output differs.');

/* A valid zlib member wins even when its compressed and source sizes are equal. */
$equalPackage = "\xC1\x83\x2A\x9E" . str_repeat("\x9E", 9);
$equalCompressed = hex2bin('78da3bd8ac350f0e0033be079b');
redirect_payload_assert(is_string($equalCompressed), 'equal-size zlib fixture could not be prepared.');
redirect_payload_assert(strlen($equalCompressed) === strlen($equalPackage), 'equal-size zlib fixture lengths differ.');
$equalUz2 = pack('V2', strlen($equalCompressed), strlen($equalPackage)) . $equalCompressed;
$equalDecoded = catalog_redirect_archive_decode_payload($equalUz2, 'uz2', 1024 * 1024);
redirect_payload_assert(is_array($equalDecoded), 'equal-size exact zlib record was rejected.');
redirect_payload_assert((string)$equalDecoded['decoder'] === 'epic-uz2-zlib', 'equal-size valid zlib was treated as stored.');
redirect_payload_assert(hash_equals($equalPackage, (string)$equalDecoded['data']), 'equal-size zlib output differs.');

/* If exact zlib fails and both sizes match, the record is a verbatim Epic block. */
$storedPackage = "\xC1\x83\x2A\x9E" . hash('sha256', 'Epic UZ2 stored record regression', true);
$storedUz2 = pack('V2', strlen($storedPackage), strlen($storedPackage)) . $storedPackage;
$storedDecoded = catalog_redirect_archive_decode_payload($storedUz2, 'uz2', 1024 * 1024);
redirect_payload_assert(is_array($storedDecoded), 'equal-size verbatim UZ2 record was rejected.');
redirect_payload_assert((string)$storedDecoded['decoder'] === 'epic-uz2-stored', 'verbatim UZ2 record used the wrong decoder.');
redirect_payload_assert(hash_equals($storedPackage, (string)$storedDecoded['data']), 'verbatim UZ2 output differs.');

$storedPath = tempnam(sys_get_temp_dir(), 'redirect-uz2-stored-');
redirect_payload_assert(is_string($storedPath), 'could not allocate stored UZ2 fixture path.');
file_put_contents($storedPath, $storedUz2);
try {
    $result = catalog_epic_redirect_decompress_to_temp($storedPath, 'Stored.utx.uz2', 1024 * 1024, true);
    redirect_payload_assert(str_contains((string)$result['decoder'], 'stored'), 'streamed UZ2 path did not use stored-record handling.');
    redirect_payload_assert(hash_equals($storedPackage, (string)file_get_contents((string)$result['path'])), 'streamed stored-record output differs.');
    @unlink((string)$result['path']);
} finally {
    @unlink($storedPath);
}

/* UE3 UZ3 is 5678 + total uncompressed size + one exact zlib stream. */
$uz3Output = "\xC1\x83\x2A\x9E" . str_repeat('Epic UE3 UZ3 package payload.', 100);
$uz3Compressed = gzcompress($uz3Output, 9);
redirect_payload_assert(is_string($uz3Compressed), 'could not prepare UZ3 fixture.');
$uz3 = pack('V2', 5678, strlen($uz3Output)) . $uz3Compressed;
$decodedUz3 = catalog_redirect_archive_decode_payload($uz3, 'uz3', 1024 * 1024);
redirect_payload_assert(is_array($decodedUz3), 'exact UZ3 wrapper was rejected.');
redirect_payload_assert((string)$decodedUz3['decoder'] === 'epic-uz3-zlib', 'UZ3 used the wrong decoder.');
redirect_payload_assert((int)($decodedUz3['wrapper_signature'] ?? 0) === 5678, 'UZ3 signature was not preserved.');
redirect_payload_assert(hash_equals($uz3Output, (string)$decodedUz3['data']), 'UZ3 output differs.');

$uz3Path = tempnam(sys_get_temp_dir(), 'redirect-uz3-');
redirect_payload_assert(is_string($uz3Path), 'could not allocate UZ3 fixture path.');
file_put_contents($uz3Path, $uz3);
try {
    $result = catalog_redirect_archive_decompress_payload_to_temp($uz3Path, 'Example.upk.uz3', 1024 * 1024);
    redirect_payload_assert((string)$result['filename'] === 'Example.upk', 'UZ3 output name is incorrect.');
    redirect_payload_assert((string)$result['decoder'] === 'epic-uz3-zlib', 'UZ3 temporary path used the wrong decoder.');
    redirect_payload_assert(hash_equals($uz3Output, (string)file_get_contents((string)$result['path'])), 'UZ3 temporary output differs.');
    @unlink((string)$result['path']);
} finally {
    @unlink($uz3Path);
}

/* A declared-size plus concatenated-zlib wrapper is not Epic UZ2 and is rejected. */
$nonEpicMembers = '';
foreach (str_split($uz3Output, 32768) as $block) {
    $member = gzcompress($block, 9);
    redirect_payload_assert(is_string($member), 'could not prepare non-Epic member.');
    $nonEpicMembers .= $member;
}
$nonEpicUz2 = pack('V', strlen($uz3Output)) . $nonEpicMembers;
redirect_payload_assert(
    catalog_redirect_archive_decode_payload($nonEpicUz2, 'uz2', 1024 * 1024) === null,
    'non-Epic UZ2 fallback wrapper was accepted.'
);

echo "catalog_redirect_payload_test: OK\n";
