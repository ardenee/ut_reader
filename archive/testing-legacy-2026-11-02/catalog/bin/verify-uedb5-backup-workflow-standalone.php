<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;
use UnrealDb\Catalog\Infrastructure\Jobs\GameBackupImportJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$i=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$i['port']!==3339||strtolower(str_replace(chr(92),'/',$i['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Unsafe DB');
$config=catalog_config();$root=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['storage_path']=$root;$config['game_backups']['path']=$root.'/game-backups';$config['queue']['name']='uedb5-isolated-workflow';
$store=new GameBackupStore($config);$key='isolated-workflow-'.bin2hex(random_bytes(5));$store->create($key);
$source=dirname(__DIR__).'/storage/games/unreal2/verified/c87dfcad04acd290b00b18a59f770896.utx';
if(!is_file($source)||filesize($source)!==701988)throw new RuntimeException('Backup file invalid');
copy($source,$store->filesPath($key).'/Glands.utx');
$store->publishManifest($key,['format'=>'unrealdb-game-backup','format_version'=>1,'source_game'=>['id'=>2,'name'=>'Unreal II','slug'=>'unreal2'],'files'=>[['file_id'=>111147,'original_name'=>'Glands.utx','exported_relative_path'=>'Glands.utx','source_relative_path'=>'isolated-workflow/Glands.utx','file_size'=>701988,'md5'=>'c87dfcad04acd290b00b18a59f770896','is_alias'=>false]],'summary'=>['entries'=>1,'physical_files'=>1,'bytes'=>701988]]);
$q=new PdoJobQueue($db);$queue='v5-backup-workflow-'.bin2hex(random_bytes(5));
$parent=$q->enqueue($queue,JobType::IMPORT_GAME_BACKUP,['game_id'=>2,'backup_key'=>$key,'strict_profile'=>true],100,null,null,null,3);
$backup=new GameBackupImportJobHandler($db,$config);$deps=new CatalogDependencyRefreshJobHandler($db,$config);
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
foreach([JobType::IMPORT_GAME_BACKUP,JobType::IMPORT_GAME_BACKUP_ENTRY] as $type)$routes[$type]=$backup;
foreach([JobType::REBUILD_FILE_DEPENDENCIES,JobType::REBUILD_GAME_DEPENDENCIES] as $type)$routes[$type]=$deps;
$worker=new JobWorker($q,$routes,$queue,'isolated-full-backup-worker',120);
$statuses=[];$sample=[];
for($n=0;$n<160;$n++){
 $r=$worker->runOne();$statuses[$r['status']??'unknown']=($statuses[$r['status']??'unknown']??0)+1;
 if(count($sample)<15)$sample[]=['status'=>$r['status']??null,'type'=>$r['type']??null,'job_id'=>$r['job_id']??null];
 $s=$db->prepare('SELECT status FROM ue_background_jobs WHERE id=?');$s->execute([$parent]);$parentStatus=(string)$s->fetchColumn();
 if(in_array($parentStatus,['completed','dead_letter','failed','cancelled'],true))break;
 usleep(250000);
}
$parentRow=$db->query('SELECT id,status,last_error,result_json FROM ue_background_jobs WHERE id='.(int)$parent)->fetch();
$children=$db->prepare('SELECT id,job_type,status,last_error,workflow_unit_key FROM ue_background_jobs WHERE parent_job_id=? ORDER BY id');$children->execute([$parent]);$childRows=$children->fetchAll();
$result=json_decode((string)($parentRow['result_json']??''),true);
$ok=($parentRow['status']??'')==='completed'&&($result['imported']??0)>=1&&($result['dependency_status']??'')==='completed';
echo json_encode(['ok'=>$ok,'backup_key'=>$key,'parent_job'=>$parent,'parent_status'=>$parentRow['status']??null,'error'=>$parentRow['last_error']??null,'result'=>$result,'children'=>$childRows,'worker_statuses'=>$statuses,'sample'=>$sample],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
