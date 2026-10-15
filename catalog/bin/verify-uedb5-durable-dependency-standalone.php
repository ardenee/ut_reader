<?php
declare(strict_types=1);
/** Real V5 external-provider dependency publish through durable standalone MySQL worker. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false
]);
$id=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
$path=strtolower(str_replace('\\','/',$id['datadir']));
if((int)$id['port']!==3339||$path!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe database');
$config=catalog_config();
$config['storage_path']='C:/Temp/uedb5-integration-storage';
$fileId=111139;
$q=new PdoJobQueue($db);
$name='uedb5-provider-'.bin2hex(random_bytes(5));
$jobId=$q->enqueue($name,JobType::REBUILD_FILE_DEPENDENCIES,
 ['file_id'=>$fileId,'workflow_defer_game_stats'=>true,'workflow_parent_job_id'=>1000],100,null,null,null,1);
$handler=new CatalogDependencyRefreshJobHandler($db,$config);
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
$routes[JobType::REBUILD_FILE_DEPENDENCIES]=$handler;
$routes[JobType::REBUILD_GAME_DEPENDENCIES]=$handler;
$worker=new JobWorker($q,$routes,$name,'v5-provider-integration',120);
$runs=[];for($i=0;$i<3;$i++)$runs[]=$worker->runOne();
$s=$db->prepare('SELECT status,last_error,result_json FROM ue_background_jobs WHERE id=?');
$s->execute([$jobId]);$row=$s->fetch(PDO::FETCH_ASSOC);
$counts=[];
foreach(['ue_uedb5_dependency_edges','ue_uedb5_dependency_packages'] as $table){
 $st=$db->prepare('SELECT COUNT(*) FROM `'.$table.'` WHERE file_id=?');
 $st->execute([$fileId]);$counts[$table]=(int)$st->fetchColumn();
}
$state=$db->prepare('SELECT dependency_policy FROM ue_uedb5_migration_status WHERE file_id=?');
$state->execute([$fileId]);$policy=(string)$state->fetchColumn();
$result=json_decode((string)($row['result_json']??''),true);
$ok=($row['status']??'')==='completed'&&$counts['ue_uedb5_dependency_edges']>0
 &&($result['package_provider_reconciled']??false)===true;
echo json_encode(['ok'=>$ok,'file_id'=>$fileId,'isolated_storage'=>$config['storage_path'],
 'job_id'=>$jobId,'job_status'=>$row['status']??null,'last_error'=>$row['last_error']??null,
 'dependency_policy'=>$policy,'counts'=>$counts,'result'=>$result,'runs'=>$runs],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
