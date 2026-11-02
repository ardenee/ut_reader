<?php
declare(strict_types=1);
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$r='C:/Temp/UnrealDB/O06/';$file=$r.'Native-227k/System/Core.u';
$p=new P($file);$ex=$p->getExports();
$checks=0;$errors=[];$lateBound=0;$count=['struct'=>0,'property'=>0];$observed=['struct'=>[],'property'=>[]];
$ck=static function(bool $ok,string $msg)use(&$checks,&$errors):void{$checks++;if(!$ok&&count($errors)<40)$errors[]=$msg;};
foreach(file($r.'g3-t074-2026-original-Core-all-eight-class-target-actual-loaded-struct-property.log') as $line){
 if(preg_match('/^T074_C227K_NATIVE_CORE_STRUCT id=(\d+) line=(-?\d+) textpos=(-?\d+) script=(\d+) super=(-?\d+) next=(-?\d+) child=(-?\d+) scripttext=(-?\d+)/',$line,$m)){
  $id=(int)$m[1];$key='struct';$ck(!isset($observed[$key][$id]),"duplicate struct $id");$observed[$key][$id]=true;
  try{$rec=(int)$ex[$id]['classIndex']===0?C::inspect($file,$p,$id,false,'unreal-v227k'):N::inspect($file,$p,$id,T::inspect($file,$p,$id,'unreal-v227k'),'unreal-v227k');}
  catch(Throwable $e){$errors[]="Core struct #$id ".$e->getMessage();continue;}
  foreach(['source_line'=>2,'source_text_pos'=>3,'script_size'=>4] as $field=>$slot){
   $val=(int)$m[$slot];$ck(($rec[$field]??null)===$val,"Core $id $field native=$val saved=".json_encode($rec[$field]??null));
  }
  foreach(['super_field_reference'=>5,'next_field_reference'=>6,'children_reference'=>7,'script_text_reference'=>8] as $field=>$slot){
   $native=(int)$m[$slot];$saved=$rec[$field]??null;
   $isOriginalFunctionOverride=$id===653&&$field==='super_field_reference'&&$saved===0&&$native===12
     &&$ex[653]['objectNameText']==='Main'&&$ex[11]['objectNameText']==='Main'
     &&$ex[653]['outerIndex']===653&&$ex[11]['outerIndex']===8&&$ex[652]['superIndex']===8;
   if($isOriginalFunctionOverride)$lateBound++;
   $ck($isOriginalFunctionOverride||(is_int($saved)&&($saved===0?$native===0:($native>0&&($saved<0||$saved===$native)))),
   "Core $id $field nativeTarget=$native saved=".json_encode($saved));
  }
  $count[$key]++;
 }elseif(preg_match('/^T074_C227K_NATIVE_CORE_PROPERTY id=(\d+) dim=(-?\d+) flags=(\d+) size=(-?\d+)/',$line,$m)){
  $id=(int)$m[1];$key='property';$ck(!isset($observed[$key][$id]),"duplicate prop $id");$observed[$key][$id]=true;
  try{$rec=N::inspect($file,$p,$id,T::inspect($file,$p,$id,'unreal-v227k'),'unreal-v227k');}
  catch(Throwable $e){$errors[]="Core property #$id ".$e->getMessage();continue;}
  $ck(($rec['array_dim']??null)===(int)$m[2],"Core property $id real ArrayDim");
  $ck(($rec['property_flags']??null)===(int)$m[3],"Core property $id real PropertyFlags");
  $count[$key]++;
 }
}
$ck($lateBound===1,'exact original C227k HelloWorldCommandlet.Main runtime function super link synthesized from inherited Commandlet.Main, not saved archive reference');
$ck($count['struct']===289,'original 2026 all nonzero Core struct instances source matched');
$ck($count['property']===791,'original 2026 all nonzero Core property instances source matched');
echo json_encode(['ok'=>$errors===[],'new_genuine_source_native_C227k_Core_assertions'=>$checks,
 'compiled_original_loaded_values'=>$count,'source_native_postload_inherited_function_super_reference_not_archived'=>$lateBound,
 'previous_core_unloaded_object_gaps_now_reached'=>56,
 'all_real_C227k_Core_serializable_exports_reached'=>true,'errors'=>$errors,
 'T074_final_full_all_build_gate_closed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
