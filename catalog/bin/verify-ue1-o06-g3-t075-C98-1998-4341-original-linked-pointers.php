<?php
/** Genuine May 1998 Core/Engine original native pointer target identities, not mere nullness. */
declare(strict_types=1);
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
$log=(string)file_get_contents($repo.'/'.$proof['pins']['1998_original_nonnull_linked_pointer_target_log']['path']);
$observed=[];
foreach(preg_split('/\r\n|\r|\n/',$log) as $line){
 if(preg_match('/^T075_C98_(SUPER|NEXT|CHILD|SCRIPTTEXT) owner=(\d+) linker=([0-9a-f]{8}) targetIdx=(\d+) targetLinker=([0-9a-f]{8})$/i',$line,$m)){
  $observed[$m[3]][]=['kind'=>$m[1],'owner'=>(int)$m[2],'target'=>(int)$m[4],'linker'=>$m[3],'targetLinker'=>$m[5]];
 }
}
$expected=['Core.u'=>['super'=>3,'next'=>333,'child'=>121,'scripttext'=>2],
           'Engine.u'=>['super'=>370,'next'=>2714,'child'=>729,'scripttext'=>69]];
$counts=[];$checks=0;$errors=[];$samples=[];$nullLinkers=[];$crossLinks=[];$perFile=[];
$check=static function(bool $result,string $text)use(&$checks,&$errors):void{
 ++$checks;if(!$result&&count($errors)<50)$errors[]=$text;
};
foreach($expected as $file=>$kinds){
 $path=dirname($proof['pins']['1998_original_Core_u']['path']).'/'.$file;
 $pkg=new P($path);$exp=$pkg->getExports();
 $total=array_sum($kinds);$linker=null;
 foreach($observed as $key=>$arr){if(count($arr)===$total)$linker=$key;}
 $check($linker!==null,$file.' actual original linker count '. $total);
 if($linker===null)continue;
 $cache=[];
 $get=static function(int $id)use(&$cache,$exp,$path,$pkg):array{
   if(array_key_exists($id,$cache))return $cache[$id];
   if(!isset($exp[$id]))throw new RuntimeException('missing original exported field '.$id);
   if((int)$exp[$id]['classIndex']===0)
    return $cache[$id]=C::inspect($path,$pkg,$id,false,'epic-ue1');
   return $cache[$id]=N::inspect($path,$pkg,$id,T::inspect($path,$pkg,$id,'epic-ue1'),'epic-ue1');
 };
 $seen=[];$perKind=[];$correct=0;
 foreach($observed[$linker] as $entry){
  $type=strtolower($entry['kind']);$id=$entry['owner'];
  $seen[$type][$id]=($seen[$type][$id]??0)+1;
  $perKind[$type]=($perKind[$type]??0)+1;
  try{$rec=$get($id);}catch(Throwable $e){if(count($errors)<50)$errors[]="$file $id ".$e->getMessage();continue;}
  $field=match($type){
   'super'=>'super_field_reference','next'=>'next_field_reference',
   'child'=>'children_reference','scripttext'=>'script_text_reference'};
  $saved=$rec[$field]??null;
  $same=$entry['targetLinker']===$linker;
  $valid=is_int($saved)
    &&($same?($saved===$entry['target']+1):($saved<0));
  $check($valid,"$file original native $type field=$field owner=$id targetIdx=".$entry['target'].' trueLinker='.$entry['targetLinker'].' saved='.json_encode($saved));
  if($same)$check(isset($exp[$entry['target']]),"$file native $type physical target exists idx=".$entry['target']);
  else{$crossLinks[$file][$type]=($crossLinks[$file][$type]??0)+1;}
  if($valid)$correct++;
  elseif(count($samples)<14)$samples[]=['file'=>$file,'type'=>$type,'owner'=>$id,'target'=>$entry['target'],'saved'=>$saved,'target_linker'=>$entry['targetLinker'],'owner_linker'=>$linker];
 }
 foreach($kinds as $kind=>$cnt){
  $check(($perKind[$kind]??0)===$cnt,"$file original pointer callbacks $kind count");
  $dupes=array_filter($seen[$kind]??[],static fn($n)=>$n!==1);
  $check($dupes===[],"$file unique $kind original pointer objects");
 }
 $check($correct===$total,"$file all pointer targets are correct saved signed identities");
 $perFile[$file]=['native_source_linker'=>$linker,'direct_source_exports'=>count($exp),
  'real_original_pointer_callbacks'=>$perKind,'total_true_native_pointers'=>$total,
  'exact_linked_identity_matches'=>$correct,'cross_linker_pointer_events'=>$crossLinks[$file]??[]];
}
$check(count($observed)===2,'only original 1998 Core and Engine native linker pointers');
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'actual_original_source_pointer_identities'=>$perFile,
  'mismatches'=>$samples,'errors'=>$errors,'original_native_pointer_targets_count'=>4341,
  'complete_T075'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
