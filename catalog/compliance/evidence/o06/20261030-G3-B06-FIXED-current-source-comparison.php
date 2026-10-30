<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader as S;
$f='C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Fixed227.u';
$p=new P($f);
foreach($p->getExports() as $i=>$e){
 echo 'EXPORT '.json_encode(['i'=>$i,'name'=>$e['objectNameText'],'class'=>$e['classIndex']]).PHP_EOL;
 if($e['objectNameText']==='G3PlainPair'){
 $lay=S::resolve($f,$p,$i+1,'unreal-v227k');
 echo 'LAYOUT '.json_encode($lay).PHP_EOL;
 $ints=['name'=>'InnerNumber','kind'=>'intproperty','array_dim'=>1,'skip_persistent_archive'=>false,'nested_layout'=>null,'array_inner_layout'=>null,'fixed_array_count'=>null];
 $first=['struct_reference'=>null,'fields'=>[['name'=>'OriginalNativeFixedInt','kind'=>'fixedarrayproperty','array_dim'=>1,'skip_persistent_archive'=>false,'fixed_array_count'=>2,'array_inner_layout'=>$ints]]];
 $pos=0;$s=hex2bin('7b00000038feffff');
 echo 'REAL_FIXEDINT '.json_encode(S::readAt($s,$pos,$first,true,$p)).' cursor='.$pos.PHP_EOL;
 $second=['struct_reference'=>null,'fields'=>[['name'=>'OriginalNativeFixedStruct','kind'=>'fixedarrayproperty','array_dim'=>1,'skip_persistent_archive'=>false,'fixed_array_count'=>2,'array_inner_layout'=>['name'=>'G3PlainPair','kind'=>'structproperty','array_dim'=>1,'skip_persistent_archive'=>false,'nested_layout'=>$lay]]]];
 $pos=0;$s=hex2bin('650000000000a03f36ffffff000030c0');
 echo 'REAL_FIXEDSTRUCT '.json_encode(S::readAt($s,$pos,$second,true,$p)).' cursor='.$pos.PHP_EOL;
 }
}
