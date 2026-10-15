<?php
declare(strict_types=1);
/** Exercise actual deferred V5 deletion in a disposable local fixture and SQLite. */
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogFileMaintenanceRemovalService;
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT,original_name TEXT,relative_path TEXT,scan_status TEXT)');
$db->exec('CREATE TABLE ue_file_package_aliases(id INTEGER PRIMARY KEY,file_id INTEGER,package_name TEXT)');
$db->exec("ATTACH DATABASE ':memory:' AS information_schema");
$db->exec('CREATE TABLE information_schema.tables(table_schema TEXT,table_name TEXT)');
@$db->sqliteCreateFunction('DATABASE',static fn():string=>'isolated');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE ue_uedb5_migration_status(file_id INTEGER PRIMARY KEY)');
$db->exec("INSERT INTO information_schema.tables VALUES('isolated','ue_uedb5_files'),('isolated','ue_uedb5_migration_status')");
$root=dirname(__DIR__);
$storage=$root.DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'isolated-removal-'.bin2hex(random_bytes(7));
$fixture=$storage.DIRECTORY_SEPARATOR.'fixture.uasset';
$report=['ok'=>false,'sqlite_memory_only'=>true,'disposable_file_only'=>true];
try{
 if(!mkdir($storage,0700,true))throw new RuntimeException('Fixture folder creation failed');
 file_put_contents($fixture,'isolated-u5-file-not-a-game-package');
 $relative='storage/'.basename($storage).'/fixture.uasset';
 $db->prepare('INSERT INTO ue_files VALUES(321,7,?,?,?,?)')->execute(['Fixture','fixture.uasset',$relative,'verified']);
 $db->exec("INSERT INTO ue_file_package_aliases VALUES(1,321,'AliasFixture')");
 $db->exec('INSERT INTO ue_uedb5_files VALUES(321)');
 $db->exec('INSERT INTO ue_uedb5_migration_status VALUES(321)');
 $result=(new CatalogFileMaintenanceRemovalService($db,['storage_path'=>$storage]))
  ->remove(321,null,true);
 $report['file_deleted']=!is_file($fixture);
 $report['file_row_deleted']=(int)$db->query('SELECT COUNT(*) FROM ue_files')->fetchColumn()===0;
 $report['alias_deleted']=(int)$db->query('SELECT COUNT(*) FROM ue_file_package_aliases')->fetchColumn()===0;
 $report['result_game_id']=$result['game_id']??null;
 $report['deferred_reconciliation']=array_key_exists('reconciliation_job_id',$result)&&$result['reconciliation_job_id']===null;
 $report['v5_registration_deleted']=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_files')->fetchColumn()===0;
 $report['v5_migration_status_deleted']=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_migration_status')->fetchColumn()===0;
 // Force a failure after the migration-status DELETE, before the V5 registration DELETE.
 // The transaction must restore SQL rows and the staged source filename.
 $failedFixture=$storage.DIRECTORY_SEPARATOR.'failing.uasset';
 file_put_contents($failedFixture,'isolated-failed-deletion-source');
 $db->prepare('INSERT INTO ue_files VALUES(322,7,?,?,?,?)')
    ->execute(['FailingFixture','failing.uasset','storage/'.basename($storage).'/failing.uasset','verified']);
 $db->exec("INSERT INTO ue_file_package_aliases VALUES(2,322,'FailingAlias')");
 $db->exec('INSERT INTO ue_uedb5_migration_status VALUES(322)');
 $db->exec('DROP TABLE ue_uedb5_files');
 try{
  (new CatalogFileMaintenanceRemovalService($db,['storage_path'=>$storage]))->remove(322,null,true);
  $report['failure_injection_rejected']=false;
 }catch(Throwable $error){
  $report['failure_injection_rejected']=true;
 }
 $report['failed_delete_restored_source']=is_file($failedFixture);
 $report['failed_delete_kept_file_row']=(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE id=322')->fetchColumn()===1;
 $report['failed_delete_kept_alias']=(int)$db->query('SELECT COUNT(*) FROM ue_file_package_aliases WHERE file_id=322')->fetchColumn()===1;
 $report['failed_delete_rolled_back_v5_status']=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE file_id=322')->fetchColumn()===1;
 // Failure after the core SQL commit must never resurrect an orphan source file.
 $db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY)');
 $postFixture=$storage.DIRECTORY_SEPARATOR.'postcommit.uasset';
 file_put_contents($postFixture,'isolated-post-commit-failure');
 $db->prepare('INSERT INTO ue_files VALUES(323,7,?,?,?,?)')
    ->execute(['Postcommit','postcommit.uasset','storage/'.basename($storage).'/postcommit.uasset','verified']);
 $db->exec('INSERT INTO ue_uedb5_files VALUES(323)');
 $db->exec('INSERT INTO ue_uedb5_migration_status VALUES(323)');
 $throwAfterCommit=static function(array $progress):void{
    if(($progress['stage']??'')==='dependencies')throw new RuntimeException('isolated postcommit injection');
 };
 try{
    (new CatalogFileMaintenanceRemovalService($db,['storage_path'=>$storage]))
       ->remove(323,$throwAfterCommit,true);
    $report['postcommit_failure_exposed']=false;
 }catch(RuntimeException $error){
    $report['postcommit_failure_exposed']=str_contains($error->getMessage(),'Catalog deletion was committed');
 }
 $report['postcommit_no_orphan_file']=!is_file($postFixture);
 $report['postcommit_sql_remains_deleted']=(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE id=323')->fetchColumn()===0
    &&(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_files WHERE file_id=323')->fetchColumn()===0;
 $report['postcommit_no_staged_copy']=count(glob($postFixture.'.deleting-*')?:[])===0;
 $report['ok']=$report['file_deleted']&&$report['file_row_deleted']&&$report['alias_deleted']
    &&$report['deferred_reconciliation']&&$report['v5_registration_deleted']
    &&$report['v5_migration_status_deleted']&&$report['failure_injection_rejected']
    &&$report['failed_delete_restored_source']&&$report['failed_delete_kept_file_row']
    &&$report['failed_delete_kept_alias']&&$report['failed_delete_rolled_back_v5_status']
    &&$report['postcommit_failure_exposed']&&$report['postcommit_no_orphan_file']
    &&$report['postcommit_sql_remains_deleted']&&$report['postcommit_no_staged_copy'];
}catch(Throwable $e){$report['error']=$e->getMessage();}
finally{
 if(is_file($fixture))@unlink($fixture);
 if(isset($failedFixture)&&is_file($failedFixture))@unlink($failedFixture);
 if(is_dir($storage))@rmdir($storage);
}
echo json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($report['ok']?0:2);
