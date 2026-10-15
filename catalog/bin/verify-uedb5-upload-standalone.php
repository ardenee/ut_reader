<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/bootstrap.php';
require_once dirname(__DIR__).'/lib/CatalogScanner.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||strtolower(str_replace(chr(92),'/',$i['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe MySQL');
$config=catalog_config();
$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['queue']['name']='uedb5-isolated-upload';
$source=dirname(__DIR__).'/storage/games/unreal2/verified/d952319b3d907e395bea11d8b483187b.utx';
if(!is_file($source)||filesize($source)!==263609)throw new RuntimeException('Source identity changed');
$in=$config['storage_path'].'/incoming';if(!is_dir($in))mkdir($in,0700,true);
$tmp=$in.'/upload-'.bin2hex(random_bytes(6)).'.utx';copy($source,$tmp);
$result=null;$error=null;
try{$result=scanner_scan_uploaded_file($db,$config,2,$tmp,'CreditsT.utx',null,true,null,false,['source_relative_path'=>'isolated-backup/CreditsT.utx','defer_dependency_rebuild'=>true]);}
catch(Throwable $e){$error=$e->getMessage();}
$fileId=(int)($result[1]??0);
$q=$db->prepare('SELECT id,game_id,package_name,scan_status,relative_path FROM ue_files WHERE id=?');$q->execute([$fileId]);$file=$q->fetch();
$v=$db->prepare('SELECT format_version FROM ue_uedb5_files WHERE file_id=?');$v->execute([$fileId]);$format=(int)$v->fetchColumn();
$verified=false;try{if($fileId>0)(new Uedb5MetadataReader($config['storage_path']))->verify(2,$fileId);$verified=$fileId>0;}catch(Throwable $e){$error=($error??'').';verify:'.$e->getMessage();}
$ok=($result[0]??'')==='verified'&&$error===null&&($file['scan_status']??'')==='verified'&&$format===5&&$verified&&str_starts_with((string)($file['relative_path']??''),'storage/uedb5-isolated-reimport-20261015/');
echo json_encode(['ok'=>$ok,'result'=>$result,'file'=>$file,'v5_format'=>$format,'v5_verified'=>$verified,'error'=>$error],JSON_PRETTY_PRINT),PHP_EOL;
if(is_file($tmp))@unlink($tmp);
exit($ok?0:2);
