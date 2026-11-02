<?php
/**
 * Strict UE4.27 P06 reader/semantic scope closure driver.
 * Does not claim publisher, DB, image pixel or unmounted archive certification.
 */
declare(strict_types=1);
$partial=__DIR__.'/verify-ue427-p06-partial-acceptance.php';
$commands=escapeshellarg(PHP_BINARY).' '.escapeshellarg($partial);
$lines=[];$exit=0;exec($commands.' 2>&1',$lines,$exit);
$json=json_decode(implode("\n",$lines),true);
$issues=[];
if($exit!==0 || !is_array($json)||($json['ok']??false)!==true
 || ($json['passed']??-1)!==17||($json['total']??-1)!==17){
 $issues[]=['partial_acceptance_failed'=>$exit,'log'=>array_slice($lines,0,25)];
}
$repo=dirname(__DIR__,2);
$files=[
 'reader'=>$repo.'/UE4/UnrealPackageReader.php',
 'native'=>$repo.'/catalog/compliance/ue427-native-oracle/Source/UE427Oracle/Private/UE427OracleP06AssetSemantic.h',
 'epic_summary'=>'L:/Source/Engine/UE4/UE 4.27.2/Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp',
 'epic_main'=>'L:/Source/Engine/UE4/UE 4.27.2/Engine/Source/Runtime/AssetRegistry/Private/PackageReader.cpp',
 'epic_tag'=>'L:/Source/Engine/UE4/UE 4.27.2/Engine/Source/Runtime/CoreUObject/Private/AssetRegistry/AssetDataTagMap.cpp',
];
$source=[];
foreach($files as $key=>$file){
 $txt=@file_get_contents($file);
 if(!is_string($txt))$issues[]=['missing_pinned_source'=>$key,'path'=>$file];
 else $source[$key]=$txt;
}
$contracts=[
 ['reader','public function getAssetRegistryAssetData(string $packageName)'],
 ['reader','$version < 337'],
 ['reader','$version < 520'],
 ['reader','& ~0x00000001'],
 ['reader','assetDataTagDisplayValue'],
 ['native','FAssetData Asset('],
 ['native','UE427_NATIVE_P06_ASSET_COUNT'],
 ['native','UE427MakeP06SemanticFixtures'],
 ['epic_summary','Sum.PackageFlags &= ~PKG_NewlyCreated'],
 ['epic_main','ReadPackageDataMain(FArchive&'],
 ['epic_main','VER_UE4_PUBLIC_WORLDS'],
 ['epic_main','VER_UE4_NON_OUTER_PACKAGE_IMPORT'],
 ['epic_tag','LocalizeIfComplexString'],
];
foreach($contracts as [$key,$fragment]){
 if(!isset($source[$key])||!str_contains($source[$key],$fragment))
  $issues[]=['missing_source_contract'=>[$key,$fragment]];
}
$replays=is_array($json)?$json['results']??[]:[];
foreach(['registry_native_installed','registry_native_cooked','registry_dependency_native',
 'thumbnails_native','thumbnail_payload_native','world_chunk_native',
 'semantic_assetdata_installed','semantic_assetdata_fixtures',
 'semantic_assetdata_negatives','production_caller_inventory','p05_full_regression',
 'ut4_v5_regression'] as $name){
 if(($replays[$name]['ok']??false)!==true)$issues[]=['missing_gate'=>$name];
}
$ok=$issues===[];
echo json_encode(['ok'=>$ok,'verified_operations'=>17,'primary_operation'=>'U427-P06',
 'scope'=>'UE4.27 classic reader AssetRegistry, dependency bits, thumbnails, WorldTile/ChunkID serialization and FAssetData semantics',
 'source_contract_assertions'=>count($contracts),
 'native_reference'=>'UE4Editor-Cmd.exe UE4.27.2 plus actual FAssetData/FObjectThumbnail/TBitArray constructors',
 'handoff_open'=>[
  'P08/P09 durable archive sidecar ingestion and V5 projection lifecycle',
  'P10 application search validator/consumer linkage',
  'Non-English localization and typed AssetBundleData outside tested input corpus',
  'Unconsumed thumbnail decompression/editor-autoload and historical world tiles'
 ],'limitations'=>'Private Epic PackageReader::ReadPackageDataMain predicate flow is source-mirrored rather than directly invoked; controlled branch fixtures use compiled Epic constructors.',
 'issues'=>$issues],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
