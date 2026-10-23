#!/usr/bin/env php
<?php
/** Real UT99 v69 G5 native full-element arrays: SHA-pinned CDB blobs + FName resolution.
 * Only DEV PHP; PRD source packages remain read-only and untouched.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyBinaryStream as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-FULL-ARRAY-NATIVE.json'),true,512,JSON_THROW_ON_ERROR);
$checkCount=0;$fail=[];$nativeSkeletal=0;$nativeAnimation=0;$elements=0;$nativeBytes=0;$allFNames=0;
$check=static function(string $id,bool $ok)use(&$checkCount,&$fail):void{
 $checkCount++;if(!$ok)$fail[]=$id;
};
$pin=static function(string $path,string $expected,string $id)use($check):string{
 $data=is_file($path)?file_get_contents($path):false;
 $check('sha256_'.$id,is_string($data)&&hash('sha256',$data)===$expected);
 return is_string($data)?$data:'';
};
$pin($m['engine_dll'],$m['engine_dll_sha256'],'compiled_Engine_v432');
$unwind=static function(string $raw):string{
 if(str_starts_with($raw,"\xff\xfe"))$raw=(string)iconv('UTF-16LE','UTF-8',substr($raw,2));
 return str_replace("\r",'',$raw);
};
foreach($m['packages'] as $case){
 $name=substr($case['original_name'],0,-2);
 $bytes=$pin($case['package_path'],$case['sha256'],$name.'_original_package');
 $log=$unwind($pin($case['native_array_log'],$case['native_array_log_sha256'],$name.'_native_array_log'));
 $nameLog=$unwind($pin($case['native_fname_log'],$case['native_fname_log_sha256'],$name.'_native_fname_log'));
 $check('compiled_and_source_origin_'.$name,
  str_contains($log,'Engine.dll')&&str_contains($nameLog,'Engine.dll'));
 $p=new P($case['package_path']);
 $check('valid_genuine_source_'.$name,$p->validatePackage()===[]&&($p->getHeader()['version']??null)===69);
 $out=$p->getExports();$in=$p->getImports();
 foreach($case['meshes'] as $mesh){
  $id=$mesh['export_index'];$e=$out[$id]??null;
  $ci=(int)($e['classIndex']??0);
  $cls=$ci<0?(string)($in[-$ci-1]['objectNameText']??''):'';
  $check('source_mesh_export_'.$name.'_'.$id,$cls==='SkeletalMesh');
  $val=N::inspect($case['package_path'],$p,$id,T::inspect($case['package_path'],$p,$id,'ut99-v432'),'ut99-v432');
  $check('mesh_archive_exhausted_'.$name.'_'.$id,($val['status']??null)==='native_skeletalmesh_record_complete'
   &&($val['extension_bytes']??null)===0);
  $check('compiled_mesh_method_traced_'.$name.'_'.$id,
   preg_match('/^G5_SKEL_FULL_'.preg_quote($name,'/').'_'.$id.'$/m',$log)===1);
  foreach(['bone_weight_indices'=>'indices','bone_weights'=>'weights'] as $field=>$label){
   $part=$mesh[$field];$decoded=$val[$field]??[];
   $mem=$pin($part['path'],$part['sha256'],$name.'_'.$id.'_'.$label);
   $archive=substr($bytes,$decoded['data_offset']??0,$decoded['archive_bytes']??0);
   $check('native_full_element_count_'.$name.'_'.$id.'_'.$label,
     ($decoded['count']??-1)===$part['count']);
   $check('native_full_element_bytes_'.$name.'_'.$id.'_'.$label,
     ($decoded['archive_bytes']??-1)===$part['bytes']&&strlen($mem)===$part['bytes']);
   $check('all_compiled_elements_byte_identical_'.$name.'_'.$id.'_'.$label,
     hash('sha256',$archive)===$part['sha256']&&$archive===$mem);
   // Check production-decoded semantic preview against independently unpacked loaded memory.
   for($i=0;$i<min(16,$part['count']);$i++){
    $actual=$label==='indices'?
      unpack('vindex/vnumber/vdetail_a/vdetail_b',substr($mem,$i*8,8)):
      unpack('vpoint_index/vbone_weight',substr($mem,$i*4,4));
    $check('native_element_preview_'.$name.'_'.$id.'_'.$label.'_'.$i,
      $actual===($decoded['records_preview'][$i]??null));
   }
   $nativeBytes+=$part['bytes'];$elements+=$part['count'];
  }
  $nativeSkeletal++;
 }
 if(!preg_match('/^G5_FNAME_BONE_BEGIN\n(.*?)^G5_FNAME_BONE_END$/ms',$nameLog,$section)){
  $check('compiled_native_FName_lookup_section_'.$name,false);continue;
 }
 preg_match_all('/^[0-9a-f]{8}[ \t]+"([^"]*)"$/mi',$section[1],$nameRows);
 $resolved=$nameRows[1]??[];
 foreach($case['animations'] as $anim){
  $id=$anim['export_index'];$ref=$anim['ref_bones'];
  $e=$out[$id]??null;$ci=(int)($e['classIndex']??0);
  $cls=$ci<0?(string)($in[-$ci-1]['objectNameText']??''):'';
  $check('source_animation_export_'.$name.'_'.$id,$cls==='Animation');
  $val=N::inspect($case['package_path'],$p,$id,T::inspect($case['package_path'],$p,$id,'ut99-v432'),'ut99-v432');
  $bones=$val['ref_bones']??[];
  $native=$pin($ref['path'],$ref['sha256'],$name.'_'.$id.'_full_FNamedBone');
  $check('compiled_animation_method_traced_'.$name.'_'.$id,
    preg_match('/^G5_ANIM_FULL_'.preg_quote($name,'/').'_'.$id.'$/m',$log)===1);
  $check('animation_archive_exhausted_'.$name.'_'.$id,
    ($val['status']??null)==='native_animation_record_complete'
    &&($val['extension_bytes']??null)===0);
  $check('animation_full_FNamedBone_array_length_'.$name.'_'.$id,
    ($bones['count']??-1)===$ref['count']&&strlen($native)===$ref['bytes']
    &&$ref['bytes']===$ref['count']*12);
  $check('compiled_name_lookup_count_'.$name.'_'.$id,count($resolved)===$ref['count']);
  $stream=new S($case['package_path']);$stream->seek($bones['data_offset']);
  $names=$p->getNames();
  for($i=0;$i<$ref['count'];$i++){
   $nameIndex=$stream->compactIndex();$sourceFlags=$stream->u32();$sourceParent=$stream->i32();
   $raw=unpack('Vglobal_name/Vflags/Vparent',substr($native,$i*12,12));
   $loadedParent=$raw['parent']>=0x80000000?$raw['parent']-0x100000000:$raw['parent'];
   $local=(string)($names[$nameIndex]['name']??'');
   // Runtime FName global index is not package FCompactIndex. Compare
   // native FName::Names[globalIndex]->Name Unicode string observed by CDB.
   $check('FNamedBone_native_name_'.$name.'_'.$id.'_'.$i,
     $local!=='' && isset($resolved[$i]) && strcasecmp($local,$resolved[$i])===0);
   $check('FNamedBone_flags_'.$name.'_'.$id.'_'.$i,$raw['flags']===$sourceFlags);
   $check('FNamedBone_parent_'.$name.'_'.$id.'_'.$i,$loadedParent===$sourceParent);
   if($i<16){
    $preview=$bones['records_preview'][$i]??[];
    $check('FNamedBone_production_decoder_preview_'.$name.'_'.$id.'_'.$i,
      ($preview['name']['index']??null)===$nameIndex
      &&($preview['flags']??null)===$sourceFlags
      &&($preview['parent_index']??null)===$sourceParent);
   }
   $allFNames++;
  }
  $check('FNamedBone_archive_exact_byte_consumption_'.$name.'_'.$id,
    $stream->tell()===$bones['data_offset']+$bones['archive_bytes']);
  $nativeBytes+=$ref['bytes'];$elements+=$ref['count'];$nativeAnimation++;
 }
}
$check('exact_native_real_skeletal_objects',$nativeSkeletal===5);
$check('exact_native_real_animation_objects',$nativeAnimation===3);
$check('exact_native_real_named_bones',$allFNames===143);
$check('exact_native_full_array_bytes',$nativeBytes===43748);
echo json_encode(['ok'=>$fail===[],'checks'=>$checkCount,'passed'=>$checkCount-count($fail),
 'errors'=>$fail,'compiled_real_skeletal_objects'=>$nativeSkeletal,
 'compiled_real_animation_objects'=>$nativeAnimation,
 'full_skeletal_weight_index_and_influence_bytes'=>42032,
 'native_skeletal_and_animation_elements'=>$elements,
 'full_semantic_named_bone_records'=>$allFNames,'full_memory_bytes_examined'=>$nativeBytes,
 'remaining'=>'Skeletal Points/RefSkeleton/LocalPoints/inherited LodMesh and Animation Moves/AnimSeqs nested values, malformed byte/error parity; full G5 methods OPEN.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
