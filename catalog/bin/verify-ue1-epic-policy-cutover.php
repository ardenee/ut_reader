#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "UE1 migration status is CLI only.\n");
    exit(1);
}
require_once dirname(__DIR__) . '/bootstrap.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameMissingDependencyQuery;
$app = catalog_bootstrap(false);
$db = $app->db;
$service = new Uedb5GameDependencyPassService($db, catalog_config());
$missing = new PdoGameMissingDependencyQuery($db);
$target = Uedb5GameDependencyPassService::UE1_DEPENDENCY_POLICY;
$args = getopt('', ['file-id:', 'game-id:']);
$canaryFile = (int)($args['file-id'] ?? 0);
if ($canaryFile > 0) {
    $canaryGame = (int)($args['game-id'] ?? 0);
    if (!(($canaryGame === 3 && $canaryFile === 113256)
        || ($canaryGame === 12 && $canaryFile === 1246569))) {
        throw new RuntimeException('Canary verification requires a known original UE1 file.');
    }
    $stmt = $db->prepare(
        'SELECT s.dependency_policy,HEX(s.dependency_payload_sha256) status_sha,'
        . 'HEX(v.payload_sha256) source_sha,'
        . '(SELECT COUNT(*) FROM ue_uedb5_dependency_edges e WHERE e.file_id=v.file_id) edges '
        . 'FROM ue_uedb5_files v JOIN ue_uedb5_migration_status s ON s.file_id=v.file_id '
        . 'WHERE v.file_id=? AND v.game_id=?'
    );
    $stmt->execute([$canaryFile, $canaryGame]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $reader = new \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader((string)catalog_config()['storage_path']);
    $snapshot = $reader->snapshot($canaryGame, $canaryFile);
    $importCount = count((array)($snapshot['sections']['imports'] ?? []));
    $ok = (string)($data['dependency_policy'] ?? '') === $target
        && (string)($data['status_sha'] ?? '') === (string)($data['source_sha'] ?? '')
        && (int)($data['edges'] ?? -1) === $importCount;
    echo json_encode([
        'ok' => $ok, 'game_id' => $canaryGame, 'file_id' => $canaryFile,
        'policy' => $data['dependency_policy'] ?? null, 'expected_policy' => $target,
        'source_payload_matches_published' => ($data['status_sha'] ?? null) === ($data['source_sha'] ?? null),
        'published_edges' => (int)($data['edges'] ?? -1),
        'source_imports' => $importCount,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
    exit($ok ? 0 : 2);
}
$games = [3 => 'UT99', 12 => 'Unreal Gold'];
$results = [];
$complete = true;
foreach ($games as $id => $label) {
    $pre = $service->preflight($id);
    $fresh = $missing->publicationFreshness($id, 'UE1');
    $sql = $db->prepare(
        'SELECT COUNT(*) FROM ue_uedb5_migration_status s '
        . 'JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
        . 'WHERE f.game_id=? AND f.scan_status="verified" AND s.dependency_policy=?'
    );
    $sql->execute([$id, Uedb5GameDependencyPassService::DEPENDENCY_POLICY]);
    $oldRows = (int)$sql->fetchColumn();
    $ok = !empty($pre['pass2_ready'])
        && (int)$fresh['verified'] === (int)$pre['verified_count']
        && (int)$fresh['current'] === (int)$fresh['verified']
        && (int)$fresh['stale'] === 0
        && $oldRows === 0
        && (string)$fresh['expected_policy'] === $target;
    $complete = $complete && $ok;
    $results[] = [
        'id' => $id, 'game' => $label,
        'verified' => (int)$fresh['verified'],
        'epic_source_policy_current' => (int)$fresh['current'],
        'stale' => (int)$fresh['stale'],
        'old_v13_ue1_rows' => $oldRows,
        'selected_policy' => $target,
        'preflight_ready' => !empty($pre['pass2_ready']),
        'cutover_complete' => $ok,
    ];
}
$other = [];
foreach ([2 => 'UE2', 5 => 'UE3', 6 => 'UE4', 7 => 'UE4'] as $id => $engine) {
    $fresh = $missing->publicationFreshness($id, $engine);
    if ((int)$fresh['stale'] !== 0) {
        throw new RuntimeException('Non-UE1 dependency status changed or is stale: game ' . $id);
    }
    $other[] = [
        'id' => $id, 'engine' => $engine,
        'current' => (int)$fresh['current'],
        'stale' => (int)$fresh['stale'],
        'preserved_policy' => (string)$fresh['expected_policy'],
    ];
}
echo json_encode([
    'ue1_cutover_complete' => $complete,
    'old_v13_policy_preserved_only_for_other_engines' => true,
    'ue1' => $results,
    'other_engines' => $other
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), PHP_EOL;
exit($complete ? 0 : 2);
