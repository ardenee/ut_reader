<?php
require 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
$out=[];
foreach(['C227i'=>'Native-C227i-G304/System/','C227k'=>'Native-227k/System/'] as $build=>$dir){
 foreach(['Core.u','Engine.u'] as $name){
 $path='C:/Temp/UnrealDB/O06/'.$dir.$name;
 $p=new P($path);$e=$p->getExports();$im=$p->getImports();$n=[];
 foreach($e as $id=>$rec){
  $i=(int)$rec['classIndex'];
  $kind=$i===0?'Class':($i>0?($e[$i-1]['objectNameText']??'BAD_EXPORT'):($im[-$i-1]['objectNameText']??'BAD_IMPORT'));
  $n[$kind]=($n[$kind]??0)+1;
 }
 arsort($n);$out[$build][$name]=['exports'=>count($e),'version'=>$p->getHeader()['version'],'types'=>$n];
 }
}
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
