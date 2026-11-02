<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-p05-native-summary.log';
$manifest=$argv[2]??'C:/Temp/ue427-p05-cooked-manifest.txt';
$raw=@file_get_contents($log);
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_string($raw)||!is_array($paths))throw new RuntimeException('Missing Epic-native cooked-header evidence');
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
if(isset($paths[0]))$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
$native=[];$errors=[];
foreach(explode("\n",$raw) as $line){
 $tag='UE427_NATIVE_P05_HEADER|';
 $pos=strpos($line,$tag);if($pos===false)continue;
 $values=explode('|',trim(substr($line,$pos+strlen($tag))));
 if(count($values)!==4){$errors[]=['error'=>'bad native header row','row'=>$values];continue;}
 $file=(int)$values[0];
 if(isset($native[$file]))$errors[]=['error'=>'duplicate','file'=>$file];
 $native[$file]=array_map('intval',array_slice($values,1));
}
$fields=0;
foreach($paths as $index=>$path){
 $reader=new UnrealPackageReader4($path);
 if($reader->validatePackage()!==[]){$errors[]=['file'=>$index,'issues'=>$reader->validatePackage()];continue;}
 $h=$reader->getHeader();$entry=$native[$index]??null;
 $actual=[(int)($h['totalHeaderSize']??-1),(int)($h['bulkDataStartOffset']??-1),(int)($h['exportCount']??-1)];
 if($entry===null){$errors[]=['file'=>$index,'error'=>'missing native header'];continue;}
 $fields+=3;
 if($entry!==$actual && count($errors)<20)$errors[]=['file'=>$index,'epic'=>$entry,'unrealdb'=>$actual];
}
$ok=$errors===[]&&count($native)===count($paths);
echo json_encode(['ok'=>$ok,'authentic_cooked_packages'=>count($paths),
 'native_header_count'=>count($native),'native_header_fields_compared'=>$fields,
 'mismatches'=>array_slice($errors,0,20)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
