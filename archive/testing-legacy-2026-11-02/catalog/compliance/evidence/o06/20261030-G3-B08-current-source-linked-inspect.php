<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
foreach(['C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Config227.u','C:/Temp/UnrealDB/O06/Native-227k/System/G3Config227Runtime.u'] as $f){
 $p=new P($f);$meta=$p->getHeader();
 foreach($p->getExports() as $idx=>$ex)if($ex['objectNameText']==='G3Config227Actor'||$ex['objectNameText']==='ConfigObject'){
 $r=$ex['classIndex']===0?C::inspect($f,$p,$idx,false,'unreal-v227i'):T::inspect($f,$p,$idx,'unreal-v227k');
 echo json_encode(['file'=>basename($f),'index'=>$idx,'version'=>$meta['version'],'licensee'=>$meta['licensee'],'size'=>filesize($f),'offset'=>$ex['serialOffset'],'serial_size'=>$ex['serialSize'],'status'=>$r['status'],'end'=>$r['tag_end_offset']??null,'tail'=>$r['native_tail_bytes']??null,'tags'=>array_map(static fn($t)=>[$t['name'],$t['type'],$t['value_kind'],$t['value'],$t['on_disk_value_bytes']],$r['default_property_tags']??$r['tags']??[])],JSON_PARTIAL_OUTPUT_ON_ERROR).PHP_EOL;
 }
}
