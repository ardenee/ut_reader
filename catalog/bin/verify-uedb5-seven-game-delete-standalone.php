<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceRemovalService;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||strtolower(str_replace(chr(92),'/',$i['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe target');
$config=catalog_config();$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$success=[];$failed=[];
foreach([2=>26,3=>36,4=>41,5=>46,6=>51,7=>56,12=>61] as $game=>$parentId){
 try{
  $s=$db->prepare('SELECT result_json FROM ue_background_jobs WHERE parent_job_id=? AND job_type=? AND status="completed" LIMIT 1');
  $s->execute([$parentId,JobType::IMPORT_GAME_BACKUP_ENTRY]);
  $child=json_decode((string)$s->fetchColumn(),true);
  $fileId=(int)($child['file_id']??0);
  if($fileId<1)throw new RuntimeException('Missing completed backup child');
  $f=$db->prepare('SELECT relative_path,game_id FROM ue_files WHERE id=?');
  $f->execute([$fileId]);$row=$f->fetch();
  if(!$row||(int)$row['game_id']!==$game||!str_starts_with((string)$row['relative_path'],'storage/uedb5-isolated-reimport-20261015/'))throw new RuntimeException('Not isolated source');
  $source=dirname(__DIR__).'/'.$row['relative_path'];
  if(!is_file($source))throw new RuntimeException('Missing isolated source');
  $config['queue']['name']='u5-seven-deletion-'.$game.'-'.bin2hex(random_bytes(4));
  $result=(new CatalogFileMaintenanceRemovalService($db,$config))->remove($fileId,null,false);
  $reconcileId=(int)($result['reconciliation_job_id']??0);
  if($reconcileId<1)throw new RuntimeException('Reconciliation job not queued');
  $queue=new PdoJobQueue($db);$handler=new CatalogProjectionReconciliationJobHandler($db,$config);
  $routes=array_fill_keys(JobType::all(),static function():array{return [];});
  $routes[JobType::RECONCILE_CATALOG_PROJECTIONS]=$handler;
  $routes[JobType::RECONCILE_CATALOG_PROJECTION_FILE]=$handler;
  $worker=new JobWorker($queue,$routes,$config['queue']['name'],'seven-deletion-'.$game,120);
  $status='queued';$loops=0;
  for($n=0;$n<120;$n++){
   $worker->runOne();$loops++;
   $s=$db->prepare('SELECT status FROM ue_background_jobs WHERE id=?');$s->execute([$reconcileId]);$status=(string)$s->fetchColumn();
   if(in_array($status,['completed','dead_letter','failed','cancelled'],true))break;
   usleep(150000);
  }
  $f->execute([$fileId]);$exists=$f->fetch();
  if($status!=='completed'||$exists!==false||is_file($source))throw new RuntimeException('Deletion not complete; reconcile='.$status);
  $success[]=['game_id'=>$game,'file_id'=>$fileId,'reconciliation_job'=>$reconcileId,'status'=>$status,'worker_iterations'=>$loops];
 }catch(Throwable $e){$failed[]=['game_id'=>$game,'error'=>$e->getMessage()];}
 echo json_encode(['game_id'=>$game,'completed'=>count($success),'failed'=>count($failed)]),PHP_EOL;
}
$ok=count($success)===7&&$failed===[];
echo json_encode(['ok'=>$ok,'scope'=>'nondeferred physical/catalog deletion and durable reconciliation for seven games','passed'=>$success,'failed'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
