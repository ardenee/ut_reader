#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit;

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

/** @param list<array<string,mixed>> $matches @return list<string> */
function compact_readiness_runtime_blockers(array $matches): array
{
    $byFile = [];
    foreach ($matches as $match) {
        $file = (string)($match['file'] ?? 'unknown');
        $table = (string)($match['table'] ?? 'unknown');
        $operation = (string)($match['operation'] ?? 'read');
        $byFile[$file]['count'] = (int)($byFile[$file]['count'] ?? 0) + 1;
        $byFile[$file]['tables'][$table] = true;
        $byFile[$file]['operations'][$operation] = true;
    }

    ksort($byFile, SORT_NATURAL | SORT_FLAG_CASE);
    $blockers = [];
    foreach ($byFile as $file => $details) {
        $tables = array_keys((array)$details['tables']);
        $operations = array_keys((array)$details['operations']);
        sort($tables, SORT_NATURAL | SORT_FLAG_CASE);
        sort($operations, SORT_NATURAL | SORT_FLAG_CASE);
        $blockers[] = $file . ' contains ' . (int)$details['count']
            . ' unapproved legacy metadata reference(s): '
            . implode(', ', $tables) . ' [' . implode(', ', $operations) . '].';
    }
    return $blockers;
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
    $overflowTerms = compact_readiness_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_terms WHERE is_overflow=1'
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
    if ($overflowTerms !== 0) {
        $dataBlockers[] = $overflowTerms . ' compact term(s) exceed the stored prefix. Full overflow reconstruction must be implemented before verified legacy rows are removed.';
    }

    $runtimeAudit = LegacyMetadataRuntimeAudit::scan(dirname(__DIR__));
    $runtimeMatches = (array)$runtimeAudit['matches'];
    $runtimeBlockers = compact_readiness_runtime_blockers($runtimeMatches);
    $runtimeWriteReferences = count(array_filter(
        $runtimeMatches,
        static fn(array $match): bool => (string)($match['operation'] ?? 'read') !== 'read'
    ));
    $runtimeReadReferences = count($runtimeMatches) - $runtimeWriteReferences;

    $dataReady = $dataBlockers === [];
    $writeReady = $runtimeWriteReferences === 0;
    $readReady = $runtimeReadReferences === 0;
    $safeToDelete = $dataReady && $writeReady && $readReady;

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
        'overflow_terms' => $overflowTerms,
        'legacy_rows' => compact_readiness_legacy_counts($db),
        'data_ready' => $dataReady,
        'compact_write_ready' => $writeReady,
        'compact_read_ready' => $readReady,
        'safe_to_delete_legacy_rows' => $safeToDelete,
        'runtime_legacy_reference_files' => (int)$runtimeAudit['files'],
        'runtime_legacy_references' => (int)$runtimeAudit['references'],
        'runtime_legacy_read_references' => $runtimeReadReferences,
        'runtime_legacy_write_references' => $runtimeWriteReferences,
        'data_blockers' => $dataBlockers,
        'runtime_blockers' => $runtimeBlockers,
        'runtime_reference_matches' => $runtimeMatches,
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($safeToDelete ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Compact metadata deletion readiness check failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
