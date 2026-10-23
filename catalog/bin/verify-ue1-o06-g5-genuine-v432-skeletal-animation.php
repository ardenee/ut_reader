#!/usr/bin/env php
<?php
/** G5: real PRD-sourced UT99 v69 skeletal/animation loaded counts vs compiled Engine.dll.
 * All package reads/test runs on DEV. Frozen CDB UTF-16LE logs SHA-pinned.
 * Limits: not whole-array element equality, not malformed-input or full O06 acceptance.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-PRD-NATIVE-SKELETAL-ANIMATION.json'),true,512,JSON_THROW_ON_ERROR);
$checked=0;$fail=[];$found=['SkeletalMesh'=>0,'Animation'=>0];$verifiedRefs=0;$covered=[];
$check=static function(string $label,bool $ok)use(&$checked,&$fail):void{
 $checked++;if(!$ok)$fail[]=$label;
};
$checkedFile=static function(string $path,string $expected,string $id)use($check):string{
 $valid=is_file($path);
 $data=$valid?file_get_contents($path):false;
 $check('frozen_sha_'.$id,is_string($data)&&hash('sha256',$data)===$expected);
 return is_string($data)?$data:'';
};
$compiled='C:/Temp/UnrealDB/O06/Native-UCC/System/Engine.dll';
$checkedFile($compiled,$manifest['compiled_engine_sha256'],'compiled_v432_engine');
$check('source_profiles_are_not_assumed_from_version_alone',
 ($manifest['source_loader']??'')!=='' && str_contains($manifest['classification'],'AUTHENTIC'));
$decodeLog=static function(string $bytes):string{
 $s=str_starts_with($bytes,"\xff\xfe")
     ? (string)iconv('UTF-16LE','UTF-8',substr($bytes,2)) : $bytes;
 // CDB's /u logs may contain CRCRLF. Normalize text only after
 // SHA-checking the original immutable UTF-16LE byte stream.
 return str_replace("\r",'', $s);
};
$nativeSections=static function(string $log) use ($check):array {
 $chunks=preg_split('/^(G5_REAL_SKELETAL_AFTER_ARCHIVE|G5_REAL_ANIMATION_AFTER_ARCHIVE)\r?$/m',$log,-1,PREG_SPLIT_DELIM_CAPTURE);
 $result=['SkeletalMesh'=>[],'Animation'=>[]];
 for($i=1;$i<count($chunks);$i+=2){
  $type=$chunks[$i]==='G5_REAL_SKELETAL_AFTER_ARCHIVE'?'SkeletalMesh':'Animation';
  $chunk=$chunks[$i+1]??'';
  preg_match_all('/^([0-9a-f]{8})[ \t]+([0-9a-f]{8}(?:[ \t]+[0-9a-f]{8}){0,3})[ \t]*$/mi',$chunk,$matches,PREG_SET_ORDER);
  $lines=[];
  foreach($matches as $line){
   $words=preg_split('/[ \t]+/',trim($line[2]));
   $lines[]=['addr'=>hexdec($line[1]),'words'=>array_map('hexdec',$words)];
  }
  if(count($lines)<2||count($lines[0]['words'])!==1)continue;
  $base=$lines[0]['addr']-0x14;$id=$lines[0]['words'][0];$slots=[];
  foreach($lines as $line){
   foreach($line['words'] as $j=>$value){$offset=$line['addr']-$base+4*$j;$slots[$offset]=$value;}
  }
  $result[$type][$id] ??= ['fields'=>$slots,'linker_index'=>$id,'base'=>$base];
 }
 return $result;
};
$referenceSections=static function(string $log):array {
 $chunks=preg_split('/^G5_REAL_SKELETAL_ANIMATION_REF\r?$/m',$log);
 $result=[];
 foreach(array_slice($chunks?:[],1) as $chunk){
  if(!preg_match('/^([0-9a-f]{8})[ \t]+([0-9a-f]{8})[ \t]*$/mi',$chunk,$match))continue;
  $id=hexdec($match[2]);$ref=null;
  if(preg_match('/^G5_REAL_DEFAULT_ANIM_OBJECT_LINKER_INDEX\r?\n([0-9a-f]{8})[ \t]+\?{4,}$/mi',$chunk,$refMatch)){
   // Old diagnostic CDB 'dd <scalar index>' printed computed index as
   // memory address and '?' for unreadable byte data; capture index only.
   $ref=hexdec($refMatch[1]);
  }
  $result[$id]=$ref;
 }
 return $result;
};
foreach($manifest['packages'] as $entry){
 $name=$entry['original'];$path=$entry['path'];
 $checkedFile($path,$entry['sha256'],$name);
 $pkg=new P($path);
 $check('package_valid_'.$name,$pkg->validatePackage()===[]);
 $check('genuine_v69_'.$name,($pkg->getHeader()['version']??null)===69);
 $ex=$pkg->getExports();$im=$pkg->getImports();
 $native=['SkeletalMesh'=>[],'Animation'=>[]];$refs=[];
 if(isset($entry['cdb_sha256'])){
  $logs=$checkedFile($entry['cdb_log'],$entry['cdb_sha256'],"$name-CDB");
  $native=$nativeSections($decodeLog($logs));
  $refbytes=$checkedFile($entry['reference_log'],$entry['reference_sha256'],"$name-CDB-refs");
  $refs=$referenceSections($decodeLog($refbytes));
  $check('compiled_method_execution_'.$name,
     str_contains($decodeLog($logs),'G5_REAL_SKELETAL_AFTER_ARCHIVE')
     &&str_contains($decodeLog($logs),'G5_REAL_ANIMATION_AFTER_ARCHIVE'));
 } else {
  $check('explicit_native_unavailable_'.$name,
    ($entry['native_oracle']??'')==='BLOCKED_DEPENDENCY_CHAIN_AT_k_ChimpTex');
 }
 foreach(['SkeletalMesh'=>'skeletal_exports','Animation'=>'animation_exports'] as $type=>$key){
  foreach($entry[$key] as $id){
   $record=$ex[$id]??null;
   $check("export_index_exists_$name-$id",is_array($record));
   if(!is_array($record))continue;
   $ci=(int)$record['classIndex'];
   $class=$ci<0?(string)($im[-$ci-1]['objectNameText']??''):
      ($ci>0?(string)($ex[$ci-1]['objectNameText']??''):'Class');
   $check("export_class_$name-$id",strcasecmp($class,$type)===0);
   try{
    $tag=T::inspect($path,$pkg,(int)$id,'ut99-v432');
    $val=N::inspect($path,$pkg,(int)$id,$tag,'ut99-v432');
   }catch(Throwable $e){$check("archive_parse_$name-$id",false);continue;}
   $check("archive_status_$name-$id",
     ($tag['status']??'')==='tag_envelope_decoded'
     &&($val['status']??'')==='native_'.strtolower($type).'_record_complete'
     &&($val['extension_bytes']??-1)===0
     &&($val['archive_bytes_consumed']??-1)===($tag['native_tail_bytes']??null));
   if(!isset($entry['cdb_sha256']))continue; // Current genuine SFCharacter4 is a native loader BLOCKER.
   $fields=$native[$type][$id]['fields']??null;
   $check("native_loaded_export_$name-$id",is_array($fields));
   if(!is_array($fields))continue;
   $expected=$type==='Animation'
     ?['ref_bones'=>0x2c,'moves'=>0x38,'animation_sequences'=>0x44]
     :['extended_wedges'=>0x1b4,'points'=>0x1c0,'reference_skeleton'=>0x1cc,
       'bone_weight_indices'=>0x1d8,'bone_weights'=>0x1e4,'local_points'=>0x1f0];
   foreach($expected as $field=>$offset){
    $exp=$val[$field]['count']??null;
    $check("native_loaded_count_$name-$id-$field",is_int($exp)&&($fields[$offset]??null)===$exp);
    // Compiled TArray Num must be bounded by live Max.
    $check("native_loaded_capacity_$name-$id-$field",
      isset($fields[$offset],$fields[$offset+4])
      &&$fields[$offset+4]>=$fields[$offset]);
   }
   if($type==='SkeletalMesh'){
    $weapon=$val['weapon_bone_index']??null;$raw=$fields[0x1f8]??null;
    $nativeSigned=is_int($raw)&&$raw>=0x80000000?$raw-0x100000000:$raw;
    $check("native_weapon_bone_index_$name-$id",$nativeSigned===$weapon);
    $ref=$val['default_animation_reference']??null;
    if(is_int($ref)&&$ref>0){
     $check("native_default_animation_export_reference_$name-$id",
       ($refs[$id]??null)===$ref-1);
     $verifiedRefs++;
    }
   }
   $found[$type]++;
   $covered[]=['package'=>$name,'class'=>$type,'index'=>$id,
      'object'=>$record['objectNameText'],'native_loaded'=>true];
  }
 }
}
$check('required_loaded_skeletal_count',$found['SkeletalMesh']===5);
$check('required_loaded_animation_count',$found['Animation']===3);
$check('required_loaded_default_animation_references',$verifiedRefs===3);
echo json_encode(['ok'=>$fail===[],'checks'=>$checked,
 'passed'=>$checked-count($fail),'errors'=>$fail,
 'genuine_exports_decoded'=>10,'native_loaded_value_slices'=>$found,
 'native_positive_animation_refs_checked'=>$verifiedRefs,
 'covered'=>$covered,
 'limits'=>'Three genuine v69 UT99 packages, 8 native object loaded-count/reference slices; whole arrays, native errors, and SFCharacter4 remain open. No O06 full-method signoff.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
