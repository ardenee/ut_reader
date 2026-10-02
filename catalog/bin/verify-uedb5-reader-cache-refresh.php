#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-cache-refresh-'.bin2hex(random_bytes(6));
mkdir($tmp,0777,true);
$writer=new Uedb5MetadataSnapshotWriter($tmp);
$reader=new Uedb5MetadataReader($tmp);
$make=static fn(string $value):array=>[
 'file'=>['id'=>90001,'game_id'=>2,'package_name'=>'CacheTest','original_name'=>'CacheTest.u'],
 'package_family'=>'classic-linkerload','source_policy'=>'cache-refresh-test-v1',
 'section_schemas'=>['summary'=>'cache.summary.v1'],
 'sections'=>['summary'=>[['value'=>$value]]],
];
$failures=[];
try{
 $writer->write($make('before'));
 $first=$reader->snapshot(2,90001);
 if(($first['sections']['summary'][0]['value']??null)!=='before')$failures[]='initial_read';
 $writer->write($make('after'));
 $second=$reader->snapshot(2,90001);
 if(($second['sections']['summary'][0]['value']??null)!=='after')$failures[]='automatic_cache_refresh';
}catch(Throwable $e){$failures[]=$e->getMessage();}
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $p){$p->isDir()?@rmdir($p->getPathname()):@unlink($p->getPathname());}
@rmdir($tmp);
echo json_encode(['ok'=>$failures===[],'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
