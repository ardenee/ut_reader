<?php
declare(strict_types=1);
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$f='C:/Temp/UnrealDB/O06/Native-469f/System/Engine.u';$p=new P($f);
$counts=[];$examples=[];$total=0;$errors=[];
foreach($p->getExports() as $i=>$ex){
 $classIndex=(int)$ex['classIndex'];$name=(string)$ex['objectNameText'];
 if($classIndex===0)continue;
 $imp=$classIndex<0?($p->getImports()[-$classIndex-1]['objectNameText']??''):'';
 if(!in_array($imp,['Function','State'],true))continue;
 try{
  $rec=N::inspect($f,$p,$i,T::inspect($f,$p,$i,'ut99-v432'),'ut99-v432');
  $a=$rec['script_archive']??[];$sz=$rec['script_size']??0;
  if($sz<=0||($a['status']??'')!=='script_archive_expressions_decoded')continue;
  $stack=$a['expression_preview']??[];
  while($stack){$x=array_pop($stack);$t=$x['token'];$counts[$t]=($counts[$t]??0)+1;
   if(in_array($t,[4,13,14,29,30,34,35],true) &&count($examples[$t]??[])<4){
    $examples[$t][]=['i'=>$i,'name'=>$name,'size'=>$sz,'script_memory_offset'=>$x['memory_offset'],'raw_mem_hex'=>substr((string)$a['source_memory_bytes_hex'],0,180),'memory_mask_hex'=>substr((string)$a['source_memory_known_mask_hex'],0,180),'archive_bytes'=>$a['archive_bytes_consumed']];
   }
   foreach($x['children']??[] as $child)$stack[]=$child;
  }
  $total++;
 }catch(Throwable $e){if(count($errors)<10)$errors[]=[$i,$name,$e->getMessage()];}
}
ksort($counts);
echo json_encode(['genuine_original_469f_Engine_u_processed_scripts'=>$total,'genuine_source_opcode_counts'=>$counts,'seven_real_compiled_dispatch_differences_samples'=>$examples,'exceptions_first'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
