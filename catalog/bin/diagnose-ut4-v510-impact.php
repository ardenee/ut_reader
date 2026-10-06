#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;

$options = getopt('', ['summary','storage-root::','after-id::','limit::']);
$afterId = max(0, (int)($options['after-id'] ?? 0));
$limit = max(1, min(10000, (int)($options['limit'] ?? 5000)));
$app = catalog_bootstrap();
$db = $app->db;
$config = catalog_config();
$storageOverride = trim((string)($options['storage-root'] ?? ''));
$storage = rtrim(
    $storageOverride !== '' ? $storageOverride : (string)($config['storage_path'] ?? ''),
    "\\/"
);

$table = $db->query(
    "SELECT COUNT(*) FROM information_schema.tables"
    . " WHERE table_schema=DATABASE() AND table_name='ue_uedb5_files'"
);
if ((int)$table->fetchColumn() !== 1) {
    fwrite(STDERR, "Required Step 5 table is missing: ue_uedb5_files\n");
    exit(2);
}

$game = $db->query("SELECT id FROM ue_games WHERE slug='ut4' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "UT4 game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];
$reader = new Uedb5MetadataReader($storage);
$totalStatement = $db->prepare('SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=?');
$totalStatement->execute([$gameId]);
$stagedFileCount = (int)$totalStatement->fetchColumn();

$sql = 'SELECT v.file_id,v.source_policy,f.package_version,f.licensee_version,'
    . 'EXISTS(SELECT 1 FROM ue_uedb5_dependency_edges e'
    . ' WHERE e.file_id=v.file_id AND e.source_kind=1 AND e.required_object_key IS NOT NULL) has_object_edges '
    . 'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
    . 'WHERE v.game_id=? AND v.file_id>? ORDER BY v.file_id LIMIT ' . ($limit + 1);
$statement = $db->prepare($sql);
$statement->execute([$gameId, $afterId]);
$rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
$hasMore = count($rows) > $limit;
if ($hasMore) {
    array_pop($rows);
}
$nextAfterId = $rows !== [] ? (int)$rows[array_key_last($rows)]['file_id'] : $afterId;

$readerGateVersions = [325,335,364,383,443,458,484,503,506,507,509,510];
$readerGatePass1 = [];
$unversionedPass1 = [];
$metadataPolicyRefresh = [];
$policyRefresh = [];
$pass2Candidates = [];
$sourceUnavailable = [];
$details = [];
$inspectionErrors = [];
$summaryReads = 0;

foreach ($rows as $row) {
    $fileId = (int)$row['file_id'];
    $version = (int)($row['package_version'] ?? 0);
    $licensee = (int)($row['licensee_version'] ?? 0);
    $policy = (string)($row['source_policy'] ?? '');
    $hasObjects = (int)($row['has_object_edges'] ?? 0) === 1;
    $needsSummaryInspection = $version <= 0 || $version >= 511;
    $legacyPolicy = !in_array($policy, [
        Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,
        Uedb5Ut4SnapshotBuilder::SOURCE_POLICY_V511,
    ], true);
    $readerGateRisk = $legacyPolicy
        && $licensee === 0
        && in_array($version, $readerGateVersions, true);
    $unversioned = false;
    $assumed = null;
    $unversionedNeedsPass1 = false;

    if ($needsSummaryInspection) {
        try {
            $summary = $reader->page($gameId, $fileId, 'summary', 0, 1)[0] ?? null;
            if (!is_array($summary)) {
                throw new RuntimeException("UEDB5 summary is missing for UT4 file #$fileId.");
            }
        } catch (Throwable $e) {
            $inspectionErrors[$fileId] = $e->getMessage();
            if ($legacyPolicy) {
                $policyRefresh[] = $fileId;
            }
            $sourceUnavailable[] = $fileId;
            $details[(string)$fileId] = [
                'package_version' => $version,
                'licensee_version' => $licensee,
                'source_policy_current' => $policy,
                'source_policy_expected' => Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,
                'inspection_error' => $e->getMessage(),
                'source_valid_after_prerequisites' => false,
                'has_object_edges' => $hasObjects,
            ];
            continue;
        }
        $summaryReads++;
        $unversioned = !empty($summary['unversioned']);
        $profile = (array)($summary['parser_profile'] ?? []);
        $assumed = isset($profile['assumed_unversioned_parser_version'])
            ? (int)$profile['assumed_unversioned_parser_version']
            : null;
        $unversionedNeedsPass1 = $legacyPolicy
            && $unversioned
            && ($assumed !== 510 || (int)($summary['package_version'] ?? 0) !== 510);
        if ($unversionedNeedsPass1) {
            $unversionedPass1[] = $fileId;
        }
    }

    if ($readerGateRisk) {
        $readerGatePass1[] = $fileId;
    }
    if ($legacyPolicy) {
        $policyRefresh[] = $fileId;
    }

    $explicitCleanMaster = $version >= 214 && $version <= 510 && $licensee === 0;
    $explicitStructuralV511 = $version === 511 && $licensee === 0 && !$unversioned;
    $sourceValid = $explicitCleanMaster || $explicitStructuralV511 || $unversionedNeedsPass1;
    $expectedPolicy = $explicitStructuralV511
        ? Uedb5Ut4SnapshotBuilder::SOURCE_POLICY_V511
        : Uedb5Ut4SnapshotBuilder::SOURCE_POLICY;
    $policyNeedsRefresh = $policy !== $expectedPolicy;
    if (!$sourceValid) {
        $sourceUnavailable[] = $fileId;
    } else {
        if ($policyNeedsRefresh) {
            $policyRefresh[] = $fileId;
        }
        if ($policyNeedsRefresh && !$readerGateRisk && !$unversionedNeedsPass1) {
            $metadataPolicyRefresh[] = $fileId;
        }
        // v511 package structure is proven, but exact UT/Main VerifyImport is not.
        if ($hasObjects && !$explicitStructuralV511) {
            $pass2Candidates[] = $fileId;
        }
    }

    if ($readerGateRisk || $needsSummaryInspection || $policyNeedsRefresh || !$sourceValid) {
        $details[(string)$fileId] = [
            'package_version' => $version,
            'licensee_version' => $licensee,
            'source_policy_current' => $policy,
            'source_policy_expected' => $expectedPolicy,
            'unversioned' => $unversioned,
            'assumed_unversioned_parser_version' => $assumed,
            'reader_gate_pass1_reparse_required' => $readerGateRisk,
            'unversioned_pass1_reparse_required' => $unversionedNeedsPass1,
            'metadata_only_source_policy_refresh' => $policyNeedsRefresh
                && !$readerGateRisk
                && !$unversionedNeedsPass1
                && $sourceValid,
            'structural_v511_verifyimport_review_required' => $explicitStructuralV511 && $hasObjects,
            'source_valid_after_prerequisites' => $sourceValid,
            'has_object_edges' => $hasObjects,
        ];
    }
}

foreach ([
    'readerGatePass1','unversionedPass1','metadataPolicyRefresh',
    'policyRefresh','pass2Candidates','sourceUnavailable'
] as $name) {
    $$name = array_values(array_unique($$name));
    sort($$name, SORT_NUMERIC);
}
$pass1 = array_values(array_unique(array_merge($readerGatePass1, $unversionedPass1)));
sort($pass1, SORT_NUMERIC);

$result = [
    'ok' => $inspectionErrors === [],
    'read_only' => true,
    'game_id' => $gameId,
    'staged_file_count' => $stagedFileCount,
    'slice_file_count' => count($rows),
    'after_id' => $afterId,
    'limit' => $limit,
    'next_after_id' => $nextAfterId,
    'has_more' => $hasMore,
    'uedb5_summary_reads' => $summaryReads,
    'inspection_error_count' => count($inspectionErrors),
    'reader_gate_versions' => $readerGateVersions,
    'reader_gate_pass1_reparse_file_count' => count($readerGatePass1),
    'unversioned_pass1_reparse_file_count' => count($unversionedPass1),
    'pass1_reparse_file_count' => count($pass1),
    'metadata_only_source_policy_refresh_file_count' => count($metadataPolicyRefresh),
    'source_policy_refresh_file_count' => count($policyRefresh),
    'verifyimport_pass2_candidate_file_count' => count($pass2Candidates),
    'structural_v511_verifyimport_review_file_count' => count(array_filter(
        $details,
        static fn(array $detail): bool => !empty($detail['structural_v511_verifyimport_review_required'])
    )),
    'source_unavailable_file_count' => count($sourceUnavailable),
    'requires_original_package_read' => $pass1 !== [],
];
if (!isset($options['summary'])) {
    $result['reader_gate_pass1_reparse_file_ids'] = $readerGatePass1;
    $result['unversioned_pass1_reparse_file_ids'] = $unversionedPass1;
    $result['pass1_reparse_file_ids'] = $pass1;
    $result['metadata_only_source_policy_refresh_file_ids'] = $metadataPolicyRefresh;
    $result['source_policy_refresh_file_ids'] = $policyRefresh;
    $result['verifyimport_pass2_candidate_file_ids'] = $pass2Candidates;
    $result['source_unavailable_file_ids'] = $sourceUnavailable;
    $result['details'] = $details;
    $result['inspection_errors'] = $inspectionErrors;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 2);
