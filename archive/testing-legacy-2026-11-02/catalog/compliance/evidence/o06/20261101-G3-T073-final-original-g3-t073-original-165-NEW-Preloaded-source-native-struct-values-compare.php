<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root='C:/Temp/UnrealDB/O06/';$errors=[];$checks=0;$totals=[];
$ck=static function($ok,$message)use(&$checks,&$errors){$checks++;if(!$ok&&count($errors)<60)$errors[]=$message;};
foreach(['v432'=>['Native-UCC/System/','ut99-v432'],'f469'=>['Native-469f/System/','ut99-v469f']] as $build=>[$dir,$profile]){
 $log=$root.'g3-t073-'.$build.($build==='f469'?'-new-only-real-source-native-Preloaded-struct-values.log':'-new-only-real-source-native-struct-values.log');
 $data=file($log);$cache=[];$byType=[];$observed=[];$matched=0;
 foreach($data as $line){
  $regex='/^T073_NEW_ACTUAL_NATIVE_STRUCT pkg=(Core\.u|Engine\.u) idx=(\d+) type=(\w+) line=(-?\d+) textpos=(-?\d+) script=(\d+) super=(-?\d+) next=(-?\d+) children=(-?\d+) text=(-?\d+) object=(.*)$/';
  if(!preg_match($regex,$line,$m))continue;
  $pkgName=$m[1];$id=(int)$m[2];$kind=$m[3];$seenKey=$pkgName.'#'.$id;
  $ck(!isset($observed[$seenKey]),"$build duplicate original native $seenKey");$observed[$seenKey]=1;
  if(!isset($cache[$pkgName])){
   $path=$root.$dir.$pkgName;$p=new P($path);
   $cache[$pkgName]=[$path,$p,$p->getExports()];
  }
  [$path,$p,$exports]=$cache[$pkgName];
  $ck(isset($exports[$id]),"$build $seenKey physical export exists");
  try{
   $actual=$kind==='Class'
    ? C::inspect($path,$p,$id,false,$profile)
    : N::inspect($path,$p,$id,T::inspect($path,$p,$id,$profile),$profile);
  }catch(Throwable $e){$errors[]="$build $seenKey reader ".$e->getMessage();continue;}
  $all=true;
  foreach(['source_line'=>4,'source_text_pos'=>5,'script_size'=>6] as $key=>$slot){
   $expected=(int)$m[$slot];$value=$actual[$key]??null;
   $ok=is_int($value)&&$value===$expected;
   $ck($ok,"$build $seenKey $key native=$expected saved=".json_encode($value));$all=$all&&$ok;
  }
  foreach(['super_field_reference'=>7,'next_field_reference'=>8,'children_reference'=>9,'script_text_reference'=>10] as $key=>$slot){
   $native=(int)$m[$slot];$saved=$actual[$key]??null;
   $ok=is_int($saved) && ($saved===0? $native===0 : ($native>0&&($saved<0||$saved===$native)));
   $ck($ok,"$build $seenKey $key native_index_plus1=$native source_signed=".json_encode($saved));$all=$all&&$ok;
  }
  $byType[$kind]=($byType[$kind]??0)+1;
  if($all)$matched++;
 }
 $expected=$build==='v432'?148:17;
 $ck(count($observed)===$expected,"$build exact original newly materialized class/struct/function count ".count($observed)."/".$expected);
 $ck($matched===$expected,"$build all newly discovered original native loaded source values matched");
 $totals[$build]=['new_original_loaded_object_count'=>count($observed),'exact_matching_objects'=>$matched,'types'=>$byType];
}
echo json_encode(['ok'=>$errors===[],'new_original_native_struct_method_checks'=>$checks,
 'source_loaded_object_parity'=>$totals,'errors'=>$errors,
 'T073_decision_pending_elsewhere'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
