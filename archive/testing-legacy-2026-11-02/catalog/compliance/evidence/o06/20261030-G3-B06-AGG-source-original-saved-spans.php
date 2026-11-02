<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
$f='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime.u';
$p=new UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader($f);
$a=UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader::inspect($f,$p,0,'unreal-v227k');
foreach($a['tags'] as $tag){
 $o=$tag['value_offset'];$n=$tag['on_disk_value_bytes'];
 echo json_encode(['name'=>$tag['name'],'start'=>$o,'bytes'=>$n,'hex'=>bin2hex(substr(file_get_contents($f),$o,$n))],JSON_UNESCAPED_SLASHES).PHP_EOL;
}
