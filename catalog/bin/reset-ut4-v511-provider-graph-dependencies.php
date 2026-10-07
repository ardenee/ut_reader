#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5StagingIsolationContract;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;

$options = getopt('', ['apply','summary']);
$apply = array_key_exists('apply', $options);

$app = catalog_bootstrap();
$db = $app->db;

$game = $db->query("SELECT id,name,slug FROM ue_games WHERE slug='ut4' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "UT4 game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];

$count = static function(PDO $db, string $sql, array $args=[]): int {
    $s = $db->prepare($sql);
    $s->execute($args);
    return (int)$s->fetchColumn();
};

$total = $count($db, 'SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=?', [$gameId]);
$canonical = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=? AND source_policy=?',
    [$gameId, Uedb5Ut4SnapshotBuilder::SOURCE_POLICY]
);
$statusCount = $count($db, 'SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE game_id=?', [$gameId]);
$primaryProviders = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id '
    . 'WHERE v.game_id=? AND p.source_kind=1 AND p.source_id=p.file_id',
    [$gameId]
);
$badPrimaryKind = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_provider_keys p JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id '
    . 'WHERE v.game_id=? AND p.source_kind=1 AND p.source_id=p.file_id AND p.package_key_kind<>?',
    [$gameId, Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME]
);
$duplicatePrimary = $count(
    $db,
    'SELECT COUNT(*) FROM (SELECT p.file_id,COUNT(*) c FROM ue_uedb5_provider_keys p '
    . 'JOIN ue_uedb5_files v ON v.file_id=p.file_id AND v.game_id=p.game_id '
    . 'WHERE v.game_id=? AND p.source_kind=1 AND p.source_id=p.file_id GROUP BY p.file_id HAVING c<>1) q',
    [$gameId]
);
$dependencyComplete = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE game_id=? AND dependency_policy IS NOT NULL '
    . 'AND dependency_payload_sha256 IS NOT NULL AND dependency_completed_at IS NOT NULL',
    [$gameId]
);
$validated = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE game_id=? AND status="validated"',
    [$gameId]
);

$preflightOk = $total > 0
    && $canonical === $total
    && $statusCount === $total
    && $primaryProviders === $total
    && $badPrimaryKind === 0
    && $duplicatePrimary === 0;

$result = [
    'ok'=>$preflightOk,
    'apply'=>$apply,
    'read_only'=>!$apply,
    'game'=>$game,
    'staged_count'=>$total,
    'canonical_policy_count'=>$canonical,
    'status_count'=>$statusCount,
    'primary_provider_count'=>$primaryProviders,
    'bad_primary_key_kind_count'=>$badPrimaryKind,
    'duplicate_primary_provider_count'=>$duplicatePrimary,
    'dependency_complete_before'=>$dependencyComplete,
    'validated_before'=>$validated,
    'required_primary_key_kind'=>Uedb5SqlProjectionContract::PACKAGE_KEY_CLASSIC_FNAME,
    'reset_reason'=>'provider_key_graph_changed_after_dependency_pass',
];

if (!$preflightOk) {
    $result['error'] = 'UT4 provider graph is not in canonical v511 state; refusing dependency reset.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(2);
}

if (!$apply) {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit(0);
}

Uedb5StagingIsolationContract::assertWriteTable('ue_uedb5_migration_status');
$now = gmdate('Y-m-d H:i:s');

$db->beginTransaction();
try {
    $s = $db->prepare(
        'UPDATE ue_uedb5_migration_status s '
        . 'JOIN ue_uedb5_files v ON v.file_id=s.file_id AND v.game_id=s.game_id '
        . 'SET s.status="staged",s.validator_policy=NULL,s.last_checked_payload_sha256=NULL,'
        . 's.validated_payload_sha256=NULL,s.dependency_policy=NULL,s.dependency_payload_sha256=NULL,'
        . 's.dependency_completed_at=NULL,s.last_error_code=NULL,s.last_error_text=NULL,'
        . 's.last_result_json=NULL,s.validated_at=NULL,s.failed_at=NULL,s.updated_at=? '
        . 'WHERE s.game_id=? AND v.source_policy=?'
    );
    $s->execute([$now, $gameId, Uedb5Ut4SnapshotBuilder::SOURCE_POLICY]);
    $updated = $s->rowCount();
    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) { $db->rollBack(); }
    throw $e;
}

$stagedAfter = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE game_id=? AND status="staged"',
    [$gameId]
);
$dependencyRemaining = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE game_id=? AND dependency_policy IS NULL '
    . 'AND dependency_payload_sha256 IS NULL AND dependency_completed_at IS NULL',
    [$gameId]
);
$validatedAfter = $count(
    $db,
    'SELECT COUNT(*) FROM ue_uedb5_migration_status WHERE game_id=? AND status="validated"',
    [$gameId]
);

$result['updated_rows'] = $updated;
$result['staged_after'] = $stagedAfter;
$result['dependency_invalidated_after'] = $dependencyRemaining;
$result['validated_after'] = $validatedAfter;
$result['ok'] = $stagedAfter === $total
    && $dependencyRemaining === $total
    && $validatedAfter === 0;

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 2);
