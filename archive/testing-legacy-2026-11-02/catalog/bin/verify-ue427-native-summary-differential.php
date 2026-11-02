<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$log=$argv[1]??'C:/Temp/ue427-native-commandlet-stdout.log';
$list=$argv[2]??'C:/Temp/ue427-p01-real-assets.txt';
$raw=@file_get_contents($log);$paths=@file($list,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if(!is_string($raw)||!is_array($paths))throw new RuntimeException('Native Epic output or corpus list unavailable');
if(isset($paths[0]))$paths[0]=preg_replace('/^\\xEF\\xBB\\xBF/', '', $paths[0]);
if(str_starts_with($raw,chr(255).chr(254)))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$lines=explode("\n",$raw);
$keys=['version','licenseeVersion','totalHeaderSize','nameCount','nameOffset','importCount','importOffset','exportCount','exportOffset','dependsOffset','stringAssetReferencesCount','stringAssetReferencesOffset','preloadDependencyCount','preloadDependencyOffset','assetRegistryDataOffset','thumbnailTableOffset','compressionFlags'];
$count=0;$fields=0;$mismatch=[];
foreach($lines as $line){
 $marker='UE427_NATIVE_SUMMARY|';$pos=strpos($line,$marker);if($pos===false)continue;
 $values=explode('|',trim(substr($line,$pos+strlen($marker))));$name=array_shift($values);
 $path=$paths[$count]??null;
 if(!is_string($path)||basename($path)!==$name){$mismatch[]=['file'=>$name,'error'=>'native input order mismatch','position'=>$count];$count++;continue;}
 $reader=new UnrealPackageReader4($path);$h=$reader->getHeader();
 if($reader->validatePackage()!==[])$mismatch[]=['file'=>$name,'errors'=>$reader->validatePackage()];
 if(count($values)!==count($keys)){$mismatch[]=['file'=>$name,'error'=>'field count mismatch'];$count++;continue;}
 foreach($keys as $i=>$key){
  $fields++;$actual=$h[$key]??null;$native=(int)$values[$i];
  if($actual===null||(int)$actual!==$native)$mismatch[]=['file'=>$name,'field'=>$key,'epic'=>$native,'unrealdb'=>$actual];
 }
 $count++;
}
$ok=$count===count($paths)&&$mismatch===[];
echo json_encode(['ok'=>$ok,'native_packages'=>$count,'native_fields_compared'=>$fields,'mismatch_count'=>count($mismatch),'mismatches'=>array_slice($mismatch,0,25)],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
