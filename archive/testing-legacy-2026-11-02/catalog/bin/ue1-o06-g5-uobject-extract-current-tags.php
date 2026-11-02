<?php
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$base='C:/Temp/UnrealDB/O06/';
$log=file_get_contents($base.'G5-UObject-native-loaded-tagged-all.txt');
preg_match_all('/G5_NATIVE_OBJECT_BEGIN ([^\r\n]+)/',$log,$m);
$objects=array_map(static fn($x)=>trim($x),$m[1]);$source=$base.'Native-UCC/System/Entry.unr';
$pkg=new P($source);$exports=$pkg->getExports();
$rows=[];$matched=0;$tagCount=0;$kinds=[];$missing=[];
foreach($objects as $full){
  preg_match('/^([^ ]+) Entry\.(.+)$/',$full,$parts);
  $name=$parts[2]??'';
  $exp=null;
  foreach($exports as $e){if($e['objectNameText']===$name){$exp=$e;break;}}
  if($exp===null){$missing[]=$full;continue;}
  try{
    $v=T::inspect($source,$pkg,(int)$exp['index'],'ut99-v432');
    $tags=$v['tags']??[];
    $record=['idx'=>(int)$exp['index'],'class'=>$parts[1]??'','name'=>$name,
    'serialSize'=>(int)$exp['serialSize'],'tagEnd'=>$v['tag_end_offset']??null,
    'status'=>$v['status']??'none','tags'=>[]];
    foreach($tags as $t){$record['tags'][]=['name'=>$t['name'],'kind'=>$t['value_kind'],'type'=>$t['type'],'value'=>$t['value'],'array_index'=>$t['array_index']??0];$tagCount++;$kinds[$t['value_kind']]=($kinds[$t['value_kind']]??0)+1;}
    $rows[]=$record;$matched++;
  }catch(Throwable $e){$rows[]=['name'=>$name,'error'=>$e->getMessage()];}
}
file_put_contents($base.'G5-UObject-on-disk-tagged-matched-native.json',json_encode($rows,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['compiled_live_objects'=>count($objects),'matching_real_export_rows'=>$matched,'total_genuine_tags'=>$tagCount,'kinds'=>$kinds,'missing_non_export'=>$missing,'first_eight'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
