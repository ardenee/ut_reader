<?php
/** Compare compiled Epic FObjectThumbnail::Serialize to lazy UnrealDB decoder. */
declare(strict_types=1);
require dirname(__DIR__,2).'/UE4/UnrealPackageReader.php';
$manifest=$argv[1]??'C:/Temp/ue427-all-assets.txt';
$nativeFile=$argv[2]??'C:/Temp/ue427-p06-thumppayload-native.log';
$paths=@file($manifest,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
$raw=@file_get_contents($nativeFile);
if(!is_array($paths)||!is_string($raw))throw new RuntimeException('Missing genuine corpus or Epic native log');
$paths[0]=preg_replace('/^\xEF\xBB\xBF/','',$paths[0]);
if(str_starts_with($raw,"\xFF\xFE"))$raw=mb_convert_encoding(substr($raw,2),'UTF-8','UTF-16LE');
$rows=[];$errors=[];$end=false;
foreach(explode("\n",$raw) as $line){
 if(str_contains($line,'UE427_NATIVE_COUNT 1749'))$end=true;
 $at=strpos($line,'UE427_NATIVE_P06_IMAGE|');
 if($at===false)continue;
 $v=explode('|',trim(substr($line,$at+strlen('UE427_NATIVE_P06_IMAGE|'))));
 if(count($v)!==8){$errors[]=['invalid_native_line'=>$v];continue;}
 $i=(int)$v[0];$j=(int)$v[1];
 if(isset($rows[$i][$j])){$errors[]=['duplicate_native'=>$i.':'.$j];continue;}
 $rows[$i][$j]=[
   'fileOffset'=>(int)$v[2],'width'=>(int)$v[3],'height'=>(int)$v[4],
   'compressedDataSize'=>(int)$v[5],'hash'=>strtoupper($v[6]),'endOffset'=>(int)$v[7],
 ];
}
$count=['packages'=>0,'payloads'=>0,'empty_payloads'=>0,'compressed_bytes_hashed'=>0,'fields_compared'=>0,'native_payloads'=>array_sum(array_map('count',$rows))];
foreach($paths as $i=>$path){
 $r=new UnrealPackageReader4($path);
 $count['packages']++;
 if($r->validatePackage()!==[]){$errors[]=['reader_issues'=>$i,'issues'=>$r->validatePackage()];continue;}
 $indices=$r->getThumbnailIndex();
 if(count($indices)!==count($rows[$i]??[])){
  if(count($errors)<20)$errors[]=['file'=>$i,'wrong_payload_count'=>[count($indices),count($rows[$i]??[])]];continue;
 }
 foreach($indices as $j=>$entry){
  try{$p=$r->getThumbnailPayload($j);}
  catch(Throwable $e){if(count($errors)<20)$errors[]=['file'=>$i,'entry'=>$j,'exception'=>$e->getMessage()];continue;}
  $actual=[
   'fileOffset'=>$p['fileOffset'],'width'=>$p['width'],'height'=>$p['height'],
   'compressedDataSize'=>$p['compressedDataSize'],
   'hash'=>strtoupper(sha1($p['compressedData'])),'endOffset'=>$p['endOffset'],
  ];
  $count['payloads']++;$count['fields_compared']+=6;
  $count['compressed_bytes_hashed']+=$p['compressedDataSize'];
  if($p['compressedDataSize']===0)$count['empty_payloads']++;
  if(($rows[$i][$j]??null)!==$actual && count($errors)<20)
   $errors[]=['file'=>$i,'entry'=>$j,'native'=>$rows[$i][$j]??null,'reader'=>$actual];
 }
}
$ok=$end&&$count['packages']===1749&&$count['payloads']===1729
 &&$count['native_payloads']===1729&&$count['compressed_bytes_hashed']===84244023
 &&$count['empty_payloads']===234&&$errors===[];
echo json_encode(['ok'=>$ok,'scope'=>'Compiled Epic FObjectThumbnail::Serialize dimensions and SHA-1 compressed bytes (not pixels)',
 'counts'=>$count,'mismatch_count'=>count($errors),'mismatches'=>array_slice($errors,0,20)],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
