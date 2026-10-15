<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Import\CatalogZenIoStorePackagePublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_zen_satisfactory_test;charset=utf8mb4','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339 || strtolower(str_replace(chr(92),'/',$id['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')
 throw new RuntimeException('Refusing non-isolated MySQL.');
$dir='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks';
$toc=new Uedb5IoStoreTocReader($dir.'/FactoryGame-WindowsServer.utoc',null,'C:/php8.5/ext/oo2core_9_win64.dll');
$storage=sys_get_temp_dir().'/uedb5_satisfactory_mysql_test';
$service=new CatalogZenIoStorePackagePublisher($db,$storage);
$game=990001;
$checks=[];$failures=[];
$check=static function(bool $ok,string $name)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok)$failures[]=$name;};
$ids=[12=>980020012,13=>980020013];
foreach($ids as $idx=>$fileId) {
 $db->prepare('INSERT INTO ue_files(id,game_id,scan_status) VALUES(?,?,"verified") ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),scan_status=VALUES(scan_status)')->execute([$fileId,$game]);
 $packageId=$toc->chunks()[$idx]['package_id'];
 $first=$service->publish($toc,$packageId,$game,$fileId);
 $again=$service->publish($toc,$packageId,$game,$fileId);
 $check($first['status']==='published' || $first['status']==='existing','first_published_or_replayed_'.$idx);
 $check($again['status']==='existing','idempotent_replay_'.$idx);
 $query=$db->prepare('SELECT count(*) FROM ue_uedb5_files WHERE file_id=?');
 $query->execute([$fileId]);
 $check((int)$query->fetchColumn()===1,'unique_v5_registration_'.$idx);
}
$conflictRejected=false;
try{$service->publish($toc,$toc->chunks()[13]['package_id'],$game,$ids[12]);}
catch(RuntimeException $e){$conflictRejected=str_contains($e->getMessage(),'another package identity');}
$check($conflictRejected,'reused_file_id_cannot_change_package_id');
$otherId=980020003;
$db->prepare('INSERT INTO ue_files(id,game_id,scan_status) VALUES(?,?,"verified") ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),scan_status=VALUES(scan_status)')->execute([$otherId,$game]);
$dupRejected=false;
try{$service->publish($toc,$toc->chunks()[12]['package_id'],$game,$otherId);}
catch(RuntimeException $e){$dupRejected=str_contains($e->getMessage(),'already registered');}
$check($dupRejected,'one_package_id_cannot_register_twice');
$unverified=980020004;
$db->prepare('INSERT INTO ue_files(id,game_id,scan_status) VALUES(?,?,"failed") ON DUPLICATE KEY UPDATE game_id=VALUES(game_id),scan_status=VALUES(scan_status)')->execute([$unverified,$game]);
$unverifiedRejected=false;
try{$service->publish($toc,$toc->chunks()[14]['package_id'],$game,$unverified);}
catch(RuntimeException $e){$unverifiedRejected=str_contains($e->getMessage(),'matching verified');}
$check($unverifiedRejected,'unverified_identity_rejected');
echo json_encode(['ok'=>$failures===[],'isolated'=>true,'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT),PHP_EOL;
exit($failures===[]?0:2);
