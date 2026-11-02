<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$files=[
 ['C:/Temp/UnrealDB/O06/Native-UCC/System/G5DirectStringPayloadBad.u','ut99-v432'],
 ['C:/Temp/UnrealDB/O06/Native-227k/System/G3Scalar227RuntimeBadNul.u','unreal-v227k'],
 ['C:/Temp/UnrealDB/O06/Native-227k/System/G3Scalar227RuntimeBadCount.u','unreal-v227k']
];
foreach($files as [$file,$family]){
 $pkg=new P($file);
 try{$r=T::inspect($file,$pkg,0,$family);
  echo json_encode(['file'=>basename($file),'status'=>'NO_ARCHIVE_EXCEPTION','end'=>$r['tag_end_offset'],'tail'=>$r['native_tail_bytes'],'values'=>array_map(static fn($t)=>[$t['name'],$t['value_kind'],$t['on_disk_value_bytes']],$r['tags'])]).PHP_EOL;
 }catch(Throwable $e){echo json_encode(['file'=>basename($file),'status'=>'SOURCE_SELECTED_NATIVE_ARCHIVE_EXCEPTION','error'=>$e->getMessage()]).PHP_EOL;}
}
