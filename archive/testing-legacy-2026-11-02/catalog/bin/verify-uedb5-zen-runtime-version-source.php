<?php
declare(strict_types=1);
/**
 * B009/B010 source-only audit of Epic UE5.8.3 runtime Zen header behavior.
 * Verifies source control flow and archived version hand-off; does NOT
 * execute an Epic binary or claim real cooked differential parity.
 */
if (PHP_SAPI !== 'cli') { exit(2); }
$root = trim((string)(getenv('UNREALDB_EPIC_SOURCE_ROOT') ?: ''));
if ($root === '') {
    echo json_encode(['ok'=>false,'reason'=>'UNREALDB_EPIC_SOURCE_ROOT required']),PHP_EOL;
    exit(2);
}
$files=[
    'header'=>['Engine/Source/Runtime/CoreUObject/Private/Serialization/ZenPackageHeader.cpp',
        '954117a45b9a802d3f289b30f2a0e191a7bb82cdcb107a22fef134a73172995b'],
    'loader'=>['Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp',
        '43ce5d7838d25f5b1a39f3acd5db71e29973c5a0f5f28240ef7602d309d616a9'],
    'version'=>['Engine/Source/Runtime/Core/Private/UObject/ObjectVersion.cpp',
        'b7d6bcc25f5ade60b0b64a588beeb99a3e1b8c6d8768b5f96443219d3d391026'],
    'enum'=>['Engine/Source/Runtime/Core/Public/UObject/ObjectVersion.h',
        '4c1ce99232b6bcd538654d67d0fb3e05fc55eea982b249883a3159303d020c87'],
    'custom'=>['Engine/Source/Runtime/Core/Private/Serialization/CustomVersion.cpp',
        '08827e1157efcb956568d34e85dd44a7a586cacc2f925cfa77733f4bbec42597'],
];
$text=[];$checks=[];$failures=[];
$check=static function(string $name,bool $valid) use (&$checks,&$failures):void {
    $checks[$name]=$valid;
    if(!$valid){$failures[]=$name;}
};
foreach($files as $name=>[$rel,$sha]){
    $path=rtrim(str_replace('\\','/',$root),'/').'/'.$rel;
    $data=is_file($path)?file_get_contents($path):false;
    $check('pinned_'.$name.'_source_sha',is_string($data)&&hash_equals($sha,hash('sha256',$data)));
    $text[$name]=is_string($data)?$data:'';
}
$has=static fn(string $name,string $needle):bool=>str_contains($text[$name]??'',$needle);
$pattern=static fn(string $name,string $expression):bool=>preg_match($expression,$text[$name]??'')===1;
$check('runtime_calls_makeview',
    $has('loader','FZenPackageHeader::MakeView(Package->SerializationState.IoRequest.GetResultOrDie().GetView())'));
$check('nonzero_flag_reads_versioning',
    $pattern('header','/if\s*\(PackageHeader\.PackageSummary->bHasVersioningInfo\)\s*\{\s*PackageHeader\.VersioningInfo\.Emplace\(\);\s*PackageHeaderDataReader\s*<<\s*PackageHeader\.VersioningInfo\.GetValue\(\);/s'));
$check('source_versioning_field_order',
    $pattern('loader','/Ar\s*<<\s*VersioningInfo\.ZenVersion;\s*Ar\s*<<\s*VersioningInfo\.PackageVersion;\s*Ar\s*<<\s*VersioningInfo\.LicenseeVersion;\s*VersioningInfo\.CustomVersions\.Serialize\(Ar\);/s'));
$check('package_versions_serialize_separately',
    $pattern('version','/Ar\s*<<\s*Version\.FileVersionUE4;\s*Ar\s*<<\s*Version\.FileVersionUE5;/s'));
$check('custom_versions_optimized_array',
    $pattern('custom','/case\s+ECustomVersionSerializationFormat::Optimized:\s*\{\s*Slot\s*<<\s*Versions;/s'));
$check('cell_map_conditional',
    $has('header','LocalVersioningInfo == nullptr || LocalVersioningInfo->PackageVersion >= EUnrealEngineObjectUE5Version::VERSE_CELLS'));
$check('bulk_map_conditional',
    $has('header','LocalVersioningInfo == nullptr || LocalVersioningInfo->PackageVersion >= EUnrealEngineObjectUE5Version::DATA_RESOURCES'));
$check('old_cell_offsets_use_bundle_boundary',
    $has('header','CellOffsets.CellImportMapOffset = PackageSummary->ExportBundleEntriesOffset;')
    &&$has('header','CellOffsets.CellExportMapOffset = PackageSummary->ExportBundleEntriesOffset;'));
$check('bulk_pad_read_does_not_require_zero',
    $pattern('header','/uint8\s+PadBytes\[sizeof\(uint64\)\]\s*=\s*\{\};\s*PackageHeaderDataReader\.Serialize\(PadBytes,\s*BulkDataPad\);/s'));
$check('versioned_header_applied_to_linker',
    $pattern('loader','/if\s*\(const FZenPackageVersioningInfo\* VersioningInfo = Package->HeaderData\.VersioningInfo\.GetPtrOrNull\(\)\)\s*\{\s*Package->LinkerRoot->SetLinkerPackageVersion\(VersioningInfo->PackageVersion\);\s*Package->LinkerRoot->SetLinkerLicenseeVersion\(VersioningInfo->LicenseeVersion\);\s*Package->LinkerRoot->SetLinkerCustomVersions\(VersioningInfo->CustomVersions\);/s'));
$check('no_version_uses_engine_defaults',
    $pattern('loader','/else\s*\{\s*Package->LinkerRoot->SetLinkerPackageVersion\(GPackageFileUEVersion\);\s*Package->LinkerRoot->SetLinkerLicenseeVersion\(GPackageFileLicenseeUEVersion\);/s'));
$check('export_archive_inherits_effective_versions',
    $pattern('loader','/Ar\.SetUEVer\(LinkerRoot->GetLinkerPackageVersion\(\)\);\s*Ar\.SetLicenseeUEVer\(LinkerRoot->GetLinkerLicenseeVersion\(\)\);/s')
    &&$has('loader','Ar.SetCustomVersions(LinkerRoot->GetLinkerCustomVersions());'));
$check('ue5_comparison_uses_ue5_version',
    $has('enum','return FileVersionUE5 >= (int32)Version;'));
$check('default_versions_are_compiled_engine_version',
    $has('version','GPackageFileUEVersion(VER_LATEST_ENGINE_UE4, EUnrealEngineObjectUE5Version::AUTOMATIC_VERSION)')
    &&$has('version','GPackageFileLicenseeUEVersion = VER_LATEST_ENGINE_LICENSEEUE4;'));
echo json_encode([
    'ok'=>$failures===[],
    'read_only'=>true,
    'editor_or_engine_binary_used'=>false,
    'evidence_type'=>'pinned_source_branch_verification_not_executed_epic_differential',
    'source_baseline'=>'UE5.8.3',
    'checks'=>$checks,
    'failures'=>$failures
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
