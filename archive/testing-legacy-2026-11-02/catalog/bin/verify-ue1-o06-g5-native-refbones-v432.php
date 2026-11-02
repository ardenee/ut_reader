#!/usr/bin/env php
<?php
/** Focused genuine native v432 FMeshBone elements vs production reader, DEV only. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyBinaryStream as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-NATIVE-RESUMABLE-BATCH.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$failed=[];$bones=0;$objects=0;$bytesCompared=0;$nativeUninit=0;
$check=static function(string $id,bool $ok)use(&$checks,&$failed):void{ $checks++;if(!$ok)$failed[]=$id; };
$pin=static function(string $file,string $sha,string $id)use($check):string{
 $data=is_file($file)?file_get_contents($file):false;
 $check('SHA256_'.$id,is_string($data)&&hash('sha256',$data)===$sha);
 return is_string($data)?$data:'';
};
$decode=static function(string $v):string{
 $s=str_starts_with($v,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($v,2)):$v;
 return str_replace("\r",'',$s);
};
$pin($m['native_engine_path'],$m['engine_sha256'],'Engine_v432');
foreach($m['cases'] as $c){
 $name=$c['name'];$archive=$c['archive'];
 $pin($archive,$c['archive_sha256'],'original_'.$name);
 $source=new P($archive);$sourceNames=$source->getNames();
 $check('genuine_v69_and_structural_'.$name,
   $source->validatePackage()===[]&&($source->getHeader()['version']??null)===69);
 $log=$decode($pin($c['refLog'],$c['refLog_sha256'],'compiled_CDB_'.$name));
 foreach($c['refs'] as $ref){
  $id=$ref['index'];$nameID=$name.'_'.$id;$mem=$pin($ref['path'],$ref['sha256'],'native_refbone_'.$nameID);
  $check('compiled_UObject_linker_and_method_'.$nameID,
    preg_match('/^G5_REF_OBJECT_'.preg_quote($name,'/').'_'.$id.'$/m',$log)===1);
  $begin='G5_REF_NAMES_BEGIN_'.$name.'_'.$id;$end='G5_REF_NAMES_END_'.$name.'_'.$id;
  $ok=preg_match('/^'.preg_quote($begin,'/').'\n(.*?)^'.preg_quote($end,'/').'$/ms',$log,$part)===1;
  $check('native_FName_table_capture_'.$nameID,$ok);
  preg_match_all('/^[0-9a-f]{8}[ \t]+"([^"]*)"$/mi',$part[1]??'',$names);
  $globalNames=$names[1]??[];
  $value=N::inspect($archive,$source,$id,T::inspect($archive,$source,$id,'ut99-v432'),'ut99-v432');
  $array=$value['reference_skeleton'];
  $check('native_array_complete_'.$nameID,
    $value['status']==='native_skeletalmesh_record_complete'
    &&$value['extension_bytes']===0
    &&$array['count']===$ref['count']);
  $check('native_array_size_'.$nameID,strlen($mem)===$ref['count']*64);
  $check('native_resolved_names_count_'.$nameID,count($globalNames)===$ref['count']);
  $stream=new S($archive);$stream->seek($array['data_offset']);
  for($i=0;$i<$ref['count'];$i++){
   $packageNameID=$stream->compactIndex();
   $flags=$stream->bytes(4);$joint=$stream->bytes(44);
   $children=$stream->bytes(4);$parent=$stream->bytes(4);
   $native=substr($mem,$i*64,64);
   $sourceName=(string)($sourceNames[$packageNameID]['name']??'');
   $id2=$nameID.'_bone'.$i;
   $check('native_bone_name_'.$id2,
     $sourceName!=='' && isset($globalNames[$i])
     &&strcasecmp($sourceName,$globalNames[$i])===0);
   $check('native_bone_flags_'.$id2,$flags===substr($native,4,4));
   $check('native_quat_position_length_'.$id2,substr($joint,0,32)===substr($native,8,32));
   // Epic's v432 VJointPos::operator<< serializes XSize twice, with
   // second read overwriting XSize. YSize and Depth are NOT serialized.
   $check('native_xsize_second_serialized_value_'.$id2,substr($joint,36,4)===substr($native,40,4));
   $check('native_zsize_'.$id2,substr($joint,40,4)===substr($native,48,4));
   $check('native_parent_'.$id2,$parent===substr($native,52,4));
   $check('native_children_'.$id2,$children===substr($native,56,4));
   if(substr($native,44,4)==="\x0d\xf0\xad\xba"
     &&substr($native,60,4)==="\x0d\xf0\xad\xba")$nativeUninit++;
   if($i<16){
    $p=$array['records_preview'][$i]??[];
    $check('production_preview_'.$id2,
      ($p['name']['index']??null)===$packageNameID
      &&($p['flags']??null)===unpack('V',$flags)[1]
      &&($p['num_children']??null)===(($v=unpack('V',$children)[1])>=0x80000000?$v-0x100000000:$v)
      &&($p['parent_index']??null)===(($v=unpack('V',$parent)[1])>=0x80000000?$v-0x100000000:$v));
   }
   $bones++;$bytesCompared+=64;
  }
  $check('complete_source_array_consumption_'.$nameID,
    $stream->tell()===$array['data_offset']+$array['archive_bytes']);
  $objects++;
 }
}
$check('five_original_skeletal_exports',$objects===5);
$check('all_native_FMeshBone_records',$bones===237);
$check('all_full_memory_FMeshBone_bytes',$bytesCompared===15168);
echo json_encode(['ok'=>$failed===[],'checks'=>$checks,'passed'=>$checks-count($failed),
 'failures'=>$failed,'genuine_loaded_meshes'=>$objects,
 'resolved_full_FMeshBone_records'=>$bones,'runtime_FMeshBone_bytes_compared'=>$bytesCompared,
 'native_debug_fill_y_and_depth_instances'=>$nativeUninit,
 'exclusion'=>'YSize and Depth are uninitialized native members and cannot be asserted from archive; first archived XSize is overwritten by second, only second compares to compiled native XSize.',
 'remaining'=>'Inherited ULodMesh and other skeletal fields, nested animation Moves/other payloads, native malformed errors; G5 method OPEN.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
