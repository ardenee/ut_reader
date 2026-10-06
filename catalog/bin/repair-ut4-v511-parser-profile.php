#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/lib/CatalogUE4ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5MigrationStatusRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5ProviderKeyPublisher;
use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5StagingRegistrationRepository;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;

$options = getopt('', ['apply','summary','ids-file:','after-id::','limit::','storage-root::']);
$apply = array_key_exists('apply', $options);
$idsFile = trim((string)($options['ids-file'] ?? ''));
$afterId = max(0, (int)($options['after-id'] ?? 0));
$limit = max(1, min(5000, (int)($options['limit'] ?? 1000)));

if ($idsFile === '' || !is_file($idsFile)) {
    fwrite(STDERR, "Usage: php catalog/bin/repair-ut4-v511-parser-profile.php --ids-file=PATH [--after-id=N] [--limit=1000] [--apply] [--summary] [--storage-root=PATH]\n");
    exit(1);
}

$ids = [];
foreach (file($idsFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $id = (int)trim($line);
    if ($id > $afterId) { $ids[$id] = true; }
}
$ids = array_keys($ids);
sort($ids, SORT_NUMERIC);
$ids = array_slice($ids, 0, $limit);

$app = catalog_bootstrap();
$db = $app->db;
$config = catalog_config();
$storageOverride = trim((string)($options['storage-root'] ?? ''));
$storage = rtrim($storageOverride !== '' ? $storageOverride : (string)($config['storage_path'] ?? ''), "\\/");
if ($storage === '') {
    fwrite(STDERR, "Catalog storage_path is required.\n");
    exit(2);
}

$game = $db->query("SELECT id,name,slug FROM ue_games WHERE slug='ut4' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "UT4 game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];

$profileRaw = catalog_ue4_parser_profile($config, $game, []);
$targetProfile = [
    'key' => (string)($profileRaw['profile_key'] ?? ''),
    'label' => (string)($profileRaw['label'] ?? ''),
    'assumed_unversioned_parser_version' => (int)($profileRaw['assumed_unversioned_parser_version'] ?? 0),
    'source_reference' => (string)($profileRaw['source_reference'] ?? ''),
    'raw' => $profileRaw,
];
if ($targetProfile['key'] !== 'ut4-alpha' || $targetProfile['assumed_unversioned_parser_version'] !== 511) {
    fwrite(STDERR, "Canonical UT4 parser profile is not the expected clean-master v511 profile.\n");
    exit(2);
}

$reader = new Uedb5MetadataReader($storage);
$providerPublisher = new PdoUedb5ProviderKeyPublisher($db);
$providerRows = static function(array $rows): array {
    $out = [];
    foreach ($rows as $row) {
        $row = (array)$row;
        $out[] = [
            'source_kind'=>(int)($row['source_kind'] ?? 0),
            'source_id'=>(int)($row['source_id'] ?? 0),
            'game_id'=>(int)($row['game_id'] ?? 0),
            'package_key_kind'=>(int)($row['package_key_kind'] ?? 0),
            'package_key_hex'=>isset($row['package_key']) ? bin2hex((string)$row['package_key']) : '',
            'file_id'=>(int)($row['file_id'] ?? 0),
        ];
    }
    usort($out, static fn(array $a,array $b): int => [$a['source_kind'],$a['source_id'],$a['file_id']] <=> [$b['source_kind'],$b['source_id'],$b['file_id']]);
    return $out;
};
$actualProviderStmt = $db->prepare(
    'SELECT source_kind,source_id,game_id,package_key_kind,package_key,file_id '
    . 'FROM ue_uedb5_provider_keys WHERE file_id=? ORDER BY source_kind,source_id'
);
$eligible = [];
$alreadyCanonical = [];
$blocked = [];

$contextSql = 'SELECT f.id,f.package_version,f.licensee_version,v.source_policy,v.payload_sha256,'
    . 's.status,s.dependency_policy,s.dependency_payload_sha256 '
    . 'FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
    . 'JOIN ue_uedb5_migration_status s ON s.file_id=f.id AND s.game_id=f.game_id '
    . 'WHERE f.game_id=? AND f.id=? LIMIT 1';
$contextStmt = $db->prepare($contextSql);

foreach ($ids as $id) {
    try {
        $contextStmt->execute([$gameId, $id]);
        $row = $contextStmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) { throw new RuntimeException('staging_context_missing'); }
        if ((string)$row['source_policy'] !== Uedb5Ut4SnapshotBuilder::SOURCE_POLICY) {
            throw new RuntimeException('source_policy_not_canonical_ut4_v511');
        }
        $version = (int)$row['package_version'];
        if ($version < 214 || $version > 511 || (int)$row['licensee_version'] !== 0) {
            throw new RuntimeException('catalogue_version_outside_ut4_clean_master');
        }
        if ((string)($row['dependency_policy'] ?? '') !== Uedb5GameDependencyPassService::DEPENDENCY_POLICY) {
            throw new RuntimeException('dependency_policy_not_current');
        }
        $registeredPayload = (string)($row['payload_sha256'] ?? '');
        $dependencyPayload = (string)($row['dependency_payload_sha256'] ?? '');
        if (strlen($registeredPayload) !== 32 || strlen($dependencyPayload) !== 32 || !hash_equals($registeredPayload, $dependencyPayload)) {
            throw new RuntimeException('dependency_checkpoint_not_current_payload');
        }

        $summary = $reader->page($gameId, $id, 'summary', 0, 1)[0] ?? null;
        if (!is_array($summary)) { throw new RuntimeException('missing_uedb5_summary'); }
        if (!empty($summary['unversioned'])) { throw new RuntimeException('unversioned_row_not_metadata_only'); }
        if ((int)($summary['package_version'] ?? -1) !== $version || (int)($summary['licensee_version'] ?? -1) !== 0) {
            throw new RuntimeException('staged_summary_identity_mismatch');
        }

        $currentProfile = (array)($summary['parser_profile'] ?? []);
        $profileNeedsRefresh = $currentProfile !== $targetProfile;
        if ($profileNeedsRefresh
            && ((string)($currentProfile['key'] ?? '') !== 'standard-ue4'
                || (int)($currentProfile['assumed_unversioned_parser_version'] ?? 0) !== 522)) {
            throw new RuntimeException('unexpected_existing_parser_profile');
        }

        $expectedProvider = $providerRows($providerPublisher->expectedRows($id));
        $actualProviderStmt->execute([$id]);
        $actualProvider = $providerRows($actualProviderStmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        $providerNeedsRefresh = $expectedProvider !== $actualProvider;

        if (!$profileNeedsRefresh && !$providerNeedsRefresh) {
            $alreadyCanonical[] = $id;
            continue;
        }

        $eligible[$id] = [
            'profile_refresh'=>$profileNeedsRefresh,
            'provider_refresh'=>$providerNeedsRefresh,
        ];
    } catch (Throwable $e) {
        $blocked[$id] = $e->getMessage();
    }
}

$lastScannedId = $ids !== [] ? (int)$ids[array_key_last($ids)] : $afterId;
$result = [
    'ok' => $blocked === [],
    'apply' => $apply,
    'read_only' => !$apply,
    'game_id' => $gameId,
    'candidate_count' => count($ids),
    'eligible_repair_count' => count($eligible),
    'eligible_profile_refresh_count' => count(array_filter($eligible, static fn(array $row): bool => !empty($row['profile_refresh']))),
    'eligible_provider_refresh_count' => count(array_filter($eligible, static fn(array $row): bool => !empty($row['provider_refresh']))),
    'already_canonical_count' => count($alreadyCanonical),
    'blocked_count' => count($blocked),
    'after_id' => $afterId,
    'limit' => $limit,
    'next_after_id' => $lastScannedId,
    'target_profile' => $targetProfile,
];

if (!$apply) {
    if (!isset($options['summary'])) {
        $result['eligible'] = $eligible;
        $result['already_canonical_file_ids'] = $alreadyCanonical;
        $result['blocked'] = $blocked;
    }
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    exit($result['ok'] ? 0 : 2);
}

$writer = new Uedb5MetadataSnapshotWriter($storage);
$registration = new PdoUedb5StagingRegistrationRepository($db, $storage);
$statuses = new PdoUedb5MigrationStatusRepository($db);
$repaired = [];
$failed = [];

$dependencyFingerprint = static function(array $snapshot): string {
    $data = [
        'schema' => (string)($snapshot['section_schemas']['dependency_results'] ?? ''),
        'rows' => array_values((array)($snapshot['sections']['dependency_results'] ?? [])),
    ];
    return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
};

foreach ($eligible as $id => $needs) {
    try {
        $snapshot = $reader->snapshot($gameId, $id);
        $beforeDependency = $dependencyFingerprint($snapshot);
        if ((string)($snapshot['section_schemas']['dependency_results'] ?? '') !== 'unreal.classic.dependency-result.v1') {
            throw new RuntimeException('dependency_results_schema_not_ut4_classic');
        }

        if (!empty($needs['profile_refresh'])) {
            if (!isset($snapshot['sections']['summary'][0]) || !is_array($snapshot['sections']['summary'][0])) {
                throw new RuntimeException('summary_missing');
            }
            $snapshot['sections']['summary'][0]['parser_profile'] = $targetProfile;
            $writer->write($snapshot);
            $reader->clearCache($gameId, $id);

            $written = $reader->snapshot($gameId, $id);
            if ((array)($written['sections']['summary'][0]['parser_profile'] ?? []) !== $targetProfile) {
                throw new RuntimeException('parser_profile_write_verification_failed');
            }
            if (!hash_equals($beforeDependency, $dependencyFingerprint($written))) {
                throw new RuntimeException('dependency_results_changed_during_profile_refresh');
            }
        }

        $db->beginTransaction();
        try {
            $registered = !empty($needs['profile_refresh'])
                ? $registration->refreshExisting($gameId, $id)
                : $registration->inspect($gameId, $id);
            $payload = (string)($registered['payload_sha256'] ?? '');
            if (strlen($payload) !== 32) {
                throw new RuntimeException('refreshed_payload_hash_invalid');
            }
            $publishedProviders = $providerPublisher->publish($id);
            $statuses->markDependencySucceeded(
                $id,
                $gameId,
                $payload,
                Uedb5GameDependencyPassService::DEPENDENCY_POLICY
            );
            $db->commit();
        } catch (Throwable $dbError) {
            if ($db->inTransaction()) { $db->rollBack(); }
            throw $dbError;
        }

        $repaired[] = [
            'file_id'=>$id,
            'profile_refreshed'=>!empty($needs['profile_refresh']),
            'provider_refreshed'=>!empty($needs['provider_refresh']),
            'provider_rows'=>$publishedProviders,
            'payload_sha256'=>bin2hex($payload),
        ];
    } catch (Throwable $e) {
        $failed[] = ['file_id'=>$id,'error'=>$e->getMessage()];
    }
}

$result['ok'] = $failed === [] && $blocked === [];
$result['repaired_count'] = count($repaired);
$result['failed_count'] = count($failed);
$result['already_canonical_count'] = count($alreadyCanonical);
if (!isset($options['summary'])) {
    $result['repaired'] = $repaired;
    $result['failed'] = $failed;
    $result['already_canonical_file_ids'] = $alreadyCanonical;
    $result['blocked'] = $blocked;
}
$result['resume_after_id'] = $lastScannedId;

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 2);
