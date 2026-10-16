<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$file=$root.'/catalog/compliance/inventory/B014-ue5-async-loading-symbols.json';
$inventory=json_decode((string)file_get_contents($file),true);
$src=getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'';
$path=rtrim($src,'/\\').'/'.$inventory['source']['path'];
if($src===''||!is_file($path)){fwrite(STDERR,"Epic source is unavailable\n");exit(2);}
$bytes=(string)file_get_contents($path);
$hash=hash('sha256',$bytes);
if(!hash_equals($inventory['source']['sha256'],$hash)){fwrite(STDERR,"Source fingerprint mismatch\n");exit(2);}
$anchors=[];
foreach(glob($root.'/catalog/compliance/batches/*.json')?:[] as $batch){
  $data=json_decode((string)file_get_contents($batch),true);
  foreach(($data['operations']??[]) as $op)foreach(($op['source']['symbols']??[]) as $anchor)$anchors[]=$anchor;
}
$rows=[];
foreach($inventory['symbols'] as $symbol){
  $present=strpos($bytes,$symbol)!==false;
  $mapped=false;
  foreach($anchors as $anchor)if(str_contains($anchor,$symbol)){$mapped=true;break;}
  $rows[]=['symbol'=>$symbol,'source_present'=>$present,'referenced'=>$mapped,'gap'=>$present&&!$mapped];
}
$gaps=array_values(array_filter($rows,fn($r)=>$r['gap']));
$missing=array_values(array_filter($rows,fn($r)=>!$r['source_present']));
echo json_encode(['ok'=>$missing===[],'batch'=>'B014','seeded_symbols'=>count($rows),'unmapped_count'=>count($gaps),'unmapped'=>array_column($gaps,'symbol'),'missing_source_symbols'=>array_column($missing,'symbol'),'certification'=>'not certified; seeded inventory only'],JSON_PRETTY_PRINT),PHP_EOL;
exit($missing===[]?0:1);
