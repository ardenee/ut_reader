<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Reader;
$invoke=static fn(string $name,array $args)=>(new ReflectionMethod(Reader::class,$name))->invokeArgs(null,$args);
$reject=static function(callable $f):bool{try{$f();return false;}catch(RuntimeException){return true;}};
$null='FFFFFFFFFFFFFFFF';
$valid=$invoke('decodeObjectIndex',[$null,'test',[],[],1]);
$export=$invoke('decodeObjectIndex',['0000000000000000','test',[],[],1]);
$reservedRejected=$reject(static fn()=>$invoke('decodeObjectIndex',['C000000000000000','test',[],[],1]));
$outOfRangeRejected=$reject(static fn()=>$invoke('decodeObjectIndex',['0000000000000001','test',[],[],1]));
$ok=$valid['type']==='Null'&&$export['type']==='Export'&&$reservedRejected&&$outOfRangeRejected;
echo json_encode(['ok'=>$ok,'null_type'=>$valid['type'],'export_type'=>$export['type'],'reserved_rejected'=>$reservedRejected,'out_of_range_rejected'=>$outOfRangeRejected],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
