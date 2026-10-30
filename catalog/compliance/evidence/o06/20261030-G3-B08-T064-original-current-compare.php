<?php
require 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
foreach(['BadTagType','BadArrayIndex','BadTerminator'] as $case){
 $f='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime'.$case.'.u';
 try {
  $p=new UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader($f);
  $v=UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader::inspect($f,$p,0,'unreal-v227k');
  echo 'CASE '.$case.' PASS '.json_encode(['tag_end'=>$v['tag_end_offset'],'native_tail'=>$v['native_tail_bytes'],'tags'=>array_map(static fn($t)=>[$t['name'],$t['type'],$t['value_kind'],$t['on_disk_value_bytes']],$v['tags'])]).PHP_EOL;
 }catch(Throwable $e){echo 'CASE '.$case.' ERROR '. $e->getMessage().PHP_EOL;}
}
