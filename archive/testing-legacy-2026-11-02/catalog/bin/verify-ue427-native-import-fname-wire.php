<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-p02-full-wire-native.log';
$list=$argv[2]??'C:/Temp/ue427-all-assets.txt';
$raw=@file_get_contents($log);$files=@file($list,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_string($raw)||!is_array($files))throw new RuntimeException('Oracle log/manifest missing');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
if(isset($files[0]))$files[0]=preg_replace('/^\xEF\xBB\xBF/','',$files[0]);
$native=[];$fatal=[];
foreach(explode("\n",$raw) as $line){
 if(($p=strpos($line,'UE427_NATIVE_FNAME_WIRE|'))!==false){
  $v=explode('|',trim(substr($line,$p+strlen('UE427_NATIVE_FNAME_WIRE|'))));
  if(count($v)!==4)throw new RuntimeException('Malformed native FName wire row');
  $native[(int)$v[0]]=[(int)$v[1],(int)$v[2],strtoupper($v[3])];
 }elseif(str_contains($line,'UE427_NATIVE_FNAME_WIRE_ERROR|')){
  $fatal[]=$line;
 }
}
$entries=0;$mismatch=[];$withImports=0;
foreach($files as $index=>$path){
 $reader=new UnrealPackageReader4($path);$h=$reader->getHeader();
 $has=(int)($h['importCount']??0)>0;
 if(!$has){if(isset($native[$index]))$mismatch[]=['file'=>$index,'unexpected_native_wire'=>true];continue;}
 $withImports++;
 if($reader->validatePackage()!==[]){$mismatch[]=['file'=>$index,'error'=>'reader issues'];continue;}
 $first=$reader->getImports()[0]['classPackage']??[];
 $expected=[(int)($first['index']??-1),(int)($first['number']??-1),
     strtoupper(bin2hex((string)($first['text']??'')))];
 $actual=$native[$index]??null;
 if($actual!==$expected&&count($mismatch)<25)$mismatch[]=['file'=>$index,'native'=>$actual,'unrealdb'=>$expected];
 $entries++;
}
$ok=count($native)===$withImports&&$mismatch===[]&&$fatal===[];
echo json_encode(['ok'=>$ok,'packages'=>count($files),'packages_with_imports'=>$withImports,
  'native_wire_records'=>count($native),'fname_fields_compared'=>$entries*3,
  'mismatch_count'=>count($mismatch),'native_errors'=>$fatal,'mismatches'=>$mismatch],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
