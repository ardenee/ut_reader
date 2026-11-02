<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogIoStoreNameIndexJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Unsafe database');
$queueName='u5-iostore-names-'.bin2hex(random_bytes(4));
$queue=new PdoJobQueue($db);
$routes=array_fill_keys(JobType::all(),static fn():array=>[]);
$routes[JobType::INDEX_IOSTORE_PACKAGE_NAMES]=new CatalogIoStoreNameIndexJobHandler($db,[
 'storage_path'=>'C:/Temp/uedb5-iostore-retention-test'
]);
$worker=new JobWorker($queue,$routes,$queueName,'iostore-name-worker',120);
$id=$queue->enqueue($queueName,JobType::INDEX_IOSTORE_PACKAGE_NAMES,['container_id'=>1],100,null,null,null,3);
$statuses=[];
for($step=0;$step<3;$step++){
 $db->prepare('UPDATE ue_background_jobs SET available_at=UTC_TIMESTAMP() WHERE id=? AND status="queued"')->execute([$id]);
 $worker->runOne();
 $stmt=$db->prepare('SELECT status,attempts,progress_json FROM ue_background_jobs WHERE id=?');
 $stmt->execute([$id]);$row=$stmt->fetch();
 $statuses[]=['status'=>$row['status'],'attempts'=>$row['attempts'],'progress'=>json_decode((string)$row['progress_json'],true)];
}
$ready=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=1 AND metadata_status="ready"')->fetchColumn();
$failed=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=1 AND metadata_status="failed"')->fetchColumn();
$globalJob=$queue->enqueue($queueName,JobType::INDEX_IOSTORE_PACKAGE_NAMES,['container_id'=>2],100,null,null,null,1);
$worker->runOne();
$stmt=$db->prepare('SELECT status,result_json FROM ue_background_jobs WHERE id=?');
$stmt->execute([$globalJob]);$global=$stmt->fetch();
$ok=$ready>=350&&$failed===0&&count($statuses)===3
 &&$statuses[0]['status']==='queued'&&$statuses[2]['status']==='queued'
 &&$global['status']==='completed';
echo json_encode(['ok'=>$ok,'isolated'=>true,'name_index_job_id'=>$id,'resume_steps'=>$statuses,
 'ready_packages'=>$ready,'failed_packages'=>$failed,
 'empty_global_status'=>$global['status'],'empty_global_result'=>json_decode((string)$global['result_json'],true)],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
