<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogIoStoreBackupJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos((string)$i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong MySQL');
$db->exec("INSERT INTO ue_games(id,name,slug,profile_id) VALUES (9003,'IoStore queued restore fixture','satisfactoryqueuedrestore',9001) ON DUPLICATE KEY UPDATE profile_id=9001");
$sourceRoot='C:/Temp/uedb5-iostore-retention-test';
$backupRoot='C:/Temp/uedb5-iostore-queue-backups';
$backupKey='zenqueue-'.bin2hex(random_bytes(4));
$queueName='zen-bk-'.bin2hex(random_bytes(4));
$queue=new PdoJobQueue($db);
$routes=array_fill_keys(JobType::all(),static fn():array=>[]);
$routes[JobType::BACKUP_IOSTORE_CONTAINER]=new CatalogIoStoreBackupJobHandler($db,['storage_path'=>$sourceRoot,'game_backups'=>['path'=>$backupRoot]]);
$worker=new JobWorker($queue,$routes,$queueName,'isolated-zen-backup',120);
$first=$queue->enqueue($queueName,JobType::BACKUP_IOSTORE_CONTAINER,['container_id'=>2,'backup_key'=>$backupKey],100,null,null,null,1);
$worker->runOne();
$q=$db->prepare('SELECT status,result_json,last_error FROM ue_background_jobs WHERE id=?');
$q->execute([$first]);$firstJob=$q->fetch();
$hash=$db->query('SELECT utoc_sha256 FROM ue_iostore_containers WHERE id=2')->fetchColumn();
$routes[JobType::RESTORE_IOSTORE_CONTAINER]=new CatalogIoStoreBackupJobHandler($db,['storage_path'=>'C:/Temp/uedb5-iostore-queue-restored','game_backups'=>['path'=>$backupRoot]]);
$worker=new JobWorker($queue,$routes,$queueName,'isolated-zen-restore',120);
$second=$queue->enqueue($queueName,JobType::RESTORE_IOSTORE_CONTAINER,[
 'game_id'=>9003,'backup_key'=>$backupKey,'utoc_sha256'=>$hash
],100,null,null,null,1);
$worker->runOne();
$q->execute([$second]);$secondJob=$q->fetch();
$restore=json_decode((string)$secondJob['result_json'],true);
$ok=$firstJob['status']==='completed'&&$secondJob['status']==='completed'
 &&($restore['retention_status']??'')==='ready'
 &&(int)($restore['name_job_id']??0)>0
 &&(int)($restore['publish_job_id']??0)>0;
echo json_encode(['ok'=>$ok,'isolated'=>true,'backup_key'=>$backupKey,
 'backup_job'=>['status'=>$firstJob['status'],'result'=>json_decode((string)$firstJob['result_json'],true),'error'=>$firstJob['last_error']],
 'restore_job'=>['status'=>$secondJob['status'],'result'=>$restore,'error'=>$secondJob['last_error']]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
