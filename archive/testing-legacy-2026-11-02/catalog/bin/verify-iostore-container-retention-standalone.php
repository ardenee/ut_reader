<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerStorage;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong SQL');
$source='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/global.utoc';
$root='C:/Temp/uedb5-iostore-retention-test';
$store=new CatalogIoStoreContainerStorage($db,$root);
$a=$store->import(2,'unreal2',$source,null,'C:/php8.5/ext/oo2core_9_win64.dll');
$b=$store->import(2,'unreal2',$source,null,'C:/php8.5/ext/oo2core_9_win64.dll');
$files=$db->prepare('SELECT relative_path,file_size,sha256 FROM ue_iostore_container_parts WHERE container_id=?');
$files->execute([$a['container_id']]);$parts=$files->fetchAll();
$verified=true;
foreach($parts as $p){
 $path=$root.'/'.$p['relative_path'];
 $verified=$verified&&is_file($path)&&filesize($path)==(int)$p['file_size']&&hash_file('sha256',$path)===$p['sha256'];
}
$ok=$verified&&$a['retained_parts']===(int)$b['retained_parts']&&$b['status']==='existing';
echo json_encode(['ok'=>$ok,'isolated'=>true,'original_unchanged'=>true,'first'=>$a,'repeat'=>$b,'verified_retained_parts'=>count($parts)],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
