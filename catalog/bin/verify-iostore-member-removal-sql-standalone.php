<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4',
 'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos((string)$i['datadir'],'uedb5-isolated-mysql')===false)
 throw new RuntimeException('Wrong MySQL instance');
if(!CatalogIoStoreContainerIndexer::schemaInstalled($db))throw new RuntimeException('IoStore schema missing.');
$q=$db->prepare('SELECT id,file_id,publication_status FROM ue_iostore_packages WHERE container_id=1 AND file_id IS NOT NULL AND publication_status="published" ORDER BY toc_index LIMIT 1');
$q->execute();$before=$q->fetch();
if(!is_array($before))throw new RuntimeException('Published fixture missing.');
$memberId=(int)$before['id'];$fileId=(int)$before['file_id'];
$db->beginTransaction();
try{
 $db->prepare('UPDATE ue_iostore_packages SET publication_status="indexed" WHERE file_id=?')->execute([$fileId]);
 $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
 $check=$db->prepare('SELECT publication_status,file_id FROM ue_iostore_packages WHERE id=?');
 $check->execute([$memberId]);$detached=$check->fetch();
 $ok=($detached['publication_status']??'')==='indexed'&&$detached['file_id']===null;
}finally{$db->rollBack();}
$q=$db->prepare('SELECT publication_status,file_id FROM ue_iostore_packages WHERE id=?');
$q->execute([$memberId]);$restored=$q->fetch();
$ok=$ok&&$restored['publication_status']==='published'&&(int)$restored['file_id']===$fileId;
echo json_encode(['ok'=>$ok,'isolated_transaction_rolled_back'=>true,
 'tested_file_id'=>$fileId,'detached_inside_transaction'=>$detached,'restored_after_rollback'=>$restored],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
