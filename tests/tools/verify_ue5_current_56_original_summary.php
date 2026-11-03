<?php
declare(strict_types=1);
/**
 * Scoped UE5 "tables" regression: one original Satisfactory 1.2 / UE5.6.1
 * ExportBundleData header, source field with different 5.6/5.8 names.
 * No production connection, no source modification, no invented package data.
 */
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader as Toc;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader as StoreHeader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;

$root = rtrim((string)($argv[1] ?? 'D:/UE5/Paks'), '/\\');
$utoc = $root . '/FactoryGame-WindowsServer.utoc';
if (!is_file($utoc) || !is_file($root . '/FactoryGame-WindowsServer.ucas')) {
    throw new RuntimeException('Original current Satisfactory IoStore source unavailable: ' . $root);
}
$expectedSha = '84d30d56ccbd26cfeb1c0319b92d3274b13dff4406facad267aa582217c51696';
$beforeSha = hash_file('sha256', $utoc);
if ($beforeSha !== $expectedSha) throw new RuntimeException('Wrong original TOC revision; cannot use source-pinned proof.');
$codec = (string)(getenv('UNREALDB_OODLE_LIBRARY') ?: 'D:/php8.5/ext/oo2core_9_win64.dll');
$toc = new Toc($utoc, null, $codec);
$selectedIndex = 53;
$chunk = $toc->chunks()[$selectedIndex];
if ((int)$chunk['chunk_type'] !== Toc::CHUNK_TYPE_EXPORT_BUNDLE_DATA) {
    throw new RuntimeException('Selected original fixture is not a Zen package.');
}
$containerIndices = $toc->chunkIndexesByType(Toc::CHUNK_TYPE_CONTAINER_HEADER);
if (count($containerIndices) !== 1) throw new RuntimeException('ContainerHeader missing');
$header = StoreHeader::parse($toc->readChunk($containerIndices[0]));
$store = null;
foreach ($header['store_entries'] as $entry) {
    if ((string)$entry['package_id'] === (string)$chunk['package_id']) { $store = $entry; break; }
}
if (!is_array($store)) throw new RuntimeException('Original FPackageId not in real PackageStore');
$bytes = $toc->readChunk($selectedIndex);
$zen = Zen::parse($bytes, (string)$chunk['package_id'], $store);
$raw = unpack('V', substr($bytes, 20, 4));
$expectedWord = (int)($raw[1] ?? -1);
$summary = (array)$zen['summary'];
if ($expectedWord !== 7367 ||
    !isset($summary['cooked_header_size_or_reserved']) ||
    (int)$summary['cooked_header_size_or_reserved'] !== $expectedWord ||
    array_key_exists('unused_cooked_header_size',$summary) ||
    $zen['versioning_info'] !== null) {
    throw new RuntimeException('UnrealDB Zen summary did not retain the original UE5.6 source word');
}
if (hash_file('sha256',$utoc) !== $beforeSha) throw new RuntimeException('Original TOC changed');
echo json_encode([
    'result'=>'PASS', 'existing_contract'=>'ue5-tables',
    'actual_current_original_toc_sha256'=>$expectedSha,
    'original_package_id'=>$chunk['package_id'],
    'source_position'=>20, 'source_value'=>$expectedWord,
    'versioning_serialized'=>false,
    'ue5_56_field'=>'CookedHeaderSize','ue5_58_field'=>'_Unused',
    'version_neutral_raw_value_retained'=>true,
    'PRD_SQL_writes'=>0,'original_file_mutations'=>0,
    'new_acceptance_contract_ids'=>0
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
