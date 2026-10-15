<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerBackupService;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos((string)$i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Unsafe DB');
$backupRoot='C:/Temp/uedb5-iostore-backup-test';
$restoreRoot='C:/Temp/uedb5-iostore-restored-test';
if(!is_dir($backupRoot))mkdir($backupRoot,0700,true);
if(!is_dir($restoreRoot))mkdir($restoreRoot,0700,true);
$db->exec("INSERT INTO ue_games(id,name,slug,profile_id) VALUES (9002,'IoStore restore fixture','satisfactoryrestore',9001) ON DUPLICATE KEY UPDATE profile_id=9001");
$exporter=new CatalogIoStoreContainerBackupService($db,'C:/Temp/uedb5-iostore-retention-test');
$a=$exporter->export(2,$backupRoot);
$b=$exporter->export(2,$backupRoot);
$restorer=new CatalogIoStoreContainerBackupService($db,$restoreRoot);
$c=$restorer->restore((string)$a['backup_path'],9002,'satisfactoryrestore');
$parts=$db->prepare('SELECT part_index,relative_path,file_size,sha256 FROM ue_iostore_container_parts WHERE container_id=?');
$parts->execute([$c['container_id']]);$rows=$parts->fetchAll();
$valid=count($rows)===1;
foreach($rows as $row){
 $p=$restoreRoot.'/'.$row['relative_path'];
 $valid=$valid&&is_file($p)&&filesize($p)===(int)$row['file_size']&&hash_file('sha256',$p)===$row['sha256'];
}
$ok=$valid&&in_array($a['status'],['exported','existing'],true)&&$b['status']==='existing'
  &&$c['retention_status']==='ready'&&in_array($c['status'],['indexed','existing'],true);
echo json_encode(['ok'=>$ok,'source_read_only'=>true,'isolated_mysql_only'=>true,'export'=>$a,'replay'=>$b,'restore'=>$c,'parts_verified'=>count($rows)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
