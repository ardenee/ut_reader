<?php
declare(strict_types=1);
/** Real scanner-driven reimport, stable identity, isolated MySQL and file storage. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$ident=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||strtolower(str_replace('\\','/',$ident['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Refusing production DB');
$config=catalog_config();
$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['queue']['name']='uedb5-isolated-reimport';
$fileId=111139;
$before=$db->query('SELECT id,game_id,package_name,scan_status,md5,relative_path FROM ue_files WHERE id=111139')->fetch();
$result=null;$error=null;
try{
 $result=(new CatalogFileMaintenanceActionService($db,$config,null))->execute('sync_reimport',['file_id'=>$fileId]);
}catch(Throwable $e){$error=get_class($e).': '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine();}
$after=$db->query('SELECT id,game_id,package_name,scan_status,md5,relative_path FROM ue_files WHERE id=111139')->fetch();
$reg=$db->query('SELECT format_version,package_name FROM ue_uedb5_files WHERE file_id=111139')->fetch();
$good=false;$verification=null;
try{$verification=(new Uedb5MetadataReader($config['storage_path']))->verify(2,$fileId);$good=true;}catch(Throwable $e){$verification=$e->getMessage();}
$pass=$error===null && ($result['status']??'')==='reimported' &&(int)($after['id']??0)===$fileId
 &&($after['scan_status']??'')==='verified'&&($reg['format_version']??0)==5
 && $good && ($before['md5']??'')===($after['md5']??'');
echo json_encode(['ok'=>$pass,'isolated'=>true,'file_id'=>$fileId,'error'=>$error,'result'=>$result,'before'=>$before,'after'=>$after,
 'registration'=>$reg,'v5_verified'=>$good,'v5_verification'=>$good?'passed':$verification],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($pass?0:2);
