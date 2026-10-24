#!/usr/bin/env php
<?php
/** Real UT99 69 MotionChunk loaded v432 header/full-first-track parity, DEV only. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogLegacyBinaryStream as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G5-MOTIONCHUNK-NATIVE.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fails=[];$moves=0;$firstTracks=0;$nestedValueBytes=0;
$ck=static function(string $msg,bool $ok)use(&$checks,&$fails):void{$checks++;if(!$ok)$fails[]=$msg;};
$pin=static function(string $path,string $sha,string $tag)use($ck):string{
 $data=is_file($path)?file_get_contents($path):false;
 $ck('SHA256_'.$tag,is_string($data)&&hash('sha256',$data)===$sha);
 return is_string($data)?$data:'';
};
$pin($m['engine'],$m['engine_sha256'],'compiled_UT99_v432_Engine');
$int=static fn(string $buf,int $off):int=>(int)unpack('V',substr($buf,$off,4))[1];
$readArray=static function(S $s,int $itemSize):array{
 $count=$s->compactIndex();
 if($count<0||$count>1000000||$itemSize<1)throw new RuntimeException('Invalid bounded source array count');
 return ['count'=>$count,'bytes'=>$s->bytes($count*$itemSize)];
};
foreach($m['cases'] as $c){
 $name=$c['name'];$nameId=$name.'_'.$c['id'];
 $pin($c['path'],$c['package_sha256'],$name.'_source_archive');
 $logBytes=$pin($c['log'],$c['log_sha256'],$name.'_compiled_log');
 $log=str_starts_with($logBytes,"\xff\xfe")?(string)iconv('UTF-16LE','UTF-8',substr($logBytes,2)):$logBytes;
 $ck('native_serializer_executed_'.$name,str_contains($log,'G5_MOVE_HEADERS_'.$name));
 $buffers=[];
 foreach($c['files'] as $tag=>$f)$buffers[$tag]=$pin($f['path'],$f['sha256'],$name.'_'.$tag);
 $pkg=new P($c['path']);
 $ck('real_genuine_v69_'.$name,$pkg->validatePackage()===[]&&$pkg->getHeader()['version']===69);
 $rec=N::inspect($c['path'],$pkg,$c['id'],T::inspect($c['path'],$pkg,$c['id'],'ut99-v432'),'ut99-v432');
 $header=$buffers['allmoves-headers'];
 $ck('source_archive_complete_'.$name,($rec['status']??null)==='native_animation_record_complete'&&($rec['extension_bytes']??null)===0);
 $ck('all_native_loaded_MotionChunk_structs_'.$name,
     strlen($header)===$c['moves']*88&&$rec['moves']['count']===$c['moves']);
 $stream=new S($c['path']);$stream->seek($rec['moves']['data_offset']);
 for($move=0;$move<$c['moves'];$move++){
  $id=$nameId.'_move'.$move;$h=substr($header,$move*88,88);
  $fields=$stream->bytes(24);
  $ck('native_root_speed_tracktime_start_flags_'.$id,
    strlen($h)===88&&substr($h,0,24)===$fields);
  $index=$readArray($stream,4);
  $ck('native_bone_indices_count_'.$id,$index['count']===$int($h,28)&&$int($h,32)>=$index['count']);
  if($move===0){
    $ck('all_first_move_BoneIndices_bytes_'.$name,
      $index['count']===$c['bones']&&$index['bytes']===$buffers['move0-indices']);
    $nestedValueBytes+=strlen($buffers['move0-indices']);
  }
  $trackCount=$stream->compactIndex();
  $ck('native_AnimTracks_count_'.$id,$trackCount===$int($h,40)&&$int($h,44)>=$trackCount);
  if($move===0)$ck('native_first_move_AnimTracks_full_header_bytes_'.$name,
    strlen($buffers['move0-tracks'])===$trackCount*40&&$trackCount===$c['tracks']);
  for($track=0;$track<$trackCount;$track++){
   $flags=$stream->bytes(4);
   $keyQuat=$readArray($stream,16);
   $keyPos=$readArray($stream,12);
   $keyTime=$readArray($stream,4);
   if($move!==0)continue;
   $t=substr($buffers['move0-tracks'],$track*40,40);
   $ref=$id.'_track'.$track;
   $ck('full_first_move_track_native_flags_'.$ref,substr($t,0,4)===$flags);
   $ck('full_first_move_track_native_counts_'.$ref,
     $keyQuat['count']===$int($t,8)&&$keyPos['count']===$int($t,20)
     &&$keyTime['count']===$int($t,32)
     &&$int($t,12)>=$keyQuat['count']&&$int($t,24)>=$keyPos['count']
     &&$int($t,36)>=$keyTime['count']);
   if($track===0){
    foreach(['quat'=>$keyQuat,'pos'=>$keyPos,'time'=>$keyTime] as $kind=>$src){
      $ck('compiled_loaded_first_track_full_key_values_'.$name.'_'.$kind,
       $src['count']===$c['keys']
       &&$src['bytes']===$buffers['move0-track0-'.$kind]);
      $nestedValueBytes+=strlen($buffers['move0-track0-'.$kind]);
    }
   }
   $firstTracks++;
  }
  $rootFlags=$stream->bytes(4);
  $rootQuat=$readArray($stream,16);
  $rootPos=$readArray($stream,12);
  $rootTime=$readArray($stream,4);
  $ck('native_root_track_flags_'.$id,substr($h,48,4)===$rootFlags);
  $ck('native_root_track_counts_'.$id,
    $rootQuat['count']===$int($h,56)
    &&$rootPos['count']===$int($h,68)
    &&$rootTime['count']===$int($h,80)
    &&$int($h,60)>=$rootQuat['count']
    &&$int($h,72)>=$rootPos['count']
    &&$int($h,84)>=$rootTime['count']);
  // Production UnrealDB's first 16 MotionChunks are independently decoded;
  // compare fundamental source values with its production preview.
  if($move<16){
    $preview=$rec['moves']['records_preview'][$move]??[];
    $sourceStart=$int($fields,16);if($sourceStart>=0x80000000)$sourceStart-=0x100000000;
    $ck('production_move_flags_and_indices_'.$id,
      ($preview['flags']??null)===$int($fields,20)
      &&($preview['start_bone']??null)===$sourceStart
      &&($preview['bone_indices']['count']??null)===$index['count']
      &&($preview['anim_tracks']['count']??null)===$trackCount);
  }
  $moves++;
 }
 $ck('complete_source_native_moves_archived_extent_'.$name,
   $stream->tell()===$rec['moves']['data_offset']+$rec['moves']['archive_bytes']);
}
$ck('verified_seventy_real_MotionChunk_headers',$moves===70);
$ck('verified_95_full_first_move_AnalogTrack_headers',$firstTracks===95);
$ck('full_nested_value_buffers_compared',$nestedValueBytes===732);
echo json_encode(['ok'=>$fails===[],'checks'=>$checks,'passed'=>$checks-count($fails),
 'failures'=>$fails,'genuine_compiled_motion_chunk_headers'=>$moves,
 'first_move_AnalogTrack_headers'=>$firstTracks,
 'full_bone_indices_and_first_track_quat_pos_time_bytes'=>$nestedValueBytes,
 'limits'=>'All 70 MotionChunk scalar headers/root/array counts and 95 first-Move AnalogTrack headers; only first track nested array bytes from each package verified. The remaining nested KeyQuat/Pos/Time data of thousands of tracks not fully native checked. UAnimation FULL method OPEN.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fails===[]?0:1);
