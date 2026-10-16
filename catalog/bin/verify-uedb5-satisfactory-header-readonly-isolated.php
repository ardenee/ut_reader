<?php
declare(strict_types=1);
require 'C:/Apache24/htdocs/unrealdb/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreContainerHeaderReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339||stripos($id['datadir'],'uedb5-isolated-mysql')===false){throw new RuntimeException('Not the isolated database');}
$stored='C:/Temp/uedb5-iostore-retention-test';
$q=$db->query('SELECT id,utoc_relative_path,utoc_sha256 FROM ue_iostore_containers WHERE id=1 LIMIT 1')->fetch();
if(!$q||!str_starts_with((string)$q['utoc_relative_path'],'games/')){throw new RuntimeException('Missing expected isolated container');}
$path=realpath($stored.'/'.str_replace('\\','/',(string)$q['utoc_relative_path']));
if(!is_string($path)||!str_starts_with(strtolower(str_replace('\\','/',$path)),strtolower($stored).'/')){throw new RuntimeException('Invalid retained path');}
$toc=new Uedb5IoStoreTocReader($path,null,'C:/php8.5/ext/oo2core_9_win64.dll');
$chunks=$toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_CONTAINER_HEADER);
$results=[];
foreach(array_slice($chunks,0,2) as $index){
 $bytes=$toc->readChunk($index);
 $parsed=Uedb5IoStoreContainerHeaderReader::parse($bytes);
 $results[]=['chunk_index'=>$index,'source_bytes'=>strlen($bytes),'parsed_version'=>$parsed['version'],
  'package_ids'=>count($parsed['package_ids']),'optional_ids'=>count($parsed['optional_segment_package_ids']),
  'redirects'=>count($parsed['package_redirects'])];
}
$ok=count($results)>0;
echo json_encode(['ok'=>$ok,'read_only'=>true,'isolated_mysql'=>true,'results'=>$results],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
