#!/usr/bin/env php
<?php
/**
 * UE1 O06: enumerate all relevant serializers for ONE unchanged genuine
 * package using ONE CatalogUE1PackageReader instance.
 * No PHP mock of Epic semantics: evidence discovery only.
 * stdout: one JSON observation with native classes and exact export indices.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
if($argc!==3){fwrite(STDERR,"Usage: php scan-ue1-o06-g5-package-methods.php FILE NAME\n");exit(2);}
[$cmd,$path,$key]=$argv;
try{
 if(!is_file($path)){throw new RuntimeException('Missing original package '.$path);}
 $reader=new CatalogUE1PackageReader($path);
 $issues=$reader->validatePackage();
 if($issues!==[]){throw new RuntimeException('Invalid original package '.json_encode($issues));}
 $header=$reader->getHeader();
 $version=(int)($header['version']??0);
 $imports=$reader->getImports();
 $exports=$reader->getExports();
 $methods=[];
 $add=static function(string $method,int $idx)use(&$methods):void{
  if(!isset($methods[$method]))$methods[$method]=['count'=>0,'sample_indices'=>[]];
  $methods[$method]['count']++;
  if(count($methods[$method]['sample_indices'])<12)
   $methods[$method]['sample_indices'][]=$idx;
 };
 $classCounts=[];$seen=0;$payloadTargets=[];$stackTargets=[];
 foreach($exports as $e){
  $idx=(int)($e['index']??$seen);$seen++;
  $ci=(int)($e['classIndex']??0);
  $class=$ci<0?(string)($imports[-$ci-1]['objectNameText']??''):
   ($ci>0?(string)($exports[$ci-1]['objectNameText']??''):'Class');
  if($class===''){$class='UnresolvedClassIndex';}
  $classCounts[$class]=($classCounts[$class]??0)+1;
  if(((int)($e['objectFlags']??0)&0x02000000)!==0
      &&(int)($e['serialSize']??0)>0)$stackTargets[]=$idx;
  // Each serialized ordinary object runs this transitive base serializer.
  $add('T99:UObject::Serialize',$idx);
  if($ci===0){$add('T99:UClass::Serialize',$idx);continue;}
  $direct=[
   'textbuffer'=>'UTextBuffer', 'font'=>'UFont',
   'skeletalmesh'=>'USkeletalMesh','animation'=>'UAnimation',
   'sound'=>'USound','music'=>'UMusic',
   'texture'=>'UTexture','palette'=>'UPalette',
   'mesh'=>'UMesh','lodmesh'=>'ULodMesh',
   'model'=>'UModel','level'=>'ULevel','const'=>'UConst',
   'enum'=>'UEnum','package'=>'UPackage'
  ][strtolower($class)]??null;
  if($direct!==null){
   $add('T99:'.$direct.'::Serialize',$idx);
   if(in_array($direct,['UTextBuffer','UFont','USkeletalMesh',
       'UAnimation','USound','UMusic'],true) && (int)($e['serialSize']??0)>0){
    $payloadTargets[]=['index'=>$idx,'method'=>'T99:'.$direct.'::Serialize'];
   }
  }
 }
 // Reuse ONE package instance, its cached Import/Export/Name maps and one
 // worker process to inspect selected production native payloads across
 // all six method types. Each export's bounded byte range is read by
 // the existing reader; do NOT implement a second parser or mock Epic.
 $production=[];$payloadBytes=0;$errors=[];
 foreach($payloadTargets as $item){
  $id=$item['index'];$method=$item['method'];
  if(!isset($production[$method]))$production[$method]=
   ['attempted'=>0,'complete'=>0,'partial'=>0,'errors'=>0,'archive_bytes'=>0];
  $production[$method]['attempted']++;
  try{
   $tags=Tag::inspect($path,$reader,$id,'ut99-v432');
   $native=Native::inspect($path,$reader,$id,$tags,'ut99-v432');
   $serial=(int)($exports[$id]['serialSize']??0);
   $production[$method]['archive_bytes']+=$serial;$payloadBytes+=$serial;
   if(str_ends_with((string)($native['status']??''),'_complete')
       &&(int)($native['extension_bytes']??0)===0){
    $production[$method]['complete']++;
   }else{$production[$method]['partial']++;}
  }catch(Throwable $err){
   $production[$method]['errors']++;
   if(count($errors)<16)$errors[]=['index'=>$id,'method'=>$method,
     'reason'=>$err->getMessage()];
  }
 }
 // Same cached package context: decode saved UObject execution frames
 // once, not once per derived class or source method.
 $frames=['flagged'=>count($stackTargets),'decoded'=>0,'errors'=>0,
   'source_frame_bytes'=>0,'tagged_properties'=>0,'example_indices'=>[]];
 $frameErrors=[];
 foreach($stackTargets as $id){
  try{
   $tag=Tag::inspect($path,$reader,$id,'ut99-v432');
   $frame=$tag['serialized_state_frame']??null;
   if(($tag['status']??null)!=='tag_envelope_decoded'
      ||!is_array($frame)||$frame['has_stack_object_flag']!==true
      ||(int)$frame['serialized_size']<=0){
    throw new RuntimeException('Failed to decode source-defined RF_HasStack StateFrame');
   }
   $frames['decoded']++;
   $frames['source_frame_bytes']+=(int)$frame['serialized_size'];
   $frames['tagged_properties']+=count($tag['tags']??[]);
   if(count($frames['example_indices'])<12)$frames['example_indices'][]=$id;
  }catch(Throwable $exc){
   $frames['errors']++;
   if(count($frameErrors)<12)$frameErrors[]=['index'=>$id,'reason'=>$exc->getMessage()];
  }
 }
 ksort($methods);ksort($production);arsort($classCounts);
 $result=[
  'key'=>$key,'path'=>$path,'sha256'=>hash_file('sha256',$path),
  'size_bytes'=>(int)filesize($path),
  'archive_version'=>$version,'licensee_version'=>(int)($header['licenseeVersion']??0),
  'source_profile'=>'ut99-v432',
  'valid_package'=>true,'export_count'=>count($exports),'import_count'=>count($imports),
  'class_counts_top'=>array_slice($classCounts,0,18,true),
  'methods'=>$methods,
  'production_native_payloads'=>$production,
  'source_payload_bytes_inspected'=>$payloadBytes,
  'source_payload_errors'=>$errors,
  'source_uobject_state_frames'=>$frames,
  'source_stateframe_errors'=>$frameErrors,
  'discovery_only'=>false,
  'reader_instance_count'=>1,
  'note'=>'One package metadata pass plus one shared production-reader payload traversal for six serializer types. Individual bounded export ranges are read as necessary; not a compiled-engine oracle. UObject/UClass mappings are candidates, not independent runtime calls.'
 ];
 echo json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
}catch(Throwable $e){
 fwrite(STDERR,get_class($e).': '.$e->getMessage().PHP_EOL);exit(1);
}
