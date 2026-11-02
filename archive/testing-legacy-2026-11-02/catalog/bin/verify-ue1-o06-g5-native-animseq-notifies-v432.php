#!/usr/bin/env php
<?php
/** Compiled real v432 animation-sequence metadata and nested notifies. DEV only. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyBinaryStream as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-NATIVE-RESUMABLE-BATCH.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fails=[];$sequences=0;$notifies=0;$completeSequences=0;
$check=static function(string $id,bool $ok)use(&$checks,&$fails):void{$checks++;if(!$ok)$fails[]=$id;};
$pin=static function(string $f,string $sha,string $id)use($check):string{
 $raw=is_file($f)?file_get_contents($f):false;
 $check('hash_'.$id,is_string($raw)&&hash('sha256',$raw)===$sha);
 return is_string($raw)?$raw:'';
};
$txt=static function(string $data):string{
 $v=str_starts_with($data,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($data,2)):$data;
 return str_replace("\r",'',$v);
};
$pin($m['native_engine_path'],$m['engine_sha256'],'v432_dll');
foreach($m['cases'] as $case){
 if(!$case['seq'])continue;$name=$case['name'];
 $archive=$case['archive'];$pin($archive,$case['archive_sha256'],'genuine_'.$name);
 $p=new P($archive);$pkgNames=$p->getNames();
 $check('valid_version_and_structure_'.$name,$p->validatePackage()===[]&&$p->getHeader()['version']===69);
 $log=$txt($pin($case['seqLog'],$case['seqLog_sha256'],'CDB_sequences_'.$name));
 $check('native_source_resolved_'.$name,
   preg_match('/^G5_ANIMSEQ_'.preg_quote($name,'/').'_/m',$log)===1);
 $check('native_name_table_begin_end_'.$name,
   preg_match('/^G5_ANIMSEQ_NAMES_BEGIN\n(.*?)^G5_ANIMSEQ_NAMES_END$/ms',$log,$section)===1);
 preg_match_all('/^[0-9a-f]{8}[ \t]+"([^"]*)"$/mi',$section[1]??'',$named);
 $globalNames=$named[1]??[];
 $nativeNotifys=[];
 if($case['notifyLog']){
  $notifyLog=$txt($pin($case['notifyLog'],$case['notifyLog_sha256'],'compiled_notify_log_'.$name));
  $check('native_notify_debugger_completed_'.$name,
    str_contains($notifyLog,"G5_NATIVE_NOTIFYS_BEGIN\n")&&str_contains($notifyLog,"G5_NATIVE_NOTIFYS_END\n"));
  preg_match_all('/^SEQ_INDEX=(\d+) NOTIFY_COUNT=(\d+)\n(.*?)(?=^SEQ_INDEX=|^G5_NATIVE_NOTIFYS_END$)/ms',$notifyLog,$groups,PREG_SET_ORDER);
  foreach($groups as $group){
   preg_match_all('/^([0-9a-f]{8})[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})\n[0-9a-f]{8}[ \t]+"([^"]*)"$/mi',
     $group[3],$captured,PREG_SET_ORDER);
   $nativeNotifys[(int)$group[1]]=['expected'=>(int)$group[2],'rows'=>$captured];
  }
 }
 foreach($case['seq'] as $seq){
  $id=$seq['index'];$idStr=$name.'_'.$id;
  $mem=$pin($seq['path'],$seq['sha256'],'native_sequence_array_'.$idStr);
  $v=N::inspect($archive,$p,$id,T::inspect($archive,$p,$id,'ut99-v432'),'ut99-v432');
  $arr=$v['animation_sequences'];$count=$seq['count'];
  $check('source_native_animation_record_complete_'.$idStr,
    $v['status']==='native_animation_record_complete'&&$v['extension_bytes']===0);
  $check('native_full_sequence_struct_byte_count_'.$idStr,
    strlen($mem)===$count*32 && $arr['count']===$count);
  $check('runtime_resolved_FName_count_'.$idStr,count($globalNames)===$count*2);
  $stream=new S($archive);$stream->seek($arr['data_offset']);
  for($i=0;$i<$count;$i++){
   $a=$stream->compactIndex();$b=$stream->compactIndex();
   $start=$stream->bytes(4);$frames=$stream->bytes(4);
   $numNotifies=$stream->compactIndex();
   $sourceNotifies=[];
   for($n=0;$n<$numNotifies;$n++){
    $sourceNotifies[]=['time'=>$stream->bytes(4),'nameIndex'=>$stream->compactIndex()];
   }
   $rate=$stream->bytes(4);$native=substr($mem,$i*32,32);
   $k=$idStr.'_'.$i;
   $check('runtime_sequence_FName_'.$k,
     strcasecmp((string)($pkgNames[$a]['name']??''),(string)($globalNames[$i*2]??''))===0);
   $check('runtime_sequence_Group_'.$k,
     strcasecmp((string)($pkgNames[$b]['name']??''),(string)($globalNames[$i*2+1]??''))===0);
   $check('runtime_startframe_'.$k,$start===substr($native,8,4));
   $check('runtime_numframes_'.$k,$frames===substr($native,12,4));
   $check('runtime_rate_'.$k,$rate===substr($native,16,4));
   $check('runtime_notify_count_'.$k,unpack('V',substr($native,24,4))[1]===$numNotifies);
   $check('runtime_notify_capacity_'.$k,unpack('V',substr($native,28,4))[1]>=$numNotifies);
   if($case['notifyLog']&&$numNotifies){
    $known=$nativeNotifys[$i]??['expected'=>-1,'rows'=>[]];
    $check('native_notify_rows_count_'.$k,$known['expected']===$numNotifies&&count($known['rows'])===$numNotifies);
    for($n=0;$n<$numNotifies;$n++){
     $row=$known['rows'][$n]??null;$payload=$sourceNotifies[$n];
     $check('native_notify_time_'.$k.'_'.$n,
       $row!==null && strtolower($row[2])===bin2hex(strrev($payload['time'])));
     $check('native_notify_function_name_'.$k.'_'.$n,
       $row!==null && strcasecmp((string)($pkgNames[$payload['nameIndex']]['name']??''),(string)$row[4])===0);
     $notifies++;
    }
   }
   $sequences++;
  }
  $check('entire_source_AnimSeqs_consumed_'.$idStr,
    $stream->tell()===$arr['data_offset']+$arr['archive_bytes']);
  $completeSequences++;
 }
}
$check('two_compiled_native_animation_export_arrays',$completeSequences===2);
$check('all_seventy_real_AnimSeqs',$sequences===70);
$check('all_twenty_two_real_nested_notifies',$notifies===22);
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'passed'=>$checks-count($fails),'failures'=>$fails,
 'native_AnimSeq_objects'=>$completeSequences,'full_loaded_sequence_metadata_records'=>$sequences,
 'nested_native_notifies_verified'=>$notifies,
 'limitations'=>'Runtime FMeshAnimSeq serialized names, group, start, frames, rate, notify count and 22 notify functions/times verified; MotionChunk Moves nested arrays, negative errors, other programs and all method behavior remain OPEN.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
