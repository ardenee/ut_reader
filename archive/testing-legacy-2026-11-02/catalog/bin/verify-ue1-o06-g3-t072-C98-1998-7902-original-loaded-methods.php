<?php
/** Original May 1998 C98 genuine Core.u + Engine.u loaded-value differential, new scope only. */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

$repo=dirname(__DIR__,2);
$proof=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T072-T075-original-C98-1998-Core-Engine-compiled-full-cohort-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($proof['pins'] as $name=>$pin){
    if(str_ends_with($name,'_verifier'))continue;
    $source=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
    $valid=is_file($source)&&filesize($source)===$pin['bytes']
      &&hash_file('sha256',$source)===$pin['sha256'];
    $gitAlt=isset($pin['canonical_git_blob_sha256'])&&is_file($source)
      &&filesize($source)===$pin['canonical_git_blob_bytes']
      &&hash_file('sha256',$source)===$pin['canonical_git_blob_sha256'];
    if(!$valid&&!$gitAlt)throw new RuntimeException('1998 C98 original native/source SHA pin changed: '.$name);
}
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$raw=(string)file_get_contents($repo.'/'.$proof['pins']['1998_original_Field_Property_Struct_true_loaded_values_log']['path']);
$rows=['FIELD'=>[],'PROPERTY'=>[],'STRUCT'=>[]];
foreach(preg_split('/\r\n|\n|\r/',$raw) as $line){
 if(preg_match('/^T072_C98_NEW_FIELD linker=([0-9a-f]{8}) idx=(\d+) super=([0-9a-f]{8}) next=([0-9a-f]{8})$/i',$line,$m)){
    $rows['FIELD'][$m[1]][]=['id'=>(int)$m[2],'super'=>hexdec($m[3]),'next'=>hexdec($m[4])];
 }elseif(preg_match('/^T072_C98_NEW_PROPERTY linker=([0-9a-f]{8}) idx=(\d+) dim=(-?\d+) flags=([0-9a-f]{8})$/i',$line,$m)){
    $rows['PROPERTY'][$m[1]][]=['id'=>(int)$m[2],'dim'=>(int)$m[3],'flags'=>hexdec($m[4])];
 }elseif(preg_match('/^T072_C98_NEW_STRUCT linker=([0-9a-f]{8}) idx=(\d+) child=([0-9a-f]{8}) scripttext=([0-9a-f]{8}) line=(-?\d+) textpos=(-?\d+) scriptnum=(\d+)$/i',$line,$m)){
    $rows['STRUCT'][$m[1]][]=['id'=>(int)$m[2],'child'=>hexdec($m[3]),'scripttext'=>hexdec($m[4]),
      'line'=>(int)$m[5],'textpos'=>(int)$m[6],'scriptnum'=>(int)$m[7]];
 }
}
$checks=0;$errors=[];$samples=[];$stats=[];$matched=['FIELD'=>0,'PROPERTY'=>0,'STRUCT'=>0];
$check=static function(bool $ok,string $title)use(&$checks,&$errors):void{
 $checks++;if(!$ok&&count($errors)<35)$errors[]=$title;
};
$root=dirname($proof['pins']['1998_original_Core_u']['path']).'/';
$expected=['Core.u'=>['FIELD'=>456,'PROPERTY'=>327,'STRUCT'=>126],
           'Engine.u'=>['FIELD'=>3512,'PROPERTY'=>2493,'STRUCT'=>988]];
foreach($expected as $fn=>$counts){
 $file=$root.$fn;$pkg=new P($file);$exps=$pkg->getExports();$h=$pkg->getHeader();
 $check($h['version']===61,'genuine May98 C98 original v61 '.$fn);
 $cache=[];
 $get=static function(int $id)use(&$cache,$exps,$file,$pkg):array{
  if(isset($cache[$id]))return $cache[$id];
  $e=$exps[$id]??null;
  if(!is_array($e))throw new RuntimeException('No real export '.$id);
  if((int)$e['classIndex']===0)return $cache[$id]=C::inspect($file,$pkg,$id,false,'epic-ue1');
  return $cache[$id]=N::inspect($file,$pkg,$id,T::inspect($file,$pkg,$id,'epic-ue1'),'epic-ue1');
 };
 $bykind=[];$nonzero=['child'=>0,'scripttext'=>0,'super'=>0,'next'=>0];$observed=[];$scriptvalues=0;
 foreach(['FIELD','PROPERTY','STRUCT'] as $kind){
  $linker=null;
  foreach($rows[$kind] as $address=>$candidates){
   if(count($candidates)===$counts[$kind])$linker=$address;
  }
  $check($linker!==null,'exact original compiled '.$fn.' '.$kind.' distinct linker counts');
  if($linker===null)continue;
  $seen=[];$match=0;$unavail=0;
  foreach($rows[$kind][$linker] as $n){
   $id=$n['id'];$check(!isset($seen[$id]),"$fn $kind unique linker export $id");
   $seen[$id]=true;
   try{$rec=$get($id);}catch(Throwable $err){$unavail++;if(count($errors)<35)$errors[]="$fn $kind $id inspect: ".$err->getMessage();continue;}
   $ok=true;
   if($kind==='FIELD'){
    foreach(['super'=>'super_field_reference','next'=>'next_field_reference'] as $native=>$field){
     $saved=$rec[$field]??null;

     // Rewritten explicitly below to avoid mixed chained equality.
     $correct=is_int($saved) && (($n[$native]===0)===($saved===0));
     $check($correct,"$fn FIELD $id $field real native pointer nullness");
     if($n[$native]!==0)$nonzero[$native]++;
     $ok=$ok&&$correct;
     if(!$correct&&count($samples)<18)$samples[]=['file'=>$fn,'type'=>$kind,'idx'=>$id,'field'=>$field,'native'=>$n[$native],'saved'=>$saved];
    }
   }elseif($kind==='PROPERTY'){
    foreach(['dim'=>'array_dim','flags'=>'property_flags'] as $native=>$field){
      $saved=$rec[$field]??null;
      $correct=$saved===$n[$native];
      $check($correct,"$fn PROPERTY $id ".$field." native exact integer");
      $ok=$ok&&$correct;
      if(!$correct&&count($samples)<18)$samples[]=['file'=>$fn,'type'=>$kind,'idx'=>$id,'field'=>$field,'native'=>$n[$native],'saved'=>$saved];
    }
   }else{
    foreach(['line'=>'source_line','textpos'=>'source_text_pos','scriptnum'=>'script_size'] as $native=>$field){
      $saved=$rec[$field]??null;$correct=$saved===$n[$native];
      $check($correct,"$fn STRUCT $id ".$field." native exact");
      $ok=$ok&&$correct;
      if(!$correct&&count($samples)<18)$samples[]=['file'=>$fn,'type'=>$kind,'idx'=>$id,'field'=>$field,'native'=>$n[$native],'saved'=>$saved];
    }
    foreach(['child'=>'children_reference','scripttext'=>'script_text_reference'] as $native=>$field){
      $saved=$rec[$field]??null;
      $correct=is_int($saved)&&(($n[$native]===0)===($saved===0));
      $check($correct,"$fn STRUCT $id $field real original pointer nullness");
      if($n[$native]!==0)$nonzero[$native]++;
      $ok=$ok&&$correct;
      if(!$correct&&count($samples)<18)$samples[]=['file'=>$fn,'type'=>$kind,'idx'=>$id,'field'=>$field,'native'=>$n[$native],'saved'=>$saved];
    }
    if($n['line']!==0)$scriptvalues++;
   }
   if($ok){$matched[$kind]++;$match++;}
  }
  $check(count($seen)===$counts[$kind],"$fn $kind exact original compiled object count");
  $bykind[$kind]=['linker'=>$linker,'expected'=>$counts[$kind],'unique'=>count($seen),'exact_matching_loaded_native_records'=>$match,'source_reader_exceptions'=>$unavail];
 }
 $stats[$fn]=['archive_exports'=>count($exps),'original_native_method_records'=>$bykind,
               'non_null_native_member_pointers'=>$nonzero,
               'native_struct_nonzero_line'=>$scriptvalues];
}
$check($matched['FIELD']===3968,'all original compiled C98 UField loaded recorded fields match');
$check($matched['PROPERTY']===2820,'all original compiled C98 UProperty loaded fields match');
$check($matched['STRUCT']===1114,'all original compiled C98 UStruct loaded fields match');
echo json_encode(['ok'=>$errors===[],'new_original_native_C98_1998_scope'=>'Core.u+Engine.u original fully loaded',
 'checks'=>$checks,'matched_method_records'=>$matched,
 'source_original_loaded_details'=>$stats,'mismatches'=>$samples,'errors'=>$errors,
 'complete_T072'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
