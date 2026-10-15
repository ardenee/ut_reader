<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
 'lib/CatalogNavigation.php'=>['Package Viewer','paks.php'],
 'paks.php'=>['Package Viewer','IoStore (.utoc/.ucas) browsing is being integrated'],
 'iostore-info.php'=>['IoStore container','Package name or FPackageId','ue_iostore_packages','iostore-package-info.php'],
 'iostore-package-info.php'=>['CatalogIoStoreIndexedPackageReader','Zen package details','file-info.php'],
 'iostore-backup.php'=>['BACKUP_IOSTORE_CONTAINER','RESTORE_IOSTORE_CONTAINER','catalog_csrf'],
 'game-paks.php'=>['Package Viewer','PAK containers','IoStore containers','iostore-info.php?id=','iostore-backup.php'],
 'src/Infrastructure/Storage/CatalogIoStoreIndexedPackageReader.php'=>['ue_iostore_container_parts','utoc_sha256','Uedb5Ue5ZenIoStoreSnapshotBuilder'],
 'pak-info.php'=>['Package Viewer','pak-info.php'],
];
$results=[];
foreach($files as $path=>$terms){
 $contents=file_get_contents($root.'/'.$path);
 $ok=is_string($contents);
 foreach($terms as $term)$ok=$ok&&str_contains($contents,$term);
 $results[$path]=$ok;
}
$ok=!in_array(false,$results,true);
echo json_encode(['ok'=>$ok,'read_only'=>true,'checks'=>$results],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
