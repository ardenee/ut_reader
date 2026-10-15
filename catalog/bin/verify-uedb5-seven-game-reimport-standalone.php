<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceActionService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||strtolower(str_replace(chr(92),'/',$i['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe database');
$config=catalog_config();
$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['queue']['name']='uedb5-reimport-seven-'.bin2hex(random_bytes(4));
$service=new CatalogFileMaintenanceActionService($db,$config,null);
$out=[];$failed=[];
foreach([2=>111139,3=>111198,4=>111199,5=>111200,6=>111201,7=>111202,12=>111203] as $game=>$id){
 try{
  $q=$db->prepare('SELECT id,game_id,md5,scan_status,relative_path FROM ue_files WHERE id=?');
  $q->execute([$id]);$before=$q->fetch();
  if(!$before||(int)$before['game_id']!==$game||$before['scan_status']!=='verified')throw new RuntimeException('Bad isolated fixture');
  if(!str_starts_with((string)$before['relative_path'],'storage/uedb5-isolated-reimport-20261015/'))throw new RuntimeException('Unexpected path');
  $result=$service->execute('sync_reimport',['file_id'=>$id]);
  $q->execute([$id]);$after=$q->fetch();
  $v=(new Uedb5MetadataReader($config['storage_path']))->verify($game,$id);
  if(($result['status']??'')!=='reimported'||$before['md5']!==$after['md5']||$after['scan_status']!=='verified'||$after['relative_path']!==$before['relative_path'])throw new RuntimeException('Reimport identity mismatch '.json_encode($result));
  $out[]=['game_id'=>$game,'file_id'=>$id,'status'=>'reimported','metadata_format'=>5,'block_count'=>$v['block_count']??null];
 }catch(Throwable $e){$failed[]=['game_id'=>$game,'file_id'=>$id,'error'=>$e->getMessage()];}
}
$ok=count($out)===7&&$failed===[];
echo json_encode(['ok'=>$ok,'scope'=>'seven game actual scanner reimport with stable IDs in standalone MySQL','passed'=>$out,'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
