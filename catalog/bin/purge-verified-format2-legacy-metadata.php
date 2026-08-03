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

const LEGACY_PURGE_CONFIRMATION = 'PURGE_VERIFIED_FORMAT2_LEGACY_ROWS';
const LEGACY_PURGE_LOCK = 'unrealdb_verified_format2_legacy_purge_v1';

/** @return int */
function legacy_purge_scalar(PDO $db, string $sql, array $args = []): int
{
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return (int)($statement->fetchColumn() ?: 0);
}

/** @return array<string,int> */
function legacy_purge_total_counts(PDO $db): array
{
    $counts = [];
    foreach (['ue_names', 'ue_imports', 'ue_exports', 'ue_dependencies'] as $table) {
        $counts[$table] = legacy_purge_scalar($db, 'SELECT COUNT(*) FROM ' . $table);
    }
    return $counts;
}

/** @return array<string,mixed> */
function legacy_purge_preflight(PDO $db): array
{
    $blockers = [];
    $missingFormat2 = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<>2)'
    );
    $metadataMismatch = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" AND m.format_version=2 AND ('
        . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)'
    );
    $expectedExports = legacy_purge_scalar(
        $db,
        'SELECT COALESCE(SUM(f.export_count),0) FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified"'
    );
    $actualExports = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_export_lookup l '
        . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
    );
    $expectedImports = legacy_purge_scalar(
        $db,
        'SELECT COALESCE(SUM(f.import_count),0) FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified"'
    );
    $actualDependencies = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_dependency_links l '
        . 'JOIN ue_files f ON f.id=l.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
    );
    $missingExportTerms = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_export_lookup l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'WHERE l.local_path_term_id IS NULL'
    );
    $missingImportTerms = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_dependency_links l '
        . 'JOIN ue_file_metadata m ON m.file_id=l.file_id AND m.format_version=2 '
        . 'WHERE l.import_object_term_id IS NULL'
    );
    $totalOverflowTerms = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_terms WHERE is_overflow=1'
    );
    $incompleteOverflowTerms = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_terms WHERE is_overflow=1 AND ('
        . 'OCTET_LENGTH(value_prefix)<>value_length OR value_hash<>UNHEX(MD5(value_prefix)))'
    );

    if ($missingFormat2 !== 0) {
        $blockers[] = $missingFormat2 . ' verified file(s) are missing format-2 metadata.';
    }
    if ($metadataMismatch !== 0) {
        $blockers[] = $metadataMismatch . ' format-2 file(s) have metadata count mismatches.';
    }
    if ($expectedExports !== $actualExports) {
        $blockers[] = 'Export projection count mismatch.';
    }
    if ($expectedImports !== $actualDependencies) {
        $blockers[] = 'Dependency projection count mismatch.';
    }
    if ($missingExportTerms !== 0 || $missingImportTerms !== 0) {
        $blockers[] = 'Required compact search terms are missing.';
    }
    if ($incompleteOverflowTerms !== 0) {
        $blockers[] = $incompleteOverflowTerms
            . ' compact overflow term(s) are truncated or fail their stored hash.';
    }

    $audit = LegacyMetadataRuntimeAudit::scan(dirname(__DIR__));
    if ((int)$audit['references'] !== 0) {
        $blockers[] = (int)$audit['references'] . ' unapproved runtime legacy-table reference(s) remain.';
    }

    $runningJobs = 0;
    try {
        $runningJobs = legacy_purge_scalar(
            $db,
            'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
        );
        if ($runningJobs !== 0) {
            $blockers[] = $runningJobs . ' background job(s) are still marked running.';
        }
    } catch (Throwable) {
        // Older installations may not have the durable jobs table.
    }

    $candidateFiles = legacy_purge_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified" AND ('
        . 'EXISTS(SELECT 1 FROM ue_names n WHERE n.file_id=f.id) OR '
        . 'EXISTS(SELECT 1 FROM ue_imports i WHERE i.file_id=f.id) OR '
        . 'EXISTS(SELECT 1 FROM ue_exports e WHERE e.file_id=f.id) OR '
        . 'EXISTS(SELECT 1 FROM ue_dependencies d WHERE d.file_id=f.id))'
    );

    $expectedRows = $db->query(
        'SELECT COALESCE(SUM(f.name_count),0) names,'
        . 'COALESCE(SUM(f.import_count),0) imports,'
        . 'COALESCE(SUM(f.export_count),0) exports,'
        . 'COALESCE(SUM(f.import_count),0) dependencies '
        . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified"'
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'safe_to_apply' => $blockers === [],
        'blockers' => $blockers,
        'candidate_files' => $candidateFiles,
        'expected_verified_rows' => array_map('intval', $expectedRows),
        'legacy_table_rows' => legacy_purge_total_counts($db),
        'overflow_terms_total' => $totalOverflowTerms,
        'overflow_terms_incomplete' => $incompleteOverflowTerms,
        'runtime_references' => (int)$audit['references'],
        'running_jobs' => $runningJobs,
    ];
}

/** @return list<array{id:int,row_budget:int}> */
function legacy_purge_candidates(PDO $db, int $maxFiles): array
{
    $sql =
        'SELECT f.id,(f.name_count+(f.import_count*2)+f.export_count) row_budget '
        . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified" AND ('
        . 'EXISTS(SELECT 1 FROM ue_names n WHERE n.file_id=f.id) OR '
        . 'EXISTS(SELECT 1 FROM ue_imports i WHERE i.file_id=f.id) OR '
        . 'EXISTS(SELECT 1 FROM ue_exports e WHERE e.file_id=f.id) OR '
        . 'EXISTS(SELECT 1 FROM ue_dependencies d WHERE d.file_id=f.id)) '
        . 'ORDER BY row_budget,f.id';
    if ($maxFiles > 0) {
        $sql .= ' LIMIT ' . $maxFiles;
    }
    $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(
        static fn(array $row): array => [
            'id' => (int)$row['id'],
            'row_budget' => max(1, (int)$row['row_budget']),
        ],
        $rows
    );
}

/** @param list<array{id:int,row_budget:int}> $candidates @return list<list<int>> */
function legacy_purge_groups(array $candidates, int $rowBudget): array
{
    $groups = [];
    $current = [];
    $currentRows = 0;
    foreach ($candidates as $candidate) {
        $candidateRows = max(1, (int)$candidate['row_budget']);
        if ($current !== [] && ($currentRows + $candidateRows > $rowBudget || count($current) >= 500)) {
            $groups[] = $current;
            $current = [];
            $currentRows = 0;
        }
        $current[] = (int)$candidate['id'];
        $currentRows += $candidateRows;
    }
    if ($current !== []) {
        $groups[] = $current;
    }
    return $groups;
}

/** @param list<int> $fileIds @return array<string,int> */
function legacy_purge_group(PDO $db, array $fileIds): array
{
    $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
    $deleted = ['ue_dependencies' => 0, 'ue_exports' => 0, 'ue_imports' => 0, 'ue_names' => 0];

    $db->beginTransaction();
    try {
        foreach (['ue_dependencies', 'ue_exports', 'ue_imports', 'ue_names'] as $table) {
            $statement = $db->prepare('DELETE FROM ' . $table . ' WHERE file_id IN (' . $placeholders . ')');
            $statement->execute($fileIds);
            $deleted[$table] = $statement->rowCount();
        }
        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
    return $deleted;
}

try {
    set_time_limit(0);
    $options = getopt('', ['apply::', 'row-budget::', 'max-files::']);
    $applyToken = trim((string)($options['apply'] ?? ''));
    $rowBudget = max(1000, min(1000000, (int)($options['row-budget'] ?? 250000)));
    $maxFiles = max(0, (int)($options['max-files'] ?? 0));

    $config = catalog_config();
    $db = catalog_db($config);
    $preflight = legacy_purge_preflight($db);

    if ($applyToken === '') {
        fwrite(STDOUT, json_encode([
            'mode' => 'dry-run',
            'row_budget' => $rowBudget,
            'max_files' => $maxFiles,
            'confirmation_token' => LEGACY_PURGE_CONFIRMATION,
            'apply_command' => 'php catalog/bin/purge-verified-format2-legacy-metadata.php --apply=' . LEGACY_PURGE_CONFIRMATION,
        ] + $preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(!empty($preflight['safe_to_apply']) ? 0 : 2);
    }

    if ($applyToken !== LEGACY_PURGE_CONFIRMATION) {
        throw new RuntimeException('Incorrect apply token. No rows were deleted.');
    }
    if (empty($preflight['safe_to_apply'])) {
        throw new RuntimeException('Preflight failed: ' . implode(' ', (array)$preflight['blockers']));
    }

    $lockStatement = $db->prepare('SELECT GET_LOCK(?,0)');
    $lockStatement->execute([LEGACY_PURGE_LOCK]);
    if ((int)$lockStatement->fetchColumn() !== 1) {
        throw new RuntimeException('Another verified legacy purge is already running.');
    }

    $started = microtime(true);
    $totals = ['ue_dependencies' => 0, 'ue_exports' => 0, 'ue_imports' => 0, 'ue_names' => 0];
    try {
        $candidates = legacy_purge_candidates($db, $maxFiles);
        $groups = legacy_purge_groups($candidates, $rowBudget);
        $totalGroups = count($groups);
        foreach ($groups as $index => $fileIds) {
            $deleted = legacy_purge_group($db, $fileIds);
            foreach ($totals as $table => $count) {
                $totals[$table] += (int)$deleted[$table];
            }
            fwrite(
                STDERR,
                'Purged group ' . ($index + 1) . '/' . $totalGroups
                . ': files=' . count($fileIds)
                . ', rows=' . array_sum($deleted)
                . ', last_file_id=' . max($fileIds) . PHP_EOL
            );
        }

        $normalized = $db->exec(
            'UPDATE ue_dependencies SET status="package_only" '
            . 'WHERE status="resolved" AND resolved_file_id IS NOT NULL AND resolved_export_id IS NULL'
        );

        $remainingCandidates = legacy_purge_scalar(
            $db,
            'SELECT COUNT(*) FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
            . 'WHERE f.scan_status="verified" AND ('
            . 'EXISTS(SELECT 1 FROM ue_names n WHERE n.file_id=f.id) OR '
            . 'EXISTS(SELECT 1 FROM ue_imports i WHERE i.file_id=f.id) OR '
            . 'EXISTS(SELECT 1 FROM ue_exports e WHERE e.file_id=f.id) OR '
            . 'EXISTS(SELECT 1 FROM ue_dependencies d WHERE d.file_id=f.id))'
        );

        fwrite(STDOUT, json_encode([
            'mode' => 'apply',
            'processed_files' => count($candidates),
            'groups' => $totalGroups,
            'deleted_rows' => $totals,
            'normalized_retained_dependency_rows' => (int)$normalized,
            'remaining_verified_format2_files_with_legacy_rows' => $remainingCandidates,
            'legacy_table_rows_after' => legacy_purge_total_counts($db),
            'elapsed_seconds' => round(microtime(true) - $started, 2),
            'filesystem_space_reclaimed' => false,
            'next_step' => $remainingCandidates === 0
                ? 'Run post-purge verification, then rebuild/optimize the legacy tables to return filesystem space.'
                : 'Run the same apply command again to continue the resumable purge.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([LEGACY_PURGE_LOCK]);
        } catch (Throwable) {
            // Connection close also releases the advisory lock.
        }
    }

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Verified format-2 legacy purge failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
