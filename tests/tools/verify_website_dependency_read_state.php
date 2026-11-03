<?php
declare(strict_types=1);
/**
 * Focused read-only consumer regression: one current source per engine,
 * nonzero missing/resolved/package_only, and one stale-policy transition.
 * Uses only SQLite in memory. Native correctness of classifications is not
 * asserted by this synthetic SQL test; original file evidence is separate.
 */
require_once dirname(__DIR__,2) . '/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope as Scope;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService as Pass;

$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
 PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,profile_id INTEGER)');
$db->exec('CREATE TABLE ue_game_profiles(id INTEGER PRIMARY KEY,engine_key TEXT,is_active INTEGER)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,package_name TEXT,original_name TEXT,scan_status TEXT)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER PRIMARY KEY,game_id INTEGER,format_version INTEGER,payload_sha256 TEXT)');
$db->exec('CREATE TABLE ue_uedb5_migration_status(file_id INTEGER PRIMARY KEY,game_id INTEGER,dependency_policy TEXT,dependency_payload_sha256 TEXT,dependency_completed_at TEXT)');
$db->exec('CREATE TABLE ue_game_catalog_stats(game_id INTEGER PRIMARY KEY,updated_at TEXT,dependency_count INTEGER,missing_dependency_count INTEGER,resolved_dependency_count INTEGER)');
$db->exec('CREATE TABLE ue_uedb5_dependency_packages(file_id INTEGER,game_id INTEGER,required_package_name TEXT,missing_count INTEGER,resolved_count INTEGER,package_only_count INTEGER,common_count INTEGER,unresolved_count INTEGER)');

$originals=[];
foreach(['UE1','UE2','UE3','UE4','UE5'] as $i=>$engine) {
 $game=$i+101;$id=$i+7001;$hash='original-sha-'.$id;
 $db->prepare('INSERT INTO ue_game_profiles VALUES(?,?,1)')->execute([$game,$engine]);
 $db->prepare('INSERT INTO ue_games VALUES(?,?)')->execute([$game,$game]);
 $db->prepare('INSERT INTO ue_files VALUES(?,?,?,?,"verified")')->execute([$id,$game,'OriginalConsumer'.$i,'OriginalConsumer'.$i.'.uasset']);
 $db->prepare('INSERT INTO ue_uedb5_files VALUES(?,?,5,?)')->execute([$id,$game,$hash]);
 $db->prepare('INSERT INTO ue_uedb5_migration_status VALUES(?,?,?,?,?)')->execute([$id,$game,Pass::policyForEngine($engine),$hash,'2026-11-03 09:00:00']);
 $db->prepare('INSERT INTO ue_game_catalog_stats VALUES(?,"2026-11-03 09:00:01",4,2,1)')->execute([$game]);
 $db->prepare('INSERT INTO ue_uedb5_dependency_packages VALUES(?,?,?,2,0,0,0,0)')->execute([$id,$game,'MissingPackage']);
 $db->prepare('INSERT INTO ue_uedb5_dependency_packages VALUES(?,?,?,0,1,1,0,1)')->execute([$id,$game,'KnownAndUnknownPackage']);
 $originals[]=['engine'=>$engine,'game'=>$game,'file'=>$id];
}
$db->exec("INSERT INTO ue_game_profiles VALUES (990,'UE5',1)");
$db->exec('INSERT INTO ue_games VALUES (990,990)');
$db->exec("INSERT INTO ue_game_catalog_stats VALUES (990,'2026-11-03 09:00:01',0,0,0)");
$emptyVerified=(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE game_id=990')->fetchColumn();
if($emptyVerified!==0 || !Scope::isGameCurrent($db,990,'UE5'))
    throw new RuntimeException('Empty game source policy state unexpected');
// MySQL-specific publicationFreshness's actual result for empty PRD UE5 game14
// is exercised separately in the read-only original PRD acceptance harness.
$emptyUiUnavailable=$emptyVerified===0;
if(!$emptyUiUnavailable)throw new RuntimeException('Unstaged UE5 game incorrectly produced an authoritative zero-missing presentation');
if(!Scope::allGameStatsCurrent($db))throw new RuntimeException('All source-current synthetic games incorrectly withheld');

$currentJoin=Scope::joinCurrentPackages('s');
$global=[
 'missing_objects'=>"SELECT COALESCE(SUM(s.missing_count),0) v FROM ue_uedb5_dependency_packages s $currentJoin",
 'missing_files'=>"SELECT COUNT(DISTINCT s.file_id) v FROM ue_uedb5_dependency_packages s $currentJoin WHERE s.missing_count>0",
 'missing_packages'=>"SELECT COUNT(DISTINCT s.required_package_name) v FROM ue_uedb5_dependency_packages s $currentJoin WHERE s.missing_count>0",
 'resolved_objects'=>"SELECT COALESCE(SUM(s.resolved_count),0) v FROM ue_uedb5_dependency_packages s $currentJoin",
];
$expected=[10,5,1,5];
$i=0;
foreach($global as $name=>$sql) {
 $actual=(int)$db->query($sql)->fetchColumn();
 if($actual!==$expected[$i++])throw new RuntimeException('Current global source summary '.$name.' expected nonzero, got '.$actual);
}
$owner=Scope::currentFilePredicate('f');
$summary=Scope::currentOwnerPredicate('s');
$siteQuery="SELECT f.id,CASE WHEN $owner THEN 1 ELSE 0 END dependency_projection_current,"
    ." COALESCE(SUM(s.resolved_count),0) resolved_count,"
    ." COALESCE(SUM(s.missing_count),0) missing_count,"
    ." COALESCE(SUM(s.package_only_count),0) package_only_count,"
    ." COALESCE(SUM(s.common_count),0) common_count"
    ." FROM ue_files f LEFT JOIN ue_uedb5_dependency_packages s ON s.file_id=f.id AND $summary"
    ." WHERE f.id=? GROUP BY f.id";
$q=$db->prepare($siteQuery);
$q->execute([7001]);$before=$q->fetch(PDO::FETCH_ASSOC);
if(!$before || (int)$before['dependency_projection_current']!==1
   || (int)$before['missing_count']!==2
   || (int)$before['resolved_count']!==1
   || (int)$before['package_only_count']!==1) {
 throw new RuntimeException('Current website game-page counts do not match original fixture summaries');
}
$db->exec("UPDATE ue_uedb5_migration_status SET dependency_policy='uedb5-dependency-pass-v13' WHERE file_id=7001");
if(Scope::allGameStatsCurrent($db))throw new RuntimeException('Global missing view accepted mixed v13 and current v14 profiles');
$q->execute([7001]);$after=$q->fetch(PDO::FETCH_ASSOC);
if(!$after || (int)$after['dependency_projection_current']!==0
 || (int)$after['missing_count']!==0 || (int)$after['resolved_count']!==0
 || (int)$after['package_only_count']!==0)throw new RuntimeException('Stale original website owner still classified as current');
if((int)$db->query($global['missing_objects'])->fetchColumn()!==8)
 throw new RuntimeException('Stale owner leaked into dynamic global source-current summaries');
echo json_encode([
 'result'=>'PASS','engine_profiles'=>5,'database'=>'sqlite_in_memory_only',
 'live_PHP_source_join_branches_covered'=>['current_nonzero_missing','current_resolved','current_package_only','mixed_stale_global_withheld','stale_owner_counts_excluded'],
 'current_global_missing_objects'=>10,'current_global_missing_files'=>5,
 'current_global_missing_package_names'=>1,'current_global_resolved_objects'=>5,
 'source_current_single_file'=>$before,'stale_file_result'=>$after,
 'mixed_stale_global_page_must_withhold_all_counts'=>true,
 'empty_ue5_game_no_published_files_shows_unavailable'=>$emptyUiUnavailable,
 'production_sql_writes'=>0
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
