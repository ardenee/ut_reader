<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerStorage;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong database');
$source='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/global.utoc';
$fixture='C:/Temp/uedb5-iostore-missing-part';
if(!is_dir($fixture))mkdir($fixture,0700,true);
copy($source,$fixture.'/orphan.utoc');
$before=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_containers')->fetchColumn();
$rejected=false;$reason='';
try{(new CatalogIoStoreContainerStorage($db,'C:/Temp/uedb5-iostore-retention-test'))->import(2,'unreal2',$fixture.'/orphan.utoc');}
catch(RuntimeException $e){$reason=$e->getMessage();$rejected=str_contains($reason,'Missing IoStore data partition');}
$after=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_containers')->fetchColumn();
@unlink($fixture.'/orphan.utoc');@rmdir($fixture);
$ok=$rejected&&$before===$after;
echo json_encode(['ok'=>$ok,'missing_partition_rejected'=>$rejected,'sql_rows_unchanged'=>$before===$after,'reason'=>$reason],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
