<?php
/** Reproducible P06 checkpoint. Passing does not certify all P06 branches. */
declare(strict_types=1);
$bin=__DIR__;
$checks=[
 'thumbnails_native'=>['verify-ue427-p06-native-thumbnails.php',[],
  ['indexed_packages'=>1724,'thumbnail_entries'=>1729,'native_entry_fields_compared'=>6916,'mismatch_count'=>0]],
 'thumbnails_corruptions'=>['verify-ue427-p06-thumbnail-negatives.php',[],[]],
 'registry_native_installed'=>['verify-ue427-p06-native-registry.php',[],
  ['counts.packages'=>1749,'counts.registry_objects'=>1730,'counts.registry_tags'=>12058,
   'counts.pre_dependency_format'=>1714,'counts.dependency_format'=>35,'mismatch_count'=>0]],
 'registry_native_cooked'=>['verify-ue427-p06-native-registry.php',
  ['C:/Temp/ue427-p05-optional-manifest.txt','C:/Temp/ue427-p06-registry-cooked-native.log'],
  ['counts.packages'=>245,'counts.registry_objects'=>0,'counts.pre_dependency_format'=>245,'mismatch_count'=>0]],
 'registry_corruptions'=>['verify-ue427-p06-registry-negatives.php',[],[]],
 'registry_dependency_native'=>['verify-ue427-p06-native-dependencies.php',[],
  ['counts.native_dependency_sections'=>35,'counts.php_dependency_sections'=>35,
   'counts.import_bits'=>598,'counts.soft_bits'=>9,'counts.bit_fields_compared'=>747,
   'mismatch_count'=>0]],
 'registry_dependency_corruptions'=>['verify-ue427-p06-dependency-negatives.php',[],[]],
 'world_chunk_native'=>['verify-ue427-p06-native-world-chunks.php',[],
  ['counts.fixtures'=>3,'counts.native_head_rows'=>3,'counts.native_world_rows'=>2,
   'counts.chunk_ids_compared'=>7,'counts.world_fields_compared'=>38,'mismatch_count'=>0]],
 'world_chunk_corruptions'=>['verify-ue427-p06-world-chunk-negatives.php',[],[]],
 'p05_full_regression'=>['verify-ue427-p05-acceptance.php',[],['verified_operations'=>12]],
 'ut4_v5_regression'=>['verify-uedb5-ut4-persistence.php',[],[]],
];
$results=[];$passed=0;
foreach($checks as $name=>[$script,$params,$expected]){
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg($bin.'/'.$script);
 foreach($params as $arg)$cmd.=' '.escapeshellarg($arg);
 $lines=[];$exit=0;exec($cmd.' 2>&1',$lines,$exit);
 $out=json_decode(implode("\n",$lines),true);$errors=[];
 if($exit!==0 || !is_array($out) || ($out['ok']??null)!==true)
  $errors[]=['bad_exit_or_response'=>$exit,'log'=>array_slice($lines,0,8)];
 if(is_array($out))foreach($expected as $path=>$value){
  $actual=$out;
  foreach(explode('.',$path) as $part)$actual=is_array($actual)?($actual[$part]??null):null;
  if($actual!==$value)$errors[]=['field'=>$path,'expected'=>$value,'actual'=>$actual];
 }
 $pass=$errors===[];
 $results[$name]=['ok'=>$pass,'exit'=>$exit,'failures'=>$errors];
 if($pass)$passed++;
}
$ok=$passed===count($checks);
echo json_encode(['ok'=>$ok,'scope'=>'PARTIAL P06 native thumbnail, AssetRegistry and WorldTile/ChunkID wire acceptance; semantic publication remains open',
 'passed'=>$passed,'total'=>count($checks),
 'remaining'=>['FAssetData synthesis','thumbnail image bytes',
  'world tile legacy-version positive cases and semantic consumers','production usage'],
 'results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
