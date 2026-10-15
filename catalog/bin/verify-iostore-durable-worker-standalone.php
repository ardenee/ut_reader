<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogIoStoreImportJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',
 [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||stripos($i['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Wrong MySQL instance');
$db->exec("INSERT INTO ue_game_profiles(id,profile_name,game_id,engine_key,allowed_extensions_json,is_active) VALUES (9001,'Isolated Zen IoStore fixture',NULL,'UE5','[\"utoc\",\"ucas\"]',1) ON DUPLICATE KEY UPDATE engine_key='UE5',is_active=1");
$db->exec("INSERT INTO ue_games(id,name,slug,profile_id) VALUES (9001,'Isolated Satisfactory IoStore','satisfactorytest',9001) ON DUPLICATE KEY UPDATE profile_id=9001");
$source='//192.168.0.51/c$/Program Files (x86)/Steam/steamapps/common/SatisfactoryDedicatedServer/FactoryGame/Content/Paks/global.utoc';
$queueName='u5-iostore-durable-'.bin2hex(random_bytes(4));
$storage='C:/Temp/uedb5-iostore-worker-test';
$config=['storage_path'=>$storage];
$q=new PdoJobQueue($db);
$handler=new CatalogIoStoreImportJobHandler($db,$config);
$routes=array_fill_keys(JobType::all(),static fn():array=>[]);
$routes[JobType::IMPORT_IOSTORE_CONTAINER]=$handler;
$worker=new JobWorker($q,$routes,$queueName,'isolated-iostore-worker',120);
$results=[];
foreach([1,2] as $iteration){
 $id=$q->enqueue($queueName,JobType::IMPORT_IOSTORE_CONTAINER,
 ['game_id'=>9001,'local_utoc_path'=>$source],100,null,null,null,1);
 $worker->runOne();
 $st=$db->prepare('SELECT id,status,attempts,result_json,last_error FROM ue_background_jobs WHERE id=?');
 $st->execute([$id]);$row=$st->fetch();
 $results[]=['id'=>$id,'status'=>$row['status'],'attempts'=>$row['attempts'],'result'=>json_decode((string)$row['result_json'],true),'error'=>$row['last_error']];
}
$index=$db->query('SELECT id,status,package_count FROM ue_iostore_containers WHERE game_id=9001')->fetchAll();
$parts=$db->query('SELECT count(*) FROM ue_iostore_container_parts WHERE container_id IN (SELECT id FROM ue_iostore_containers WHERE game_id=9001)')->fetchColumn();
$ok=count($results)===2&&$results[0]['status']==='completed'&&$results[1]['status']==='completed'
 &&$results[0]['attempts']==1&&$results[1]['attempts']==1
 &&count($index)===1&&$index[0]['status']==='ready'&&(int)$parts===1
 &&($results[0]['result']['container_id']??0)===($results[1]['result']['container_id']??-1);
echo json_encode(['ok'=>$ok,'isolated'=>true,'queue'=>$queueName,'jobs'=>$results,'containers'=>$index,'parts'=>$parts],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
