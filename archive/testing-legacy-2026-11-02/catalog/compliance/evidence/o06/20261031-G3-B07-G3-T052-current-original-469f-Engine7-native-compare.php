<?php
declare(strict_types=1);
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$f='C:/Temp/UnrealDB/O06/Native-469f/System/Engine.u';$p=new P($f);
$exports=$p->getExports();$imports=$p->getImports();
$wanted=array_flip(['TriggerPound','QuickSave','FormatFloat','TakeDamage','PreBeginPlay','CalcDrawOffset','GetShortAbsoluteTime','SpawnCopy','PlayerMove']);
$rows=[];
foreach($exports as $i=>$ex){
 $name=(string)$ex['objectNameText'];
 if(!isset($wanted[$name]))continue;
 $cls=$ex['classIndex'];$class=$cls<0?($imports[-$cls-1]['objectNameText']??''):'';
 if(!in_array($class,['Function','State'],true))continue;
 try{
  $r=N::inspect($f,$p,$i,T::inspect($f,$p,$i,'ut99-v432'),'ut99-v432');
  $a=$r['script_archive']??null;
  if(!is_array($a)||($r['script_size']??0)>1600||($r['script_size']??0)<=0)continue;
  $parts=[$name];$outer=$ex['outerIndex']??0;
  for($j=0;$outer!==0&&$j<20;$j++){
   $o=$outer>0?($exports[$outer-1]??null):($imports[-$outer-1]??null);
   if(!$o)break;
   array_unshift($parts,$o['objectNameText']);
   $outer=$o['outerIndex'];
  }
  if($parts[0]!=='Engine')array_unshift($parts,'Engine');
  $fullname=$class.' '.implode('.',$parts);
  $pending=$a['expression_preview']??[];$present=[];$counts=[];
  while($pending){$e=array_pop($pending);$t=(int)$e['token'];$counts[$t]=($counts[$t]??0)+1;
   foreach($e['children']??[] as $child)$pending[]=$child;
  }
  $rows[$fullname]=['fullname'=>$fullname,'i'=>$i,'size'=>$r['script_size'],
    'real_expression_token_counts'=>$counts,
    'memory_hex'=>$a['source_memory_bytes_hex'],'mask_hex'=>$a['source_memory_known_mask_hex'],
    'physical_archive_bytes'=>$a['archive_bytes_consumed'],'relocations'=>$a['native_load_relocation_preview']];
 }catch(Throwable $e){echo 'ERR '.$name.' '.$e->getMessage().PHP_EOL;}
}
echo json_encode(['rows'=>array_values($rows),'count'=>count($rows)],JSON_UNESCAPED_SLASHES).PHP_EOL;
