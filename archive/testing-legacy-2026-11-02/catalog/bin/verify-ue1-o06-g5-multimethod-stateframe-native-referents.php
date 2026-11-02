#!/usr/bin/env php
<?php
/** Strict genuine UT99 v432 compiled FStateFrame Node+StateNode name oracle.
 *  14 saved positive v68 map frames; live native FName->Names, not archive
 *  strings disguised as compiled evidence. Five frames remain uncovered.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$base=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($base.'/catalog/compliance/UE1-O06-G5-MULTIMETHOD-STATEFRAME-NATIVE-REFERENTS.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];$matched=0;$references=0;$sourceFrameBytes=0;
$ck=static function(string $key,bool $truth) use(&$checks,&$errors):void{
  $checks++;if(!$truth)$errors[]=$key;
};
$pin=static function(string $key,string $sha,string $label)use($ck):string{
  $b=is_file($key)?file_get_contents($key):false;
  $ck('sha256_'.$label,is_string($b)&&hash('sha256',$b)===$sha);
  return is_string($b)?$b:'';
};
$pin($m['source'],$m['source_sha256'],'Entry_original_v68');
$pin($m['core'],$m['core_sha256'],'compiled_Core_v432');
$raw=$pin($m['native_log'],$m['native_log_sha256'],'CDB_native_FName_references');
$log=str_starts_with($raw,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
$log=str_replace("\r",'',$log);
$ck('native_is_actual_FName_global_table',
  str_contains($log,'Core!FName::Names')&&str_contains($log,'Core+0x556b1'));
$ck('native_debugger_no_bad_memory_or_expression',
  !str_contains($log,'Memory access error')&&!str_contains($log,'Numeric expression missing'));
$pat='/G5_NATIVE_FRAME_REF idx=(\d+) flags=([0-9a-f]{8}) node=([^\r\n]*?) state=([^\r\n]*?) probe_lo=([0-9a-f]{8}) probe_hi=([0-9a-f]{8}) latent=([0-9a-f]{8}) code=([0-9a-f]{8})(?=\s|$)/mi';
preg_match_all($pat,$log,$found,PREG_SET_ORDER);
$ck('exact_fourteen_real_native_records',count($found)===$m['compiled_records']);
$ck('native_terminates_after_fourteen_loaded_positive_actors',
  preg_match('/G5_NATIVE_FRAME_REFERENTS_COMPLETE count=(\d+)/',$log,$last)===1
  &&(int)($last[1]??-1)===count($found));
$p=new P($m['source']);$imports=$p->getImports();$exports=$p->getExports();
$ck('original_package_valid',$p->validatePackage()===[]&&(int)$p->getHeader()['version']===68);
$positive=array_filter($exports,static fn($e)=>((int)$e['objectFlags']&0x02000000)!==0);
$ck('original_map_nineteen_StateFrames',count($positive)===$m['expected_source_positive_objects']);
$seen=[];
foreach($found as $r){
 $idx=(int)$r[1];$key='compiled_Entry_frame_'.$idx;
 $ck('matching_original_flagged_export_'.$key,isset($positive[$idx]));
 if(!isset($positive[$idx]))continue;
 $ck('native_export_observed_once_'.$key,!isset($seen[$idx]));$seen[$idx]=true;
 $ex=$positive[$idx];$f=T::inspect($m['source'],$p,$idx,'ut99-v432');
 $frame=$f['serialized_state_frame']??null;
 $ck('reader_identifies_real_StateFrame_'.$key,
   $f['status']==='tag_envelope_decoded'&&is_array($frame)
   &&($frame['has_stack_object_flag']??false));
 if(!is_array($frame))continue;
 $flags=hexdec($r[2]);
 $ck('original_export_object_flags_survive_'.$key,
   ($flags&(int)$ex['objectFlags'])===(int)$ex['objectFlags']);
 $ck('compiled_debug_serialize_flag_'.$key,($flags&0x40000000)!==0);
 foreach([['node_reference',$r[3]],['state_node_reference',$r[4]]] as [$field,$actualName]){
   $ref=(int)$frame[$field];
   $referent=$ref<0?$imports[-$ref-1]??null:($ref>0?$exports[$ref-1]??null:null);
   $ck('source_import_export_referent_exists_'.$field.'_'.$key,is_array($referent));
   if(!is_array($referent))continue;
   $expected=(string)($referent['objectNameText']??'');
   $ck('real_compiled_native_FName_referent_'.$field.'_'.$key,
      $expected!=='' &&strcasecmp($expected,trim($actualName))===0);
   $references++;
 }
 $actualProbe=bin2hex(pack('V2',hexdec($r[5]),hexdec($r[6])));
 $ck('compiled_probe_mask_exact_64bit_'.$key,
   $actualProbe===(string)$frame['probe_mask_le_hex']);
 $nativeLatent=hexdec($r[7]);
 if($nativeLatent>=0x80000000)$nativeLatent-=0x100000000;
 $ck('compiled_signed_latent_action_exact_'.$key,
   $nativeLatent===(int)$frame['latent_action']);
 $ck('compiled_null_script_code_offset_'.$key,
   hexdec($r[8])===0&&$frame['script_code_offset']===-1);
 $ck('original_source_frame_consumption_'.$key,
   (int)$frame['serialized_size']===15
   &&(int)$frame['serialized_start']===(int)$ex['serialOffset']);
 $sourceFrameBytes+=(int)$frame['serialized_size'];$matched++;
}
$ck('all_original_observed_frames_evaluated',
 $matched===$m['compiled_records']);
$ck('exact_source_import_referent_names_all_twenty_eight',
 $references===$m['expected_native_named_references']);
$ck('five_remaining_unobserved_original_export_frames',
 count($positive)-count($seen)===$m['remaining_source_positive_unobserved']);
$ck('original_source_frame_bytes_vs_native_subset',
 $sourceFrameBytes===$matched*15);
echo json_encode([
 'ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),
 'errors'=>$errors,'compiled_original_frames'=>$matched,
 'native_imported_Node_and_StateNode_reference_name_matches'=>$references,
 'native_memory_source_frame_bytes_compared'=>$sourceFrameBytes,
 'original_source_positive_total'=>count($positive),
 'remaining_original_positive_unloaded'=>count($positive)-count($seen),
 'full_method_accepted'=>false,
 'gaps'=>['5 original Entry.unr RF_HasStack frames not observed native',
 'Native reference names verified but not full class/outer identity',
 'Malformed consumption/errors and other source versions open'],
],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($errors===[]?0:1);
