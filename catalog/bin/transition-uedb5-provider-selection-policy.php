#!/usr/bin/env php
<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;

const OLD_POLICY = 'uedb5-dependency-pass-v1';

$options = getopt('', ['game-id::','apply','rebuild-impacted','limit::']);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$apply = isset($options['apply']);
$rebuildImpacted = isset($options['rebuild-impacted']);
$limit = max(1, min(10000, (int)($options['limit'] ?? 100)));
if ($rebuildImpacted && !$apply) {
    fwrite(STDERR, "--rebuild-impacted requires --apply.\n");
    exit(1);
}

$app = catalog_bootstrap();
$db = $app->db;
$requiredTables = [
    'ue_uedb5_migration_status','ue_uedb5_files','ue_uedb5_dependency_edges',
    'ue_uedb5_provider_keys','ue_files','ue_invalid_file_identities',
];
$tableCheck = $db->prepare(
    'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
);
foreach ($requiredTables as $table) {
    $tableCheck->execute([$table]);
    if ((int)$tableCheck->fetchColumn() !== 1) {
        fwrite(STDERR, json_encode([
            'ok'=>false,
            'error'=>'Run catalog/bin/migrate.php migrate before the UEDB5 provider-selection policy transition.',
            'missing_table'=>$table,
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
}
if ($gameId > 0) {
    $gameCheck = $db->prepare('SELECT COUNT(*) FROM ue_games WHERE id=?');
    $gameCheck->execute([$gameId]);
    if ((int)$gameCheck->fetchColumn() !== 1) {
        fwrite(STDERR, json_encode(['ok'=>false,'error'=>'Unknown game ID.','game_id'=>$gameId], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(1);
    }
}
$newPolicy = Uedb5GameDependencyPassService::DEPENDENCY_POLICY;
if ($newPolicy === OLD_POLICY) {
    throw new RuntimeException('Provider-selection policy transition requires a new dependency policy identifier.');
}

$ambiguousKeysSql =
    'SELECT p.game_id,p.package_key_kind,p.package_key '
    . 'FROM ue_uedb5_provider_keys p '
    . 'JOIN ue_uedb5_files pv ON pv.file_id=p.file_id AND pv.game_id=p.game_id '
    . 'JOIN ue_files pf ON pf.id=p.file_id AND pf.game_id=p.game_id '
    . 'WHERE pf.scan_status="verified" '
    . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
    . 'WHERE bad.file_size=pf.file_size AND bad.md5=LOWER(pf.md5) AND bad.sha1=LOWER(pf.sha1)) '
    . 'GROUP BY p.game_id,p.package_key_kind,p.package_key '
    . 'HAVING COUNT(DISTINCT p.file_id)>1';

$impactExistsSql =
    'EXISTS (SELECT 1 FROM ue_uedb5_dependency_edges e '
    . 'JOIN (' . $ambiguousKeysSql . ') amb ON amb.game_id=v.game_id '
    . 'AND amb.package_key_kind=e.required_package_key_kind AND amb.package_key=e.required_package_key '
    . 'WHERE e.file_id=s.file_id AND e.required_package_key_kind IS NOT NULL)';

$gameWhere = $gameId > 0 ? ' AND f.game_id=?' : '';
$gameArgs = $gameId > 0 ? [$gameId] : [];
$count = static function(PDO $db, string $sql, array $args = []): int {
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return (int)$statement->fetchColumn();
};

$baseCurrent =
    ' FROM ue_uedb5_migration_status s '
    . 'JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id '
    . 'JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
    . 'WHERE f.scan_status="verified" AND s.dependency_policy=? '
    . 'AND s.dependency_payload_sha256=v.payload_sha256' . $gameWhere;
$currentArgs = array_merge([OLD_POLICY], $gameArgs);

$oldCurrent = $count($db, 'SELECT COUNT(*)' . $baseCurrent, $currentArgs);
$impacted = $count(
    $db,
    'SELECT COUNT(*) FROM (SELECT DISTINCT s.file_id' . $baseCurrent . ' AND ' . $impactExistsSql . ') impacted',
    $currentArgs
);
$eligible = $count(
    $db,
    'SELECT COUNT(*)' . $baseCurrent . ' AND NOT ' . $impactExistsSql,
    $currentArgs
);
$stale = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_migration_status s '
    . 'JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id '
    . 'JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
    . 'WHERE f.scan_status="verified" AND s.dependency_policy=? '
    . 'AND (s.dependency_payload_sha256 IS NULL OR s.dependency_payload_sha256<>v.payload_sha256)'
    . $gameWhere,
    array_merge([OLD_POLICY], $gameArgs)
);

$impactedSql =
    'SELECT DISTINCT s.file_id,s.game_id' . $baseCurrent . ' AND ' . $impactExistsSql
    . ' ORDER BY s.game_id,s.file_id';
$impactedStatement = $db->prepare($impactedSql);
$impactedStatement->execute($currentArgs);
$impactedRows = $impactedStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];

$byGame = [];
foreach ($impactedRows as $row) {
    $gid = (int)$row['game_id'];
    $byGame[$gid][] = (int)$row['file_id'];
}
ksort($byGame);
$preflight = [
    'old_policy' => OLD_POLICY,
    'new_policy' => $newPolicy,
    'game_id' => $gameId > 0 ? $gameId : null,
    'old_policy_current_payload_count' => $oldCurrent,
    'unaffected_rollforward_count' => $eligible,
    'impacted_rebuild_count' => $impacted,
    'old_policy_stale_payload_count' => $stale,
    'impacted_file_ids_by_game' => $byGame,
    'proof' => 'Only current v1 payloads whose existing dependency edges reference a package key with >1 valid physical V5 provider are semantically affected by the v2 provider-selection rule.',
];

if (!$apply) {
    echo json_encode(['ok'=>true,'apply'=>false,'preflight'=>$preflight], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

$updateSql =
    'UPDATE ue_uedb5_migration_status s '
    . 'JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id '
    . 'JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id '
    . 'SET s.dependency_policy=?,s.updated_at=UTC_TIMESTAMP() '
    . 'WHERE f.scan_status="verified" AND s.dependency_policy=? '
    . 'AND s.dependency_payload_sha256=v.payload_sha256' . $gameWhere
    . ' AND NOT ' . $impactExistsSql;
$update = $db->prepare($updateSql);
$update->execute(array_merge([$newPolicy, OLD_POLICY], $gameArgs));
$rolledForward = $update->rowCount();

$rebuilt = [];
$failed = [];
if ($rebuildImpacted) {
    $service = new Uedb5GameDependencyPassService($db, catalog_config());
    foreach (array_slice($impactedRows, 0, $limit) as $row) {
        $fid = (int)$row['file_id'];
        $gid = (int)$row['game_id'];
        try {
            $result = $service->runFile($gid, $fid, true);
            $rebuilt[] = ['game_id'=>$gid,'file_id'=>$fid,'dependency_count'=>(int)($result['result']['dependency_count'] ?? 0)];
        } catch (Throwable $error) {
            $failed[] = ['game_id'=>$gid,'file_id'=>$fid,'error'=>$error->getMessage()];
        }
    }
}

$remainingImpacted = $count(
    $db,
    'SELECT COUNT(*) FROM (SELECT DISTINCT s.file_id' . $baseCurrent . ' AND ' . $impactExistsSql . ') impacted',
    $currentArgs
);
$remainingV1Current = $count($db, 'SELECT COUNT(*)' . $baseCurrent, $currentArgs);

$result = [
    'ok' => $failed === [],
    'apply' => true,
    'preflight' => $preflight,
    'rolled_forward_unaffected' => $rolledForward,
    'rebuilt_impacted' => $rebuilt,
    'failed_impacted' => $failed,
    'remaining_impacted_v1' => $remainingImpacted,
    'remaining_current_v1' => $remainingV1Current,
    'rebuild_limit' => $rebuildImpacted ? $limit : 0,
];
echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === [] ? 0 : 2);
