<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
$p=new UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader('C:/Temp/UnrealDB/O06/Native-227k/System/Core.u');
$e=$p->getExports();
foreach([7,11,652,653] as $id){
 $v=$e[$id];
 echo json_encode(['id'=>$id,'name'=>$v['objectNameText'],'outer'=>$v['outerIndex'],
  'super'=>$v['superIndex'],'class'=>$v['classIndex'],'bytes'=>$v['serialSize']]).PHP_EOL;
}
