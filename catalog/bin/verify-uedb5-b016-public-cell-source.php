<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(2);
$root=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),'/\\');
$path=$root.'/Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$src=is_file($path)?(string)file_get_contents($path):'';
$checks=[];$fails=[];
$check=static function(string $n,bool $ok)use(&$checks,&$fails):void{
 $checks[$n]=$ok;if(!$ok)$fails[]=$n;
};
$check('b016_epic_async_loading_hash_pinned',
 hash('sha256',$src)==='43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9');
$between=static function(string $a,string $b)use($src):string{
 $start=strpos($src,$a);
 if($start===false)return '';
 $end=strpos($src,$b,$start+strlen($a));
 return $end===false?'':substr($src,$start,$end-$start);
};
$lookup=$between('int32 FAsyncPackage2::GetPublicCellExportIndexAsLocalIndex(','void FAsyncPackage2::ConditionalCreateCellExport(');
$check('b016_header_search_main_cell_map_only',
 str_contains($lookup,'HeaderData.CellExportMap[CellExportIndex].PublicExportHash')
 &&!str_contains($lookup,'OptionalSegmentHeaderData'));
$check('b016_cell_local_export_offsets_ordinary_exports',
 str_contains($lookup,'return CellExportIndex + HeaderData.ExportMap.Num();'));
$check('b016_cell_header_first_match_then_not_found',
 str_contains($lookup,'return -1;'));
$import=$between('void FAsyncPackage2::ConditionalCreateCellImport(','void FAsyncPackage2::ConditionalSerializeCellImport(');
$check('b016_cell_import_offsets_ordinary_imports',
 str_contains($import,'LocalImportIndex - Header.ImportMap.Num()'));
$check('b016_cell_fallback_uses_separate_cell_index',
 str_contains($import,'FindOrGetImportCell('));
$serialize=$between('void FAsyncPackage2::ConditionalSerializeCellImport(','FString FAsyncPackage2::GetNameFromPackageObjectIndex(');
$check('b016_cell_serialize_import_uses_combined_index',
 str_contains($serialize,'LocalImportIndex - Header.ImportMap.Num()'));
$check('b016_cell_serialize_uses_cell_export_path',
 str_contains($serialize,'ConditionalSerializeCellExport('));
$store=$between('Verse::VCell* FindPublicExportCell(const FPublicExportKey& Key) const','Verse::VCell* FindScriptImportCell(');
$check('b016_verse_registry_uses_package_id_and_hash',
 str_contains($store,'PackageCells.FindAndApply(Key.GetPackageId()')
 &&str_contains($store,'GetPublicCellExport(Key.GetExportHash())'));
$cellStore=$between('void StorePublicCellExport(uint64 ExportHash, Verse::VCell* Cell)','Verse::VCell* GetPublicCellExport(uint64 ExportHash) const');
$check('b016_verse_registry_updates_same_hash',
 str_contains($cellStore,'PublicExportMap.Add(ExportHash, Cell)'));
$check('b016_cell_import_uses_public_export_key',
 str_contains($src,'FindPublicExportCell(FPublicExportKey::FromPackageImport('));
$check('b016_verse_registry_separate_from_uobject_registry',
 str_contains($src,'FindPublicExportObjectUnchecked(const FPublicExportKey& Key)'));
$create=$between('void FAsyncPackage2::EventDrivenCreateCellExport(','void FAsyncPackage2::EventDrivenSerializeCellExport(');
$check('b016_cell_store_requires_importable_package_and_hash',
 str_contains($create,'if (Desc.bCanBeImported && Export.PublicExportHash)')
 &&str_contains($create,'ImportStore.StoreGlobalCell('));
$check('b016_cell_creation_verse_vm_build_guarded',
 str_contains($create,'#if WITH_VERSE_VM || defined(__INTELLISENSE__)'));
$repo=dirname(__DIR__);
$impl=(string)file_get_contents($repo.'/src/Infrastructure/Metadata/Uedb5Ue5ZenDependencyResolver.php');
$check('b016_unrealdb_separate_cell_export_hash_map',
 str_contains($impl,"'cell_exports_by_hash' =>"));
$check('b016_optional_cell_registration_not_assumed',
 str_contains($impl,'optional_segment_public_cell_runtime_registration_required'));
$check('b016_cell_first_header_candidate_explicitly_not_live',
 str_contains($impl,'source_header_first_cell_export')
 &&str_contains($impl,'runtime_lookup_authoritative'));
$check('b016_unrealdb_combined_cell_index_provenance',
 str_contains($impl,'main_export_count')
 &&str_contains($impl,'local_export_index')
 &&str_contains($impl,'source_local_import_index'));
$references=[];$files=0;
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($repo.'/src',FilesystemIterator::SKIP_DOTS)) as $f){
 if(!$f->isFile()||strtolower($f->getExtension())!=='php')continue;
 ++$files;
 $count=substr_count((string)file_get_contents($f->getPathname()),'Uedb5Ue5ZenDependencyResolver::resolve(');
 if($count)$references[$f->getFilename()]=$count;
}
ksort($references);
$check('b016_exact_two_direct_production_consumers',
 $files>=500 &&$references===[
  'PdoUedb5PhysicalProviderSelector.php'=>1,'Uedb5DependencyRebuilder.php'=>1
 ]);
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'failures'=>$fails,
 'production_php_files_scanned'=>$files,'direct_callers'=>$references,
 'live_verse_vm_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
