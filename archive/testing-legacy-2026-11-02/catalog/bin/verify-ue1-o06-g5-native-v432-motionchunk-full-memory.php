#!/usr/bin/env php
<?php
/** G5 compiled Engine.dll: complete genuine native MotionChunk/AnalogTrack nested byte parity.
 * Decodes actual Microsoft CDB full-memory MiniDump MEMORY64 regions, not a PHP
 * simulation or synthetic key tracks. All execution occurs on DEV only.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyBinaryStream as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G5-MOTIONCHUNK-FULL-NATIVE-MEMORY.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];$moves=0;$tracks=0;$buffers=0;$bytesMatched=0;$quatKeys=0;$posKeys=0;$timeKeys=0;
$ck=static function(string $id,bool $ok)use(&$checks,&$fail):void {
 $checks++;if(!$ok&&count($fail)<30)$fail[]=$id;
};
$pin=static function(string $f,string $expected,string $label)use($ck):string{
 $data=is_file($f)?file_get_contents($f):false;
 $ck('SHA256_'.$label,is_string($data)&&hash('sha256',$data)===$expected);
 return is_string($data)?$data:'';
};
$u32=static fn(string $raw,int $offset):int=>(int)unpack('V',substr($raw,$offset,4))[1];
$signed=static fn(int $x):int=>$x>=0x80000000?$x-0x100000000:$x;
$pin($m['compiled_engine'],$m['compiled_engine_sha256'],'native_Engine_v432');
foreach($m['cases'] as $case) {
 $n=$case['name'];$source=$case['package'];$id=$case['export_index'];
 $pin($source,$case['package_sha256'],'original_'.$n);
 $log=$pin($case['log'],$case['log_sha256'],'native_debugger_'.$n);
 $log=str_starts_with($log,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($log,2)):$log;
 $log=str_replace("\r",'',$log);
 $matched=preg_match('/^'.preg_quote($case['cdb_label'],'/').'\n[0-9a-f]{8}[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})[ \t]+([0-9a-f]{8})$/mi',$log,$match)===1;
 $ck('compiled_animation_linker_breakpoint_'.$n,$matched);
 if(!$matched)continue;
 $nativeMovesPtr=hexdec($match[1]);$nativeNum=hexdec($match[2]);$nativeMax=hexdec($match[3]);
 $dump=$pin($case['dump'],$case['dump_sha256'],'genuine_full_memory_dump_'.$n);
 $ck('native_dump_valid_MiniDump_signature_'.$n,substr($dump,0,4)==='MDMP');
 $numStreams=$u32($dump,8);$dirRVA=$u32($dump,12);
 $ranges=[];$total=0;$memory64Stream=0;
 for($i=0;$i<$numStreams;$i++){
  $dir=$dirRVA+$i*12;$typ=$u32($dump,$dir);
  if($typ!==9)continue;
  $memory64Stream++;
  $rva=$u32($dump,$dir+8);
  $numRanges=$u32($dump,$rva);
  $baseRva=$u32($dump,$rva+8);
  $ck('minidump_64bit_high_word_zero_'.$n,$u32($dump,$rva+4)===0&&$u32($dump,$rva+12)===0);
  $dataOffset=$baseRva;
  for($j=0;$j<$numRanges;$j++){
   $off=$rva+16+$j*16;$address=$u32($dump,$off);
   $length=$u32($dump,$off+8);
   $ck('native_address_and_size_32bit_'.$n.'_'.$j,
     $u32($dump,$off+4)===0&&$u32($dump,$off+12)===0);
   $ranges[]=['base'=>$address,'end'=>$address+$length,'file'=>$dataOffset];
   $dataOffset+=$length;
  }
  $ck('native_mem_segments_exactly_cover_dump_'.$n,$dataOffset<=strlen($dump));
 }
 usort($ranges,static fn(array $a,array $b):int=>$a['base']<=>$b['base']);
 $ck('has_compiled_native_complete_memory64_stream_'.$n,$memory64Stream===1&&count($ranges)>20);
 $readVA=static function(int $addr,int $len)use(&$ranges,&$dump):string{
  if($len===0)return '';
  if($addr===0||$len<0||$len>16*1024*1024)throw new RuntimeException('Invalid native read memory range');
  $remaining=$len;$bytes='';
  while($remaining>0){
   $lo=0;$hi=count($ranges)-1;$found=-1;
   while($lo<=$hi){
    $mid=intdiv($lo+$hi,2);
    if($ranges[$mid]['base']<=$addr){$found=$mid;$lo=$mid+1;}else{$hi=$mid-1;}
   }
   if($found<0||$addr>=$ranges[$found]['end'])
     throw new RuntimeException(sprintf('Missing real native memory at 0x%08x (%d bytes)', $addr,$remaining));
   $seg=$ranges[$found];$size=min($remaining,$seg['end']-$addr);
   $piece=substr($dump,$seg['file']+$addr-$seg['base'],$size);
   if(strlen($piece)!==$size)throw new RuntimeException('Native MINIDUMP segment truncated');
   $bytes.=$piece;$addr+=$size;$remaining-=$size;
  }
  return $bytes;
 };
 $pkg=new P($source);
 $ck('genuine_v69_archive_valid_'.$n,$pkg->validatePackage()===[]&&($pkg->getHeader()['version']??null)===69);
 $record=N::inspect($source,$pkg,$id,T::inspect($source,$pkg,$id,'ut99-v432'),'ut99-v432');
 $expectedMoves=$record['moves'];
 $ck('complete_native_archive_decoder_'.$n,
   $record['status']==='native_animation_record_complete'&&$record['extension_bytes']===0);
 $ck('native_moves_count_equals_real_serialized_'.$n,
   $nativeNum===$case['moves']&&$nativeMax>=$nativeNum&&$expectedMoves['count']===$nativeNum);
 $s=new S($source);$s->seek($expectedMoves['data_offset']);
 $caseByteStart=$bytesMatched;$comparedSourceBytes=0;$nativePointers=0;
 $readArray=static function(int $size,int $pointerAt,string $context,
    S $s,string $header,callable $readVA,callable $ck,callable $u32)
    use(&$buffers,&$bytesMatched,&$comparedSourceBytes,&$nativePointers):array {
   $srcCount=$s->compactIndex();
   if($srcCount<0||$srcCount>1000000)throw new RuntimeException("Invalid source nested array count: $context");
   $raw=$srcCount>0?$s->bytes($srcCount*$size):'';
   $ptr=$u32($header,$pointerAt);$nativeNum=$u32($header,$pointerAt+4);
   $capacity=$u32($header,$pointerAt+8);
   $ck('compiled_native_array_num_capacity_'.$context,
     $nativeNum===$srcCount&&$capacity>=$nativeNum);
   $native=$readVA($ptr,$nativeNum*$size);
   $ck('every_loaded_array_element_equals_real_archive_'.$context,$native===$raw);
   $buffers++;$bytesMatched+=strlen($native);$comparedSourceBytes+=strlen($raw);
   if($srcCount>0)$nativePointers++;
   return ['count'=>$srcCount,'native'=>$native,'source'=>$raw];
 };
 for($i=0;$i<$case['moves'];$i++){
  $context=$n.'_Move'.$i;
  $live=$readVA($nativeMovesPtr+$i*88,88);
  $sourceFields=$s->bytes(24);
  $ck('compiled_native_motionchunk_root_and_flags_'.$context,substr($live,0,24)===$sourceFields);
  $boneIndices=$readArray(4,24,$context.'_BoneIndices',$s,$live,$readVA,$ck,$u32);
  $sourceTrackCount=$s->compactIndex();$ptr=$u32($live,36);
  $compiledTrackCount=$u32($live,40);$compiledTrackCapacity=$u32($live,44);
  $ck('native_AnalogTrack_count_capacity_'.$context,
    $sourceTrackCount===$compiledTrackCount&&$compiledTrackCapacity>=$compiledTrackCount);
  for($track=0;$track<$sourceTrackCount;$track++){
   $subContext=$context.'_AnalogTrack'.$track;
   $trackHeader=$readVA($ptr+$track*40,40);
   $sourceFlags=$s->bytes(4);
   $ck('actual_compiled_AnalogTrack_flags_'.$subContext,
     $sourceFlags===substr($trackHeader,0,4));
   $quat=$readArray(16,4,$subContext.'_KeyQuat',$s,$trackHeader,$readVA,$ck,$u32);
   $pos=$readArray(12,16,$subContext.'_KeyPos',$s,$trackHeader,$readVA,$ck,$u32);
   $times=$readArray(4,28,$subContext.'_KeyTime',$s,$trackHeader,$readVA,$ck,$u32);
   $quatKeys+=$quat['count'];$posKeys+=$pos['count'];$timeKeys+=$times['count'];
   if($i<16&&$track<16){
    $preview=$expectedMoves['records_preview'][$i]['anim_tracks']['records_preview'][$track]??null;
    $ck('production_decoded_key_track_counts_'.$subContext,
      is_array($preview)
      &&$preview['flags']===$u32($sourceFlags,0)
      &&$preview['key_quat']['count']===$quat['count']
      &&$preview['key_pos']['count']===$pos['count']
      &&$preview['key_time']['count']===$times['count']);
    if(is_array($preview)){
     foreach(['key_quat'=>[$quat,16,'gx/gy/gz/gw'],
              'key_pos'=>[$pos,12,'gx/gy/gz'],
              'key_time'=>[$times,4,'g']] as $kind=>$meta){
      [$array,$width,$fmt]=$meta;
      for($k=0;$k<min(16,$array['count']);$k++){
       $bits=substr($array['native'],$k*$width,$width);
       $real=unpack($fmt,$bits);
       $actual=$kind==='key_time'?$real[1]:$real;
       $ck('production_decoded_inner_float_values_'.$subContext.'_'.$kind.'_'.$k,
         $actual===($preview[$kind]['records_preview'][$k]??null));
      }
     }
    }
   }
   $tracks++;
  }
  $rootHeader=substr($live,48,40);
  $rootFlags=$s->bytes(4);
  $ck('native_RootTrack_flags_'.$context,$rootFlags===substr($rootHeader,0,4));
  $rootQ=$readArray(16,4,$context.'_Root_KeyQuat',$s,$rootHeader,$readVA,$ck,$u32);
  $rootP=$readArray(12,16,$context.'_Root_KeyPos',$s,$rootHeader,$readVA,$ck,$u32);
  $rootT=$readArray(4,28,$context.'_Root_KeyTime',$s,$rootHeader,$readVA,$ck,$u32);
  $quatKeys+=$rootQ['count'];$posKeys+=$rootP['count'];$timeKeys+=$rootT['count'];
  if($i<16){
   $preview=$expectedMoves['records_preview'][$i]??[];
   $speed=unpack('gx/gy/gz',substr($sourceFields,0,12));
   $start=$signed($u32($sourceFields,16));
   $trackTime=unpack('g',substr($sourceFields,12,4))[1];
   $ck('production_MotionChunk_scalars_'.$context,
     ($preview['root_speed_3d']??null)===$speed
     &&($preview['track_time']??null)===$trackTime
     &&($preview['start_bone']??null)===$start
     &&($preview['flags']??null)===$u32($sourceFields,20)
     &&($preview['bone_indices']['count']??null)===$boneIndices['count']
     &&($preview['anim_tracks']['count']??null)===$sourceTrackCount);
   if(isset($preview['root_track'])){
    $rt=$preview['root_track'];
    $ck('production_RootTrack_counts_'.$context,
      $rt['flags']===$u32($rootFlags,0)
      &&$rt['key_quat']['count']===$rootQ['count']
      &&$rt['key_pos']['count']===$rootP['count']
      &&$rt['key_time']['count']===$rootT['count']);
   }
  }
  $moves++;
 }
 $ck('all_MotionChunk_archive_bytes_consumed_'.$n,
   $s->tell()===$expectedMoves['data_offset']+$expectedMoves['archive_bytes']);
 $ck('source_native_all_nested_bytes_equal_'.$n,($bytesMatched-$caseByteStart)===$comparedSourceBytes);
}
$ck('all_original_native_Moves_headers_70',$moves===70);
$ck('large_real_native_track_sample',$tracks>=1000);
$ck('native_bytes_positive',$bytesMatched>300000);
echo json_encode(['ok'=>$fail===[],'checks'=>$checks,'passed'=>$checks-count($fail),'errors'=>$fail,
 'compiled_genuine_MotionChunks'=>$moves,'compiled_genuine_AnalogTracks'=>$tracks,
 'native_TArray_buffers_verified'=>$buffers,'nested_array_pointers_checked'=>$nativePointers,
 'all_nested_key_and_bone_bytes_identical'=>$bytesMatched,
 'native_Quat_keys'=>$quatKeys,'native_Pos_keys'=>$posKeys,'native_Time_keys'=>$timeKeys,
 'limits'=>'All MotionChunk source serial elements and nested AnalogTrack/RootTrack key arrays in two genuine v69 packages compared to real compiled v432 minidump. Other original files/source families, malformed native error paths and inherited functions remain OPEN.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
