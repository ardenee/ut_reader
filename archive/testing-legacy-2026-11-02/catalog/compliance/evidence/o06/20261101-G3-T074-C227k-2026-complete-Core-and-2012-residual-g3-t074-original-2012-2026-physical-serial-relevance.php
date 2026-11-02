<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
$r='C:/Temp/UnrealDB/O06/';
foreach(['2012'=>'Native-C227i-G304/System/','2026'=>'Native-227k/System/'] as $build=>$dir){
 foreach(['Core.u','Engine.u'] as $fn){
 $p=new P($r.$dir.$fn);$ex=$p->getExports();$im=$p->getImports();
 $inert=0;$real=['struct'=>0,'property'=>0,'other'=>0];$noneNotZero=[];
 foreach($ex as $id=>$x){
  if($x['objectNameText']==='None'&&$x['classIndex']===0){
   $isZero=$x['serialSize']===0&&$x['serialOffset']===0&&$x['objectFlags']===0;
   if($isZero)$inert++;else $noneNotZero[]=$id;continue;
  }
  $ci=(int)$x['classIndex'];$kind=$ci===0?'Class':($ci<0?($im[-$ci-1]['objectNameText']??'UNKNOWN'):($ex[$ci-1]['objectNameText']??'UNKNOWN'));
  if(in_array($kind,['Function','Class','Struct','State'],true))$real['struct']++;
  elseif(str_ends_with($kind,'Property'))$real['property']++;
  else $real['other']++;
 }
 echo json_encode(['build'=>$build,'package'=>$fn,'physical'=>count($ex),'inert_None_class0_zero'=> $inert,
 'real_types'=>$real,'bad_None_exceptions'=>$noneNotZero],JSON_PRETTY_PRINT).PHP_EOL;
 }
}
