<?php
declare(strict_types=1);
// B017 source-derived inventory. Conservative candidate extraction; never certifies automatically.
$root=dirname(__DIR__,2);
$src=getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'';
$relative='Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp';
$path=rtrim($src,'/\\').'/'.$relative;
if($src===''||!is_file($path)){fwrite(STDERR,"Pinned source unavailable\n");exit(2);}
$lines=file($path,FILE_IGNORE_NEW_LINES);
$pattern='/\\b(FPackageObjectIndex|FAsyncPackage2|FGlobalImportStore|FCookedScriptObjectsDebug)::([A-Za-z_][A-Za-z0-9_]*)\\s*\\(/';
$found=[];
foreach($lines as $n=>$line){
    if(!preg_match($pattern,$line,$m))continue;
    if(!preg_match('/^\\s*(?:[A-Za-z_][A-Za-z0-9_:<>*& ]+\\s+)?'.preg_quote($m[1],'/').'::'.preg_quote($m[2],'/').'\\s*\\(/',$line))continue;
    $key=$m[1].'::'.$m[2];
    $found[$key]??=['symbol'=>$key,'first_line'=>$n+1];
}
ksort($found);
$registered=[];
foreach(glob($root.'/catalog/compliance/batches/*.json')?:[] as $manifest){
    $data=json_decode((string)file_get_contents($manifest),true);
    foreach(($data['operations']??[]) as $op)foreach(($op['source']['symbols']??[]) as $symbol)$registered[]=$symbol;
}
$rows=[];
foreach($found as $row){
    $symbol=$row['symbol'];$name=substr($symbol,strpos($symbol,'::')+2);
    $mapped=false;
    foreach($registered as $anchor){if(str_contains($anchor,$symbol)||$anchor===$name){$mapped=true;break;}}
    $rows[]=$row+['mapped'=>$mapped];
}
$unmapped=array_values(array_filter($rows,static fn($r)=>!$r['mapped']));
$output=['batch'=>'B017','source'=>$relative,'sha256'=>hash_file('sha256',$path),'method'=>'source-derived qualified-definition candidates','candidate_count'=>count($rows),'unmapped_count'=>count($unmapped),'unmapped'=>$unmapped,'limitations'=>['Definition-pattern inventory only; virtual dispatch and indirect serialization remain to inventory','Mapped means referenced by a manifest, not verified implementation']];
echo json_encode($output,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($unmapped===[]?0:1);
