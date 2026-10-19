<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
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
 $ok=$errors===[];
 echo json_encode(['ok'=>$ok,'source_fixture'=>'existing optional-segment Zen regression','native_field_comparisons'=>$compared,'main_import'=>$decoded['store_entries'][0]['imported_package_ids'][0]??null,'optional_import'=>$decoded['optional_segment_store_entries'][0]['imported_package_ids'][0]??null,'errors'=>$errors],JSON_PRETTY_PRINT),PHP_EOL;
 exit($ok?0:2);
}finally{
 if(is_file($temp))unlink($temp);
 if(is_file($nativePath))unlink($nativePath);
}
