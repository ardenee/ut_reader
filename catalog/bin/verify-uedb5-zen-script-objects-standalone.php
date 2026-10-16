<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenScriptObjectsReader;
$pack = static fn(int $n): string => pack('V',$n);
$nameBatch = $pack(1).$pack(4).str_repeat("\0",16)."\0\x04".'Test';
$record = $pack(0x40000000).$pack(0).hex2bin('0100000000000040')
    .str_repeat("\xFF",8).str_repeat("\xFF",8);
$bytes = $nameBatch.$pack(1).$record;
$parsed = Uedb5ZenScriptObjectsReader::parse($bytes);
$object = reset($parsed['objects']);
$ok = $parsed['count']===1 && $parsed['runtime_authoritative']===false
    && $object['name']==='Test' && $object['runtime_resolved']===false;
$rejected = 0;
foreach ([$bytes."\0", $nameBatch.$pack(2).$record, $nameBatch.$pack(1).$record.$record] as $invalid) {
    try { Uedb5ZenScriptObjectsReader::parse($invalid); }
    catch (Throwable) { $rejected++; }
}
$ok = $ok && $rejected===3;
echo json_encode(['ok'=>$ok,'rejected_invalid'=>$rejected,'record'=>$object],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:1);
