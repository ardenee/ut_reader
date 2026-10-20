<?php
declare(strict_types=1);
$dir=$argv[1]??'C:/Temp/ue427-p05-policy-fixtures';
$log=$argv[2]??'C:/Temp/ue427-p05-policy-native.log';
$raw=@file_get_contents($log);
if(!is_string($raw))throw new RuntimeException('Native UE4.27 policy log missing');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$expected=[
 'inline'=>[0x40,1,0,0,0,20,0,20,'.uexp'],
 'tail'=>[0x1,0,0,0,1,48,0,48,'.uexp'],
 'ordinary'=>[0x10101,0,1,0,0,16,0,16,'.ubulk'],
 'fixed'=>[0x101,0,1,0,1,-1008,1024,16,'.ubulk'],
 'optional'=>[0x10801,0,0,1,0,16,0,16,'.uptnl'],
 'mapped'=>[0x11101,0,1,0,0,16,0,16,'.m.ubulk'],
 'large64'=>[0x12101,0,1,0,0,16,0,16,'.ubulk'],
 'duplicate_optional'=>[0x14101,0,1,0,0,16,0,16,'.uptnl'],
 'duplicate_fallback'=>[0x14101,0,1,0,0,16,0,16,'.ubulk'],
];
foreach($expected as &$e)$e[]=0;
unset($e);
$expected['swapped']=[0x10101,0,1,0,0,16,0,16,'.ubulk',1];
$native=[];$errors=[];$totalBytes=0;$fields=0;
foreach(explode("\n",$raw) as $line){
 $tag='UE427_NATIVE_P05_POLICY|';$p=strpos($line,$tag);if($p===false)continue;
 $v=explode('|',trim(substr($line,$p+strlen($tag))));
 if(count($v)!==11){$errors[]=['malformed_native'=>$v];continue;}
 $name=$v[0];
 if(isset($native[$name]))$errors[]=['duplicate_native'=>$name];
 $native[$name]=array_merge(array_map('intval',array_slice($v,1,8)),[$v[9],(int)$v[10]]);
}
foreach($expected as $name=>$want){
 if(!isset($native[$name])){$errors[]=['case'=>$name,'no_native_record'=>true];continue;}
 $actual=$native[$name];$fields+=10;
 if($actual!==$want){$errors[]=['case'=>$name,'native'=>$actual,'expected'=>$want];continue;}
 [$flags,$inline,$separate,$optional,$needsFix,$rawOffset,$fixup,$physical,$ext,$swapped]=$actual;
 $meta=$dir.'/'.$name.'.bulkmeta';$payload=$dir.'/'.$name.$ext;
 $bytes=@file_get_contents($meta);$data=@file_get_contents($payload);
 if(!is_string($bytes)||!is_string($data)){$errors[]=['case'=>$name,'files_missing'=>true];continue;}
 $wide=($flags&0x2000)!==0;
 if(strlen($bytes)!==($wide?28:20)){$errors[]=['case'=>$name,'metadata_width'=>strlen($bytes)];continue;}
 $u32=static fn(string $bytes):int=> (int)unpack($swapped?'N':'V',$bytes)[1];
 $q64=static fn(string $bytes):int=> (int)unpack('q',$swapped?strrev($bytes):$bytes)[1];
 $signed32=static function(string $bytes)use($u32):int {
  $n=$u32($bytes);return $n>=0x80000000?$n-0x100000000:$n;
 };
 $rawFlags=$u32(substr($bytes,0,4));
 if($wide){
  $n=$q64(substr($bytes,4,8));$onDisk=$q64(substr($bytes,12,8));
  $off=$q64(substr($bytes,20,8));
 }else{
  $n=$signed32(substr($bytes,4,4));$onDisk=$signed32(substr($bytes,8,4));
  $off=$q64(substr($bytes,12,8));
 }
 $fields+=4;
 if($rawFlags!==$flags||$n!==16||$onDisk!==16||$off!==$rawOffset){
  $errors[]=['case'=>$name,'wire_fields'=>[$rawFlags,$n,$onDisk,$off]];continue;
 }
 if($needsFix && $rawOffset+$fixup!==$physical)$errors[]=['case'=>$name,'incorrect_fixup'=>true];
 if(!$needsFix && $rawOffset!==$physical)$errors[]=['case'=>$name,'unwanted_fixup'=>true];
 if(substr($data,$physical,16)!==implode('',array_map('chr',range(1,16)))){
  $errors[]=['case'=>$name,'payload_mismatch'=>true];continue;
 }
 $totalBytes+=16;
}
// Unlike the other policy fixtures, these two cases run the actual Epic
// FUntypedBulkData::Serialize loader against the generated persistent archive.
$runtime=[];$runtimeExpected=['inline'=>[16,136,64,20],'tail'=>[16,136,1,48]];
foreach(explode("\n",$raw) as $line){
 $tag='UE427_NATIVE_P05_RUNTIME|';$at=strpos($line,$tag);
 if($at===false)continue;
 $record=explode('|',trim(substr($line,$at+strlen($tag))));
 if(count($record)!==5){$errors[]=['bad_runtime_row'=>$record];continue;}
 $runtime[$record[0]]=array_map('intval',array_slice($record,1));
}
foreach($runtimeExpected as $name=>$want){
 $fields+=4;
 if(($runtime[$name]??null)!==$want)$errors[]=['bad_native_runtime'=>$name,'found'=>$runtime[$name]??null,'expected'=>$want];
}
$ok=$errors===[]&&count($native)===count($expected)&&count($runtime)===count($runtimeExpected);
echo json_encode(['ok'=>$ok,'cases'=>count($expected),'native_records'=>count($native),
 'fields_checked'=>$fields,'payload_bytes_read'=>$totalBytes,
 'compiled_bulkdata_runtime_cases'=>count($runtime),
 'classification'=>'Controlled native policy/archive fixtures; compiled FUntypedBulkData::Serialize tested inline and end-of-file without owner, owner/linker-driven sidecar loading not yet tested',
 'mismatch_count'=>count($errors),'mismatches'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
