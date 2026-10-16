<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader as Reader;
$parse=static fn(string $bytes,array $names)=>(new ReflectionMethod(Reader::class,'readExportMap'))->invokeArgs(null,[$bytes,0,1,$names,[],[],1]);
$null=str_repeat("\xFF",8);
$bytes=pack('V2',16,0).pack('V2',24,0).pack('V2',0,0).$null.$null.$null.$null.pack('V2',0xAB,0).pack('V',0x1234).chr(2)."\0\0\0";
if(strlen($bytes)!==Reader::EXPORT_MAP_ENTRY_SIZE){throw new RuntimeException('Malformed test fixture length');}
$record=$parse($bytes,[['text'=>'ExampleExport']])[0];
$ok=$record['object_name']['text']==='ExampleExport'
  &&$record['cooked_serial_offset']===16 &&$record['cooked_serial_size']===24
  &&$record['outer_index']['type']==='Null' &&$record['class_index']['type']==='Null'
  &&$record['super_index']['type']==='Null' &&$record['template_index']['type']==='Null'
  &&$record['filter_flags']===2 &&$record['object_flags']==='00001234';
echo json_encode(['ok'=>$ok,'name'=>$record['object_name']['text'],'serial_size'=>$record['cooked_serial_size'],'class_type'=>$record['class_index']['type'],'filter_flags'=>$record['filter_flags']],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
