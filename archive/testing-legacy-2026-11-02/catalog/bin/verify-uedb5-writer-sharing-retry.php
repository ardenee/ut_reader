#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
$tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'uedb5-sharing-retry-'.bin2hex(random_bytes(6));
mkdir($tmp,0777,true);
$writer=new Uedb5MetadataSnapshotWriter($tmp);
$reader=new Uedb5MetadataReader($tmp);
$make=static fn(string $value):array=>[
 'file'=>['id'=>90002,'game_id'=>2,'package_name'=>'SharingTest','original_name'=>'SharingTest.u'],
 'package_family'=>'classic-linkerload','source_policy'=>'sharing-retry-test-v1',
 'section_schemas'=>['summary'=>'sharing.summary.v1'],
 'sections'=>['summary'=>[['value'=>$value]]],
];
$failures=[];$process=null;$pipes=[];
try{
 $writer->write($make('before'));
 $path=$writer->path(2,90002);
 $helper=$tmp.DIRECTORY_SEPARATOR.'hold.php';
 file_put_contents($helper,"<?php\n\$h=fopen(\$argv[1],'rb');if(!is_resource(\$h))exit(2);echo \"READY\\n\";fflush(STDOUT);usleep(750000);fclose(\$h);\n");
 $process=proc_open([PHP_BINARY,$helper,$path],[0=>['file','NUL','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$tmp);
 if(!is_resource($process))throw new RuntimeException('Could not start sharing-lock helper.');
 if(trim((string)fgets($pipes[1]))!=='READY')throw new RuntimeException('Sharing-lock helper did not become ready.');
 $started=microtime(true);$writer->write($make('after'));$elapsed=microtime(true)-$started;
 $after=$reader->snapshot(2,90002);
 if(($after['sections']['summary'][0]['value']??null)!=='after')$failures[]='replacement_not_published';
 if(PHP_OS_FAMILY==='Windows'&&$elapsed<0.50)$failures[]='windows_lock_was_not_observed';
}catch(Throwable $e){$failures[]=$e->getMessage();}
if(is_resource($process)){foreach($pipes as $pipe){if(is_resource($pipe))fclose($pipe);}proc_close($process);}
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
foreach($it as $p){$p->isDir()?@rmdir($p->getPathname()):@unlink($p->getPathname());}
@rmdir($tmp);
echo json_encode(['ok'=>$failures===[],'elapsed_seconds'=>round($elapsed??0,3),'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
