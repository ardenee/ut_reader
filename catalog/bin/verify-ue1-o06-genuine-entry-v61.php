#!/usr/bin/env php
<?php
/** Source-available genuine UE1 v61 Entry.unr map acceptance. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$path='L:\Source\Engine\UE1\Engine\Maps\Entry.unr';
$expectedHash='2958f568f300dc2f282bb498dc74a3c3a6c4151eee9a71ff8dcacbc8626521e8';
$checks=[];$failed=[];$nativeStatuses=[];$failures=[];
$check=static function(string $label,bool $ok)use(&$checks,&$failed):void{
 $checks[$label]=$ok;if(!$ok)$failed[]=$label;
};
if(!is_file($path)){throw new RuntimeException('Genuine UE1 Entry.unr unavailable');}
$check('pinned_genuine_unmodified_sha256',hash_file('sha256',$path)===$expectedHash);
$pkg=new Package($path);
$check('genuine_package_has_valid_header',$pkg->validatePackage()===[]);
$header=$pkg->getHeader();
$check('genuine_version_61',(int)($header['version']??-1)===61);
$exports=$pkg->getExports();
$check('genuine_31_exports',count($exports)===31);
$classes=[];$tailBytes=0;$totalTags=0;$models=0;$specialComplete=0;
foreach($exports as $ex){
 $i=(int)$ex['index'];
 $tag=null;$native=null;$cl='unknown';
 try{
  $tag=Tags::inspect($path,$pkg,$i);
  $check('tag_envelope_export_'.$i,($tag['status']??'')==='tag_envelope_decoded');
  if(($tag['status']??'')!=='tag_envelope_decoded'){continue;}
  $totalTags+=count($tag['tags']??[]);
  $tailBytes+=(int)($tag['native_tail_bytes']??0);
  $native=Native::inspect($path,$pkg,$i,$tag);
  $cl=(string)($native['class_name']??'?');
  $classes[$cl]=($classes[$cl]??0)+1;
  $status=(string)($native['status']??'');
  $nativeStatuses[$status]=($nativeStatuses[$status]??0)+1;
  if($cl==='Model'){
   $models++;
   $check('genuine_model_'.$i.'_fully_decoded',
     $status==='native_model_record_complete'
     &&($native['extension_bytes']??-1)===0
     &&($native['model']['archive_branch']??'')==='object_referenced_legacy');
   $check('genuine_model_'.$i.'_16_byte_sphere_source_layout',
     ($native['sphere_wire_layout']??'')==='sphere_with_radius_16'
     &&($native['source_serialized_base_bytes']??0)===41
     &&array_key_exists('w',$native['bounding_sphere']??[])
     &&($native['model']['polys_reference']??-99)>0);
   foreach(['vectors'=>'Vectors','points'=>'Vectors','nodes'=>'BspNodes',
            'surfs'=>'BspSurfs','verts'=>'Verts','polys'=>'Polys'] as $field=>$expectedClass){
    $ref=(int)($native['model'][$field.'_reference']??0);
    $target=$ref>0?($exports[$ref-1]??null):null;
    $typeIndex=(int)($target['classIndex']??0);
    $type=$typeIndex<0?($pkg->getImports()[-$typeIndex-1]??null):null;
    $check('genuine_model_'.$i.'_reference_'.$field.'_class',
      $ref>0 && is_array($target) && is_array($type)
      &&($type['objectNameText']??'')===$expectedClass);
   }
  }
  if(in_array($cl,['Level','BspNodes','BspSurfs','Verts','Vectors','Polys','TextBuffer'],true)){
   $specialComplete++;
   $check('genuine_native_'.$cl.'_'.$i.'_complete',
      str_ends_with($status,'_complete')
      &&($native['extension_bytes']??-1)===0);
  }
  if(($tag['native_tail_bytes']??0)===0){
   $check('tag_only_export_'.$i.'_no_unexplained_native_bytes',
     ($status==='tag_envelope_complete_no_native_tail'
     &&($native['extension_bytes']??-1)===0
     &&($native['class_runtime_serializer_verified']??null)===false)
       ||$status==='class_metadata_decoded_in_class_reader');
  }
 }catch(Throwable $e){
  $check('no_decode_exception_export_'.$i,false);
  $failures[]=['export'=>$i,'object'=>$ex['objectNameText']??'?',
    'class'=>$cl,'error'=>$e::class.': '.$e->getMessage()];
 }
}
$check('genuine_three_model_exports',$models===3);
$check('genuine_twenty_other_specialized_native_exports',$specialComplete===20);
$check('genuine_tag_stream_not_empty',$totalTags>40);
$srcRoot='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Engine/';
$actorSource=@file_get_contents($srcRoot.'Src/UnActor.cpp');
$actorHdr=@file_get_contents($srcRoot.'Inc/EngineClasses.h');
$actorPos=is_string($actorSource)
  ?strpos($actorSource,'void AActor::Serialize( FArchive& Ar )'):false;
$actorSerialize=$actorPos===false?'':substr($actorSource,$actorPos,450);
$check('epic_v1200_actor_v61_only_super_serializer',is_string($actorSource)
  &&str_contains($actorSerialize,'Super::Serialize( Ar );')
  &&str_contains($actorSerialize,'if( Ar.Ver()<57 )')
  &&str_contains($actorSerialize,'if( Ar.Ver()<58 )')
  &&!str_contains($actorSerialize,'Ar <<'));
$check('epic_v1200_genuine_actor_class_declarations',is_string($actorHdr)
  &&str_contains($actorHdr,'DECLARE_CLASS(ABrush,AActor,0)')
  &&str_contains($actorHdr,'DECLARE_CLASS(ACamera,APlayerPawn,0|CLASS_Config)')
  &&str_contains($actorHdr,'DECLARE_CLASS(ALevelInfo,AZoneInfo,0)')
  &&str_contains($actorHdr,'DECLARE_CLASS(APlayerStart,ANavigationPoint,0)'));
$check('genuine_eight_tagged_only_exports',($nativeStatuses['tag_envelope_complete_no_native_tail']??0)===8
  &&!isset($nativeStatuses['class_specific_serializer_unimplemented']));
ksort($classes);ksort($nativeStatuses);
echo json_encode([
 'ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'failures'=>$failures,'version'=>$header['version'],
 'package_sha256'=>$expectedHash,'exports'=>count($exports),
 'tag_count'=>$totalTags,'native_tail_bytes'=>$tailBytes,
 'class_counts'=>$classes,'native_statuses'=>$nativeStatuses,
 'scope'=>'Genuine version 61 package in local UE1 Engine/Maps; no synthetic package construction',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
