<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceRemovalService;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||strtolower(str_replace(chr(92),'/',$i['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe MySQL');
$config=catalog_config();$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['queue']['name']='uedb5-isolated-delete';$fileId=111194;
$sourceRow=$db->query('SELECT relative_path FROM ue_files WHERE id=111194')->fetch();
if(!$sourceRow)throw new RuntimeException('Fixture file already absent');
$source=dirname(__DIR__).'/'.str_replace(chr(92),'/',(string)$sourceRow['relative_path']);
$before=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE resolved_file_id=111194 AND file_id<>111194')->fetchColumn();
$removal=null;$error=null;
try{$removal=(new CatalogFileMaintenanceRemovalService($db,$config))->remove($fileId,null,false);}catch(Throwable $e){$error=get_class($e).': '.$e->getMessage();}
$after=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges WHERE resolved_file_id=111194 AND file_id<>111194')->fetchColumn();
$exists=(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE id=111194')->fetchColumn();
$jobId=(int)($removal['reconciliation_job_id']??0);$statuses=[];
if($jobId>0){
 $q=new PdoJobQueue($db);$handler=new CatalogProjectionReconciliationJobHandler($db,$config);
 $routes=array_fill_keys(JobType::all(),static function():array{return [];});
 foreach([JobType::RECONCILE_CATALOG_PROJECTIONS,JobType::RECONCILE_CATALOG_PROJECTION_FILE] as $type)$routes[$type]=$handler;
 $worker=new JobWorker($q,$routes,$config['queue']['name'],'isolated-deletion-worker',120);
 for($n=0;$n<120;$n++){
  $r=$worker->runOne();$statuses[$r['status']??'unknown']=($statuses[$r['status']??'unknown']??0)+1;
  $s=$db->prepare('SELECT status FROM ue_background_jobs WHERE id=?');$s->execute([$jobId]);$status=(string)$s->fetchColumn();
  if(in_array($status,['completed','dead_letter','failed','cancelled'],true))break;
  usleep(250000);
 }
}
$row=null;if($jobId){$s=$db->prepare('SELECT status,last_error,result_json FROM ue_background_jobs WHERE id=?');$s->execute([$jobId]);$row=$s->fetch();}
$ok=$error===null&&$before>0&&$after===0&&$exists===0&&!is_file($source)&&$jobId>0&&($row['status']??'')==='completed';
echo json_encode(['ok'=>$ok,'deleted_file_id'=>$fileId,'before_provider_edges'=>$before,'after_provider_edges'=>$after,'catalog_row_remaining'=>$exists,'source_remaining'=>is_file($source),'removal'=>$removal,'removal_error'=>$error,'reconciliation_job_id'=>$jobId,'reconciliation_status'=>$row['status']??null,'reconciliation_error'=>$row['last_error']??null,'reconciliation_result'=>json_decode((string)($row['result_json']??''),true),'worker_statuses'=>$statuses],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
