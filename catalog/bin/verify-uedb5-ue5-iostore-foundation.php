<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreCodec;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;

$failures = [];
$checks = [];
$check = static function (bool $condition, string $name) use (&$failures, &$checks): void {
    $checks[$name] = $condition;
    if (!$condition) {
        $failures[] = $name;
    }
};
$u64le = static function (string $hex): string {
    $raw = hex2bin(str_pad(strtoupper($hex), 16, '0', STR_PAD_LEFT));
    if (!is_string($raw) || strlen($raw) !== 8) {
        throw new RuntimeException('Invalid uint64 test value.');
    }
    return strrev($raw);
};
$u40be = static function (int $value): string {
    $bytes = '';
    for ($shift = 32; $shift >= 0; $shift -= 8) {
        $bytes .= chr(($value >> $shift) & 0xff);
    }
    return $bytes;
};
$u40le = static function (int $value): string {
    $bytes = '';
    for ($shift = 0; $shift <= 32; $shift += 8) {
        $bytes .= chr(($value >> $shift) & 0xff);
    }
    return $bytes;
};
$u24le = static fn(int $value): string =>
    chr($value & 0xff) . chr(($value >> 8) & 0xff) . chr(($value >> 16) & 0xff);
$arrayU64 = static function (array $ids) use ($u64le): string {
    $out = pack('V', count($ids));
    foreach ($ids as $id) {
        $out .= $u64le((string)$id);
    }
    return $out;
};
$arrayBytes = static fn(string $bytes): string => pack('V', strlen($bytes)) . $bytes;
$nameBatch = static function (array $names): string {
    if ($names === []) {
        return pack('V', 0);
    }
    $headers = '';
    $strings = '';
    foreach ($names as $name) {
        $length = strlen($name);
        $headers .= chr(($length >> 8) & 0x7f) . chr($length & 0xff);
        $strings .= $name;
    }
    return pack('V2', count($names), strlen($strings))
        . str_repeat("\0", 8)
        . str_repeat("\0", count($names) * 8)
        . $headers
        . $strings;
};
$mappedName = static fn(int $index, int $number = 0): string =>
    pack('V2', 0x40000000 | $index, $number);

$p1 = '0123456789ABCDEF';
$p2 = 'FEDCBA9876543210';
$containerId = '1111222233334444';

$storeToc = '';
$storeData = '';
$storeTocSize = 32;
$appendView = static function (array $rawItems, int $elementSize) use (&$storeToc, &$storeData, $storeTocSize): void {
    $count = count($rawItems);
    $offset = $count > 0 ? ($storeTocSize - strlen($storeToc) + strlen($storeData)) : 0;
    $storeToc .= pack('V2', $count, $offset);
    foreach ($rawItems as $raw) {
        if (strlen($raw) !== $elementSize) {
            throw new RuntimeException('Store-entry fixture element size mismatch.');
        }
        $storeData .= $raw;
    }
};
$appendView([$u64le($p2)], 8);
$appendView([strrev(hex2bin('0102030405060708'))], 8);
$appendView([], 8);
$appendView([], 8);
$storeEntries = $storeToc . $storeData;

$optionalToc = '';
$optionalData = '';
$optionalTocSize = 16;
$appendOptional = static function (array $rawItems, int $elementSize) use (&$optionalToc, &$optionalData, $optionalTocSize): void {
    $count = count($rawItems);
    $offset = $count > 0 ? ($optionalTocSize - strlen($optionalToc) + strlen($optionalData)) : 0;
    $optionalToc .= pack('V2', $count, $offset);
    foreach ($rawItems as $raw) {
        if (strlen($raw) !== $elementSize) {
            throw new RuntimeException('Optional store-entry fixture element size mismatch.');
        }
        $optionalData .= $raw;
    }
};
$appendOptional([$u64le($p2)], 8);
$appendOptional([], 8);
$optionalStoreEntries = $optionalToc . $optionalData;

$redirectNames = ['/Game/Old', '/Game/Localized'];
$containerPrefix = pack('V2', Uedb5IoStoreContainerHeaderReader::SIGNATURE, 5)
    . $u64le($containerId)
    . $arrayU64([$p1, $p2])
    . $arrayBytes($storeEntries)
    . $arrayU64([$p1])
    . $arrayBytes($optionalStoreEntries)
    . $nameBatch($redirectNames)
    . pack('V', 1)
    . $u64le('AAAABBBBCCCCDDDD')
    . $mappedName(1)
    . pack('V', 1)
    . $u64le('9999AAAABBBBCCCC')
    . $u64le($p1)
    . $mappedName(0);

$softViews = pack('V2', 1, 16) . pack('V2', 0, 0) . pack('V', 0);
$softPayload = pack('V', 1) . $arrayU64([$p2]) . $arrayBytes($softViews);
$softOffset = strlen($containerPrefix) + 16;
$containerHeader = $containerPrefix
    . pack('V2', $softOffset, 0)
    . pack('V2', strlen($softPayload), 0)
    . $softPayload;

$packageBytes = "ZEN-PACKAGE-FIXTURE\0" . str_repeat('P', 91);
$optionalPackageBytes = "ZEN-OPTIONAL-FIXTURE\0" . str_repeat('O', 53);
$logical = $containerHeader . $packageBytes . $optionalPackageBytes;
$blockSize = 64;
$blocks = str_split($logical, $blockSize);
$physical = '';
$blockRows = [];
foreach ($blocks as $index => $block) {
    $compressed = gzcompress($block, 6);
    if (!is_string($compressed)) {
        throw new RuntimeException('Could not build zlib fixture block.');
    }
    $blockRows[] = [
        'offset' => strlen($physical),
        'compressed' => strlen($compressed),
        'uncompressed' => strlen($block),
        'method' => 1,
    ];
    $physical .= $compressed;
}

$chunkId = static function (string $id, int $type, int $chunkIndex = 0) use ($u64le): string {
    return $u64le($id) . pack('n', $chunkIndex) . "\0" . chr($type);
};
$chunks = [
    ['id' => $chunkId($containerId, 6), 'offset' => 0, 'length' => strlen($containerHeader)],
    ['id' => $chunkId($p1, 1, 0), 'offset' => strlen($containerHeader), 'length' => strlen($packageBytes)],
    ['id' => $chunkId($p1, 1, 1), 'offset' => strlen($containerHeader) + strlen($packageBytes), 'length' => strlen($optionalPackageBytes)],
];

$header = Uedb5IoStoreTocReader::TOC_MAGIC
    . chr(8) . "\0" . pack('v', 0)
    . pack('V', 144)
    . pack('V', count($chunks))
    . pack('V', count($blockRows))
    . pack('V', 12)
    . pack('V', 1)
    . pack('V', 32)
    . pack('V', $blockSize)
    . pack('V', 0)
    . pack('V', 1)
    . $u64le($containerId)
    . str_repeat("\0", 16)
    . chr(Uedb5IoStoreTocReader::CONTAINER_FLAG_COMPRESSED)
    . "\0" . pack('v', 0)
    . pack('V', 0)
    . $u64le('FFFFFFFFFFFFFFFF')
    . pack('V2', 0, 0)
    . str_repeat("\0", 40);
$check(strlen($header) === 144, 'fixture_toc_header_is_144_bytes');

$utoc = $header;
foreach ($chunks as $chunk) {
    $utoc .= $chunk['id'];
}
foreach ($chunks as $chunk) {
    $utoc .= $u40be($chunk['offset']) . $u40be($chunk['length']);
}
foreach ($blockRows as $block) {
    $utoc .= $u40le($block['offset'])
        . $u24le($block['compressed'])
        . $u24le($block['uncompressed'])
        . chr($block['method']);
}
$utoc .= str_pad('Zlib', 32, "\0");
foreach ($chunks as $_) {
    $utoc .= str_repeat("\0", 20) . chr(1) . str_repeat("\0", 3);
}

$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb_iostore_' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0775, true) && !is_dir($temp)) {
    throw new RuntimeException('Could not create IoStore verification directory.');
}
$base = $temp . DIRECTORY_SEPARATOR . 'fixture';
file_put_contents($base . '.utoc', $utoc);
file_put_contents($base . '.ucas', $physical);

try {
    $toc = new Uedb5IoStoreTocReader($base . '.utoc');
    $check($toc->header()['version'] === 8, 'toc_version_8_parsed');
    $check($toc->header()['container_id'] === $containerId, 'toc_container_id_is_lossless_u64');
    $check($toc->compressionMethods() === ['None', 'Zlib'], 'toc_compression_method_table_parsed');
    $check(count($toc->chunks()) === 3, 'toc_chunk_table_count_matches');
    $check($toc->chunks()[1]['package_id'] === $p1, 'toc_package_id_is_lossless_u64');
    $check($toc->readChunk(0) === $containerHeader, 'ucas_multiblock_zlib_container_chunk_reconstructed');
    $check($toc->readChunk(1) === $packageBytes, 'ucas_midblock_package_chunk_reconstructed');
    $check($toc->readChunk(2) === $optionalPackageBytes, 'ucas_optional_segment_chunk_reconstructed');

    $containerChunkIndexes = $toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_CONTAINER_HEADER);
    $check($containerChunkIndexes === [0], 'container_header_chunk_type_selected');
    $check($toc->findPackageChunk($p1) === 1, 'package_chunk_selected_by_fpackageid');
    $check($toc->findPackageChunk($p1, Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA, 0) === 1, 'package_main_chunk_selected_by_chunk_index');
    $check($toc->findPackageChunk($p1, Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA, 1) === 2, 'package_optional_chunk_selected_by_chunk_index');

    $parsed = Uedb5IoStoreContainerHeaderReader::parse($toc->readChunk(0));
    $check($parsed['container_id'] === $containerId, 'container_header_id_parsed');
    $check($parsed['package_ids'] === [$p1, $p2], 'container_package_ids_preserve_order');
    $check(
        $parsed['store_entries'][0]['imported_package_ids'] === [$p2],
        'file_package_store_imported_packages_relative_view_parsed'
    );
    $check(
        $parsed['store_entries'][0]['shader_map_hashes'] === ['0102030405060708'],
        'file_package_store_shader_hash_relative_view_parsed'
    );
    $check(
        $parsed['optional_segment_store_entries'][0]['imported_package_ids'] === [$p2],
        'optional_segment_imported_packages_parsed'
    );
    $check(
        $parsed['localized_packages'][0]['source_package_name']['text'] === '/Game/Localized',
        'localized_package_mapped_name_parsed'
    );
    $check(
        $parsed['package_redirects'][0]['source_package_name']['text'] === '/Game/Old'
        && $parsed['package_redirects'][0]['target_package_id'] === $p1,
        'package_redirect_source_and_target_parsed'
    );
    $check(
        $parsed['soft_package_references']['package_references'][0][0]['package_id'] === $p2
        && $parsed['soft_package_references']['package_references'][1] === [],
        'soft_package_reference_relative_views_parsed'
    );
    $check(
        $parsed['soft_package_references']['serial_info']['offset'] === $softOffset
        && $parsed['soft_package_references']['serial_info']['size'] === strlen($softPayload),
        'soft_package_reference_serial_info_parsed'
    );

    $localizedMappedNameBytes = $mappedName(1);
    $localizedMappedNameOffset = strpos($containerHeader, $localizedMappedNameBytes);
    if ($localizedMappedNameOffset === false) {
        throw new RuntimeException('Could not locate localized FMappedName fixture.');
    }

    $badMappedTypeRejected = false;
    try {
        $bad = substr_replace($containerHeader, pack('V2', 1, 0), $localizedMappedNameOffset, 8);
        Uedb5IoStoreContainerHeaderReader::parse($bad);
    } catch (RuntimeException $exception) {
        $badMappedTypeRejected = str_contains($exception->getMessage(), 'container-name-map');
    }
    $check($badMappedTypeRejected, 'container_mapped_name_wrong_type_rejected');

    $badMappedIndexRejected = false;
    try {
        $bad = substr_replace($containerHeader, pack('V2', 0x40000063, 0), $localizedMappedNameOffset, 8);
        Uedb5IoStoreContainerHeaderReader::parse($bad);
    } catch (RuntimeException $exception) {
        $badMappedIndexRejected = str_contains($exception->getMessage(), 'outside RedirectsNameMap');
    }
    $check($badMappedIndexRejected, 'container_mapped_name_out_of_range_rejected');

    $badSoftBoolRejected = false;
    try {
        $bad = substr_replace($containerHeader, pack('V', 2), $softOffset, 4);
        Uedb5IoStoreContainerHeaderReader::parse($bad);
    } catch (RuntimeException $exception) {
        $badSoftBoolRejected = str_contains($exception->getMessage(), 'serialized bool');
    }
    $check($badSoftBoolRejected, 'soft_reference_invalid_serialized_bool_rejected');

    $oodleFailedClosed = false;
    try {
        (new Uedb5IoStoreCodec($temp . DIRECTORY_SEPARATOR . 'missing-oodle.dll'))->decode('Oodle', 'x', 1);
    } catch (RuntimeException $exception) {
        $oodleFailedClosed = str_contains($exception->getMessage(), 'Oodle');
    }
    $check($oodleFailedClosed, 'oodle_without_runtime_fails_closed');

    $aesKey = str_repeat('11', 32);
    $aesPlain = str_repeat('A', 64);
    $aesPhysical = openssl_encrypt(
        $aesPlain,
        'aes-256-ecb',
        hex2bin($aesKey),
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING
    );
    if (!is_string($aesPhysical)) {
        throw new RuntimeException('Could not create AES fixture.');
    }
    $aesHeader = Uedb5IoStoreTocReader::TOC_MAGIC
        . chr(8) . "\0" . pack('v', 0)
        . pack('V', 144) . pack('V', 1) . pack('V', 1) . pack('V', 12)
        . pack('V', 0) . pack('V', 32) . pack('V', 64) . pack('V', 0) . pack('V', 1)
        . $u64le($containerId) . str_repeat("\0", 16)
        . chr(Uedb5IoStoreTocReader::CONTAINER_FLAG_ENCRYPTED)
        . "\0" . pack('v', 0) . pack('V', 0)
        . $u64le('FFFFFFFFFFFFFFFF') . pack('V2', 0, 0) . str_repeat("\0", 40);
    $aesUtoc = $aesHeader
        . $chunkId($p2, 1)
        . $u40be(0) . $u40be(64)
        . $u40le(0) . $u24le(64) . $u24le(64) . chr(0)
        . str_repeat("\0", 20) . chr(0) . str_repeat("\0", 3);
    file_put_contents($temp . DIRECTORY_SEPARATOR . 'encrypted.utoc', $aesUtoc);
    file_put_contents($temp . DIRECTORY_SEPARATOR . 'encrypted.ucas', $aesPhysical);
    $encrypted = new Uedb5IoStoreTocReader($temp . DIRECTORY_SEPARATOR . 'encrypted.utoc', $aesKey);
    $check($encrypted->readChunk(0) === $aesPlain, 'ucas_aes256_ecb_block_decrypted');

    $badKeyRejected = false;
    try {
        (new Uedb5IoStoreTocReader($temp . DIRECTORY_SEPARATOR . 'encrypted.utoc'))->readChunk(0);
    } catch (RuntimeException $exception) {
        $badKeyRejected = str_contains($exception->getMessage(), 'AES key');
    }
    $check($badKeyRejected, 'encrypted_ucas_without_key_fails_closed');
} finally {
    foreach (glob($temp . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($temp);
}

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
