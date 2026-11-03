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
// A current target may have current cross-game reverse dependencies, but
// neither old-v13 sources nor an unsupported profile can publish a link.
// The old JSON endpoint's ELSE v13 SQL would admit the unsupported source.
$foreignGameId = 15;
$foreignFileId = 1501;
$targetFileId = 301; // Current UE1/UT99 fixture, requires cross-engine source checks.
$insert['profile']->execute([$foreignGameId,'UE42',1]);
$insert['game']->execute([$foreignGameId,$foreignGameId]);
$insert['file']->execute([$foreignFileId,$foreignGameId,'verified']);
$foreignSha=hash('sha256','unsupported-v13-owner');
$insert['v5']->execute([$foreignFileId,$foreignGameId,5,$foreignSha]);
$insert['policy']->execute([$foreignFileId,$foreignGameId,
    Pass2::DEPENDENCY_POLICY,$foreignSha,'2026-11-03 12:00:00']);
foreach ([201,501,302,$foreignFileId] as $sourceFileId) {
    $insert['edge']->execute([$sourceFileId,$targetFileId]);
}
$reverseSql='SELECT e.file_id FROM ue_uedb5_dependency_edges e '
    . Scope::joinCurrentEdges('e')
    . ' WHERE e.resolved_file_id=? ORDER BY e.file_id';
$reverse=$db->prepare($reverseSql);
$reverse->execute([$targetFileId]);
$sourceIds=array_map('intval',$reverse->fetchAll(PDO::FETCH_COLUMN));
if ($sourceIds!==[201,501]) {
    throw new RuntimeException('Current cross-engine reverse source gate admitted stale or unknown owners: '
        . json_encode($sourceIds,JSON_THROW_ON_ERROR));
}
$unsupportedStmt=$db->prepare('SELECT '.Scope::currentFilePredicate('f')
    .' FROM ue_files f WHERE f.id=?');
$unsupportedStmt->execute([$foreignFileId]);
if ((int)$unsupportedStmt->fetchColumn()!==0) {
    throw new RuntimeException('Unsupported UE42 source profile was granted a current publication.');
}
// Reconstruct the former JSON endpoint's policy CASE to demonstrate why the
// inherited v13 fallback was unsafe for a cross-game Required By source.
$oldPolicy='CASE UPPER(pr.engine_key)';
foreach (['UE1','UE2','UE3','UE4','UE5'] as $engine) {
    $oldPolicy.=' WHEN '.$db->quote($engine).' THEN '
        .$db->quote(Pass2::policyForEngine($engine));
}
$oldPolicy.=' ELSE '.$db->quote(Pass2::DEPENDENCY_POLICY).' END';
$legacyReverse=$db->prepare(
    'SELECT e.file_id FROM ue_uedb5_dependency_edges e '
    . 'JOIN ue_uedb5_files v ON v.file_id=e.file_id AND v.format_version=5 '
    . 'JOIN ue_games g ON g.id=v.game_id '
    . 'JOIN ue_game_profiles pr ON pr.id=g.profile_id AND pr.is_active=1 '
    . 'JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id AND s.game_id=v.game_id '
    . 'AND s.dependency_completed_at IS NOT NULL '
    . 'AND s.dependency_payload_sha256=v.payload_sha256 '
    . 'AND s.dependency_policy=('.$oldPolicy.') '
    . 'WHERE e.resolved_file_id=? ORDER BY e.file_id'
);
$legacyReverse->execute([$targetFileId]);
$formerSourceIds=array_map('intval',$legacyReverse->fetchAll(PDO::FETCH_COLUMN));
if ($formerSourceIds!==[201,501,$foreignFileId]) {
    throw new RuntimeException('Legacy v13 fallback comparison did not reproduce the source-profile leak: '
        . json_encode($formerSourceIds,JSON_THROW_ON_ERROR));
}
$endpoint=file_get_contents(dirname(__DIR__,2).'/catalog/file-examine-dependencies.php');
if (!is_string($endpoint)
    || !str_contains($endpoint, '$scope::currentFilePredicate(\'f\')')
    || substr_count($endpoint, '$currentEdgeJoin')!==3
    || str_contains($endpoint, '$enginePolicySql')) {
    throw new RuntimeException('JSON endpoint did not use the shared exact policy/payload freshness gate.');
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
    'current_cross_game_reverse_sources'=>count($sourceIds),
    'unsupported_engine_v13_fallback_leaked_before'=>in_array($foreignFileId,$formerSourceIds,true),
    'unsupported_engine_source_hidden_after'=>!in_array($foreignFileId,$sourceIds,true),
    'unsupported_engine_and_cross_game_extra_fixture_rows'=>1,
    'JSON_endpoint_uses_shared_owner_source_gate'=>true,
    'original_native_engine_or_website_HTTP_execution'=>false,
    'production_database_writes'=>0
], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),PHP_EOL;
