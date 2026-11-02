<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$sourceRoot=getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'';
$sources=[
 'Engine/Source/Runtime/CoreUObject/Private/Serialization/PackageStore.cpp'=>'FB5C1930F5B8E6611392C3009DDE16922661C7942F2EAA8D875492C8981F67B9',
 'Engine/Source/Runtime/CoreUObject/Private/Serialization/ZenPackageHeader.cpp'=>'954117A45B9A802D3F289B30F2A0E191A7BB82CDCB107A22FEF134A73172995B',
];
$classified=[];
foreach(glob($root.'/catalog/compliance/inventory/B[0-9][0-9][0-9]-ue5-store*.json')?:[] as $path){
 $data=json_decode((string)file_get_contents($path),true);
 foreach((array)($data['reviewed']??[]) as $item){
  if(!empty($item['symbol'])&&!empty($item['disposition'])&&!empty($item['reason']))$classified[$item['symbol']]=$item['disposition'];
 }
}
$rows=[];$errors=[];
foreach($sources as $relative=>$expectedHash){
 $path=rtrim($sourceRoot,'/\\').'/'.$relative;
 if(!is_file($path)){$errors[]='missing:'.$relative;continue;}
 if(strtoupper(hash_file('sha256',$path))!==$expectedHash){$errors[]='hash_mismatch:'.$relative;continue;}
 $lines=file($path,FILE_IGNORE_NEW_LINES);
 foreach($lines as $lineIndex=>$line){
  if(!preg_match('/\\b(FPackageStore|FPackageStoreBackendContext|FZenPackageHeader)::([A-Za-z_][A-Za-z_0-9]*)\\s*\\(/',$line,$m))continue;
  if(!preg_match('/^\\s*(?:[A-Za-z_][A-Za-z_0-9:<>*& ]+\\s+)?'.preg_quote($m[1].'::'.$m[2],'/').'\\s*\\(/',$line))continue;
  $symbol=$m[1].'::'.$m[2];
  $rows[$symbol]=['symbol'=>$symbol,'source'=>$relative,'line'=>$lineIndex+1,'classified_as'=>$classified[$symbol]??null];
 }
}
ksort($rows);
$unclassified=array_values(array_filter($rows,static fn(array $r)=>$r['classified_as']===null));
echo json_encode(['ok'=>$errors===[]&&$unclassified===[],'sources'=>array_keys($sources),'candidate_count'=>count($rows),'classified_count'=>count($rows)-count($unclassified),'unclassified_count'=>count($unclassified),'unclassified'=>$unclassified,'errors'=>$errors,'scope'=>'Three qualified classes in two pinned source files; not a full UE5 source inventory'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]&&$unclassified===[]?0:1);
