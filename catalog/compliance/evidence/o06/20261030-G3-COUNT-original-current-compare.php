<?php
require 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
foreach(['CountHuge','CountThree'] as $label){
 $f='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime'.$label.'.u';
 try{$pkg=new UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader($f);
 $x=UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader::inspect($f,$pkg,0,'unreal-v227k');
 echo $label.' UNEXPECTED_SUCCESS '.json_encode($x['status']).PHP_EOL;
 }catch(Throwable $e){echo $label.' ERROR '.$e->getMessage().PHP_EOL;}
}
