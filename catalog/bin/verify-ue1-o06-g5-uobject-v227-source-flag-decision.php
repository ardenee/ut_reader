#!/usr/bin/env php
<?php
/** Distinct source v227 flag enums; reuse all prior compiled v432 oracle
 * results by SHA. No claim v227 native UObject::Serialize body is known.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);
$base='C:/Temp/UnrealDB/O06/';
$json=static function(string $p):array{
 $r=(string)file_get_contents($p);
 if(str_starts_with($r,"\xEF\xBB\xBF"))$r=substr($r,3);
 return json_decode($r,true,512,JSON_THROW_ON_ERROR);
};
$m=$json($root.'/catalog/compliance/UE1-O06-UE1-SOURCE-EQUIVALENCE.json');
$checks=0;$fails=[];
$ck=static function(string $id,bool $valid)use(&$checks,&$fails):void{
 $checks++;if(!$valid)$fails[]=$id;
};
foreach($m['verified_source_input_sha256'] as $k=>$f){
 $ck('Epic_local_source_pinned_'.$k,is_file($f['path'])
   &&hash_file('sha256',$f['path'])===$f['sha256']);
}
$state=$m['v227_vs_v432_stateframe_declaration'];
$flag=$m['v227_vs_v432_flag_difference'];
$ck('v227_FStateFrame_members_same_but_not_native_body',
 count($state['archive_members_common'])===4
 &&str_starts_with($state['native_serializer_body_equivalence'],'NOT PROVED'));
$ck('v227_declares_pending_delete_not_debug_serialize',
 $flag['v227_bit_0x40000000']==='RF_PendingDelete'
 &&$flag['v1200_and_v432_bit_0x40000000']==='RF_DebugSerialize');
$ck('v227_extra_archetype_flag_in_source_header',
 str_contains($flag['v227_bit_0x00000100'],'RF_HasArchtype')
 &&str_contains($flag['v432_RF_HasArchtype'],'not declared'));
$ck('v1200_only_common_frame_v60_61_not_all_tagged_semantics',
 $m['shared_wire_v1200_v432_version_interval']===[60,61]
 &&count($m['reviewed_operations'])>=11);
$files=$json($base.'g5-prd-ue1-staged-manifest.json')['files'];
$sourceFile=static function(string $name)use($files):array{
 foreach($files as $file){
  if(str_ends_with($file['staged'],$name))return $file;
 }
 throw new RuntimeException('Missing staged original PRD corpus '.$name);
};
$gold=$sourceFile('46a37326a73a5966ef33441eed13e799.unr');
$cls=$sourceFile('aa33ed5fc5c15fd90e6969e8478fbd73.u');
$early=$sourceFile('fd0609f727411e4f073fc1e60cc91f49.unr');
foreach(['gold'=>$gold,'class'=>$cls,'early'=>$early] as $k=>$file){
 $ck('original_DEV_SHA_verified_'.$k,hash_file('sha256',$file['staged'])===$file['sha256']);
}
$p=new P($gold['staged']);
$ut=T::inspect($gold['staged'],$p,0,'ut99-v432');
$v=T::inspect($gold['staged'],$p,0,'unreal-v227');
$ck('same_original_v69_archive_tag_value_stream',
 $ut['status']==='tag_envelope_decoded'
 &&$v['status']==='tag_envelope_decoded'
 &&count($ut['tags'])===count($v['tags']));
$ck('v227_UObject_postload_flag_not_fabricated',
 $v['loaded_object_flags_after_uobject_serialize']===null
 &&$v['source_sets_rf_debug_serialize']===false);
$ck('v432_UObject_postload_debug_bit_preserved',
 $ut['loaded_object_flags_after_uobject_serialize']
 ===((int)$p->getExports()[0]['objectFlags']|0x40000000)
 &&$ut['source_sets_rf_debug_serialize']===true);
$cp=new P($cls['staged']);$found=false;
foreach($cp->getExports() as $i=>$ex){
 if((int)$ex['classIndex']!==0||$ex['objectNameText']!=='EditorEngine')continue;
 $cl=T::inspect($cls['staged'],$cp,$i,'unreal-v227');
 $cl432=T::inspect($cls['staged'],$cp,$i,'ut99-v432');
 $found=true;
 $ck('v227_UClass_flags_not_fabricated',
   $cl['loaded_object_flags_after_uobject_serialize']===null
   &&$cl['loaded_object_flags_after_uclass_serialize']===null
   &&$cl['source_sets_rf_debug_serialize']===false);
 $ck('v432_UClass_postload_debug_bit_preserved',
   ($cl432['loaded_object_flags_after_uobject_serialize']&0x40000000)!==0
   &&$cl432['source_sets_rf_debug_serialize']===true);
 break;
}
$ck('genuine_original_Class_object_examined',$found);
$e=new P($early['staged']);
$legacy=T::inspect($early['staged'],$e,0,'epic-ue1');
$ck('v1200_UObject_declared_debug_flag_still_preserved',
 $legacy['status']==='tag_envelope_decoded'
 &&$legacy['source_sets_rf_debug_serialize']===true
 &&($legacy['loaded_object_flags_after_uobject_serialize']&0x40000000)!==0);
$events=array_map(static fn($l)=>json_decode($l,true),
 array_filter(explode("\n",(string)file_get_contents(
 $root.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'))));
$prior=null;foreach($events as $entry){
 if(($entry['test']??'')==='verify-ue1-o06-g5-uobject-prd-v61-native-loaded-decision.php'
 &&($entry['state']??'')==='PASS')$prior=$entry;
}
$log=$prior?$root.'/'.str_replace('\\','/',$prior['log']):null;
$old=$log&&is_file($log)?$json($log):[];
$ck('prior_real_PRD_v61_compiled_308_identity_and_184_tail_SHA_reused',
 $prior!==null
 &&hash_file('sha256',$log)===$prior['output_sha256']
 &&($old['ok']??null)===true
 &&($old['checks']??null)===24
 &&($old['compiled_native_v432_loaded_objects']??null)===308
 &&($old['source_native_tails_complete']??null)===184);
$ck('v227_native_body_not_present_no_false_closure',
 !$m['v227_native_implementation_source_available']
 &&$m['full_method_decision']==='BLOCKED');
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,
 'passed'=>$checks-count($fails),'failures'=>$fails,
 'v1200_v432_shared_archive_frame_versions'=>'60..61',
 'v227_FStateFrame_data_members'=>'same_declarations_not_implementation',
 'v227_loaded_DebugSerialize_bit'=>'never fabricated',
 'v227_native_serializer_implementation'=>'unavailable_in_local_source',
 'native_engine_reruns_in_verifier'=>0,
 'old_compiled_PRD_v61_load_exact_identity_count_SHA_reused'=>308,
 'full_method_decision'=>'BLOCKED'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
