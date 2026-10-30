<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
foreach(['Native-C227i-G304/System/G3Array227.u','Native-227k/System/G3Array227Runtime.u'] as $p){
 $path='C:/Temp/UnrealDB/O06/'.$p;$x=new P($path);
 echo json_encode(['file'=>basename($p),'version'=>$x->getHeader()['version'],'licensee'=>$x->getHeader()['licensee'],'exports'=>count($x->getExports())]).PHP_EOL;
 foreach($x->getExports() as $i=>$e)if($e['objectNameText']==='G3Array227Actor'||$e['objectNameText']==='ArrayObject'){
  try{$r=$e['classIndex']==0?C::inspect($path,$x,$i,false,'unreal-v227i'):T::inspect($path,$x,$i,'unreal-v227k');
  echo json_encode(['index'=>$i,'name'=>$e['objectNameText'],'offset'=>$e['serialOffset'],'size'=>$e['serialSize'],'status'=>$r['status'],'complete'=>$r['source_file_archive_complete']??null,'end'=>$r['tag_end_offset']??null,'tail'=>$r['native_tail_bytes']??null,'tags'=>array_map(static fn($t)=>['name'=>$t['name'],'kind'=>$t['value_kind'],'value'=>$t['value'],'on_disk_value_bytes'=>$t['on_disk_value_bytes']],$r['default_property_tags']??$r['tags']??[])],JSON_PARTIAL_OUTPUT_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL;
  }catch(Throwable $ex){echo 'DECODE_ERROR '.$e['objectNameText'].' '.$ex->getMessage().PHP_EOL;}
 }
}
