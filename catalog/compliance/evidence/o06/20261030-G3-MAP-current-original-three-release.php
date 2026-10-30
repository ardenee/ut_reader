<?php
require 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader as S;
foreach([
 ['ut99-v432',0,'',0],
 ['ut99-v432',0,'',2],
 ['unreal-v227i',0,'',0],
 ['unreal-v227i',0,'',2],
 ['unreal-v227k',227,'00',0],
 ['unreal-v227k',227,'020C000000B004000022000000480D0000',2],
 ['unreal-v227k',227,'020C000000B004000022000000480D0000',2],
] as [$family,$lic,$hex,$expected]){
 $position=0;
 try{$r=S::decodeOriginalMapAt(hex2bin($hex),$position,$family,$lic,'intproperty','intproperty');echo json_encode(compact('family','lic','position','r')).PHP_EOL;}
 catch(Throwable $e){echo "ERROR $family ".$e->getMessage().PHP_EOL;}
}
