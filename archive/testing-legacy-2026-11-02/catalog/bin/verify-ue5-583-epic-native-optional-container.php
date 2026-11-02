<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenMountedStoreContextBuilder;
$exe='H:/UE5-583-runtime-probe/Engine/Binaries/Win64/UnrealPackageTool.exe';
$builder=__DIR__.'/verify-uedb5-ue5-zen-package.php';
$original=file_get_contents($builder);
if(!is_string($original)||!is_file($exe))throw new RuntimeException('Native probe or source fixture missing');
$needle='$chunkId = static function';
if(substr_count($original,$needle)!==1)throw new RuntimeException('Container fixture source changed');
$nativePath=sys_get_temp_dir().'/ue583-optional-container-native-'.getmypid().'.bin';
$temp=__DIR__.'/_ue583_optional_generator_'.getmypid().'.php';
$generator=str_replace($needle,'file_put_contents('.var_export($nativePath,true).', $containerHeader);'.PHP_EOL.'exit(0);'.PHP_EOL.$needle,$original);
try{
 if(file_put_contents($temp,$generator)!==strlen($generator))throw new RuntimeException('Generator not written');
 $lines=[];$rc=0;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($temp).' 2>&1',$lines,$rc);
 if($rc!==0||!is_file($nativePath))throw new RuntimeException('Fixture generator failed: '.implode(' ',$lines));
 $decoded=Uedb5IoStoreContainerHeaderReader::parse(file_get_contents($nativePath));
 $out=[];$exit=0;
 exec(escapeshellarg($exe).' -ContainerHeaderProbe='.escapeshellarg($nativePath).' -unattended 2>&1',$out,$exit);
 $text=str_replace('\\n',"\n",implode("\n",$out));
 if(!in_array($exit,[0,777006],true))throw new RuntimeException('Epic native process failed: '.$exit);
 if(!preg_match('/^UE583_NATIVE_CONTAINER_COUNTS (\d+) (\d+) (\d+) (\d+) (\d+) (\d+)$/m',$text,$m))throw new RuntimeException('Missing Epic native counts');
 $errors=[];$compared=0;
 $counts=[count($decoded['package_ids']),count($decoded['optional_segment_package_ids']),24*count($decoded['store_entries']),24*count($decoded['optional_segment_store_entries']),count($decoded['localized_packages']),count($decoded['package_redirects'])];
 foreach($counts as $i=>$expected){$compared++;if((int)$m[$i+1]!==$expected)$errors[]=['count'=>$i,'native'=>$m[$i+1],'local'=>$expected];}
 $sections=[['MAIN','store_entries'],['OPTIONAL','optional_segment_store_entries']];
 foreach($sections as [$label,$key]){
  preg_match_all('/^UE583_NATIVE_CONTAINER_'.$label.' (\d+) ([0-9A-F]{16}) (\d+)$/m',$text,$entries,PREG_SET_ORDER);
  if(count($entries)!==count($decoded[$key]))$errors[]='Native '.$label.' row cardinality mismatch';
  foreach($entries as $entry){
   $i=(int)$entry[1];$row=$decoded[$key][$i]??[];
   $compared+=2;
   if($entry[2]!==($row['package_id']??null))$errors[]=['section'=>$label,'row'=>$i,'package_id'=>$entry[2]];
   if((int)$entry[3]!==count($row['imported_package_ids']??[]))$errors[]=['section'=>$label,'row'=>$i,'import_count'=>$entry[3]];
  }
  preg_match_all('/^UE583_NATIVE_CONTAINER_'.$label.'_IMPORT (\d+) (\d+) ([0-9A-F]{16})$/m',$text,$imports,PREG_SET_ORDER);
  $total=array_sum(array_map(static fn($row)=>count($row['imported_package_ids']),$decoded[$key]));
  if(count($imports)!==$total)$errors[]='Native '.$label.' import count mismatch';
  foreach($imports as $entry){
   $i=(int)$entry[1];$j=(int)$entry[2];$compared++;
   if($entry[3]!==($decoded[$key][$i]['imported_package_ids'][$j]??null))$errors[]=['section'=>$label,'entry'=>$i,'import'=>$j,'native'=>$entry[3]];
  }
 }
 // Native Epic FFilePackageStoreBackend performs an actual mount, read,
 // redirect lookup and unmount rather than merely deserializing a header.
 $checkNativeMount=static function(string $path,int $version,array $header) use($exe,&$compared,&$errors):void{
  $mount=[
   'container_id'=>$header['container_id'],'order'=>10,'sequence'=>0,
   'package_redirects'=>$header['package_redirects'],
   'localized_packages'=>$header['localized_packages'],
   'package_entries'=>[['package_id'=>$header['package_ids'][0],'file_id'=>1234]]
  ];
  $context=Uedb5ZenMountedStoreContextBuilder::build([$mount]);
  $localOk=($context['file_backend_entries'][$header['package_ids'][0]]['status']??null)==='Ok';
  $localRedirect=$context['package_redirects'][0]['target_package_id']??null;
  $out=[];$rc=0;
  exec(escapeshellarg($exe).' -FileStoreProbe='.escapeshellarg($path).' -unattended 2>&1',$out,$rc);
  $text=str_replace('\\n',"\n",implode("\n",$out));
  if(!in_array($rc,[0,777006],true)||!preg_match('/^UE583_NATIVE_FILESTORE_MOUNT (\d+) (\d+) (\d+) (\d+) ([0-9A-F]{16})$/m',$text,$m)){
   $errors[]=['version'=>$version,'native_filestore'=>'missing mount marker'];return;
  }
  $values=[(int)$m[1]===4&&$localOk,(int)$m[2]===count($header['store_entries'][0]['imported_package_ids']),(int)$m[3]===1,(int)$m[4]===1,$m[5]===$localRedirect];
  foreach($values as $i=>$ok){$compared++;if(!$ok)$errors[]=['version'=>$version,'mount_field'=>$i];}
  if(!preg_match('/^UE583_NATIVE_FILESTORE_UNMOUNT (\d+)$/m',$text,$unmount))$errors[]=['version'=>$version,'unmount'=>'marker missing'];
  else{$compared++;if((int)$unmount[1]!==1)$errors[]=['version'=>$version,'unmount'=>'not Missing'];}
 };
 $checkNativeMount($nativePath,5,$decoded);
 $checkNativePriority=static function(string $path,int $version,array $header) use($exe,&$compared,&$errors):void{
  $id=$header['package_ids'][0];
  $low=[
   'container_id'=>$header['container_id'].'-low','order'=>10,'sequence'=>0,
   'package_redirects'=>$header['package_redirects'],'localized_packages'=>$header['localized_packages'],
   'package_entries'=>[['package_id'=>$id,'file_id'=>1001]]
  ];
  $high=[
   'container_id'=>$header['container_id'].'-high','order'=>20,'sequence'=>1,
   'package_redirects'=>$header['package_redirects'],'localized_packages'=>$header['localized_packages'],
   'package_entries'=>[['package_id'=>$id,'file_id'=>2002]]
  ];
  $both=Uedb5ZenMountedStoreContextBuilder::build([$low,$high]);
  $restored=Uedb5ZenMountedStoreContextBuilder::build([$low]);
  $empty=Uedb5ZenMountedStoreContextBuilder::build([]);
  $output=[];$rc=0;
  exec(escapeshellarg($exe).' -FileStorePriorityProbe='.escapeshellarg($path).' -unattended 2>&1',$output,$rc);
  $text=str_replace('\\n',"\n",implode("\n",$output));
  if(!in_array($rc,[0,777006],true)||!preg_match('/^UE583_NATIVE_FILESTORE_PRIORITY (\d+) ([0-9A-F]{16}) (\d+) ([0-9A-F]{16}) (\d+)$/m',$text,$matches)){
   $errors[]=['version'=>$version,'native_priority'=>'missing marker'];return;
  }
  $checks=[
   (int)$matches[1]===4,
   $matches[2]==='1111222233334444',
   ($both['file_backend_entries'][$id]['file_id']??null)===2002,
   (int)$matches[3]===4,
   $matches[4]===($header['store_entries'][0]['imported_package_ids'][0]??''),
   ($restored['file_backend_entries'][$id]['file_id']??null)===1001,
   (int)$matches[5]===1,
   !isset($empty['file_backend_entries'][$id])
  ];
  foreach($checks as $i=>$ok){$compared++;if(!$ok)$errors[]=['version'=>$version,'priority_field'=>$i];}
 };
 $checkNativePriority($nativePath,5,$decoded);
 // Epic IoContainerHeader.cpp intentionally has no Latest upper-bound
 // rejection: future versions follow the >= SoftPackageReferencesOffset branch.
 $originalBytes=file_get_contents($nativePath);
 $futureVersions=[6,100];
 foreach($futureVersions as $version){
  file_put_contents($nativePath,substr_replace($originalBytes,pack('V',$version),4,4));
  $future=Uedb5IoStoreContainerHeaderReader::parse(file_get_contents($nativePath));
  $futureOutput=[];$futureRc=0;
  exec(escapeshellarg($exe).' -ContainerHeaderProbe='.escapeshellarg($nativePath).' -unattended 2>&1',$futureOutput,$futureRc);
  $futureText=str_replace('\\n',"\n",implode("\n",$futureOutput));
  if(!in_array($futureRc,[0,777006],true)||!preg_match('/^UE583_NATIVE_CONTAINER_COUNTS (\d+) (\d+) (\d+) (\d+) (\d+) (\d+)$/m',$futureText,$futureMatch))$errors[]=['version'=>$version,'error'=>'Epic future-version read failed'];
  $expectedCounts=[count($future['package_ids']),count($future['optional_segment_package_ids']),24*count($future['store_entries']),24*count($future['optional_segment_store_entries']),count($future['localized_packages']),count($future['package_redirects'])];
  foreach($expectedCounts as $i=>$count){$compared++;if((int)($futureMatch[$i+1]??-1)!==$count)$errors[]=['version'=>$version,'count_field'=>$i];}
  foreach(['MAIN'=>'store_entries','OPTIONAL'=>'optional_segment_store_entries'] as $label=>$key){
   if(!preg_match('/^UE583_NATIVE_CONTAINER_'.$label.'_IMPORT 0 0 ([0-9A-F]{16})$/m',$futureText,$futureImport)||$futureImport[1]!==$future[$key][0]['imported_package_ids'][0])$errors[]=['version'=>$version,'section'=>$label];
   $compared++;
  }
  $checkNativeMount($nativePath,$version,$future);
  $checkNativePriority($nativePath,$version,$future);
 }
 $ok=$errors===[];
 echo json_encode(['ok'=>$ok,'source_fixture'=>'existing optional-segment Zen regression','native_field_comparisons'=>$compared,'main_import'=>$decoded['store_entries'][0]['imported_package_ids'][0]??null,'optional_import'=>$decoded['optional_segment_store_entries'][0]['imported_package_ids'][0]??null,'errors'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;
 exit($ok?0:2);
}finally{
 if(is_file($temp))unlink($temp);
 if(is_file($nativePath))unlink($nativePath);
}
