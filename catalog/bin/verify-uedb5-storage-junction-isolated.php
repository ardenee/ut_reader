<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Storage\LocalFilesystemPackageStorage;
$root=dirname(__DIR__);$config=catalog_config();
$method=new ReflectionMethod(LocalFilesystemPackageStorage::class,'relativeStorageRoot');
$base=new LocalFilesystemPackageStorage((string)$config['storage_path'],$root);
$nested=new LocalFilesystemPackageStorage($root.'/storage/uedb5-isolated-reimport-20261015',$root);
$checks=['production_mount_root'=>$method->invoke($base)==='storage','isolated_junction_subtree'=>$method->invoke($nested)==='storage/uedb5-isolated-reimport-20261015'];
try{$method->invoke(new LocalFilesystemPackageStorage(sys_get_temp_dir(),$root));$checks['outside_storage_rejected']=false;}
catch(RuntimeException $e){$checks['outside_storage_rejected']=str_contains($e->getMessage(),'outside the catalog storage mount');}
$ok=!in_array(false,$checks,true);
echo json_encode(['ok'=>$ok,'read_only'=>true,'checks'=>$checks],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
