<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogIoStoreImportJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||stripos($ident['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong instance');
$source='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/global.utoc';
$queueName='u5-iostore-guards-'.bin2hex(random_bytes(4));
$queue=new PdoJobQueue($db);
$routes=array_fill_keys(JobType::all(),static fn():array=>[]);
$routes[JobType::IMPORT_IOSTORE_CONTAINER]=new CatalogIoStoreImportJobHandler($db,['storage_path'=>'C:/Temp/uedb5-iostore-worker-test']);
$worker=new JobWorker($queue,$routes,$queueName,'u5-guard-worker',120);
$cases=[
 'non_ue5_profile'=>['game_id'=>2,'local_utoc_path'=>$source],
 'changed_source_hash'=>['game_id'=>9001,'local_utoc_path'=>$source,'toc_sha256'=>str_repeat('0',64)],
];
$out=[];
foreach($cases as $name=>$payload){
 $id=$queue->enqueue($queueName,JobType::IMPORT_IOSTORE_CONTAINER,$payload,100,null,null,null,1);
 $worker->runOne();
 $st=$db->prepare('SELECT status,attempts,last_error FROM ue_background_jobs WHERE id=?');
 $st->execute([$id]);$row=$st->fetch();
 $out[$name]=$row;
}
$pass=$out['non_ue5_profile']['status']==='dead_letter'
 &&$out['changed_source_hash']['status']==='dead_letter'
 &&str_contains((string)$out['non_ue5_profile']['last_error'],'UE5')
 &&str_contains((string)$out['changed_source_hash']['last_error'],'changed');
echo json_encode(['ok'=>$pass,'isolated'=>true,'failure_scenarios'=>$out],JSON_PRETTY_PRINT),PHP_EOL;
exit($pass?0:2);
