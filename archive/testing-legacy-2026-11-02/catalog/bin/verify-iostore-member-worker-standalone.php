<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogIoStoreMemberPublishJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$identity=$db->query('SELECT @@port AS port,@@datadir AS dir')->fetch();
if((int)$identity['port']!==3339||stripos((string)$identity['dir'],'uedb5-isolated-mysql')===false)
 throw new RuntimeException('Refusing non-isolated MySQL.');
$profile=$db->query('SELECT profile_id FROM ue_games WHERE id=2')->fetchColumn();
if($profile===false)throw new RuntimeException('Expected isolated fixture game absent.');
$restorable=$profile===null?null:(int)$profile;
$results=[];$jobId=null;$qName='u5-zen-publish-'.bin2hex(random_bytes(5));
try {
 $db->exec('UPDATE ue_games SET profile_id=9001 WHERE id=2');
 $queue=new PdoJobQueue($db);
 $routes=array_fill_keys(JobType::all(),static fn():array=>[]);
 $routes[JobType::PUBLISH_IOSTORE_PACKAGES]=new CatalogIoStoreMemberPublishJobHandler($db,
   ['storage_path'=>'C:/Temp/uedb5-iostore-retention-test']);
 $worker=new JobWorker($queue,$routes,$qName,'isolated-publisher',120);
 $jobId=$queue->enqueue($qName,JobType::PUBLISH_IOSTORE_PACKAGES,['container_id'=>1],100,null,null,null,3);
 for($n=0;$n<2;$n++){
  $db->prepare('UPDATE ue_background_jobs SET available_at=UTC_TIMESTAMP() WHERE id=? AND status="queued"')
     ->execute([$jobId]);
  $worker->runOne();
  $q=$db->prepare('SELECT status,attempts,progress_json,last_error FROM ue_background_jobs WHERE id=?');
  $q->execute([$jobId]);$record=$q->fetch();
  $results[]=['status'=>$record['status'],'attempts'=>$record['attempts'],
    'progress'=>json_decode((string)$record['progress_json'],true),'error'=>$record['last_error']];
 }
}finally{
 $statement=$db->prepare('UPDATE ue_games SET profile_id=? WHERE id=2');
 $statement->execute([$restorable]);
}
$verified=(int)$db->query('SELECT COUNT(*) FROM ue_iostore_packages WHERE container_id=1 AND publication_status="published"')->fetchColumn();
$ok=count($results)===2 && $results[0]['status']==='queued'
 && $results[1]['status']==='queued' && $verified>=20;
echo json_encode(['ok'=>$ok,'standalone_mysql_only'=>true,'job_id'=>$jobId,
 'steps'=>$results,'published_member_count'=>$verified,'restored_game_profile'=>$restorable],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
