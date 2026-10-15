<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
/** Copy bounded Unreal II source/V5 fixtures into a wholly separate storage subtree. */
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$identity['port']!==3339||strtolower(str_replace('\\','/',$identity['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Non-test database');
$catalogRoot=dirname(__DIR__);
$root=$catalogRoot.'/storage/uedb5-isolated-reimport-20261015';
$oldMetadata='C:/Temp/uedb5-integration-storage';
if(!is_dir($root))mkdir($root,0700,true);
$files=[111139,111194,111142,111141];$results=[];
foreach($files as $fileId){
 $q=$db->prepare('SELECT relative_path,stored_name,md5,file_size FROM ue_files WHERE id=?');
 $q->execute([$fileId]);$row=$q->fetch();
 if(!is_array($row))throw new RuntimeException('Missing isolated identity '.$fileId);
 $oldPath=$catalogRoot.'/'.str_replace('\\','/',$row['relative_path']);
 if(!is_file($oldPath))throw new RuntimeException('Source not found for '.$fileId);
 $name=basename((string)$row['stored_name']);
 if($name!==$row['stored_name'])throw new RuntimeException('Unsafe filename');
 $relative='storage/uedb5-isolated-reimport-20261015/games/unreal2/verified/'.$name;
 $target=$catalogRoot.'/'.$relative;
 if(!is_dir(dirname($target)))mkdir(dirname($target),0700,true);
 if((realpath($oldPath)!==realpath($target)&&!copy($oldPath,$target))||filesize($target)!==(int)$row['file_size']||
   strtolower(md5_file($target))!==strtolower((string)$row['md5']))throw new RuntimeException('Invalid source copy '.$fileId);
 $src=Uedb5MetadataContainer::path($oldMetadata,2,$fileId);
 $dst=Uedb5MetadataContainer::path($root,2,$fileId);
 if(!is_dir(dirname($dst)))mkdir(dirname($dst),0700,true);
 if(!copy($src,$dst)||hash_file('sha256',$src)!==hash_file('sha256',$dst))throw new RuntimeException('Invalid V5 copy '.$fileId);
 $db->prepare('UPDATE ue_files SET relative_path=? WHERE id=?')->execute([$relative,$fileId]);
 $results[]=['file_id'=>$fileId,'source_bytes'=>filesize($target),'v5_bytes'=>filesize($dst)];
}
echo json_encode(['ok'=>true,'isolated_storage'=>$root,'files'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
