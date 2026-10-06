#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5MigrationStatusRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;

$options = getopt('', ['apply','summary','file-id::','after-id::','limit::','storage-root::']);
$apply = array_key_exists('apply', $options);
$fileId = max(0, (int)($options['file-id'] ?? 0));
$afterId = max(0, (int)($options['after-id'] ?? 0));
$limit = max(1, min(10000, (int)($options['limit'] ?? 1000)));

$app = catalog_bootstrap();
$db = $app->db;
$config = catalog_config();
$storageOverride = trim((string)($options['storage-root'] ?? ''));
$storage = rtrim(
    $storageOverride !== '' ? $storageOverride : (string)($config['storage_path'] ?? ''),
    "\\/"
);
if ($storage === '') {
    fwrite(STDERR, "Catalog storage_path is required.\n");
    exit(2);
}

foreach (['ue_uedb5_files','ue_uedb5_migration_status','ue_files','ue_games'] as $tableName) {
    $q = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?'
    );
    $q->execute([$tableName]);
    if ((int)$q->fetchColumn() !== 1) {
        fwrite(STDERR, "Required staging table is missing: {$tableName}\n");
        exit(2);
    }
}

$game = $db->query("SELECT id FROM ue_games WHERE slug='ut4' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "UT4 game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];
$legacyPolicies = [
    'ue4-4.27.2-release-classic-package',
    Uedb5Ut4SnapshotBuilder::LEGACY_SOURCE_POLICY_V511_STRUCTURAL,
];
$targetPolicy = Uedb5Ut4SnapshotBuilder::SOURCE_POLICY;

$placeholders = implode(',', array_fill(0, count($legacyPolicies), '?'));
$sql = 'SELECT v.file_id,v.source_policy,f.package_version,f.licensee_version '
    . 'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
    . 'WHERE v.game_id=? AND v.source_policy IN (' . $placeholders . ') '
    . 'AND f.package_version BETWEEN 214 AND 511 AND f.licensee_version=0 '
    . 'AND v.file_id>? ';
$args = [$gameId, ...$legacyPolicies, $afterId];
if ($fileId > 0) {
    $sql .= 'AND v.file_id=? ';
    $args[] = $fileId;
}
$sql .= 'ORDER BY v.file_id LIMIT ' . $limit;
$stmt = $db->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$reader = new Uedb5MetadataReader($storage);
$eligible = [];
$deferred = [];
$blocked = [];
$lastScannedId = $rows !== [] ? (int)$rows[array_key_last($rows)]['file_id'] : $afterId;

foreach ($rows as $row) {
    $id = (int)$row['file_id'];
    try {
        $summary = $reader->page($gameId, $id, 'summary', 0, 1)[0] ?? null;
        if (!is_array($summary)) {
            throw new RuntimeException('missing_uedb5_summary');
        }
    } catch (Throwable $e) {
        $blocked[$id] = 'staged_container_unavailable: ' . $e->getMessage();
        continue;
    }

    if (!empty($summary['unversioned'])) {
        $deferred[$id] = 'unversioned_requires_original_byte_reparse';
        continue;
    }
    if ((int)($summary['package_version'] ?? -1) !== (int)$row['package_version']
        || (int)($summary['licensee_version'] ?? -1) !== 0) {
        $blocked[$id] = 'staged_summary_identity_mismatch';
        continue;
    }

    $eligible[] = $id;
}

$result = [
    'ok' => $blocked === [],
    'apply' => $apply,
    'read_only' => !$apply,
    'game_id' => $gameId,
    'legacy_source_policies' => $legacyPolicies,
    'target_source_policy' => $targetPolicy,
    'candidate_count' => count($rows),
    'eligible_metadata_only_refresh_count' => count($eligible),
    'deferred_original_byte_reparse_count' => count($deferred),
    'blocked_count' => count($blocked),
    'after_id' => $afterId,
    'limit' => $limit,
    'next_after_id' => $lastScannedId,
];

if (!$apply) {
    if (!isset($options['summary'])) {
        $result['eligible_file_ids'] = $eligible;
        $result['deferred_original_byte_reparse'] = $deferred;
        $result['blocked'] = $blocked;
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($result['ok'] ? 0 : 2);
}

$writer = new Uedb5MetadataSnapshotWriter($storage);
$registration = new PdoUedb5StagingRegistrationRepository($db, $storage);
$statuses = new PdoUedb5MigrationStatusRepository($db);
$refreshed = [];
$failed = [];

foreach ($eligible as $id) {
    try {
        $snapshot = $reader->snapshot($gameId, $id);
        $schema = strtolower(trim((string)($snapshot['section_schemas']['imports'] ?? '')));
        if (!str_starts_with($schema, 'ue4.ut4.')) {
            throw new RuntimeException('Staged snapshot is not a UT4 import schema.');
        }

        $snapshotPolicy = (string)($snapshot['source_policy'] ?? '');
        if (in_array($snapshotPolicy, $legacyPolicies, true)) {
            $snapshot['source_policy'] = $targetPolicy;
            $writer->write($snapshot);
        } elseif ($snapshotPolicy !== $targetPolicy) {
            throw new RuntimeException('Staged source policy changed after preflight.');
        }

        $db->beginTransaction();
        try {
            $registered = $registration->refreshExisting($gameId, $id);
            $statuses->markStageSucceeded($id, $gameId);
            $db->commit();
        } catch (Throwable $dbError) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $dbError;
        }

        $payload = (string)($registered['payload_sha256'] ?? '');
        $refreshed[] = [
            'file_id' => $id,
            'payload_sha256' => strlen($payload) === 32 ? bin2hex($payload) : '',
        ];
    } catch (Throwable $e) {
        $failed[] = ['file_id'=>$id, 'error'=>$e->getMessage()];
    }
}

$result['ok'] = $failed === [] && $blocked === [];
$result['refreshed'] = $refreshed;
$result['failed'] = $failed;
$result['deferred_original_byte_reparse'] = $deferred;
$result['blocked'] = $blocked;
$result['resume_after_id'] = $lastScannedId;

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 2);
