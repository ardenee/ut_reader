<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerBackupService;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos((string)$i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Unsafe DB');
$started=microtime(true);
$exporter=new CatalogIoStoreContainerBackupService($db,'C:/Temp/uedb5-iostore-retention-test');
$a=$exporter->export(1,'C:/Temp/uedb5-iostore-backup-test');
$restorer=new CatalogIoStoreContainerBackupService($db,'C:/Temp/uedb5-iostore-restored-test');
$b=$restorer->restore((string)$a['backup_path'],9002,'satisfactoryrestore');
$q=$db->prepare('SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=?');
$q->execute([$b['container_id']]);$members=(int)$q->fetchColumn();
$q=$db->prepare('SELECT relative_path,sha256,file_size FROM ue_iostore_container_parts WHERE container_id=?');
$q->execute([$b['container_id']]);$rows=$q->fetchAll();
$ok=$members===27240&&count($rows)===1&&$b['retention_status']==='ready';
foreach($rows as $part){
 $file='C:/Temp/uedb5-iostore-restored-test/'.$part['relative_path'];
 $ok=$ok&&is_file($file)&&filesize($file)===(int)$part['file_size']&&hash_file('sha256',$file)===$part['sha256'];
}
echo json_encode(['ok'=>$ok,'isolated'=>true,'export'=>$a,'restored'=>$b,'indexed_members'=>$members,'parts_verified'=>count($rows),'elapsed_seconds'=>round(microtime(true)-$started,2)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
