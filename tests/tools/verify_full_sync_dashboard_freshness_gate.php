<?php
declare(strict_types=1);
// Exercises the very same freshness predicate used by Full Sync finalize and
// by games.php, with in-memory tables only. No PRD schema or data is changed.
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope as Scope;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService as Pass;
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_uedb5_files(file_id INTEGER,game_id INTEGER,format_version INTEGER,payload_sha256 TEXT)');
$db->exec('CREATE TABLE ue_uedb5_migration_status(file_id INTEGER,game_id INTEGER,dependency_completed_at TEXT,dependency_policy TEXT,dependency_payload_sha256 TEXT)');
$db->exec('CREATE TABLE ue_game_catalog_stats(game_id INTEGER,updated_at TEXT,dependency_count INTEGER,missing_dependency_count INTEGER,resolved_dependency_count INTEGER)');
$db->exec('INSERT INTO ue_games VALUES(2)');
$db->exec("INSERT INTO ue_files VALUES(42,2,'verified')");
$db->exec("INSERT INTO ue_uedb5_files VALUES(42,2,5,'ABC')");
$policy=Pass::policyForEngine('UE2');
$ins=$db->prepare('INSERT INTO ue_uedb5_migration_status VALUES(42,2,?,?,?)');
$ins->execute(['2026-11-05 17:30:00',$policy,'WRONG']);
$stale=Scope::isGameStatsCurrent($db,2,'UE2')===false;
$db->exec("UPDATE ue_uedb5_migration_status SET dependency_payload_sha256='ABC' WHERE file_id=42");
$missingStats=Scope::isGameStatsCurrent($db,2,'UE2')===false;
$db->exec("INSERT INTO ue_game_catalog_stats VALUES(2,'2026-11-05 17:31:00',5,0,1)");
$fresh=Scope::isGameStatsCurrent($db,2,'UE2')===true;
$db->exec("UPDATE ue_uedb5_migration_status SET dependency_completed_at='2026-11-05 17:35:00' WHERE file_id=42");
$outdated=Scope::isGameStatsCurrent($db,2,'UE2')===false;
$db->exec("UPDATE ue_game_catalog_stats SET updated_at='2026-11-05 17:36:00' WHERE game_id=2");
$refreshed=Scope::isGameStatsCurrent($db,2,'UE2')===true;
$pass=$stale&&$missingStats&&$fresh&&$outdated&&$refreshed;
echo json_encode(['pass'=>$pass,'stale_payload_rejected'=>$stale,'missing_aggregate_rejected'=>$missingStats,
    'current_aggregate_accepted'=>$fresh,'outdated_aggregate_rejected'=>$outdated,
    'refreshed_aggregate_accepted'=>$refreshed,'production_changes'=>false],
    JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),PHP_EOL;
exit($pass?0:2);
