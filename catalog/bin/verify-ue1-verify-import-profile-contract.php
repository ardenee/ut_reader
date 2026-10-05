#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe1VerifyImportProjectionResolver as Ue1;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok)$failures[]=$name;};

$providerImports=[
 ['import_index'=>0,'object_name'=>'Engine','outer_index'=>0],
 ['import_index'=>1,'object_name'=>'Texture','outer_index'=>-1],
 ['import_index'=>2,'object_name'=>'UnrealShare','outer_index'=>0],
 ['import_index'=>3,'object_name'=>'Texture','outer_index'=>-3],
 ['import_index'=>4,'object_name'=>'LodMesh','outer_index'=>-1],
 ['import_index'=>5,'object_name'=>'Mesh','outer_index'=>-1],
 ['import_index'=>6,'object_name'=>'OtherClass','outer_index'=>-1],
];

$consumer=[
 ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'TestPkg','outer_index'=>0],
 ['import_index'=>1,'class_package'=>'Engine','class_name'=>'Texture','object_name'=>'Parent','outer_index'=>-1],
 ['import_index'=>2,'class_package'=>'Engine','class_name'=>'Texture','object_name'=>'Child','outer_index'=>-2],
];
$exports=[
 ['export_index'=>0,'class_index'=>-2,'object_name'=>'Parent','outer_index'=>0,'object_flags'=>4],
 ['export_index'=>1,'class_index'=>-2,'object_name'=>'Child','outer_index'=>1,'object_flags'=>4],
];

$ut99=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UT99_V1400,$consumer,$providerImports,$exports,'TestPkg',68,68);
$check('ut99_exact_identity_parent',($ut99[1]['export_index']??null)===0&&($ut99[2]['export_index']??null)===1);

$dupes=$exports;
$dupes[]=['export_index'=>3,'class_index'=>-2,'object_name'=>'Parent','outer_index'=>0,'object_flags'=>4];
$ut99=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UT99_V1400,$consumer,$providerImports,$dupes,'TestPkg',68,68);
$unreal=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UNREAL_V120,$consumer,$providerImports,$dupes,'TestPkg',54,54);
$check('ut99_hash_traversal_descending',($ut99[1]['export_index']??null)===3);
$check('unreal_v120_export_scan_ascending',($unreal[1]['export_index']??null)===0);

$classHackConsumer=[
 ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'TestPkg','outer_index'=>0],
 ['import_index'=>1,'class_package'=>'UnrealI','class_name'=>'Texture','object_name'=>'LegacyTex','outer_index'=>-1],
];
$classHackExports=[
 ['export_index'=>0,'class_index'=>-4,'object_name'=>'LegacyTex','outer_index'=>0,'object_flags'=>4],
];
$ut99=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UT99_V1400,$classHackConsumer,$providerImports,$classHackExports,'UnrealShare',68,68);
$unreal=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UNREAL_V120,$classHackConsumer,$providerImports,$classHackExports,'UnrealShare',54,54);
$check('ut99_unreali_unrealshare_class_hack',($ut99[1]['export_index']??null)===0&&($ut99[1]['reason']??'')==='ut99_unreali_unrealshare_class_package');
$check('unreal_v120_has_no_ut99_class_hack',($unreal[1]['status']??'')==='runtime_only');

$meshConsumer=[
 ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'TestPkg','outer_index'=>0],
 ['import_index'=>1,'class_package'=>'Engine','class_name'=>'Mesh','object_name'=>'LegacyModel','outer_index'=>-1],
];
$meshExports=[['export_index'=>0,'class_index'=>-5,'object_name'=>'LegacyModel','outer_index'=>0,'object_flags'=>4]];
$ut99=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UT99_V1400,$meshConsumer,$providerImports,$meshExports,'TestPkg',68,68);
$unreal=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UNREAL_V120,$meshConsumer,$providerImports,$meshExports,'TestPkg',54,54);
$check('ut99_mesh_to_lodmesh',($ut99[1]['export_index']??null)===0&&($ut99[1]['reason']??'')==='ut99_mesh_to_lodmesh');
$check('unreal_v120_does_not_inherit_mesh_fallback',($unreal[1]['status']??'')==='runtime_only');

$private=$exports;$private[0]['object_flags']=0;
$ut99=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UT99_V1400,$consumer,$providerImports,$private,'TestPkg',68,68);
$check('ut99_private_export_fails_before_runtime_fallback',($ut99[1]['status']??'')==='private_export'&&($ut99[1]['candidate_export_index']??null)===0);

$noneConsumer=[
 ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'TestPkg','outer_index'=>0],
 ['import_index'=>1,'class_package'=>'None','class_name'=>'None','object_name'=>'None','outer_index'=>-1],
 ['import_index'=>2,'class_package'=>'Engine','class_name'=>'Texture','object_name'=>'Child','outer_index'=>-2],
];
$ut99=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UT99_V1400,$noneConsumer,$providerImports,$exports,'TestPkg',68,68);
$check('ue1_direct_name_none_is_ignored',($ut99[1]['status']??'')==='ignored');
$check('ue1_name_none_ancestor_is_not_ignored',($ut99[2]['status']??'')==='invalid'&&($ut99[2]['reason']??'')==='parent_source_linker_unavailable');

$wrongOuter=[['export_index'=>0,'class_index'=>-2,'object_name'=>'LegacyTexture','outer_index'=>77,'object_flags'=>0]];
$legacyConsumer=[
 ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'TestPkg','outer_index'=>0],
 ['import_index'=>1,'class_package'=>'Engine','class_name'=>'Texture','object_name'=>'LegacyTexture','outer_index'=>-1],
];
$unreal=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UNREAL_V120,$legacyConsumer,$providerImports,$wrongOuter,'TestPkg',54,54);
$check('unreal_v120_texture_retry_ignores_outer_and_public',($unreal[1]['export_index']??null)===0&&($unreal[1]['reason']??'')==='unreal_v120_legacy_no_outer_match');

$pre50Consumer=[
 ['import_index'=>0,'class_package'=>'Engine','class_name'=>'OtherClass','object_package'=>'TestPkg','object_package_present'=>true,'object_name'=>'LegacyObject','outer_index'=>0],
];
$pre50Exports=[['export_index'=>0,'class_index'=>-7,'object_name'=>'LegacyObject','outer_index'=>123,'object_flags'=>4]];
$pre50=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UNREAL_V120,$pre50Consumer,$providerImports,$pre50Exports,'TestPkg',49,49);
$check('unreal_pre50_uses_object_package_and_skips_outer_check',($pre50[0]['export_index']??null)===0);

$missingConsumer=[
 ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'TestPkg','outer_index'=>0],
 ['import_index'=>1,'class_package'=>'Engine','class_name'=>'OtherClass','object_name'=>'MissingObject','outer_index'=>-1],
];
$ut99=Ue1::resolveInMemoryOutcome(Ue1::PROFILE_UT99_V1400,$missingConsumer,$providerImports,[],'TestPkg',68,68);
$check('ut99_post_file_miss_requires_runtime_state',($ut99[1]['status']??'')==='runtime_only');


$profileMethod=new ReflectionMethod(PdoDependencyResolver::class,'ue1VerifyImportProfile');
$check('v4_ut99_v1400_profile_is_version_bounded',
    $profileMethod->invoke(null,3,68,0)===Ue1::PROFILE_UT99_V1400
    &&$profileMethod->invoke(null,3,69,0)===null);
$check('v4_unreal_v120_profile_is_version_bounded',
    $profileMethod->invoke(null,12,54,0)===Ue1::PROFILE_UNREAL_V120
    &&$profileMethod->invoke(null,12,60,0)===null);
$rootMethod=new ReflectionMethod(PdoDependencyResolver::class,'legacyRootPackageName');
$pre50Root=[0=>['import_index'=>0,'class_package'=>'Engine','class_name'=>'Texture','object_name'=>'LegacyObject','outer_index'=>0,'object_package_present'=>true,'object_package'=>'LegacyPkg']];
$check('v4_pre50_root_comes_from_object_package',$rootMethod->invoke(null,$pre50Root,0)==='LegacyPkg');
$objectMethod=new ReflectionMethod(PdoDependencyResolver::class,'isSourceObjectImport');
$check('v4_pre50_object_import_does_not_use_zero_outer_as_package_only',$objectMethod->invoke(null,$pre50Root[0],'UE1')===true);
$v4Source=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoDependencyResolver.php');
$check('v4_ue1_uses_profile_resolver_and_ut99_only_package_retry',
    str_contains($v4Source,'PdoUe1VerifyImportProjectionResolver::resolveProviderOutcome')
    &&str_contains($v4Source,"PROFILE_UT99_V1400")
    &&str_contains($v4Source,"['UnrealShare']")
    &&str_contains($v4Source,"ut99_unreali_to_unrealshare"));

$v5Resolver=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5ClassicDependencyResolver.php');
$pass2Service=(string)file_get_contents($root.'/src/Infrastructure/Metadata/Uedb5GameDependencyPassService.php');
$check('ue1_does_not_consume_catalog_class_remap',
    str_contains($v5Resolver,"\$classRemaps = \$engine === 'ue2' ?")
    &&str_contains($pass2Service,"\$engine === 'UE2' && \$this->tableExists('ue_class_remaps')"));

$ok=$failures===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);