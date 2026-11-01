<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$r='C:/Temp/UnrealDB/O06/';
$path=$r.'Native-469f/System/Core.u';$p=new P($path);
$checks=0;$errors=[];$seen=[];
foreach(file($r.'g3-t073-f469-six-Registry-actual-native-serialized-dim-flags.log') as $ln){
 if(!preg_match('/^T073_469F_SIX_CORE_PROPERTY_SERIALIZED index=(\d+) dim=(\d+) flags=(\d+)/',$ln,$m))continue;
 $id=(int)$m[1];$dim=(int)$m[2];$flags=(int)$m[3];
 $data=N::inspect($path,$p,$id,T::inspect($path,$p,$id,'ut99-v469f'),'ut99-v469f');
 $seen[$id]=true;
 foreach(['array_dim'=>$dim,'property_flags'=>$flags] as $field=>$native){
  $checks++;if(($data[$field]??null)!==$native)$errors[]=['id'=>$id,'field'=>$field,'native'=>$native,'saved'=>$data[$field]??null];
 }
}
$ok=count($seen)===6&&$checks===12&&$errors===[];
echo json_encode(['ok'=>$ok,'genuine_new_469f_registry_properties'=>count($seen),'checked_saved_fields'=>$checks,
 'native_only_unlinked_runtime_ElementSize_zero_do_not_claim_saved'=>true,'errors'=>$errors],JSON_PRETTY_PRINT).PHP_EOL;
exit($ok?0:1);
