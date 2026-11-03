<?php
declare(strict_types=1);

/**
 * Scoped existing UE5 table/dependency contracts, one genuine current original.
 * No PRD MySQL, no SQL publishing, no new acceptance IDs.
 */
require_once dirname(__DIR__, 2) . '/catalog/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader as Toc;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader as Header;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder as Builder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Resolver;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Zen;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenSourcePolicy as Release;

$root = (string)($argv[1] ?? 'D:/UE5/Paks');
$utoc = rtrim($root, '/\\') . '/FactoryGame-WindowsServer.utoc';
$expectedSha = '84d30d56ccbd26cfeb1c0319b92d3274b13dff4406facad267aa582217c51696';
if (!is_file($utoc) || hash_file('sha256', $utoc) !== $expectedSha) {
    throw new RuntimeException('Wrong current Satisfactory original TOC; no source authority.');
}
$oodle = getenv('UNREALDB_OODLE_LIBRARY') ?: 'D:/php8.5/ext/oo2core_9_win64.dll';
$toc = new Toc($utoc, null, $oodle);
$index = 53;
$chunk = $toc->chunks()[$index] ?? null;
if (!is_array($chunk) || (int)$chunk['chunk_type'] !== Toc::CHUNK_TYPE_EXPORT_BUNDLE_DATA) {
    throw new RuntimeException('Current original Zen source fixture is not a package.');
}
$packageId = (string)$chunk['package_id'];
$selected = Builder::build($toc, $packageId, [
    'id' => 14000053, 'game_id' => 14,
    'package_name' => '', 'original_name' => 'current-original.zenpkg',
]);
if (Release::forGameId(14) !== Release::UE5_56
    || $selected['source_policy'] !== Release::UE5_56
    || Builder::SOURCE_POLICY !== Release::UE5_56
    || ((int)($selected['sections']['package_summary'][0]['summary']['cooked_header_size_or_reserved'] ?? 0)) !== 7367
) {
    throw new RuntimeException('Current Satisfactory source release was incorrectly dispatched.');
}
$hIndices = $toc->chunkIndexesByType(Toc::CHUNK_TYPE_CONTAINER_HEADER);
$h = Header::parse($toc->readChunk($hIndices[0]));
$store = null;
foreach ($h['store_entries'] as $entry) {
    if ($entry['package_id'] === $packageId) { $store = $entry; break; }
}
if (!is_array($store)) throw new RuntimeException('Original FPackageId not registered.');
$decoded = $toc->readChunk($index);
$latest = Zen::parse($decoded, $packageId, $store, Release::UE5_56);
$legacy = Zen::parse($decoded, $packageId, $store); // retained independent 5.8.3 direct path
if ($latest['source_policy'] !== Release::UE5_56 || $legacy['source_policy'] !== Release::UE5_58
    || $latest['summary'] !== $legacy['summary'] || $latest['name_map'] !== $legacy['name_map']
    || $latest['import_map'] !== $legacy['import_map'] || $latest['exports'] !== $legacy['exports']) {
    throw new RuntimeException('Source policy changed serialized table bytes on same original.');
}
$out = Resolver::resolve($selected, [], []);
if (count($out) < 1) throw new RuntimeException('No dependency outcomes from original current snapshot.');
foreach ($out as $row) {
    if ($row['source_policy'] !== Release::UE5_56
        || $row['resolver_policy'] !== Resolver::RESOLVER_POLICY_56
        || $row['outcome'] === 'missing') {
        throw new RuntimeException('Current UE5.6 dependency policy inaccurate or definitive missing fabricated.');
    }
}
$oldSnapshot = $selected;
$oldSnapshot['source_policy'] = Release::UE5_58;
$oldRows = Resolver::resolve($oldSnapshot, [], []);
if (count($oldRows) !== count($out)) throw new RuntimeException('Historical direct 5.8 resolver regressed.');
foreach ($oldRows as $row) {
    if ($row['source_policy'] !== Release::UE5_58 || $row['resolver_policy'] !== Resolver::RESOLVER_POLICY) {
        throw new RuntimeException('Legacy UE5.8.3 result lost source provenance.');
    }
}
$negatives = [];
$tryReject = static function (callable $call, string $id) use (&$negatives): void {
    try {
        $call();
    } catch (RuntimeException $e) {
        $negatives[$id] = $e->getMessage();
        return;
    }
    throw new RuntimeException('Source policy negative case unexpectedly accepted: ' . $id);
};
$tryReject(static fn() => Release::forGameId(999), 'unregistered_game');
$tryReject(static fn() => Zen::parse($decoded, $packageId, $store, 'ue5-unknown-policy'), 'unregistered_policy');
$tryReject(static fn() => Resolver::resolve($selected, [['snapshot'=>$oldSnapshot,'provider_id'=>1001]], []), 'mixed_release_provider');
$invalid = $selected;
$invalid['source_policy'] = 'ue5-unknown-policy';
$tryReject(static fn() => Resolver::resolve($invalid, [], []), 'unsupported_consumer');
$tryReject(static fn() => Builder::build($toc, $packageId, [
    'id'=>14000053,'game_id'=>999,'package_name'=>'',
]), 'builder_unknown_game');
if (hash_file('sha256', $utoc) !== $expectedSha) throw new RuntimeException('Original game source changed.');
echo json_encode([
    'result'=>'PASS',
    'existing_contract_cases'=>['ue5-tables','ue5-dependency-publish','ue5-iostore-ingest'],
    'source_policy_current'=>Release::UE5_56,
    'source_policy_existing_58'=>Release::UE5_58,
    'current_original_package_id'=>$packageId,
    'current_original_dependencies'=>count($out),
    'all_current_dependency_result_rows_correctly_source_labelled'=>true,
    'legacy_58_path_retained'=>true,
    'negative_policy_guards'=>array_keys($negatives),
    'current_original_archive_sha256'=>$expectedSha,
    'original_files_modified'=>false, 'PRD_SQL_writes'=>0, 'new_contract_ids'=>0
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
