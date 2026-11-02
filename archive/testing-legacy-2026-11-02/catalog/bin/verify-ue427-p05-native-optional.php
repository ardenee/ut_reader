<?php
declare(strict_types=1);
$log=$argv[1]??'C:/Temp/ue427-p05-optional-native.log';
$manifest=$argv[2]??'C:/Temp/ue427-p05-optional-manifest.txt';
$raw=@file_get_contents($log);
$files=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_string($raw)||!is_array($files))throw new RuntimeException('Missing native output or cooked manifest');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
if(isset($files[0]))$files[0]=preg_replace('/^\xEF\xBB\xBF/','',$files[0]);
$counts=[];$rows=[];$issues=[];
foreach(explode("\n",$raw) as $line){
 foreach(['UE427_NATIVE_OPTIONAL_COUNT|'=>'count','UE427_NATIVE_OPTIONAL|'=>'row','UE427_NATIVE_OPTIONAL_ERROR|'=>'error'] as $tag=>$kind){
  $p=strpos($line,$tag);if($p===false)continue;
  $fields=explode('|',trim(substr($line,$p+strlen($tag))));
  if($kind==='error'){$issues[]=['epic_native_error'=>$fields];break;}
  if(count($fields)!==($kind==='row'?7:3)){$issues[]=['invalid_native_record'=>$fields];break;}
  $v=array_map('intval',$fields);
  if($kind==='count'){$counts[$v[0]]=array_slice($v,1);}
  else {$rows[$v[0]][$v[1]]=array_slice($v,2);}
  break;
 }
}
$payloads=0;$payloadBytes=0;$digest=hash_init('sha256');$used=0;$assets=0;$fullFiles=0;
foreach($files as $i=>$path){
 $stem=preg_replace('/\.(uasset|umap)$/i','',$path);
 $bulk=$stem.'.uptnl';$exp=$stem.'.uexp';
 $exists=is_file($bulk);$length=$exists?(int)filesize($bulk):-1;
 if(!isset($counts[$i])){$issues[]=['file'=>$i,'missing_native_count'=>true];continue;}
 [$num,$nativeLength]=$counts[$i];
 if($nativeLength!==$length)$issues[]=['file'=>$i,'bulk_size'=>[$nativeLength,$length]];
 if($num!==count($rows[$i]??[]))$issues[]=['file'=>$i,'records'=>[$num,count($rows[$i]??[])]];
 if(!$exists){if($num!==0)$issues[]=['file'=>$i,'native_records_without_file'=>$num];continue;}
 $assets++;
 if(!is_file($exp)){$issues[]=['file'=>$i,'missing_uexp'=>true];continue;}
 $data=@file_get_contents($exp);if(!is_string($data))throw new RuntimeException('Cannot read cooked uexp');
 $fp=fopen($bulk,'rb');
 $cursor=0;$all=true;
 $set=$rows[$i]??[];
 $positions=[];
 foreach($set as $rowIndex=>$r){
  [$wirePos,$flags,$count,$size,$offset]=$r;
  $end=$wirePos+20;
  if($wirePos<0||$end>strlen($data)){$issues[]=['file'=>$i,'row'=>$rowIndex,'out_of_bounds'=>true];$all=false;continue;}
  $wire=unpack('Vflags/Vcount/Vsize/Poffset',substr($data,$wirePos,20));
  $same=($wire['flags']===$flags&&$wire['count']===$count&&$wire['size']===$size&&$wire['offset']===$offset);
  if(!$same){$issues[]=['file'=>$i,'row'=>$rowIndex,'wrong_native_wire'=>$r];$all=false;}
  if($flags!==0x10D01 || $size<1 || $size!==$count)$issues[]=['file'=>$i,'row'=>$rowIndex,'unexpected_bulk_flags_or_size'=>$r];
  if(isset($positions[$wirePos]))$issues[]=['file'=>$i,'duplicate_wire_pos'=>$wirePos];
  $positions[$wirePos]=true;
 }
 $sorted=array_values($set);usort($sorted,static fn(array $a,array $b)=>$a[4]<=>$b[4]);
 foreach($sorted as $row){
  [$position,$flags,$count,$size,$offset]=$row;
  if($cursor!==$offset || $offset<0 || $offset+$size>$length){
   if(count($issues)<40)$issues[]=['file'=>$i,'noncontiguous_bulk'=>[$cursor,$offset,$size,$length]];
   $all=false;continue;
  }
  fseek($fp,$offset);$h=hash_init('sha256');$read=hash_update_stream($h,$fp,$size);
  if($read!==$size){$issues[]=['file'=>$i,'short_bulk_read'=>[$size,$read]];$all=false;continue;}
  hash_update($digest,pack('V',$size).hash_final($h,true));
  $payloads++;$payloadBytes+=$size;$cursor+=$size;
 }
 fclose($fp);
 if($cursor!==$length)$issues[]=['file'=>$i,'bulk_coverage'=>[$cursor,$length]];
 elseif($all)$fullFiles++;
}
$isFullCorpus=count($files)>1;
$ok=count($counts)===count($files)&&$issues===[]
 &&(!$isFullCorpus || (count($files)===245&&$assets===32&&$fullFiles===32
   &&$payloads===87&&$payloadBytes===44497920));
echo json_encode(['ok'=>$ok,'files'=>count($files),'native_count_rows'=>count($counts),
'uptnl_files'=>$assets,'fully_covered_uptnl_files'=>$fullFiles,'native_optional_headers'=>$payloads,'payload_bytes_hashed'=>$payloadBytes,
'combined_span_digest_sha256'=>hash_final($digest),'mismatch_count'=>count($issues),'mismatches'=>array_slice($issues,0,25)],
JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
