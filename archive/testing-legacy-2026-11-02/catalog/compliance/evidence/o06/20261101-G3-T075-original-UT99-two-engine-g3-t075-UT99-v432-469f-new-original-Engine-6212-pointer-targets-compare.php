<?php
declare(strict_types=1);
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root='C:/Temp/UnrealDB/O06/';
$builds=[
 'v432'=>['dir'=>'Native-UCC/System/','profile'=>'ut99-v432',
   'linkers'=>['01066940'=>'Core.u','0108c2a0'=>'Engine.u'],
   'expected'=>['Core.u'=>169,'Engine.u'=>1236]],
 'f469'=>['dir'=>'G3-T073-new-469f-array-native/System/','profile'=>'ut99-v469f',
   'linkers'=>['02e2aa50'=>'Core.u','02e29e50'=>'Engine.u'],
   'expected'=>['Core.u'=>178,'Engine.u'=>1427]],
];
$result=[];$errors=[];$checks=0;$matches=0;$other=[];
$ck=static function(bool $ok,string $label)use(&$checks,&$errors):void {
 ++$checks;if(!$ok && count($errors)<45)$errors[]=$label;
};
foreach($builds as $build=>$cfg){
 $txt=(string)file_get_contents($root.'g3-t075-'.$build.'-new-original-Engine-pointer-targets-singleline.log');
 $groups=[];$nativeOwners=[];$foreign=0;
 foreach(preg_split('/\r\n|\r|\n/',$txt) as $ln){
  if(preg_match('/^T075_'.strtoupper($build).'_SERIALIZED owner=(\d+) linker=([0-9a-f]{8}) child=([0-9a-f]{8}) scripttext=([0-9a-f]{8}) super=([0-9a-f]{8}) next=([0-9a-f]{8})$/i',$ln,$m)){
   if(!isset($cfg['linkers'][$m[2]])){$foreign++;continue;}
   $fn=$cfg['linkers'][$m[2]];$id=(int)$m[1];
   $ck(!isset($nativeOwners[$fn][$id]),"$build $fn duplicated true UStruct object $id");
   $nativeOwners[$fn][$id]=['child'=>hexdec($m[3]),'text'=>hexdec($m[4]),'super'=>hexdec($m[5]),'next'=>hexdec($m[6])];
  }elseif(preg_match('/^T075_'.strtoupper($build).'_(CHILD|TEXT|SUPER|NEXT) owner=(\d+) linker=([0-9a-f]{8}) target=(\d+) targetLinker=([0-9a-f]{8})$/i',$ln,$m)){
   if(!isset($cfg['linkers'][$m[3]]))continue;
   $fn=$cfg['linkers'][$m[3]];
   $groups[$fn][]=['kind'=>strtolower($m[1]),'id'=>(int)$m[2],'nativeTarget'=>(int)$m[4],
     'targetLinker'=>$m[5],'ownerLinker'=>$m[3]];
  }
 }
 $checks++;
 if($foreign && $build!=='v432')$errors[]="$build unknown genuine original linker objects $foreign";
 $detail=['outside_known_Core_Engine_original_linker_objects'=>$foreign];
 foreach($cfg['expected'] as $fn=>$expected){
  $file=$root.$cfg['dir'].$fn;$pkg=new P($file);$ex=$pkg->getExports();
  $idMap=$nativeOwners[$fn]??[];$ck(count($idMap)===$expected,"$build $fn original loaded UStruct coverage");
  $cache=[];$get=static function(int $idx)use($pkg,$file,$ex,$cfg,&$cache):array{
   if(isset($cache[$idx]))return $cache[$idx];
   if(!isset($ex[$idx]))throw new RuntimeException('genuine original export absent '.$idx);
   if((int)$ex[$idx]['classIndex']===0)
    return $cache[$idx]=C::inspect($file,$pkg,$idx,false,$cfg['profile']);
   return $cache[$idx]=N::inspect($file,$pkg,$idx,
     T::inspect($file,$pkg,$idx,$cfg['profile']),$cfg['profile']);
  };
  $first=[];$actualNulls=0;$typedNames=[];
  foreach($idMap as $id=>$obj){
   try{$v=$get($id);}
   catch(Throwable $e){$errors[]="$build $fn $id ".$e->getMessage();continue;}
   $type=(int)$ex[$id]['classIndex']===0?'Class':$v['class_name'];
   $typedNames[$type]=($typedNames[$type]??0)+1;
   foreach(['child'=>'children_reference','text'=>'script_text_reference',
     'super'=>'super_field_reference','next'=>'next_field_reference'] as $kind=>$field){
    $archive=$v[$field]??null;$pointer=$obj[$kind];
    $okay=is_int($archive)&&(($pointer===0)===($archive===0));
    $ck($okay,"$build $fn $id $kind original loaded nullness=".$pointer." saved=".json_encode($archive));
    if($pointer===0)$actualNulls++;
   }
  }
  $matchedPtr=0;$cross=0;$totalTargets=0;
  foreach($groups[$fn]??[] as $target){
   $totalTargets++;$id=$target['id'];$type=$target['kind'];
   $field=match($type){
    'child'=>'children_reference','text'=>'script_text_reference',
    'super'=>'super_field_reference','next'=>'next_field_reference'};
   try{$v=$get($id);}catch(Throwable $e){$errors[]="$build $fn native pointer owner $id ".$e->getMessage();continue;}
   $archive=$v[$field]??null;
   $same=$target['targetLinker']===$target['ownerLinker'];
   $correct=is_int($archive)&&($same
      ? $archive===$target['nativeTarget']+1
      : $archive<0);
   $ck($correct,"$build $fn $id $type physical pointer targetIdx=".$target['nativeTarget'].
      " sameLinker=".($same?'1':'0')." signedSource=".json_encode($archive));
   if($same){
     $ck(isset($ex[$target['nativeTarget']]),"$build $fn actual linked target exists #".$target['nativeTarget']);
   }else{$cross++;}
   if($correct){$matchedPtr++;$matches++;}
  }
  $detail[$fn]=['source_original_package_version'=>$pkg->getHeader()['version'],
    'actual_original_loaded_UStruct_callbacks'=>count($idMap),
    'saved_native_nullness_members_checked'=>count($idMap)*4,
    'true_runtime_nonnull_UObject_pointers'=>$totalTargets,
    'matched_original_signed_target_ids'=>$matchedPtr,'cross_linker_imports'=>$cross,
    'object_type_distribution'=>$typedNames];
 }
 $result[$build]=$detail;
}
$ck($matches>5000,'combined real original UT99 pointer target identities sizeable');
echo json_encode(['ok'=>count($errors)===0,'checks'=>$checks,
 'exact_original_runtime_pointer_target_matches'=>$matches,
 'real_original_native_compiled_source_profile_differential'=>$result,
 'errors'=>$errors,'frozen_T075_fully_closed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
