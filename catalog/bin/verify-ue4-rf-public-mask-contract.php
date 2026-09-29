<?php
declare(strict_types=1);
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogDependencyDiagnostics.php';
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[]=['check'=>$name,'ok'=>$ok];if(!$ok)$failures[]=$name;};
$consumer=[
 ['import_index'=>0,'class_package'=>'/Script/CoreUObject','class_name'=>'Package','object_name'=>'/Game/TestPkg','outer_index'=>0],
 ['import_index'=>1,'class_package'=>'/Script/CoreUObject','class_name'=>'Class','object_name'=>'Material','outer_index'=>-1],
];
$public=[['export_index'=>0,'class_index'=>0,'object_name'=>'Material','outer_index'=>0,'object_flags'=>1]];
$match=PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer,[],$public,'/Game/TestPkg');
$check('ue4_rf_public_0x1_resolves',($match[1]??null)===0);
$nativeOnly=$public;$nativeOnly[0]['object_flags']=4;
$match=PdoUe4VerifyImportProjectionResolver::resolveInMemory($consumer,[],$nativeOnly,'/Game/TestPkg');
$check('ue4_rf_mark_as_native_0x4_is_not_public',!isset($match[1]));
$resolver=file_get_contents($root.'/src/Infrastructure/Persistence/PdoUe4VerifyImportProjectionResolver.php')?:'';
$diag=file_get_contents($root.'/lib/CatalogDependencyDiagnostics.php')?:'';
$check('resolver_uses_epic_ue4_rf_public_mask',str_contains($resolver,'RF_PUBLIC = 0x00000001'));
$check('diagnostic_uses_ue4_rf_public_mask',str_contains($diag,"('UE4'?0x00000001")||str_contains($diag,"'UE4'?0x00000001"));
$workerVersion=file_get_contents($root.'/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php')?:'';
$check('worker_fingerprint_tracks_ue4_resolver',str_contains($workerVersion,'PdoUe4VerifyImportProjectionResolver.php'));
$result=['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($result['ok']?0:1);
