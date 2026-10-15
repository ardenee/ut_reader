<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenIoStoreSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5CompactCandidatePublisher;
$mysql=new PDO('mysql:host=127.0.0.1;port=3339;charset=utf8mb4','root','',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false]);
$ident=$mysql->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||strtolower(str_replace(chr(92),'/',(string)$ident['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')
 throw new RuntimeException('Refusing non-isolated MySQL instance');
$schema='uedb5_zen_satisfactory_test';
$mysql->exec('CREATE DATABASE IF NOT EXISTS `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$mysql->exec('USE `'.$schema.'`');
$mysql->exec('CREATE TABLE IF NOT EXISTS ue_files(id BIGINT UNSIGNED PRIMARY KEY,game_id BIGINT UNSIGNED NOT NULL,scan_status VARCHAR(32) NOT NULL)');
foreach(['ue_uedb5_files','ue_uedb5_compact_name_dictionary','ue_uedb5_compact_name_candidates','ue_uedb5_compact_object_candidates'] as $table){
 $mysql->exec('CREATE TABLE IF NOT EXISTS `'.$table.'` LIKE uedb5_isolated_integration.`'.$table.'`');
}
$utoc='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
$dll='C:/php8.5/ext/oo2core_9_win64.dll';
if(!is_file($utoc)||!is_file($dll))throw new RuntimeException('Missing read-only Satisfactory source / Oodle');
$toc=new Uedb5IoStoreTocReader($utoc,null,$dll);
$indexes=array_slice($toc->chunkIndexesByType(Uedb5IoStoreTocReader::CHUNK_TYPE_EXPORT_BUNDLE_DATA),0,3);
$storage=sys_get_temp_dir().'/uedb5_satisfactory_mysql_test';
if(!is_dir($storage))mkdir($storage,0700,true);
$writer=new Uedb5MetadataSnapshotWriter($storage);
$registry=new PdoUedb5StagingRegistrationRepository($mysql,$storage);
$publisher=new PdoUedb5CompactCandidatePublisher($mysql,false);
$results=[];$errors=[];
foreach($indexes as $idx){
 $fileId=980000000+$idx;$gameId=990001;
 $snapshot=Uedb5Ue5ZenIoStoreSnapshotBuilder::build($toc,$toc->chunks()[$idx]['package_id'],[
  'id'=>$fileId,'game_id'=>$gameId,'package_name'=>'','original_name'=>'Satisfactory read-only sample']);
 try{
  $mysql->prepare('INSERT INTO ue_files(id,game_id,scan_status) VALUES(?,?,"verified") ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),scan_status=VALUES(scan_status)')->execute([$fileId,$gameId]);
  $written=$writer->write($snapshot,2);
  $registered=$registry->register($gameId,$fileId);
  $projection=$publisher->publish($snapshot,$registered);
  $query=$mysql->prepare('SELECT package_family,package_key_kind,HEX(package_key) key_hex FROM ue_uedb5_files WHERE file_id=?');
  $query->execute([$fileId]);$row=$query->fetch();
  $n=$mysql->prepare('SELECT COUNT(*) FROM ue_uedb5_compact_name_candidates WHERE file_id=?');$n->execute([$fileId]);
  $o=$mysql->prepare('SELECT COUNT(*) FROM ue_uedb5_compact_object_candidates WHERE file_id=?');$o->execute([$fileId]);
  if(($row['package_family']??'')!=='zen-iostore'||($row['key_hex']??'')!==$toc->chunks()[$idx]['package_id']||(int)$n->fetchColumn()<1)
   throw new RuntimeException('Zen registration or candidate projection mismatch');
  $o->execute([$fileId]);
  $results[]=['file_id'=>$fileId,'package_id'=>$row['key_hex'],'package_name'=>$snapshot['file']['package_name'],
   'candidate_names'=>(int)$projection['names'],'candidate_objects'=>(int)$projection['objects'],'v5_bytes'=>filesize($written['path'])];
 }catch(Throwable $e){$errors[]=['file_id'=>$fileId,'error'=>$e->getMessage()];}
}
$ok=count($results)===3&&$errors===[];
echo json_encode(['ok'=>$ok,'standalone_mysql_only'=>true,'schema'=>$schema,'source_read_only'=>true,'registered'=>$results,'errors'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
