<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-p02-suffix-native.log';
$list=$argv[2]??'C:/Temp/ue427-name-fixtures/list.txt';
$raw=@file_get_contents($log);$files=@file($list,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_string($raw)||!is_array($files))throw new RuntimeException('Native log or list missing');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
if(isset($files[0]))$files[0]=preg_replace('/^\xEF\xBB\xBF/','',$files[0]);
$strings=[];$suffixes=[];
foreach(explode("\n",$raw) as $line){
 if(($p=strpos($line,'UE427_NATIVE_STRING|'))!==false){
  $v=explode('|',trim(substr($line,$p+strlen('UE427_NATIVE_STRING|'))));
  if(count($v)!==3)throw new RuntimeException('Bad native FString row');
  $strings[(int)$v[0]]=[strtoupper($v[1]),strtoupper($v[2])];
 } elseif(($p=strpos($line,'UE427_NATIVE_SUFFIX|'))!==false){
  $v=explode('|',trim(substr($line,$p+strlen('UE427_NATIVE_SUFFIX|'))));
  if(count($v)!==4)throw new RuntimeException('Bad native suffix row');
  $suffixes[(int)$v[0]][(int)$v[1]]=[(int)$v[2],strtoupper($v[3])];
 }
}
$errors=[];$stringFields=0;$suffixFields=0;
foreach($files as $fileIndex=>$path){
 $reader=new UnrealPackageReader4($path);$h=$reader->getHeader();
 if($reader->validatePackage()!==[])$errors[]=['file'=>$fileIndex,'issues'=>$reader->validatePackage()];
 $native=$strings[$fileIndex]??null;
 $expected=[strtoupper(bin2hex((string)($h['folderName']??''))),strtoupper(bin2hex((string)($h['localizationId']??'')))];
 if($native!==$expected)$errors[]=['file'=>$fileIndex,'string'=>$native,'expected'=>$expected];
 $stringFields+=2;
 foreach($suffixes[$fileIndex]??[] as $nameIndex=>$entry){
  [$number,$hex]=$entry;
  $actual=strtoupper(bin2hex($reader->nameByIndex($nameIndex,$number)));
  $suffixFields++;
  if($hex!==$actual && count($errors)<35)$errors[]=['file'=>$fileIndex,'nameIndex'=>$nameIndex,'number'=>$number,'native'=>$hex,'unrealdb'=>$actual];
 }
}
$ok=count($files)===count($strings)&&$errors===[];
echo json_encode(['ok'=>$ok,'files'=>count($files),'string_fields_compared'=>$stringFields,
 'fname_suffixes_compared'=>$suffixFields,'mismatch_count'=>count($errors),'mismatches'=>array_slice($errors,0,25)],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
