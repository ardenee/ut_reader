<?php
declare(strict_types=1);
/**
 * Five-engine UEDB5 website dependency freshness admission / exclusion.
 * Isolated SQLite test with no PRD SQL access or file writes.
 *
 * This verifies policy-gated SQL reader behavior (including UE2/UT2004's
 * UE1 wire format) but does NOT certify native mount selection, new Pass2
 * publication, or the HTTP-rendered website.
 */
require_once dirname(__DIR__, 2) . '/catalog/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5DependencyPublicationScope as Scope;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService as Pass2;

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
foreach ([
    'CREATE TABLE ue_games (id INTEGER PRIMARY KEY, profile_id INTEGER NOT NULL)',
    'CREATE TABLE ue_game_profiles (id INTEGER PRIMARY KEY, engine_key TEXT NOT NULL, is_active INTEGER NOT NULL)',
    'CREATE TABLE ue_files (id INTEGER PRIMARY KEY, game_id INTEGER NOT NULL, scan_status TEXT NOT NULL)',
    'CREATE TABLE ue_uedb5_files (file_id INTEGER PRIMARY KEY, game_id INTEGER NOT NULL, format_version INTEGER NOT NULL, payload_sha256 TEXT NOT NULL)',
    'CREATE TABLE ue_uedb5_migration_status (file_id INTEGER PRIMARY KEY, game_id INTEGER NOT NULL, dependency_policy TEXT NOT NULL, dependency_payload_sha256 TEXT NOT NULL, dependency_completed_at TEXT)',
    'CREATE TABLE ue_uedb5_dependency_edges (file_id INTEGER NOT NULL, resolved_file_id INTEGER, outcome INTEGER NOT NULL)',
    'CREATE TABLE ue_uedb5_dependency_packages (file_id INTEGER NOT NULL, game_id INTEGER NOT NULL, missing_count INTEGER NOT NULL)'
] as $ddl) {
    $db->exec($ddl);
}
$insert = [
    'profile' => $db->prepare('INSERT INTO ue_game_profiles (id,engine_key,is_active) VALUES (?,?,?)'),
    'game' => $db->prepare('INSERT INTO ue_games (id,profile_id) VALUES (?,?)'),
    'file' => $db->prepare('INSERT INTO ue_files (id,game_id,scan_status) VALUES (?,?,?)'),
    'v5' => $db->prepare('INSERT INTO ue_uedb5_files (file_id,game_id,format_version,payload_sha256) VALUES (?,?,?,?)'),
    'policy' => $db->prepare('INSERT INTO ue_uedb5_migration_status (file_id,game_id,dependency_policy,dependency_payload_sha256,dependency_completed_at) VALUES (?,?,?,?,?)'),
    'edge' => $db->prepare('INSERT INTO ue_uedb5_dependency_edges (file_id,resolved_file_id,outcome) VALUES (?,?,1)'),
    'package' => $db->prepare('INSERT INTO ue_uedb5_dependency_packages (file_id,game_id,missing_count) VALUES (?,?,1)'),
];
$games = [
    [3,'UE1','UT99'], [2,'UE2','Unreal2'], [5,'UE2','UT2004 UE1-wire'],
    [6,'UE3','UT3'], [7,'UE4','UT4'], [14,'UE5','UE5 classic/Zen']
];
$total = 0;
$currentFiles = [];
$expectedFileDecisions = [];
foreach ($games as [$gameId,$engine,$label]) {
    $insert['profile']->execute([$gameId,$engine,1]);
    $insert['game']->execute([$gameId,$gameId]);
    $currentPolicy = Pass2::policyForEngine($engine);
    $current = $gameId * 100 + 1;
    $currentFiles[$label] = $current;
    foreach (['current','old_v13','payload_mismatch','not_completed','unverified'] as $offset => $type) {
        $id = $gameId * 100 + $offset + 1;
        $payload = hash('sha256', $label . ':' . $id);
        $scanStatus = $type === 'unverified' ? 'pending' : 'verified';
        $insert['file']->execute([$id,$gameId,$scanStatus]);
        $insert['v5']->execute([$id,$gameId,5,$payload]);
        $insert['policy']->execute([
            $id,$gameId,
            $type === 'old_v13' ? Pass2::DEPENDENCY_POLICY : $currentPolicy,
            $type === 'payload_mismatch' ? hash('sha256', 'other-payload') : $payload,
            $type === 'not_completed' ? null : '2026-11-03 12:00:00'
        ]);
        $insert['edge']->execute([$id,900000 + $id]);
        $insert['package']->execute([$id,$gameId]);
        $expectedFileDecisions[$id] = $type === 'current';
        $total++;
    }
}
$actualFileDecisions = [];
$fileSql = $db->prepare(
    'SELECT ' . Scope::currentFilePredicate('f') .
    ' AS admitted FROM ue_files f WHERE f.id=?'
);
foreach ($expectedFileDecisions as $id=>$expected) {
    $fileSql->execute([$id]);
    $actual = (int)$fileSql->fetchColumn() === 1;
    if ($actual !== $expected) {
        throw new RuntimeException('Wrong file policy/payload freshness decision for isolated ID ' . $id);
    }
    $actualFileDecisions[$id] = $actual;
}
$edgeSql = $db->query(
    'SELECT e.file_id FROM ue_uedb5_dependency_edges e ' .
    Scope::joinCurrentEdges('e') . ' ORDER BY e.file_id'
);
$visibleEdges = array_map('intval',$edgeSql->fetchAll(PDO::FETCH_COLUMN));
$packageSql = $db->query(
    'SELECT p.file_id FROM ue_uedb5_dependency_packages p ' .
    Scope::joinCurrentPackages('p') . ' ORDER BY p.file_id'
);
$visiblePackages = array_map('intval',$packageSql->fetchAll(PDO::FETCH_COLUMN));
$ownerSql = $db->query(
    'SELECT e.file_id FROM ue_uedb5_dependency_edges e WHERE ' .
    Scope::currentOwnerPredicate('e') . ' ORDER BY e.file_id'
);
$visibleOwners = array_map('intval',$ownerSql->fetchAll(PDO::FETCH_COLUMN));
$expected = array_values($currentFiles);
sort($expected,SORT_NUMERIC);
foreach (['joinCurrentEdges'=>$visibleEdges,'joinCurrentPackages'=>$visiblePackages,'currentOwnerPredicate'=>$visibleOwners] as $method=>$actual) {
    if ($actual !== $expected) {
        throw new RuntimeException($method . ' failed to exclude stale/incomplete/mismatched dependency rows');
    }
}
foreach ($games as [$gameId,$engine,$label]) {
    if (Scope::isGameCurrent($db,$gameId,$engine)) {
        throw new RuntimeException('Game unexpectedly declared fully source-current despite bad files: ' . $label);
    }
}
$badAliasRejected = false;
try {
    Scope::joinCurrentEdges('e;DROP TABLE ue_files');
} catch (InvalidArgumentException) {
    $badAliasRejected = true;
}
if (!$badAliasRejected) throw new RuntimeException('Unsafe SQL alias accepted');
echo json_encode([
    'result'=>'PASS',
    'engine_families'=>5,
    'game_contexts'=>count($games),
    'fixture_file_rows'=>$total,
    'current_published_rows_visible'=>count($visibleEdges),
    'stale_or_invalid_rows_hidden'=>$total-count($visibleEdges),
    'exact_current_accepted_by_all_three_SQL_gates'=>true,
    'policy_version_payload_completed_and_verified_enforced'=>true,
    'wrong_SQL_alias_rejected'=>$badAliasRejected,
    'game_aggregates_correctly_not_current'=>true,
    'original_native_engine_or_website_HTTP_execution'=>false,
    'production_database_writes'=>0
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
