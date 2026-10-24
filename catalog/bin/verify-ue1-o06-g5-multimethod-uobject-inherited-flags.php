#!/usr/bin/env php
<?php
/** Shared compiled UObject::Serialize and derived native effects in ONE
 * actual UT99 v432 Engine.u load. This is a partial inherited-path oracle.
 * Critically: NO positive RF_HasStack / class-default / malformed proof.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-MULTIMETHOD-UOBJECT-LOADED-FLAGS.json'),true,512,JSON_THROW_ON_ERROR);
$fail=[];$checks=0;$bytes=0;$matchedText=0;$matchedFont=0;
$check=static function(string $label,bool $ok)use(&$fail,&$checks):void{$checks++;if(!$ok)$fail[]=$label;};
$pin=static function(string $path,string $expected,string $label)use($check):string{
 $data=is_file($path)?file_get_contents($path):false;
 $check('pinned_sha256_'.$label,is_string($data)&&hash('sha256',$data)===$expected);
 return is_string($data)?$data:'';
};
$pin($m['source_file'],$m['source_sha256'],'original_Engine_u');
$pin($m['core_dll'],$m['core_sha256'],'original_Core_dll');
$pin($m['engine_dll'],$m['engine_sha256'],'original_Engine_dll');
$raw=$pin($m['log_file'],$m['log_sha256'],'CDB_one_load_four_methods');
$log=str_starts_with($raw,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($raw,2)):$raw;
$log=str_replace("\r",'',$log);
$check('one_real_compiled_native_loader_four_methods',
 preg_match('/G5_SHARED4_SUMMARY UObject=(\d+) UObjectStackBefore=(\d+) UClass=(\d+) TextBuffer=(\d+) Font=(\d+)/',$log,$call)===1
 && (int)$call[1]===$m['expected_calls']['UObject']
 && (int)$call[2]===$m['expected_calls']['UObjectStackBefore']
 && (int)$call[3]===$m['expected_calls']['UClass']
 && (int)$call[4]===$m['expected_calls']['UTextBuffer']
 && (int)$call[5]===$m['expected_calls']['UFont']);
$check('compiled_exact_four_method_entry_points',
 str_contains($log,'bp Core+0x17b7')&&str_contains($log,'bp Core+0x1203')
 &&str_contains($log,'bp Core+0x31bb')&&str_contains($log,'bp Engine+0x242d'));
preg_match_all('/G5_UOBJ_TEXT idx=(\d+) flags=([0-9a-f]{8}) pos=(-?\d+) top=(-?\d+) nchars=(\d+)/i',$log,$textEvents,PREG_SET_ORDER);
preg_match_all('/G5_UOBJ_FONT idx=(\d+) flags=([0-9a-f]{8})/i',$log,$fontEvents,PREG_SET_ORDER);
$check('all_90_textbuffer_native_events',count($textEvents)===90);
$check('all_four_font_post_archive_events',count($fontEvents)===4);
$pkg=new P($m['source_file']);$exps=$pkg->getExports();$imps=$pkg->getImports();
$expectedText=[];$expectedFont=[];
foreach($exps as $e){
 $ci=(int)($e['classIndex']??0);
 if($ci>=0)continue;
 $class=strtolower((string)($imps[-$ci-1]['objectNameText']??''));
 if($class==='textbuffer')$expectedText[(int)$e['index']]=$e;
 if($class==='font')$expectedFont[(int)$e['index']]=$e;
}
$check('genuine_88_TextBuffer_export_rows',count($expectedText)===88);
$check('genuine_four_Font_export_rows',count($expectedFont)===4);
$check('no_UE1_state_frames_in_unmodified_Engine_u',
 count(array_filter($exps,static fn($e)=>(int)$e['objectFlags'] & 0x02000000))===0);
$seenText=[];$extra=[];
$checkFields=static function(int $idx,int $flags,array $e,string $class)
 use($check,$m,$pkg,&$bytes):array {
  $flagsBefore=(int)$e['objectFlags'];
  $check('original_export_flags_in_native_loaded_'.$class.'_'.$idx,
   ($flags&$flagsBefore)===$flagsBefore);
  $check('UObject_RF_DebugSerialize_set_'.$class.'_'.$idx,
   ($flags&0x40000000)!==0);
  $check('native_loaded_lifecycle_flag_delta_'.$class.'_'.$idx,
   ($flags&(~$flagsBefore))===0x41008000);
  $tag=T::inspect($m['source_file'],$pkg,$idx,'ut99-v432');
  $check('original_TagEnvelope_decoded_'.$class.'_'.$idx,
   $tag['status']==='tag_envelope_decoded'
   &&$tag['serialized_state_frame']===null
   &&$tag['loaded_object_flags_after_uobject_serialize']===($flagsBefore|0x40000000)
   &&$tag['native_tail_bytes']>0);
  $native=N::inspect($m['source_file'],$pkg,$idx,$tag,'ut99-v432');
  $check('original_native_record_decoded_'.$class.'_'.$idx,
   str_ends_with((string)$native['status'],'_complete')
   &&($native['extension_bytes']??0)===0);
  $bytes+=(int)$e['serialSize'];
  return $native;
};
foreach($textEvents as $event){
 $idx=(int)$event[1];$seenText[$idx]=($seenText[$idx]??0)+1;
 if(!isset($expectedText[$idx])){$extra[]=$idx;continue;}
 $flags=hexdec($event[2]);$record=$checkFields($idx,$flags,$expectedText[$idx],'Text');
 $check('compiled_Pos_Top_and_UTF16_TCHAR_length_'.$idx,
   (int)$event[3]===$record['position']
   &&(int)$event[4]===$record['top']
   &&(int)$event[5]===$record['text_character_count']+1);
 $matchedText++;
}
$check('all_88_real_TextBuffer_observed',count(array_intersect_key($expectedText,$seenText))===88);
sort($extra);
$check('two_native_runtime_transient_non_textbuffer_exports',
 $extra===[333,579]);
$check('no_duplicate_source_TextBuffer_entry',
 count(array_filter($seenText,static fn($n)=>$n!==1))===0);
$seenFont=[];
foreach($fontEvents as $event){
 $idx=(int)$event[1];$seenFont[$idx]=($seenFont[$idx]??0)+1;
 if(!isset($expectedFont[$idx])){$check('unmatched_native_font_'.$idx,false);continue;}
 $checkFields($idx,hexdec($event[2]),$expectedFont[$idx],'Font');$matchedFont++;
}
$check('all_four_real_Font_observed',count($seenFont)===4 && count(array_filter($seenFont,static fn($x)=>$x!==1))===0);
$check('full_method_acceptance_remains_OPEN',
 $m['expected_calls']['UObjectStackBefore']===0&&$matchedText===88&&$matchedFont===4);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),'failures'=>$fail,
 'single_original_compiled_load_UObject_calls'=>(int)$call[1],
 'single_original_compiled_load_UClass_calls'=>(int)$call[3],
 'single_original_compiled_load_UTextBuffer_calls'=>(int)$call[4],
 'single_original_compiled_load_UFont_calls'=>(int)$call[5],
 'compiled_inherited_loaded_TextBuffer_export_records'=>$matchedText,
 'compiled_inherited_loaded_Font_export_records'=>$matchedFont,
 'source_archive_bytes_of_correlated_objects'=>$bytes,
 'original_exports_with_RF_HasStack'=>0,
 'full_method_acceptance'=>'OPEN',
 'reason'=>'No actual positive StateFrame branch or all properties/super/inherited error paths verified; native runtime flags include loader lifecycle bits not attributed solely to UObject::Serialize.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
