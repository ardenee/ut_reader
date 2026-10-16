<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Reader;
function invokePrivate(string $name,array $arguments): mixed {
    $method=new ReflectionMethod(Reader::class,$name);
    return $method->invokeArgs(null,$arguments);
}
function rejects(callable $fn):bool {try{$fn();return false;}catch(RuntimeException){return true;}}
$bytes=pack('V5',0,1,2,3,4);
$headers=invokePrivate('readDependencyHeaders',[$bytes,0,strlen($bytes),1]);
$valid=$headers[0]['entry_count'] === [[1,2],[3,4]] && $headers[0]['total_entry_count']===10;
$entries=pack('V3',1,0xFFFFFFFF,0);
$imports=[['type'=>'ScriptImport','value'=>'0000000000000001']];
$decoded=invokePrivate('readDependencyEntries',[$entries,0,strlen($entries),$imports,[],1]);
$valid=$valid && $decoded[0]['kind']==='Export' && $decoded[1]['kind']==='Import' && $decoded[2]['kind']==='Null';
$valid=$valid && rejects(static fn()=>invokePrivate('validateDependencyRanges',[[['first_entry_index'=>0,'total_entry_count'=>11]],10]));
$valid=$valid && rejects(static fn()=>invokePrivate('readDependencyEntries',[pack('V',2),0,4,[],[],1]));
$valid=$valid && rejects(static fn()=>invokePrivate('readDependencyEntries',[pack('V',0xFFFFFFFF),0,4,[],[],0]));
echo json_encode(['ok'=>$valid,'partition_counts_preserved'=>$headers[0]['entry_count'],'decoded_kinds'=>array_column($decoded,'kind'),'negative_bounds_rejected'=>$valid],JSON_PRETTY_PRINT),PHP_EOL;
exit($valid?0:2);
