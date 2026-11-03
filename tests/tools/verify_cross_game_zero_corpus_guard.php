<?php
declare(strict_types=1);
/**
 * Existing website-dependency acceptance scope: no extra contract IDs.
 * Prove empty verified source is unavailable, while current nonempty zero
 * dependency publications remain a valid empty result. No PRD access.
 */
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService as Pass2;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameMissingDependencyQuery;
use UnrealDb\Catalog\Infrastructure\Unverified\PdoGameDependencyCrossExamineQuery;

// The production SQL uses MySQL's double-quoted literal convention. SQLite
// resolves "verified" as the COUNT(*) alias in this specific query, so adapt
// that one SQL literal only; no production SQL or engine behavior is changed.
final class IsolatedMySqlLiteralPdo extends PDO {
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        return parent::prepare(
            str_replace('scan_status="verified"', "scan_status='verified'", $query),
            $options
        );
    }
}
$db=new IsolatedMySqlLiteralPdo('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach([
 'CREATE TABLE ue_games (id INTEGER PRIMARY KEY,name TEXT NOT NULL,slug TEXT NOT NULL,profile_id INTEGER NOT NULL)',
 'CREATE TABLE ue_game_profiles (id INTEGER PRIMARY KEY,engine_key TEXT NOT NULL,profile_name TEXT NOT NULL,notes TEXT NOT NULL,is_active INTEGER NOT NULL)',
 'CREATE TABLE ue_files (id INTEGER PRIMARY KEY,game_id INTEGER NOT NULL,scan_status TEXT NOT NULL)',
 'CREATE TABLE ue_uedb5_files (file_id INTEGER PRIMARY KEY,game_id INTEGER NOT NULL,format_version INTEGER NOT NULL,payload_sha256 TEXT NOT NULL)',
 'CREATE TABLE ue_uedb5_migration_status (file_id INTEGER PRIMARY KEY,game_id INTEGER NOT NULL,dependency_completed_at TEXT,dependency_policy TEXT,dependency_payload_sha256 TEXT)',
 'CREATE TABLE ue_uedb5_dependency_packages (file_id INTEGER,game_id INTEGER,missing_count INTEGER,required_package_name TEXT)'
] as $ddl)$db->exec($ddl);

$profile=$db->prepare('INSERT INTO ue_game_profiles (id,engine_key,profile_name,notes,is_active) VALUES (?,?,?,"",1)');
$game=$db->prepare('INSERT INTO ue_games (id,name,slug,profile_id) VALUES (?,?,?,?)');
foreach([[14,'UE5','Satisfactory'],[3,'UE1','UT99'],[6,'UE3','UT3']] as [$id,$engine,$name]){
 $profile->execute([$id,$engine,$name]);
 $game->execute([$id,$name,strtolower($name),$id]);
}
$insertFile=$db->prepare('INSERT INTO ue_files (id,game_id,scan_status) VALUES (?,?,"verified")');
$insertV5=$db->prepare('INSERT INTO ue_uedb5_files (file_id,game_id,format_version,payload_sha256) VALUES (?,?,5,?)');
$insertStatus=$db->prepare('INSERT INTO ue_uedb5_migration_status (file_id,game_id,dependency_completed_at,dependency_policy,dependency_payload_sha256) VALUES (?,?,"2026-11-03 14:30:00",?,?)');
foreach([[301,3,true],[601,6,false]] as [$fileId,$gid,$current]){
 $sha=hash('sha256',"isolated-snapshot-{$fileId}");
 $insertFile->execute([$fileId,$gid]);
 $insertV5->execute([$fileId,$gid,$sha]);
 $insertStatus->execute([$fileId,$gid,$current?Pass2::policyForEngine($gid===3?'UE1':'UE3'):'uedb5-dependency-pass-v13',$sha]);
}
$q=new PdoGameMissingDependencyQuery($db);
$cross=new PdoGameDependencyCrossExamineQuery($db,['storage_path'=>'D:/Temp/UnrealDB/isolated-no-files/']);
$observed=[];
foreach([[14,'UE5','zero-source-unavailable'],[6,'UE3','stale-source-unavailable'],[3,'UE1','current-source-zero-missing']] as [$gid,$engine,$case]){
 $fresh=$q->publicationFreshness($gid,$engine);
 try{
   $result=$cross->fetch($gid,0,10);
   $observed[$case]=['status'=>'RETURNED','rows'=>count($result['rows']),'freshness'=>$fresh];
 }catch(RuntimeException $e){
   $observed[$case]=['status'=>'REJECTED','reason'=>$e->getMessage(),'freshness'=>$fresh];
 }
}
$zero=$observed['zero-source-unavailable'];
if($zero['status']!=='REJECTED'
 || !str_contains((string)$zero['reason'],'no verified files')
 || $zero['freshness']['verified']!==0)throw new RuntimeException('An empty engine target was falsely reported as no missing dependencies.');
$stale=$observed['stale-source-unavailable'];
if($stale['status']!=='REJECTED'
 || !str_contains((string)$stale['reason'],'not current for 1 verified files'))throw new RuntimeException('Stale publication was not rejected.');
$current=$observed['current-source-zero-missing'];
if($current['status']!=='RETURNED' || $current['rows']!==0
 || $current['freshness']['verified']!==1
 || $current['freshness']['current']!==1)throw new RuntimeException('A genuinely published empty dependency set was incorrectly rejected.');
echo json_encode([
 'result'=>'PASS','isolated_test_cases'=>3,'engines'=>['UE5','UE3','UE1'],
 'empty_original_source_is_unavailable'=>true,
 'stale_legacy_source_is_unavailable'=>true,
 'genuinely_current_no_missing_result_still_allowed'=>true,
 'new_acceptance_contract_ids'=>0,'PRD_SQL_access'=>false,'PRD_data_updates'=>0,
 'observations'=>$observed
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
