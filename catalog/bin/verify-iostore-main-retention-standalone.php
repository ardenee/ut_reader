<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerStorage;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong SQL');
$source='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc';
$root='C:/Temp/uedb5-iostore-retention-test';
$service=new CatalogIoStoreContainerStorage($db,$root);
$started=microtime(true);
$a=$service->import(2,'unreal2',$source,null,'C:/php8.5/ext/oo2core_9_win64.dll');
$b=$service->import(2,'unreal2',$source,null,'C:/php8.5/ext/oo2core_9_win64.dll');
$parts=$db->prepare('SELECT relative_path,file_size,sha256 FROM ue_iostore_container_parts WHERE container_id=?');
$parts->execute([$a['container_id']]);$rows=$parts->fetchAll();
$valid=count($rows)===1;
foreach($rows as $row){$p=$root.'/'.$row['relative_path'];$valid=$valid&&is_file($p)&&filesize($p)===(int)$row['file_size']&&hash_file('sha256',$p)===$row['sha256'];}
$ok=$valid&&$a['package_count']===27240&&$b['status']==='existing';
echo json_encode(['ok'=>$ok,'isolated'=>true,'first'=>$a,'repeat'=>$b,'part_count'=>count($rows),'elapsed_seconds'=>round(microtime(true)-$started,2)],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
