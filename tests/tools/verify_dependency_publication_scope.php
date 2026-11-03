<?php
declare(strict_types=1);
/**
 * Focused, pure in-memory regression for the five engine-specific UEDB5
 * dependency publication gates. Does not open production DB or change files.
 *
 * A synthetic SQL shape is used ONLY to validate policy/sha rejection logic;
 * this is not an Epic native engine oracle or end-to-end file proof.
 */
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope as Scope;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService as Policy;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,format_version INTEGER,payload_sha256 TEXT)');
$db->exec('CREATE TABLE ue_uedb5_migration_status(file_id INTEGER PRIMARY KEY,game_id INTEGER,dependency_policy TEXT,dependency_payload_sha256 TEXT,dependency_completed_at TEXT)');
$db->exec('CREATE TABLE ue_game_catalog_stats(game_id INTEGER PRIMARY KEY,updated_at TEXT,dependency_count INTEGER DEFAULT 0,missing_dependency_count INTEGER DEFAULT 0,resolved_dependency_count INTEGER DEFAULT 0)');
$db->exec('CREATE TABLE ue_uedb5_dependency_edges(file_id INTEGER,source_index INTEGER,outcome INTEGER,resolved_file_id INTEGER)');
$db->exec('CREATE TABLE ue_uedb5_dependency_packages(file_id INTEGER,game_id INTEGER,missing_count INTEGER)');

function expected(PDO $db,int $gameId,int $fileId,string $engine): void {
    $joined=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges e '.Scope::joinCurrentEdges('e').' WHERE e.file_id='.$fileId)->fetchColumn();
    $summary=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages p '.Scope::joinCurrentPackages('p').' WHERE p.file_id='.$fileId)->fetchColumn();
    $pred=(int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages p WHERE '.Scope::currentOwnerPredicate('p').' AND p.file_id='.$fileId)->fetchColumn();
    $file=(int)$db->query('SELECT COUNT(*) FROM ue_files f WHERE '.Scope::currentFilePredicate('f').' AND f.id='.$fileId)->fetchColumn();
    if([$joined,$summary,$pred,$file]!==[1,1,1,1]
        || !Scope::isGameCurrent($db,$gameId,$engine)
        || !Scope::isGameStatsCurrent($db,$gameId,$engine)) {
        throw new RuntimeException($engine.' current policy acceptance failed');
    }
}

foreach(['UE1','UE2','UE3','UE4','UE5'] as $n=>$engine) {
    $id=$n+101;$f=$n+7001;$profile=$n+901;
    $insert=$db->prepare('INSERT INTO ue_games VALUES(?,?)');$insert->execute([$id,$profile]);
    $insert=$db->prepare('INSERT INTO ue_game_profiles VALUES(?,?,1)');$insert->execute([$profile,$engine]);
    $insert=$db->prepare('INSERT INTO ue_files VALUES(?,?,"verified")');$insert->execute([$f,$id]);
    $insert=$db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,5,?)');$insert->execute([$f,$id,'exact-hash-'.$f]);
    $insert=$db->prepare('INSERT INTO ue_uedb5_migration_status VALUES(?,?,?,?,?)');
    $insert->execute([$f,$id,Policy::policyForEngine($engine),'exact-hash-'.$f,'2026-11-03 06:00:00']);
    $insert=$db->prepare('INSERT INTO ue_game_catalog_stats(game_id,updated_at) VALUES(?,"2026-11-03 06:00:01")');$insert->execute([$id]);
    $db->exec("INSERT INTO ue_uedb5_dependency_edges VALUES($f,0,1,200)");
    $db->exec("INSERT INTO ue_uedb5_dependency_packages VALUES($f,$id,0)");
    expected($db,$id,$f,$engine);
    $db->exec("UPDATE ue_uedb5_migration_status SET dependency_policy='uedb5-dependency-pass-v13' WHERE file_id=$f");
    if((int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_edges e '.Scope::joinCurrentEdges('e').' WHERE e.file_id='.$f)->fetchColumn()!==0
       || Scope::isGameCurrent($db,$id,$engine))throw new RuntimeException($engine.' accepted old v13 policy');
    $db->prepare('UPDATE ue_uedb5_migration_status SET dependency_policy=? WHERE file_id=?')
       ->execute([Policy::policyForEngine($engine),$f]);
    $db->exec("UPDATE ue_uedb5_migration_status SET dependency_payload_sha256='changed' WHERE file_id=$f");
    if((int)$db->query('SELECT COUNT(*) FROM ue_uedb5_dependency_packages p '.Scope::joinCurrentPackages('p').' WHERE p.file_id='.$f)->fetchColumn()!==0
       || Scope::isGameCurrent($db,$id,$engine))throw new RuntimeException($engine.' accepted stale payload');
    $db->prepare('UPDATE ue_uedb5_migration_status SET dependency_payload_sha256=? WHERE file_id=?')
       ->execute(['exact-hash-'.$f,$f]);
    $db->exec("UPDATE ue_game_catalog_stats SET updated_at='2026-11-03 05:59:59' WHERE game_id=$id");
    if(Scope::isGameStatsCurrent($db,$id,$engine))throw new RuntimeException($engine.' accepted stale cached aggregate');
    $db->exec("UPDATE ue_game_catalog_stats SET updated_at='2026-11-03 06:00:01' WHERE game_id=$id");
    $db->exec("UPDATE ue_uedb5_migration_status SET dependency_completed_at=NULL WHERE file_id=$f");
    if(Scope::isGameCurrent($db,$id,$engine))throw new RuntimeException($engine.' accepted uncompleted publication');
    $db->exec("UPDATE ue_uedb5_migration_status SET dependency_completed_at='2026-11-03 06:00:00' WHERE file_id=$f");
    expected($db,$id,$f,$engine);
}
$db->exec("INSERT INTO ue_games VALUES (990,999)");
$db->exec("INSERT INTO ue_game_profiles VALUES (999,'UE5',1)");
$db->exec("INSERT INTO ue_game_catalog_stats(game_id,updated_at,dependency_count,missing_dependency_count,resolved_dependency_count) VALUES (990,'2026-11-03 06:00:01',11,11,0)");
if(Scope::isGameStatsCurrent($db,990,'UE5')) throw new RuntimeException('Empty game accepted stale cached counts');
$db->exec('UPDATE ue_game_catalog_stats SET dependency_count=0,missing_dependency_count=0 WHERE game_id=990');
if(!Scope::isGameStatsCurrent($db,990,'UE5')) throw new RuntimeException('Empty game with zero cached counters rejected');
try{Scope::currentOwnerPredicate('e;DROP TABLE z');throw new RuntimeException('Unsafe SQL alias accepted');}
catch(InvalidArgumentException){}
if(!Scope::allGameStatsCurrent($db))throw new RuntimeException('All-current detection failed');
$db->exec("UPDATE ue_uedb5_migration_status SET dependency_policy='uedb5-dependency-pass-v13' WHERE file_id=7003");
if(Scope::allGameStatsCurrent($db))throw new RuntimeException('Any-stale detection failed');
echo json_encode(['status'=>'PASS','engine_cases'=>5,'checks_per_engine'=>[
  'current_v14_edge_summary_and_owner_predicate','wrong_policy_fails_closed',
  'wrong_payload_fails_closed','stale_stats_timestamp_fails_closed','fresh_stats_accepted','no_publish_completion_rejected','empty_game_stale_cached_count_rejected'
],'all_games_mixed_state_fails_closed'=>true,'database'=>'sqlite_in_memory_only','prd_sql_writes'=>0],
JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),"\n";
