<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$files=[
 'lib/CatalogNavigation.php'=>['Package Viewer','paks.php'],
 'paks.php'=>['Package Viewer','IoStore (.utoc/.ucas) browsing is being integrated'],
 'game-paks.php'=>['Package Viewer','PAK containers','IoStore containers','iostore-info.php?id='],
 'iostore-info.php'=>['IoStore container','FPackageId prefix','ue_iostore_packages'],
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
