#!/usr/bin/env php
<?php
/** Independent seven-gate original UT99 v432 UTextBuffer::Serialize
 * file-loading review, reusing genuine native 88/88 byte values,
 * native malformed/UTF16/PosTop fixtures, no CDB replay.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);$dir=$root.'/catalog/compliance';
$m=json_decode((string)file_get_contents($dir.'/UE1-O06-G5-T99-TEXTBUFFER-INHERITED-88-SUPER-REVIEW.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$bad=[];
$ck=static function(string $id,bool $pass)use(&$checks,&$bad):void{$checks++;if(!$pass)$bad[]=$id;};
$sha=static function(array $entry):bool{return is_file($entry['path'])&&hash_file('sha256',$entry['path'])===$entry['sha256'];};
foreach(['sources_sha256','proof_sha256'] as $section){
 foreach($m[$section] as $name=>$file)$ck('sha_'.$name,$sha($file));
}
$old=json_decode((string)file_get_contents($m['proof_sha256']['legacy_compiled_88_full_values']['path']),true,512,JSON_THROW_ON_ERROR);
$ck('actual_native_genuine_count_88',$old['expected_genuine_loaded_textbuffers']===88);
$ck('actual_compiled_TCHAR_bytes_1007796',$old['expected_UTF16_storage_bytes']===1007796);
$ck('exact_UT99_source_profile_only',$m['source_profile']==='ut99-v432'
 &&str_contains($m['source_variant_limit'],'only UT99 v432'));
$source=(string)file_get_contents($m['sources_sha256']['UT99_v400_Core_UnObj_UObject_UTextBuffer']['path']);
$section=explode('IMPLEMENT_CLASS(UTextBuffer)',explode('void UTextBuffer::Serialize( FArchive& Ar )',$source,2)[1])[0]??'';
$ck('original_Epic_UTextBuffer_parent_then_three_members',
 str_contains($section,'Super::Serialize(Ar);')
 &&str_contains($section,'Ar << Pos << Top << Text;'));
$ck('original_Epic_super_stateframe_and_tagged_properties',
 str_contains($source,'if( GetFlags() & RF_HasStack )')
 &&str_contains($source,'GetClass()->SerializeTaggedProperties( Ar, (BYTE*)this, Class );'));
$all=json_decode((string)file_get_contents($m['proof_sha256']['inherited_88_manifest']['path']),true,512,JSON_THROW_ON_ERROR);
$pkg=new P($all['archive']);$exports=$pkg->getExports();
$handle=fopen($all['archive'],'rb');if($handle===false)throw new RuntimeException('Original native Engine.u absent');
$ck('original_SHA_equals_review_manifest',hash_file('sha256',$all['archive'])===$m['original_file_sha256']);
$seen=[];$count=0;$logicalNativeTail=0;$distinct=[];
foreach($all['objects'] as $row){
 $id=(int)$row['index'];$entry=$exports[$id]??null;
 $ck('real_TextBuffer_export_'.$id,is_array($entry)&&(int)$entry['serialSize']===$row['serial_size']
   &&(int)$entry['serialOffset']===$row['tag_start']);
 if($entry===null)continue;
 $tags=T::inspect($all['archive'],$pkg,$id,'ut99-v432');
 $native=N::inspect($all['archive'],$pkg,$id,$tags,'ut99-v432');
 fseek($handle,(int)$entry['serialOffset']);
 $compactNone=fread($handle,2);
 $ck('native_UObject_no_stack_no_tag_compact_name_None_'.$id,
   (($entry['objectFlags']&0x02000000)===0)
   &&$tags['serialized_state_frame']===null&&$tags['tags']===[]
   &&$tags['tag_end_offset']===(int)$entry['serialOffset']+2
   &&$compactNone==="\x50\x34");
 $ck('current_native_complete_exact_export_size_'.$id,
   $native['status']==='native_textbuffer_record_complete'
   &&$row['native_status']==='native_textbuffer_record_complete'
   &&$row['serial_size']>2);
 $logicalNativeTail+=(int)$entry['serialSize']-2;$seen[]=$id;$count++;
}
fclose($handle);
$ck('all_88_real_source_native_originals_no_error',$count===88
 &&count(array_unique($seen))===88&&$all['errors']===[]);
$ck('real_version_69',$pkg->getHeader()['version']===69);
foreach(['compiled_core','native_disassembly','native_loaded_log','native_memory_dump'] as $name){
 $ck('pinned_native_original_'.$name,$sha($old[$name]));
}
foreach($old['variations'] as $case){
 $kind=$case['kind'];$distinct[]=$kind;
 $ck('unique_variant_SHA_'.$kind,$sha($case['archive'])&&$sha($case['stdout']));
 $nativeStdout=(string)file_get_contents($case['stdout']['path']);
 if($kind==='oversized'){
  $ck('genuine_bad_count_compiled_912_vs_843',
   str_contains($nativeStdout,'Serial size mismatch: Got 912, Expected 843')
   &&$case['expected_native_exit']===1);
  $mutated=new P($case['archive']['path']);
  $tag=T::inspect($case['archive']['path'],$mutated,$case['idx'],'ut99-v432');
  $err='';
  try{N::inspect($case['archive']['path'],$mutated,$case['idx'],$tag,'ut99-v432');}
  catch(RuntimeException $e){$err=$e->getMessage();}
  $ck('current_reader_native_bad_count_exact_cursor_and_safe',$err==='TextBuffer: Serial size mismatch: Got 912, Expected 843');
 }else{
  $ck('native_positive_variant_success_'.$kind,
   $case['expected_native_exit']===0
   &&str_contains($nativeStdout,'Success - 0 error(s), 0 warnings'));
 }
}
$ck('all_four_original_compiled_variations_exactly_once',
 count($distinct)===4 &&count(array_unique($distinct))===4
 &&in_array('pos_top',$distinct,true)&&in_array('wide_utf16',$distinct,true)
 &&in_array('missing_nul',$distinct,true)&&in_array('oversized',$distinct,true));
$events=array_filter(array_map('json_decode',file($dir.'/UE1-O06-TEST-EVENTS.jsonl')));
$previous=[];
foreach($events as $e){
 if(($e->state??'')==='PASS'
  &&in_array(($e->test??''),[
 'verify-ue1-o06-g5-textbuffer-consolidated-acceptance.php',
 'verify-ue1-o06-g5-textbuffer-native-all-values.php'],true)){
  $previous[$e->test]=$e;
 }
}
foreach($previous as $name=>$event){
 $log=$root.'/'.str_replace('\\','/',$event->log);
 $file=$root.'/catalog/bin/'.$name;
 $ck('prior_PINNED_full_native_positives_'.$name,
  is_file($log)&&hash_file('sha256',$log)===$event->output_sha256
  &&hash_file('sha256',$file)===$event->test_sha256);
}
$ck('both_previous_native_acceptances_exist',count($previous)===2);
$ck('not_misrepresent_shared_generic_UObject',
 $m['overall_generic_T99_UObject_full']==='BLOCKED_DIFFERENT_ENGINE_LOADER_VARIANTS'
 &&$m['native_repeat_count']===0);
$gates=array_fill_keys(['pinned_source_and_dispatch','complete_native_field_values_and_references',
 'archive_bytes_and_consumption','malformed_input_error_semantics',
 'inherited_and_version_variant_semantics','current_acceptance_tests',
 'independent_full_method_review'],'VERIFIED');
$ck('seven_source_profile_criteria_are_specific_not_global',count($gates)===7);
echo json_encode(['ok'=>$bad===[],'checks'=>$checks,'passed'=>$checks-count($bad),'failed'=>$bad,
 'method'=>'T99:UTextBuffer::Serialize','scope'=>'UT99 v432 file-loading',
 'observed_genuine_native_loaded_objects'=>88,
 'original_native_memory_UTF16_bytes'=>1007796,
 'inherited_real_source_compact_name_None_2bytes'=>88,
 'compiled_native_positive_variants'=>3,'compiled_native_negative_variants'=>1,
 'native_overlong_cursor'=>'Got 912, Expected 843',
 'review'=>'FULL_SOURCE_PROFILE_ONLY',
 'seven_gates'=>$gates,'generic_T99_UObject'=>'BLOCKED_INDEPENDENTLY',
 'compiled_native_reruns'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
