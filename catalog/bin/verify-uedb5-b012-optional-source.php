<?php
declare(strict_types=1);
/** B012 source-derived optional export-segment contract (no editor executable). */
if(PHP_SAPI!=='cli'){exit(2);}
$root=(string)(getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'');
$epic=rtrim($root,'/\\').'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$source=is_file($epic)?(string)file_get_contents($epic):'';
$checks=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
$check('b012_epic_583_async_loading_hash_pinned',
 hash('sha256',$source)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$snippets=[
 'b012_editor_only_optional_segment'=>'#if WITH_EDITOR',
 'b012_optional_import_package_ids_separate'=>'OptionalSegmentImportedPackageIds.GetData()',
 'b012_editor_two_export_bundles'=>'TotalExportBundleCount = bHasOptionalSegment ? 2 : 1',
 'b012_runtime_one_export_bundle'=>'TotalExportBundleCount = 1;',
 'b012_import_recursion_optional_header'=>'ImportPackagesRecursiveInner(ThreadState, IoBatch, PackageStore, OptionalSegmentHeaderData.GetValue());',
 'b012_optional_chunk_index_one'=>'CreateIoChunkId(Desc.PackageIdToLoad.Value(), 1, EIoChunkType::ExportBundleData)',
 'b012_main_chunk_runtime_zero'=>'const uint16 ChunkIndex = 0;',
 'b012_main_chunk_editor_replacement'=>'const uint16 ChunkIndex = bRequestOptionalChunk ? 1 : 0;',
 'b012_main_optional_headers_separate'=>'Package->OptionalSegmentHeaderData.GetPtrOrNull()',
 'b012_two_exports_preinsert_editor'=>'Package->OptionalSegmentHeaderData->ExportMap',
 'b012_optional_export_archive_selects_header'=>'Ar.HeaderData = OptionalSegmentHeaderData.GetPtrOrNull();',
 'b012_optional_bundle_second'=>'bIsOptionalSegment = ProcessedExportBundlesCount == 1;',
 'b012_hash_source_first_main'=>'if (HeaderData.ExportMap[ExportIndex].PublicExportHash == ExportHash)',
 'b012_hash_optional_second'=>'if (OptionalSegmentHeaderDataPtr->ExportMap[ExportIndex].PublicExportHash == ExportHash)',
 'b012_hash_optional_global_index'=>'return HeaderData.ExportMap.Num() + ExportIndex;',
 'b012_optional_provider_release'=>'ImportStore.ReleaseImportedPackageReferences(Desc, OptionalSegmentHeaderData->ImportedPackageIds)',
];
foreach($snippets as $name=>$snippet){
 $check($name,$source!==''&&str_contains($source,$snippet));
}
// Verify source control-flow order in the same GetPublicExportIndex function,
// not mere symbol co-occurrence somewhere in AsyncLoading2.cpp.
$at=strpos($source,'int32 FAsyncPackage2::GetPublicExportIndex(');
$body=$at===false?'':substr($source,$at,2000);
$main=strpos($body,'HeaderData.ExportMap[ExportIndex].PublicExportHash');
$optional=strpos($body,'OptionalSegmentHeaderDataPtr->ExportMap[ExportIndex].PublicExportHash');
$check('b012_get_public_export_index_main_precedes_optional',
 $main!==false && $optional!==false && $main<$optional
 && strpos($body,'return ExportIndex;')<$optional);
$check('b012_optional_lookup_within_editor_preprocessor',
 $main!==false && $optional!==false &&
 strpos($body,'#if WITH_EDITOR')>$main && strpos($body,'#if WITH_EDITOR')<$optional);
$repo=dirname(__DIR__);
$builder=(string)file_get_contents($repo.'/src/Infrastructure/Metadata/Uedb5Ue5ZenIoStoreSnapshotBuilder.php');
$resolver=(string)file_get_contents($repo.'/src/Infrastructure/Metadata/Uedb5Ue5ZenDependencyResolver.php');
$reader=(string)file_get_contents($repo.'/src/Infrastructure/Metadata/Uedb5ZenPackageReader.php');
$check('b012_two_export_bundle_chunk_requests_in_builder',
 str_contains($builder,'CHUNK_TYPE_EXPORT_BUNDLE_DATA, 0')
 &&str_contains($builder,'CHUNK_TYPE_EXPORT_BUNDLE_DATA, 1'));
$check('b012_separate_optional_import_store_entry_in_builder',
 str_contains($builder,"'optional_segment_imported_package_ids'")
 &&str_contains($builder,'$optionalStoreEntry')
 &&str_contains($builder,'$optionalZen'));
$check('b012_both_segments_same_parser',
 substr_count($builder,'Uedb5ZenPackageReader::parse(')===2);
$check('b012_optional_imports_not_flattened_into_main',
 str_contains($builder,"'optional_segment' => \$optionalZen === null ? []")
 &&str_contains($builder,"'imports' => self::classifiedObjectIndexes((array)\$optionalZen['import_map'], true, false)")
 &&str_contains($builder,"'imports' => self::classifiedObjectIndexes((array)\$zen['import_map'], false, false)"));
$check('b012_resolver_editor_gate',
 str_contains($resolver,"'optional_segment_not_loaded_non_editor'")
 &&str_contains($resolver,"'optional_segment_editor_build_context_required'")
 &&str_contains($resolver,"'optional_segment_export_not_loaded_non_editor'"));
$check('b012_resolver_checks_optional_import_bundle_identity',
 str_contains($resolver,'Zen optional-segment dependency bundle object_index does not match the combined import map.'));
$check('b012_resolver_main_export_precedence_explicit',
 str_contains($resolver,'GetPublicExportIndex scans the')
 &&str_contains($resolver,'$mainMatches = array_values(array_filter($matches,'));
$check('b012_reader_uses_per_segment_store_ids',
 str_contains($reader,"\$storeEntry['imported_package_ids']"));
echo json_encode([
 'ok'=>$fail===[],'operation'=>'UE5-583-OPTIONAL-EXPORT-SEGMENT-001',
 'source_oracle'=>true,'engine_executed'=>false,
 'checks'=>$checks,'failures'=>$fail
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
