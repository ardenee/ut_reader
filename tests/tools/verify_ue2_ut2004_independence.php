<?php
declare(strict_types=1);
/** Verify UE2-owned UT2004 legacy wire compatibility without loading UE1 reader. */
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2LegacyCompatibilityPackageReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UE1TableSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UE2LegacyCompatibilityTableSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ClassicDependencyResolver;
$path='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Textures/Female2Skins.utx';
if (!is_file($path)) throw new RuntimeException('Genuine legacy-format fixture absent');
$compat=new CatalogUE2LegacyCompatibilityPackageReader($path);
if (class_exists(CatalogUE1PackageReader::class,false)) throw new RuntimeException('UE2 compatibility loading imported the UE1 parser!');
$loaded=array_filter(get_included_files(), static fn(string $f):bool=>str_contains(str_replace('\\','/',$f),'/CatalogUE1PackageReader.php'));
if ($loaded) throw new RuntimeException('UE1 reader file included by UE2: '.implode(',',$loaded));
if ($compat->validatePackage() !== []) throw new RuntimeException('UE2-owned legacy reader rejected genuine 1998 fixture');
$file=['id'=>1,'game_id'=>5,'package_name'=>'Female2Skins','original_name'=>basename($path)];
$profile=['label'=>'UT2004 UE1 compatibility','policy'=>'ue1-ut2004-legacy-texture-profile-compatible','schema_prefix'=>'ue1.ut2004.compat'];
$ue2=Uedb5UE2LegacyCompatibilityTableSnapshotBuilder::build($compat,$file,$profile);
// UE1 wire format is not UE1 runtime ownership. Original UT2004 v3186
// Core/Src/UnLinker.cpp uses UE2 ULinkerLoad::VerifyImport for v60+ inputs.
$baseline=Uedb5ClassicDependencyResolver::resolve($ue2,[],[]);
if(count($baseline)!==count($ue2['sections']['imports'])) {
    throw new RuntimeException('UE2-owned compatibility dependency baseline lost an import.');
}
foreach($baseline as $row) {
    if((string)($row['status']??'')==='missing'
        || str_starts_with((string)($row['reason']??''),'ue1_')) {
        throw new RuntimeException('UT2004 legacy dependencies were attributed to UE1 or invented a missing provider.');
    }
}
if($baseline===[] || !in_array('ue2_verify_import_source_implementation_unavailable',
    array_column($baseline,'reason'),true)) {
    throw new RuntimeException('UT2004 legacy package did not retain UE2 source-unknown VerifyImport status.');
}
$incorrect=[
    'wrong_game'=>static function(array &$s):void {$s['file']['game_id']=3;},
    'wrong_policy'=>static function(array &$s):void {$s['source_policy']='ue1-ut99-v430-public-source-partial';},
    'wrong_version'=>static function(array &$s):void {$s['sections']['summary'][0]['package_version']=100;},
    'wrong_family'=>static function(array &$s):void {$s['package_family']='ue4-classic-package';},
];
foreach($incorrect as $case=>$alter) {
    $s=$ue2;$alter($s);
    try {
        Uedb5ClassicDependencyResolver::resolve($s,[],[]);
        throw new RuntimeException('Wrong UT2004 source scope accepted: '.$case);
    } catch(RuntimeException $e) {
        if(str_starts_with($e->getMessage(),'Wrong UT2004 source scope accepted:'))throw $e;
    }
}
if(class_exists(CatalogUE1PackageReader::class,false)) {
    throw new RuntimeException('UE2 dependency resolver loaded UE1 package reader.');
}
$ue1=Uedb5UE1TableSnapshotBuilder::build(new CatalogUE1PackageReader($path),$file,$profile);
if ($ue2 !== $ue1) throw new RuntimeException('UT2004 compatible UEDB5 snapshot changed during engine independence');
echo json_encode(['ut2004_legacy_format_sample'=>'original Unreal 1998 v61 .utx, format-equivalent only',
 'bytes_sha256'=>hash_file('sha256',$path),'parser_engines_independent'=>true,
 'ue1_module_loaded_when_ue2_compat_parsed'=>false,
 'ue2_compat_snapshot_sha256'=>hash('sha256',serialize($ue2)),
 'all_generated_fields_identical'=>true,
 'UE2_owned_classic_resolver_baseline_rows'=>count($baseline),
 'UE2_source_unavailable_reason_preserved'=>true,
 'rejected_wrong_owner_policy_version_or_family'=>count($incorrect),
 'ut2004_profile_selection_database_tested'=>false],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
