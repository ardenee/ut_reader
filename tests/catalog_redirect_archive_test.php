<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies catalog redirect archive behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

require_once __DIR__ . '/../catalog/lib/CatalogRedirectArchivePayload.php';

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

/* Epic UE2: repeated little-endian [compressed size, uncompressed size, zlib compress() payload] records. */
$wrapper = '';
foreach (str_split($package, $blockSize) as $block) {
    $compressed = gzcompress($block, 9);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not prepare compressed regression block.');
    }
    $wrapper .= pack('V2', strlen($compressed), strlen($block)) . $compressed;
}

$decoded = catalog_redirect_archive_decode_payload($wrapper, 'uz2', 32 * 1024 * 1024);
redirect_test_assert(is_array($decoded), 'Epic UZ2 wrapper was not decoded.');
redirect_test_assert(str_starts_with((string)$decoded['decoder'], 'epic-uz2-zlib-'), 'Epic UZ2 wrapper did not use zlib uncompress semantics.');
redirect_test_assert((int)$decoded['chunks'] === $blockCount, 'not every Epic compressed record was consumed.');
redirect_test_assert(strlen((string)$decoded['data']) === $expectedBytes, 'decoded byte count is incorrect.');
redirect_test_assert(hash_equals(hash('sha256', $package), hash('sha256', (string)$decoded['data'])), 'decoded package bytes differ from the source package.');
redirect_test_assert(catalog_redirect_archive_epic_uz2_payload(substr($wrapper, 0, -10), 32 * 1024 * 1024) === null, 'truncated Epic UZ2 wrapper was accepted.');

/* Epic invokes zlib even when compressed and uncompressed sizes happen to be equal. */
$equalPackage = "\xC1\x83\x2A\x9E" . str_repeat("\x9E", 9);
$equalCompressed = hex2bin('78da3bd8ac350f0e0033be079b');
redirect_test_assert(is_string($equalCompressed) && strlen($equalCompressed) === strlen($equalPackage), 'equal-size zlib fixture is invalid.');
$equalWrapper = pack('V2', strlen($equalCompressed), strlen($equalPackage)) . $equalCompressed;
$equalDecoded = catalog_redirect_archive_epic_uz2_payload($equalWrapper, 1024);
redirect_test_assert(is_array($equalDecoded), 'equal-size Epic zlib record was not decoded.');
redirect_test_assert(hash_equals($equalPackage, (string)$equalDecoded['data']), 'equal-size Epic zlib record was treated as stored data.');

$raw = gzdeflate($equalPackage, 9);
$gzip = gzencode($equalPackage, 9);
redirect_test_assert(is_string($raw) && is_string($gzip), 'Could not prepare non-zlib rejection fixtures.');
redirect_test_assert(
    catalog_redirect_archive_decode_payload(pack('V2', strlen($raw), strlen($equalPackage)) . $raw, 'uz2', 1024) === null,
    'raw-deflate payload was accepted as Epic UZ2.'
);
redirect_test_assert(
    catalog_redirect_archive_decode_payload(pack('V2', strlen($gzip), strlen($equalPackage)) . $gzip, 'uz2', 1024) === null,
    'gzip payload was accepted as Epic UZ2.'
);

$oversizedRecord = pack('V2', 1, CATALOG_EPIC_UZ2_BLOCK_BYTES + 1) . "\0";
redirect_test_assert(catalog_redirect_archive_epic_uz2_payload($oversizedRecord, 1024 * 1024) === null, 'oversized Epic UZ2 source block was accepted.');

$sourcePath = tempnam(sys_get_temp_dir(), 'ue_redirect_test_');
if ($sourcePath === false || file_put_contents($sourcePath, $wrapper) !== strlen($wrapper)) {
    throw new RuntimeException('Could not create redirect archive regression fixture.');
}

try {
    $result = catalog_redirect_archive_decompress_to_temp($sourcePath, 'outdoortxt.utx.uz2', 32 * 1024 * 1024);
    redirect_test_assert(str_starts_with((string)$result['decoder'], 'epic-uz2-zlib-'), 'profiled UZ2 import did not use Epic zlib semantics.');
    redirect_test_assert((int)$result['bytes'] === $expectedBytes, 'temporary output size is incorrect.');
    redirect_test_assert((int)$result['chunks'] === $blockCount, 'temporary output metadata has the wrong record count.');
    redirect_test_assert((string)$result['filename'] === 'outdoortxt.utx', 'redirect extension was not removed from the output filename.');
    redirect_test_assert(hash_equals(hash('sha256', $package), (string)hash_file('sha256', (string)$result['path'])), 'temporary output bytes are incorrect.');
    @unlink((string)$result['path']);
} finally {
    @unlink($sourcePath);
}

/* Epic UE1 UCC .uz: signature 1234, embedded filename, Huffman -> MTF -> BWT -> RLE decode. */
$legacyWrapper = base64_decode('0gQAAA9UZXN0TGVnYWN5LnV0eADpAQAAvwsWCwHSCJH/S4PmjJkyZMaICRubNWrz0hWYr89PHz57/w4ZKkRokKBAgP7+u2OnDp05cuLAeZuePHhvgkQVKPbAoQgQ14GjDx7LgNEGjd/ATJQgORUpUJxUgOD///+7YqUKlSlSokB54qQJkyVKkiA5YqQIkSFCggD54aMHz+OyRUsWrL8YMF64aMFi5b8dOnLguGGjBo0ZMrdAoQYMO2BQAoQFzE3b/vn///+3TVs2bNesVaM2TVo0aM+cNWO2TFkyZMeMFSM2/P///18/fvv05cN3z149evPkxYP3zl07duvUpUN3zlw5cuPEhQP3zVs37jdL9v8nLBiwX7568dqlKxeuW7Zq0f7//wfqk6dOnDZpyoTpkqVKlCZJigTpkaNGjBYpSoT4/y8WrFeuWrFapSoVqlOmSpEaJSqUAAD73nf0vrP33TW/////4//NX/2bvxH9/3X/f9Ff+Vf8bf6qv+av/1P/lD/9T/sT/4Q/+U/6g3/gz/1z/vw/78/8M/7sP+sP/UP+8D/sL/jvCvwR/9h/lD/uH/kv/Uf7W/7/mH/L366kEf7////L/2UB', true);
redirect_test_assert(is_string($legacyWrapper), 'legacy fixture could not be decoded from base64.');
$legacyDecoded = catalog_redirect_archive_decode_payload($legacyWrapper, 'uz', 1024 * 1024);
redirect_test_assert(is_array($legacyDecoded), 'Epic UZ wrapper was not decoded.');
redirect_test_assert((string)$legacyDecoded['decoder'] === 'epic-uz-huffman+mtf+bwt+rle', 'Epic UZ wrapper used the wrong codec chain.');
redirect_test_assert((int)$legacyDecoded['chunks'] === 1, 'legacy BWT block count is incorrect.');
redirect_test_assert(strlen((string)$legacyDecoded['data']) === 500, 'legacy output byte count is incorrect.');
redirect_test_assert(md5((string)$legacyDecoded['data']) === '839a18feb3ce047c8a06afda844ef6b1', 'legacy output bytes are incorrect.');
redirect_test_assert((string)$legacyDecoded['embedded_filename'] === 'TestLegacy.utx', 'legacy embedded filename was not read.');
redirect_test_assert(catalog_redirect_archive_decode_payload(substr($legacyWrapper, 0, -1), 'uz', 1024 * 1024) === null, 'truncated legacy wrapper was accepted.');

$legacyPath = tempnam(sys_get_temp_dir(), 'ue_legacy_redirect_test_');
if ($legacyPath === false || file_put_contents($legacyPath, $legacyWrapper) !== strlen($legacyWrapper)) {
    throw new RuntimeException('Could not create legacy redirect archive regression fixture.');
}
try {
    $legacyResult = catalog_redirect_archive_decompress_to_temp($legacyPath, 'renamed.utx.uz', 1024 * 1024);
    redirect_test_assert((int)$legacyResult['bytes'] === 500, 'legacy temporary output size is incorrect.');
    redirect_test_assert((string)$legacyResult['filename'] === 'TestLegacy.utx', 'legacy embedded output filename was not preserved.');
    redirect_test_assert((string)hash_file('md5', (string)$legacyResult['path']) === '839a18feb3ce047c8a06afda844ef6b1', 'legacy temporary output bytes are incorrect.');
    @unlink((string)$legacyResult['path']);
} finally {
    @unlink($legacyPath);
}

/* Historical UE1 NewVer .uz variant: signature 5678 plus an extra RLE codec before Huffman. */
$newVerWrapper = base64_decode('LhYAAA5UZXN0RXBpY1VaMy51APIAAAB/AVIa8LZvikFCOERTWeBdmpOOCT0eUnEomDfpWKhdu5dUgexGRfsgcjgB29tw1nL00j0AsDOM9oCBICP4C/IF/YJ9h3+3fiGQX6gv8zv6C/Pd9oX9bvtu/cLhv8zvBOIX6YtM+U79on3RvzO+M7+z2F/md84X94v3nf/dv1sA', true);
redirect_test_assert(is_string($newVerWrapper), 'legacy NewVer fixture could not be decoded from base64.');
$newVerDecoded = catalog_redirect_archive_decode_payload($newVerWrapper, 'uz', 1024 * 1024);
redirect_test_assert(is_array($newVerDecoded), 'legacy NewVer UZ wrapper was not decoded.');
redirect_test_assert((string)$newVerDecoded['decoder'] === 'legacy-uz-newver-huffman+rle+mtf+bwt+rle', 'legacy NewVer wrapper used the wrong codec chain.');
redirect_test_assert((int)$newVerDecoded['wrapper_signature'] === 5678, 'legacy NewVer signature was not preserved.');
redirect_test_assert(md5((string)$newVerDecoded['data']) === 'a966f9234c05a7ea3d134fb97d08cfad', 'legacy NewVer output bytes are incorrect.');
redirect_test_assert(catalog_redirect_archive_decode_payload($newVerWrapper, 'uz3', 1024 * 1024) === null, 'legacy NewVer .uz was misidentified as UE3 UZ3.');

/* Epic UE3 .uz3: tag 5678, total uncompressed size, then one zlib compress() stream. */
$uz3Package = "\xC1\x83\x2A\x9E" . substr(str_repeat($pattern, 128), 0, 4096 - 4);
$uz3Compressed = gzcompress($uz3Package, 9);
if (!is_string($uz3Compressed)) {
    throw new RuntimeException('Could not prepare Epic UZ3 zlib fixture.');
}
$uz3Wrapper = pack('V2', 5678, strlen($uz3Package)) . $uz3Compressed;
$uz3Decoded = catalog_redirect_archive_decode_payload($uz3Wrapper, 'uz3', 1024 * 1024);
redirect_test_assert(is_array($uz3Decoded), 'Epic UZ3 wrapper was not decoded.');
redirect_test_assert(str_starts_with((string)$uz3Decoded['decoder'], 'epic-uz3-zlib-'), 'Epic UZ3 wrapper did not use whole-file zlib.');
redirect_test_assert((int)$uz3Decoded['wrapper_signature'] === 5678, 'Epic UZ3 file tag was not preserved.');
redirect_test_assert((int)$uz3Decoded['chunks'] === 1, 'Epic UZ3 must be one compressed unit.');
redirect_test_assert(hash_equals($uz3Package, (string)$uz3Decoded['data']), 'Epic UZ3 output bytes are incorrect.');
redirect_test_assert(catalog_redirect_archive_decode_payload(substr($uz3Wrapper, 0, -1), 'uz3', 1024 * 1024) === null, 'truncated Epic UZ3 wrapper was accepted.');
redirect_test_assert(catalog_redirect_archive_decode_payload($uz3Wrapper, 'uz', 1024 * 1024) === null, 'Epic UZ3 was accepted as UE1 UZ.');

$uz3Raw = gzdeflate($uz3Package, 9);
$uz3Gzip = gzencode($uz3Package, 9);
redirect_test_assert(is_string($uz3Raw) && is_string($uz3Gzip), 'Could not prepare UZ3 non-zlib rejection fixtures.');
redirect_test_assert(catalog_redirect_archive_decode_payload(pack('V2', 5678, strlen($uz3Package)) . $uz3Raw, 'uz3', 1024 * 1024) === null, 'raw-deflate payload was accepted as Epic UZ3.');
redirect_test_assert(catalog_redirect_archive_decode_payload(pack('V2', 5678, strlen($uz3Package)) . $uz3Gzip, 'uz3', 1024 * 1024) === null, 'gzip payload was accepted as Epic UZ3.');

$uz3Path = tempnam(sys_get_temp_dir(), 'ue3_redirect_test_');
if ($uz3Path === false || file_put_contents($uz3Path, $uz3Wrapper) !== strlen($uz3Wrapper)) {
    throw new RuntimeException('Could not create Epic UZ3 regression fixture.');
}
try {
    $uz3Result = catalog_redirect_archive_decompress_to_temp($uz3Path, 'renamed.u.uz3', 1024 * 1024);
    redirect_test_assert(str_starts_with((string)$uz3Result['decoder'], 'epic-uz3-zlib-'), 'profiled UZ3 import did not use Epic whole-file zlib.');
    redirect_test_assert((int)$uz3Result['bytes'] === strlen($uz3Package), 'Epic UZ3 temporary output size is incorrect.');
    redirect_test_assert((string)$uz3Result['filename'] === 'renamed.u', 'Epic UZ3 output must use the wrapper name without .uz3.');
    redirect_test_assert(hash_equals(hash('sha256', $uz3Package), (string)hash_file('sha256', (string)$uz3Result['path'])), 'Epic UZ3 temporary output bytes are incorrect.');
    @unlink((string)$uz3Result['path']);
} finally {
    @unlink($uz3Path);
}

echo "catalog_redirect_archive_test: OK\n";
