#!/usr/bin/env php
<?php
/**
 * Read-only UE4 residual missing-dependency audit using the same deterministic
 * VerifyImport resolver as production dependency rebuilding.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotLoader;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe4VerifyImportProjectionResolver;

$options = getopt('', ['game-id::', 'examples::', 'progress-every::']);
$gameId = max(1, (int)($options['game-id'] ?? 7));
$exampleLimit = max(0, min(50, (int)($options['examples'] ?? 10)));
$progressEvery = max(1, (int)($options['progress-every'] ?? 100));

$config = catalog_config();
$db = catalog_db($config);
$storageRoot = trim((string)($config['storage_path'] ?? ''));
if ($storageRoot === '') throw new RuntimeException('catalog storage_path is required.');
$loader = new BlockedCompressedMetadataSnapshotLoader($db, $storageRoot);

$game = catalog_one(
    $db,
    'SELECT g.id,g.name,g.slug,p.engine_key,p.profile_name FROM ue_games g '
    . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=? LIMIT 1',
    [$gameId]
);
if (!$game || strtoupper(trim((string)($game['engine_key'] ?? ''))) !== 'UE4') {
    throw new RuntimeException('Selected game is not an active UE4 game.');
}

$missingRows = catalog_all(
    $db,
    'SELECT l.file_id,l.import_index,CONVERT(pkg.value_prefix USING utf8mb4) required_package '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'AND CONVERT(pkg.value_prefix USING utf8mb4) NOT LIKE "/Script/%" '
    . 'ORDER BY l.file_id,required_package,l.import_index',
    [BlockedCompressedMetadataContainer::FORMAT_VERSION, $gameId]
);
$groupsByKey = [];
foreach ($missingRows as $row) {
    $fileId = (int)($row['file_id'] ?? 0);
    $packageName = trim((string)($row['required_package'] ?? ''));
    $importIndex = (int)($row['import_index'] ?? -1);
    if ($fileId < 1 || $packageName === '' || $importIndex < 0) continue;
    $key = $fileId . "\0" . $packageName;
    if (!isset($groupsByKey[$key])) {
        $groupsByKey[$key] = ['file_id'=>$fileId,'required_package'=>$packageName,'missing_import_indexes'=>[]];
    }
    $groupsByKey[$key]['missing_import_indexes'][$importIndex] = true;
}
$groups = array_values($groupsByKey);

$counts = [
    'no_package_provider' => 0,
    'provider_rejected_by_ue4_verifyimport' => 0,
    'v4_package_context_unavailable' => 0,
    'suspicious_complete_provider_still_missing' => 0,
];
$examples = [];
$rejectionReasonPairs = [];
$rejectionReasonGroups = [];
$rejectionReasonExamples = [];
$snapshotCache = [];
$processed = 0;

foreach ($groups as $group) {
    $fileId = (int)($group['file_id'] ?? 0);
    $packageName = trim((string)($group['required_package'] ?? ''));
    $missingImportIndexes = array_map('intval', array_keys((array)($group['missing_import_indexes'] ?? [])));
    $missingImportSet = array_fill_keys($missingImportIndexes, true);
    if ($fileId < 1 || $packageName === '' || $missingImportSet === []) continue;

    if (!isset($snapshotCache[$fileId])) {
        $snapshotCache = [$fileId => $loader->loadDependencySnapshot($fileId, true)];
    }
    $imports = array_values((array)($snapshotCache[$fileId]['imports'] ?? []));
    $exports = array_values((array)($snapshotCache[$fileId]['exports'] ?? []));
    $required = [];
    $needsV5Context = false;
    foreach ($imports as $fallback => $import) {
        if (!is_array($import) || strcasecmp(trim((string)($import['root_package'] ?? '')), $packageName) !== 0) continue;
        if (trim((string)($import['relative_object_path'] ?? '')) === '') continue;
        $index = isset($import['import_index']) ? (int)$import['import_index'] : (int)$fallback;
        $required[] = $index;
        if ((int)($import['outer_index'] ?? 0) > 0) $needsV5Context = true;
    }
    if ($required === []) continue;
    $missingRequiredIndexes = array_values(array_filter(
        $required,
        static fn(int $index): bool => isset($missingImportSet[$index])
    ));
    $missingRequiredSet = array_fill_keys($missingRequiredIndexes, true);

    $providerRows = catalog_all(
        $db,
        'SELECT f.id file_id FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE f.game_id=? AND f.scan_status="verified" AND f.package_name=? '
        . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
        . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1)) '
        . 'UNION SELECT a.file_id FROM ue_file_package_aliases a '
        . 'JOIN ue_files f ON f.id=a.file_id AND f.game_id=a.game_id '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE a.game_id=? AND f.scan_status="verified" AND a.package_name=? '
        . 'AND NOT EXISTS (SELECT 1 FROM ue_invalid_file_identities bad '
        . 'WHERE bad.file_size=f.file_size AND bad.md5=LOWER(f.md5) AND bad.sha1=LOWER(f.sha1))',
        [BlockedCompressedMetadataContainer::FORMAT_VERSION, $gameId, $packageName,
         BlockedCompressedMetadataContainer::FORMAT_VERSION, $gameId, $packageName]
    );
    $providerIds = array_values(array_unique(array_map(
        static fn(array $row): int => (int)($row['file_id'] ?? 0),
        $providerRows
    )));
    $providerIds = array_values(array_filter($providerIds, static fn(int $id): bool => $id > 0));

    $providerResults = [];
    $hasComplete = false;
    $groupReasons = [];
    $groupPairReasonCounts = [];
    foreach ($providerIds as $providerId) {
        $diagnostic = PdoUe4VerifyImportProjectionResolver::diagnoseProviderOutcome($db, $providerId, $imports, $exports);
        $matches = (array)($diagnostic['matches'] ?? []);
        $rejections = (array)($diagnostic['rejections'] ?? []);
        $matched = 0;
        $missingMatched = 0;
        $reasonCounts = [];
        $failedImports = [];
        foreach ($required as $index) {
            $isMatch = array_key_exists($index, $matches);
            if ($isMatch) {
                $matched++;
                if (isset($missingRequiredSet[$index])) $missingMatched++;
                continue;
            }
            if (!isset($missingRequiredSet[$index])) continue;
            $detail = (array)($rejections[$index] ?? ['import_index'=>$index,'reason'=>'unclassified']);
            $reason = (string)($detail['reason'] ?? 'unclassified');
            $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
            $groupPairReasonCounts[$reason] = ($groupPairReasonCounts[$reason] ?? 0) + 1;
            $groupReasons[$reason] = true;
            $failedImports[] = $detail;
        }
        ksort($reasonCounts);
        $providerResults[] = [
            'file_id' => $providerId,
            'matched' => $matched,
            'required' => count($required),
            'missing_matched' => $missingMatched,
            'missing_required' => count($missingRequiredSet),
            'rejection_reason_counts' => $reasonCounts,
            'rejections' => $failedImports,
        ];
        if ($matched === count($required)) {
            $hasComplete = true;
            break;
        }
    }

    if ($providerIds === []) {
        $classification = 'no_package_provider';
    } elseif ($hasComplete) {
        $classification = 'suspicious_complete_provider_still_missing';
    } elseif ($needsV5Context) {
        $classification = 'v4_package_context_unavailable';
    } else {
        $classification = 'provider_rejected_by_ue4_verifyimport';
    }
    $counts[$classification]++;
    if ($classification === 'provider_rejected_by_ue4_verifyimport') {
        foreach ($groupPairReasonCounts as $reason => $count) {
            $rejectionReasonPairs[$reason] = ($rejectionReasonPairs[$reason] ?? 0) + $count;
        }
        foreach (array_keys($groupReasons) as $reason) {
            $rejectionReasonGroups[$reason] = ($rejectionReasonGroups[$reason] ?? 0) + 1;
        }
    }

    $owner = null;
    if ($exampleLimit > 0 && count($examples[$classification] ?? []) < $exampleLimit) {
        $owner = catalog_one($db, 'SELECT original_name,package_version FROM ue_files WHERE id=? LIMIT 1', [$fileId]) ?: [];
        $examples[$classification][] = [
            'consumer_file_id' => $fileId,
            'consumer_file' => (string)($owner['original_name'] ?? ''),
            'package_version' => (int)($owner['package_version'] ?? 0),
            'required_package' => $packageName,
            'required_import_indexes' => $required,
            'missing_import_indexes' => $missingImportIndexes,
            'missing_object_import_indexes' => $missingRequiredIndexes,
            'providers' => $providerResults,
        ];
    }
    if ($classification === 'provider_rejected_by_ue4_verifyimport' && $exampleLimit > 0) {
        foreach (array_keys($groupReasons) as $reason) {
            if (count($rejectionReasonExamples[$reason] ?? []) >= $exampleLimit) continue;
            if ($owner === null) {
                $owner = catalog_one($db, 'SELECT original_name,package_version FROM ue_files WHERE id=? LIMIT 1', [$fileId]) ?: [];
            }
            $reasonProviders = [];
            foreach ($providerResults as $providerResult) {
                $reasonRejections = array_values(array_filter(
                    (array)($providerResult['rejections'] ?? []),
                    static fn(array $detail): bool => (string)($detail['reason'] ?? '') === $reason
                ));
                if ($reasonRejections === []) continue;
                $reasonProviders[] = [
                    'file_id' => (int)($providerResult['file_id'] ?? 0),
                    'matched' => (int)($providerResult['matched'] ?? 0),
                    'required' => (int)($providerResult['required'] ?? 0),
                    'rejections' => $reasonRejections,
                ];
            }
            $rejectionReasonExamples[$reason][] = [
                'consumer_file_id' => $fileId,
                'consumer_file' => (string)($owner['original_name'] ?? ''),
                'package_version' => (int)($owner['package_version'] ?? 0),
                'required_package' => $packageName,
                'providers' => $reasonProviders,
            ];
        }
    }

    $processed++;
    if (($processed % $progressEvery) === 0) {
        fwrite(STDERR, 'Classified ' . $processed . '/' . count($groups) . PHP_EOL);
    }
}

arsort($counts);
arsort($rejectionReasonPairs);
arsort($rejectionReasonGroups);
echo json_encode([
    'ok' => true,
    'read_only' => true,
    'game' => $game,
    'metadata_format_version' => BlockedCompressedMetadataContainer::FORMAT_VERSION,
    'residual_non_script_groups' => count($groups),
    'classifications' => $counts,
    'provider_rejection_reason_audit' => [
        'failed_import_provider_pairs_by_reason' => $rejectionReasonPairs,
        'groups_containing_reason' => $rejectionReasonGroups,
        'examples_per_reason' => $rejectionReasonExamples,
        'counting_note' => 'Reason counts/examples include only object Imports whose persisted ue_dependency_links.status is missing (0). Pair counts count each such failed Import against each physical provider; group counts count each residual package group at most once per reason.',
    ],
    'examples_per_classification' => $examples,
    'interpretation' => [
        'suspicious_complete_provider_still_missing' => 'Investigate first: production UE4 VerifyImport semantics found one complete physical provider although the persisted group is still missing.',
        'provider_rejected_by_ue4_verifyimport' => 'A physical package exists, but no one provider satisfies the complete object/class/class-package/outer/public Import set.',
        'no_package_provider' => 'No verified game-local physical provider exists for the serialized package identity.',
        'v4_package_context_unavailable' => 'The consumer graph needs modern UE4 package context that v4 does not preserve; v5 must retain FObjectImport::PackageName before resolving it.',
    ],
    'rejection_reason_meanings' => [
        'object_name_not_found' => 'No provider export has the serialized Import.ObjectName.',
        'class_name_mismatch' => 'The object name exists, but not with the serialized Import.ClassName.',
        'class_package_mismatch' => 'ObjectName/ClassName candidates exist, but fail UE4 full-ClassPackage then short-ClassPackage selection.',
        'outer_mismatch' => 'Object/class/class-package candidates exist, but none has the SourceIndex-derived serialized outer required by VerifyImportInner.',
        'private_export_rejected' => 'The matching export lacks RF_Public and none of the exact UE4 editor consumer-graph exceptions applies.',
        'outer_import_unresolved' => 'The serialized Import outer did not resolve first, so VerifyImportInner cannot validate the child candidate.',
        'object_redirector_target_unavailable' => 'UE4 found the ObjectRedirector retry target, but DestinationObject requires export payload state.',
        'object_redirector_ancestor_target_unavailable' => 'An outer Import resolves through ObjectRedirector; UE4 needs DestinationObject before descendant resolution can continue.',
        'v4_package_context_unavailable' => 'A positive export outer requires FObjectImport::PackageName context that UEDB4 did not retain.',
    ],
    'invariant' => 'Consumer Imports create requirements; one physical provider must independently satisfy the complete set.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
