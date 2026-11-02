<?php
declare(strict_types=1);
$log=$argv[1]??'C:/Temp/ue427-p05-owner-stream.log';
$raw=@file_get_contents($log);
if(!is_string($raw))throw new RuntimeException('Missing genuine-linker native runtime log');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$header=null;$complete=null;
foreach(explode("\n",$raw) as $line){
 foreach(['UE427_NATIVE_P05_OWNER_HEADER|'=>'header','UE427_NATIVE_P05_OWNER_COMPLETE|'=>'complete'] as $tag=>$type){
  $at=strpos($line,$tag);if($at===false)continue;
  $v=explode('|',trim(substr($line,$at+strlen($tag))));
  if($type==='header')$header=$v;else $complete=array_map('intval',$v);
 }
}
$bulkOffset=isset($header[2])?(int)$header[2]:-1;
$expected=[
 'ordinary'=>[65793,16,$bulkOffset,0,1,1,'.ubulk'],
 'fixed'=>[257,16,$bulkOffset,0,1,1,'.ubulk'],
 'optional'=>[67585,16,$bulkOffset,0,1,1,'.uptnl'],
 'mapped'=>[69889,16,$bulkOffset,0,1,1,'.m.ubulk'],
 'mapped_fixed'=>[4353,16,$bulkOffset,0,1,1,'.m.ubulk'],
 'large64'=>[73985,16,$bulkOffset,0,1,1,'.ubulk'],
 'duplicate_optional'=>[82177,16,$bulkOffset,0,1,1,'.uptnl'],
 'duplicate_fallback'=>[82177,16,$bulkOffset,0,1,1,'.ubulk'],
];
$entries=[];$errors=[];$mapProbe=[];
foreach(explode("\n",$raw) as $line){
 $mapTag='UE427_NATIVE_P05_MAP_PROBE|';
 $mapAt=strpos($line,$mapTag);
 if($mapAt!==false){
  $parts=explode('|',trim(substr($line,$mapAt+strlen($mapTag))));
  if(count($parts)===4){
   $mapProbe[$parts[0]]=array_map('intval',array_slice($parts,1));
  }else $errors[]=['invalid_native_map_probe'=>$parts];
 }

 $tag='UE427_NATIVE_P05_OWNER_RUNTIME|';
 $pos=strpos($line,$tag);
 if($pos===false)continue;
 $v=explode('|',trim(substr($line,$pos+strlen($tag))));
 if(count($v)!==8){$errors[]=['bad_log_entry'=>$v];continue;}
 $name=$v[0];
 if(isset($entries[$name]))$errors[]=['duplicate'=>$name];
 $entries[$name]=[
  'fields'=>array_map('intval',array_slice($v,1,6)),
  'path'=>str_replace('\\','/',trim($v[7])),
 ];
}
foreach($expected as $name=>$wanted){
 if(!isset($entries[$name])){$errors[]=['missing'=>$name];continue;}
 $actual=$entries[$name]['fields'];
 $suffix=array_pop($wanted);
 $path=$entries[$name]['path'];
 if($actual!==$wanted||!str_ends_with(strtolower($path),strtolower('/Cube'.$suffix))
    ||!str_contains($path,'UE427Oracle/Content/UE427PayloadProbe/')){
  $errors[]=['case'=>$name,'numeric'=>$actual,'expected'=>$wanted,'path'=>$path];
 }
}
if(count($entries)!==count($expected))$errors[]=['unexpected_case_count'=>count($entries)];
if(count($header??[])!==3 || $header[0]!=='/Game/UE427PayloadProbe/Cube.Cube'
   || !str_ends_with(str_replace('\\','/',$header[1]),'/UE427PayloadProbe/Cube.uasset')
   || $bulkOffset<=0)$errors[]=['invalid_native_owner_header'=>$header];
if($complete!==[8,8])$errors[]=['incomplete_native_run'=>$complete];
foreach(['mapped','mapped_fixed'] as $name) {
 if(!isset($mapProbe[$name])||count($mapProbe[$name])!==3||$mapProbe[$name][1]!==1)
  $errors[]=['native_map_region_payload_check_failed'=>$name,'data'=>$mapProbe[$name]??null];
}
if(preg_match('/Assertion failed|Fatal error|UE427_NATIVE_OWNER_ERROR/i',$raw))$errors[]=['native_failure_in_log'=>true];
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'native_owner_linker_cases'=>count($entries),
 'source'=>'Real UE4.27 UObject/FLinkerLoad with compiled FUntypedBulkData::Serialize and CreateStreamingRequest',
 'streaming_payload_bytes_verified'=>count($entries)*16,
 'fields_verified'=>count($entries)*7,
 'owner_linker_bulk_start'=>$bulkOffset,
 'mapped_region_cases'=>count($mapProbe),
 'mapped_region_payload_bytes_verified'=>count($mapProbe)*16,
 'reported_mapping_alignment'=>$mapProbe['mapped'][0]??null,
 'limitations'=>'Mapped file streaming verified; native memory mapping pointer alignment branch is not certified',
 'mismatches'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
