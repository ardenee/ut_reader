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
$iso=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration;charset=utf8mb4','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$iso->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||strtolower(str_replace(chr(92),'/',$ident['datadir']))!=='c:/temp/uedb5-isolated-mysql/data/')throw new RuntimeException('Non-isolated MySQL');
$prod=catalog_bootstrap(false)->db;
$config=catalog_config();$config['storage_path']=dirname(__DIR__).'/storage/uedb5-isolated-reimport-20261015';
$config['game_backups']['path']=$config['storage_path'].'/game-backups';
$store=new GameBackupStore($config);$results=[];$failures=[];
$files=[2=>111144,3=>113257,4=>148953,5=>111505,6=>112303,7=>113529,12=>110906];
foreach($files as $gameId=>$originalId){
 try{
  $q=$prod->prepare('SELECT f.id,f.game_id,f.original_name,f.file_size,f.md5,f.relative_path,g.slug,g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=? AND f.game_id=? AND f.scan_status="verified"');
  $q->execute([$originalId,$gameId]);$file=$q->fetch();
  if(!is_array($file))throw new RuntimeException('Missing source fixture');
  $src=dirname(__DIR__).'/'.$file['relative_path'];
  if(!is_file($src)||filesize($src)!==(int)$file['file_size']||md5_file($src)!==strtolower($file['md5']))throw new RuntimeException('Fixture source changed');
  $key='seven-backup-'.$gameId.'-'.bin2hex(random_bytes(5));$store->create($key);
  $filename=basename((string)$file['original_name']);
  if(!copy($src,$store->filesPath($key).'/'.$filename))throw new RuntimeException('Could not copy backup source');
  $store->publishManifest($key,['format'=>'unrealdb-game-backup','format_version'=>1,'source_game'=>['id'=>$gameId,'name'=>$file['game_name'],'slug'=>$file['slug']], 'files'=>[['file_id'=>$originalId,'original_name'=>$file['original_name'],'exported_relative_path'=>$filename,'source_relative_path'=>'seven-game-restore/'.$filename,'file_size'=>$file['file_size'],'md5'=>$file['md5'],'is_alias'=>false]],'summary'=>['entries'=>1,'physical_files'=>1,'bytes'=>$file['file_size']]]);
  $config['queue']['name']='u5-seven-backup-'.$gameId.'-'.bin2hex(random_bytes(4));
  $queue=new PdoJobQueue($iso);
  $parentId=$queue->enqueue($config['queue']['name'],JobType::IMPORT_GAME_BACKUP,['game_id'=>$gameId,'backup_key'=>$key,'strict_profile'=>true],100,null,null,null,3);
  $backup=new GameBackupImportJobHandler($iso,$config);$deps=new CatalogDependencyRefreshJobHandler($iso,$config);
  $handlers=array_fill_keys(JobType::all(),static function():array{return [];});
  foreach([JobType::IMPORT_GAME_BACKUP,JobType::IMPORT_GAME_BACKUP_ENTRY] as $type)$handlers[$type]=$backup;
  foreach([JobType::REBUILD_FILE_DEPENDENCIES,JobType::REBUILD_GAME_DEPENDENCIES] as $type)$handlers[$type]=$deps;
  $worker=new JobWorker($queue,$handlers,$config['queue']['name'],'seven-game-restore-'.$gameId,120);
  $status='queued';$loops=0;
  for($n=0;$n<160;$n++){
   $worker->runOne();$loops++;
   $st=$iso->prepare('SELECT status FROM ue_background_jobs WHERE id=?');$st->execute([$parentId]);$status=(string)$st->fetchColumn();
   if(in_array($status,['completed','dead_letter','failed','cancelled'],true))break;
   usleep(150000);
  }
  $st=$iso->prepare('SELECT status,last_error,result_json FROM ue_background_jobs WHERE id=?');$st->execute([$parentId]);$parent=$st->fetch();
  $result=json_decode((string)($parent['result_json']??''),true);
  $ok=($parent['status']??'')==='completed'&&($result['completed_entries']??0)===1&&($result['dependency_status']??'')==='completed';
  $row=['game_id'=>$gameId,'backup_key'=>$key,'parent_job_id'=>$parentId,'status'=>$parent['status']??null,'imported'=>$result['imported']??null,'dependency_status'=>$result['dependency_status']??null,'loops'=>$loops,'error'=>$parent['last_error']??null];
  if($ok)$results[]=$row;else $failures[]=$row;
 }catch(Throwable $e){$failures[]=['game_id'=>$gameId,'error'=>$e->getMessage()];}
 echo json_encode(['game_id'=>$gameId,'completed'=>count($results),'failures'=>count($failures)],JSON_UNESCAPED_SLASHES),PHP_EOL;
}
$ok=count($results)===7&&$failures===[];
echo json_encode(['ok'=>$ok,'scope'=>'actual durable backup restore parents with dependencies across all seven games','passed'=>$results,'failed'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
