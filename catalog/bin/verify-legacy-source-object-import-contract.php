#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyResolver;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoLegacyVerifyImportProjectionResolver;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;if(!$ok)$failures[]=$name;
};

$method=new ReflectionMethod(PdoDependencyResolver::class,'isSourceObjectImport');
$whitespaceImport=[
    'import_index'=>1,'class_package'=>'Core','class_name'=>'Package','object_name'=>' ',
    'outer_index'=>-1,'root_package'=>'Provider','relative_object_path'=>'','full_path'=>'Provider',
];
$rootImport=[
    'import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Provider',
    'outer_index'=>0,'root_package'=>'Provider','relative_object_path'=>'','full_path'=>'Provider',
];
$check('ue1_object_import_comes_from_serialized_outer',
    $method->invoke(null,$whitespaceImport,'UE1')===true);
$check('ue2_object_import_comes_from_serialized_outer',
    $method->invoke(null,$whitespaceImport,'UE2')===true);
$check('legacy_root_package_import_stays_package_level',
    $method->invoke(null,$rootImport,'UE2')===false);
$check('ue4_keeps_existing_derived_path_boundary',
    $method->invoke(null,$whitespaceImport,'UE4')===false);

$noneMethod=new ReflectionMethod(PdoDependencyResolver::class,'legacySourceIrrelevantIndexes');
$noneGraph=[
    0=>['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Provider','outer_index'=>0],
    1=>['import_index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>'None','outer_index'=>-1],
    2=>['import_index'=>2,'class_package'=>'Core','class_name'=>'Class','object_name'=>'Child','outer_index'=>-2],
];
$noneIndexes=$noneMethod->invoke(null,$noneGraph);
$check('legacy_name_none_is_excluded_from_provider_scoring',
    !isset($noneIndexes[0])
    &&($noneIndexes[1]['reason']??null)==='name_none'
    &&(int)($noneIndexes[1]['ancestor_index']??-1)===1);
$check('legacy_name_none_descendant_is_excluded_from_provider_scoring',
    ($noneIndexes[2]['reason']??null)==='name_none_ancestor'
    &&(int)($noneIndexes[2]['ancestor_index']??-1)===1);

$consumer=[
    $rootImport,
    $whitespaceImport,
];
$providerImports=[
    ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Core','outer_index'=>0],
    ['import_index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>'Package','outer_index'=>-1],
];
$providerExports=[
    ['export_index'=>0,'class_index'=>-2,'super_index'=>0,'outer_index'=>0,'object_name'=>' ','object_flags'=>0x00000004],
];
$variants=PdoLegacyVerifyImportProjectionResolver::resolveInMemoryVariants(
    $consumer,$providerImports,$providerExports,'Provider',[]
);
$check('legacy_verifyimport_resolves_literal_whitespace_fname',
    (int)($variants['standard'][1]??-1)===0);

$source=(string)file_get_contents($root.'/src/Infrastructure/Persistence/PdoDependencyResolver.php');
$cli=(string)file_get_contents($root.'/bin/rebuild-legacy-dependencies.php');
$worker=(string)file_get_contents($root.'/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$check('legacy_verification_requirements_use_source_object_imports',
    str_contains($source,'if ($legacyVerifyImport)')
    &&str_contains($source,'!self::isSourceObjectImport($import, $engineKey)')
    &&str_contains($source,'$isObjectImport = self::isSourceObjectImport($import, $engineKey);'));
$check('legacy_requirements_exclude_source_irrelevant_none_imports',
    str_contains($source,'$legacySourceIrrelevant = $legacyVerifyImport')
    &&str_contains($source,'if ($legacyVerifyImport && isset($legacySourceIrrelevant[$importIndex]))')
    &&str_contains($source,'if (isset($legacySourceIrrelevant[$importIndex]))'));
$check('canonical_v4_rebuild_supports_exact_file_target',
    str_contains($cli,"'file-id::'")
    &&str_contains($cli,"'targeted'=>true")
    &&str_contains($cli,"'dependency_summary_refreshed'=>true")
    &&str_contains($cli,'PdoGameCatalogStats'));
$check('worker_fingerprint_tracks_legacy_verifyimport_resolver',
    str_contains($worker,'/src/Infrastructure/Persistence/PdoLegacyVerifyImportProjectionResolver.php'));

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
