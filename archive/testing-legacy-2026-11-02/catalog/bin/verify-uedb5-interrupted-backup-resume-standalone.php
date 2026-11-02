<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;
use UnrealDb\Catalog\Infrastructure\Jobs\GameBackupImportJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||strtolower(str_replace(chr(92),'/',$ident['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Refusing wrong MySQL');
$config=catalog_config();$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['game_backups']['path']=$config['storage_path'].'/game-backups';
$config['queue']['name']='u5-backup-resume-'.bin2hex(random_bytes(4));
$store=new GameBackupStore($config);$key='interrupted-restore-'.bin2hex(random_bytes(5));$store->create($key);
$prod=catalog_bootstrap(false)->db;
$query=$prod->prepare('SELECT id,original_name,relative_path,file_size,md5 FROM ue_files WHERE id=? AND game_id=2 AND scan_status="verified"');
$entries=[];
foreach([111144,111146] as $position=>$id){
 $query->execute([$id]);$row=$query->fetch();
 if(!$row)throw new RuntimeException('Missing isolated sample input');
 $src=dirname(__DIR__).'/'.$row['relative_path'];
 $name=basename((string)$row['original_name']);
 if(!is_file($src)||md5_file($src)!==strtolower((string)$row['md5']))throw new RuntimeException('Source identity mismatch');
 copy($src,$store->filesPath($key).'/'.$name);
 $entries[]=['file_id'=>$id,'original_name'=>$name,'exported_relative_path'=>$name,
 'source_relative_path'=>'recovery/'.$name,'file_size'=>$row['file_size'],
 'md5'=>$position===1?str_repeat('0',32):$row['md5'],'is_alias'=>false];
}
$manifest=['format'=>'unrealdb-game-backup','format_version'=>1,'source_game'=>['id'=>2,'name'=>'Unreal II','slug'=>'unreal2'],
 'files'=>$entries,'summary'=>['entries'=>2,'physical_files'=>2,'bytes'=>(int)$entries[0]['file_size']+(int)$entries[1]['file_size']]];
$store->publishManifest($key,$manifest);
$queue=new PdoJobQueue($db);$parent=$queue->enqueue($config['queue']['name'],JobType::IMPORT_GAME_BACKUP,
 ['game_id'=>2,'backup_key'=>$key,'strict_profile'=>true],100,null,null,null,3);
$backup=new GameBackupImportJobHandler($db,$config);$deps=new CatalogDependencyRefreshJobHandler($db,$config);
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
foreach([JobType::IMPORT_GAME_BACKUP,JobType::IMPORT_GAME_BACKUP_ENTRY] as $t)$routes[$t]=$backup;
foreach([JobType::REBUILD_FILE_DEPENDENCIES,JobType::REBUILD_GAME_DEPENDENCIES] as $t)$routes[$t]=$deps;
$worker=new JobWorker($queue,$routes,$config['queue']['name'],'recover-backup-worker',120);
$children=$db->prepare('SELECT id,status,attempts,workflow_unit_key FROM ue_background_jobs WHERE parent_job_id=? AND job_type=? ORDER BY workflow_unit_key');
$ready=false;
for($n=0;$n<80;$n++){
 $worker->runOne();$children->execute([$parent,JobType::IMPORT_GAME_BACKUP_ENTRY]);$rows=$children->fetchAll();
 if(count($rows)===2&&($rows[0]['status']??'')==='completed'&&($rows[1]['status']??'')==='dead_letter'){$ready=true;break;}
 usleep(100000);
}
if(!$ready)throw new RuntimeException('Could not establish interrupted restore with completed first child');
$firstId=(int)$rows[0]['id'];$failedId=(int)$rows[1]['id'];$initialFirstAttempt=(int)$rows[0]['attempts'];
$manifest['files'][1]['md5']=md5_file($store->filesPath($key).'/'.$entries[1]['exported_relative_path']);
$store->publishManifest($key,$manifest);
$retried=$queue->retryDeadLetter($failedId);
$db->prepare('UPDATE ue_background_jobs SET available_at=UTC_TIMESTAMP() WHERE id=? AND status="queued"')->execute([$parent]);
$parentStatus='';
for($n=0;$n<100;$n++){
 // The fixture removes only the artificial parent backoff after both children
 // have completed. Production retry and scheduling semantics are unchanged.
 $children->execute([$parent,JobType::IMPORT_GAME_BACKUP_ENTRY]);
 $currentChildren=$children->fetchAll();
 if(count($currentChildren)===2&&$currentChildren[0]['status']==='completed'&&$currentChildren[1]['status']==='completed'){
  $db->prepare('UPDATE ue_background_jobs SET available_at=UTC_TIMESTAMP() WHERE id=? AND status="queued"')->execute([$parent]);
 }
 $worker->runOne();$s=$db->prepare('SELECT status FROM ue_background_jobs WHERE id=?');$s->execute([$parent]);$parentStatus=(string)$s->fetchColumn();
 if(in_array($parentStatus,['completed','failed','dead_letter','cancelled'],true))break;
 usleep(150000);
}
$children->execute([$parent,JobType::IMPORT_GAME_BACKUP_ENTRY]);$finalChildren=$children->fetchAll();
$s=$db->prepare('SELECT status,result_json,last_error FROM ue_background_jobs WHERE id=?');$s->execute([$parent]);$end=$s->fetch();
$r=json_decode((string)($end['result_json']??''),true);
$ok=$retried&&$parentStatus==='completed'&&count($finalChildren)===2
 &&$finalChildren[0]['status']==='completed'&&(int)$finalChildren[0]['attempts']===$initialFirstAttempt
 &&$finalChildren[1]['status']==='completed'&&(int)$finalChildren[1]['attempts']===1
 &&($r['dependency_status']??'')==='completed'&&($r['imported']??0)===2;
echo json_encode(['ok'=>$ok,'isolated'=>true,'parent'=>$parent,'interrupted_first_child_id'=>$firstId,'failed_child_id'=>$failedId,'failed_child_retried'=>$retried,
 'initial_first_child_attempt'=>$initialFirstAttempt,'final_parent_status'=>$parentStatus,'children'=>$finalChildren,'result'=>$r,'error'=>$end['last_error']??null],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
