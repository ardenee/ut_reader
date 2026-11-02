#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Authoritative Epic CreateExport Camera exclusion. One semantic rule
 * per source family; compare 3 genuine maps and reuse 2 sealed real
 * compiled v432 native evidence manifests. Never rerun native oracles.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$manifest=json_decode((string)file_get_contents(
 $root.'/catalog/compliance/UE1-O06-G5-CAMERA-SOURCE-REACHABILITY-DECISION.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fails=[];$ck=static function(string $id,bool $ok)use(&$checks,&$fails):void{
 $checks++;if(!$ok)$fails[]=$id;
};
foreach(['source_SHA','original_archives_SHA','original_compiled_oracles_SHA'] as $group){
 foreach($manifest[$group] as $key=>$file){
  $ck('SHA_'.$key,is_file($file['path'])
    &&hash_file('sha256',$file['path'])===$file['sha256']);
 }
}
$early=(string)file_get_contents($manifest['source_SHA']['Epic_Unreal_v1200_Core_UnLinker_CreateExport']['path']);
$later=(string)file_get_contents($manifest['source_SHA']['UT99_inherited_v400_Core_UnLinker_CreateExport']['path']);
$ck('epic_v1200_below57_Camera_skipped',
 str_contains($early,'Ver()>=57 || appStricmp(*Export.ClassName,"Camera")'));
$ck('ut99_v400_Camera_CreateExport_returns_NULL',
 (bool)preg_match('/if\\( LoadClass->GetFName\\(\\)==NAME_Camera \\).*?return NULL;/s',$later));
$expected=[
 'original_PRD_UnrealGold_v54_Map'=>['version'=>54,'profile'=>'epic-ue1','cameras'=>5],
 'original_PRD_UnrealGold_v61_Map'=>['version'=>61,'profile'=>'ut99-v432','cameras'=>4],
 'original_installed_UT99_v68_Entry_Map'=>['version'=>68,'profile'=>'ut99-v432','cameras'=>5]];
$totalCameras=0;$totalOrdinaryControls=0;$totalFrames=0;
foreach($expected as $key=>$expect){
 $file=$manifest['original_archives_SHA'][$key]['path'];$pkg=new P($file);
 $header=$pkg->getHeader();
 $ck('archive_version_'.$key,(int)$header['version']===$expect['version']);
 $cam=0;$control=0;$imports=$pkg->getImports();$exports=$pkg->getExports();
 foreach($exports as $i=>$ex){
  if((int)$ex['serialSize']<=0)continue;
  $ci=(int)$ex['classIndex'];
  $cls=$ci<0?($imports[-$ci-1]['objectNameText']??''):
    ($ci>0?($exports[$ci-1]['objectNameText']??''):'Class');
  if(strcasecmp($cls,'Camera')===0){
   $r=T::inspect($file,$pkg,$i,$expect['profile']);
   $cam++;$totalCameras++;
   $ck('Camera_source_excluded_'.$key.'_'.$i,
     $r['status']==='tag_envelope_decoded'
     &&$r['native_loader_class_excluded_by_epic_source']===true
     &&$r['native_loader_UObject_serialize_invoked']===false
     &&$r['loaded_object_flags_after_uobject_serialize']===null
     &&$r['source_sets_rf_debug_serialize']===false);
   $ck('correct_profile_Camera_exclusion_'.$key.'_'.$i,
     $r['native_loader_source_branch']===(
       $expect['profile']==='epic-ue1'
         ?'v1200_Camera_skipped_before_version57'
         :'UT99_v400_inherited_Camera_CreateExport_returns_NULL'));
   if(is_array($r['serialized_state_frame']??null))$totalFrames++;
  }elseif($control<5){
   $r=T::inspect($file,$pkg,$i,$expect['profile']);
   $ck('not_falsely_excluded_'.$key.'_'.$i,
     $r['native_loader_class_excluded_by_epic_source']===false
     &&$r['native_loader_UObject_serialize_invoked']===null
     &&$r['source_sets_rf_debug_serialize']===true
     &&$r['loaded_object_flags_after_uobject_serialize']!==null);
   $control++;$totalOrdinaryControls++;
  }
 }
 $ck('original_Camera_count_'.$key,$cam===$expect['cameras']);
 $ck('original_normal_control_count_'.$key,$control===5);
}
// Same original v61 Camera stream, but v1200's v>=57 source rule
// does not exclude it. Header version is NOT a provenance selector.
$pkg=new P($manifest['original_archives_SHA']['original_PRD_UnrealGold_v61_Map']['path']);
$exports=$pkg->getExports();$imports=$pkg->getImports();$cameraIndex=null;
foreach($exports as $i=>$ex){
 $ci=(int)$ex['classIndex'];
 if($ci<0&&strcasecmp((string)($imports[-$ci-1]['objectNameText']??''),'Camera')===0){$cameraIndex=$i;break;}
}
$ck('v61_has_original_Camera',$cameraIndex!==null);
if($cameraIndex!==null){
 $epic=T::inspect($manifest['original_archives_SHA']['original_PRD_UnrealGold_v61_Map']['path'],$pkg,$cameraIndex,'epic-ue1');
 $ck('v1200_v61_Camera_not_source_excluded',$epic['native_loader_class_excluded_by_epic_source']===false
     &&$epic['native_loader_UObject_serialize_invoked']===null);
}
// Two independent native v432 data sets already sealed and SHA-pinned.
$native61=json_decode((string)file_get_contents(
 $manifest['original_compiled_oracles_SHA']['compiled_v432_original_v61_308_of_312']['path']),true,512,JSON_THROW_ON_ERROR);
$native68=json_decode((string)file_get_contents(
 $manifest['original_compiled_oracles_SHA']['compiled_v432_original_v68_14_of_19_saved_frames']['path']),true,512,JSON_THROW_ON_ERROR);
$ck('compiled_v432_v61_4_original_Camera_return_NULL',
 $native61['compiled_loaded_and_identity_matched']===308
 &&$native61['native_camera_instances']===0
 &&count($native61['uninstantiated_export_names'])===4);
$ck('compiled_v432_v68_5_original_Camera_not_serialized',
 $native68['original_positive_state_frame_exports']===19
 &&$native68['compiled_positive_loaded_records']===14
 &&(int)$native68['unloaded_positive_exports']===5);
$ck('v227_loader_cannot_be_assumed',
 $manifest['v227_loader_source_available']===false);
$ck('one_source_defined_Camera_rule_is_CLOSED_but_g5_not_FULL',
 $manifest['conclusion']==='CLOSED_AS_EPIC_SOURCE_DEFINED_NATIVE_CREATEEXPORT_SKIP'
 &&$manifest['source_scope_method_full_decision']==='BLOCKED');
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'passed'=>$checks-count($fails),
 'failures'=>$fails,'C98_original_v54_Camera_skipped'=>5,
 'UT99_original_v61_Camera_skipped'=>4,
 'UT99_original_v68_Camera_skipped'=>5,
 'genuine_source_excluded_Camera_exports'=>$totalCameras,
 'unrelated_original_exports_not_falsely_skipped'=>$totalOrdinaryControls,
 'native_compiled_v432_4_plus_5_Camera_exclusions_sha_reused'=>9,
 'camera_native_CreateExport_criterion'=>'CLOSED_SOURCE_DEFINED_EXCLUSION',
 'Camera_serialized_bytes_still_inspectable'=>true,
 'v227_native_Camera_unknown'=>true,'compiled_native_oracle_replays'=>0,
 'full_method_decision'=>'BLOCKED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
