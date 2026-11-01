<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
$r='C:/Temp/UnrealDB/O06/';
$b=file_get_contents($r.'g3-t073-new-shipped-original-native-unobserved-export-register.json');
$j=json_decode(str_starts_with($b,"\xff\xfe")?iconv('UTF-16LE','UTF-8',substr($b,2)):$b,true);
$native=[];foreach(file($r.'g3-t073-v432-targeted-original-40-unloaded-classes-native.log') as $line){
 if(preg_match('/^T073_V432_NATIVE_LOADED_ALL file=(\S+) idx=(\d+)/',$line,$m))$native[basename(str_replace('\\','/',$m[1]))][(int)$m[2]]=true;
}
$cnt=0;$errors=[];
foreach(['Core.u','Engine.u'] as $file){
 $p=new P($r.'Native-UCC/System/'.$file);$e=$p->getExports();
 foreach(['struct_serializer','property_serializer'] as $kind){
  foreach($j['v432'][$file]['unobserved_original_physical_export_indices'][$kind] as $row){
   $i=$row['export_index'];if(isset($native[$file][$i]))continue;
   $v=$e[$i];$cnt++;
   if($v['objectNameText']!=='None'||$v['classIndex']!==0||$v['objectFlags']!==0||$v['serialSize']!==0||$v['serialOffset']!==0)$errors[]=['file'=>$file,'id'=>$i,'export'=>$v];
  }
 }
}
echo json_encode(['genuine_v432_zero_byte_None_exports'=>$cnt,'all_source_inert'=>$errors===[],'errors'=>$errors],JSON_PRETTY_PRINT).PHP_EOL;
exit($cnt===43&&$errors===[]?0:1);
