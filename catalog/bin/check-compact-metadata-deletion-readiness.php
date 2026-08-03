#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return int */
function compact_readiness_scalar(PDO $db, string $sql, array $parameters = []): int
{
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return (int)($statement->fetchColumn() ?: 0);
}

/** @return array<string,int> */
function compact_readiness_legacy_counts(PDO $db): array
{
    $counts = [];
    foreach (['ue_names', 'ue_imports', 'ue_exports', 'ue_dependencies'] as $table) {
        $counts[$table] = compact_readiness_scalar($db, 'SELECT COUNT(*) FROM ' . $table);
    }
    return $counts;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $requiredTables = [
        'ue_files',
        'ue_file_metadata',
        'ue_terms',
        'ue_export_lookup',
        'ue_dependency_links',
        'ue_names',
        'ue_imports',
        'ue_exports',
        'ue_dependencies',
    ];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $statement = $db->prepare(
        'SELECT TABLE_NAME FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $placeholders . ')'
    );
    $statement->execute($requiredTables);
    $present = array_fill_keys(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
    $missingTables = array_values(array_filter(
        $requiredTables,
        static fn(string $table): bool => !isset($present[$table])
    ));
    if ($missingTables !== []) {
        throw new RuntimeException('Required tables are missing: ' . implode(', ', $missingTables) . '.');
    }

    $verifiedFiles = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files WHERE scan_status="verified"'
    );
    $missingFormat2 = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<>2)'
    );
    $metadataCountMismatch = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" AND m.format_version=2 AND ('
        . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)'
    );

    $expectedExports = compact_readiness_scalar(
        $db,
        'SELECT COALESCE(SUM(f.export_count),0) FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified"'
    );
    $actualExports = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_export_lookup l '
        . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
    );
    $missingExportTerms = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_export_lookup l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'WHERE l.local_path_term_id IS NULL'
    );

    $expectedImports = compact_readiness_scalar(
        $db,
        'SELECT COALESCE(SUM(f.import_count),0) FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified"'
    );
    $actualDependencies = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_dependency_links l '
        . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
    );
    $missingImportTerms = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_dependency_links l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'WHERE l.import_object_term_id IS NULL'
    );

    $dataBlockers = [];
    if ($missingFormat2 !== 0) {
        $dataBlockers[] = $missingFormat2 . ' verified file(s) are missing format-2 metadata.';
    }
    if ($metadataCountMismatch !== 0) {
        $dataBlockers[] = $metadataCountMismatch . ' format-2 file(s) have count mismatches.';
    }
    if ($actualExports !== $expectedExports) {
        $dataBlockers[] = 'Export projection count mismatch: expected ' . $expectedExports . ', found ' . $actualExports . '.';
    }
    if ($actualDependencies !== $expectedImports) {
        $dataBlockers[] = 'Dependency projection count mismatch: expected ' . $expectedImports . ', found ' . $actualDependencies . '.';
    }
    if ($missingExportTerms !== 0) {
        $dataBlockers[] = $missingExportTerms . ' Export projection row(s) have no local-path term.';
    }
    if ($missingImportTerms !== 0) {
        $dataBlockers[] = $missingImportTerms . ' dependency projection row(s) have no Import object term.';
    }

    /*
     * These are explicit runtime gates, not a best-effort source grep. Remove an
     * entry only after the named path is compact-native and covered by a contract
     * test. The deletion command will consume this same gate before removing rows.
     */
    $runtimeBlockers = [
        'Direct scanner callers outside the central package importer still require guaranteed compact finalisation.',
        'CatalogFileMaintenance snapshot/rollback still reads and restores legacy metadata rows.',
        'Legacy CatalogImport path still writes Names/Imports/Exports/dependencies directly.',
        'Game backup import/export paths still include legacy metadata-table assumptions.',
    ];

    $dataReady = $dataBlockers === [];
    $writeReady = $runtimeBlockers === [];
    $safeToDelete = $dataReady && $writeReady;

    $output = [
        'verified_files' => $verifiedFiles,
        'format2_missing' => $missingFormat2,
        'metadata_count_mismatches' => $metadataCountMismatch,
        'expected_exports' => $expectedExports,
        'actual_export_lookup_rows' => $actualExports,
        'missing_export_path_terms' => $missingExportTerms,
        'expected_imports' => $expectedImports,
        'actual_dependency_link_rows' => $actualDependencies,
        'missing_import_object_terms' => $missingImportTerms,
        'legacy_rows' => compact_readiness_legacy_counts($db),
        'data_ready' => $dataReady,
        'compact_write_ready' => $writeReady,
        'safe_to_delete_legacy_rows' => $safeToDelete,
        'data_blockers' => $dataBlockers,
        'runtime_blockers' => $runtimeBlockers,
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($safeToDelete ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact metadata deletion readiness check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
