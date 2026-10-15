<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Import\CatalogZenIoStorePackagePublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5IoStoreTocReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4',
 'root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$identity['port']!==3339||stripos((string)$identity['datadir'],'uedb5-isolated-mysql')===false)
 throw new RuntimeException('Refusing non-isolated MySQL.');
$root='C:/Temp/uedb5-iostore-retention-test';
$hash=$db->query('SELECT utoc_sha256 FROM ue_iostore_containers WHERE id=1')->fetchColumn();
$toc=new Uedb5IoStoreTocReader($root.'/games/unreal2/iostore/'.$hash.'/FactoryGame-WindowsServer.utoc',null,
 'C:/php8.5/ext/oo2core_9_win64.dll');
$service=new CatalogZenIoStorePackagePublisher($db,$root);
$checks=[];$failures=[];
$check=static function(bool $ok,string $label)use(&$checks,&$failures):void{
 $checks[$label]=$ok;if(!$ok)$failures[]=$label;
};
$records=[];
foreach([52,53] as $tocIndex){
 $q=$db->prepare('SELECT file_id,HEX(package_id) package_id FROM ue_iostore_packages WHERE container_id=1 AND toc_index=?');
 $q->execute([$tocIndex]);$row=$q->fetch(PDO::FETCH_ASSOC);
 if(!is_array($row)||!$row['file_id'])throw new RuntimeException('Full-schema published Zen fixture required.');
 $result=$service->publish($toc,$row['package_id'],2,(int)$row['file_id']);
 $records[]=$row;
 $check($result['status']==='existing','idempotent_v5_replay_'.$tocIndex);
 $q=$db->prepare('SELECT COUNT(*) FROM ue_uedb5_provider_keys WHERE file_id=?');
 $q->execute([$row['file_id']]);
 $check((int)$q->fetchColumn()>0,'provider_projection_'.$tocIndex);
 $q=$db->prepare('SELECT status FROM ue_uedb5_migration_status WHERE file_id=?');
 $q->execute([$row['file_id']]);
 $check($q->fetchColumn()==='staged','accurate_dependency_pending_'.$tocIndex);
}
$conflict=false;
try{$service->publish($toc,$records[1]['package_id'],2,(int)$records[0]['file_id']);}
catch(RuntimeException $e){$conflict=str_contains($e->getMessage(),'another package identity');}
$check($conflict,'same_file_cannot_change_package_id');
echo json_encode(['ok'=>$failures===[],'isolated'=>true,'checks'=>$checks,'failures'=>$failures],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:2);
