<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$id['port']!==3339||strtolower(str_replace(chr(92),'/',$id['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Wrong DB');
$config=catalog_config();$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$parent=71;
$st=$db->prepare('SELECT status,queue_name,progress_json FROM ue_background_jobs WHERE id=?');$st->execute([$parent]);$before=$st->fetch();
if(!is_array($before))throw new RuntimeException('Missing isolated reconcile job');
$config['queue']['name']=$before['queue_name'];
$queue=new PdoJobQueue($db);
$retried=($before['status']==='completed')?true:$queue->retryDeadLetter($parent);
$handler=new CatalogProjectionReconciliationJobHandler($db,$config);
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
$routes[JobType::RECONCILE_CATALOG_PROJECTIONS]=$handler;
$routes[JobType::RECONCILE_CATALOG_PROJECTION_FILE]=$handler;
$worker=new JobWorker($queue,$routes,$config['queue']['name'],'ut3-recovery',120);
$iterations=0;$status='queued';
for($n=0;$n<30;$n++){
 $worker->runOne();$iterations++;
 $st->execute([$parent]);$row=$st->fetch();$status=(string)$row['status'];
 if(in_array($status,['completed','dead_letter','failed','cancelled'],true))break;
 usleep(100000);
}
$child=$db->query('SELECT status,attempts FROM ue_background_jobs WHERE id=72')->fetch();
$hasDeleted=(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE id=111208')->fetchColumn();
$ok=$retried&&$status==='completed'&&($child['status']??'')==='completed'&&(int)$child['attempts']===1&&$hasDeleted===0;
echo json_encode(['ok'=>$ok,'parent_job'=>$parent,'initial_status'=>$before['status'],'retried'=>$retried,'parent_status'=>$status,'child_status'=>$child['status']??null,'child_attempts'=>$child['attempts']??null,'file_row_count'=>$hasDeleted,'iterations'=>$iterations],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
