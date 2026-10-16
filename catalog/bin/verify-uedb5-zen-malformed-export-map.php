<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Reader;
$method=new ReflectionMethod(Reader::class,'readExportMap');
$reject=static function(string $bytes)use($method):bool{
 try{$method->invokeArgs(null,[$bytes,0,1,[['text'=>'Example']],[],[],1]);return false;}
 catch(RuntimeException){return true;}
};
$null=str_repeat("\xFF",8);
$base=pack('V2',0,0).pack('V2',0,0).pack('V2',0,0).$null.$null.$null.$null.str_repeat("\0",8).pack('V',0).chr(0).str_repeat("\0",3);
$short=substr($base,0,71);
$invalidName=substr_replace($base,pack('V',3),16,4);
$invalidIndex=substr_replace($base,pack('V2',2,0),24,8);
$shortRejected=$reject($short);
$nameRejected=$reject($invalidName);
$indexRejected=$reject($invalidIndex);
$ok=$shortRejected&&$nameRejected&&$indexRejected;
echo json_encode(['ok'=>$ok,'short_rejected'=>$shortRejected,'invalid_name_rejected'=>$nameRejected,'invalid_export_index_rejected'=>$indexRejected],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
