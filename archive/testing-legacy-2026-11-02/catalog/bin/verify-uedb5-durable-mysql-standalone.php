<?php
declare(strict_types=1);
/**
 * Durable MySQL V5 dependency worker integration: ordinary tables in a completely
 * separate standalone MySQL datadir, never production schema or production queue.
 */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
use UnrealDb\Catalog\Application\Jobs\JobWorker;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogDependencyRefreshJobHandler;
$host='127.0.0.1';$port=3339;$schema='uedb5_isolated_integration';
$db=new PDO("mysql:host=$host;port=$port;charset=utf8mb4",'root','',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
 PDO::ATTR_EMULATE_PREPARES=>false,
]);
$identity=$db->query('SELECT @@port AS port,@@datadir AS datadir,@@hostname AS hostname')->fetch();
$expected='c:/temp/uedb5-isolated-mysql/data/';
$path=strtolower(str_replace('\\','/',(string)$identity['datadir']));
if((int)$identity['port']!==3339||$path!==$expected){
 throw new RuntimeException('Refusing non-isolated MySQL connection: '.$path);
}
$source=catalog_bootstrap(false)->db; // production schema introspection only.
$db->exec('CREATE DATABASE IF NOT EXISTS `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('USE `'.$schema.'`');
foreach(['ue_background_jobs','ue_job_resource_limits'] as $table){
 $row=$source->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM);
 if(!is_array($row))throw new RuntimeException('Missing production table definition '.$table);
 $ddl=(string)$row[1];
 $ddl=preg_replace('/^CREATE TABLE /','CREATE TABLE IF NOT EXISTS ',$ddl,1);
 $ddl=preg_replace('/^\s*CONSTRAINT\s+.*(?:\r?\n|$)/mi','',$ddl);
 $ddl=preg_replace('/,\s*\) ENGINE=/',') ENGINE=',$ddl);
 $ddl=preg_replace('/ AUTO_INCREMENT=\d+/',' AUTO_INCREMENT=1',$ddl);
 $db->exec($ddl);
}
$db->exec('CREATE TABLE IF NOT EXISTS ue_files(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,game_id BIGINT UNSIGNED NULL,scan_status VARCHAR(32) NULL,package_name VARCHAR(255) NULL,original_name VARCHAR(255) NULL)');
foreach(['package_name','original_name'] as $col){
 $q=$db->query("SHOW COLUMNS FROM ue_files LIKE '".$col."'");
 if(!$q->fetch())$db->exec('ALTER TABLE ue_files ADD COLUMN `'.$col.'` VARCHAR(255) NULL');
}
$q=new PdoJobQueue($db);
$name='uedb5-integration-'.bin2hex(random_bytes(5));
$first=$q->enqueue($name,JobType::REBUILD_FILE_DEPENDENCIES,
 ['file_id'=>900000000,'workflow_parent_job_id'=>800000000],100,null,null,null,1);
$second=$q->enqueue($name,JobType::REBUILD_FILE_DEPENDENCIES,['file_id'=>0],100,null,null,null,1);
$handler=new CatalogDependencyRefreshJobHandler($db,[]);
$routes=array_fill_keys(JobType::all(),static function():array{return [];});
$routes[JobType::REBUILD_FILE_DEPENDENCIES]=$handler;
$routes[JobType::REBUILD_GAME_DEPENDENCIES]=$handler;
$worker=new JobWorker($q,$routes,$name,'isolated-mysql-worker',120);
$runs=[];for($i=0;$i<6;$i++){$runs[]=$worker->runOne();}
$s=$db->prepare('SELECT id,status,attempts,result_json,progress_json,last_error FROM ue_background_jobs WHERE id IN (?,?)');
$s->execute([$first,$second]);$rows=$s->fetchAll();
$by=[];foreach($rows as $r)$by[(int)$r['id']]=$r;
$pass=[
 'isolated_connection_verified'=>true,
 'durable_success_completed'=>($by[$first]['status']??'')==='completed',
 'durable_success_result'=>(static function($r){$j=json_decode((string)($r['result_json']??''),true);return ($j['status']??'')==='already_removed';})($by[$first]??[]),
 'durable_invalid_job_terminal'=>in_array(($by[$second]['status']??''),['dead_letter','failed'],true),
 'exactly_two_jobs_persisted'=>count($rows)===2,
 'worker_executed_jobs'=>count(array_filter($runs,static fn($r):bool=>($r['status']??'')!=='idle'))>=2,
];
$errors=array_keys(array_filter($pass,static fn($v):bool=>!$v));
echo json_encode(['ok'=>$errors===[],'isolated'=>true,'mysql_datadir'=>$path,'queue'=>$name,'job_ids'=>[$first,$second],
 'states'=>array_map(static fn($r)=>['id'=>$r['id'],'status'=>$r['status'],'attempts'=>$r['attempts']],$rows),
 'checks'=>$pass,'failures'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:2);
