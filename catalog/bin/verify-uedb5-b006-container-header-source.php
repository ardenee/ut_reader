<?php
declare(strict_types=1);
/**
 * D007: independent IoContainerHeader format-source prerequisite to the
 * existing B006 FilePackageStore mount operation. This is not mount-order
 * arbitration and not a new operation. Does not claim a cooked v6 fixture.
 */
if(PHP_SAPI!=='cli')exit(2);
$root=dirname(__DIR__,2);$srcRoot=rtrim((string)getenv('UNREALDB_EPIC_SOURCE_ROOT'),"/\\");
$cppRel='Engine/Source/Runtime/Core/Private/IO/IoContainerHeader.cpp';
$hRel='Engine/Source/Runtime/Core/Public/IO/IoContainerHeader.h';
$reader='catalog/src/Infrastructure/Metadata/Uedb5IoStoreContainerHeaderReader.php';
$snapshot='catalog/src/Infrastructure/Metadata/Uedb5Ue5ZenIoStoreSnapshotBuilder.php';
$checks=[];$failures=[];$reports=[];
$check=static function(string $name,bool $valid)use(&$checks,&$failures):void{
 $checks[$name]=$valid;if(!$valid)$failures[]=$name;
};
$cppPath=$srcRoot.'/'.$cppRel;$headerPath=$srcRoot.'/'.$hRel;
$cpp=is_file($cppPath)?(string)file_get_contents($cppPath):'';
$header=is_file($headerPath)?(string)file_get_contents($headerPath):'';
$check('d007_epic_container_header_cpp_pinned',
 hash('sha256',$cpp)==='fcc949dc6f95befd0e621294b422773610896a325fd457294e640b9d9e12a590');
$check('d007_epic_container_header_public_types_pinned',
 hash('sha256',$header)==='03d325d4c496e3d75b0c91da4256be58162565496444b2942fbcad116aac3abd');
$check('d007_reader_implementation_hash_guard',
 is_file($root.'/'.$reader)&&hash_file('sha256',$root.'/'.$reader)==='1cc24525c53ed5e50817f981cf0ae2dc1607ba0b54ba2afb9b301bd27361278a');
$check('d007_snapshot_implementation_hash_guard',
 is_file($root.'/'.$snapshot)&&hash_file('sha256',$root.'/'.$snapshot)==='dccb03419fb8e64e143f49d452f02538b2bb98a03d4316e1c4de0d43950ccdc9');
$check('d007_epic_no_export_info_lower_bound_not_upper_latest',
 str_contains($cpp,'Version < EIoContainerHeaderVersion::NoExportInfo')
 &&!str_contains($cpp,'Version > EIoContainerHeaderVersion::Latest'));
$check('d007_epic_version4_serializes_inline_soft_reference',
 str_contains($cpp,'if (Version == EIoContainerHeaderVersion::SoftPackageReferences)')
 &&str_contains($cpp,'Ar << ContainerHeader.SoftPackageReferences;'));
$check('d007_epic_version5_or_later_uses_offset_layout',
 str_contains($cpp,'else if (Version >= EIoContainerHeaderVersion::SoftPackageReferencesOffset)')
 &&str_contains($cpp,'Ar << ContainerHeader.SoftPackageReferencesSerialInfo;'));
$check('d007_epic_soft_size_positive_only_advances_current_stream',
 str_contains($cpp,'if (ContainerHeader.SoftPackageReferencesSerialInfo.Size > 0)')
 &&str_contains($cpp,'const int64 EndPos = Ar.Tell() + ContainerHeader.SoftPackageReferencesSerialInfo.Size;')
 &&str_contains($cpp,'if (EndPos > Ar.TotalSize())')
 &&str_contains($cpp,'Ar.Seek(EndPos);'));
$check('d007_epic_read_path_has_no_offset_equals_current_cursor_gate',
 !str_contains($cpp,'if (ContainerHeader.SoftPackageReferencesSerialInfo.Offset != Ar.Tell())')
 &&str_contains($cpp,'if (Ar.IsLoading())'));
$check('d007_epic_no_strict_termination_or_trailing_bytes_rejection',
 !str_contains($cpp,'Ar.Tell() != Ar.TotalSize()')
 &&str_contains($cpp,'return Ar;'));
$check('d007_epic_serialize_core_arrays_and_redirect_names',
 str_contains($cpp,'Ar << ContainerHeader.PackageIds;')
 &&str_contains($cpp,'Ar << ContainerHeader.StoreEntries;')
 &&str_contains($cpp,'Ar << ContainerHeader.OptionalSegmentPackageIds;')
 &&str_contains($cpp,'Ar << ContainerHeader.OptionalSegmentStoreEntries;')
 &&str_contains($cpp,'ContainerHeader.RedirectsNameMap = LoadNameBatch(Ar);')
 &&str_contains($cpp,'Ar << ContainerHeader.LocalizedPackages;')
 &&str_contains($cpp,'Ar << ContainerHeader.PackageRedirects;'));
$check('d007_header_default_negative_serial_info_layout',
 str_contains($header,'FIoContainerHeaderSerialInfo')
 &&(str_contains($header,'Size = -1')||str_contains($header,'Size=-1')));
$read=(string)file_get_contents($root.'/'.$reader);
$snap=(string)file_get_contents($root.'/'.$snapshot);
$check('d007_unrealdb_offset_is_provenance_not_seek',
 str_contains($read,'$serialOffset = $reader->i64le();')
 &&str_contains($read,'$serialSize = $reader->i64le();')
 &&str_contains($read,'if ($serialSize > 0)')
 &&!str_contains($read,'$serialOffset !== $reader->position()'));
$check('d007_snapshot_requires_one_chunk_and_matching_container_id',
 str_contains($snap,'Uedb5IoStoreContainerHeaderReader::parse($toc->readChunk($containerHeaderIndex))')
 &&str_contains($snap,'count($containerHeaderIndexes) !== 1')
 &&str_contains($snap,"(string)\$containerHeader['container_id'] !== (string)\$tocHeader['container_id']"));
$scan=['parser'=>[],'snapshot'=>[],'source_files'=>0];
$sourceDirectory=$root.'/catalog/src';
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDirectory,
 FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
 $scan['source_files']++;
 $relative=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
 $text=(string)file_get_contents($file->getPathname());
 if(str_contains($text,'Uedb5IoStoreContainerHeaderReader::parse('))$scan['parser'][]=$relative;
 if(str_contains($text,'Uedb5Ue5ZenIoStoreSnapshotBuilder::build('))$scan['snapshot'][]=$relative;
}
sort($scan['parser']);sort($scan['snapshot']);
$check('d007_exhaustive_production_header_parser_caller_inventory',
 $scan['source_files']>=500
 &&$scan['parser']===[$snapshot]);
$check('d007_exhaustive_snapshot_entry_routes_from_import_repair_query_and_name_index',
 $scan['snapshot']===[
  'catalog/src/Infrastructure/Import/CatalogZenIoStorePackagePublisher.php',
  'catalog/src/Infrastructure/Maintenance/CatalogIoStoreMemberReimportService.php',
  'catalog/src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php',
  'catalog/src/Infrastructure/Storage/CatalogIoStorePackageNameIndexer.php'
 ]);
$fixture=$root.'/catalog/bin/verify-uedb5-ue5-iostore-foundation.php';
$check('d007_existing_full_byte_fixture_driver_fingerprint_guard',
 is_file($fixture)&&hash_file('sha256',$fixture)==='2a5f92ecc8ed3c373cecc77bad897cd778906cdb935da5a273d4c3b2186e55b9');
$out=[];$exit=127;
exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($fixture).' 2>&1',$out,$exit);
$report=json_decode(implode("\n",$out),true);
$reports['foundation']=is_array($report)?$report:[];
$check('d007_existing_full_iostore_fixture_passes',
 $exit===0 &&($report['ok']??false)===true
 &&count((array)($report['checks']??[]))===34);
foreach([
 'd007_fixture_future_version_6_uses_source_layout'=>'container_header_future_version_uses_ue5_source_layout',
 'd007_fixture_below_minimum_version_rejected'=>'container_header_version_below_epic_minimum_rejected',
 'd007_fixture_serial_offset_not_stream_cursor_gate'=>'container_header_serialized_offset_does_not_override_stream_position',
 'd007_fixture_negative_default_serial_size'=>'container_header_default_negative_serial_size_accepted',
 'd007_fixture_trailing_extension_accepted'=>'container_header_unconsumed_extension_bytes_are_not_rejected',
 'd007_fixture_container_entry_mapping'=>'file_package_store_imported_packages_relative_view_parsed',
 'd007_fixture_invalid_soft_bool_rejected'=>'soft_reference_invalid_serialized_bool_rejected',
] as $name=>$key){
 $check($name,($report['checks'][$key]??false)===true);
}
echo json_encode([
 'ok'=>$failures===[], 'original_operation'=>'UE5-583-FILE-STORE-001',
 'subscope'=>'D007 IoContainerHeader source serialization prerequisite; not mounted-order policy',
 'actual_future_version_6_cooked_fixture_executed'=>false,
 'actual_live_file_backend_mounted'=>false,
 'retained_real_v5_container_previous_read_only_evidence'=>'catalog/bin/verify-uedb5-satisfactory-header-readonly-isolated.php',
 'checks'=>$checks, 'failures'=>$failures, 'source_caller_inventory'=>$scan,
 'foundation_fixture_checks'=>count((array)($report['checks']??[]))
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
