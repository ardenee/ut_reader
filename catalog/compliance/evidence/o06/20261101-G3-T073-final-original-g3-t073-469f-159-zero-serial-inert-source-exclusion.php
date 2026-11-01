<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
$r='C:/Temp/UnrealDB/O06/';
$data=file_get_contents($r.'g3-t073-new-shipped-original-native-unobserved-export-register.json');
$old=json_decode(str_starts_with($data,"\xff\xfe")?iconv('UTF-16LE','UTF-8',substr($data,2)):$data,true);
$lines=file($r.'g3-t073-469f-original-compiled-LoadAllObjects-all-missing-records.log');
$native=[];
foreach($lines as $line)if(preg_match('/T073_ORIGINAL_BULK_MATERIALIZED file=(\S+) idx=(\d+)/',$line,$m))$native[basename(str_replace('\\','/',$m[1]))][(int)$m[2]]=true;
$checked=0;$bad=[];$stats=[];
foreach(['Core.u','Engine.u'] as $file){
 $p=new P($r.'Native-469f/System/'.$file);$exp=$p->getExports();
 foreach(['struct_serializer','property_serializer'] as $kind){
  foreach($old['f469'][$file]['unobserved_original_physical_export_indices'][$kind] as $row){
   $id=$row['export_index'];if(isset($native[$file][$id]))continue;
   $e=$exp[$id];
   $ok=$e['objectNameText']==='None'&&$e['classIndex']===0&&$e['objectFlags']===0&&$e['serialSize']===0&&$e['serialOffset']===0;
   $checked++;if(!$ok)$bad[]=['file'=>$file,'id'=>$id,'export'=>$e];
   $stats[$file][$kind]=($stats[$file][$kind]??0)+1;
  }
 }
}
echo json_encode(['source_native_inert_export_check'=>$bad===[],'original_None_export_class0_flags0_size0_offset0_count'=>$checked,
 'per_package'=>$stats,'invalid'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]&&$checked===159?0:1);
