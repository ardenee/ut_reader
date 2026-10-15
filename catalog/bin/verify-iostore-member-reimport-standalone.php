<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogIoStoreMemberReimportService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$identity['port']!==3339||stripos((string)$identity['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong DB');
$profile=$db->query('SELECT profile_id FROM ue_games WHERE id=2')->fetchColumn();
$root='C:/Temp/uedb5-iostore-retention-test';$fileId=111239;
$path=Uedb5MetadataContainer::path($root,2,$fileId);
$before=hash_file('sha256',$path);$result=null;
try{
 $db->exec('UPDATE ue_games SET profile_id=9001 WHERE id=2');
 $result=(new CatalogIoStoreMemberReimportService($db,$root))->reimport($fileId);
}finally{
 $db->prepare('UPDATE ue_games SET profile_id=? WHERE id=2')->execute([$profile]);
}
$after=hash_file('sha256',$path);
$q=$db->prepare('SELECT status,validator_policy FROM ue_uedb5_migration_status WHERE file_id=?');
$q->execute([$fileId]);$status=$q->fetch();
$ok=(int)$result['file_id']===$fileId&&$before===$after&&$status['status']==='staged'
 &&$status['validator_policy']==='uedb5-validation-v1';
echo json_encode(['ok'=>$ok,'isolated'=>true,'stable_file_id'=>$fileId,
 'v5_bytes_deterministic'=>$before===$after,'status'=>$status,'result'=>$result],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
