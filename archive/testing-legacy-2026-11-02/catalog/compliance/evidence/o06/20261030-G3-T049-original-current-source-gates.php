<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
foreach([
 ['C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Array227.u','unreal-v227i'],
 ['C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Array227.u','unreal-v227k'],
 ['C:/Temp/UnrealDB/O06/Native-UCC/System/G5ArrayProbe.u','ut99-v432']
] as [$file,$family]){
 if(!is_file($file)){echo "MISSING $file\n";continue;}
 $p=new P($file);$found=0;
 foreach($p->getExports() as $i=>$ex){
  if(!in_array($ex['objectNameText'],['Pairs','Strings','Numbers'],true))continue;
  try{$tag=T::inspect($file,$p,$i,$family);
   $native=N::inspect($file,$p,$i,$tag,$family);
   if($native['property_kind']!=='arrayproperty')continue;
   echo json_encode(['file'=>basename($file),'family'=>$family,'index'=>$i,'name'=>$ex['objectNameText'],'net_gate'=>$native['subclass_fields']['runtime_array_network_method_gate']??null,'scope'=>$native['subclass_fields']['network_packet_payload_decoding_in_package_file_reader']??null,'native_status'=>$native['status']]).PHP_EOL;
   if(++$found>=2)break;
  }catch(Throwable $e){echo 'ERR '.$ex['objectNameText'].' '.$e->getMessage().PHP_EOL;}
 }
}
