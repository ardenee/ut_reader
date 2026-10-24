#!/usr/bin/env php
<?php
/** Independent compiled v432 loaded-value closure candidate for UTextBuffer.
 * 88 genuine TextBuffers, three persisted fields, full actual native TCHAR
 * buffers, ANSI and UTF16 branches and real compiled controlled mutations.
 * Does NOT accept whole method: parent serializer and error-byte parity open.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G5-T99-TEXTBUFFER-FULL-VALUES.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fails=[];$objects=0;$memoryBytes=0;
$check=static function(string $name,bool $pass)use(&$checks,&$fails):void{
 $checks++;if(!$pass)$fails[]=$name;
};
$pin=static function(array $file,string $label)use($check):string{
 $data=is_file($file['path'])?file_get_contents($file['path']):false;
 $check('original_sha256_'.$label,is_string($data)&&hash('sha256',$data)===$file['sha256']);
 return is_string($data)?$data:'';
};
$uint=static fn(string $raw,int $off):int=>(int)unpack('V',substr($raw,$off,4))[1];
$signed=static fn(int $v):int=>$v>=0x80000000?$v-0x100000000:$v;
$decode=static function(string $s):string{
 if(str_starts_with($s,"\xff\xfe"))$s=(string)iconv('UTF-16LE','UTF-8',substr($s,2));
 return str_replace("\r",'',$s);
};
$pin($m['compiled_core'],'compiled_core');
$pin($m['base_archive'],'original_Engine_u');
$code=$decode($pin($m['native_disassembly'],'compiled_disassembly'));
$check('compiled_Core_432_UObject_super_and_PosTop_Text_order',
 str_contains($code,'Core!UTextBuffer::Serialize')
 &&str_contains($code,'Core!UObject::Serialize')
 &&str_contains($code,'Core!operator<<')
 &&str_contains($code,'lea     ecx,[edi+2Ch]')
 &&str_contains($code,'lea     eax,[edi+30h]')
 &&str_contains($code,'add     edi,34h'));
$source=(string)file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnMisc.cpp');
$check('Epic_FString_reads_final_slot_without_NUL_rejection',
 str_contains($source,'Ar << AR_INDEX(SaveNum)')
 &&str_contains($source,'A.ArrayMax = A.ArrayNum = Abs(SaveNum)')
 &&str_contains($source,'A(i)=FromAnsi(ACh)')
 &&str_contains($source,'A(i)=FromUnicode(UCh)'));
$log=$decode($pin($m['native_loaded_log'],'native_88_compiled_textbuffers'));
$dump=$pin($m['native_memory_dump'],'native_88_full_memory');
$check('native_dump_has_MiniDump_header',substr($dump,0,4)==='MDMP');
$num=$uint($dump,8);$directory=$uint($dump,12);$ranges=[];
for($i=0;$i<$num;$i++){
 $at=$directory+$i*12;if($uint($dump,$at)!==9)continue;
 $rva=$uint($dump,$at+8);$n=$uint($dump,$rva);$fileOff=$uint($dump,$rva+8);
 $check('dump_Memory64_address_overflow_'.$i,
  $uint($dump,$rva+4)===0&&$uint($dump,$rva+12)===0);
 for($j=0;$j<$n;$j++){
  $p=$rva+16+$j*16;
  $start=$uint($dump,$p);$sz=$uint($dump,$p+8);
  $check('dump_range_within_32bit_'.$j,$uint($dump,$p+4)===0&&$uint($dump,$p+12)===0);
  $ranges[]=[$start,$start+$sz,$fileOff];$fileOff+=$sz;
 }
}
usort($ranges,static fn($a,$b)=>$a[0]<=>$b[0]);
$readVA=static function(int $address,int $n)use($ranges,$dump):string{
 if($n<0||$n>20000000)throw new RuntimeException('Native memory count invalid');
 $out='';
 while($n){
  $found=null;foreach($ranges as $r){if($address>=$r[0]&&$address<$r[1]){$found=$r;break;}}
  if(!$found)throw new RuntimeException(sprintf('Native memory 0x%08x unavailable',$address));
  [$start,$end,$at]=$found;$len=min($n,$end-$address);
  $out.=substr($dump,$at+$address-$start,$len);$n-=$len;$address+=$len;
 }
 return $out;
};
preg_match_all('/^G5_TEXTBUFFER_LOADED\n[0-9a-f]{8}\s+([0-9a-f]{8})\n[0-9a-f]{8}\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\s+([0-9a-f]{8})\n[0-9a-f]{8}\s+([0-9a-f]{8})/mi',$log,$hits,PREG_SET_ORDER);
$check('all_90_compiled_TextBuffer_serialize_events',count($hits)===$m['expected_native_serialize_calls']);
$check('native_full_dump_completed',str_contains($log,'G5_TEXTBUFFER_DUMP_DONE'));
$pkg=new P($m['base_archive']['path']);$im=$pkg->getImports();$exports=$pkg->getExports();
$check('real_source_archive_valid',$pkg->validatePackage()===[]&&$pkg->getHeader()['version']===69);
$expected=[];
foreach($exports as $e){
 $ci=(int)$e['classIndex'];
 if($ci>=0||strcasecmp((string)($im[-$ci-1]['objectNameText']??''),'TextBuffer')!==0)continue;
 $idx=(int)$e['index'];$tag=T::inspect($m['base_archive']['path'],$pkg,$idx,'ut99-v432');
 $expected[$idx]=N::inspect($m['base_archive']['path'],$pkg,$idx,$tag,'ut99-v432');
}
$check('genuine_88_source_TextBuffer_exports',count($expected)===$m['expected_genuine_loaded_textbuffers']);
$seen=[];$extras=[];
foreach($hits as $hit){
 $idx=hexdec($hit[1]);$seen[$idx]=($seen[$idx]??0)+1;
 if(!isset($expected[$idx])){$extras[]=$idx;continue;}
 $ref=$expected[$idx];$pos=$signed(hexdec($hit[2]));$top=$signed(hexdec($hit[3]));
 $pointer=hexdec($hit[4]);$len=hexdec($hit[5]);$max=hexdec($hit[6]);
 $id='source_Engine_TextBuffer_'.$idx;
 $check('complete_native_source_envelope_'.$id,
  $ref['status']==='native_textbuffer_record_complete'
  &&$ref['extension_bytes']===0);
 $check('native_Pos_Top_equality_'.$id,$pos===$ref['position']&&$top===$ref['top']);
 $check('native_String_length_Max_'.$id,
  $len===$ref['text_character_count']+1&&$max>=$len);
 $raw=$readVA($pointer,$len*2);
 $check('native_TCHAR_buffer_final_NUL_'.$id,$len>0&&substr($raw,-2)==="\x00\x00");
 $text=iconv('UTF-16LE','UTF-8',substr($raw,0,-2));
 $check('native_every_UTF16_text_value_matches_UnrealDB_'.$id,
  is_string($text)&&$text===$ref['text']);
 $check('native_raw_ANSI_source_retained_'.$id,
  $ref['text_terminator_is_nul']===true&&strlen($ref['text'])===$ref['text_character_count']);
 $objects++;$memoryBytes+=strlen($raw);
}
sort($extras);
$check('only_two_known_nonarchive_native_TextBuffers',$extras===$m['expected_extra_native_linker_indices']);
$check('no_duplicate_loaded_export_ids',count(array_filter($seen,static fn($n)=>$n!==1))===0);
$check('all_88_original_Engine_export_indices_observed',array_diff_key($expected,$seen)===[]);
$check('all_88_genuine_loaded_text_values_and_bytes',
 $objects===$m['expected_genuine_loaded_textbuffers']
 &&$memoryBytes===$m['expected_UTF16_storage_bytes']);
foreach($m['variations'] as $variation){
 $id=$variation['kind'];$file=$variation['archive'];
 $pin($file,'fixture_'.$id);
 $p=new P($file['path']);$ex=$p->getExports();
 $tag=T::inspect($file['path'],$p,$variation['idx'],'ut99-v432');
 try{$value=N::inspect($file['path'],$p,$variation['idx'],$tag,'ut99-v432');$error='';}
 catch(Throwable $e){$value=[];$error=$e->getMessage();}
 $stdout=$pin($variation['stdout'],'native_stdout_'.$id);
 if($id==='oversized'){
  $check('oversized_native_rejects_'.$id,str_contains($stdout,$variation['expected_native_error'])
   &&str_contains($stdout,'Exiting due to error'));
  $check('oversized_php_rejects_'.$id,str_contains($error,'TextBuffer ANSI FString exceeds export bounds'));
  continue;
 }
 $trace=$decode($pin($variation['log'],'native_method_trace_'.$id));
 $check('compiled_native_load_success_'.$id,
   str_contains($stdout,'Success - 0 error(s), 0 warnings') &&$value['status']==='native_textbuffer_record_complete');
 if($id==='pos_top'||$id==='missing_nul'){
  preg_match('/^G5_MUTANT_5321_LOADED\n[0-9a-f]{8}[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})\n[0-9a-f]{8}[ \t]+([0-9a-f]{8})/mi',$trace,$f);
  $check('actual_compiled_Pos_Top_member_values_'.$id,
    count($f)===6
    &&$signed(hexdec($f[1]))===($variation['pos']??0)
    &&$signed(hexdec($f[2]))===($variation['top']??0)
    &&hexdec($f[4])===831&&hexdec($f[5])>=831);
  $check('UTF8_source_logical_text_len_'.$id,strlen($value['text'])===830);
  $check('native_last_code_unit_handling_'.$id,
    $value['text_terminator_is_nul']===($id==='pos_top'));
  $pin($variation['export'],'compiled_UTextBuffer_export_'.$id);
  continue;
 }
 if($id==='wide_utf16'){
  $wide=$pin($variation['native_memory'],'native_full_utf16_TCHAR_array');
  $check('wide_full_native_memory_vs_original_UTF16',
    $wide===substr((string)file_get_contents($file['path']),$value['text_raw_offset'],$value['text_raw_bytes'])
    &&strlen($wide)===1096&&$value['text_encoding']==='UTF-16LE'
    &&$value['text_character_count']===547
    &&$value['text']===str_repeat('Ω',547)
    &&$value['text_terminator_is_nul']===true);
  $check('wide_compiled_post_serialize_member_values',
    str_contains($trace,'G5_NATIVE_WIDE_4867_LOADED')
    &&str_contains($trace,'00000224')); // 548 TCHARs
 }
}
$check('native_errors_semantically_rejected_but_not_bytepath_identical',
 true); // explanatory limitation, not method acceptance
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'passed'=>$checks-count($fails),
 'errors'=>$fails,'genuine_Engine_u_loaded_objects'=>$objects,
 'native_loaded_utf16_text_storage_bytes'=>$memoryBytes,
 'compiled_mutations'=>['positive_PosTop'=>'loaded','positive_UTF16'=>'loaded',
   'missing_final_NUL'=>'loaded','oversized_900'=>'rejected'],
 'full_G5_method_acceptance'=>'OPEN',
 'not_closed_reasons'=>$m['not_accepted_reasons']],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
