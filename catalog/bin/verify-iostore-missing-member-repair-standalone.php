<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogIoStoreMemberReimportService;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStorePackageNameIndexer;
use UnrealDb\Catalog\Infrastructure\Import\CatalogIoStoreMemberCatalogPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339||stripos((string)$id['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Refuse non-isolated database');
$root='C:/Temp/uedb5-iostore-restored-test';$container=5;$gameId=9002;
$before=$db->query('SELECT profile_id FROM ue_games WHERE id=9002')->fetchColumn();
if($before===false)throw new RuntimeException('Disposable restored game missing');
$beforeFile=(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE game_id=2')->fetchColumn();
$db->exec('UPDATE ue_games SET profile_id=19 WHERE id=9002');
$away=null;$source=null;
try{
 $member=$db->query('SELECT m.toc_index,m.file_id,m.metadata_status,c.utoc_relative_path FROM ue_iostore_packages m JOIN ue_iostore_containers c ON c.id=m.container_id WHERE m.container_id=5 AND m.chunk_index=0 ORDER BY m.toc_index LIMIT 1')->fetch(PDO::FETCH_ASSOC);
 if(!$member)throw new RuntimeException('No restored package members');
 $indexer=new CatalogIoStorePackageNameIndexer($db,$root);
 if($member['metadata_status']!=='ready')$indexer->indexBatch($container,1);
 $tocFile=$root.'/'.str_replace(chr(92),'/',$member['utoc_relative_path']);
 $toc=new Uedb5IoStoreTocReader($tocFile);
 $publisher=new CatalogIoStoreMemberCatalogPublisher($db,$root);
 $published=$publisher->publish($toc,$container,(int)$member['toc_index']);
 $fileId=(int)$published['file_id'];
 $q=$db->prepare('SELECT relative_path,md5,sha1 FROM ue_files WHERE id=? AND game_id=9002');$q->execute([$fileId]);$file=$q->fetch(PDO::FETCH_ASSOC);
 if(!$file)throw new RuntimeException('Disposable published member missing');
 $source=$root.'/'.substr($file['relative_path'],strlen('storage/'));
 $away=$source.'.isolated-test-hold';
 if(!is_file($source)||is_file($away))throw new RuntimeException('Unsafe source repair fixture');
 if(!rename($source,$away))throw new RuntimeException('Cannot hide disposable member');
 $repair=(new CatalogIoStoreMemberReimportService($db,$root))->reimport($fileId);
 $ok=is_file($source)&&hash_equals($file['md5'],md5_file($source))&&hash_equals($file['sha1'],sha1_file($source))
 &&(int)$repair['file_id']===$fileId
 &&(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE game_id=2')->fetchColumn()===$beforeFile;
 echo json_encode(['ok'=>$ok,'isolated'=>true,'disposable_game'=>$gameId,'disposable_container'=>$container,'file_id'=>$fileId,'missing_source_repaired'=>$ok,'original_27240_member_dataset_unchanged'=>$ok],JSON_PRETTY_PRINT),PHP_EOL;
 exit($ok?0:2);
}finally{
 $db->prepare('UPDATE ue_games SET profile_id=? WHERE id=?')->execute([$before,$gameId]);
 if($away!==null&&is_file($away)&&!is_file($source))rename($away,$source);
 if($away!==null&&is_file($away)&&is_file($source))unlink($away);
}
