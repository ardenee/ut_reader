<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogIoStoreImportJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||stripos($ident['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong DB');
$sources=glob('C:/Temp/uedb5-iostore-http-upload-test/jobs/incoming/iostore/*/global.utoc')?:[];
if(count($sources)!==1)throw new RuntimeException('Expected one real HTTP upload staging source');
$source=$sources[0];
$queueName='u5-iostore-http-end-to-end-'.bin2hex(random_bytes(4));
$queue=new PdoJobQueue($db);
$jobId=$queue->enqueue($queueName,JobType::IMPORT_IOSTORE_CONTAINER,['game_id'=>9001,'local_utoc_path'=>$source],100,null,null,null,1);
$routes=array_fill_keys(JobType::all(),static fn():array=>[]);
$routes[JobType::IMPORT_IOSTORE_CONTAINER]=new CatalogIoStoreImportJobHandler($db,['storage_path'=>'C:/Temp/uedb5-iostore-http-upload-test-retained']);
$worker=new JobWorker($queue,$routes,$queueName,'u5-http-zen-worker',120);
$worker->runOne();
$q=$db->prepare('SELECT status,attempts,result_json,last_error FROM ue_background_jobs WHERE id=?');
$q->execute([$jobId]);$job=$q->fetch();
$result=json_decode((string)($job['result_json']??''),true);
$ok=($job['status']??'')==='completed'&&(int)$job['attempts']===1&&($result['retention_status']??'')==='ready';
echo json_encode(['ok'=>$ok,'http_multipart_staged'=>true,'isolated_mysql'=>true,'job_id'=>$jobId,'status'=>$job['status']??null,'result'=>$result,'last_error'=>$job['last_error']??null],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
