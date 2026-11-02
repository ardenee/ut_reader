#!/usr/bin/env php
<?php
/** Genuine UT99 v69 skeletal FVector full loaded-element parity; DEV only.
 * Compares 10 SHA-pinned compiled v432 CDB memory arrays against every archived
 * FVector byte and UnrealDB production-decoded semantic previews.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-VECTOR-ARRAY-NATIVE.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];$arrayCount=0;$totalBytes=0;$objects=0;
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks++;if(!$ok)$fail[]=$name;
};
$pin=static function(string $name,string $hash,string $id)use($check):string{
 $data=is_file($name)?file_get_contents($name):false;
 $check('sha256_'.$id,is_string($data)&&hash('sha256',$data)===$hash);
 return is_string($data)?$data:'';
};
$pin($m['native_engine'],$m['sha256_engine'],'compiled_UT99_Engine');
foreach($m['packages'] as $file){
 $name=substr($file['original'],0,-2);
 $original=$pin($file['local_path'],$file['sha256'],$name.'_package');
 $rawLog=$pin($file['log'],$file['log_sha256'],$name.'_CDB_native_vectors');
 $log=str_starts_with($rawLog,"\xff\xfe")
   ?(string)iconv('UTF-16LE','UTF-8',substr($rawLog,2)):$rawLog;
 $log=str_replace("\r",'',$log);
 $package=new P($file['local_path']);
 $check('original_v69_unmodified_'.$name,
   $package->validatePackage()===[] && ($package->getHeader()['version']??null)===69);
 $exports=$package->getExports();$imports=$package->getImports();
 foreach($file['meshes'] as $mesh){
  $id=$mesh['export_index'];$e=$exports[$id]??[];
  $ci=(int)($e['classIndex']??0);
  $check('native_object_is_skeletal_'.$name.'_'.$id,
   $ci<0&&strcasecmp((string)($imports[-$ci-1]['objectNameText']??''),'SkeletalMesh')===0);
  $check('native_method_breakpoint_observed_'.$name.'_'.$id,
   preg_match('/^G5_SKELETAL_VECTOR_'.preg_quote($name,'/').'_'.$id.'$/m',$log)===1);
  $tag=T::inspect($file['local_path'],$package,$id,'ut99-v432');
  $native=N::inspect($file['local_path'],$package,$id,$tag,'ut99-v432');
  $check('source_native_record_complete_'.$name.'_'.$id,
   ($tag['status']??null)==='tag_envelope_decoded'
   &&($native['status']??null)==='native_skeletalmesh_record_complete'
   &&($native['extension_bytes']??null)===0);
  foreach(['points','local_points'] as $field){
   $id2=$name.'_'.$id.'_'.$field;
   $part=$mesh[$field];$decoded=$native[$field]??[];
   $blob=$pin($part['path'],$part['sha256'],'native_memory_'.$id2);
   $archive=substr($original,$decoded['data_offset']??0,$decoded['archive_bytes']??0);
   $check('native_array_count_'.$id2,($decoded['count']??-1)===$part['count']);
   $check('native_array_byte_count_'.$id2,
     strlen($blob)===$part['bytes']
     &&($decoded['archive_bytes']??-1)===$part['bytes']
     &&$part['bytes']===$part['count']*12);
   $check('all_native_vector_elements_equal_source_'.$id2,
     $archive===$blob && hash('sha256',$archive)===$part['sha256']);
   $preview=$decoded['records_preview']??[];
   for($i=0;$i<min($part['count'],16);$i++){
    $triple=unpack('gx/gy/gz',substr($blob,$i*12,12));
    $check('vector_production_fields_'.$id2.'_'.$i,$triple===($preview[$i]??null));
   }
   $totalBytes+=$part['bytes'];$arrayCount++;
  }
  $objects++;
 }
}
$check('all_five_native_skeletal_exports',$objects===5);
$check('exactly_ten_full_native_vector_arrays',$arrayCount===10);
$check('all_225756_vector_memory_bytes',$totalBytes===$m['expected_total_bytes']&&$totalBytes===225756);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),
 'errors'=>$fail,'real_v69_skeletal_meshes'=>$objects,'loaded_arrays'=>$arrayCount,
 'compiled_native_vector_bytes_compared'=>$totalBytes,'FVector_records'=>$totalBytes/12,
 'limits'=>'Only Points and LocalPoints 10 whole arrays; bone and FNamedBone whole arrays separately G5 733/733; inherited LodMesh, RefSkeleton, animation nested arrays and malformed errors remain OPEN.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
