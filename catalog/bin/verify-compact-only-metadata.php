#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return int */
function compact_only_scalar(PDO $db, string $sql, array $args = []): int
{
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return (int)($statement->fetchColumn() ?: 0);
}

/** @return array<string,int> */
function compact_only_legacy_verified_counts(PDO $db): array
{
    $counts = [];
    foreach (['ue_names', 'ue_imports', 'ue_exports', 'ue_dependencies'] as $table) {
        $counts[$table] = compact_only_scalar(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' legacy '
            . 'JOIN ue_files f ON f.id=legacy.file_id AND f.scan_status="verified" '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
        );
    }
    return $counts;
}

/** @return array<int,true> */
function compact_only_sample_indexes(int $count, int $requested): array
{
    if ($count < 1 || $requested < 1) {
        return [];
    }
    $requested = min($count, $requested);
    $indexes = [0 => true, ($count - 1) => true];
    if ($requested > 2) {
        $step = max(1, (int)floor(($count - 1) / ($requested - 1)));
        for ($index = 0; $index < $count && count($indexes) < $requested; $index += $step) {
            $indexes[$index] = true;
        }
    }
    ksort($indexes, SORT_NUMERIC);
    return $indexes;
}

try {
    set_time_limit(0);
    $options = getopt('', ['hash-sample::']);
    $hashSample = max(0, min(1000, (int)($options['hash-sample'] ?? 100)));

    $config = catalog_config();
    $db = catalog_db($config);
    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('Catalog storage_path is required for compact-only verification.');
    }

    $files = $db->query(
        'SELECT f.id,f.game_id,f.name_count,f.import_count,f.export_count,'
        . 'm.format_version,m.compressed_size,m.payload_sha256,'
        . 'm.name_count metadata_name_count,m.import_count metadata_import_count,'
        . 'm.export_count metadata_export_count '
        . 'FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" ORDER BY f.id'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $verifiedFiles = count($files);
    $expectedNames = 0;
    $expectedImports = 0;
    $expectedExports = 0;
    $missingFormat2 = 0;
    $metadataCountMismatches = 0;
    $missingContainerCount = 0;
    $containerSizeMismatchCount = 0;
    $missingContainerSample = [];
    $containerSizeMismatchSample = [];
    $hashMismatchFileIds = [];
    $containerVerificationFailures = [];
    $sampledContainers = 0;
    $sampleIndexes = compact_only_sample_indexes($verifiedFiles, $hashSample);

    foreach ($files as $index => $file) {
        $fileId = (int)$file['id'];
        $gameId = (int)$file['game_id'];
        $expectedNames += (int)$file['name_count'];
        $expectedImports += (int)$file['import_count'];
        $expectedExports += (int)$file['export_count'];

        if ((int)($file['format_version'] ?? 0) !== 2) {
            $missingFormat2++;
            continue;
        }
        if (
            (int)$file['metadata_name_count'] !== (int)$file['name_count']
            || (int)$file['metadata_import_count'] !== (int)$file['import_count']
            || (int)$file['metadata_export_count'] !== (int)$file['export_count']
        ) {
            $metadataCountMismatches++;
        }

        $path = BlockedCompressedMetadataContainer::path($storageRoot, $gameId, $fileId);
        if (!is_file($path)) {
            $missingContainerCount++;
            if (count($missingContainerSample) < 25) {
                $missingContainerSample[] = ['file_id' => $fileId, 'path' => $path];
            }
            continue;
        }
        $actualSize = filesize($path);
        if ($actualSize === false || (int)$actualSize !== (int)$file['compressed_size']) {
            $containerSizeMismatchCount++;
            if (count($containerSizeMismatchSample) < 25) {
                $containerSizeMismatchSample[] = [
                    'file_id' => $fileId,
                    'expected' => (int)$file['compressed_size'],
                    'actual' => $actualSize === false ? null : (int)$actualSize,
                ];
            }
            continue;
        }
        if (!isset($sampleIndexes[$index])) {
            continue;
        }

        $sampledContainers++;
        $bytes = file_get_contents($path);
        if (!is_string($bytes)) {
            $containerVerificationFailures[] = ['file_id' => $fileId, 'error' => 'Could not read container.'];
            continue;
        }
        if (!hash_equals((string)$file['payload_sha256'], hash('sha256', $bytes, true))) {
            $hashMismatchFileIds[] = $fileId;
            continue;
        }
        try {
            BlockedCompressedMetadataContainer::verifyBytes($bytes, $fileId);
        } catch (Throwable $error) {
            $containerVerificationFailures[] = ['file_id' => $fileId, 'error' => $error->getMessage()];
        }
    }

    $actualExportLookup = compact_only_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_export_lookup l '
        . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
    );
    $actualDependencyLinks = compact_only_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_dependency_links l '
        . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
    );
    $missingExportTerms = compact_only_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_export_lookup l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'WHERE l.local_path_term_id IS NULL'
    );
    $missingImportTerms = compact_only_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_dependency_links l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'WHERE l.import_object_term_id IS NULL'
    );
    $overflowIncomplete = compact_only_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_terms WHERE is_overflow=1 AND ('
        . 'OCTET_LENGTH(value_prefix)<>value_length OR value_hash<>UNHEX(MD5(value_prefix)))'
    );
    $legacyVerifiedRows = compact_only_legacy_verified_counts($db);
    $runtimeAudit = LegacyMetadataRuntimeAudit::scan(dirname(__DIR__));

    $blockers = [];
    if ($missingFormat2 !== 0) {
        $blockers[] = $missingFormat2 . ' verified file(s) are missing format-2 metadata.';
    }
    if ($metadataCountMismatches !== 0) {
        $blockers[] = $metadataCountMismatches . ' verified file(s) have metadata count mismatches.';
    }
    if ($actualExportLookup !== $expectedExports) {
        $blockers[] = 'Export projection count mismatch.';
    }
    if ($actualDependencyLinks !== $expectedImports) {
        $blockers[] = 'Dependency projection count mismatch.';
    }
    if ($missingExportTerms !== 0 || $missingImportTerms !== 0) {
        $blockers[] = 'Required compact term references are missing.';
    }
    if ($overflowIncomplete !== 0) {
        $blockers[] = $overflowIncomplete . ' overflow term(s) are incomplete.';
    }
    if (array_sum($legacyVerifiedRows) !== 0) {
        $blockers[] = array_sum($legacyVerifiedRows) . ' verified format-2 legacy row(s) remain.';
    }
    if ((int)$runtimeAudit['references'] !== 0) {
        $blockers[] = (int)$runtimeAudit['references'] . ' unapproved runtime legacy reference(s) remain.';
    }
    if ($missingContainerCount !== 0) {
        $blockers[] = $missingContainerCount . ' compact metadata container(s) are missing.';
    }
    if ($containerSizeMismatchCount !== 0) {
        $blockers[] = $containerSizeMismatchCount . ' compact metadata container size(s) do not match.';
    }
    if ($hashMismatchFileIds !== [] || $containerVerificationFailures !== []) {
        $blockers[] = 'One or more sampled compact metadata containers failed integrity verification.';
    }

    $output = [
        'verified' => $blockers === [],
        'verified_files' => $verifiedFiles,
        'format2_missing' => $missingFormat2,
        'metadata_count_mismatches' => $metadataCountMismatches,
        'expected_counts' => [
            'names' => $expectedNames,
            'imports' => $expectedImports,
            'exports' => $expectedExports,
            'dependencies' => $expectedImports,
        ],
        'compact_projection_rows' => [
            'ue_export_lookup' => $actualExportLookup,
            'ue_dependency_links' => $actualDependencyLinks,
        ],
        'missing_export_path_terms' => $missingExportTerms,
        'missing_import_object_terms' => $missingImportTerms,
        'overflow_terms_incomplete' => $overflowIncomplete,
        'verified_format2_legacy_rows' => $legacyVerifiedRows,
        'runtime_legacy_references' => (int)$runtimeAudit['references'],
        'containers_checked_for_presence_and_size' => $verifiedFiles - $missingFormat2,
        'missing_container_count' => $missingContainerCount,
        'container_size_mismatch_count' => $containerSizeMismatchCount,
        'containers_integrity_sampled' => $sampledContainers,
        'missing_container_sample' => $missingContainerSample,
        'container_size_mismatch_sample' => $containerSizeMismatchSample,
        'hash_mismatch_file_ids' => array_slice($hashMismatchFileIds, 0, 25),
        'container_verification_failures' => array_slice($containerVerificationFailures, 0, 25),
        'blockers' => $blockers,
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($blockers === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact-only metadata verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
