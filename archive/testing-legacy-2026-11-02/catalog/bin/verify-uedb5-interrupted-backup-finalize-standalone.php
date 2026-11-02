<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Jobs\GameBackupImportJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||strtolower(str_replace(chr(92),'/',$ident['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Wrong DB');
$parent=(int)($argv[1]??0);
if($parent<1)throw new RuntimeException('Pass isolated backup parent ID to resume');$config=catalog_config();$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';$config['game_backups']['path']=$config['storage_path'].'/game-backups';
$q=$db->prepare('SELECT queue_name,status FROM ue_background_jobs WHERE id=?');$q->execute([$parent]);$row=$q->fetch();
if(!is_array($row)||!in_array($row['status'],['queued','completed'],true))throw new RuntimeException('Unexpected parent state');
$config['queue']['name']=$row['queue_name'];
$queue=new PdoJobQueue($db);$backup=new GameBackupImportJobHandler($db,$config);$deps=new CatalogDependencyRefreshJobHandler($db,$config);
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
foreach([JobType::IMPORT_GAME_BACKUP,JobType::IMPORT_GAME_BACKUP_ENTRY] as $type)$routes[$type]=$backup;
foreach([JobType::REBUILD_FILE_DEPENDENCIES,JobType::REBUILD_GAME_DEPENDENCIES] as $type)$routes[$type]=$deps;
$worker=new JobWorker($queue,$routes,$config['queue']['name'],'resume-parent-backup-worker',120);
$status=$row['status'];$loops=0;
for($n=0;$n<80;$n++){
 $db->prepare('UPDATE ue_background_jobs SET available_at=UTC_TIMESTAMP() WHERE id=? AND status="queued"')->execute([$parent]);
 $worker->runOne();$loops++;
 $q=$db->prepare('SELECT status FROM ue_background_jobs WHERE id=?');$q->execute([$parent]);$status=(string)$q->fetchColumn();
 if(in_array($status,['completed','dead_letter','failed','cancelled'],true))break;
 usleep(120000);
}
$q=$db->prepare('SELECT result_json,last_error FROM ue_background_jobs WHERE id=?');$q->execute([$parent]);$end=$q->fetch();
$result=json_decode((string)($end['result_json']??''),true);
$childQuery=$db->prepare('SELECT id,status,attempts FROM ue_background_jobs WHERE parent_job_id=? AND job_type=? ORDER BY workflow_unit_key');
$childQuery->execute([$parent,JobType::IMPORT_GAME_BACKUP_ENTRY]);$children=$childQuery->fetchAll();
$ok=$status==='completed'&&($result['dependency_status']??'')==='completed'&&($result['imported']??0)===2&&count($children)===2;
foreach($children as $c)$ok=$ok&&$c['status']==='completed'&&(int)$c['attempts']===1;
echo json_encode(['ok'=>$ok,'parent_status'=>$status,'result'=>$result,'children'=>$children,'loops'=>$loops,'error'=>$end['last_error']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
